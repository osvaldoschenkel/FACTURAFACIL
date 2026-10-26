<?php
declare(strict_types=1);

namespace FacturaFacil;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/** A definitive business rejection; a transport exception must never be treated as a rejection. */
class ArcaRejectedException extends RuntimeException {}

/**
 * SOAP WSAA / WSFEv1 / Padrón A13, with no third-party fiscal intermediary.
 * Protocol references (reviewed 2026-10-26):
 * https://www.arca.gob.ar/ws/documentacion/manuales/manual-desarrollador-ARCA-COMPG.pdf
 * https://www.arca.gob.ar/ws/WSAA/WSAAmanualDev.pdf
 * https://www.arca.gob.ar/ws/ws-padron-a13/manual-ws-sr-padron-a13-v1.4.pdf
 * The optional transport replaces SOAP, not authentication, and is intended for offline tests.
 */
class ArcaGateway
{
    private array $settings;
    private string $storage;
    private mixed $transport;
    private array $clients = [];

    public function __construct(array $settings, string $storagePath, ?callable $transport = null)
    {
        $this->settings = $settings;
        $this->storage = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->transport = $transport;
        if (!in_array($settings['arca_environment'] ?? 'homologation', ['homologation', 'production'], true)) {
            throw new RuntimeException('Ambiente ARCA inválido.');
        }
    }

    public function lastAuthorized(int $point, int $type): int
    {
        $this->validateSeries($point, $type);
        $r = $this->wsfe('FECompUltimoAutorizado', ['PtoVta' => $point, 'CbteTipo' => $type]);
        $this->checkErrors($r);
        if (!isset($r['CbteNro']) || !is_numeric($r['CbteNro']) || (int) $r['CbteNro'] < 0
            || (int) ($r['PtoVta'] ?? $point) !== $point || (int) ($r['CbteTipo'] ?? $type) !== $type) {
            throw new RuntimeException('ARCA no devolvió una numeración válida.');
        }
        return (int) $r['CbteNro'];
    }

    public function issue(array $invoice): array
    {
        $detail = $this->buildDetail($invoice);
        $point = (int) $invoice['point_of_sale'];
        $type = (int) $invoice['voucher_type'];
        $r = $this->wsfe('FECAESolicitar', ['FeCAEReq' => [
            'FeCabReq' => ['CantReg' => 1, 'PtoVta' => $point, 'CbteTipo' => $type],
            'FeDetReq' => ['FECAEDetRequest' => [$detail]],
        ]]);
        $rows = self::rows($r['FeDetResp']['FECAEDetResponse'] ?? []);
        $response = count($rows) === 1 ? $rows[0] : [];
        if (($response['Resultado'] ?? '') === 'R' || ($r['FeCabResp']['Resultado'] ?? '') === 'R') {
            if (count($rows) > 1 || !empty($response['CAE']) || ($response['Resultado'] ?? '') === 'A') {
                throw new RuntimeException('ARCA devolvió un resultado contradictorio. Consultá el comprobante antes de reintentar.');
            }
            $messages = array_merge(self::messages($r['Errors']['Err'] ?? []), self::messages($response['Observaciones']['Obs'] ?? []));
            // Infrastructure failures can leave an unknown fiscal result. They are never safe to retry blindly.
            foreach (self::rows($r['Errors']['Err'] ?? []) as $error) {
                if ((int) ($error['Code'] ?? 0) < 1000) {
                    throw new RuntimeException('No se pudo confirmar el resultado fiscal. Consultá el comprobante antes de reintentar.');
                }
            }
            throw new ArcaRejectedException('ARCA rechazó el comprobante: ' . implode(' · ', $messages ?: ['sin detalle']));
        }
        $this->checkErrors($r);
        if (($response['Resultado'] ?? '') !== 'A'
            || (int) ($response['CbteDesde'] ?? 0) !== (int) $invoice['number']
            || (int) ($response['CbteHasta'] ?? 0) !== (int) $invoice['number']
            || (int) ($response['DocTipo'] ?? -1) !== $detail['DocTipo']
            || (string) ($response['DocNro'] ?? '') !== (string) $detail['DocNro']
            || (string) ($response['CbteFch'] ?? '') !== $detail['CbteFch']
            || (int) ($r['FeCabResp']['PtoVta'] ?? 0) !== $point
            || (int) ($r['FeCabResp']['CbteTipo'] ?? 0) !== $type) {
            throw new RuntimeException('Respuesta fiscal incompleta o diferente de la solicitud. Es necesario consultar el comprobante.');
        }
        return $this->authorization($response['CAE'] ?? '', $response['CAEFchVto'] ?? '', (int) $invoice['number'], $response);
    }

