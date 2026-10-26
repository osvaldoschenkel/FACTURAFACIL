<?php
declare(strict_types=1);

namespace FacturaFacil;

use PDO;
use RuntimeException;

final class Settings
{
    private const DEFAULTS = [
        'company_name' => '',
        'cuit' => '',
        'address' => '',
        'city' => '',
        'province' => '',
        'postal_code' => '',
        'email' => '',
        'phone' => '',
        'income_tax_number' => '',
        'activity_start' => '',
        'issuer_tax_condition' => 1,
        'point_of_sale' => 1,
        'arca_environment' => 'homologation',
        'default_voucher_type' => 6,
        'default_vat_rate' => '21',
        'prices_include_vat' => true,
        'print_format' => 'a4',
    ];

    public function __construct(private PDO $db, private string $storagePath)
    {
    }

    public function get(bool $includeSecrets = false): array
    {
        $values = self::DEFAULTS;
        $stored = $this->db->query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (array_keys(self::DEFAULTS) as $field) {
            if (array_key_exists($field, $stored)) {
                $values[$field] = json_decode($stored[$field], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $prefix = $values['arca_environment'] . '_';
        $certificate = isset($stored[$prefix . 'certificate_path']) ? (string)json_decode($stored[$prefix . 'certificate_path'], true) : '';
        $key = isset($stored[$prefix . 'private_key_path']) ? (string)json_decode($stored[$prefix . 'private_key_path'], true) : '';
        $passphrase = isset($stored[$prefix . 'private_key_passphrase']) ? (string)json_decode($stored[$prefix . 'private_key_passphrase'], true) : '';
        $values['certificate_configured'] = $certificate !== '' && is_file($certificate);
        $values['private_key_configured'] = $key !== '' && is_file($key);
        $values['private_key_passphrase_configured'] = $passphrase !== '';
        $values['arca_configured'] = $values['cuit'] !== '' && $values['certificate_configured'] && $values['private_key_configured'];
        if ($includeSecrets) {
            $values['certificate_path'] = $certificate;
            $values['private_key_path'] = $key;
            $values['private_key_passphrase'] = $passphrase !== '' ? $this->decrypt($passphrase) : '';
        }
        return $values;
    }

    public function save(array $data, array $files = []): array
    {
        $lock = @fopen(rtrim($this->storagePath, '/') . '/issuance.lock', 'c');
        if (!$lock) {
            throw new RuntimeException('No se pudo bloquear la configuración fiscal.');
        }
        @chmod(rtrim($this->storagePath, '/') . '/issuance.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Hay una emisión o un respaldo en curso. Esperá a que termine para guardar la configuración.');
        }
        try {
            return $this->saveLocked($data, $files);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function saveLocked(array $data, array $files): array
    {
        if (isset($data['gross_income_number']) && !isset($data['income_tax_number'])) {
            $data['income_tax_number'] = $data['gross_income_number'];
        }
        if (isset($data['activity_start_date']) && !isset($data['activity_start'])) {
            $data['activity_start'] = $data['activity_start_date'];
        }
        $current = $this->get(true);
        $values = array_intersect_key(array_replace($current, $data), self::DEFAULTS);
        foreach (['company_name' => 200, 'address' => 250, 'city' => 100, 'province' => 100, 'postal_code' => 20, 'email' => 254, 'phone' => 50, 'income_tax_number' => 50] as $field => $max) {
            $values[$field] = trim((string)$values[$field]);
            if (strlen($values[$field]) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $values[$field])) {
                throw new RuntimeException('El campo ' . $field . ' supera el máximo permitido.');
            }
        }
        if ($values['company_name'] === '') {
            throw new RuntimeException('Ingresá la razón social del emisor.');
        }
        $values['cuit'] = preg_replace('/[ .-]/', '', (string)$values['cuit']);
        Catalog::validateDocument('CUIT', $values['cuit']);
        if ($current['cuit'] !== '' && $values['cuit'] !== $current['cuit'] && $this->db->query('SELECT 1 FROM invoices WHERE number IS NOT NULL LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('No se puede cambiar el CUIT después de asignar un número de comprobante. Para otro emisor, usá una instalación separada.');
        }
        $values['issuer_tax_condition'] = filter_var($values['issuer_tax_condition'], FILTER_VALIDATE_INT);
        if (!in_array($values['issuer_tax_condition'], [1, 4, 6], true)) {
            throw new RuntimeException('La condición del emisor debe ser Responsable inscripto, Monotributista o Exento.');
        }
        $values['point_of_sale'] = filter_var($values['point_of_sale'], FILTER_VALIDATE_INT);
        if ($values['point_of_sale'] === false || $values['point_of_sale'] < 1 || $values['point_of_sale'] > 99998) {
            throw new RuntimeException('El punto de venta debe estar entre 1 y 99998.');
        }
        if (!in_array($values['arca_environment'], ['homologation', 'production'], true)) {
            throw new RuntimeException('El ambiente fiscal debe ser homologación o producción.');
        }
        $values['default_voucher_type'] = filter_var($values['default_voucher_type'], FILTER_VALIDATE_INT);
        $types = $values['issuer_tax_condition'] === 1 ? [1, 6] : [11];
        if (!in_array($values['default_voucher_type'], $types, true)) {
            throw new RuntimeException($types === [11] ? 'Monotributistas y exentos deben configurar factura C.' : 'Un responsable inscripto debe configurar factura A o B.');
        }
        $values['default_vat_rate'] = str_replace(',', '.', (string)$values['default_vat_rate']);
        if (!in_array($values['default_vat_rate'], ['10.5', '21'], true)) {
            throw new RuntimeException('El IVA predeterminado debe ser 10,5% o 21%.');
        }
        $boolean = filter_var($values['prices_include_vat'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($boolean === null) {
            throw new RuntimeException('La opción de precios con IVA debe ser verdadera o falsa.');
        }
        $values['prices_include_vat'] = $boolean;
        if (!in_array($values['print_format'], ['a4', '80mm', '50mm'], true)) {
            throw new RuntimeException('El formato de impresión debe ser A4, ticket de 80 mm o ticket de 50 mm.');
        }
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo del emisor no es válido.');
        }
        $values['activity_start'] = trim((string)$values['activity_start']);
        if ($values['activity_start'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['activity_start']);
            if (!$date || $date->format('Y-m-d') !== $values['activity_start']) {
                throw new RuntimeException('La fecha de inicio de actividades no es válida.');
            }
        }

        // Credentials belong to one environment. Switching environments never borrows another pair.
        $environment = $values['arca_environment'];
        $stored = $this->db->query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $prefix = $environment . '_';
        $certificatePath = isset($stored[$prefix . 'certificate_path']) ? (string)json_decode($stored[$prefix . 'certificate_path'], true) : '';
        $keyPath = isset($stored[$prefix . 'private_key_path']) ? (string)json_decode($stored[$prefix . 'private_key_path'], true) : '';
        $oldPassphrase = isset($stored[$prefix . 'private_key_passphrase']) ? (string)json_decode($stored[$prefix . 'private_key_passphrase'], true) : '';
        $passphrase = $oldPassphrase !== '' ? $this->decrypt($oldPassphrase) : '';
        if (isset($data['private_key_passphrase']) && (string)$data['private_key_passphrase'] !== '') {
            $passphrase = (string)$data['private_key_passphrase'];
        }
        if (strlen($passphrase) > 4096) {
            throw new RuntimeException('La contraseña de la clave privada es demasiado larga.');
        }
        $certificateUpload = $this->upload($files['certificate'] ?? $files['certificate_file'] ?? null);
        $keyUpload = $this->upload($files['private_key'] ?? $files['private_key_file'] ?? null);
        $credentialChanged = $certificateUpload !== null || $keyUpload !== null || (isset($data['private_key_passphrase']) && (string)$data['private_key_passphrase'] !== '');
        if ($credentialChanged) {
            $certificate = $certificateUpload ?? ($certificatePath !== '' && is_file($certificatePath) ? file_get_contents($certificatePath) : '');
            $key = $keyUpload ?? ($keyPath !== '' && is_file($keyPath) ? file_get_contents($keyPath) : '');
            $x509 = @openssl_x509_read($certificate);
            $private = @openssl_pkey_get_private($key, $passphrase);
            if (!$x509 || !$private || !openssl_x509_check_private_key($x509, $private)) {
                throw new RuntimeException('El certificado y la clave privada no coinciden, o la contraseña no es válida. Cargá ambos archivos PEM/CRT/KEY.');
            }
            $parsed = openssl_x509_parse($x509);
            if (!$parsed || (int)($parsed['validTo_time_t'] ?? 0) <= time() || (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time()) {
                throw new RuntimeException('El certificado está vencido o todavía no es válido.');
            }
            $privateInfo = openssl_pkey_get_details($private);
            if (!$privateInfo || $privateInfo['bits'] < 2048) {
                throw new RuntimeException('La clave privada debe tener al menos 2048 bits.');
            }
            $certificatePath = $this->writePrivate($certificate, '.crt');
            try {
                $keyPath = $this->writePrivate($key, '.key');
            } catch (\Throwable $e) {
                @unlink($certificatePath);
                throw $e;
            }
            $values[$prefix . 'certificate_path'] = $certificatePath;
            $values[$prefix . 'private_key_path'] = $keyPath;
            $values[$prefix . 'private_key_passphrase'] = $passphrase !== '' ? $this->encrypt($passphrase) : '';
        }
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
            foreach ($values as $field => $value) {
                $statement->execute([$field, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($credentialChanged) {
                @unlink($certificatePath);
                @unlink($keyPath);
            }
            throw $e;
        }
        return $this->get();
    }

    private function upload(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 65536 || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('No se pudo recibir el archivo fiscal. El máximo es 64 KB.');
        }
        $content = file_get_contents($file['tmp_name']);
        if ($content === false || strlen($content) > 65536) {
            throw new RuntimeException('No se pudo leer el archivo fiscal.');
        }
        return $content;
    }

    private function privateDirectory(): string
    {
        $path = rtrim($this->storagePath, '/') . '/credentials';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('No se pudo crear el directorio privado fiscal.');
        }
        @chmod($path, 0700);
        return $path;
    }

    private function writePrivate(string $contents, string $extension): string
    {
        $path = $this->privateDirectory() . '/' . bin2hex(random_bytes(16)) . $extension;
        $handle = @fopen($path, 'x');
        if (!$handle) {
            throw new RuntimeException('No se pudo guardar el archivo fiscal privado.');
        }
        @chmod($path, 0600);
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('No se pudo guardar el archivo fiscal completo.');
            }
        } finally {
            fclose($handle);
        }
        return $path;
    }

    private function masterKey(): string
    {
        $path = $this->privateDirectory() . '/encryption.key';
        if (!is_file($path)) {
            $handle = @fopen($path, 'x');
            if ($handle) {
                @chmod($path, 0600);
                fwrite($handle, random_bytes(32));
                fclose($handle);
            }
        }
        $key = @file_get_contents($path);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('No se pudo leer la clave de cifrado local.');
        }
        return $key;
    }

    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->masterKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo proteger la contraseña de la clave fiscal.');
        }
        return base64_encode($nonce . $tag . $ciphertext);
    }

    private function decrypt(string $ciphertext): string
    {
        $bytes = base64_decode($ciphertext, true);
        if ($bytes === false || strlen($bytes) < 29) {
            throw new RuntimeException('La contraseña fiscal guardada está dañada.');
        }
        $plaintext = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->masterKey(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('No se pudo descifrar la contraseña fiscal. Restaurá también la clave local del respaldo.');
        }
        return $plaintext;
    }
}
