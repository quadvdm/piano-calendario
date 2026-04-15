<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance();
$conn = $db->getConnection();

$user_id = (int)$_SESSION['user_id'];
$userRol = strtolower($_SESSION['user_rol'] ?? 'alumno');

$resConf = $conn->query("SELECT clave, valor FROM configuraciones WHERE clave IN ('horario_apertura', 'horario_cierre')");
$config = [];
while($rowC = $resConf->fetch_assoc()){
    $config[$rowC['clave']] = $rowC['valor'];
}
$h_apertura = $config['horario_apertura'] ?? '08:00';
$h_cierre   = $config['horario_cierre']   ?? '20:00';

$reserva_id = (int)($_GET['id'] ?? $_POST['reserva_id'] ?? 0);

if ($reserva_id <= 0) {
    header('Location: mis-reservas.php');
    exit;
}

$sql = "SELECT r.id AS reserva_id, r.fecha, r.suscripcion_id, r.usuario_id, r.horario_id,
               h.hora, h.duracion_minutos, h.tipo_turno, h.modalidad, h.profesor_id, h.instrumento,
               u.nombre AS alumno_nombre
        FROM reservas r
        INNER JOIN horarios h ON r.horario_id = h.id
        INNER JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reserva_id);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();

if (!$reserva) {
    header('Location: mis-reservas.php?err=no_existe');
    exit;
}

if ($userRol !== 'profesor' && $userRol !== 'admin-profesor') {
    header('Location: dashboard.php');
    exit;
}

if ($userRol === 'profesor' && (int)$reserva['profesor_id'] !== $user_id) {
    header('Location: mis-reservas.php?err=permiso_denegado');
    exit;
}

