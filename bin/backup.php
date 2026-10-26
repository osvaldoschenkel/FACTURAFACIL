<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use FacturaFacil\Database;

try {
    $destination = $argv[1] ?? '';
    if (!$destination || !str_starts_with($destination,DIRECTORY_SEPARATOR)) { throw new RuntimeException('Uso: php bin/backup.php /ruta/privada/de/respaldos'); }
    if (!is_dir($destination) && !mkdir($destination,0700,true)) { throw new RuntimeException('No se pudo crear la carpeta de respaldo.'); }
    $destination = realpath($destination);
    $public = realpath(dirname(__DIR__) . '/public');
    if ($destination === $public || str_starts_with($destination,$public . DIRECTORY_SEPARATOR)) { throw new RuntimeException('Guardá los respaldos fuera del directorio público.'); }
    $storage = ff_storage();
    if ($destination === $storage || str_starts_with($destination,$storage . DIRECTORY_SEPARATOR)) { throw new RuntimeException('Guardá los respaldos fuera del directorio de datos de la aplicación.'); }
    $db = Database::connect($storage);
    $lock = fopen($storage . '/issuance.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { throw new RuntimeException('Esperá a que finalice la emisión en curso para respaldar.'); }
    try {
        $folder = $destination . '/facturafacil-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!mkdir($folder,0700)) { throw new RuntimeException('No se pudo crear el respaldo.'); }
        // VACUUM INTO creates a consistent SQLite copy, including committed WAL data.
        $db->exec('VACUUM INTO ' . $db->quote($folder . '/facturafacil.sqlite'));
        chmod($folder . '/facturafacil.sqlite',0600);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage,FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(),strlen($storage)+1);
            // Include certificates and the encryption key; exclude locks, TA cache and SQLite sidecars.
            if (!$file->isFile() || preg_match('~(?:\.sqlite(?:-.*)?$|\.lock$|(?:^|/)(?:sessions|tickets|arca-cache|wsaa)(?:/|$))~',$relative)) { continue; }
            $target = $folder . '/' . $relative;
            if (!is_dir(dirname($target))) { mkdir(dirname($target),0700,true); }
            if (!copy($file->getPathname(),$target)) { throw new RuntimeException('No se pudo copiar un archivo privado.'); }
            chmod($target,0600);
        }
        fwrite(STDOUT,"Respaldo creado: " . $folder . "\n");
    } finally { flock($lock,LOCK_UN); fclose($lock); }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
