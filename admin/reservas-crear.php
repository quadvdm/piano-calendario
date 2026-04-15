<?php
// admin/reservas-crear.php — CREAR RESERVA (Admin)
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();

$db = Database::getInstance();
$conn = $db->getConnection();

$msg = ''; $err = '';

// --- 1. Obtener Configuración Dinámica ---
$resConfig = $db->fetchAll("SELECT clave, valor FROM configuraciones WHERE clave IN ('max_reservas_semana', 'horario_apertura', 'horario_cierre', 'dias_anticipacion_reserva')");
$config = [];
foreach ($resConfig as $c) { $config[$c['clave']] = $c['valor']; }

$max_semanal = (int)($config['max_reservas_semana'] ?? 2);
$h_apertura  = $config['horario_apertura'] ?? '08:00';
$h_cierre    = $config['horario_cierre']   ?? '20:00';
$dias_ant    = (int)($config['dias_anticipacion_reserva'] ?? 0);
$fecha_min   = date('Y-m-d', strtotime("+$dias_ant days"));

// 2. Obtener Alumnos activos
$usuarios = [];
$res_u = $conn->query("SELECT id, nombre, apellido, email, rol FROM usuarios WHERE activo=1 ORDER BY apellido, nombre");
while($row = $res_u->fetch_assoc()) $usuarios[] = $row;

// 3. Obtener Profesores
$profesores_data = [];
$res_p = $conn->query("SELECT id, nombre, especialidad FROM profesores WHERE activo=1 ORDER BY nombre");
while($row = $res_p->fetch_assoc()) {
    $especialidades = array_map('trim', explode(',', (string)$row['especialidad']));
    $profesores_data[] = ['id' => (int)$row['id'], 'nombre' => $row['nombre'], 'instrumentos' => $especialidades];
}

// 4. Obtener Horarios disponibles existentes
$horarios_existentes = [];
$sql_h = "SELECT h.*, p.nombre AS profesor FROM horarios h JOIN profesores p ON p.id = h.profesor_id
          WHERE h.activo = 1 AND h.reservas_actuales < h.capacidad 
          ORDER BY h.fecha_especifica, h.hora";
$res_h = $conn->query($sql_h);
while($row = $res_h->fetch_assoc()) $horarios_existentes[] = $row;