// LÓGICA DE GUARDADO Y VALIDACIÓN GLOBAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_guardar'])) {
    try {
        $horario_id      = (int)$_POST['horario_id'];
        $nueva_fecha     = $_POST['fecha'] ?? '';
        $nueva_hora      = trim((string)($_POST['hora'] ?? ''));
        $nueva_duracion  = (int)($_POST['duracion'] ?? 60); 
        $nuevo_tipo      = $_POST['tipo_turno'] ?? 'extra';
        $nueva_modalidad = $_POST['modalidad'] ?? 'Presencial';
        $prof_id         = (int)$reserva['profesor_id'];
        $observaciones   = trim((string)($_POST['observaciones'] ?? ''));
        
        $nuevo_usuario_id = (int)($_POST['usuario_id'] ?? $reserva['usuario_id']);
        $antiguo_usuario_id = (int)$reserva['usuario_id'];

        if (!$horario_id || !$nueva_fecha || !$nueva_hora || $nueva_duracion <= 0) {
            throw new Exception("Faltan datos obligatorios o la duración es inválida.");
        }

        $timestamp_inicio = strtotime($nueva_fecha . ' ' . $nueva_hora);
        $hora_fin = date('H:i:s', $timestamp_inicio + ($nueva_duracion * 60));
        
        $dias_map = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
        $dia_nombre = $dias_map[date('l', $timestamp_inicio)] ?? '';

        // --- VALIDACIÓN DE SOLAPAMIENTO GLOBAL (Detecta conflictos con TODOS los profesores) ---
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
            $horario_id, $dia_nombre, 
            $nuevo_tipo, $nueva_fecha, $nueva_fecha, 
            $nuevo_tipo, $nueva_fecha,
            $nueva_hora, $hora_fin
        );
        
        $st_c->execute();
        $res_c = $st_c->get_result();

        while ($c = $res_c->fetch_assoc()) {
            $h_ini_c = substr($c['hora'], 0, 5);
            $h_fin_c = date('H:i', strtotime($c['hora'] . " + {$c['duracion_minutos']} minutes"));
            
            if ((int)$c['profesor_id'] === $prof_id) {
                throw new Exception("Ya tienes una clase agendada de {$h_ini_c} a {$h_fin_c} hs.");
            }
            if (strtolower($nueva_modalidad) === 'presencial' && strtolower($c['modalidad']) === 'presencial') {
                throw new Exception("El salón Presencial ya está ocupado de {$h_ini_c} a {$h_fin_c} hs por el profesor {$c['prof_nombre']}.");
            }
        }

        $conn->begin_transaction();
        // --- LÓGICA DE SUSCRIPCIÓN (Usando columna 'activo') ---
        $suscripcion_id = $reserva['suscripcion_id'];

        if ($nuevo_tipo === 'fijo' && empty($suscripcion_id)) {
            // Caso A: De Extra a Fijo -> Crear nueva suscripción activa
            $stmtS = $conn->prepare("INSERT INTO suscripciones (usuario_id, horario_id, fecha_inicio, activo) VALUES (?, ?, ?, 1)");
            $stmtS->bind_param("iis", $nuevo_usuario_id, $horario_id, $nueva_fecha);
            $stmtS->execute();
            $suscripcion_id = $conn->insert_id;
        } 
        elseif ($nuevo_tipo === 'extra' && !empty($suscripcion_id)) {
            // Caso B: De Fijo a Extra -> Desactivar suscripción anterior
            $fecha_ayer = date('Y-m-d', strtotime($nueva_fecha . ' -1 day'));
            $stmtS = $conn->prepare("UPDATE suscripciones SET activo = 0, fecha_fin = ? WHERE id = ?");
            $stmtS->bind_param("si", $fecha_ayer, $suscripcion_id);
            $stmtS->execute();
            
            // Rompemos el vínculo para esta reserva específica
            $suscripcion_id = null;
        }

        // Sincronizar estadísticas si cambió el alumno
        if ($nuevo_usuario_id !== $antiguo_usuario_id) {
            $conn->query("UPDATE usuarios SET total_reservas = GREATEST(0, total_reservas - 1) WHERE id = $antiguo_usuario_id");
            $conn->query("UPDATE usuarios SET total_reservas = total_reservas + 1 WHERE id = $nuevo_usuario_id");
        }

        // A. Actualizar Horario 
        $stmtH = $conn->prepare("UPDATE horarios SET fecha_especifica = ?, dia_semana = ?, hora = ?, duracion_minutos = ?, tipo_turno = ?, modalidad = ? WHERE id = ?");
        $stmtH->bind_param("sssissi", $nueva_fecha, $dia_nombre, $nueva_hora, $nueva_duracion, $nuevo_tipo, $nueva_modalidad, $horario_id);
        $stmtH->execute();

        // B. Actualizar Reserva vinculando el suscripcion_id
        $stmtR = $conn->prepare("UPDATE reservas SET fecha = ?, usuario_id = ?, suscripcion_id = ?, observaciones = ? WHERE id = ?");
        $stmtR->bind_param("siisi", $nueva_fecha, $nuevo_usuario_id, $suscripcion_id, $observaciones, $reserva_id);
        $stmtR->execute();

        // NOTIFICAR EDICIÓN DE CLASE
        $id_sesion = (int)($_SESSION['user_id'] ?? 0);
        $id_alumno = (int)$reserva['usuario_id'];
        $inst = $reserva['instrumento'] ?? 'clase';
        
        // Obtener nombre del profesor y alumno
        $res_u = $conn->query("SELECT u.id, p.nombre as prof_n FROM usuarios u JOIN profesores p ON u.email = p.email WHERE p.id = " . (int)$reserva['profesor_id']);
        $user_profe = $res_u->fetch_assoc();
        $nom_prof = $user_profe['prof_n'] ?? 'Profesor';
        $nom_alu = $reserva['alumno_nombre'] ?? 'Un alumno';
        
        $dia_esp = $dias_map[date('l', strtotime($nueva_fecha))] ?? '';
        $fecha_f = date('d/m', strtotime($nueva_fecha));
        $hora_f = substr($nueva_hora, 0, 5);
        $tipo_str = ($nuevo_tipo === 'fijo') ? 'fija' : 'extra';

        if ($id_sesion === $id_alumno) {
            if ($user_profe) {
                $msg = "El alumno $nom_alu ha editado los detalles de su clase $tipo_str de $inst del $dia_esp $fecha_f a las $hora_f hs.";
                enviarNotificacion($conn, (int)$user_profe['id'], $msg, 'info', 'mis-reservas.php');
            }
        } else {
            $msg_alu = "Se han modificado los detalles de tu clase $tipo_str de $inst con $nom_prof del $dia_esp $fecha_f a las $hora_f hs.";
            enviarNotificacion($conn, $id_alumno, $msg_alu, 'warning', 'mis-reservas.php');

            if (!empty($observaciones)) {
                $msg_obs = "El profesor ha dejado una nota en tu clase de $inst ($fecha_f): \"$observaciones\"";
                enviarNotificacion($conn, $id_alumno, $msg_obs, 'info', 'mis-reservas.php');
            }
        }

        $conn->commit();
        $_SESSION['mensaje_exito'] = "Reserva actualizada con éxito.";
        header("Location: mis-reservas.php");
        exit;

    } catch (Exception $e) {
        if(isset($conn)) $conn->rollback();
        $error_msg = $e->getMessage();
    }
}

