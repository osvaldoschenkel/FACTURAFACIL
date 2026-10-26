<?php
declare(strict_types=1);
// Router for php -S, never serve arbitrary files outside the assets directory.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$asset = realpath(__DIR__ . rawurldecode($path));
if (str_starts_with($path, '/assets/') && $asset && str_starts_with($asset, __DIR__ . '/assets/') && is_file($asset) && in_array(pathinfo($asset, PATHINFO_EXTENSION), ['css','js','svg','png','ico'], true)) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