// 5. PROCESAR EL GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_crear'])) {
    $usuario_id = (int)($_POST['usuario_id'] ?? 0);
    $modo_horario = $_POST['modo_horario'] ?? 'existente';
    $nuevo_estado = (string)($_POST['estado'] ?? 'confirmada');
    $observaciones = trim((string)($_POST['descripcion'] ?? ''));
    
    $conn->begin_transaction();
    try {
        $horario_id = 0;
        $fecha_clase = '';
        $hora_inicio = '';
        $duracion = 60; 
        $tipo_turno = '';
        $prof_id = 0;
        $modalidad = '';
        $instr = '';
        $dia_nombre = '';

        // --- 1. RECOLECCIÓN DE DATOS (Existente vs Nuevo) ---
        if ($modo_horario === 'existente') {
            $horario_id = (int)($_POST['horario_id'] ?? 0);
            $stmt_h = $conn->prepare("SELECT * FROM horarios WHERE id = ? AND activo = 1 FOR UPDATE");
            $stmt_h->bind_param('i', $horario_id);
            $stmt_h->execute();
            $h_data = $stmt_h->get_result()->fetch_assoc();

            if (!$h_data || $h_data['reservas_actuales'] >= $h_data['capacidad']) throw new Exception('Horario no disponible o lleno.');
            
            $fecha_clase = $h_data['fecha_especifica'];
            $hora_inicio = $h_data['hora'];
            $duracion = (int)$h_data['duracion_minutos'];
            $tipo_turno = $h_data['tipo_turno'];
            $modalidad = ucfirst(strtolower(trim((string)$h_data['modalidad']))); 
            $prof_id = (int)$h_data['profesor_id'];
            $instr = $h_data['instrumento'];
            $dia_nombre = $h_data['dia_semana'];
            $hora_fin = date('H:i:s', strtotime($hora_inicio) + ($duracion * 60));
            
        } else {
            $fecha_clase = $_POST['n_fecha'] ?? '';
            $hora_inicio = trim((string)($_POST['n_hora'] ?? ''));
            $prof_id     = (int)($_POST['n_profesor_id'] ?? 0);
            $instr       = trim((string)($_POST['n_instrumento'] ?? ''));
            $tipo_turno  = $_POST['n_tipo_turno'] ?? 'extra';
            $modalidad   = ucfirst(strtolower(trim((string)($_POST['n_modalidad'] ?? 'Presencial')))); 
            $duracion    = (int)($_POST['n_duracion'] ?? 60);

            if (empty($fecha_clase) || empty($hora_inicio) || $prof_id === 0 || empty($instr)) {
                throw new Exception('Por favor completa todos los campos del nuevo horario.');
            }

            $timestamp_inicio = strtotime($fecha_clase . ' ' . $hora_inicio);
            $hora_fin = date('H:i:s', $timestamp_inicio + ($duracion * 60));
            $dias_esp = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
            $dia_nombre = $dias_esp[date('l', $timestamp_inicio)] ?? '';
        }

        // --- 2. CHEQUEO DEL ALUMNO  ---
        $sql_alumno_conf = "SELECT r.id, h.hora, h.duracion_minutos, h.tipo_turno, h.fecha_especifica, p.nombre as prof_nombre
                            FROM reservas r
                            JOIN horarios h ON r.horario_id = h.id
                            JOIN profesores p ON h.profesor_id = p.id
                            WHERE r.usuario_id = ? 
                            AND r.estado IN ('confirmada', 'pendiente')
                            AND h.activo = 1
                            AND h.dia_semana = ?
                            AND (
                                (? = 'extra' AND ((h.tipo_turno = 'extra' AND h.fecha_especifica = ?) OR (h.tipo_turno = 'fijo' AND h.fecha_especifica <= ?)))
                                OR (? = 'fijo' AND ((h.tipo_turno = 'fijo') OR (h.tipo_turno = 'extra' AND h.fecha_especifica >= ?)))
                            )
                            AND (? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) AND ? > h.hora)";

        $st_alu = $conn->prepare($sql_alumno_conf);
        $st_alu->bind_param('issssssss', 
            $usuario_id, $dia_nombre, $tipo_turno, $fecha_clase, $fecha_clase, $tipo_turno, $fecha_clase, $hora_inicio, $hora_fin
        );
        $st_alu->execute();
        $res_alu = $st_alu->get_result();

        if ($c_alu = $res_alu->fetch_assoc()) {
            $h_ini_c = substr($c_alu['hora'], 0, 5);
            $h_fin_c = date('H:i', strtotime($c_alu['hora'] . " + {$c_alu['duracion_minutos']} minutes"));
            $dia_txt = ($c_alu['tipo_turno'] === 'fijo') ? "los $dia_nombre" : "el día " . date('d/m/Y', strtotime($c_alu['fecha_especifica']));
            throw new Exception("BLOQUEO DE ALUMNO: El alumno ya tiene una clase agendada {$dia_txt} de {$h_ini_c} a {$h_fin_c} hs con {$c_alu['prof_nombre']}.");
        }

        // --- 2.5. CHEQUEO DE LÍMITE SEMANAL DEL ALUMNO ---
        if ($max_semanal > 0) {
            $sql_limite = "SELECT COUNT(*) as total 
                           FROM reservas r 
                           JOIN horarios h ON r.horario_id = h.id 
                           WHERE r.usuario_id = ? 
                           AND r.estado IN ('confirmada', 'pendiente') 
                           AND YEARWEEK(h.fecha_especifica, 1) = YEARWEEK(?, 1)";
            $st_lim = $conn->prepare($sql_limite);
            $st_lim->bind_param('is', $usuario_id, $fecha_clase);
            $st_lim->execute();
            $total_reservas = (int)$st_lim->get_result()->fetch_assoc()['total'];

            if ($total_reservas >= $max_semanal) {
                throw new Exception("LÍMITE ALCANZADO: El alumno ya tiene {$total_reservas} reservas en la semana del " . date('d/m/Y', strtotime($fecha_clase)) . " (Máximo permitido: {$max_semanal}).");
            }
        }

        // --- 3. CHEQUEO DEL PROFESOR Y SALÓN  ---
        if ($modo_horario === 'nuevo') {
            $sql_prof_conf = "SELECT h.hora, h.duracion_minutos, h.tipo_turno, h.fecha_especifica, h.modalidad, p.nombre as prof_nombre, h.profesor_id 
                              FROM horarios h JOIN profesores p ON h.profesor_id = p.id
                              WHERE h.activo = 1 AND h.dia_semana = ? 
                              AND (
                                  (? = 'extra' AND ((h.tipo_turno = 'extra' AND h.fecha_especifica = ?) OR (h.tipo_turno = 'fijo' AND h.fecha_especifica <= ?)))
                                  OR (? = 'fijo' AND ((h.tipo_turno = 'fijo') OR (h.tipo_turno = 'extra' AND h.fecha_especifica >= ?)))
                              )
                              AND (? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) AND ? > h.hora)";
                         
            $st_p = $conn->prepare($sql_prof_conf);
            $st_p->bind_param('ssssssss', $dia_nombre, $tipo_turno, $fecha_clase, $fecha_clase, $tipo_turno, $fecha_clase, $hora_inicio, $hora_fin);
            $st_p->execute();
            $res_p_conf = $st_p->get_result();

            while ($c = $res_p_conf->fetch_assoc()) {
                $h_ini_c = substr($c['hora'], 0, 5);
                $h_fin_c = date('H:i', strtotime($c['hora'] . " + {$c['duracion_minutos']} minutes"));
                
                if ((int)$c['profesor_id'] === $prof_id) {
                    throw new Exception("BLOQUEO DE PROFESOR: {$c['prof_nombre']} ya tiene clase de {$h_ini_c} a {$h_fin_c} hs.");
                }
                if (strtolower($modalidad) === 'presencial' && strtolower((string)$c['modalidad']) === 'presencial') {
                    throw new Exception("BLOQUEO DE SALÓN: El espacio Presencial está ocupado de {$h_ini_c} a {$h_fin_c} hs.");
                }
            }

            // Si llegamos acá, el horario nuevo es seguro. Lo creamos:
            $ins_h = $conn->prepare("INSERT INTO horarios (dia_semana, hora, duracion_minutos, profesor_id, instrumento, tipo_turno, fecha_especifica, capacidad, reservas_actuales, modalidad, activo) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?, 1)");
            $ins_h->bind_param('ssiissss', $dia_nombre, $hora_inicio, $duracion, $prof_id, $instr, $tipo_turno, $fecha_clase, $modalidad);
            $ins_h->execute();
            $horario_id = (int)$conn->insert_id;
        }

        // --- 4. GESTIÓN DE SUSCRIPCIÓN (Si es turno fijo) ---
        $suscripcion_id = null; 

        if ($tipo_turno === 'fijo') {
            // Verificar si el alumno ya tiene una suscripción activa para este horario
            $st_check_s = $conn->prepare("SELECT id FROM suscripciones WHERE usuario_id = ? AND horario_id = ? AND activo = 1");
            $st_check_s->bind_param('ii', $usuario_id, $horario_id);
            $st_check_s->execute();
            $res_s = $st_check_s->get_result();

            if ($res_s->num_rows > 0) {
                // Si ya existe, recuperamos el ID
                $suscripcion_id = (int)$res_s->fetch_assoc()['id'];
            } else {
                // Si no existe, la creamos y capturamos el ID generado
                $st_ins_s = $conn->prepare("INSERT INTO suscripciones (usuario_id, horario_id, fecha_inicio, activo) VALUES (?, ?, ?, 1)");
                $st_ins_s->bind_param('iis', $usuario_id, $horario_id, $fecha_clase);
                $st_ins_s->execute();
                $suscripcion_id = (int)$conn->insert_id;
            }
        }

        $ins_r = $conn->prepare("INSERT INTO reservas (usuario_id, horario_id, suscripcion_id, fecha, estado, observaciones) VALUES (?, ?, ?, ?, ?, ?)");
        $ins_r->bind_param('iiisss', $usuario_id, $horario_id, $suscripcion_id, $fecha_clase, $nuevo_estado, $observaciones);
        $ins_r->execute();

        // --- 4.5. INTEGRACIÓN CON SUSCRIPCIONES  ---
        if ($tipo_turno === 'fijo') {
            // Verificar si el alumno ya tiene una suscripción activa para este horario
            $st_check_s = $conn->prepare("SELECT id FROM suscripciones WHERE usuario_id = ? AND horario_id = ? AND activo = 1");
            $st_check_s->bind_param('ii', $usuario_id, $horario_id);
            $st_check_s->execute();
            if ($st_check_s->get_result()->num_rows === 0) {
                // Si no existe, la creamos
                $st_ins_s = $conn->prepare("INSERT INTO suscripciones (usuario_id, horario_id, fecha_inicio, activo) VALUES (?, ?, ?, 1)");
                $st_ins_s->bind_param('iis', $usuario_id, $horario_id, $fecha_clase);
                $st_ins_s->execute();
            }
        }

        // --- 5. NOTIFICACIONES Y COMMIT ---
        $id_sesion = (int)($_SESSION['user_id'] ?? 0);
        $res_alu = $conn->query("SELECT nombre, apellido FROM usuarios WHERE id=$usuario_id");
        $alu_data = $res_alu->fetch_assoc();
        $nombre_alu_completo = trim(($alu_data['nombre'] ?? '') . ' ' . ($alu_data['apellido'] ?? ''));

        $res_pro = $conn->query("SELECT nombre FROM profesores WHERE id=$prof_id");
        $pro_data = $res_pro->fetch_assoc();
        $nombre_pro_completo = $pro_data['nombre'] ?? 'Profesor';

        $fecha_f = date('d/m', strtotime($fecha_clase));
        $hora_f = substr($hora_inicio, 0, 5);
        $estado_txt = ($nuevo_estado === 'pendiente') ? ' (Pendiente a confirmar)' : '';

        if ($id_sesion !== $usuario_id) {
            $msg_alu = "La administración te ha agendado una reserva de $instr con $nombre_pro_completo el $fecha_f a las $hora_f hs ($tipo_turno, $modalidad)$estado_txt.";
            enviarNotificacion($conn, $usuario_id, $msg_alu, 'success', 'mis-reservas.php');

            if (!empty($observaciones)) {
                $msg_obs = "Nota de administración para tu clase de $instr ($fecha_f): \"$observaciones\"";
                enviarNotificacion($conn, $usuario_id, $msg_obs, 'info', 'mis-reservas.php');
            }
        }

        

        if ($id_sesion !== $prof_id) {
            $msg_pro = "La administración te ha asignado una reserva de $instr con el alumno $nombre_alu_completo el $fecha_f a las $hora_f hs ($tipo_turno, $modalidad)$estado_txt.";
            enviarNotificacion($conn, $prof_id, $msg_pro, 'info', 'mis-reservas.php');
        }

        if ($modo_horario === 'existente') {
            $conn->query("UPDATE horarios SET reservas_actuales = reservas_actuales + 1 WHERE id = $horario_id");
        }

        $conn->commit();
        header("Location: reservas.php?msg=" . urlencode("Reserva creada correctamente."));
        exit;

    } catch (Exception $e) {
        try { @$conn->rollback(); } catch (Throwable $t) {}
        $err = $e->getMessage();
    }
}
require_once __DIR__ . '/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    :root { --pri:#4f46e5; --accent:#6366f1; --border:rgba(255,255,255,0.08); }
    select, input, textarea, option { background-color: #0f172a !important; color: #fff !important; }
    .info-guide { background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); padding: 15px; border-radius: 12px; margin: 0 auto 25px auto; max-width: 700px; }
    .info-guide summary { cursor: pointer; outline: none; list-style: none; display: flex; align-items: center; gap: 10px; font-weight: 800; color: #60a5fa; font-size: 13px; }
    .info-guide p { margin: 15px 0 0 0; font-size: 12.5px; color: #cbd5e1; line-height: 1.6; }
    .card { background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius:24px; padding:40px; max-width:700px; margin:auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .mode-selector { display: flex; background: #0f172a; padding: 6px; border-radius: 14px; margin-bottom: 25px; border: 1px solid var(--border); }
    .mode-btn { flex: 1; padding: 12px; text-align: center; cursor: pointer; border-radius: 10px; font-size: 13px; font-weight: 700; color: #64748b; transition: 0.3s; }
    .mode-btn.active { background: var(--pri); color: #fff; }
    .form-group { margin-bottom:25px }
    label { display:block; font-size:11px; font-weight:800; color:#6b7280; text-transform:uppercase; margin-bottom:10px; letter-spacing: 1px; }
    select, input, textarea { width:100%; border:1px solid var(--border); padding:16px; border-radius:14px; font-size:14px; transition: 0.3s; outline: none; }
    select:focus, input:focus, textarea:focus { border-color: var(--pri); background: #1e293b !important; }
    .grid-nuevo { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; background: rgba(255,255,255,0.03); padding: 20px; border-radius: 16px; border: 1px dashed rgba(255,255,255,0.1); margin-bottom: 20px; }
    .btn-group { display: flex; gap: 15px; margin-top: 35px; }
    .btn-save { background: var(--pri); color:#fff; border:none; padding:16px 30px; border-radius:14px; font-weight:800; cursor:pointer; transition:0.3s; flex: 2; font-size: 14px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3); }
    .btn-save:hover { background: var(--accent); transform:translateY(-2px); }
    .btn-cancel { background: rgba(255,255,255,0.05); color:#9ca3af; border: 1px solid var(--border); padding:16px 30px; border-radius:14px; font-weight:800; cursor:pointer; transition:0.3s; flex: 1; text-align: center; text-decoration: none; font-size: 14px; }
    .alert-err { background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.2); padding:15px; border-radius:12px; margin-bottom:20px; font-weight:700; text-align: center; }

    .select2-container--default .select2-selection--single { background-color: #0f172a !important; border: 1px solid var(--border) !important; border-radius: 14px !important; height: 54px !important; display: flex; align-items: center; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { color: #fff !important; padding-left: 16px !important; }
    .select2-dropdown { background-color: #0f172a !important; border: 1px solid var(--pri) !important; border-radius: 14px !important; color: #fff !important; }
    .select2-results__option { background-color: #0f172a !important; color: #fff !important; }
    .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: var(--pri) !important; }
</style>

<div style="margin: 40px 0; text-align: center;">
    <h1 style="font-size: 2.2rem; letter-spacing: -1px; margin-bottom: 8px;">Nueva Reserva Manual</h1>
    <p style="color: #64748b;">Asigna un usuario a un turno existente o crea uno personalizado.</p>
</div>

<details class="info-guide">
    <summary><i class="fas fa-question-circle"></i> <span>¿Ayuda con las reservas?</span></summary>
    <p>• <strong>Turnos:</strong> Se puede usar un horario existente o crear uno nuevo desde aqui.<br>• <strong>Límite:</strong> Máximo <?= $max_semanal ?> reservas semanales por alumno.</p>
</details>

<div class="card">
    <?php if($err): ?><div class="alert-err">✗ <?= $err ?></div><?php endif; ?>

    <div id="horario-aviso" style="display:none; background: rgba(245, 158, 11, 0.1); color: #fbbf24; padding: 12px; border-radius: 12px; font-size: 13px; margin-bottom: 20px; border: 1px solid rgba(245, 158, 11, 0.3); text-align: center;">
        <i class="fas fa-clock"></i> 
        Horario de academia: <strong><?= $h_apertura ?> hs</strong> a <strong><?= $h_cierre ?> hs</strong>.
    </div>

    <form method="POST" id="formReserva" onsubmit="return validarAntesDeEnviar();">
        <div class="form-group">
            <label>Usuario (Alumno)</label>
            <select name="usuario_id" id="usuario_selector" class="js-buscador" required>
                <option value="">-- Buscar usuario --</option>
                <?php foreach($usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['apellido']." ".$u['nombre']." [".strtoupper($u['rol'])."] - ".$u['email']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <label>Tipo de Horario</label>
        <div class="mode-selector">
            <button type="button" class="mode-btn active" onclick="setModo('existente')" id="btn-ex">Horario Existente</button>
            <button type="button" class="mode-btn" onclick="setModo('nuevo')" id="btn-nw">Crear Nuevo</button>
        </div>
        <input type="hidden" name="modo_horario" id="modo_horario" value="existente">

        <div id="panel-existente" class="form-group">
            <label>Seleccionar Horario Disponible</label>
            <select name="horario_id" id="horario_selector">
                <option value="">-- Seleccionar --</option>
                <?php foreach($horarios_existentes as $h): 
                    $f = date('d/m', strtotime($h['fecha_especifica']));
                    $txt = "[{$h['tipo_turno']}] {$h['dia_semana']} {$f} - ".substr($h['hora'],0,5)." | {$h['profesor']} ({$h['modalidad']})";
                ?>
                    <option value="<?= $h['id'] ?>"><?= htmlspecialchars($txt) ?> (Cupos: <?= $h['capacidad']-$h['reservas_actuales'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="panel-nuevo" style="display:none;">
            <div class="grid-nuevo">
                <div><label>Fecha</label><input type="date" name="n_fecha" id="n_fecha" min="<?= date('Y-m-d') ?>"></div>
                <div><label>Hora Inicio</label><input type="time" name="n_hora" id="n_hora" onchange="verificarRangoHorario(this)"></div>
                <div><label>Duración (Min)</label><input type="number" name="n_duracion" value="60" ></div>
                <div><label>Profesor</label>
                    <select name="n_profesor_id" id="n_prof_id" onchange="actualizarInstrumentos()">
                        <option value="">--</option>
                        <?php foreach($profesores_data as $p): ?><option value="<?= $p['id'] ?>"><?= $p['nombre'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Instrumento</label><select name="n_instrumento" id="n_instr"><option value="">--</option></select></div>
                <div><label>Tipo</label><select name="n_tipo_turno"><option value="extra">Extra</option><option value="fijo">Fijo</option></select></div>
                <div><label>Modalidad</label><select name="n_modalidad"><option value="Presencial">Presencial</option><option value="Virtual">Virtual</option><option value="A domicilio">A domicilio</option></select></div>
            </div>
        </div>

        <div class="form-group">
            <label>Estado Inicial y Descripcion</label>
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:10px;">
                <select name="estado"><option value="confirmada">✓ Confirmada</option><option value="pendiente">⏳ Pendiente</option></select>
                <input type="text" name="descripcion" placeholder="Notas...">
            </div>
        </div>

        <div class="btn-group">
            <a href="reservas.php" class="btn-cancel">Descartar</a>
            <button type="submit" name="btn_crear" class="btn-save">Confirmar Reserva</button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const profesores = <?= json_encode($profesores_data) ?>;
const hApertura = "<?= $h_apertura ?>";
const hCierre = "<?= $h_cierre ?>";

function setModo(m) {
    $('#modo_horario').val(m);
    $('#panel-existente').toggle(m==='existente');
    $('#panel-nuevo').toggle(m==='nuevo');
    $('#btn-ex').toggleClass('active', m==='existente');
    $('#btn-nw').toggleClass('active', m==='nuevo');
    if(m === 'nuevo') $('#horario-aviso').fadeIn();
    else $('#horario-aviso').fadeOut();
}

function verificarRangoHorario(input) {
    if (input.value && (input.value < hApertura || input.value > hCierre)) {
        alert("Atención: El horario seleccionado está fuera del rango permitido.");
        input.value = ""; 
    }
}

function validarAntesDeEnviar() {
    return confirm('¿Procesar esta reserva?');
}

function actualizarInstrumentos() {
    const id = $('#n_prof_id').val();
    const sel = $('#n_instr').empty().append('<option value="">--</option>');
    const p = profesores.find(x => x.id == id);
    if(p) p.instrumentos.forEach(i => sel.append(new Option(i, i)));
}

$(document).ready(function() {
    $('.js-buscador').select2({ width: '100%' });
});
</script>