include_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Clase - Ánima Música</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --bg-color: #131527; 
        --bg-darker: #0d101b;
        --glass-bg: rgba(255, 255, 255, 0.03); 
        --glass-border: rgba(255, 255, 255, 0.08); 
        --text: #f8fafc; 
        --muted: #cbd5e1; 
        --text-dim: #94a3b8;
        --accent: #8b5cf6; 
        --accent-glow: rgba(139, 92, 246, 0.2); 
        --gradient-primary: linear-gradient(135deg, #8b5cf6, #6d28d9);
        --radius: 20px;
    }

    * { box-sizing: border-box; }

    body {
        background: 
            radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.12) 0%, transparent 45%), 
            radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.12) 0%, transparent 45%), 
            linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
        background-attachment: fixed;
        color: var(--text);
        font-family: 'Inter', sans-serif;
        margin: 0;
        padding-bottom: 40px;
        min-height: 100vh;
    }

    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.05); border-radius: 50%; box-shadow: 0 0 8px rgba(139, 92, 246, 0.15); }
    .particle:nth-child(1) { width: 4px; height: 4px; top: 20%; left: 10%; animation: float 18s infinite linear; }
    .particle:nth-child(2) { width: 6px; height: 6px; top: 60%; left: 85%; animation: float 22s infinite linear reverse; }
    .particle:nth-child(3) { width: 3px; height: 3px; top: 80%; left: 15%; animation: float 15s infinite linear; }
    .particle:nth-child(4) { width: 5px; height: 5px; top: 30%; left: 90%; animation: float 25s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-15px) translateX(15px); } }

    .container { 
        max-width: 650px; 
        margin: 60px auto; 
        padding: 0 20px; 
    }

    .edit-card { 
        background: var(--glass-bg); 
        backdrop-filter: blur(12px); 
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border); 
        border-radius: var(--radius); 
        padding: 40px; 
        box-shadow: 0 15px 35px rgba(0,0,0,0.3), 0 0 20px rgba(139, 92, 246, 0.05);
    }

    .header { 
        margin-bottom: 30px; 
        border-bottom: 1px solid var(--glass-border); 
        padding-bottom: 20px; 
    }
    .header h1 { 
        font-size: 1.6rem; 
        margin: 0; 
        color: #fff; 
        font-weight: 800; 
        letter-spacing: -0.5px;
        text-shadow: 0 0 15px rgba(139, 92, 246, 0.2);
    }
    .header p { 
        color: var(--text-dim); 
        font-size: 0.95rem; 
        margin-top: 8px; 
    }
    .badge-info { 
        background: rgba(139, 92, 246, 0.1); 
        color: #c4b5fd; 
        padding: 14px 18px; 
        border-radius: 12px; 
        margin-bottom: 25px; 
        font-size: 0.9rem; 
        border: 1px solid rgba(139, 92, 246, 0.2);
        border-left: 4px solid var(--accent); 
        box-shadow: inset 0 0 10px rgba(139, 92, 246, 0.05);
        line-height: 1.5;
    }

    .form-group { margin-bottom: 22px; }
    
    label { 
        display: block; 
        margin-bottom: 8px; 
        font-size: 0.75rem; 
        color: var(--text-dim); 
        font-weight: 700; 
        text-transform: uppercase; 
        letter-spacing: 0.5px;
    }
    
    .input { 
        width: 100%; 
        padding: 14px 16px; 
        border-radius: 12px; 
        border: 1px solid var(--glass-border); 
        background: rgba(0,0,0,0.2); 
        color: #fff; 
        font-size: 0.95rem; 
        font-family: inherit;
        transition: 0.3s ease;
    }
