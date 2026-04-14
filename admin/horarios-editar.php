<?php
// admin/horarios-editar.php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();

$db   = Database::getInstance();
$conn = $db->getConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: horarios.php'); exit; }

$from = $_GET['from'] ?? 'horarios.php';

// 1. OBTENER DATOS ACTUALES DEL HORARIO
$st = $conn->prepare("SELECT * FROM horarios WHERE id=? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$h = $st->get_result()->fetch_assoc();
$st->close();

if (!$h) { header('Location: horarios.php'); exit; }

// --- OBTENER CONFIGURACIONES ---
$resConf = $conn->query("SELECT clave, valor FROM configuraciones WHERE clave IN ('horario_apertura', 'horario_cierre')");
$config = [];
while($rowC = $resConf->fetch_assoc()){
    $config[$rowC['clave']] = $rowC['valor'];
}
$h_apertura = $config['horario_apertura'] ?? '08:00';
$h_cierre   = $config['horario_cierre']   ?? '20:00';

$msg = ''; $err = '';

// 2. PROCESAR EL GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha  = (string)($_POST['fecha_especifica'] ?? '');
    $hora_inicio = trim((string)($_POST['hora'] ?? ''));
    $dur    = (int)($_POST['duracion_minutos'] ?? 60);
    $prof   = (int)($_POST['profesor_id'] ?? 0);
    $instr  = trim((string)($_POST['instrumento'] ?? ''));
    $tipo   = (string)($_POST['tipo_turno'] ?? 'fijo');
    $mod    = (string)($_POST['modalidad'] ?? 'Presencial');

    try {
        if (empty($fecha) || empty($hora_inicio) || $prof === 0 || empty($instr)) {
            throw new Exception('Por favor completa los campos obligatorios.');
        }

        $timestamp_inicio = strtotime($fecha . ' ' . $hora_inicio);
        $hora_fin = date('H:i:s', $timestamp_inicio + ($dur * 60));
        $dias_esp = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
        $dia_nombre = $dias_esp[date('l', $timestamp_inicio)] ?? '';

        // --- LÓGICA DE SOLAPAMIENTO ---
        $sql_conf = "SELECT h.id, p.nombre as prof_nombre, h.tipo_turno, h.fecha_especifica, h.modalidad, h.profesor_id, h.hora, h.duracion_minutos 
                     FROM horarios h 
                     JOIN profesores p ON h.profesor_id = p.id
                     WHERE h.activo = 1 AND h.id != ? AND h.dia_semana = ? 
                     AND (
                         (? = 'extra' AND (
                             (h.tipo_turno = 'extra' AND h.fecha_especifica = ?) OR 
                             (h.tipo_turno = 'fijo' AND h.fecha_especifica <= ?)
                         ))
                         OR 
                         (? = 'fijo' AND (
                             (h.tipo_turno = 'fijo') OR 
                             (h.tipo_turno = 'extra' AND h.fecha_especifica >= ?)
                         ))
                     )
                     AND (
                        ? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) 
                        AND 
                        ? > h.hora
                     )";

        $st_c = $conn->prepare($sql_conf);
        
        $st_c->bind_param('issssssss', 
            $id, 
            $dia_nombre, 
            $tipo, $fecha, $fecha, 
            $tipo, $fecha,
            $hora_inicio, $hora_fin
        );
        
        $st_c->execute();
        $res_c = $st_c->get_result();

        while ($c = $res_c->fetch_assoc()) {
            $h_ini_c = substr($c['hora'], 0, 5);
            $h_fin_c = date('H:i', strtotime($c['hora'] . " + {$c['duracion_minutos']} minutes"));
            
            if ((int)$c['profesor_id'] === $prof) {
                throw new Exception("El profesor {$c['prof_nombre']} ya tiene clase de {$h_ini_c} a {$h_fin_c} hs.");
            }
            if ($mod === 'Presencial' && $c['modalidad'] === 'Presencial') {
                throw new Exception("El salón Presencial ya está ocupado de {$h_ini_c} a {$h_fin_c} hs.");
            }
        }

        // --- ACTUALIZACIÓN ---
        $conn->begin_transaction();
        
        $stU = $conn->prepare("UPDATE horarios SET dia_semana=?, fecha_especifica=?, hora=?, duracion_minutos=?, profesor_id=?, instrumento=?, tipo_turno=?, modalidad=? WHERE id=? LIMIT 1");
        $stU->bind_param('sssiisssi', $dia_nombre, $fecha, $hora_inicio, $dur, $prof, $instr, $tipo, $mod, $id);
        
        if (!$stU->execute()) throw new Exception("Error al actualizar el horario.");

        // --- NOTIFICAR AL PROFESOR DE LA EDICIÓN ---
        $id_sesion = (int)($_SESSION['user_id'] ?? 0);
        if ($id_sesion !== $prof) {
            $fecha_f = date('d/m', strtotime($fecha));
            $hora_f = substr($hora_inicio, 0, 5);
            $msg_prof = "La administración ha modificado tu horario de $instr del $dia_nombre $fecha_f a las $hora_f hs.";
            enviarNotificacion($conn, $prof, $msg_prof, 'warning', 'mis-reservas.php');
        }
        // ----------------------------------------------------

        // Sincronizar fecha en reservas si el turno es extra
        $st_res = $conn->prepare("UPDATE reservas SET fecha = ? WHERE horario_id = ? AND estado IN ('pendiente', 'confirmada')");
        $st_res->bind_param('si', $fecha, $id);
        $st_res->execute();

        $conn->commit();
        header("Location: " . $from);
        exit;

    } catch (Exception $e) {
        try { @$conn->rollback(); } catch (Throwable $t) {}
        $err = $e->getMessage();
    }
}

