<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/ArcaGateway.php';

use FacturaFacil\ArcaGateway;
use FacturaFacil\ArcaRejectedException;

function arcaAssert(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
function arcaThrows(callable $fn, string $class = RuntimeException::class): Throwable {
    try { $fn(); } catch (Throwable $e) { arcaAssert($e instanceof $class, 'Tipo de excepción inesperado: ' . get_class($e)); return $e; }
    throw new RuntimeException('Se esperaba una excepción.');
}

$directory = sys_get_temp_dir() . '/facturafacil-arca-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'FacturaFacil offline tests'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
openssl_pkey_export($key, $pem);
file_put_contents($directory . '/key.pem', $pem);
openssl_x509_export($cert, $pem);
file_put_contents($directory . '/cert.pem', $pem);
$settings = ['arca_environment' => 'homologation', 'cuit' => '30712345678', 'point_of_sale' => 2,
    'issuer_tax_condition' => 1, 'certificate_path' => $directory . '/cert.pem', 'private_key_path' => $directory . '/key.pem'];
$invoice = ['id' => 7, 'number' => 1, 'point_of_sale' => 2, 'voucher_type' => 1, 'issue_date' => '2026-10-26',
    'customer' => ['document_type' => 'CUIT', 'document_number' => '20111111112', 'tax_condition' => 1],
    'totals' => ['net_cents' => 15000, 'vat_cents' => 2625, 'total_cents' => 17625,
        'vat_breakdown' => [['rate' => '21','base_cents' => 10000,'vat_cents' => 2100], ['rate' => '10.5','base_cents' => 5000,'vat_cents' => 525]]]];
$state = ['mode' => 'approved', 'calls' => [], 'detail' => [], 'wsaa' => 0, 'padron_ids' => ['20111111112']];
$transport = static function (string $service, string $method, array $params) use (&$state, $directory): array {
    $state['calls'][] = [$service, $method];
    if ($method === 'loginCms') {
        $state['wsaa']++;
        arcaAssert(base64_decode($params['in0'], true) !== false, 'CMS firmado inválido');
        // Verify the attached CMS signature and actual TRA content, not only base64 syntax.
        $mime = "MIME-Version: 1.0\nContent-Type: application/x-pkcs7-mime; name=\"smime.p7m\"\nContent-Transfer-Encoding: base64\n\n" . chunk_split($params['in0'],64,"\n");
        file_put_contents($directory . '/verify.mime', $mime);
        arcaAssert(openssl_pkcs7_verify($directory . '/verify.mime', PKCS7_NOVERIFY, null, [], null, $directory . '/verified.xml') === true, 'Firma CMS verificable y contenido adjunto');
        $tra = simplexml_load_file($directory . '/verified.xml');
        arcaAssert(in_array((string)$tra->service, ['wsfe','ws_sr_padron_a13'], true), 'TRA con servicio correcto');
        arcaAssert(strtotime((string)$tra->header->generationTime) < time() && strtotime((string)$tra->header->expirationTime) > time(), 'TRA con ventana temporal válida');
        return ['loginCmsReturn' => '<loginTicketResponse><header><expirationTime>' . date('c', time() + 3600)
            . '</expirationTime></header><credentials><token>offline-token</token><sign>offline-sign</sign></credentials></loginTicketResponse>'];
    }
    if ($method === 'FEDummy') { return ['FEDummyResult' => ['AppServer' => 'OK','DbServer' => 'OK','AuthServer' => 'OK']]; }
    if ($method === 'FECompUltimoAutorizado') { return ['FECompUltimoAutorizadoResult' => ['PtoVta' => 2,'CbteTipo' => 1,'CbteNro' => 0]]; }
    if ($method === 'FECAESolicitar') {
        $state['detail'] = $params['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        if ($state['mode'] === 'timeout') { throw new RuntimeException('private-secret-and-token'); }
        if ($state['mode'] === 'rejected') { return ['FECAESolicitarResult' => ['FeCabResp' => ['Resultado' => 'R'],
            'FeDetResp' => ['FECAEDetResponse' => ['Resultado' => 'R','Observaciones' => ['Obs' => ['Code' => 10030,'Msg' => 'CUIT no registrada']]]]]]; }
        if ($state['mode'] === 'infrastructure') { return ['FECAESolicitarResult' => ['FeCabResp' => ['Resultado' => 'R'], 'Errors' => ['Err' => ['Code' => 500,'Msg' => 'error interno']]]]; }
        if ($state['mode'] === 'contradictory') { return ['FECAESolicitarResult' => ['FeCabResp' => ['Resultado' => 'R'], 'FeDetResp' => ['FECAEDetResponse' => ['Resultado' => 'R','CAE' => '12345678901234']]]]; }
        $d = $state['detail'];
        return ['FECAESolicitarResult' => ['FeCabResp' => ['PtoVta' => 2,'CbteTipo' => $params['FeCAEReq']['FeCabReq']['CbteTipo'],'Resultado' => 'A'],
            'FeDetResp' => ['FECAEDetResponse' => [array_intersect_key($d, array_flip(['DocTipo','DocNro','CbteDesde','CbteHasta','CbteFch'])) +
                ['Resultado' => 'A','CAE' => '12345678901234','CAEFchVto' => '20261105']]]]];
    }
    if ($method === 'FECompConsultar') {
        if ($state['mode'] === 'not_found') { return ['FECompConsultarResult' => ['Errors' => ['Err' => ['Code' => 602,'Msg' => 'No existen datos']]]]; }
        if ($state['mode'] === 'consult_error') { return ['FECompConsultarResult' => ['Errors' => ['Err' => ['Code' => 500,'Msg' => 'error interno']]]]; }
        $d = $state['detail'];
        if ($state['mode'] === 'mismatch_total') { $d['ImpTotal'] = '999.99'; }
        if ($state['mode'] === 'mismatch_document') { $d['DocNro'] = '20999999991'; }
        if ($state['mode'] === 'mismatch_vat') { $d['Iva']['AlicIva'][0]['Id'] = 4; }
        return ['FECompConsultarResult' => ['ResultGet' => $d + ['Resultado' => 'A','EmisionTipo' => 'CAE',
            'PtoVta' => 2,'CbteTipo' => 1,'CodAutorizacion' => '12345678901234','FchVto' => '20261105']]];
    }
    if ($method === 'getIdPersonaListByDocumento') { return ['idPersonaListReturn' => ['idPersona' => $state['padron_ids']]]; }
    if ($method === 'getPersona') { return ['personaReturn' => ['persona' => ['idPersona' => '20111111112','apellido' => 'Pérez','nombre' => 'Ana',
        'domicilio' => [['tipoDomicilio' => 'LEGAL/REAL','direccion' => 'Otra 2'], ['tipoDomicilio' => 'FISCAL','direccion' => 'Fiscal 1',
            'localidad' => 'Campana','descripcionProvincia' => 'Buenos Aires','codigoPostal' => '2804']]]]]; }
    throw new RuntimeException('Unexpected offline method: ' . $method);
};
try {
    $gateway = new ArcaGateway($settings, $directory, $transport);
    arcaAssert($gateway->lastAuthorized(2,1) === 0, 'Último autorizado');
    $result = $gateway->issue($invoice);
    arcaAssert($result['cae'] === '12345678901234' && $result['cae_due_date'] === '2026-11-05', 'Autorización');
    arcaAssert($state['detail']['Iva']['AlicIva'][0]['Id'] === 5 && $state['detail']['Iva']['AlicIva'][1]['Id'] === 4, 'IVA 21 y 10,5');
    arcaAssert($state['detail']['ImpTotal'] === '176.25' && $state['detail']['CondicionIVAReceptorId'] === 1, 'Importes y condición');
    arcaAssert($state['wsaa'] === 1, 'Ticket compartido dentro del mismo servicio');
    foreach ([6,13,16] as $condition) {
        $monotaxRecipient = $invoice; $monotaxRecipient['customer']['tax_condition'] = $condition;
        arcaAssert($gateway->issue($monotaxRecipient)['cae'] !== '', 'Factura A para monotributista ' . $condition);
    }
    $free = $invoice; $free['totals']['net_cents'] = 10000; $free['totals']['vat_cents'] = 2100; $free['totals']['total_cents'] = 12100;
    $free['totals']['vat_breakdown'][1]['base_cents'] = 0; $free['totals']['vat_breakdown'][1]['vat_cents'] = 0;
    $gateway->issue($free); arcaAssert(count($state['detail']['Iva']['AlicIva']) === 1, 'Producto gratis de otra alícuota no agrega IVA vacío');
    $gateway->issue($invoice);
    arcaAssert($gateway->consult($invoice)['number'] === 1, 'Recuperación segura');
    foreach (['mismatch_total','mismatch_document','mismatch_vat','consult_error'] as $mode) {
        $state['mode'] = $mode; arcaThrows(fn () => $gateway->consult($invoice));
    }
    $state['mode'] = 'not_found'; arcaAssert($gateway->consult($invoice) === null, 'Sólo 602 significa no encontrado');
    $state['mode'] = 'rejected'; arcaThrows(fn () => $gateway->issue($invoice), ArcaRejectedException::class);
    $state['mode'] = 'infrastructure'; $e = arcaThrows(fn () => $gateway->issue($invoice)); arcaAssert(!$e instanceof ArcaRejectedException, 'Infraestructura no es rechazo definitivo');
    $state['mode'] = 'contradictory'; $e = arcaThrows(fn () => $gateway->issue($invoice)); arcaAssert(!$e instanceof ArcaRejectedException, 'CAE más rechazo es resultado incierto');
    $state['mode'] = 'timeout'; $e = arcaThrows(fn () => $gateway->issue($invoice)); arcaAssert(!str_contains($e->getMessage(), 'private-secret'), 'No filtrar SOAP interno');
    $state['mode'] = 'approved';
    $customer = $gateway->lookupCustomer('CUIT','20-11111111-2');
    arcaAssert($customer['name'] === 'Pérez Ana' && $customer['address'] === 'Fiscal 1' && !array_key_exists('tax_condition',$customer), 'Padrón fiscal y condición no inferida');
    arcaAssert($state['wsaa'] === 2, 'Ticket de padrón separado de WSFE');
    $customer = $gateway->lookupCustomer('DNI','12345678'); arcaAssert($customer['document_type'] === 'DNI', 'DNI conservado');
    $state['padron_ids'][] = '27111111116'; arcaThrows(fn () => $gateway->lookupCustomer('DNI','12345678'));
    $bad = $invoice; $bad['number'] = 0; arcaThrows(fn () => $gateway->issue($bad));
    $bad = $invoice; $bad['totals']['total_cents']++; arcaThrows(fn () => $gateway->issue($bad));
    $bad = $invoice; $bad['customer']['tax_condition'] = 0; arcaThrows(fn () => $gateway->issue($bad));
    $leading = $invoice; $leading['voucher_type'] = 6; $leading['customer'] = ['document_type' => 'DNI','document_number' => '01234567','tax_condition' => 5];
    $gateway->issue($leading); arcaAssert($state['detail']['DocNro'] === '1234567', 'DNI se normaliza al entero SOAP para comparar respuesta');
    $mono = $settings; $mono['issuer_tax_condition'] = 6;
    $monoGateway = new ArcaGateway($mono, $directory, $transport);
    arcaThrows(fn () => $monoGateway->issue($invoice));
    $c = $invoice; $c['voucher_type'] = 11; $c['totals'] = ['net_cents' => 17625,'vat_cents' => 0,'total_cents' => 17625,'vat_breakdown' => []];
    $monoGateway->issue($c); arcaAssert(!isset($state['detail']['Iva']) && $state['detail']['ImpIVA'] === '0.00', 'C sin IVA discriminado');
    arcaAssert($gateway->health()['authenticated'] === true, 'Diagnóstico sin emisión');
    echo "ARCA: firma, IVA, emisión, conciliación, padrón y fallos verificados offline.\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
