<?php declare(strict_types=1); $e = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>
<!doctype html>
<html lang="es-AR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $e($csrf) ?>">
  <meta name="theme-color" content="#2159db">
  <title>FacturaFácil · Gestión y facturación</title>
  <link rel="stylesheet" href="<?= $e($basePath) ?>/assets/app.css">
  <script src="<?= $e($basePath) ?>/assets/app.js" defer></script>
</head>
<body data-base-path="<?= $e($basePath) ?>">
<a class="skip-link" href="#main">Ir al contenido</a>
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="#dashboard"><span class="brand-symbol">f<span>·</span></span><span>Factura<span class="brand-light">Fácil</span><small>GESTIÓN SIMPLE</small></span></a>
    <div class="nav-caption">PRINCIPAL</div>
    <nav aria-label="Menú principal">
      <a class="nav-item" href="#dashboard" data-page="dashboard"><span class="nav-icon" data-icon="grid"></span>Resumen</a>
      <a class="nav-item" href="#invoices" data-page="invoices"><span class="nav-icon" data-icon="document"></span>Facturas</a>
      <a class="nav-item" href="#products" data-page="products"><span class="nav-icon" data-icon="box"></span>Productos</a>
      <a class="nav-item" href="#customers" data-page="customers"><span class="nav-icon" data-icon="users"></span>Clientes</a>
      <div class="nav-caption settings-caption">ADMINISTRACIÓN</div>
      <a class="nav-item" href="#settings" data-page="settings"><span class="nav-icon" data-icon="settings"></span>Configuración</a>
    </nav>
    <div class="sidebar-bottom"><div class="sidebar-tip"><span class="nav-icon" data-icon="shield"></span><strong>Tu gestión, en orden</strong><p>Productos, clientes y comprobantes en un solo lugar.</p></div><span class="version">FacturaFácil · v1.0</span></div>
  </aside>
  <div class="workspace">
    <header class="topbar"><div class="topbar-left"><button id="menu-toggle" class="icon-button mobile-menu" aria-label="Abrir menú" aria-expanded="false"><span data-icon="menu"></span></button><span class="breadcrumb">Mi espacio <span>/</span> <strong id="breadcrumb-page">Resumen</strong></span></div><div class="topbar-right"><span class="environment-badge" id="environment-badge">Cargando entorno</span><div class="account-avatar"><?= $e(strtoupper(substr((string) ($user['email'] ?? 'U'), 0, 1))) ?></div><div class="account-details"><strong>Administrador</strong><span><?= $e($user['email'] ?? '') ?></span></div><form action="<?= $e($basePath) ?>/logout" method="post"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="icon-button" title="Cerrar sesión" aria-label="Cerrar sesión"><span data-icon="logout"></span></button></form></div></header>
    <main id="main" tabindex="-1"><div class="loading-panel"><span class="spinner"></span>Preparando tu espacio…</div></main>
    <footer class="app-footer">Hecho para simplificar tu día.<span>FacturaFácil</span></footer>
  </div>
</div>
<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true"></div>
<dialog id="app-dialog" class="app-dialog"><div class="dialog-heading"><div><h2 id="dialog-title"></h2><p id="dialog-subtitle"></p></div><button class="icon-button" id="dialog-close" type="button" aria-label="Cerrar"><span data-icon="close"></span></button></div><div id="dialog-content"></div></dialog>
</body>
</html>
