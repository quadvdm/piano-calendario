<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/header.php';

$db   = Database::getInstance();
$conn = $db->getConnection();

// --- LÓGICA DE LIMPIEZA Y SALTO ANTICIPADO ---
$ahora = date('Y-m-d H:i:s');
$hoy   = date('Y-m-d');

$config = [];
$resConf = $conn->query("SELECT clave, valor FROM configuraciones");
while ($rowConf = $resConf->fetch_assoc()) {
    $config[$rowConf['clave']] = $rowConf['valor'];
}

$diasAnticipacion = (int)($config['dias_anticipacion_reserva'] ?? 0);
$minFecha = date('Y-m-d', strtotime("+{$diasAnticipacion} days"));

$check_query = "SELECT id, fecha_especifica, hora, duracion_minutos, tipo_turno, reservas_actuales 
                FROM horarios 
                WHERE activo = 1";

$res_check = $conn->query($check_query);

while ($row = $res_check->fetch_assoc()) {

    $fecha_inicio = $row['fecha_especifica'] . ' ' . $row['hora'];
    $fecha_fin = date('Y-m-d H:i:s', strtotime($fecha_inicio . " + " . $row['duracion_minutos'] . " minutes"));
    $dia_anterior = date('Y-m-d', strtotime($row['fecha_especifica'] . ' -1 day'));

    if (
        $diasAnticipacion > 0 &&
        (int)$row['reservas_actuales'] === 0 &&
        $row['fecha_especifica'] < $minFecha
    ) {

        if ($row['tipo_turno'] === 'fijo') {

            $nueva_fecha = $row['fecha_especifica'];
            while ($nueva_fecha < $minFecha) {
                $nueva_fecha = date('Y-m-d', strtotime($nueva_fecha . " + 7 days"));
            }

            $conn->query("UPDATE horarios 
                          SET fecha_especifica = '$nueva_fecha' 
                          WHERE id = {$row['id']}");
        } else {
            // extra → eliminar
            $conn->query("DELETE FROM reservas WHERE horario_id = {$row['id']}");
            $conn->query("DELETE FROM horarios WHERE id = {$row['id']}");
        }

        continue;
    }

    // CASO 0: TURNO DE HOY CANCELADO
    if ((int)$row['reservas_actuales'] === 0 
        && $hoy == $row['fecha_especifica']) {

        if ($row['tipo_turno'] === 'extra') {
            $conn->query("DELETE FROM reservas WHERE horario_id = {$row['id']}");
            $conn->query("DELETE FROM horarios WHERE id = {$row['id']}");
        } else {
            $nueva_fecha = date('Y-m-d', strtotime($row['fecha_especifica'] . " + 7 days"));
            $conn->query("UPDATE horarios 
                          SET fecha_especifica = '$nueva_fecha' 
                          WHERE id = {$row['id']}");
        }

        continue;
    }

    // CASO 1: TURNO VACÍO (días anteriores)
    if ((int)$row['reservas_actuales'] === 0 
        && $hoy > $dia_anterior 
        && $hoy != $row['fecha_especifica']) {

        if ($row['tipo_turno'] === 'fijo') {
            $nueva_fecha = date('Y-m-d', strtotime($row['fecha_especifica'] . " + 7 days"));
            $conn->query("UPDATE horarios 
                          SET fecha_especifica = '$nueva_fecha' 
                          WHERE id = {$row['id']}");
        } else {
            $conn->query("DELETE FROM reservas WHERE horario_id = {$row['id']}");
            $conn->query("DELETE FROM horarios WHERE id = {$row['id']}");
        }

        continue;
    }

    // CASO 2: TURNO CON RESERVAS
    if ($ahora > $fecha_fin) {

        if ($row['tipo_turno'] === 'fijo') {
            $nueva_fecha = date('Y-m-d', strtotime($row['fecha_especifica'] . " + 7 days"));
            $conn->query("UPDATE horarios 
                          SET fecha_especifica = '$nueva_fecha',
                              reservas_actuales = 0 
                          WHERE id = {$row['id']}");
        } else {
            $conn->query("DELETE FROM reservas WHERE horario_id = {$row['id']}");
            $conn->query("DELETE FROM horarios WHERE id = {$row['id']}");
        }
    }
}

// --- CONFIGURACIÓN DE PAGINACIÓN ---
$por_pagina = 10;
$pagina_actual = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $por_pagina;

// --- PROCESAMIENTO DE FILTROS ---
$where = []; $params = []; $types = "";

if (!empty($_GET['f_id'])) { $where[] = "h.id = ?"; $params[] = (int)$_GET['f_id']; $types .= "i"; }
if (!empty($_GET['f_dia'])) { $where[] = "h.dia_semana = ?"; $params[] = $_GET['f_dia']; $types .= "s"; }
if (!empty($_GET['f_mes'])) { $where[] = "DATE_FORMAT(h.fecha_especifica, '%Y-%m') = ?"; $params[] = $_GET['f_mes']; $types .= "s"; }
if (!empty($_GET['f_instrumento'])) { $where[] = "h.instrumento = ?"; $params[] = $_GET['f_instrumento']; $types .= "s"; }
if (!empty($_GET['f_profesor'])) { $where[] = "h.profesor_id = ?"; $params[] = (int)$_GET['f_profesor']; $types .= "i"; }
if (!empty($_GET['f_tipo'])) { $where[] = "h.tipo_turno = ?"; $params[] = $_GET['f_tipo']; $types .= "s"; }
if (!empty($_GET['f_modalidad'])) { $where[] = "h.modalidad = ?"; $params[] = $_GET['f_modalidad']; $types .= "s"; }

if (isset($_GET['f_estado']) && $_GET['f_estado'] !== '') {
    if ($_GET['f_estado'] === 'reservada') { $where[] = "h.reservas_actuales > 0"; }
    else { $where[] = "h.reservas_actuales = 0"; }
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Contar total para paginación
$total_query = "SELECT COUNT(*) as total FROM horarios h $where_sql";
$stmt_t = $conn->prepare($total_query);
if (count($params) > 0) { $stmt_t->bind_param($types, ...$params); }
$stmt_t->execute();
$total_registros = $stmt_t->get_result()->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Consulta final con LIMIT
$sql = "SELECT h.*, p.nombre AS profesor FROM horarios h 
        LEFT JOIN profesores p ON p.id = h.profesor_id 
        $where_sql 
        ORDER BY h.fecha_especifica ASC, h.hora ASC 
        LIMIT $offset, $por_pagina";

$stmt = $conn->prepare($sql);
if (count($params) > 0) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

$all_profesores = $conn->query("SELECT id, nombre FROM profesores WHERE activo=1 ORDER BY nombre");
$all_instrumentos = $conn->query("SELECT DISTINCT instrumento FROM horarios WHERE instrumento IS NOT NULL AND instrumento != '' ORDER BY instrumento");
?>

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

.header-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 20px;
}

.info-guide { background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); padding: 15px; border-radius: 12px; margin-bottom: 25px; }
.info-guide summary { cursor: pointer; outline: none; list-style: none; display: flex; align-items: center; gap: 10px; font-weight: 800; color: #60a5fa; font-size: 13px; }
.info-guide p { margin: 12px 0 0 0; font-size: 12.5px; color: #cbd5e1; line-height: 1.6; }

.filter-card { background: rgba(0, 0, 0, 0.19); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 20px; margin-bottom: 30px; }
.filter-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.filter-group { flex: 1 1 130px; min-width: 130px; }
.filter-group label { display: block; font-size: 10px; font-weight: 800; color: #6b7280; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
.filter-input { width: 100%; background: #1b2949; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 9px 12px; color: #fff; font-size: 13px; outline: none; transition: 0.2s; }
.filter-input:focus { border-color: #4f46e5; }

.btn-submit { background: #4f46e5; color:#fff; border:none; padding:10px 20px; border-radius:10px; font-weight:800; cursor:pointer; font-size:13px; height: 39px; flex: 1; }
.btn-clear { background: rgba(255,255,255,0.05); color:#9ca3af; padding:10px 15px; border-radius:10px; font-weight:700; text-decoration:none; font-size:13px; height: 39px; display: flex; align-items: center; justify-content: center; flex: 1; }

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.08);
    background: rgba(255,255,255,0.01);
}

.table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
.table th { background: rgba(0,0,0,0.2); padding: 15px; font-size: 10px; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; border-bottom: 1px solid rgba(255,255,255,0.05); white-space: nowrap; }
.table td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13.5px; color: #e2e8f0; vertical-align: middle; }

.text-instrumento { color: #a78bfa; font-weight: 800; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; }

.badge-tipo { padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; border: 1px solid transparent; }
.badge-fijo { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border-color: rgba(59, 130, 246, 0.2); }
.badge-extra { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border-color: rgba(245, 158, 11, 0.2); }

.status-pill { padding: 5px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.status-reservada { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.15); }
.status-libre { background: rgba(34, 197, 94, 0.1); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.15); }

.btn-action { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 800; text-decoration: none; text-transform: uppercase; transition: 0.2s; white-space: nowrap; }
.btn-edit { background: rgba(255, 255, 255, 0.05); color: #fff; border: 1px solid rgba(255, 255, 255, 0.1); }
.btn-delete { background: rgba(248, 113, 113, 0.05); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.1); }

.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; }
.page-link { padding: 8px 16px; background: rgba(255,255,255,0.05); color: #9ca3af; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 13px; border: 1px solid rgba(255,255,255,0.1); transition: 0.2s; }
.page-link:hover { background: rgba(255,255,255,0.1); color: #fff; }
.page-link.active { background: #4f46e5; color: #fff; border-color: #4f46e5; }

.toast-error { position: fixed; top: 20px; right: 20px; background: #ef4444; color: white; padding: 12px 20px; border-radius: 12px; font-size: 13px; font-weight: 700; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4); z-index: 9999; display: flex; align-items: center; gap: 10px; animation: slideIn 0.3s ease-out; }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

@media (max-width: 768px) {
    .header-container { flex-direction: column; align-items: flex-start; }
    .header-container a { width: 100%; justify-content: center; }
    .filter-group { flex: 1 1 100%; }
    .filter-actions { width: 100%; margin-left: 0 !important; }
}
</style>

<div class="header-container">
    <div>
        <h1 style="font-size: 24px; letter-spacing: -0.5px; font-weight: 800; margin: 0;">Gestión de Horarios</h1>
        <p style="color: #64748b; font-size: 14px; margin: 5px 0 0 0;">Administra los cupos y la disponibilidad de clases.</p>
    </div>
    <a href="horarios-crear.php" style="background:#4f46e5; color:#fff; padding:12px 22px; border-radius:12px; text-decoration:none; font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);">
        <i class="fas fa-plus"></i> Nuevo Horario
    </a>
</div>

<details class="info-guide">
    <summary>
        <i class="fas fa-question-circle"></i> 
        <span>Guía de Administración de Horarios</span>
        <i class="fas fa-chevron-down" style="font-size: 10px; margin-left: auto; opacity: 0.5;"></i>
    </summary>
    <p>
        • <strong>Tipos de Turno:</strong> Los turnos <strong>Fijos</strong> se reprograman automáticamente. Los <strong>Extras</strong> son de única vez.<br>
        • <strong>Edición:</strong> Editar un horario hace que si hay un reserva vinculada a este, cambien los datos pero manteniendo activa la reserva.<br>
        • <strong>Borrar:</strong> Elimina el horario de manera permanente, si hay un reserva vinculada al horario, primero debera cancelar la reserva en cuestion.<br>
        • <strong>Importante:</strong> Al editar un horario, se actualiza la reserva vinculada a este.
    </p>
</details>

<form method="GET" action="horarios.php" class="filter-card">
    <div class="filter-grid">
        <div class="filter-group" style="min-width: 60px; max-width: 80px;">
            <label>ID</label>
            <input type="number" name="f_id" class="filter-input" value="<?= htmlspecialchars($_GET['f_id'] ?? '') ?>">
        </div>
        <div class="filter-group">
            <label>Mes</label>
            <input type="month" name="f_mes" class="filter-input" value="<?= htmlspecialchars($_GET['f_mes'] ?? '') ?>">
        </div>
        <div class="filter-group">
            <label>Profesor</label>
            <select name="f_profesor" class="filter-input">
                <option value="">Todos</option>
                <?php while($p = $all_profesores->fetch_assoc()): ?>
                    <option value="<?= $p['id'] ?>" <?= (int)($_GET['f_profesor'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= $p['nombre'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Instrumento</label>
            <select name="f_instrumento" class="filter-input">
                <option value="">Todos</option>
                <?php while($ins = $all_instrumentos->fetch_assoc()): ?>
                    <option value="<?= $ins['instrumento'] ?>" <?= ($_GET['f_instrumento'] ?? '') === $ins['instrumento'] ? 'selected' : '' ?>><?= $ins['instrumento'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Modalidad</label>
            <select name="f_modalidad" class="filter-input">
                <option value="">Todas</option>
                <option value="Presencial" <?= ($_GET['f_modalidad'] ?? '') === 'Presencial' ? 'selected' : '' ?>>Presencial</option>
                <option value="Virtual" <?= ($_GET['f_modalidad'] ?? '') === 'Virtual' ? 'selected' : '' ?>>Virtual</option>
                <option value="A domicilio" <?= ($_GET['f_modalidad'] ?? '') === 'A domicilio' ? 'selected' : '' ?>>A domicilio</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Estado</label>
            <select name="f_estado" class="filter-input">
                <option value="">Todos</option>
                <option value="libre" <?= ($_GET['f_estado'] ?? '') === 'libre' ? 'selected' : '' ?>>Libre</option>
                <option value="reservada" <?= ($_GET['f_estado'] ?? '') === 'reservada' ? 'selected' : '' ?>>Reservada</option>
            </select>
        </div>
        <div class="filter-actions" style="display: flex; gap: 8px; margin-left: auto;">
            <button type="submit" class="btn-submit">Buscar</button>
            <a href="horarios.php" class="btn-clear">Ver todo</a>
        </div>
    </div>
</form>

<div id="main-container">
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Fecha y Día</th>
                    <th>Hora</th>
                    <th>Duración</th>
                    <th>Instrumento</th>
                    <th>Profesor</th>
                    <th>Modalidad</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($res->num_rows === 0): ?>
                    <tr><td colspan="10" style="text-align: center; padding: 60px; color: #64748b;">No se encontraron horarios con los filtros aplicados.</td></tr>
                <?php endif; ?>
                <?php while($h = $res->fetch_assoc()): ?>
                <tr>
                    <td style="font-family: monospace; opacity: 0.3; font-size: 11px;">#<?= $h['id'] ?></td>
                    <td>
                        <div style="font-weight: 700;"><?= date('d/m/Y', strtotime($h['fecha_especifica'])) ?></div>
                        <div style="font-weight: 700; color: #64748b;"><?= $h['dia_semana'] ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 800; color: #60a5fa; font-size: 14px;"><?= substr($h['hora'], 0, 5) ?> <span style="font-size: 10px; opacity: 0.7;">HS</span></div>
                    </td>
                    <td style="font-size: 12px; color: #94a3b8; font-weight: 600;">
                        <i class="far fa-clock" style="opacity: 0.5; margin-right: 4px;"></i><?= $h['duracion_minutos'] ?> min
                    </td>
                    <td><span class="text-instrumento"><?= htmlspecialchars((string)$h['instrumento']) ?></span></td>
                    <td style="font-weight: 600;"><?= htmlspecialchars((string)$h['profesor']) ?></td>
                    
                    <td style="font-size: 12px; font-weight: 600;">
                        <i class="fas <?= $h['modalidad'] === 'Virtual' ? 'fa-laptop' : ($h['modalidad'] === 'Presencial' ? 'fa-building' : 'fa-home') ?>" style="opacity: 0.5; margin-right: 5px;"></i>
                        <?= $h['modalidad'] ?>
                    </td>

                    <td><span class="badge-tipo <?= $h['tipo_turno'] === 'fijo' ? 'badge-fijo' : 'badge-extra' ?>"><?= $h['tipo_turno'] ?></span></td>
                    <td>
                        <?php if ($h['reservas_actuales'] > 0): ?>
                            <span class="status-pill status-reservada"><i class="fas fa-circle" style="font-size: 7px;"></i> Reservada</span>
                        <?php else: ?>
                            <span class="status-pill status-libre"><i class="fas fa-circle" style="font-size: 7px;"></i> Libre</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <a href="horarios-editar.php?id=<?= $h['id'] ?>" class="btn-action btn-edit">Editar</a>
                            <a href="horarios-eliminar.php?id=<?= $h['id'] ?>&confirm=yes" class="btn-action btn-delete" onclick="return confirm('¿Eliminar este horario?')">Borrar</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <?php 
        $query_data = $_GET; 
        for ($i = 1; $i <= $total_paginas; $i++): 
            $query_data['p'] = $i;
            $url_pag = 'horarios.php?' . http_build_query($query_data);
        ?>
            <a href="<?= $url_pag ?>" class="page-link <?= ($i === $pagina_actual) ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const errorMsg = urlParams.get('error');

    if (errorMsg) {
        const toast = document.createElement('div');
        toast.className = 'toast-error';
        toast.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${decodeURIComponent(errorMsg.replace(/\+/g, ' '))}`;
        document.body.appendChild(toast);
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
        setTimeout(() => {
            toast.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            setTimeout(() => toast.remove(), 500);
        }, 4000);
    }

    function cargarHorarios(url, push = true) {
        document.getElementById('main-container').style.opacity = '0.5';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.getElementById('main-container').innerHTML;
                document.getElementById('main-container').innerHTML = newContent;
                document.getElementById('main-container').style.opacity = '1';

                if (push) history.pushState(null, '', url);
            })
            .catch(err => {
                console.error("Error AJAX:", err);
                document.getElementById('main-container').style.opacity = '1';
            });
    }

    document.addEventListener('click', function(e) {
        const link = e.target.closest('.page-link');
        if (link) {
            e.preventDefault();
            cargarHorarios(link.href);
        }
    });

    const filterForm = document.querySelector('form');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const url = this.action + '?' + new URLSearchParams(new FormData(this)).toString();
            cargarHorarios(url);
        });
    }

    const btnClear = document.querySelector('.btn-clear');
    if (btnClear) {
        btnClear.addEventListener('click', function(e) {
            e.preventDefault();
            cargarHorarios(this.href);
        });
    }

    window.addEventListener('popstate', () => {
        cargarHorarios(location.href, false);
    });
});
</script>