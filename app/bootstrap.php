<?php
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');
foreach (['Database','Money','Catalog','Settings','ArcaGateway','InvoiceService','Auth'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}

function ff_storage(): string
{
    $path = getenv('FACTURAFACIL_STORAGE') ?: dirname(__DIR__) . '/private/storage';
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) { throw new RuntimeException('FACTURAFACIL_STORAGE debe ser una ruta absoluta fuera de public.'); }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new RuntimeException('No se pudo crear el directorio privado.'); }
    $real = realpath($path);
    $public = realpath(dirname(__DIR__) . '/public');
    if ($real === false || ($public !== false && ($real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR)))) { throw new RuntimeException('El directorio de datos debe quedar fuera de public.'); }
    chmod($real, 0700);
    return $real;
}

function ff_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
