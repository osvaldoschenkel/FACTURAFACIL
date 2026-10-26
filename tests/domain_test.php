<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/Money.php';
require_once __DIR__ . '/../app/Catalog.php';
require_once __DIR__ . '/../app/Settings.php';

use FacturaFacil\Catalog;
use FacturaFacil\Database;
use FacturaFacil\Money;
use FacturaFacil\Settings;

function domainCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function domainReject(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$storage = sys_get_temp_dir() . '/facturafacil-domain-' . bin2hex(random_bytes(6));
try {
    $db = Database::connect($storage);
    Database::connect($storage); // Migration is idempotent and does not clear records.
    domainCheck(Money::parse('1234,56') === 123456, 'Spanish decimals must parse exactly.');
    domainCheck(Money::parse('1234.56') === 123456, 'Dot decimals must parse exactly.');
    domainReject(fn() => Money::parse('1.234'), 'Ambiguous thousands must be rejected.');
    domainReject(fn() => Money::parse('1.234,56'), 'Thousands separators must be rejected.');
    domainReject(fn() => Money::parse('-1'), 'Negative prices must be rejected.');
    domainReject(fn() => Money::calculate([['name' => 'Test', 'price' => '100', 'quantity' => '0', 'vat_rate' => '21']]), 'Zero quantity must be rejected.');
    $totals = Money::calculate([
        ['name' => 'IVA 21', 'quantity' => '2', 'price' => '121', 'vat_rate' => '21'],
        ['name' => 'IVA 10,5', 'quantity' => '0,5', 'price' => '221', 'vat_rate' => '10.5'],
    ]);
    domainCheck($totals['net_cents'] === 30000 && $totals['vat_cents'] === 5250 && $totals['total_cents'] === 35250, 'Mixed included-VAT and fractional quantities must calculate exactly.');
    $tiny = Money::calculate(array_fill(0, 200, ['name' => 'Pequeño', 'quantity' => '1', 'price' => '0.01', 'vat_rate' => '21']));
    domainCheck($tiny['total_cents'] === 200 && $tiny['net_cents'] === 165 && $tiny['vat_cents'] === 35, 'Small included-VAT lines must reconcile by rate.');
    domainCheck(array_sum(array_column($tiny['items'], 'vat_cents')) === 35, 'Line tax allocations must equal grouped totals.');
    $exclusive = Money::calculate(array_fill(0, 200, ['name' => 'Pequeño', 'quantity' => '1', 'price' => '0.01', 'vat_rate' => '21']), false);
    domainCheck($exclusive['net_cents'] === 200 && $exclusive['vat_cents'] === 42 && $exclusive['total_cents'] === 242, 'Exclusive VAT must reconcile by rate.');
    $invoiceC = Money::calculate([['name' => 'C', 'price' => '121', 'quantity' => '1', 'vat_rate' => '21']], true, true);
    domainCheck($invoiceC['total_cents'] === 12100 && $invoiceC['net_cents'] === 12100 && $invoiceC['vat_cents'] === 0 && $invoiceC['vat_breakdown'] === [], 'Invoice C must not send discriminated VAT.');
    $catalog = new Catalog($db);
    $product = $catalog->save('products', ['name' => 'Producto', 'code' => 'PROD-1', 'price' => '121,00', 'vat_rate' => '21']);
    domainCheck($product['price_cents'] === 12100, 'Product must persist integer cents.');
    domainReject(fn() => $catalog->save('products', ['name' => 'Duplicado', 'code' => 'prod-1', 'price' => '1', 'vat_rate' => '21']), 'Codes must be unique regardless of case.');
    $catalog->save('products', ['description' => 'Actualizado'], (int)$product['id']);
    domainCheck(count($catalog->list('products', 'PROD-1')) === 1, 'Code lookup should find product.');
    domainCheck(count($catalog->list('products', '%')) === 0, 'Search wildcards should be treated literally.');
    $customer = $catalog->save('customers', ['name' => 'Consumidor final', 'document_type' => 'CF', 'tax_condition' => '5']);
    domainCheck($customer['document_number'] === '0' && $customer['tax_condition'] === 5, 'Consumer final must normalize identification.');
    domainReject(fn() => $catalog->save('customers', ['name' => 'CUIT inválido', 'document_type' => 'CUIT', 'document_number' => '20123456780', 'tax_condition' => '1']), 'CUIT checksum must be validated.');
    $settings = new Settings($db, $storage);
    domainCheck($settings->get()['arca_environment'] === 'homologation', 'Default must be homologation.');
    domainCheck($settings->get()['arca_configured'] === false, 'No credentials means not configured.');
    $settings->save(['company_name' => 'Prueba', 'cuit' => '20123456786', 'issuer_tax_condition' => '1', 'default_voucher_type' => '6', 'prices_include_vat' => '0']);
    domainCheck($settings->get()['prices_include_vat'] === false, 'Boolean settings must normalize form values.');
    domainCheck(!array_key_exists('private_key_path', $settings->get()), 'Public settings must not expose private paths.');
    domainReject(fn() => $settings->save(['point_of_sale' => '0']), 'Point of sale zero must be rejected.');
    domainReject(fn() => $settings->save(['point_of_sale' => '99999']), 'Reserved point of sale 99999 must be rejected.');
    $settings->save(['gross_income_number' => '123', 'activity_start_date' => '2026-10-26']);
    domainCheck($settings->get()['income_tax_number'] === '123' && $settings->get()['activity_start'] === '2026-10-26', 'Legacy fiscal keys must normalize to public configuration names.');
    domainReject(fn() => $settings->save(['activity_start' => '2026-02-30']), 'Invalid calendar date must be rejected.');
    domainReject(fn() => $settings->save(['issuer_tax_condition' => 6, 'default_voucher_type' => 6]), 'Monotributista cannot configure invoice B.');
    $settings->save(['issuer_tax_condition' => 6, 'default_voucher_type' => 11]);
    domainCheck($settings->get()['default_voucher_type'] === 11, 'Monotributista may configure invoice C.');
    $settings->save(['cuit' => '30712345671']);
    domainCheck($settings->get()['cuit'] === '30712345671', 'Issuer CUIT may change before any number is assigned.');
    $settings->save(['cuit' => '20123456786']);
    $issuanceLock = fopen($storage . '/issuance.lock', 'c');
    domainCheck($issuanceLock !== false && flock($issuanceLock, LOCK_EX | LOCK_NB), 'Test must acquire the fiscal lock.');
    try {
        domainReject(fn() => $settings->save(['address' => 'Debe esperar']), 'Configuration must refuse changes while issuance or backup holds its lock.');
        domainCheck($settings->get()['address'] === '', 'A refused save must not change configuration.');
    } finally {
        flock($issuanceLock, LOCK_UN);
        fclose($issuanceLock);
    }
    $settings->save(['address' => 'Permitido']);
    domainCheck($settings->get()['address'] === 'Permitido', 'Saving must work after the fiscal lock is released.');
    $snapshot = json_encode($customer, JSON_THROW_ON_ERROR);
    $insert = $db->prepare('INSERT INTO invoices(customer_id,customer_json,issuer_json,items_json,totals_json,issue_date,voucher_type,point_of_sale,environment) VALUES(?,?,?,?,?,?,?,?,?)');
    $insert->execute([$customer['id'], $snapshot, '{}', '[]', '{}', '2026-10-26', 11, 1, 'homologation']);
    $db->exec("UPDATE invoices SET number=1,status='authorized'");
    domainReject(fn() => $settings->save(['cuit' => '30712345671']), 'Issuer CUIT must remain fixed after an issued number exists.');
    domainCheck($settings->get()['cuit'] === '20123456786', 'Refused issuer change must retain the existing CUIT.');
    $db->exec("UPDATE invoices SET status='rejected'");
    domainReject(fn() => $settings->save(['cuit' => '30712345671']), 'Any previously assigned number must prevent issuer changes even after rejection.');
    $settings->save(['cuit' => '20-12345678-6', 'address' => 'Nueva dirección', 'point_of_sale' => 2]);
    domainCheck($settings->get()['address'] === 'Nueva dirección' && $settings->get()['point_of_sale'] === 2, 'Same-CUIT configuration changes remain possible after an assigned number.');
    $catalog->delete('customers', (int)$customer['id']);
    domainCheck(count($catalog->list('customers')) === 0, 'Archived customer must leave active list.');
    domainCheck($db->query('SELECT customer_json FROM invoices')->fetchColumn() === $snapshot, 'Archiving must preserve invoice customer snapshot.');
    domainCheck((int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 1, 'Customer archive must not physically delete linked records.');
    echo "Domain tests passed.\n";
} finally {
    if (isset($db)) {
        $db = null;
    }
    if (is_dir($storage)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($storage);
    }
}
