<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$diasSemana = [1 => "Lunes", 2 => "Martes", 3 => "Miércoles", 4 => "Jueves", 5 => "Viernes", 6 => "Sábado", 0 => "Domingo"];

// --- CONSULTA DE RESERVAS ACTIVAS (TABLERO) ---
$sqlActivas = "SELECT 
            r.id AS reserva_id, r.fecha, r.suscripcion_id, r.usuario_id AS alumno_id,
            h.profesor_id, h.id AS horario_id, h.instrumento, h.modalidad, h.duracion_minutos,
            r.estado, h.hora, p.nombre AS nombre_profesor, u.nombre AS nombre_alumno,
            CASE WHEN r.suscripcion_id IS NOT NULL THEN 'Fijo' ELSE 'Individual' END as tipo_label
        FROM reservas r
        INNER JOIN horarios h ON r.horario_id = h.id
        INNER JOIN profesores p ON h.profesor_id = p.id
        INNER JOIN usuarios u ON r.usuario_id = u.id
        WHERE (h.profesor_id = ? OR r.usuario_id = ?)
        AND r.estado IN ('confirmada','pendiente')
        AND r.fecha >= CURDATE()
        ORDER BY r.fecha ASC, h.hora ASC";

$reservasActivas = $db->fetchAll($sqlActivas, [$userId, $userId]);

$fijosPorDia = [];
$extrasPorDia = [];
foreach ($reservasActivas as $r) {
    $numDia = (int)date('w', strtotime($r['fecha']));
    if ($r['suscripcion_id'] !== null) $fijosPorDia[$numDia][] = $r;
    else $extrasPorDia[$numDia][] = $r;
}

// --- LÓGICA DE HISTORIAL CON PAGINACIÓN Y AJAX ---
$limit = 10;
$page  = isset($_GET['p_his']) ? (int)$_GET['p_his'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$whereHist = ["(profesor_id = ? OR usuario_id = ?)"];
$paramsHist = [$userId, $userId];

if (!empty($_GET['hist_id'])) {
    $whereHist[] = "id = ?";
    $paramsHist[] = $_GET['hist_id'];
}
if (!empty($_GET['nombre'])) {
    $whereHist[] = "(nombre_alumno LIKE ? OR nombre_profesor LIKE ?)";
    $paramsHist[] = "%" . $_GET['nombre'] . "%";
    $paramsHist[] = "%" . $_GET['nombre'] . "%";
}
if (!empty($_GET['mes'])) {
    $whereHist[] = "MONTH(fecha_clase) = ?";
    $paramsHist[] = $_GET['mes'];
}
if (!empty($_GET['fecha_exacta'])) {
    $whereHist[] = "fecha_clase = ?";
    $paramsHist[] = $_GET['fecha_exacta'];
}

$whereStr = implode(" AND ", $whereHist);

$sqlCount = "SELECT COUNT(*) as total FROM historial_reservas WHERE $whereStr";
$resCount = $db->fetchAll($sqlCount, $paramsHist);
$totalRows = (int)($resCount[0]['total'] ?? 0);
$totalPages = (int)ceil($totalRows / $limit);

$sqlHist = "SELECT *, DAYOFWEEK(fecha_clase) as num_dia_semana FROM historial_reservas 
            WHERE $whereStr
            ORDER BY id DESC LIMIT $limit OFFSET $offset";
$historial = $db->fetchAll($sqlHist, $paramsHist);

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    renderHistorialContent($historial, $page, $totalPages);
    exit;
}

