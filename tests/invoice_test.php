<?php
declare(strict_types=1);

use FacturaFacil\ArcaRejectedException;
use FacturaFacil\Catalog;
use FacturaFacil\Database;
use FacturaFacil\InvoiceService;
use FacturaFacil\Settings;

foreach (['Database', 'Money', 'Catalog', 'Settings', 'ArcaGateway', 'InvoiceService'] as $class) {
    require_once dirname(__DIR__) . '/app/' . $class . '.php';
}
date_default_timezone_set('America/Argentina/Buenos_Aires');

final class InvoiceGatewayDouble
{
    public int $last = 0;
    public int $submissions = 0;
    public string $mode = 'accept';
    public array $remote = [];
    public array $configurations = [];
    public array $consultations = [];
    public array $requests = [];

    public function lastAuthorized(int $point, int $type): int { return $this->last; }

    public function issue(array $invoice): array
    {
        ++$this->submissions;
        $this->requests[] = $invoice;
        if ($this->mode === 'reject') { throw new ArcaRejectedException('Rechazo de prueba.'); }
        if ($this->mode === 'network_before_acceptance') { throw new RuntimeException('Conexión interrumpida.'); }
        $this->last = (int)$invoice['number'];
        $result = [
            'cae' => (string)(70000000000000 + $this->last),
            'cae_due_date' => date('Y-m-d', strtotime('+10 days')),
            'number' => $this->last,
            'observations' => [],
        ];
        $this->remote[(int)$invoice['number']] = $result;
        if ($this->mode === 'network_after_acceptance') { throw new RuntimeException('Conexión interrumpida tras recibir ARCA.'); }
        return $result;
    }

    public function consult(array $invoice): ?array
    {
        $this->consultations[] = $invoice;
        return $this->remote[(int)$invoice['number']] ?? null;
    }
}