.input-style, .input {
    width: 100%;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--glass-border);
    background: rgba(0, 0, 0, 0.4);
    color: #fff;
    font-size: 0.95rem;
    font-family: inherit;
    outline: none;
    transition: 0.3s ease;
    appearance: none; /* Quitamos estilos nativos */
}

select.input-style, select.input {
    cursor: pointer;
    padding-right: 40px; 
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%238b5cf6'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px;
}

.input-style option, .input option {
    background-color: #1a1c2e; 
    color: #fff;
    padding: 10px;
}

input[type="date"]::-webkit-calendar-picker-indicator,
input[type="time"]::-webkit-calendar-picker-indicator {
    filter: invert(45%) sepia(90%) saturate(1500%) hue-rotate(230deg) brightness(100%) contrast(100%);
    cursor: pointer;
}

.input-style:focus, .input:focus {
    border-color: var(--accent);
    background-color: rgba(255, 255, 255, 0.05);
    box-shadow: 0 0 10px rgba(139, 92, 246, 0.2);
}

input[type="text"].input, 
input[type="email"].input, 
input[type="number"].input,
textarea.input {
    background-image: none !important;
}

    .grid-2 { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        gap: 20px; 
    }
    .btn-save { 
        width: 100%; 
        background: var(--gradient-primary); 
        color: white; 
        padding: 16px; 
        border-radius: 14px; 
        border: none; 
        font-weight: 800; 
        cursor: pointer; 
        transition: 0.3s ease; 
        margin-top: 15px; 
        font-size: 0.95rem; 
        text-transform: uppercase;
        letter-spacing: 1px;
        box-shadow: 0 6px 15px var(--accent-glow);
    }
    .btn-save:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 10px 25px rgba(139, 92, 246, 0.3); 
    }
    .btn-save:active { transform: scale(0.98); }

    .btn-back { 
        display: inline-flex; 
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        text-align: center; 
        margin-top: 20px; 
        padding: 16px;
        color: var(--text-dim); 
        text-decoration: none; 
        font-size: 0.85rem; 
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-radius: 14px;
        border: 1px solid transparent;
        transition: 0.3s ease;
    }
    .btn-back:hover { 
        color: #fff; 
        background: rgba(255,255,255,0.05);
        border-color: var(--glass-border);
    }

    /* --- RESPONSIVE --- */
    @media (max-width: 600px) {
        .container { 
            margin: 30px auto; 
            padding: 0 15px; 
        }
        .edit-card { 
            padding: 25px; 
            border-radius: 20px;
        }
        .grid-2 { 
            grid-template-columns: 1fr; 
            gap: 15px; 
        }
        .header h1 { font-size: 1.4rem; }
    }
</style>
</head>
<body>