    /** null means ARCA explicitly reports 602, not a timeout, rejection, or mismatch. */
    public function consult(array $invoice): ?array
    {
        $detail = $this->buildDetail($invoice);
        $r = $this->wsfe('FECompConsultar', ['FeCompConsReq' => [
            'CbteTipo' => (int) $invoice['voucher_type'], 'CbteNro' => (int) $invoice['number'],
            'PtoVta' => (int) $invoice['point_of_sale'],
        ]]);
        $errors = self::rows($r['Errors']['Err'] ?? []);
        if (count($errors) === 1 && (int) ($errors[0]['Code'] ?? 0) === 602 && empty($r['ResultGet'])) {
            return null;
        }
        $this->checkErrors($r);
        $found = $r['ResultGet'] ?? [];
        if (($found['Resultado'] ?? '') !== 'A' || ($found['EmisionTipo'] ?? '') !== 'CAE') {
            throw new RuntimeException('La consulta no devolvió una autorización CAE válida.');
        }
        foreach (['Concepto', 'DocTipo', 'DocNro', 'CbteDesde', 'CbteHasta', 'CbteFch', 'MonId'] as $field) {
            if (!array_key_exists($field, $found) || (string) $found[$field] !== (string) $detail[$field]) {
                throw new RuntimeException('El comprobante de ARCA no coincide con el borrador guardado. Requiere revisión manual.');
            }
        }
        if ((int) ($found['PtoVta'] ?? 0) !== (int) $invoice['point_of_sale']
            || (int) ($found['CbteTipo'] ?? 0) !== (int) $invoice['voucher_type']
            || (float) ($found['MonCotiz'] ?? 0) !== 1.0) {
            throw new RuntimeException('La serie o moneda consultada no coincide con el borrador.');
        }
        foreach (['ImpTotal', 'ImpNeto', 'ImpIVA', 'ImpTotConc', 'ImpOpEx', 'ImpTrib'] as $field) {
            if (!array_key_exists($field, $found) || self::cents($found[$field]) !== self::cents($detail[$field])) {
                throw new RuntimeException('Los importes autorizados no coinciden con el borrador. Requiere revisión manual.');
            }
        }
        $expectedVat = $detail['Iva']['AlicIva'] ?? [];
        $actualVat = self::rows($found['Iva']['AlicIva'] ?? []);
        $sort = static function (array &$rows): void { usort($rows, static fn (array $a, array $b): int => (int) $a['Id'] <=> (int) $b['Id']); };
        $sort($expectedVat);
        $sort($actualVat);
        if (count($expectedVat) !== count($actualVat)) {
            throw new RuntimeException('El IVA autorizado no coincide con el borrador.');
        }
        foreach ($expectedVat as $i => $vat) {
            if ((int) ($actualVat[$i]['Id'] ?? 0) !== (int) $vat['Id']
                || self::cents($actualVat[$i]['BaseImp'] ?? -1) !== self::cents($vat['BaseImp'])
                || self::cents($actualVat[$i]['Importe'] ?? -1) !== self::cents($vat['Importe'])) {
                throw new RuntimeException('Las alícuotas autorizadas no coinciden con el borrador.');
            }
        }
        if (isset($found['CondicionIVAReceptorId']) && (int) $found['CondicionIVAReceptorId'] !== $detail['CondicionIVAReceptorId']) {
            throw new RuntimeException('La condición de IVA autorizada no coincide con el borrador.');
        }
        return $this->authorization($found['CodAutorizacion'] ?? '', $found['FchVto'] ?? '', (int) $invoice['number'], $found);
    }

