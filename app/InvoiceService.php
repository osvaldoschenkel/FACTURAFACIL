<?php
declare(strict_types=1);

namespace FacturaFacil;

use PDO;
use RuntimeException;
use Throwable;

final class InvoiceService
{
    public function __construct(private PDO $db, private Settings $settings, private string $storagePath, private $gatewayFactory = null) {}

    private function gateway(array $settings): object
    {
        return $this->gatewayFactory ? ($this->gatewayFactory)($settings) : new ArcaGateway($settings, $this->storagePath);
    }

    public function preview(array $data): array
    {
        $settings = $this->settings->get();
        $q = $this->db->prepare('SELECT * FROM customers WHERE id = ? AND active = 1');
        $q->execute([(int)($data['customer_id'] ?? 0)]);
        $customer = $q->fetch();
        if (!$customer) { throw new RuntimeException('Seleccioná un cliente activo.'); }
        $date = (string)($data['issue_date'] ?? date('Y-m-d'));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new RuntimeException('La fecha no es válida.'); }
        $issuerCondition = (int)($settings['issuer_tax_condition'] ?? 1);
        $automaticType = in_array($issuerCondition, [6, 4], true) ? 11 : (in_array((int)$customer['tax_condition'], [1, 6, 13, 16], true) ? 1 : 6);
        $type = (int)($data['voucher_type'] ?? $automaticType);
        if ($type !== $automaticType) {
            throw new RuntimeException('El tipo de factura no corresponde a la condición de IVA del emisor y del cliente. Corresponde factura ' . ([1=>'A',6=>'B',11=>'C'][$automaticType]) . '.');
        }
        if ($type === 1 && $customer['document_type'] !== 'CUIT') { throw new RuntimeException('Para emitir factura A, guardá el CUIT del cliente en su ficha.'); }
        $input = $data['items'] ?? [];
        if (!is_array($input) || count($input) < 1 || count($input) > 200) { throw new RuntimeException('Agregá entre 1 y 200 productos.'); }
        $items = [];
        $productQuery = $this->db->prepare('SELECT * FROM products WHERE id = ? AND active = 1');
        foreach ($input as $row) {
            if (!is_array($row)) { throw new RuntimeException('Detalle de producto inválido.'); }
            $productQuery->execute([(int)($row['product_id'] ?? 0)]);
            $product = $productQuery->fetch();
            if (!$product) { throw new RuntimeException('Uno de los productos ya no está activo.'); }
            $items[] = [
                'product_id' => (int)$product['id'], 'code' => $product['code'], 'name' => $product['name'],
                'quantity' => (string)($row['quantity'] ?? '1'),
                'price' => isset($row['price']) && $row['price'] !== '' ? (string)$row['price'] : number_format((int)$product['price_cents'] / 100, 2, '.', ''),
                'vat_rate' => (string)($row['vat_rate'] ?? $product['vat_rate']),
            ];
        }
        $totals = Money::calculate($items, (bool)($settings['prices_include_vat'] ?? true), $type === 11);
        if ($totals['total_cents'] <= 0) { throw new RuntimeException('El total debe ser mayor que cero.'); }
        $issuer = array_intersect_key($settings, array_flip(['company_name','cuit','issuer_tax_condition','point_of_sale','arca_environment','address','city','province','postal_code','income_tax_number','activity_start','prices_include_vat']));
        return [
            'customer_id' => (int)$customer['id'], 'customer' => $customer, 'customer_name' => $customer['name'],
            'issuer' => $issuer, 'items' => $totals['items'] ?? $items, 'totals' => $totals,
            'issue_date' => $date, 'voucher_type' => $type,
            'point_of_sale' => (int)($settings['point_of_sale'] ?? 1),
            'environment' => $settings['arca_environment'] ?? 'homologation',
            'status' => 'draft', 'net_cents' => $totals['net_cents'], 'vat_cents' => $totals['vat_cents'], 'total_cents' => $totals['total_cents'],
        ];
    }

    public function create(array $data): array
    {
        return $this->insert($this->preview($data));
    }

    private function insert(array $invoice): array
    {
        $q = $this->db->prepare('INSERT INTO invoices (customer_id, customer_json, issuer_json, items_json, totals_json, issue_date, voucher_type, point_of_sale, environment, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $now = date('c');
        $q->execute([$invoice['customer_id'], self::json($invoice['customer']), self::json($invoice['issuer']), self::json($invoice['items']), self::json($invoice['totals']), $invoice['issue_date'], $invoice['voucher_type'], $invoice['point_of_sale'], $invoice['environment'], 'draft', $now, $now]);
        $id = (int)$this->db->lastInsertId();
        $this->event($id, 'created', 'Borrador creado.');
        return $this->find($id);
    }

    public function bulkCreate(array $data): array
    {
        $ids = $this->ids($data['customer_ids'] ?? [], 100);
        // Validate every draft before writing; rollback the entire batch on failure.
        $previews = [];
        foreach ($ids as $id) {
            $input = $data;
            $input['customer_id'] = $id;
            // Each recipient can require a different voucher class.
            if (empty($input['voucher_type']) || $input['voucher_type'] === 'auto') { unset($input['voucher_type']); }
            $previews[] = $this->preview($input);
        }
        $this->db->beginTransaction();
        try {
            $result = array_map(fn(array $preview) => $this->insert($preview), $previews);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function list(string $search = '', string $status = ''): array
    {
        $where = [];
        $params = [];
        if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
        if ($search !== '') { $where[] = '(customer_json LIKE ? OR CAST(id AS TEXT) = ? OR CAST(number AS TEXT) = ?)'; $params = array_merge($params, ['%' . $search . '%', $search, $search]); }
        $q = $this->db->prepare('SELECT * FROM invoices' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 1000');
        $q->execute($params);
        return array_map([$this, 'hydrate'], $q->fetchAll());
    }

    public function find(int $id): array
    {
        $q = $this->db->prepare('SELECT * FROM invoices WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) { throw new RuntimeException('No se encontró la factura.'); }
        return $this->hydrate($row);
    }

    public function delete(int $id): void
    {
        $this->db->beginTransaction();
        try {
            $invoice = $this->find($id);
            if (!in_array($invoice['status'], ['draft','rejected'], true) || !empty($invoice['number'])) { throw new RuntimeException('Solo se pueden eliminar borradores sin numeración fiscal.'); }
            $this->db->prepare('DELETE FROM invoice_events WHERE invoice_id = ?')->execute([$id]);
            $q = $this->db->prepare("DELETE FROM invoices WHERE id = ? AND status IN ('draft','rejected') AND number IS NULL");
            $q->execute([$id]);
            if (!$q->rowCount()) { throw new RuntimeException('La factura cambió mientras se procesaba.'); }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function issueBatch(array $data): array
    {
        if (($data['confirmed'] ?? false) !== true) { throw new RuntimeException('Confirmá los comprobantes antes de emitirlos.'); }
        $ids = $this->ids($data['ids'] ?? [], 25);
        $settings = $this->settings->get(true);
        if (($data['environment'] ?? '') !== $settings['arca_environment']) { throw new RuntimeException('El ambiente cambió. Revisá nuevamente la selección.'); }
        if ($settings['arca_environment'] === 'production' && ($data['production_confirmation'] ?? '') !== 'EMITIR') { throw new RuntimeException('Escribí EMITIR para confirmar la emisión en producción.'); }
        $this->validateConfiguration($settings);
        $lock = $this->acquireLock();
        try {
            $invoices = [];
            foreach ($ids as $id) {
                $invoice = $this->find($id);
                // Authorized IDs make a repeated browser request idempotent.
                if (!in_array($invoice['status'], ['draft','rejected','authorized'], true)) { throw new RuntimeException('Hay comprobantes pendientes de conciliación. Revisalos antes de continuar.'); }
                if ($invoice['status'] !== 'authorized') { $this->validateSnapshot($invoice, $settings, empty($invoice['number'])); }
                $invoices[] = $invoice;
            }
            $gateway = $this->gateway($settings);
            $results = [];
            foreach ($invoices as $invoice) {
                $id = (int)$invoice['id'];
                if ($invoice['status'] === 'authorized') { $results[] = $this->result($invoice); continue; }
                try {
                    $gateway = $this->gateway(array_replace($settings, ['issuer_tax_condition' => $invoice['issuer']['issuer_tax_condition']]));
                    $blocked = $this->db->prepare("SELECT id FROM invoices WHERE environment = ? AND point_of_sale = ? AND voucher_type = ? AND (status IN ('processing','uncertain') OR (status = 'rejected' AND number IS NOT NULL)) AND id <> ? LIMIT 1");
                    $blocked->execute([$invoice['environment'], $invoice['point_of_sale'], $invoice['voucher_type'], $id]);
                    if ($blocked->fetchColumn()) { throw new RuntimeException('Hay una factura de este punto de venta pendiente de conciliación con ARCA.'); }
                    $number = $gateway->lastAuthorized((int)$invoice['point_of_sale'], (int)$invoice['voucher_type']) + 1;
                    if (!empty($invoice['number']) && (int)$invoice['number'] !== $number) { throw new RuntimeException('La numeración cambió. Conciliá este comprobante antes de reintentarlo.'); }
                    // Persist the exact request BEFORE network I/O. A killed PHP process is recoverable.
                    $q = $this->db->prepare("UPDATE invoices SET status = 'processing', number = ?, error = NULL, updated_at = ? WHERE id = ? AND status IN ('draft','rejected')");
                    $q->execute([$number, date('c'), $id]);
                    if ($q->rowCount() !== 1) { throw new RuntimeException('La factura cambió mientras se procesaba.'); }
                    $invoice['number'] = $number;
                    $invoice['status'] = 'processing';
                    $this->event($id, 'submitted', 'Solicitud ARCA ' . $number);
                    $authorization = $gateway->issue($invoice);
                    $this->authorize($id, $authorization);
                } catch (Throwable $e) {
                    $current = $this->find($id);
                    $status = $current['status'];
                    if ($status === 'processing') { $status = $e instanceof ArcaRejectedException ? 'rejected' : 'uncertain'; }
                    if ($e instanceof ArcaRejectedException && $current['status'] === 'processing') {
                        // A definitive rejection did not consume a fiscal number. The attempted
                        // number remains in the event log; future drafts can use the series.
                        $this->db->prepare('UPDATE invoices SET number = NULL WHERE id = ?')->execute([$id]);
                    }
                    $q = $this->db->prepare('UPDATE invoices SET status = ?, error = ?, updated_at = ? WHERE id = ?');
                    $q->execute([$status, mb_substr($e->getMessage(), 0, 1500), date('c'), $id]);
                    $this->event($id, $status, $e->getMessage());
                }
                $results[] = $this->result($this->find($id));
            }
            return ['results' => $results, 'summary' => array_count_values(array_column($results, 'status'))];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function reconcile(int $id): array
    {
        $settings = $this->settings->get(true);
        $this->validateConfiguration($settings);
        $lock = $this->acquireLock();
        try {
            $invoice = $this->find($id);
            if ($invoice['status'] === 'authorized') { return $invoice; }
            if (!in_array($invoice['status'], ['uncertain','processing','rejected'], true) || empty($invoice['number'])) { throw new RuntimeException('Este comprobante no necesita conciliación.'); }
            $this->validateSnapshot($invoice, $settings, false);
            $gateway = $this->gateway(array_replace($settings, ['issuer_tax_condition' => $invoice['issuer']['issuer_tax_condition']]));
            $found = $gateway->consult($invoice);
            if ($found) {
                $this->authorize($id, $found);
            } else {
                $last = $gateway->lastAuthorized((int)$invoice['point_of_sale'], (int)$invoice['voucher_type']);
                if ($last >= (int)$invoice['number']) { throw new RuntimeException('La numeración fue usada por otro comprobante. No se reemitirá automáticamente.'); }
                // Preserve its reserved number. Retrying uses the same immutable data and number.
                $q = $this->db->prepare("UPDATE invoices SET status = 'rejected', error = ?, updated_at = ? WHERE id = ?");
                $q->execute(['ARCA no registra este comprobante. Se puede reintentar con los mismos datos y número.', date('c'), $id]);
                $this->event($id, 'reconciled_not_found', 'ARCA no registra autorización.');
            }
            return $this->find($id);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function validateConfiguration(array $settings): void
    {
        foreach (['company_name','cuit','point_of_sale','certificate_path','private_key_path'] as $field) {
            if (empty($settings[$field])) { throw new RuntimeException('Completá la configuración fiscal y cargá el certificado y la clave privada antes de emitir.'); }
        }
    }

    private function validateSnapshot(array $invoice, array $settings, bool $checkEmissionDate = true): void
    {
        $fields = $checkEmissionDate
            ? ['cuit','issuer_tax_condition','point_of_sale','arca_environment','company_name','address','city','province','income_tax_number','activity_start']
            : ['cuit','arca_environment'];
        foreach ($fields as $field) {
            if ((string)($invoice['issuer'][$field] ?? '') !== (string)($settings[$field] ?? '')) {
                throw new RuntimeException('La configuración del emisor cambió desde que se creó el borrador. Creá uno nuevo con la configuración actual.');
            }
        }
        $age = (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($invoice['issue_date']));
        if ($checkEmissionDate && $age->days > 5) { throw new RuntimeException('Para emitir productos, usá una fecha dentro de los cinco días de hoy.'); }
    }

    private function acquireLock()
    {
        $lock = fopen($this->storagePath . '/issuance.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) { fclose($lock); } throw new RuntimeException('Ya hay una emisión en curso. Esperá a que termine.'); }
        return $lock;
    }

    private function authorize(int $id, array $authorization): void
    {
        if (!preg_match('/^\d{14}$/', (string)($authorization['cae'] ?? ''))) { throw new RuntimeException('ARCA no devolvió un CAE válido. Conciliá el comprobante.'); }
        $q = $this->db->prepare("UPDATE invoices SET status = 'authorized', cae = ?, cae_due_date = ?, observations_json = ?, error = NULL, updated_at = ? WHERE id = ?");
        $q->execute([$authorization['cae'], $authorization['cae_due_date'], self::json($authorization['observations'] ?? []), date('c'), $id]);
        $this->event($id, 'authorized', 'CAE ' . $authorization['cae']);
    }

    private function ids(mixed $input, int $limit): array
    {
        if (!is_array($input) || !$input || count($input) > $limit) { throw new RuntimeException('Seleccioná entre 1 y ' . $limit . ' registros.'); }
        foreach ($input as $value) { if (!ctype_digit((string)$value) || (int)$value < 1) { throw new RuntimeException('Identificador inválido.'); } }
        return array_values(array_unique(array_map('intval', $input)));
    }

    private function hydrate(array $row): array
    {
        foreach (['customer','issuer','items','totals','observations'] as $field) { $row[$field] = json_decode($row[$field . '_json'] ?? '[]', true, 512, JSON_THROW_ON_ERROR); unset($row[$field . '_json']); }
        $row['id'] = (int)$row['id'];
        $row['customer_name'] = $row['customer']['name'] ?? '';
        foreach (['net_cents','vat_cents','total_cents'] as $field) { $row[$field] = (int)($row['totals'][$field] ?? 0); }
        return $row;
    }

    private function result(array $invoice): array { return array_intersect_key($invoice, array_flip(['id','status','number','cae','error'])); }
    private function event(int $id, string $event, string $detail): void
    {
        $q = $this->db->prepare('INSERT INTO invoice_events (invoice_id,event,detail,created_at) VALUES (?,?,?,?)');
        $q->execute([$id, $event, mb_substr($detail, 0, 1500), date('c')]);
    }
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
}
