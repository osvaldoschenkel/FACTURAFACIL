<?php
declare(strict_types=1);

namespace FacturaFacil;

use PDO;
use PDOException;
use RuntimeException;

final class Catalog
{
    public function __construct(private PDO $db)
    {
    }

    private function kind(string $kind): string
    {
        if (!in_array($kind, ['products', 'customers'], true)) {
            throw new RuntimeException('Catálogo inexistente.');
        }
        return $kind;
    }

    public function list(string $kind, string $search = ''): array
    {
        $kind = $this->kind($kind);
        $column = $kind === 'products' ? 'code' : 'document_number';
        $sql = 'SELECT * FROM ' . $kind . ' WHERE active=1';
        $params = [];
        if ($search !== '') {
            $sql .= " AND (name LIKE ? ESCAPE '\\' OR " . $column . " LIKE ? ESCAPE '\\')";
            $params = array_fill(0, 2, '%' . strtr(trim($search), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%');
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY name COLLATE NOCASE,id');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function save(string $kind, array $data, ?int $id = null): array
    {
        $kind = $this->kind($kind);
        $existing = [];
        if ($id !== null) {
            $stmt = $this->db->prepare('SELECT * FROM ' . $kind . ' WHERE id=? AND active=1');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('No se encontró el registro para modificar.');
            }
        }
        $data = array_replace($existing, $data);
        $values = ['name' => self::text($data, 'name', 200, true)];
        if ($kind === 'products') {
            $values['code'] = self::text($data, 'code', 80, true);
            $values['description'] = self::text($data, 'description', 2000);
            $values['price_cents'] = Money::parse((string)($data['price'] ?? (isset($existing['price_cents']) ? Money::format((int)$existing['price_cents']) : '')));
            $values['vat_rate'] = str_replace(',', '.', (string)($data['vat_rate'] ?? '21'));
            if (!in_array($values['vat_rate'], ['10.5', '21'], true)) {
                throw new RuntimeException('El IVA debe ser 10,5% o 21%.');
            }
        } else {
            $values['document_type'] = strtoupper(trim((string)($data['document_type'] ?? 'CUIT')));
            $values['document_number'] = preg_replace('/[ .-]/', '', trim((string)($data['document_number'] ?? '')));
            if ($values['document_type'] === 'CF') {
                $values['document_number'] = '0';
            }
            self::validateDocument($values['document_type'], $values['document_number']);
            $condition = filter_var($data['tax_condition'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($condition, [1, 4, 5, 6, 7, 8, 9, 10, 13, 15, 16], true)) {
                throw new RuntimeException('Seleccioná una condición de IVA válida para el cliente.');
            }
            if ($values['document_type'] === 'CF' && $condition !== 5) {
                throw new RuntimeException('El cliente sin documento debe tener condición Consumidor final.');
            }
            $values['tax_condition'] = $condition;
            foreach (['address' => 250, 'city' => 100, 'province' => 100, 'postal_code' => 20, 'email' => 254, 'phone' => 50] as $field => $max) {
                $values[$field] = self::text($data, $field, $max);
            }
            if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo del cliente no es válido.');
            }
        }
        try {
            if ($id === null) {
                $fields = array_keys($values);
                $stmt = $this->db->prepare('INSERT INTO ' . $kind . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
                $stmt->execute(array_values($values));
                $id = (int)$this->db->lastInsertId();
            } else {
                $fields = array_map(static fn(string $field): string => $field . '=?', array_keys($values));
                $stmt = $this->db->prepare('UPDATE ' . $kind . ' SET ' . implode(',', $fields) . ',updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->execute([...array_values($values), $id]);
            }
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException($kind === 'products' ? 'Ya existe un producto con ese código, incluso entre los archivados.' : 'Ya existe un cliente con ese tipo y número de documento, incluso entre los archivados.');
            }
            throw $e;
        }
        $stmt = $this->db->prepare('SELECT * FROM ' . $kind . ' WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function delete(string $kind, int $id): void
    {
        $kind = $this->kind($kind);
        $stmt = $this->db->prepare('UPDATE ' . $kind . ' SET active=0,updated_at=CURRENT_TIMESTAMP WHERE id=? AND active=1');
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('No se encontró el registro para archivar.');
        }
    }

    private static function text(array $data, string $field, int $max, bool $required = false): string
    {
        $value = trim((string)($data[$field] ?? ''));
        if (($required && $value === '') || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('El campo ' . $field . ' está vacío o supera el máximo de ' . $max . ' caracteres.');
        }
        return $value;
    }

    public static function validateDocument(string $type, string $document): void
    {
        if ($type === 'CF' && $document === '0') {
            return;
        }
        if ($type === 'DNI' && preg_match('/^\d{7,8}$/D', $document) && (int)$document > 0) {
            return;
        }
        if (in_array($type, ['CUIT', 'CUIL'], true) && preg_match('/^\d{11}$/D', $document)) {
            $sum = 0;
            foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $weight) {
                $sum += (int)$document[$i] * $weight;
            }
            $digit = 11 - $sum % 11;
            $digit = $digit === 11 ? 0 : ($digit === 10 ? 9 : $digit);
            if ((int)$document[10] === $digit && (int)$document > 0) {
                return;
            }
        }
        throw new RuntimeException('El tipo o número de documento no es válido. Revisá también el dígito verificador del CUIT/CUIL.');
    }
}