include_once 'navbar.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Reservas - Anima</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
    :root {
        --bg-color: #131527; 
        --bg-darker: #0d101b;
        --glass-bg: rgba(255, 255, 255, 0.05); 
        --glass-border: rgba(255, 255, 255, 0.12); 
        --text: #f8fafc; 
        --muted: #cbd5e1; 
        --accent: #8b5cf6; 
        --accent-glow: rgba(139, 92, 246, 0.5);
        --green: #4ade80;
        --gradient-primary: linear-gradient(135deg, #8b5cf6, #6d28d9);
        --radius: 24px;
        --glass: #111827; 
        --text-dim: #cbd5e1;
    }

    body { 
        background: 
            radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), 
            radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), 
            linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
        background-attachment: fixed;
        color: var(--text); 
        font-family: 'Inter', sans-serif; 
        margin: 0; 
        min-height: 100vh;
    }

    /* Partículas de "escenario" */
    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
    .particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
    .particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
    .particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
    .particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }

    .container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
    .page-header { display: flex; align-items: baseline; gap: 15px; margin-bottom: 5px; }
    h1 { font-weight: 900; font-size: 2.8rem; letter-spacing: -1.5px; margin: 0; text-shadow: 0 0 20px rgba(139, 92, 246, 0.3); }

    .seccion-titulo { 
        margin: 40px 0 20px; 
        font-weight: 900; font-size: 0.8rem; 
        text-transform: uppercase; letter-spacing: 2px; 
        color: var(--text-dim); display: flex; align-items: center; gap: 15px;
    }
    .seccion-titulo::after { content: ""; flex: 1; height: 1px; background: var(--glass-border); }

    .filters-container {
        background: var(--glass-bg); 
        border: 1px solid var(--glass-border);
        padding: 25px; border-radius: var(--radius); margin-bottom: 30px;
        display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;
        backdrop-filter: blur(20px);
        box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    }
    .filter-group label { display: block; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px; }
    .filter-group input, .filter-group select {
        width: 100%; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--glass-border);
        padding: 12px 14px; border-radius: 12px; color: #fff; font-size: 0.9rem; outline: none;
        appearance: none; transition: 0.3s;
    }
    .filter-group input:focus, .filter-group select:focus { border-color: var(--accent); box-shadow: 0 0 10px rgba(139, 92, 246, 0.3); }
    .filter-group select option { background: var(--bg-darker); color: #fff; }

    .btn-filter {
        background: var(--gradient-primary); color: white; border: none; padding: 10px; 
        border-radius: 12px; font-weight: 800; cursor: pointer; align-self: end;
        text-transform: uppercase; font-size: 0.8rem; transition: 0.3s; height: 44px;
        box-shadow: 0 10px 20px var(--accent-glow);
    }
    .btn-filter:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(139, 92, 246, 0.7); }

    .semana-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 15px; }
    @media (max-width: 1200px) { .semana-grid { grid-template-columns: repeat(4, 1fr); } }
    @media (max-width: 900px) { .semana-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 500px) { .semana-grid { grid-template-columns: 1fr; } }

    .dia-columna { background: var(--glass-bg); border-radius: var(--radius); padding: 15px; border: 1px solid var(--glass-border); min-height: 150px; backdrop-filter: blur(10px); }
    .dia-header { text-align: center; font-weight: 900; font-size: 0.75rem; color: #fff; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; }

    .reserva-card {
        background: rgba(0, 0, 0, 0.2); border: 1px solid var(--glass-border);
        border-radius: 16px; padding: 16px; margin-bottom: 12px; cursor: pointer; transition: 0.3s;
        backdrop-filter: blur(5px);
    }
    .reserva-card:hover { transform: translateY(-5px); background: rgba(255, 255, 255, 0.05); border-color: rgba(139, 92, 246, 0.5); box-shadow: 0 10px 20px rgba(0,0,0,0.3); }
    .card-fijo { border-left: 4px solid var(--accent); }
    .card-extra { border-left: 4px solid var(--green); }

    .card-time { font-weight: 900; font-size: 1.2rem; color: #fff; display: block; }
    .card-instr { font-size: 0.8rem; color: var(--text-dim); font-weight: 600; margin-bottom: 10px; display: block; }
    .pill-fecha { font-size: 0.7rem; color: var(--text-dim); font-weight: 700; opacity: 0.9; }

    .tag-identidad { font-size: 0.55rem; font-weight: 900; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; margin-bottom: 10px; width: fit-content; letter-spacing: 0.5px; }
    .tag-profe { background: rgba(139, 92, 246, 0.2); color: #d8b4fe; border: 1px solid rgba(139, 92, 246, 0.4); }
    .tag-alumno { background: rgba(255, 255, 255, 0.1); color: #f8fafc; border: 1px solid var(--glass-border); }

    .table-container { background: var(--glass-bg); border-radius: var(--radius); border: 1px solid var(--glass-border); overflow: hidden; margin-top: 20px; backdrop-filter: blur(15px); box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
    .historial-table { width: 100%; border-collapse: collapse; }
    .historial-table th { padding: 20px 25px; text-align: left; font-size: 0.7rem; color: #fff; text-transform: uppercase; background: rgba(0,0,0,0.3); font-weight: 900; letter-spacing: 1px; }
    .historial-table td { padding: 18px 25px; border-bottom: 1px solid var(--glass-border); font-size: 0.9rem; color: var(--text); }
    .historial-table tr:hover td { background: rgba(255,255,255,0.02); }
    
    .status-pill { padding: 6px 12px; border-radius: 8px; font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; }
    .status-completada { background: rgba(16, 185, 129, 0.15); color: #4ade80; border: 1px solid rgba(16, 185, 129, 0.3); }
    .status-cancelada { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
    .status-trasladada { background: rgba(234, 179, 8, 0.15); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.3); }

    .pagination { display: flex; justify-content: center; gap: 10px; padding: 25px; background: rgba(0,0,0,0.2); }
    .page-link { 
        padding: 8px 18px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); 
        border-radius: 12px; color: #fff; text-decoration: none; font-size: 0.85rem; font-weight: 700; transition: 0.3s;
    }
    .page-link:hover { background: rgba(139, 92, 246, 0.2); border-color: rgba(139, 92, 246, 0.4); }
    .page-link.active { background: var(--gradient-primary); border-color: transparent; box-shadow: 0 5px 15px var(--accent-glow); }

    /* MODAL ESTILOS */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(15px); display: none; justify-content: center; align-items: center; z-index: 9999; padding: 20px; }
    .modal-card { background: var(--bg-darker); border: 1px solid var(--glass-border); width: 100%; max-width: 440px; border-radius: var(--radius); padding: 35px; box-shadow: 0 30px 60px rgba(0, 0, 0, 0.6), 0 0 30px rgba(139,92,246,0.1); }
    
    .modal-info-grid { display: grid; grid-template-columns: 1fr; gap: 18px; margin-top: 25px; border-top: 1px solid var(--glass-border); padding-top: 25px; }
    .info-row-main { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    
    .info-item label { display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--text-dim); font-weight: 800; margin-bottom: 6px; letter-spacing: 0.5px; }
    .info-item span { font-size: 1.05rem; font-weight: 700; color: #fff; line-height: 1.2; }
    
    .duracion-pill { 
        display: inline-flex; align-items: center; background: rgba(255,255,255,0.08); 
        padding: 5px 12px; border-radius: 10px; border: 1px solid var(--glass-border);
        font-size: 0.8rem; color: #fff; font-weight: 800; margin-top: 8px;
    }

    #modalActions { margin-top: 35px; display: flex; flex-direction: column; gap: 12px; }
    .btn-action { 
        width: 100%; height: 46px; border-radius: 14px; font-weight: 800; 
        text-transform: uppercase; border: none; cursor: pointer; 
        display: flex; align-items: center; justify-content: center; gap: 10px; 
        font-size: 0.8rem; text-decoration: none; transition: 0.3s; letter-spacing: 0.5px;
    }
    .btn-edit { background: #fff; color: var(--bg-darker); }
    .btn-move { background: rgba(59, 130, 246, 0.15); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
    .btn-cancel { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
    .btn-action:hover { transform: translateY(-2px); filter: brightness(1.1); box-shadow: 0 8px 20px rgba(0,0,0,0.3); }

    #historial-ajax-container { transition: opacity 0.3s ease; }
    
    @media (max-width: 900px) {
        .table-container { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
        .historial-table { min-width: 800px; }
        .table-container::-webkit-scrollbar { height: 6px; }
        .table-container::-webkit-scrollbar-track { background: transparent; }
        .table-container::-webkit-scrollbar-thumb { background: rgba(139, 92, 246, 0.5); border-radius: 10px; }
    }
</style>
</head>
<body>
<div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
<div class="container">
    <h1>Mis Turnos</h1>
    <p style="color: var(--text-dim); font-weight: 600; font-size: 0.95rem; margin-bottom: 30px;">Gestiona tus horarios y revisa tu actividad.</p>

    <?php 
    renderTablero($fijosPorDia, "Suscripciones Fijas", "card-fijo", $userId);
    renderTablero($extrasPorDia, "Turnos Individuales", "card-extra", $userId);
    ?>

    <div class="seccion-titulo">Historial de Reservas</div>

    <form id="filter-form" class="filters-container" method="GET">
        <div class="filter-group">
            <label>ID Historial</label>
            <input type="number" name="hist_id" value="<?= h($_GET['hist_id'] ?? '') ?>" placeholder="Ej: 124">
        </div>
        <div class="filter-group">
            <label>Alumno / Profesor</label>
            <input type="text" name="nombre" value="<?= h($_GET['nombre'] ?? '') ?>" placeholder="Buscar nombre...">
        </div>
        <div class="filter-group">
            <label>Mes</label>
            <select name="mes">
                <option value="">Todos los meses</option>
                <?php 
                $meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
                foreach($meses as $idx => $nombreMes): ?>
                    <option value="<?= $idx+1 ?>" <?= ($_GET['mes'] ?? '') == ($idx+1) ? 'selected' : '' ?>>
                        <?= $nombreMes ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Fecha Exacta</label>
            <input type="date" name="fecha_exacta" value="<?= h($_GET['fecha_exacta'] ?? '') ?>">
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> buscar</button>
        <button type="button" id="btn-clear" class="btn-filter" style="background:rgba(255,255,255,0.05);">Ver todo</button>
    </form>

    <div id="historial-ajax-container">
        <?php renderHistorialContent($historial, $page, $totalPages); ?>
    </div>
</div>

<div id="reservaModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div id="modalContent"></div>
        <div id="modalActions"></div>
    </div>
</div>

<?php 
function renderTablero($grupos, $titulo, $claseExtra, $userId) {
    global $diasSemana;
    echo "<div class='seccion-titulo'>$titulo</div><div class='semana-grid'>";
    foreach ($diasSemana as $num => $nombre) {
        echo "<div class='dia-columna'><div class='dia-header'>$nombre</div>";
        if (isset($grupos[$num])) {
            foreach ($grupos[$num] as $r) {
                $esProfe = ((int)$r['profesor_id'] === $userId);
                echo "
                <div class='reserva-card $claseExtra' onclick='openModal(".json_encode($r).", $userId)'>
                    <div class='tag-identidad ".($esProfe ? 'tag-profe' : 'tag-alumno')."'>".($esProfe ? 'PROFESOR' : 'ALUMNO')."</div>
                    <span class='card-time'>".substr($r['hora'],0,5)."</span>
                    <span class='card-instr'>".h($r['instrumento'])."</span>
                    <div class='pill-fecha'><i class='far fa-calendar'></i> ".date('d/m', strtotime($r['fecha']))."</div>
                </div>";
            }
        }
        echo "</div>";
    }
    echo "</div>";
}

function renderHistorialContent($historial, $page, $totalPages) {
    ?>
    <div class="table-container">
        <table class="historial-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Alumno</th>
                    <th>Profesor</th>
                    <th>Instrumento</th>
                    <th>Fecha</th>
                    <th>Día</th>
                    <th>Modalidad</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial as $h): 
                    $nombreDia = [1=>'Lunes', 2=>'Martes', 3=>'Miércoles', 4=>'Jueves', 5=>'Viernes', 6=>'Sábado', 7=>'Domingo'];
                    $diaNum = (int)$h['num_dia_semana'];
                    $nombreDiaCorrecto = $nombreDia[$diaNum == 1 ? 7 : $diaNum - 1];
                ?>
                <tr>
                    <td style="font-weight: 800; color: var(--accent);">#<?= $h['id'] ?></td>
                    <td><div style="font-weight: 700;"><?= h($h['nombre_alumno']) ?></div></td>
                    <td><div style="font-weight: 700;"><?= h($h['nombre_profesor']) ?></div></td>
                    <td style="color: var(--text-dim);"><?= h($h['instrumento']) ?></td>
                    <td><strong><?= date('d/m/Y', strtotime($h['fecha_clase'])) ?></strong><br><small><?= substr($h['hora'], 0, 5) ?>hs</small></td>
                    <td><span style="font-size: 0.75rem; opacity: 0.8;"><?= $nombreDiaCorrecto ?></span></td>
                    <td><span style="font-size: 0.75rem; font-weight: 600;"><?= h($h['modalidad'] ?? 'Presencial') ?></span></td>
                    <td><span class="status-pill status-<?= strtolower($h['estado']) ?>"><?= h($h['estado']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($historial)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:40px; color:var(--text-dim);">No se encontraron registros en el historial.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php 
            $start = max(1, $page - 5);
            $end = min($totalPages, $start + 9);
            if ($end - $start < 9) $start = max(1, $end - 9);

            if ($page > 1): ?>
                <a href="?p_his=1" class="page-link ajax-page"><i class="fas fa-angle-double-left"></i></a>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <a href="?p_his=<?= $i ?>" class="page-link ajax-page <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?p_his=<?= $totalPages ?>" class="page-link ajax-page"><i class="fas fa-angle-double-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<script>
/** --- AJAX --- **/
function cargarHistorial(url) {
    const container = document.getElementById('historial-ajax-container');
    container.style.opacity = '0.5';

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.text())
        .then(html => {
            container.innerHTML = html;
            container.style.opacity = '1';
            window.history.pushState(null, '', url);
        })
        .catch(err => {
            console.error("Error AJAX:", err);
            container.style.opacity = '1';
        });
}

document.addEventListener('click', function(e) {
    const link = e.target.closest('.ajax-page');
    if (link) {
        e.preventDefault();
        cargarHistorial(link.href);
    }
});

document.getElementById('filter-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(this));
    params.set('p_his', '1');
    cargarHistorial('mis-reservas.php?' + params.toString());
});

document.getElementById('btn-clear').addEventListener('click', function() {
    document.getElementById('filter-form').reset();
    cargarHistorial('mis-reservas.php');
});

function openModal(data, currentUserId) {
    const modal = document.getElementById('reservaModal');
    const actions = document.getElementById('modalActions');
    const content = document.getElementById('modalContent');
    
    content.innerHTML = `
        <div style="text-align:center; margin-bottom: 30px;">
            <div style="font-size: 0.65rem; font-weight: 900; color: var(--accent); text-transform: uppercase; letter-spacing: 2.5px; margin-bottom: 12px;">Turno ${data.tipo_label}</div>
            <div style="font-size: 4rem; font-weight: 900; letter-spacing: -3px; color: #fff; line-height: 1; margin-bottom: 5px;">${data.hora.substring(0,5)}</div>
            <div style="color: var(--text-dim); font-weight: 700; font-size: 1rem;">${data.fecha.split('-').reverse().join(' / ')}</div>
        </div>
        
        <div class="modal-info-grid">
            <div class="info-row-main">
                <div class="info-item">
                    <label>Profesor</label>
                    <span>${data.nombre_profesor}</span>
                </div>
                <div class="info-item">
                    <label>Alumno</label>
                    <span>${data.nombre_alumno}</span>
                </div>
            </div>
            
            <div class="info-row-main">
                <div class="info-item">
                    <label>Instrumento</label>
                    <span>${data.instrumento}</span>
                    <div class="duracion-pill">
                        <i class="far fa-clock" style="margin-right:6px; opacity:0.6"></i>
                        ${data.duracion_minutos} min
                    </div>
                </div>
                <div class="info-item">
                    <label>Modalidad</label>
                    <span>${data.modalidad}</span>
                </div>
            </div>
        </div>
    `;

    actions.innerHTML = '';
    const esProfe = (parseInt(data.profesor_id) === currentUserId);
    const esFijo = (data.suscripcion_id !== null && data.suscripcion_id !== "");

    if (esProfe) {
        let htmlButtons = `<a href="procesar-editar-clase.php?id=${data.reserva_id}" class="btn-action btn-edit"><i class="fas fa-pen"></i> Editar Turno</a>`;

        // Botón Trasladar con confirmación (Solo Fijos)
        if (esFijo) {
            htmlButtons += `
                <form method="POST" action="procesar.php" style="width:100%" onsubmit="return confirm('¿Estás seguro de que deseas trasladar esta clase a la próxima semana?')">
                    <input type="hidden" name="accion" value="trasladar">
                    <input type="hidden" name="reserva_id" value="${data.reserva_id}">
                    <button type="submit" class="btn-action btn-move"><i class="fas fa-calendar-arrow-right"></i> Trasladar a próxima semana</button>
                </form>`;
        }

        // Botón Cancelar con confirmación
        const msgCancel = esFijo ? '¿Confirmas que deseas cancelar el turno fijo?' : '¿Confirmas que deseas cancelar este turno extra?';
        
        htmlButtons += `
            <form method="POST" action="procesar.php" style="width:100%" onsubmit="return confirm('${msgCancel}')">
                <input type="hidden" name="accion" value="cancelar">
                <input type="hidden" name="${data.suscripcion_id ? 'suscripcion_id' : 'reserva_id'}" value="${data.suscripcion_id || data.reserva_id}">
                <button type="submit" class="btn-action btn-cancel"><i class="fas fa-trash"></i> Cancelar Reserva</button>
            </form>`;
        
        actions.innerHTML = htmlButtons;
    } else {
        actions.innerHTML = '<div style="background:rgba(255,255,255,0.03); padding:18px; border-radius:18px; text-align:center; font-size:0.7rem; color:var(--text-dim); font-weight:800; border:1px solid var(--stroke); letter-spacing:1px;">VISTA DE ALUMNO</div>';
    }
    modal.style.display = 'flex';
}
function closeModal(e) { if (e.target.classList.contains('modal-overlay')) document.getElementById('reservaModal').style.display = 'none'; }
</script>

<div style="height: 100px;"></div>
</body>
</html>