<div class="container">
    <div class="edit-card">
        <div class="header">
            <h1><i class="fas fa-edit"></i> Reprogramar Clase</h1>
            <p>Ajusta los detalles de la reserva seleccionada.</p>
        </div>

        <div class="badge-info">
            <i class="fas fa-user"></i> Alumno: <b><?= htmlspecialchars($reserva['alumno_nombre']) ?></b><br>
            <i class="fas fa-music"></i> Instrumento: <b><?= htmlspecialchars($reserva['instrumento'] ?: 'No especificado') ?></b>
        </div>

        <?php if(isset($error_msg)): ?>
            <div style="color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 10px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #ef4444; font-weight: 500;">
                <i class="fas fa-exclamation-triangle"></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" id="formEditarReserva">
            <input type="hidden" name="reserva_id" value="<?= $reserva['reserva_id'] ?>">
            <input type="hidden" name="horario_id" value="<?= $reserva['horario_id'] ?>">

            <div class="form-group">
                <label>Fecha de la Clase</label>
                <input type="date" name="fecha" class="input" value="<?= $reserva['fecha'] ?>" min="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <label>Hora de Inicio</label>
                        <span style="font-size: 12px; color: var(--accent); font-weight: 800; text-transform: uppercase;">
                            <i class="fas fa-clock"></i> <?= $h_apertura ?> - <?= $h_cierre ?>
                        </span>
                    </div>
                    <input type="time" name="hora" class="input" value="<?= substr($reserva['hora'], 0, 5) ?>" required onchange="verificarRangoHorario(this)">
                </div>
                <div class="form-group">
                    <label>Duración (min)</label>
                    <input type="number" name="duracion" class="input" value="<?= $reserva['duracion_minutos'] ?>" min="1">
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Tipo de Turno</label>
                    <select name="tipo_turno" class="input">
                        <option value="extra" <?= $reserva['tipo_turno'] == 'extra' ? 'selected' : '' ?>>Extra (Solo una vez)</option>
                        <option value="fijo" <?= $reserva['tipo_turno'] == 'fijo' ? 'selected' : '' ?>>Fijo (Suscripción)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Modalidad</label>
                    <select name="modalidad" class="input">
                        <option value="Presencial" <?= strtolower($reserva['modalidad']) == 'presencial' ? 'selected' : '' ?>>Presencial</option>
                        <option value="Virtual" <?= strtolower($reserva['modalidad']) == 'virtual' ? 'selected' : '' ?>>Virtual</option>
                        <option value="A domicilio" <?= strtolower($reserva['modalidad']) == 'a domicilio' ? 'selected' : '' ?>>A domicilio</option>
                    </select>
                </div>
            </div>

            <div class="field-group" style="margin-bottom: 20px;">
    <label style="display: block; font-weight: 600; margin-bottom: 5px; color: var(--text-main);">
        Descripción / Notas para el Alumno
    </label>
    <textarea name="observaciones" class="input-style" rows="3" 
              placeholder="Escribe una nota que el alumno recibirá..." 
              style="width: 100%; resize: vertical; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);"><?= htmlspecialchars($reserva['observaciones'] ?? '') ?></textarea>
    <small style="color: var(--text-dim);">Si escribes algo aquí, se enviará con lo escrito una notificación al alumno.</small>
</div>

            <button type="submit" name="btn_guardar" class="btn-save">
                <i class="fas fa-save"></i> Guardar Cambios
            </button>
            
            <a href="mis-reservas.php" class="btn-back">Cancelar y volver</a>
        </form>
    </div>
</div>

<script>
const H_APERTURA = "<?= $h_apertura ?>";
const H_CIERRE = "<?= $h_cierre ?>";

function verificarRangoHorario(input) {
    if (input.value) {
        if (input.value < H_APERTURA || input.value > H_CIERRE) {
            alert(`Academia cerrada en ese horario. Por favor selecciona entre ${H_APERTURA} y ${H_CIERRE}`);
            input.value = ""; 
        }
    }
}

document.getElementById('formEditarReserva').addEventListener('submit', function(e) {
    const horaInput = document.querySelector('input[name="hora"]').value;
    if (horaInput < H_APERTURA || horaInput > H_CIERRE) {
        e.preventDefault();
        alert(`Error: La academia está cerrada. Elija entre ${H_APERTURA} y ${H_CIERRE}.`);
    }
});
</script>

</body>
</html>