    public function lookupCustomer(string $documentType, string $document): array
    {
        $type = strtoupper($documentType);
        $number = preg_replace('/[\s.-]/', '', $document);
        if (!in_array($type, ['CUIT', 'CUIL', 'DNI'], true) || !preg_match($type === 'DNI' ? '/^\d{7,8}$/' : '/^\d{11}$/', $number)) {
            throw new RuntimeException('Ingresá CUIT/CUIL de 11 dígitos o DNI de 7 u 8 dígitos.');
        }
        $auth = $this->credentials('ws_sr_padron_a13');
        $params = ['token' => $auth['token'], 'sign' => $auth['sign'], 'cuitRepresentada' => $this->cuit()];
        $personId = $number;
        if ($type === 'DNI') {
            $ids = $this->call('padron', 'getIdPersonaListByDocumento', $params + ['documento' => $number]);
            $list = $ids['idPersonaListReturn']['idPersona'] ?? [];
            $list = is_array($list) ? $list : [$list];
            $list = array_values(array_unique(array_filter(array_map('strval', $list), static fn (string $id): bool => preg_match('/^\d{11}$/', $id) === 1)));
            if (count($list) !== 1) {
                throw new RuntimeException(count($list) > 1 ? 'El DNI tiene más de una clave asociada. Consultá por CUIT/CUIL.' : 'No se encontró una clave asociada al DNI.');
            }
            $personId = $list[0];
        }
        $r = $this->call('padron', 'getPersona', $params + ['idPersona' => $personId]);
        $person = $r['personaReturn']['persona'] ?? [];
        if (empty($person) || (string) ($person['idPersona'] ?? '') !== $personId) {
            throw new RuntimeException('ARCA no encontró los datos del cliente. Podés cargarlos manualmente.');
        }
        $name = trim((string) ($person['razonSocial'] ?? ''));
        if ($name === '') {
            $name = trim(($person['apellido'] ?? '') . ' ' . ($person['nombre'] ?? ''));
        }
        if ($name === '') {
            throw new RuntimeException('El padrón no devolvió un nombre o razón social.');
        }
        $addresses = self::rows($person['domicilio'] ?? []);
        $address = $addresses[0] ?? [];
        foreach ($addresses as $candidate) {
            if (($candidate['tipoDomicilio'] ?? '') === 'FISCAL') { $address = $candidate; break; }
        }
        // A13 is identity data, not proof of the recipient's VAT registration.
        return ['name' => $name, 'document_type' => $type, 'document_number' => $number,
            'address' => (string) ($address['direccion'] ?? ''), 'city' => (string) ($address['localidad'] ?? ''),
            'province' => (string) ($address['descripcionProvincia'] ?? ''),
            'postal_code' => (string) ($address['codigoPostal'] ?? $address['codPostal'] ?? ''),
        ];
    }

    /** Read-only service diagnostics; no invoice is submitted. */
    public function health(): array
    {
        $dummy = $this->call('wsfe', 'FEDummy', []);
        $this->credentials('wsfe');
        return ['environment' => $this->settings['arca_environment'] ?? 'homologation',
            'authenticated' => true, 'services' => $dummy['FEDummyResult'] ?? $dummy];
    }

