<!doctype html>
<html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FacturaFácil · Ingresar</title><link rel="stylesheet" href="<?= ff_escape(($basePath ?? '') . '/assets/app.css') ?>"></head>
<body class="auth-body"><main class="auth-card"><div class="brand-mark">F</div><h1>FacturaFácil</h1><p>Tu facturación, en un solo lugar.</p>
<?php if (!empty($installation)): ?><h2>Configurar acceso</h2><p>Desde una terminal del servidor ejecutá:</p><pre>php bin/install.php</pre><p>Después volvé a esta página para ingresar.</p>
<?php else: ?>
<?php if (!empty($loginError)): ?><div class="notice error" role="alert"><?= ff_escape($loginError) ?></div><?php endif; ?>
<form method="post" action="<?= ff_escape(($basePath ?? '') . '/login') ?>"><input type="hidden" name="_csrf" value="<?= ff_escape($csrf ?? '') ?>"><label>Correo electrónico<input type="email" name="email" required autocomplete="username" maxlength="254"></label><label>Contraseña<input type="password" name="password" required autocomplete="current-password" maxlength="72"></label><button class="btn primary" type="submit">Ingresar</button></form>
<?php endif; ?></main></body></html>
