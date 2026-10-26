<?php
declare(strict_types=1);

use FacturaFacil\{Auth,ArcaGateway,Catalog,Database,InvoiceService,Settings};

require dirname(__DIR__) . '/app/bootstrap.php';
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
$storagePath = ff_storage();
$sessionPath = $storagePath . '/sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath,0700,true) && !is_dir($sessionPath)) { http_response_code(500); exit('No se pudo preparar el acceso privado.'); }
ini_set('session.save_path',$sessionPath);
session_name('FACTURAFACILSESSID');
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>$basePath . '/']);
if (!session_start()) { http_response_code(500); exit('No se pudo iniciar la sesión.'); }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
if ($secure) { header('Strict-Transport-Security: max-age=31536000'); }

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($basePath && str_starts_with($path, $basePath . '/')) { $path = substr($path, strlen($basePath)); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$api = str_starts_with($path, '/api/');

function ff_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::connect($storagePath);
    $auth = new Auth($db);
    if (!$auth->installed()) {
        if ($api) { ff_response(['error'=>'Instalá la aplicación con php bin/install.php desde el servidor.'], 503); }
        http_response_code(503);
        $installation = true;
        require dirname(__DIR__) . '/app/views/login.php';
        exit;
    }
    if (!in_array($method, ['GET','HEAD','OPTIONS'], true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals($csrf, $token)) { if ($api) { ff_response(['error'=>'La sesión cambió. Recargá la página.'], 419); } throw new RuntimeException('La sesión cambió. Recargá la página.'); }
    }
    if ($path === '/login' && $method === 'POST') {
        if ($auth->login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? 'unknown')) { header('Location: ' . $basePath . '/'); exit; }
        $loginError = 'El correo o la contraseña no son correctos.';
        http_response_code(401);
    }
    if ($path === '/logout' && $method === 'POST') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . $basePath . '/');
        exit;
    }
    $user = $auth->user();
    if (!$user) {
        if ($api) { ff_response(['error'=>'La sesión venció. Volvé a ingresar.'], 401); }
        require dirname(__DIR__) . '/app/views/login.php';
        exit;
    }
    $settings = new Settings($db, $storagePath);
    $catalog = new Catalog($db);
    $invoices = new InvoiceService($db, $settings, $storagePath);
    if (preg_match('~^/invoices/(\d+)/print$~', $path, $match) && $method === 'GET') {
        $invoice = $invoices->find((int)$match[1]);
        if ($invoice['status'] !== 'authorized') { throw new RuntimeException('Solo se pueden imprimir comprobantes autorizados.'); }
        $printFormat = $_GET['format'] ?? ($settings->get()['print_format'] ?? 'a4');
        if (!is_string($printFormat) || !in_array($printFormat, ['a4','80mm','50mm'], true)) { throw new RuntimeException('Elegí un formato válido: A4, ticket de 80 mm o ticket de 50 mm.'); }
        require dirname(__DIR__) . '/app/views/print.php';
        exit;
    }
    if (!$api) {
        if ($path !== '/' && $path !== '/index.php') { http_response_code(404); echo 'Página no encontrada.'; exit; }
        require dirname(__DIR__) . '/app/views/shell.php';
        exit;
    }
    $data = [];
    if (!in_array($method, ['GET','HEAD'], true)) {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) { ff_response(['error'=>'La solicitud supera 1 MB.'], 413); }
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $data = json_decode(file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new RuntimeException('La solicitud no es válida.'); }
        } else { $data = $_POST; }
    }
    if ($path === '/api/dashboard' && $method === 'GET') {
        $counts = [];
        foreach (['products','customers'] as $kind) { $counts[$kind] = (int)$db->query('SELECT COUNT(*) FROM ' . $kind . ' WHERE active = 1')->fetchColumn(); }
        foreach (['drafts'=>'draft','authorized'=>'authorized'] as $key=>$status) { $q=$db->prepare('SELECT COUNT(*) FROM invoices WHERE status=?'); $q->execute([$status]); $counts[$key]=(int)$q->fetchColumn(); }
        ff_response(['counts'=>$counts,'recent'=>array_slice($invoices->list(),0,8),'settings'=>$settings->get()]);
    }
    if ($path === '/api/customers/lookup' && $method === 'POST') {
        // Release the PHP session lock while consulting the remote service.
        session_write_close();
        $gateway = new ArcaGateway($settings->get(true), $storagePath);
        ff_response(['customer'=>$gateway->lookupCustomer((string)($data['document_type'] ?? 'CUIT'),(string)($data['document_number'] ?? ''))]);
    }
    if (preg_match('~^/api/(products|customers)(?:/(\d+))?$~', $path, $match)) {
        $kind=$match[1]; $id=isset($match[2])?(int)$match[2]:null;
        if ($method==='GET' && !$id) { ff_response(['items'=>$catalog->list($kind,mb_substr((string)($_GET['search'] ?? ''),0,150))]); }
        if ($method==='POST' && !$id) { ff_response(['item'=>$catalog->save($kind,$data)],201); }
        if ($method==='PUT' && $id) { ff_response(['item'=>$catalog->save($kind,$data,$id)]); }
        if ($method==='DELETE' && $id) { $catalog->delete($kind,$id); ff_response(['ok'=>true]); }
    }
    if ($path==='/api/settings' && $method==='GET') { ff_response(['settings'=>$settings->get()]); }
    if ($path==='/api/settings' && $method==='POST') { ff_response(['settings'=>$settings->save($data,$_FILES)]); }
    if ($path==='/api/settings/test' && $method==='POST') {
        session_write_close();
        ff_response(['health'=>(new ArcaGateway($settings->get(true),$storagePath))->health()]);
    }
    if ($path==='/api/password' && $method==='POST') { $auth->changePassword((string)($data['current_password'] ?? ''),(string)($data['new_password'] ?? '')); ff_response(['ok'=>true]); }
    if ($path==='/api/invoices' && $method==='GET') { ff_response(['items'=>$invoices->list(mb_substr((string)($_GET['search']??''),0,150),(string)($_GET['status']??''))]); }
    if ($path==='/api/invoices/preview' && $method==='POST') { ff_response(['invoice'=>$invoices->preview($data)]); }
    if ($path==='/api/invoices/bulk-drafts' && $method==='POST') { $created=$invoices->bulkCreate($data); ff_response(['invoices'=>$created,'count'=>count($created)],201); }
    if ($path==='/api/invoices/issue' && $method==='POST') {
        session_write_close();
        set_time_limit(900);
        ignore_user_abort(true);
        ff_response($invoices->issueBatch($data));
    }
    if ($path==='/api/invoices' && $method==='POST') { ff_response(['invoice'=>$invoices->create($data)],201); }
    if (preg_match('~^/api/invoices/(\d+)(/reconcile)?$~',$path,$match)) {
        $id=(int)$match[1];
        if ($method==='POST' && !empty($match[2])) { session_write_close(); ff_response(['invoice'=>$invoices->reconcile($id)]); }
        if ($method==='GET' && empty($match[2])) { ff_response(['invoice'=>$invoices->find($id)]); }
        if ($method==='DELETE' && empty($match[2])) { $invoices->delete($id); ff_response(['ok'=>true]); }
    }
    ff_response(['error'=>'Ruta no encontrada.'],404);
} catch (JsonException $e) {
    ff_response(['error'=>'JSON inválido.'],422);
} catch (PDOException $e) {
    error_log('FacturaFacil database error: ' . $e->getMessage());
    if ($api) { ff_response(['error'=>'No se pudo guardar la operación. Revisá los datos o consultá el registro del servidor.'],422); }
    http_response_code(500); echo 'No se pudo completar la operación.';
} catch (RuntimeException $e) {
    if ($api) { ff_response(['error'=>$e->getMessage()],422); }
    http_response_code(422);
    if (!isset($user) || !$user) { $loginError=$e->getMessage(); require dirname(__DIR__) . '/app/views/login.php'; }
    else { echo ff_escape($e->getMessage()); }
} catch (Throwable $e) {
    error_log('FacturaFacil error: ' . $e->getMessage());
    if ($api) { ff_response(['error'=>'Error interno. Consultá el registro del servidor.'],500); }
    http_response_code(500); echo 'No se pudo completar la operación. Revisá la configuración del servidor.';
}