    private function buildDetail(array $invoice): array
    {
        $point = (int) ($invoice['point_of_sale'] ?? 0);
        $type = (int) ($invoice['voucher_type'] ?? 0);
        $this->validateSeries($point, $type);
        $number = (int) ($invoice['number'] ?? 0);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($invoice['issue_date'] ?? ''), new DateTimeZone('America/Argentina/Buenos_Aires'));
        if ($number < 1 || $number > 99999999 || !$date || $date->format('Y-m-d') !== ($invoice['issue_date'] ?? '')) {
            throw new RuntimeException('El comprobante necesita número fiscal reservado y fecha válida.');
        }
        $customer = $invoice['customer'] ?? [];
        $documentType = ['CUIT' => 80, 'CUIL' => 86, 'DNI' => 96, 'CF' => 99][$customer['document_type'] ?? ''] ?? null;
        $document = (string) ($customer['document_number'] ?? '');
        if ($documentType === null || ($documentType !== 99 && !ctype_digit($document))
            || (in_array($documentType, [80,86], true) && strlen($document) !== 11)
            || ($documentType === 96 && !preg_match('/^\d{7,8}$/', $document))
            || ($type === 1 && $documentType !== 80)) {
            throw new RuntimeException('Documento del receptor inválido para el tipo de factura.');
        }
        $condition = (int) ($customer['tax_condition'] ?? 0);
        if (!in_array($condition, [1,4,5,6,7,8,9,10,13,15,16], true)
            || ($documentType === 99 && $condition !== 5)) {
            throw new RuntimeException('Completá la condición frente al IVA del cliente.');
        }
        $totals = $invoice['totals'] ?? $invoice;
        foreach (['net_cents', 'vat_cents', 'total_cents'] as $field) {
            if (!isset($totals[$field]) || !is_int($totals[$field]) || $totals[$field] < 0 || $totals[$field] > 999999999999999) {
                throw new RuntimeException('Los importes deben estar calculados en centavos enteros.');
            }
        }
        if ($totals['total_cents'] <= 0 || $totals['net_cents'] + $totals['vat_cents'] !== $totals['total_cents']) {
            throw new RuntimeException('El total del comprobante no coincide con neto más IVA.');
        }
        $issuerCondition = (int) ($this->settings['issuer_tax_condition'] ?? 1);
        if (($issuerCondition === 1 && !in_array($type, [1,6], true))
            || (in_array($issuerCondition, [4,6], true) && $type !== 11)
            || !in_array($issuerCondition, [1,4,6], true)) {
            throw new RuntimeException('El tipo de factura no corresponde a la condición fiscal del emisor.');
        }
        if ($issuerCondition === 1 && $type !== (in_array($condition, [1,6,13,16], true) ? 1 : 6)) {
            throw new RuntimeException('La clase de factura no corresponde a la condición de IVA del receptor.');
        }
        $detail = ['Concepto' => 1, 'DocTipo' => $documentType, 'DocNro' => $documentType === 99 ? '0' : (string) (int) $document,
            'CbteDesde' => $number, 'CbteHasta' => $number, 'CbteFch' => $date->format('Ymd'),
            'ImpTotal' => self::money($totals['total_cents']), 'ImpTotConc' => '0.00',
            'ImpNeto' => self::money($totals['net_cents']), 'ImpOpEx' => '0.00',
            'ImpIVA' => self::money($totals['vat_cents']), 'ImpTrib' => '0.00',
            'MonId' => 'PES', 'MonCotiz' => 1, 'CondicionIVAReceptorId' => $condition];
        if ($type === 11) {
            if ($totals['vat_cents'] !== 0) { throw new RuntimeException('Las facturas C no discriminan IVA.'); }
            return $detail;
        }
        $vatRows = $totals['vat_breakdown'] ?? $invoice['vat_breakdown'] ?? [];
        $base = 0; $vat = 0; $seen = [];
        foreach ($vatRows as $row) {
            $rate = (string) ($row['rate'] ?? '');
            if (!in_array($rate, ['10.5','21'], true) || isset($seen[$rate])
                || !is_int($row['base_cents'] ?? null) || !is_int($row['vat_cents'] ?? null)
                || $row['base_cents'] < 0 || $row['vat_cents'] < 0) {
                throw new RuntimeException('Desglose de IVA inválido. Se admite 10,5% y 21%.');
            }
            $seen[$rate] = true;
            if ($row['base_cents'] === 0 && $row['vat_cents'] === 0) { continue; }
            if ($row['base_cents'] === 0) { throw new RuntimeException('Una alícuota sin base imponible no puede tener IVA.'); }
            $base += $row['base_cents']; $vat += $row['vat_cents'];
            $detail['Iva']['AlicIva'][] = ['Id' => $rate === '21' ? 5 : 4,
                'BaseImp' => self::money($row['base_cents']), 'Importe' => self::money($row['vat_cents'])];
        }
        if ($base !== $totals['net_cents'] || $vat !== $totals['vat_cents'] || $base === 0) {
            throw new RuntimeException('El desglose de IVA no coincide con los totales.');
        }
        return $detail;
    }

    private function validateSeries(int $point, int $type): void
    {
        if ($point < 1 || $point > 99998 || !in_array($type, [1,6,11], true)) {
            throw new RuntimeException('Configurá un punto de venta y tipo de factura válidos.');
        }
    }

    private function wsfe(string $method, array $params): array
    {
        $auth = $this->credentials('wsfe');
        $r = $this->call('wsfe', $method, ['Auth' => ['Token' => $auth['token'], 'Sign' => $auth['sign'], 'Cuit' => $this->cuit()]] + $params);
        return $r[$method . 'Result'] ?? $r;
    }

    private function cuit(): string
    {
        $cuit = (string) ($this->settings['cuit'] ?? '');
        if (!preg_match('/^\d{11}$/', $cuit)) { throw new RuntimeException('Configurá el CUIT del emisor.'); }
        return $cuit;
    }

    private function credentials(string $service): array
    {
        $cuit = $this->cuit();
        foreach (['certificate_path', 'private_key_path'] as $field) {
            $file = (string) ($this->settings[$field] ?? '');
            if ($file === '' || str_contains($file, '://') || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Configurá el certificado y la clave privada de ARCA en archivos locales protegidos.');
            }
        }
        $certificate = (string) file_get_contents($this->settings['certificate_path']);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if ($fingerprint === false) { throw new RuntimeException('El certificado ARCA no es válido.'); }
        $cacheDir = $this->storage . '/arca-cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('No se pudo crear la caché privada de ARCA.');
        }
        $key = hash('sha256', ($this->settings['arca_environment'] ?? 'homologation') . '|' . $cuit . '|' . $service . '|' . $fingerprint);
        $path = $cacheDir . '/' . $key . '.json';
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('No se pudo bloquear la autenticación ARCA.'); }
        @chmod($path . '.lock', 0600);
        try {
            if (is_file($path)) {
                $cached = json_decode((string) file_get_contents($path), true);
                if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time() + 120 && !empty($cached['token']) && !empty($cached['sign'])) { return $cached; }
            }
            $cms = $this->signTicket($service, $cacheDir);
            $r = $this->call('wsaa', 'loginCms', ['in0' => $cms]);
            $xml = (string) ($r['loginCmsReturn'] ?? '');
            if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) { throw new RuntimeException('WSAA devolvió una respuesta inválida.'); }
            $previous = libxml_use_internal_errors(true);
            try { $ticket = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            $data = $ticket === false ? [] : ['token' => (string) $ticket->credentials->token,
                'sign' => (string) $ticket->credentials->sign, 'expires' => strtotime((string) $ticket->header->expirationTime)];
            if (empty($data['token']) || empty($data['sign']) || (int) ($data['expires'] ?? 0) <= time() + 120) {
                throw new RuntimeException('WSAA no devolvió credenciales vigentes.');
            }
            $tmp = tempnam($cacheDir, 'ta-');
            if ($tmp === false) { throw new RuntimeException('No se pudo guardar la autenticación ARCA.'); }
            try {
                chmod($tmp, 0600);
                if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) {
                    throw new RuntimeException('No se pudo guardar la autenticación ARCA.');
                }
            } finally { if (is_file($tmp)) { unlink($tmp); } }
            return $data;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function signTicket(string $service, string $directory): string
    {
        if (!in_array($service, ['wsfe', 'ws_sr_padron_a13'], true)) { throw new RuntimeException('Servicio ARCA inválido.'); }
        $certificate = (string) file_get_contents($this->settings['certificate_path']);
        $privateKey = openssl_pkey_get_private((string) file_get_contents($this->settings['private_key_path']), (string) ($this->settings['private_key_passphrase'] ?? ''));
        $info = openssl_x509_parse($certificate);
        if ($privateKey === false || !openssl_x509_check_private_key($certificate, $privateKey)
            || !$info || ($info['validTo_time_t'] ?? 0) <= time() || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > time()) {
            throw new RuntimeException('El certificado está vencido, todavía no es válido o la clave privada no corresponde.');
        }
        $requestFile = tempnam($directory, 'tra-'); $signedFile = tempnam($directory, 'cms-');
        if ($requestFile === false || $signedFile === false) { throw new RuntimeException('No se pudo preparar la firma ARCA.'); }
        try {
            chmod($requestFile, 0600); chmod($signedFile, 0600);
            $now = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
            $xml = '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>'
                . time() . '</uniqueId><generationTime>' . $now->modify('-5 minutes')->format('c')
                . '</generationTime><expirationTime>' . $now->modify('+10 minutes')->format('c')
                . '</expirationTime></header><service>' . $service . '</service></loginTicketRequest>';
            if (file_put_contents($requestFile, $xml) === false || !openssl_pkcs7_sign($requestFile, $signedFile, $certificate, $privateKey, [], PKCS7_BINARY)) {
                throw new RuntimeException('No se pudo firmar la solicitud de acceso ARCA.');
            }
            $parts = preg_split('/\r?\n\r?\n/', (string) file_get_contents($signedFile), 2);
            $cms = preg_replace('/\s+/', '', $parts[1] ?? '');
            if ($cms === '' || base64_decode($cms, true) === false) { throw new RuntimeException('La firma CMS no es válida.'); }
            return $cms;
        } finally { if (is_file($requestFile)) { unlink($requestFile); } if (is_file($signedFile)) { unlink($signedFile); } }
    }

    private function call(string $service, string $method, array $params): array
    {
        try {
            if ($this->transport !== null) {
                $r = ($this->transport)($service, $method, $params);
            } else {
                if (!class_exists('SoapClient')) { throw new RuntimeException('Falta habilitar la extensión SOAP de PHP.'); }
                $production = ($this->settings['arca_environment'] ?? 'homologation') === 'production';
                $urls = [
                    'wsaa' => $production ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL' : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL',
                    'wsfe' => $production ? 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL' : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL',
                    'padron' => $production ? 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL' : 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL',
                ];
                if (!isset($urls[$service])) { throw new RuntimeException('Servicio no permitido.'); }
                if (!isset($this->clients[$service])) {
                    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT],
                        'http' => ['timeout' => 35, 'follow_location' => 0]]);
                    $this->clients[$service] = new \SoapClient($urls[$service], ['exceptions' => true, 'trace' => false,
                        'cache_wsdl' => WSDL_CACHE_NONE, 'connection_timeout' => 15, 'stream_context' => $context,
                        'features' => SOAP_SINGLE_ELEMENT_ARRAYS, 'encoding' => 'UTF-8', 'soap_version' => SOAP_1_1]);
                }
                $r = $this->clients[$service]->__soapCall($method, [$params]);
            }
            $array = json_decode(json_encode($r, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($array)) { throw new RuntimeException('Respuesta SOAP inválida.'); }
            return $array;
        } catch (Throwable $e) {
            // SOAP faults may include signed tickets and private filesystem paths: never expose them.
            throw new RuntimeException('No se pudo confirmar la respuesta de ARCA. Revisá configuración y conexión; si estabas emitiendo, consultá el comprobante antes de reintentar.', 0, $e);
        }
    }

    private function checkErrors(array $result): void
    {
        if (!empty($result['Errors']['Err'])) {
            throw new RuntimeException('ARCA informó: ' . implode(' · ', self::messages($result['Errors']['Err'])));
        }
    }

    private function authorization(mixed $cae, mixed $due, int $number, array $result): array
    {
        $cae = (string) $cae; $due = (string) $due;
        $date = DateTimeImmutable::createFromFormat('!Ymd', $due);
        if (!preg_match('/^\d{14}$/', $cae) || !$date || $date->format('Ymd') !== $due) {
            throw new RuntimeException('ARCA no devolvió CAE y vencimiento válidos. Consultá el resultado antes de reintentar.');
        }
        return ['cae' => $cae, 'cae_due_date' => $date->format('Y-m-d'), 'number' => $number,
            'observations' => self::messages($result['Observaciones']['Obs'] ?? [])];
    }

    private static function rows(mixed $value): array
    {
        if (!is_array($value) || $value === []) { return []; }
        return array_is_list($value) ? $value : [$value];
    }

    private static function messages(mixed $value): array
    {
        return array_values(array_map(static fn (array $row): string => (string) ($row['Code'] ?? '') . ': ' . substr((string) ($row['Msg'] ?? ''), 0, 700), self::rows($value)));
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function cents(mixed $value): int
    {
        if (!is_numeric($value)) { throw new RuntimeException('ARCA devolvió un importe inválido.'); }
        return (int) round((float) $value * 100);
    }
}
