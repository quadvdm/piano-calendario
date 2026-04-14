<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Conexión a la base de datos (Ajusta la ruta según tu estructura)
require_once '../config/database.php';
$db_admin = Database::getInstance();
$conn = $db_admin->getConnection();

$user_name    = $_SESSION['user_name']  ?? 'Administrador';
$user_email   = $_SESSION['user_email'] ?? '';
$user_id_sesion = $_SESSION['user_id'] ?? 0;

$cant_notif = 0;
if ($user_id_sesion > 0 && isset($conn)) {
    $sql_n = "SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ? AND leido = 0";
    $stmt_n = $conn->prepare($sql_n);
    $stmt_n->bind_param("i", $user_id_sesion);
    $stmt_n->execute();
    $res_n = $stmt_n->get_result();
    if ($res_n) {
        $cant_notif = (int)$res_n->fetch_assoc()['total'];
    }
}

$current = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Admin · Anima Música</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --bg: #0b1220;
    --bg2: #070b14;
    --card-bg: rgba(255, 255, 255, 0.03);
    --border: rgba(255, 255, 255, 0.08);
    --text: #f3f4f6;
    --muted: #9ca3af;
    --accent: #4f46e5;
    --danger: #991b1b; 
    --radius: 14px;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: 'Inter', system-ui, sans-serif;
    background-color: var(--bg);
    background-image: radial-gradient(circle at top right, rgba(79, 70, 229, 0.05), transparent),
                      radial-gradient(circle at bottom left, rgba(79, 70, 229, 0.05), transparent);
    color: var(--text);
    min-height: 100vh;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: rgba(11, 18, 32, 0.85);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.brand {
    font-weight: 900;
    font-size: 1rem;
    letter-spacing: -0.5px;
    color: #fff;
    white-space: nowrap;
}

.user-section {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-info {
    text-align: right;
    display: none;
}

.user-info .name { font-weight: 800; font-size: 13px; display: block; color: #fff; }
.user-info .email { color: var(--muted); font-size: 10px; }

.btn-top {
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid var(--border);
    white-space: nowrap;
}

.btn-vista { 
    background: rgba(255,255,255,0.03); 
    color: #fff; 
}
.btn-vista:hover { 
    background: rgba(255,255,255,0.08); 
    border-color: rgba(255,255,255,0.3); 
}

.btn-salir { 
    background: var(--danger); 
    color: #fff; 
    border: none; 
}
.btn-salir:hover { 
    background: #b91c1c; 
    transform: translateY(-1px); 
}

.nav-tabs {
    display: flex;
    gap: 8px;
    padding: 10px 20px;
    overflow-x: auto;
    background: rgba(11, 18, 32, 0.4);
    border-bottom: 1px solid var(--border);
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}
.nav-tabs::-webkit-scrollbar { display: none; }

.nav-tabs a {
    text-decoration: none;
    color: var(--muted);
    padding: 10px 16px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    background: rgba(255,255,255,0.03);
    border: 1px solid transparent;
    transition: 0.2s;
    white-space: nowrap;
}

.nav-tabs a.active {
    color: #fff;
    background: rgba(79, 70, 229, 0.2);
    border-color: rgba(79, 70, 229, 0.4);
}

.main-content { 
    padding: 20px; 
    max-width: 1200px; 
    margin: 0 auto; 
}

.nav-notif {
    position: relative;
    display: flex;
    align-items: center;
}

.notif-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: #ef4444;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    border: 1.5px solid #0b1220;
    z-index: 10;
}

.pulse-notif {
    animation: pulse-red 2s infinite;
}

@keyframes pulse-red {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}

@media (min-width: 640px) {
    .topbar { padding: 15px 30px; }
    .brand { font-size: 1.1rem; }
    .user-info { display: block; }
    .btn-top { font-size: 13px; padding: 10px 18px; }
    .nav-tabs { padding: 12px 30px; gap: 10px; }
    .nav-tabs a { font-size: 14px; }
    .main-content { padding: 30px; }
}

@media (max-width: 480px) {
    .btn-vista { display: none; }
    .brand { font-size: 0.9rem; }
    .topbar { padding: 10px 15px; }
}
</style>
</head>
<body>

<header class="topbar">
    <div class="brand">Admin · Anima Música</div>

    <div class="user-section">
        <div class="user-info">
            <span class="name"><?= htmlspecialchars($user_name) ?></span>
            <span class="email"><?= htmlspecialchars($user_email) ?></span>
        </div>
        <a href="/web_turnos/dashboard.php" class="btn-top btn-vista">Vista alumno</a>
        <a href="../logout.php" class="btn-top btn-salir">Salir</a>
    </div>
</header>

<nav class="nav-tabs">
    <a href="index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">Dashboard</a>
    <a href="usuarios.php" class="<?= ($current === 'usuarios.php' || $current === 'usuarios-editar.php') ? 'active' : '' ?>">Usuarios</a>
    <a href="profesores1.php" class="<?= $current === 'profesores1.php' ? 'active' : '' ?>">Profesores</a>
    <a href="instrumentos.php" class="<?= $current === 'instrumentos.php' ? 'active' : '' ?>">Instrumentos</a>
    <a href="horarios.php" class="<?= $current === 'horarios.php' ? 'active' : '' ?>">Horarios</a>
    <a href="reservas.php" class="<?= $current === 'reservas.php' ? 'active' : '' ?>">Reservas</a>
    
    <a href="auditoria.php" class="<?= $current === 'auditoria.php' ? 'active' : '' ?> nav-notif">
        <i class="fas fa-bell"></i> Auditoría
        <?php if ($cant_notif > 0): ?>
            <span class="notif-badge pulse-notif"></span>
        <?php endif; ?>
    </a>
</nav>

<main class="main-content">