// Obtener profesores para el selector
$profesores_data = [];
$res_p = $conn->query("SELECT id, nombre, especialidad FROM profesores WHERE activo=1 ORDER BY nombre");
while($row = $res_p->fetch_assoc()) {
    $especialidades = array_map('trim', explode(',', (string)$row['especialidad']));
    $profesores_data[] = ['id' => (int)$row['id'], 'nombre' => $row['nombre'], 'instrumentos' => $especialidades];
}

require_once __DIR__ . '/header.php';
?>

<style>
    .container-center { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 70vh; padding: 20px; }
    .card { border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.03); border-radius: 16px; padding: 24px; width: 100%; max-width: 850px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
    .row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    @media(max-width:820px){ .row { grid-template-columns: 1fr; } }
    label { display: block; font-weight: 900; font-size: 11px; color: #9ca3af; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .input, select { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,.2); background: #1f2937; color: #fff; outline: none; box-sizing: border-box; }
    select option { background: #1f2937; color: #fff; }
    .btn-save { background: #4f46e5; color: white; border: none; padding: 14px 28px; border-radius: 10px; font-weight: bold; cursor: pointer; transition: 0.2s; }
    .alert { padding: 14px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; width: 100%; max-width: 850px; box-sizing: border-box; }
    .alert-err { background: rgba(239,68,68,.1); color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
</style>

<div class="container-center">
    <h1>Editar Horario #<?= $id ?></h1>
    <p style="color: #9ca3af; margin-bottom: 20px;">Rango de horario de clases: <?= $h_apertura ?> a <?= $h_cierre ?> hs.</p>

    <?php if($err): ?><div class="alert alert-err"><b>✗</b> <?= $err ?></div><?php endif; ?>

    <div class="card">
        <form method="post" id="formEditar" action="horarios-editar.php?id=<?= $id ?>&from=<?= urlencode($from) ?>">
            <div class="row">
                <div>
                    <label>Fecha Específica</label>
                    <input type="date" class="input" name="fecha_especifica" required value="<?= htmlspecialchars((string)$h['fecha_especifica']) ?>">
                </div>
                <div>
                    <label>Hora Inicio</label>
                    <input type="time" class="input" name="hora" id="inputHora" required value="<?= htmlspecialchars(substr((string)$h['hora'], 0, 5)) ?>">
                </div>
                <div>
                    <label>Duración (min)</label>
                    <input type="number" class="input" name="duracion_minutos" required value="<?= (int)$h['duracion_minutos'] ?>">
                </div>
                <div>
                    <label>Profesor</label>
                    <select name="profesor_id" id="profesor_id" class="input" onchange="actualizarInstrumentos()" required>
                        <?php foreach($profesores_data as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($h['profesor_id'] == $p['id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars($p['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Instrumento</label>
                    <select name="instrumento" id="instrumento" class="form-control" required>
    <option value="">Seleccione un profesor primero...</option>
</select>
                </div>
                <div>
                    <label>Modalidad</label>
                    <select name="modalidad" class="input">
                        <option value="Presencial" <?= $h['modalidad'] === 'Presencial' ? 'selected' : '' ?>>Presencial</option>
                        <option value="Virtual" <?= $h['modalidad'] === 'Virtual' ? 'selected' : '' ?>>Virtual</option>
                        <option value="A domicilio" <?= $h['modalidad'] === 'A domicilio' ? 'selected' : '' ?>>A domicilio</option>
                    </select>
                </div>
                <div>
                    <label>Tipo de Turno</label>
                    <select name="tipo_turno" class="input">
                        <option value="fijo" <?= $h['tipo_turno'] === 'fijo' ? 'selected' : '' ?>>Fijo (Semanal)</option>
                        <option value="extra" <?= $h['tipo_turno'] === 'extra' ? 'selected' : '' ?>>Extra (Una vez)</option>
                    </select>
                </div>
                <div>
                    <label>Capacidad</label>
                    <input type="number" class="input" value="<?= (int)$h['capacidad'] ?>" readonly style="opacity: 0.6;">
                </div>
            </div>

            <div style="margin-top:30px; display: flex; align-items: center; justify-content: space-between;">
                <a href="<?= htmlspecialchars($from) ?>" style="color:#9ca3af; text-decoration:none;">← Volver</a>
                <button type="submit" class="btn-save">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
const profesores = <?= json_encode($profesores_data) ?>;
const seleccionadoAnterior = "<?= htmlspecialchars((string)$h['instrumento']) ?>";
const H_APERTURA = "<?= $h_apertura ?>";
const H_CIERRE = "<?= $h_cierre ?>";

function actualizarInstrumentos() {
    const profId = document.getElementById('profesor_id').value;
    const selectInstr = document.getElementById('instrumento');
    selectInstr.innerHTML = '';
    const prof = profesores.find(p => p.id == profId);
    if (prof && prof.instrumentos) {
        prof.instrumentos.forEach(instr => {
            if(instr) {
                const opt = document.createElement('option');
                opt.value = instr; opt.textContent = instr;
                if(instr === seleccionadoAnterior) opt.selected = true;
                selectInstr.appendChild(opt);
            }
        });
    }
}

document.getElementById('formEditar').addEventListener('submit', function(e) {
    const hora = document.getElementById('inputHora').value;
    if (hora < H_APERTURA || hora > H_CIERRE) {
        e.preventDefault();
        alert(`Horario no permitido, seleccione un horario entre ${H_APERTURA} y ${H_CIERRE} hs.`);
    }
});

document.addEventListener('DOMContentLoaded', actualizarInstrumentos);
</script>