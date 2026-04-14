<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/header.php';

$db   = Database::getInstance();
$conn = $db->getConnection();

// 1. MÉTRICAS DINÁMICAS (Mes actual)
$primerDiaMes = date('Y-m-01');
$ultimoDiaMes = date('Y-m-t');

// Traducción de meses manual para evitar problemas de configuración en el servidor
$meses = ["", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
$mesNombre = $meses[(int)date('m')];
$anioActual = date('Y');

// Usuarios activos (totales)
$usuariosActivos = 0;
if ($r = $conn->query("SELECT COUNT(*) AS c FROM usuarios WHERE activo = 1")) {
    $usuariosActivos = (int)($r->fetch_assoc()['c'] ?? 0);
}

// Profesores activos (Rol profesor o admin-profesor)
$profesoresActivos = 0;
if ($r = $conn->query("SELECT COUNT(*) AS c FROM usuarios WHERE activo = 1 AND (rol = 'profesor' OR rol = 'admin-profesor')")) {
    $profesoresActivos = (int)($r->fetch_assoc()['c'] ?? 0);
}

// Clases RESERVADAS ACTIVAS del mes (Tabla: reservas)
$clasesMes = 0;
$stClases = $conn->prepare("SELECT COUNT(*) AS c FROM reservas WHERE fecha BETWEEN ? AND ? AND estado IN ('pendiente','confirmada')");
$stClases->bind_param('ss', $primerDiaMes, $ultimoDiaMes);
$stClases->execute();
$clasesMes = (int)($stClases->get_result()->fetch_assoc()['c'] ?? 0);

// CANCELACIONES del mes actual (Tabla: historial_reservas)
$cancelacionesMes = 0;
$stCanc = $conn->prepare("SELECT COUNT(*) AS c FROM historial_reservas WHERE fecha_clase BETWEEN ? AND ? AND estado = 'cancelada'");
$stCanc->bind_param('ss', $primerDiaMes, $ultimoDiaMes);
$stCanc->execute();
$cancelacionesMes = (int)($stCanc->get_result()->fetch_assoc()['c'] ?? 0);
?>

<style>
    :root {
        --accent: #8b5cf6;
        --accent-glow: rgba(139, 92, 246, 0.3);
        --card-bg: rgba(255, 255, 255, 0.03);
        --border: rgba(255, 255, 255, 0.08);
        --text-dim: #94a3b8;
    }

    .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 20px; }

    /* Grilla de Métricas */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 50px;
    }

    .metric-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 24px;
        padding: 30px;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(10px);
    }

    .metric-card:hover {
        border-color: var(--accent);
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.3), 0 0 20px var(--accent-glow);
    }

    .metric-label { 
        color: var(--text-dim); 
        font-size: 11px; 
        font-weight: 800; 
        text-transform: uppercase; 
        letter-spacing: 1.5px; 
        margin-bottom: 10px;
        display: block;
    }

    .metric-value { 
        font-size: 3.5rem; 
        font-weight: 900; 
        color: #fff; 
        line-height: 1; 
        margin: 5px 0;
    }

    .metric-sub { 
        font-size: 12px; 
        color: var(--text-dim); 
        margin-top: 15px;
        line-height: 1.4;
    }

    /* Sección de Hoja de Ruta */
    .guide-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 25px;
    }

    .guide-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 25px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: 0.3s;
    }

    .guide-card:hover {
        background: rgba(255, 255, 255, 0.04);
        border-color: rgba(255, 255, 255, 0.15);
    }

    .guide-card h3 { 
        font-size: 1.1rem; 
        font-weight: 800; 
        margin: 0 0 12px 0; 
        color: #fff; 
        display: flex; 
        align-items: center; 
        gap: 10px; 
    }

    .guide-card h3 i { color: var(--accent); font-size: 1.2rem; }

    .guide-card p { 
        color: var(--text-dim); 
        font-size: 14px; 
        line-height: 1.6; 
        margin: 0 0 20px 0; 
    }

    .btn-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 20px;
        background: var(--accent);
        color: #fff;
        text-decoration: none;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 800;
        transition: 0.3s;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .btn-action:hover {
        filter: brightness(1.2);
        box-shadow: 0 0 15px var(--accent-glow);
        transform: scale(1.02);
    }

    .btn-secondary {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: #fff;
    }

    @media (max-width: 640px) {
        .metric-value { font-size: 2.8rem; }
        .guide-grid { grid-template-columns: 1fr; }
        .dashboard-container { padding: 15px; }
    }
