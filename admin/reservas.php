<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// --- LÓGICA DE ACTUALIZACIÓN AUTOMÁTICA DE RESERVAS ---
$ahora_check = date('Y-m-d H:i:s');
$check_query = "
    SELECT r.id AS reserva_id, r.fecha, r.usuario_id, r.horario_id,
           h.hora, h.duracion_minutos, h.tipo_turno, h.instrumento, h.modalidad,
           u.nombre, u.apellido, p.nombre as prof_nombre, p.id as prof_id
    FROM reservas r
    JOIN horarios h ON r.horario_id = h.id
    JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN profesores p ON h.profesor_id = p.id
    WHERE r.estado IN ('pendiente', 'confirmada')";

$res_check = $conn->query($check_query);

if ($res_check) {
    while ($row = $res_check->fetch_assoc()) {
        $fecha_inicio = $row['fecha'] . ' ' . $row['hora'];
        $fecha_fin = date('Y-m-d H:i:s', strtotime($fecha_inicio . " + " . $row['duracion_minutos'] . " minutes"));

        if ($ahora_check > $fecha_fin) {
            $conn->begin_transaction();
            try {
                $nombre_alu = trim($row['apellido'] . ' ' . $row['nombre']);
                
                $stH = $conn->prepare("INSERT INTO historial_reservas (reserva_id, usuario_id, profesor_id, nombre_alumno, nombre_profesor, instrumento, fecha_clase, hora, tipo_turno, estado, modalidad) VALUES (?,?,?,?,?,?,?,?,?, 'completada', ?)");
                $stH->bind_param('iiisssssss',
                    $row['reserva_id'], $row['usuario_id'], $row['prof_id'],
                    $nombre_alu, $row['prof_nombre'], $row['instrumento'],
                    $row['fecha'], $row['hora'], $row['tipo_turno'], $row['modalidad']
                );
                $stH->execute();

                // --- ACTUALIZACIÓN DE CONTADORES ---
                // Sumar al alumno
                $conn->query("UPDATE usuarios SET clases_asistidas = clases_asistidas + 1 WHERE id = {$row['usuario_id']}");
                
                // Sumar al profesor (si existe)
                if (!empty($row['prof_id'])) {
                    $conn->query("UPDATE profesores SET clases_dictadas = clases_dictadas + 1 WHERE id = {$row['prof_id']}");
                }

                if ($row['tipo_turno'] === 'fijo') {
                    $nueva_fecha = date('Y-m-d', strtotime($row['fecha'] . " + 7 days"));
                    $conn->query("UPDATE reservas SET fecha = '$nueva_fecha', estado = 'pendiente' WHERE id = {$row['reserva_id']}");
                    $conn->query("UPDATE horarios SET fecha_especifica = '$nueva_fecha' WHERE id = {$row['horario_id']}");
                } else {
                    $conn->query("DELETE FROM reservas WHERE id = {$row['reserva_id']}");
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
            }
        }
    }
}

// --- CARGA DE DATOS PARA SELECTORES ---
$instrumentos_db = $conn->query("SELECT nombre FROM instrumentos ORDER BY nombre ASC");
$profesores_db = $conn->query("SELECT id, nombre FROM profesores ORDER BY nombre ASC");
$modalidades_lista = ['Presencial', 'A domicilio', 'Virtual'];

// --- LÓGICA DE PAGINACIÓN ---
$limit = 10;
$page_res = isset($_GET['p_res']) ? (int)$_GET['p_res'] : 1;
$offset_res = ($page_res - 1) * $limit;
$page_his = isset($_GET['p_his']) ? (int)$_GET['p_his'] : 1;
$offset_his = ($page_his - 1) * $limit;

$dias_map = [
    'Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 
    'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'
];
$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

// --- FILTROS TABLA RESERVAS ---
$f_res = [
    'id'       => $_GET['res_id'] ?? '',
    'alumno'   => $_GET['res_alunmo'] ?? '',
    'mes'      => $_GET['res_mes'] ?? '',
    'fecha'    => $_GET['res_fecha'] ?? '',
    'dia'      => $_GET['res_dia'] ?? '',
    'instr'    => $_GET['res_instr'] ?? '',
    'prof'     => $_GET['res_prof'] ?? '',
    'mod'      => $_GET['res_mod'] ?? '',
];

$where_res = ["1=1"];
if($f_res['id'])     $where_res[] = "r.id = '".$conn->real_escape_string($f_res['id'])."'";
if($f_res['alumno']) {
    $s = $conn->real_escape_string($f_res['alumno']);
    $where_res[] = "(u.nombre LIKE '%$s%' OR u.apellido LIKE '%$s%')";
}
if($f_res['mes'])   $where_res[] = "r.fecha LIKE '".$conn->real_escape_string($f_res['mes'])."%'";
if($f_res['fecha']) $where_res[] = "r.fecha = '".$conn->real_escape_string($f_res['fecha'])."'";
if($f_res['dia'])   $where_res[] = "h.dia_semana = '".$conn->real_escape_string($f_res['dia'])."'";
if($f_res['instr']) $where_res[] = "h.instrumento = '".$conn->real_escape_string($f_res['instr'])."'";
if($f_res['prof'])  $where_res[] = "h.profesor_id = '".$conn->real_escape_string($f_res['prof'])."'";
if($f_res['mod'])   $where_res[] = "h.modalidad = '".$conn->real_escape_string($f_res['mod'])."'";

$sql_res = "SELECT r.*, u.nombre AS alumno_nombre, u.apellido AS alumno_apellido, 
                    h.dia_semana, h.hora, h.instrumento, h.tipo_turno, h.duracion_minutos, h.modalidad, p.nombre AS profesor_nombre
            FROM reservas r
            JOIN usuarios u ON u.id = r.usuario_id
            JOIN horarios h ON h.id = r.horario_id
            LEFT JOIN profesores p ON p.id = h.profesor_id
            WHERE ".implode(" AND ", $where_res)."
            ORDER BY r.fecha ASC LIMIT $limit OFFSET $offset_res";
$res = $conn->query($sql_res);

// --- FILTROS TABLA HISTORIAL ---
$f_his = [
    'id_his'  => $_GET['his_id'] ?? '',
    'id_res'  => $_GET['his_res_id'] ?? '',
    'alumno'  => $_GET['his_alumno'] ?? '',
    'mes'     => $_GET['his_mes'] ?? '',
    'fecha'   => $_GET['his_fecha'] ?? '',
    'dia'     => $_GET['his_dia'] ?? '',
    'prof'    => $_GET['his_prof'] ?? '',
    'mod'     => $_GET['his_mod'] ?? '',
];

$where_his = ["1=1"];
if($f_his['id_his']) $where_his[] = "id = '".$conn->real_escape_string($f_his['id_his'])."'";
if($f_his['id_res']) $where_his[] = "reserva_id = '".$conn->real_escape_string($f_his['id_res'])."'";
if($f_his['alumno']) $where_his[] = "nombre_alumno LIKE '%".$conn->real_escape_string($f_his['alumno'])."%'";
if($f_his['mes'])    $where_his[] = "fecha_clase LIKE '".$conn->real_escape_string($f_his['mes'])."%'";
if($f_his['fecha'])  $where_his[] = "fecha_clase = '".$conn->real_escape_string($f_his['fecha'])."'";
if($f_his['prof'])   $where_his[] = "nombre_profesor = '".$conn->real_escape_string($f_his['prof'])."'";
if($f_his['mod'])    $where_his[] = "modalidad = '".$conn->real_escape_string($f_his['mod'])."'";
if($f_his['dia']){
    $dia_en = array_search($f_his['dia'], $dias_map);
    $where_his[] = "DAYNAME(fecha_clase) = '$dia_en'";
}

$sql_his = "SELECT *, DAYNAME(fecha_clase) as dia_nombre_en FROM historial_reservas 
            WHERE ".implode(" AND ", $where_his)." 
            ORDER BY creado_en DESC LIMIT $limit OFFSET $offset_his";
$res_historial = $conn->query($sql_his);

function getPagingUrl(string $key, int $page): string {
    $params = $_GET;
    $params[$key] = $page;
    return "?" . http_build_query($params);
}
?>

<style>
    .info-guide { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-start; }
    .info-guide i { color: #60a5fa; font-size: 1.2rem; margin-top: 2px; }
    .info-guide p { margin: 0; font-size: 12.5px; color: #cbd5e1; line-height: 1.5; }

    .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .btn-new { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 800; font-size: 13px; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); transition: all 0.2s; }
    .btn-new:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(16, 185, 129, 0.3); }

    .filter-wrapper { background: rgba(0, 0, 0, 0.3); padding: 15px; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.05); margin-bottom: 25px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; align-items: end; }
    .filter-grid label { display: block; font-size: 8.5px; color: #6b7280; margin-bottom: 4px; font-weight: 800; text-transform: uppercase; }
    .filter-grid input, .filter-grid select { background: #1d2942 !important; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; padding: 8px; font-size: 11px; width: 100%; outline: none; }
    
    .btn-search { background: #6366f1; color: #fff; border: none; padding: 10px; border-radius: 8px; font-weight: 800; cursor: pointer; font-size: 11px; }
    .btn-all { background: rgba(255,255,255,0.05); color: #9ca3af; border: 1px solid rgba(255,255,255,0.1); padding: 10px; border-radius: 8px; text-decoration: none; text-align: center; font-size: 11px; font-weight: 800; }

    .table-wrapper { background: rgba(255,255,255,.02); border-radius:14px; border:1px solid rgba(255,255,255,.08); overflow-x: auto; margin-bottom: 10px; }
    .table { width:100%; border-collapse:collapse; min-width: 1000px; }
    .table th { background: rgba(0,0,0,.2); color:#6b7280; font-size: 10px; text-transform: uppercase; padding: 12px; text-align: left; }
    .table td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 13px; }

    .badge { display: inline-flex; padding: 4px 8px; border-radius: 6px; font-weight: 900; font-size: 10px; text-transform: uppercase; }
    .badge-confirmada, .badge-completada { background: rgba(34,197,94,.14); color: #86efac; }
    .badge-cancelada { background: rgba(239,68,68,.14); color: #fca5a5; }
    .badge-trasladada { background: rgba(245,158,11,0.15); color: #fcd34d; }
    .badge-pendiente { background: rgba(59,130,246,0.1); color: #60a5fa; }
    .badge-fijo { background: rgba(99, 102, 241, 0.1); color: #a5b4fc; }
    .badge-extra { background: rgba(236, 72, 153, 0.1); color: #f9a8d4; }
    .badge-timer { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); font-family: monospace; }
    
    .badge-presencial { background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .badge-domicilio { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
    .badge-virtual { background: rgba(99, 102, 241, 0.1); color: #818cf8; }

    .instr-text { color: #a78bfa; font-weight: 700; }
    
    .desc-cell { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #9ca3af; font-size: 11px; font-style: italic; cursor: help; }

    .actions { display: flex; flex-direction: column; gap: 4px; }
    .btn-action { padding: 6px 10px; display: flex; align-items: center; gap: 8px; border-radius: 6px; text-decoration: none; font-size: 10px; font-weight: 800; text-transform: uppercase; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.05); width: fit-content; min-width: 100px; }
    .btn-edit { background: rgba(99, 102, 241, 0.1); color: #818cf8; }
    .btn-edit:hover { background: #6366f1; color: #fff; }
    .btn-transfer { background: rgba(245, 158, 11, 0.1); color: #fbbf24; }
    .btn-transfer:hover { background: #f59e0b; color: #fff; }
    .btn-delete { background: rgba(239, 68, 68, 0.1); color: #f87171; }
    .btn-delete:hover { background: #ef4444; color: #fff; }

    .pagination { display: flex; gap: 5px; margin-top: 20px; justify-content: center; }
    .page-link { padding: 8px 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; text-decoration: none; border-radius: 8px; font-size: 11px; }
    .page-link.active { background: #6366f1; border-color: #6366f1; font-weight: bold; }

    details summary::-webkit-details-marker {
        display: none;
    }
    details summary:hover {
        color: #fff !important;
    }
</style>

<div class="header-section">
    <h1>Gestión de Reservas</h1>
    <a href="reservas-crear.php" class="btn-new">
        <i class="fas fa-plus-circle"></i> Nueva Reserva
    </a>
</div>

<details class="info-guide" style="cursor: pointer; outline: none;">
    <summary style="list-style: none; display: flex; align-items: center; gap: 10px; font-weight: 800; color: #60a5fa; font-size: 13px;">
        <i class="fas fa-question-circle"></i> 
        <span>¿Necesitas ayuda? Abrir Guía de Gestión</span>
        <i class="fas fa-chevron-down" style="font-size: 10px; margin-left: auto; opacity: 0.5;"></i>
    </summary>
    
    <div style="margin-top: 15px; cursor: default;">
        <p style="margin-bottom: 10px;">
            <strong>Guía de Gestión:</strong> Aquí administras las clases activas. 
            Las reservas se mueven al historial automáticamente al finalizar, trasladar o cancelar.
        </p>
        
        <div style="margin-bottom: 10px;">
            <strong>Tipos de Turnos:</strong><br>
            • <span class="badge badge-fijo" style="background: rgba(99, 102, 241, 0.2);">FIJO:</span> Clases que se repiten <strong>semanalmente</strong>. Al finalizar, el sistema genera la reserva para la semana siguiente y la anterior queda guardada en el historial.<br>
            • <span class="badge badge-extra" style="background: rgba(236, 72, 153, 0.2);">EXTRA:</span> Clases de <strong>única vez</strong>. Una vez finalizadas, se guardan en el historial como completadas.
        </div>

        <div>
            <strong>Funciones de botones:</strong><br>
            • <span style="color: #10b981;"><strong>Nueva Reserva:</strong></span> Registra una clase manual para un alumno.<br>
            • <span style="color: #818cf8;"><strong>Editar:</strong></span> Modifica detalles, estado, descripciones o cambia el alumno de la reserva.<br>
            • <span style="color: #fbbf24;"><strong>Trasladar:</strong></span> Exclusivo para turnos <i>Fijos</i>. Salta la clase de esta semana y la reprograma para la siguiente.<br>
            • <span style="color: #f87171;"><strong>Cancelar:</strong></span> Elimina solo la reserva actual. (Para borrar el horario base, ve a la página de <a href="horarios.php" style="color: #718af8; text-decoration: underline; font-weight: 800;">Horarios</a>).
        </div>
    </div>
</details>


<div class="filter-wrapper">
    <form method="GET" class="filter-grid">
        <input type="hidden" name="p_his" value="<?=$page_his?>">
        <input type="hidden" name="tipo" value="res">

        <div><label>ID Reserva</label><input type="number" name="res_id" value="<?=$f_res['id']?>"></div>
        <div><label>Alumno</label><input type="text" name="res_alunmo" value="<?=$f_res['alumno']?>"></div>

        <div><label>Profesor</label>
            <select name="res_prof">
                <option value="">Cualquiera</option>
                <?php while($p = $profesores_db->fetch_assoc()): ?>
                    <option value="<?=$p['id']?>" <?=$f_res['prof']==$p['id']?'selected':''?>><?=$p['nombre']?></option>
                <?php endwhile; $profesores_db->data_seek(0); ?>
            </select>
        </div>

        <div><label>Modalidad</label>
            <select name="res_mod">
                <option value="">Todas</option>
                <?php foreach($modalidades_lista as $m): ?>
                    <option value="<?=$m?>" <?=$f_res['mod']==$m?'selected':''?>><?=$m?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>Día Semana</label>
            <select name="res_dia">
                <option value="">Cualquiera</option>
                <?php foreach($dias_semana as $d): ?>
                    <option value="<?=$d?>" <?=$f_res['dia']==$d?'selected':''?>><?=$d?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>Mes</label><input type="month" name="res_mes" value="<?=$f_res['mes']?>"></div>
        <div><label>Fecha Exacta</label><input type="date" name="res_fecha" value="<?=$f_res['fecha']?>"></div>
        
        <div><label>Instrumento</label>
            <select name="res_instr">
                <option value="">Todos</option>
                <?php while($ins = $instrumentos_db->fetch_assoc()): ?>
                    <option value="<?=$ins['nombre']?>" <?=$f_res['instr']==$ins['nombre']?'selected':''?>><?=$ins['nombre']?></option>
                <?php endwhile; $instrumentos_db->data_seek(0); ?>
            </select>
        </div>

        <button type="submit" class="btn-search">Buscar</button>
        <a href="?tipo=res" class="btn-all">Ver todo</a>
    </form>
</div>

<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Alumno</th>
                <th>Tipo</th>
                <th>Modalidad</th>
                <th>Día / Fecha</th>
                <th>Hora / Cronómetro</th>
                <th>Duración</th>
                <th>Instrumento / Prof.</th>
                <th>Descripción</th>
                <th>Estado</th>
                <th style="width:140px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while($r = $res->fetch_assoc()): 
                $t_inicio = strtotime($r['fecha'] . ' ' . $r['hora']);
                $t_fin = $t_inicio + ($r['duracion_minutos'] * 60);
                $mod_class = 'badge-' . strtolower(str_replace(' ', '', $r['modalidad']));
            ?>
            <tr data-inicio="<?= $t_inicio ?>" data-fin="<?= $t_fin ?>">
                <td style="color:#6b7280">#<?=$r['id']?></td>
                <td><strong><?=htmlspecialchars($r['alumno_apellido'].' '.$r['alumno_nombre'])?></strong></td>
                <td><span class="badge badge-<?=$r['tipo_turno']?>"><?=$r['tipo_turno']?></span></td>
                <td><span class="badge <?=$mod_class?>"><?=$r['modalidad']?></span></td>
                <td><?=$r['dia_semana']?><br><small><?=date('d/m/Y', strtotime($r['fecha']))?></small></td>
                <td>
                    <strong><?=substr($r['hora'],0,5)?></strong><br>
                    <span class="badge badge-timer countdown">Cargando...</span>
                </td>
                <td style="font-size: 12px; color: #9ca3af; font-weight: 600;">
                    <i class="far fa-clock" style="opacity: 0.5; margin-right: 4px;"></i><?= $r['duracion_minutos'] ?> min
                </td>
                <td><span class="instr-text"><?=$r['instrumento']?></span><br><small style="color:#9ca3af"><?=$r['profesor_nombre']?></small></td>
                
                <td class="desc-cell" title="<?= htmlspecialchars($r['observaciones'] ?? '') ?>">
                    <?= !empty($r['observaciones']) ? htmlspecialchars($r['observaciones']) : '<span style="opacity:0.3">-</span>' ?>
                </td>

                <td><span class="badge badge-<?=$r['estado']?>"><?=$r['estado']?></span></td>
                <td>
                    <div class="actions">
                        <a href="reservas-editar.php?id=<?=$r['id']?>" class="btn-action btn-edit" title="Editar">
                            <i class="fas fa-edit"></i> <span>Editar</span>
                        </a>
                        <?php if($r['tipo_turno'] === 'fijo'): ?>
                            <a href="reservas-trasladar.php?id=<?=$r['id']?>" class="btn-action btn-transfer" title="Trasladar clase" onclick="return confirm('¿Trasladar clase a la semana que viene?')">
                                <i class="fas fa-calendar-plus"></i> <span>Trasladar</span>
                            </a>
                        <?php endif; ?>
                        <a href="reservas-eliminar.php?id=<?=$r['id']?>" class="btn-action btn-delete" title="Borrar / Cancelar" onclick="return confirm('¿Eliminar reserva?')">
                            <i class="fas fa-trash-alt"></i> <span>Cancelar</span>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="pagination">
    <a href="<?=getPagingUrl('p_res', max(1, $page_res-1))?>" class="page-link">«</a>
    <a href="<?=getPagingUrl('p_res', $page_res)?>" class="page-link active"><?=$page_res?></a>
    <a href="<?=getPagingUrl('p_res', $page_res+1)?>" class="page-link">»</a>
</div>

<hr style="border:0; border-top:1px solid rgba(255,255,255,0.05); margin: 40px 0;">

<h2>Historial de Movimientos</h2>

<div class="filter-wrapper">
    <form method="GET" class="filter-grid">
        <input type="hidden" name="p_res" value="<?=$page_res?>">
        <input type="hidden" name="tipo" value="his">

        <div><label>ID Historial</label><input type="number" name="his_id" value="<?=$f_his['id_his']?>"></div>
        <div><label>ID Reserva</label><input type="number" name="his_res_id" value="<?=$f_his['id_res']?>"></div>
        <div><label>Alumno</label><input type="text" name="his_alumno" value="<?=$f_his['alumno']?>"></div>
        
        <div><label>Modalidad</label>
            <select name="his_mod">
                <option value="">Todas</option>
                <?php foreach($modalidades_lista as $m): ?>
                    <option value="<?=$m?>" <?=$f_his['mod']==$m?'selected':''?>><?=$m?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div><label>Profesor</label>
            <select name="his_prof">
                <option value="">Cualquiera</option>
                <?php 
                $profesores_db->data_seek(0);
                while($p = $profesores_db->fetch_assoc()): ?>
                    <option value="<?=$p['nombre']?>" <?=$f_his['prof']==$p['nombre']?'selected':''?>><?=$p['nombre']?></option>
                <?php endwhile; $profesores_db->data_seek(0); ?>
            </select>
        </div>

        <div><label>Mes</label><input type="month" name="his_mes" value="<?=$f_his['mes']?>"></div>
        <div><label>Fecha Exacta</label><input type="date" name="his_fecha" value="<?=$f_his['fecha']?>"></div>

        <button type="submit" class="btn-search">Buscar</button>
        <a href="?tipo=his" class="btn-all">Ver todo</a>
    </form>
</div>

<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th>ID Hist..</th>
                <th>ID Res.</th>
                <th>Día / Fecha</th>
                <th>Alumno</th>
                <th>Modalidad</th>
                <th>Instrumento / Prof.</th>
                <th>Estado Final</th>
            </tr>
        </thead>
        <tbody>
            <?php while($h = $res_historial->fetch_assoc()): 
                $dia_esp = $dias_map[$h['dia_nombre_en']] ?? $h['dia_nombre_en'];
                $mod_class_h = 'badge-' . strtolower(str_replace(' ', '', $h['modalidad']));
            ?>
            <tr>
                <td style="color:#6b7280">#<?=$h['id']?></td>
                <td style="color:#6366f1">#<?=$h['reserva_id']?></td>
                <td><?=$dia_esp?><br><small><?=date('d/m/Y', strtotime($h['fecha_clase']))?></small></td>
                <td><strong><?=htmlspecialchars($h['nombre_alumno'])?></strong></td>
                <td><span class="badge <?=$mod_class_h?>"><?=$h['modalidad']?></span></td>
                <td><span class="instr-text"><?=$h['instrumento']?></span><br><small><?=$h['nombre_profesor']?></small></td>
                <td><span class="badge badge-<?=$h['estado']?>"><?=$h['estado']?></span></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="pagination">
    <a href="<?=getPagingUrl('p_his', max(1, $page_his-1))?>" class="page-link">«</a>
    <a href="<?=getPagingUrl('p_his', $page_his)?>" class="page-link active"><?=$page_his?></a>
    <a href="<?=getPagingUrl('p_his', $page_his+1)?>" class="page-link">»</a>
</div>

<script>
function updateTimers() {
    const ahora = Math.floor(Date.now() / 1000);
    const rows = document.querySelectorAll('tbody tr[data-inicio]');

    rows.forEach(row => {
        const inicio = parseInt(row.dataset.inicio);
        const fin = parseInt(row.dataset.fin);
        const timerElement = row.querySelector('.countdown');
        if(!timerElement) return;

        if (ahora < inicio) {
            let diff = inicio - ahora;
            let dias = Math.floor(diff / 86400);
            let horas = Math.floor((diff % 86400) / 3600);
            let mins = Math.floor((diff % 3600) / 60);
            timerElement.innerHTML = dias > 0 ? `Faltan ${dias}d ${horas}h` : (horas > 0 ? `Faltan ${horas}h ${mins}m` : `Faltan ${mins}m`);
            timerElement.style.color = "#60a5fa";
        } else if (ahora >= inicio && ahora <= fin) {
            let diff = fin - ahora;
            let segs = (diff % 60).toString().padStart(2, '0');
            timerElement.innerHTML = `EN CLASE: ${Math.floor(diff/60)}:${segs}`;
            timerElement.style.color = "#86efac";
            timerElement.style.borderColor = "#22c55e";
        } else {
            timerElement.innerHTML = "Finalizada";
            timerElement.style.color = "#9ca3af";
            timerElement.style.borderColor = "rgba(255,255,255,0.1)";
        }
    });
}
setInterval(updateTimers, 1000);
updateTimers();

function cargarReservas(url, push = true) {
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            document.querySelectorAll('.table-wrapper').forEach((wrapper, index) => {
                const newContent = doc.querySelectorAll('.table-wrapper')[index];
                if(newContent) wrapper.innerHTML = newContent.innerHTML;
            });

            document.querySelectorAll('.pagination').forEach((pag, index) => {
                const newPag = doc.querySelectorAll('.pagination')[index];
                if(newPag) pag.innerHTML = newPag.innerHTML;
            });

            if (push) history.pushState(null, '', url);
            updateTimers();
        });
}

document.addEventListener('click', function(e) {
    const link = e.target.closest('.page-link');
    if (link) {
        e.preventDefault();
        cargarReservas(link.href);
    }
});

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const params = new URLSearchParams(window.location.search);
        const formData = new FormData(this);
        const tipo = formData.get('tipo');

        if (tipo === 'res') {
            ['res_id','res_alunmo','res_mes','res_fecha','res_dia','res_instr','res_prof','res_mod','p_res']
                .forEach(k => params.delete(k));
        } else {
            ['his_id','his_res_id','his_alumno','his_mes','his_fecha','his_dia','his_prof','his_mod','p_his']
                .forEach(k => params.delete(k));
        }

        formData.forEach((value, key) => {
            if (value !== '') params.set(key, value);
        });

        const url = this.action + '?' + params.toString();
        cargarReservas(url);
    });
});

document.addEventListener('click', function(e) {
    const link = e.target.closest('.btn-all');
    if (link) {
        e.preventDefault();
        const url = new URL(window.location.href);
        const tipo = new URL(link.href, window.location.origin).searchParams.get('tipo');

        if (tipo === 'res') {
            ['res_id','res_alunmo','res_mes','res_fecha','res_dia','res_instr','res_prof', 'res_mod', 'p_res']
                .forEach(k => url.searchParams.delete(k));
        } else {
            ['his_id','his_res_id','his_alumno','his_mes','his_fecha','his_dia','his_prof', 'his_mod', 'p_his']
                .forEach(k => url.searchParams.delete(k));
        }
        cargarReservas(url.toString());
    }
});

window.addEventListener('popstate', () => {
    cargarReservas(location.href, false);
});
</script>