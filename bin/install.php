<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use FacturaFacil\{Auth,Database};

try {
    foreach (['pdo_sqlite','soap','openssl','simplexml','mbstring','ctype'] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException('Falta la extensión PHP ' . $extension); } }
    $db = Database::connect(ff_storage());
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { throw new RuntimeException('La instalación ya tiene un usuario. No se sobrescribió ningún dato.'); }
    $email = $argv[1] ?? '';
    if (!$email) { fwrite(STDOUT, 'Correo del administrador: '); $email = trim((string)fgets(STDIN)); }
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) { throw new RuntimeException('El correo no es válido.'); }
    function readPassword(string $prompt): string {
        fwrite(STDOUT, $prompt);
        $hidden = function_exists('stream_isatty') && stream_isatty(STDIN) && PHP_OS_FAMILY !== 'Windows';
        if ($hidden) { system('stty -echo'); }
        try { return rtrim((string)fgets(STDIN), "\r\n"); }
        finally { if ($hidden) { system('stty echo'); } fwrite(STDOUT, "\n"); }
    }
    $password = readPassword('Contraseña (12–72 caracteres): ');
    Auth::validatePassword($password);
    if (!hash_equals($password, readPassword('Repetí la contraseña: '))) { throw new RuntimeException('Las contraseñas no coinciden.'); }
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) { throw new RuntimeException('La instalación ya fue completada por otro proceso.'); }
        $db->prepare('INSERT INTO users(email,password_hash) VALUES (?,?)')->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    fwrite(STDOUT,"Instalación completada. Ingresá y completá Configuración antes de emitir.\n");
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