</style>

<div class="dashboard-container">
    <div style="margin-bottom: 40px; border-left: 4px solid var(--accent); padding-left: 20px;">
        <h1 style="font-size: 2rem; font-weight: 900; margin: 0; letter-spacing: -1px;">Panel de Control</h1>
        <p style="color: var(--text-dim); margin-top: 5px; font-size: 1.1rem;">
            Estado operativo de <strong><?= $mesNombre ?> <?= $anioActual ?></strong>
        </p>
    </div>

    <section class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Comunidad</span>
            <div class="metric-value"><?= $usuariosActivos ?></div>
            <p class="metric-sub">Usuarios con acceso habilitado al sistema de Ánima.</p>
        </div>

        <div class="metric-card">
            <span class="metric-label">Docentes</span>
            <div class="metric-value"><?= $profesoresActivos ?></div>
            <p class="metric-sub">Profesores activos.</p>
        </div>

        <div class="metric-card">
            <span class="metric-label">Clases Activas</span>
            <div class="metric-value" style="color: var(--accent);"><?= $clasesMes ?></div>
            <p class="metric-sub">Reservas programadas (pendientes y confirmadas) para este mes.</p>
        </div>

        <div class="metric-card">
            <span class="metric-label">Bajas</span>
            <div class="metric-value" style="color: #f87171;"><?= $cancelacionesMes ?></div>
            <p class="metric-sub">Turnos liberados o cancelados durante el periodo actual.</p>
        </div>
    </section>

    <h2 style="font-size: 12px; text-transform: uppercase; letter-spacing: 2px; color: var(--text-dim); margin-bottom: 25px; font-weight: 800; opacity: 0.7;">
        Gestión del Sistema
    </h2>
    
    <section class="guide-grid">
        <div class="guide-card">
            <div>
                <h3><i class="fas fa-users"></i> Gestión de Cuentas</h3>
                <p>Monitorea a los usuarios. Visualiza las reservas activas que tiene el usuario, ya sea como docente o como alumno.</p>
            </div>
            <a href="usuarios.php" class="btn-action">Ver Usuarios</a>
        </div>

        <div class="guide-card">
            <div>
                <h3><i class="fas fa-chalkboard-teacher"></i> Staff e Instrumentos</h3>
                <p>Define el catálogo de instrumento y asigna a nuevos profesores.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="profesores1.php" class="btn-action" style="width: 150px;">Profesores</a>
                <a href="instrumentos.php" class="btn-action btn-secondary" style="width: 150px;"><i class="fas fa-music"></i>Instrumentos</a>
            </div>
        </div>

        <div class="guide-card">
            <div>
                <h3><i class="fas fa-calendar-alt"></i> Configuración de Horarios</h3>
                <p>Crea la disponibilidad semanal. Asigna franjas horarias a los docentes.</p>
            </div>
            <a href="horarios.php" class="btn-action">Configurar Horarios</a>
        </div>

        <div class="guide-card">
            <div>
                <h3><i class="fas fa-clipboard-check"></i> Control de Reservas</h3>
                <p>Gestiona traslados de fecha para turnos fijos y administra cancelaciones manuales.</p>
            </div>
            <a href="reservas.php" class="btn-action">Ver Reservas</a>
        </div>
    </section>
</div>

</body>
</html>