$checks = 0;
$directories = [];
function expect(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('Falló: ' . $message); }
}
function rejects(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException $e) { expect(true, $message); return; }
    expect(false, $message);
}
function fixture(): array
{
    global $directories;
    $path = sys_get_temp_dir() . '/facturafacil-invoice-' . bin2hex(random_bytes(8));
    $directories[] = $path;
    $db = Database::connect($path);
    $settings = new Settings($db, $path);
    $settings->save(['company_name' => 'Empresa de prueba', 'cuit' => '20123456786']);
    // The gateway is replaced completely. These are fixture values, not usable credentials.
    foreach (['homologation_certificate_path' => $path . '/fixture.crt', 'homologation_private_key_path' => $path . '/fixture.key'] as $key => $value) {
        $q = $db->prepare('INSERT INTO settings(key,value) VALUES (?,?)');
        $q->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }
    $catalog = new Catalog($db);
    $customer = $catalog->save('customers', ['name' => 'Cliente original', 'document_type' => 'DNI', 'document_number' => '12345678', 'tax_condition' => 5]);
    $customer2 = $catalog->save('customers', ['name' => 'Otro cliente', 'document_type' => 'DNI', 'document_number' => '12345679', 'tax_condition' => 5]);
    $p21 = $catalog->save('products', ['code' => 'IVA21', 'name' => 'Producto 21', 'price' => '121', 'vat_rate' => '21']);
    $p105 = $catalog->save('products', ['code' => 'IVA105', 'name' => 'Producto 10,5', 'price' => '110.50', 'vat_rate' => '10.5']);
    $gateway = new InvoiceGatewayDouble();
    $service = new InvoiceService($db, $settings, $path, static function (array $config) use ($gateway): InvoiceGatewayDouble {
        $gateway->configurations[] = $config;
        return $gateway;
    });
    $input = ['customer_id' => $customer['id'], 'issue_date' => date('Y-m-d'), 'items' => [
        ['product_id' => $p21['id'], 'quantity' => '1'],
        ['product_id' => $p105['id'], 'quantity' => '1'],
    ]];
    return compact('path', 'db', 'settings', 'catalog', 'customer', 'customer2', 'p21', 'p105', 'gateway', 'service', 'input');
}
function batch(array $ids): array { return ['ids' => $ids, 'confirmed' => true, 'environment' => 'homologation']; }
function removeTree(string $path): void
{
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { removeTree($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}

try {
    $f = fixture();
    $invoice = $f['service']->create($f['input']);
    expect($invoice['voucher_type'] === 6 || (int)$invoice['voucher_type'] === 6, 'factura B automática a consumidor final');
    expect($invoice['net_cents'] === 20000 && $invoice['vat_cents'] === 3150 && $invoice['total_cents'] === 23150, 'ambas alícuotas mantienen neto, IVA y total exactos');
    expect(count($invoice['totals']['vat_breakdown']) === 2, 'se conservan los dos grupos de IVA');
    $f['catalog']->save('products', ['name' => 'Nombre nuevo', 'price' => '999'], (int)$f['p21']['id']);
    $f['catalog']->save('customers', ['name' => 'Cliente modificado'], (int)$f['customer']['id']);
    $snapshot = $f['service']->find($invoice['id']);
    expect($snapshot['customer']['name'] === 'Cliente original' && $snapshot['items'][0]['name'] === 'Producto 21', 'las ediciones no alteran datos históricos');
    expect($snapshot['total_cents'] === 23150, 'editar precio no altera el borrador existente');
    $result = $f['service']->issueBatch(batch([$invoice['id']]));
    expect($result['results'][0]['status'] === 'authorized' && $f['gateway']->submissions === 1, 'emisión autorizada');
    $f['service']->issueBatch(batch([$invoice['id']]));
    expect($f['gateway']->submissions === 1, 'repetir el pedido no genera segunda emisión');
    rejects(fn() => $f['service']->delete($invoice['id']), 'no se borra una factura fiscal numerada');
    rejects(fn() => $f['service']->issueBatch(['ids' => [$invoice['id']], 'environment' => 'homologation']), 'emisión requiere confirmación expresa');
    rejects(fn() => $f['service']->issueBatch(['ids' => [$invoice['id']], 'confirmed' => true, 'environment' => 'production']), 'no se permite confirmar otro ambiente');

    $f = fixture();
    $count = (int)$f['db']->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
    rejects(fn() => $f['service']->bulkCreate($f['input'] + ['customer_ids' => [$f['customer']['id'], 999999]]), 'lote con cliente inexistente se rechaza');
    expect((int)$f['db']->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === $count, 'lote inválido no deja borradores parciales');
    $created = $f['service']->bulkCreate($f['input'] + ['customer_ids' => [$f['customer']['id'], $f['customer2']['id']]]);
    expect(count($created) === 2 && $created[0]['customer_id'] !== $created[1]['customer_id'], 'se crea un borrador por cada cliente seleccionado');
    $f['settings']->save(['company_name' => 'Otro emisor']);
    rejects(fn() => $f['service']->issueBatch(batch([$created[0]['id']])), 'no se emiten borradores con configuración fiscal modificada');
    expect($f['gateway']->submissions === 0, 'cambio del emisor se detecta antes de enviar a ARCA');

    $f = fixture();
    $first = $f['service']->create($f['input']);
    $second = $f['service']->create($f['input']);
    $f['gateway']->mode = 'network_after_acceptance';
    $result = $f['service']->issueBatch(batch([$first['id']]));
    expect($result['results'][0]['status'] === 'uncertain', 'corte de red no se interpreta como rechazo');
    expect((int)$f['service']->find($first['id'])['number'] === 1, 'el número se guarda antes del envío');
    $f['gateway']->mode = 'accept';
    $blocked = $f['service']->issueBatch(batch([$second['id']]));
    expect($blocked['results'][0]['status'] !== 'authorized' && $f['gateway']->submissions === 1, 'no se avanza la serie mientras hay emisión incierta');
    $recovered = $f['service']->reconcile($first['id']);
    expect($recovered['status'] === 'authorized' && $recovered['cae'] === '70000000000001', 'conciliación recupera el CAE de ARCA');
    expect($f['gateway']->submissions === 1, 'conciliar consulta sin reemitir');
    $result = $f['service']->issueBatch(batch([$second['id']]));
    expect($result['results'][0]['status'] === 'authorized' && (int)$result['results'][0]['number'] === 2, 'la serie continúa después de conciliar');

    $f = fixture();
    $first = $f['service']->create($f['input']);
    $second = $f['service']->create($f['input']);
    $f['gateway']->mode = 'network_before_acceptance';
    $f['service']->issueBatch(batch([$first['id']]));
    $reconciled = $f['service']->reconcile($first['id']);
    expect($reconciled['status'] === 'rejected' && (int)$reconciled['number'] === 1, 'sin autorización se conserva el número reservado para reintentar');
    rejects(fn() => $f['service']->delete($first['id']), 'no se borra una reserva fiscal tras un corte');
    $f['gateway']->mode = 'accept';
    $blocked = $f['service']->issueBatch(batch([$second['id']]));
    expect($blocked['results'][0]['status'] !== 'authorized' && $f['gateway']->submissions === 1, 'conciliar sin autorización mantiene bloqueados otros borradores de la serie');
    $result = $f['service']->issueBatch(batch([$first['id']]));
    expect($result['results'][0]['status'] === 'authorized' && (int)$result['results'][0]['number'] === 1, 'reintento conserva número y datos originales');
    $result = $f['service']->issueBatch(batch([$second['id']]));
    expect($result['results'][0]['status'] === 'authorized' && (int)$result['results'][0]['number'] === 2, 'se libera la serie después de resolver la reserva y autorizarla');

    $f = fixture();
    $first = $f['service']->create($f['input']);
    $f['gateway']->mode = 'reject';
    $result = $f['service']->issueBatch(batch([$first['id']]));
    expect($result['results'][0]['status'] === 'rejected', 'rechazo fiscal expreso se distingue de un corte de red');
    expect($f['service']->find($first['id'])['number'] === null, 'rechazo expreso libera el número fiscal que ARCA no consumió');
    $lock = fopen($f['path'] . '/issuance.lock', 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    rejects(fn() => $f['service']->issueBatch(batch([$first['id']])), 'otra emisión concurrente no puede tomar el mismo bloqueo');
    flock($lock, LOCK_UN);
    fclose($lock);
    $second = $f['service']->create($f['input']);
    $f['gateway']->mode = 'accept';
    $result = $f['service']->issueBatch(batch([$second['id']]));
    expect($result['results'][0]['status'] === 'authorized' && (int)$result['results'][0]['number'] === 1, 'otro borrador puede usar el número liberado por un rechazo expreso');

    $f = fixture();
    $f['settings']->save(['issuer_tax_condition' => 6, 'default_voucher_type' => 11]);
    $invoiceC = $f['service']->create($f['input']);
    expect((int)$invoiceC['voucher_type'] === 11 && $invoiceC['vat_cents'] === 0 && $invoiceC['net_cents'] === $invoiceC['total_cents'], 'monotributista crea factura C sin discriminar IVA');

    $f = fixture();
    $first = $f['service']->create($f['input']);
    $f['gateway']->mode = 'network_after_acceptance';
    $f['service']->issueBatch(batch([$first['id']]));
    $q = $f['db']->prepare('UPDATE invoices SET issue_date=? WHERE id=?');
    $q->execute([date('Y-m-d', strtotime('-6 days')), $first['id']]);
    $recovered = $f['service']->reconcile($first['id']);
    expect($recovered['status'] === 'authorized', 'conciliación recupera un pendiente aunque tenga más de cinco días');

    $f = fixture();
    $first = $f['service']->create($f['input']);
    $second = $f['service']->create($f['input']);
    $f['gateway']->mode = 'network_after_acceptance';
    $f['service']->issueBatch(batch([$first['id']]));
    $f['settings']->save([
        'company_name' => 'Nombre actualizado', 'address' => 'Otra dirección 123',
        'point_of_sale' => 2, 'issuer_tax_condition' => 6, 'default_voucher_type' => 11,
    ]);
    $recovered = $f['service']->reconcile($first['id']);
    expect($recovered['status'] === 'authorized', 'cambiar datos actuales del emisor no impide recuperar un pendiente histórico');
    $consulted = $f['gateway']->consultations[count($f['gateway']->consultations) - 1];
    $config = $f['gateway']->configurations[count($f['gateway']->configurations) - 1];
    expect((int)$consulted['point_of_sale'] === 1 && $consulted['issuer']['company_name'] === 'Empresa de prueba', 'conciliación conserva punto de venta y datos históricos del comprobante');
    expect((int)$config['issuer_tax_condition'] === 1, 'conciliación usa la condición original del emisor aunque cambie la actual');
    rejects(fn() => $f['service']->issueBatch(batch([$second['id']])), 'la emisión nueva sigue exigiendo coincidencia de la configuración fiscal');

    $f = fixture();
    $first = $f['service']->create($f['input']);
    $f['gateway']->mode = 'network_before_acceptance';
    $f['service']->issueBatch(batch([$first['id']]));
    $oldDate = date('Y-m-d', strtotime('-6 days'));
    // Simulate six days passing without making a real fiscal request.
    $f['db']->prepare('UPDATE invoices SET issue_date=? WHERE id=?')->execute([$oldDate, $first['id']]);
    $f['settings']->save([
        'company_name' => 'Nombre nuevo', 'address' => 'Dirección nueva 456',
        'point_of_sale' => 2, 'issuer_tax_condition' => 6, 'default_voucher_type' => 11,
    ]);
    $reconciled = $f['service']->reconcile($first['id']);
    expect($reconciled['status'] === 'rejected' && (int)$reconciled['number'] === 1, 'reserva antigua sin autorización mantiene su identidad fiscal');
    $f['gateway']->mode = 'reject';
    $result = $f['service']->issueBatch(batch([$first['id']]));
    expect($f['gateway']->submissions === 2, 'reserva antigua puede reintentarse pese a cambios de configuración y fecha');
    $request = $f['gateway']->requests[count($f['gateway']->requests) - 1];
    $config = $f['gateway']->configurations[count($f['gateway']->configurations) - 1];
    expect((int)$request['number'] === 1 && (int)$request['point_of_sale'] === 1 && $request['issue_date'] === $oldDate, 'reintento envía número, serie y fecha originales sin actualizarlos');
    expect((int)$config['issuer_tax_condition'] === 1 && $request['issuer']['company_name'] === 'Empresa de prueba', 'reintento conserva la condición y los datos originales del emisor');
    expect($result['results'][0]['status'] === 'rejected' && $f['service']->find($first['id'])['number'] === null, 'rechazo definitivo del reintento libera la reserva antigua');
    $f['settings']->save([
        'company_name' => 'Empresa de prueba', 'address' => '',
        'point_of_sale' => 1, 'issuer_tax_condition' => 1, 'default_voucher_type' => 6,
    ]);
    $second = $f['service']->create($f['input']);
    $f['gateway']->mode = 'accept';
    $result = $f['service']->issueBatch(batch([$second['id']]));
    expect($result['results'][0]['status'] === 'authorized' && (int)$result['results'][0]['number'] === 1, 'otra factura continúa la serie después del rechazo definitivo de la reserva antigua');

    $f = fixture();
    $draft = $f['service']->create($f['input']);
    $f['service']->delete($draft['id']);
    expect((int)$f['db']->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 0, 'se elimina un borrador sin numeración');
    echo "InvoiceService: {$checks} verificaciones correctas.\n";
} finally {
    unset($f);
    foreach ($directories as $directory) { removeTree($directory); }
}
