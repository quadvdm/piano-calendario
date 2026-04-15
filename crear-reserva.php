<?php
// crear-reserva.php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

session_start();
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$user_id = (int)($_SESSION['user_id'] ?? 0);
$userRol = strtolower($_SESSION['user_rol'] ?? 'alumno');


if ($userRol !== 'profesor' && $userRol !== 'admin-profesor') {
    header("Location: dashboard.php");
    exit;
}

// 1. OBTENER CONFIGURACIONES 
$resConf = $conn->query("SELECT clave, valor FROM configuraciones WHERE clave IN ('horario_apertura', 'horario_cierre', 'dias_anticipacion_reserva')");
$config = [];
while($rowC = $resConf->fetch_assoc()){
    $config[$rowC['clave']] = $rowC['valor'];
}
$h_apertura = $config['horario_apertura'] ?? '08:00';
$h_cierre   = $config['horario_cierre']   ?? '20:00';
$dias_anticipacion = (int)($config['dias_anticipacion_reserva'] ?? 7);

$fecha_minima_habilitar = date('Y-m-d', strtotime("+$dias_anticipacion days"));

// 2. OBTENER DATOS DEL PROFESOR 
$sqlProf = "SELECT id, nombre, especialidad FROM profesores WHERE id = ? AND activo = 1 LIMIT 1";
$stmtP = $conn->prepare($sqlProf);
$stmtP->bind_param("i", $user_id);
$stmtP->execute();
$profData = $stmtP->get_result()->fetch_assoc();

if (!$profData) {
    die("Error: El perfil de profesor no está activo o no existe.");
}

$instrumentos = array_map('trim', explode(',', (string)$profData['especialidad']));

$msg = $_GET['msg'] ?? ''; 
$err = $_GET['error'] ?? '';

// 3. PROCESAR LA CREACIÓN CON VALIDACIÓN INTEGRAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_crear'])) {
    $fecha  = (string)($_POST['fecha_especifica'] ?? '');
    $hora_inicio = trim((string)($_POST['hora'] ?? ''));
    $dur    = (int)($_POST['duracion_minutos'] ?? 60);
    $instr  = (string)($_POST['instrumento'] ?? '');
    $tipo   = (string)($_POST['tipo_turno'] ?? 'extra');
    $mod    = (string)($_POST['modalidad'] ?? 'Presencial');
    $cap    = ($tipo === 'fijo') ? 1 : (int)($_POST['capacidad'] ?? 1);

    try {
        if (!$fecha || !$hora_inicio || !$instr) {
            throw new Exception("Por favor, completa todos los campos obligatorios.");
        }

        // VALIDACIÓN DE ANTICIPACIÓN
        if ($fecha < $fecha_minima_habilitar) {
            throw new Exception("Solo puedes habilitar turnos con $dias_anticipacion días de anticipación (desde el " . date('d/m/Y', strtotime($fecha_minima_habilitar)) . ").");
        }

        $ahora = new DateTime();
        $inicio_dt = new DateTime($fecha . ' ' . $hora_inicio);
        
        if ($inicio_dt < $ahora) {
            throw new Exception('No puedes habilitar un turno para una fecha u hora que ya pasó.');
        }

        if ($hora_inicio < $h_apertura || $hora_inicio > $h_cierre) {
            throw new Exception("La academia opera de $h_apertura a $h_cierre.");
        }

        // Cálculo de tiempos para el nuevo turno
        $hora_fin_nueva = date('H:i:s', strtotime($hora_inicio . " + $dur minutes"));
        $dias_map = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
        $dia_nombre = $dias_map[date('l', strtotime($fecha))] ?? '';

        // --- VALIDACIÓN DE SOLAPAMIENTO ---
        $sqlCheck = "SELECT h.hora, h.duracion_minutos, h.tipo_turno, h.fecha_especifica, h.modalidad, p.nombre as prof_nombre, h.profesor_id
                     FROM horarios h
                     LEFT JOIN profesores p ON h.profesor_id = p.id
                     WHERE h.activo = 1 
                     AND h.dia_semana = ?
                     AND (
                         (? = 'extra' AND (
                             (h.tipo_turno = 'extra' AND h.fecha_especifica = ?) 
                             OR (h.tipo_turno = 'fijo' AND h.fecha_especifica <= ?)
                         ))
                         OR 
                         (? = 'fijo' AND (
                             (h.tipo_turno = 'extra' AND h.fecha_especifica >= ?) 
                             OR (h.tipo_turno = 'fijo')
                         ))
                     )
                     AND (
                         h.hora < ? 
                         AND 
                         ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) > ?
                     )";
        
        $stCheck = $conn->prepare($sqlCheck);
        $stCheck->bind_param('ssssssss', 
            $dia_nombre, $tipo, $fecha, $fecha, $tipo, $fecha, $hora_fin_nueva, $hora_inicio
        );
        $stCheck->execute();
        $resConflictos = $stCheck->get_result();

        while ($c = $resConflictos->fetch_assoc()) {
            $h_ini_c = substr($c['hora'], 0, 5);
            $h_fin_c = date('H:i', strtotime($c['hora'] . " + {$c['duracion_minutos']} minutes"));
            $dia_texto = ($c['tipo_turno'] === 'fijo') ? "fijo recurrente los {$dia_nombre}" : "el día " . date('d/m/Y', strtotime($c['fecha_especifica']));
            
            if ((int)$c['profesor_id'] === $user_id) {
                throw new Exception("Conflicto: Ya tienes un turno {$c['tipo_turno']} agendado {$dia_texto} de {$h_ini_c} a {$h_fin_c} hs.");
            }
            if (strtolower($mod) === 'presencial' && strtolower((string)$c['modalidad']) === 'presencial') {
                throw new Exception("El salón Presencial ya está ocupado {$dia_texto} de {$h_ini_c} a {$h_fin_c} hs por el profesor {$c['prof_nombre']}.");
            }
        }

        $check_sql = "SELECT id FROM horarios WHERE profesor_id = ? AND dia_semana = ? AND hora = ? AND activo = 1 AND fecha_especifica = ?";
        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("isss", $user_id, $dia_nombre, $hora_inicio, $fecha);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            throw new Exception("Ya has publicado este mismo horario anteriormente.");
        }
        $stmt_check->close();
        
        // --- 1. INSERTAR EL NUEVO HORARIO (ESTO ES LO QUE FALTABA) ---
        $sqlInsertH = "INSERT INTO horarios (profesor_id, instrumento, dia_semana, fecha_especifica, hora, duracion_minutos, tipo_turno, modalidad, activo) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($sqlInsertH);
        $stmt->bind_param("issssiss", $user_id, $instr, $dia_nombre, $fecha, $hora_inicio, $dur, $tipo, $mod);
        
        // --- 2. AUDITORÍA ADMINISTRATIVA ---
        $nombre_profe = $_SESSION['user_nombre'] ?? 'Un profesor';
        $hora_f = substr($hora_inicio, 0, 5);
        $msg_audit = "El profesor $nombre_profe ha publicado un nuevo horario de $instr los días $dia_nombre a las $hora_f hs.";
        
        $stmtAudit = $conn->prepare("INSERT INTO notificaciones (usuario_id, mensaje, tipo, leido) VALUES (0, ?, 'info', 0)");
        $stmtAudit->bind_param("s", $msg_audit);
        $stmtAudit->execute();
        $stmtAudit->close();
        
        // --- 3. EJECUTAR LA CREACIÓN DEL HORARIO ---
        if ($stmt->execute()) {
            $msg = "¡Horario habilitado correctamente!";
        } else {
            throw new Exception("Error al guardar: " . $conn->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// 4. OBTENER LA LISTA DE HORARIOS DEL PROFESOR
$sqlMisHorarios = "
    SELECT h.*, 
           (SELECT COUNT(*) FROM reservas r WHERE r.horario_id = h.id AND r.estado IN ('confirmada', 'pendiente')) as cant_reservas 
    FROM horarios h 
    WHERE h.profesor_id = ? AND h.activo = 1 
    ORDER BY h.fecha_especifica ASC, h.hora ASC";
$stmtMis = $conn->prepare($sqlMisHorarios);
$stmtMis->bind_param("i", $user_id);
$stmtMis->execute();
$mis_horarios = $stmtMis->get_result()->fetch_all(MYSQLI_ASSOC);

include_once 'navbar.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Habilitar Turno - Ánima</title>
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
        --radius: 24px;
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
        min-height: 100vh;
    }

    /* Partículas suavizadas */
    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.05); border-radius: 50%; box-shadow: 0 0 8px rgba(139, 92, 246, 0.15); }
    .particle:nth-child(1) { width: 4px; height: 4px; top: 20%; left: 10%; animation: float 18s infinite linear; }
    .particle:nth-child(2) { width: 6px; height: 6px; top: 60%; left: 85%; animation: float 22s infinite linear reverse; }
    .particle:nth-child(3) { width: 3px; height: 3px; top: 80%; left: 15%; animation: float 15s infinite linear; }
    .particle:nth-child(4) { width: 5px; height: 5px; top: 30%; left: 90%; animation: float 25s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-15px) translateX(15px); } }


    .main-layout { max-width: 1200px; margin: 40px auto; padding: 0 20px; display: flex; gap: 30px; align-items: flex-start; }
    .layout-left { flex: 1; min-width: 300px; }
    .layout-right { flex: 1; min-width: 300px; }
    
    @media (max-width: 900px) {
        .main-layout { flex-direction: column-reverse; }
        .layout-left, .layout-right { width: 100%; min-width: 0; }
    }

    .glass-card { 
        background: var(--glass-bg); 
        backdrop-filter: blur(12px); 
        -webkit-backdrop-filter: blur(12px); 
        border: 1px solid var(--glass-border); 
        padding: 30px; 
        border-radius: var(--radius); 
        box-shadow: 0 15px 35px rgba(0,0,0,0.3), 0 0 20px rgba(139, 92, 246, 0.05);
    }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
    @media (max-width: 500px) { .form-grid { grid-template-columns: 1fr; } }
    
    label { display: block; font-size: 0.8rem; color: var(--text-dim); margin-bottom: 8px; font-weight: 600; text-transform: uppercase; }
    
/* 1. ESTILO BASE PARA TODOS LOS CAMPOS (Texto, Date, Time, Select) */
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
    
    /* BOTONES */
    .btn-submit { 
        background: var(--gradient-primary); color: #fff; padding: 15px; border-radius: 12px; 
        border: none; font-weight: 800; cursor: pointer; transition: 0.3s; width: 100%; 
        display: block; text-align: center; text-decoration: none; box-sizing: border-box; 
        box-shadow: 0 6px 15px var(--accent-glow); text-transform: uppercase; font-size: 13px; letter-spacing: 1px;
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(139, 92, 246, 0.3); }
    
    .btn-cancel { 
        background: rgba(255,255,255,0.05); color: var(--muted); padding: 15px; border-radius: 12px; 
        border: 1px solid var(--glass-border); font-weight: 800; cursor: pointer; transition: 0.3s; 
        width: 100%; display: block; text-align: center; text-decoration: none; margin-top: 15px; 
        box-sizing: border-box; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;
    }
    .btn-cancel:hover { background: rgba(255,255,255,0.1); color: #fff; }

    /* ALERTAS E INFO BOXES */
    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: bold; }
    .alert-success { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #4ade80; box-shadow: inset 0 0 10px rgba(34, 197, 94, 0.1); }
    .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; box-shadow: inset 0 0 10px rgba(239, 68, 68, 0.1); }
    
    .prof-info { display: flex; align-items: center; gap: 15px; background: rgba(139, 92, 246, 0.1); padding: 15px; border-radius: 15px; margin-bottom: 25px; border-left: 4px solid var(--accent); }
    .info-box { background: rgba(139, 92, 246, 0.05); padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border); margin-bottom: 20px; font-size: 0.85rem; color: var(--muted); line-height: 1.4; }

    /* TARJETAS DE HORARIOS (LISTA DERECHA) */
    .horario-card { 
        background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); 
        border-radius: 16px; padding: 16px; margin-bottom: 12px; 
        display: flex; justify-content: space-between; align-items: center; transition: 0.3s; 
    }
    .horario-card:hover { border-color: rgba(139, 92, 246, 0.3); background: rgba(255,255,255,0.02); }
    
    .h-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; }
    .badge-libre { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
    .badge-reservado { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
    
    .btn-delete { 
        background: rgba(239, 68, 68, 0.1); color: #f87171; 
        border: 1px solid rgba(239, 68, 68, 0.2); padding: 8px 12px; 
        border-radius: 8px; cursor: pointer; transition: 0.2s; 
        text-decoration: none; font-size: 0.8rem; font-weight: 700; 
    }
    .btn-delete:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
</style>
</head>
<body>

<div class="main-layout">
    
    <div class="layout-left">
        <h2 style="font-size: 1.3rem; margin-top: 0; margin-bottom: 20px;"><i class="fas fa-list" style="color: var(--accent);"></i> Mis Horarios Publicados</h2>
        
        <?php if (empty($mis_horarios)): ?>
            <div style="background: rgba(255,255,255,0.02); border: 1px dashed var(--stroke); padding: 30px; text-align: center; border-radius: 16px; color: #94a3b8;">
                <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                <p>No tienes horarios publicados actualmente.</p>
            </div>
        <?php else: ?>
            <?php foreach ($mis_horarios as $h): 
                $es_libre = ($h['cant_reservas'] == 0);
            ?>
            <div class="horario-card">
                <div>
                    <div style="font-size: 1.2rem; font-weight: 800; color: #fff;">
                        <?= substr($h['hora'], 0, 5) ?> hs 
                        <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 500;">(<?= $h['duracion_minutos'] ?> min)</span>
                    </div>
                    <div style="font-size: 0.85rem; color: #cbd5e1; margin-top: 4px;">
                        <i class="fas fa-calendar-day"></i> <?= $h['dia_semana'] ?> <?= date('d/m', strtotime($h['fecha_especifica'])) ?>
                        <span style="margin: 0 5px; color: var(--stroke);">|</span>
                        <?= htmlspecialchars($h['instrumento']) ?>
                    </div>
                    <div style="margin-top: 8px; display: flex; gap: 6px;">
                        <span class="h-badge" style="background: rgba(255,255,255,0.05); color: #e2e8f0;"><?= $h['tipo_turno'] ?></span>
                        <span class="h-badge" style="background: rgba(255,255,255,0.05); color: #e2e8f0;"><?= $h['modalidad'] ?></span>
                        
                        <?php if ($es_libre): ?>
                            <span class="h-badge badge-libre">Libre</span>
                        <?php else: ?>
                            <span class="h-badge badge-reservado">Reservada</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($es_libre): ?>
                    <a href="profesor-cancelar-horario.php?id=<?= $h['id'] ?>" class="btn-delete" onclick="return confirm('¿Seguro que deseas eliminar este horario disponible?');" title="Eliminar Horario Libre">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="layout-right">
        <div class="glass-card">
            <div class="prof-info">
                <i class="fas fa-chalkboard-teacher" style="color: var(--accent); font-size: 1.5rem;"></i>
                <div>
                    <p style="margin: 0; font-size: 0.8rem; color: #94a3b8;">Sesión iniciada como:</p>
                    <h3 style="margin: 0;"><?= htmlspecialchars($profData['nombre']) ?></h3>
                </div>
            </div>

            <h1 style="margin: 0; font-size: 1.8rem;">Habilitar Horario</h1>
            <p style="color: #94a3b8; font-size: 0.9rem;">Crea un espacio disponible para que tus alumnos puedan reservar.</p>

            <div class="info-box">
                <div style="display: flex; gap: 10px;">
                    <i class="fas fa-info-circle" style="color: var(--accent); margin-top: 3px;"></i> 
                    <div>
                        Configuración de Reservas: Se requieren <strong><?= $dias_anticipacion ?> días</strong> de anticipación. <br>
                        Turnos públicos desde el <strong><?= date('d/m/Y', strtotime($fecha_minima_habilitar)) ?></strong>.
                    </div>
                </div>
            </div>

            <?php if($msg): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $msg ?></div> <?php endif; ?>
            <?php if($err): ?> <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $err ?></div> <?php endif; ?>

            <form method="POST" class="form-grid" id="formCrearReserva">
                <div>
                    <label>Fecha de la Clase</label>
                    <input type="date" name="fecha_especifica" class="input-style" 
                           min="<?= $fecha_minima_habilitar ?>" 
                           value="<?= $fecha_minima_habilitar ?>" required>
                </div>
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <label>Hora Inicio</label>
                        <span style="font-size: 11px; color: var(--accent); font-weight: 800; text-transform: uppercase;">
                            <?= $h_apertura ?> - <?= $h_cierre ?>
                        </span>
                    </div>
                    <input type="time" name="hora" class="input-style" required onchange="verificarRangoHorario(this)">
                </div>

                <div>
                    <label>Instrumento / Especialidad</label>
                    <select name="instrumento" class="input-style" required>
                        <?php foreach($instrumentos as $ins): ?>
                            <option value="<?= htmlspecialchars($ins) ?>"><?= htmlspecialchars($ins) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Duración (Minutos)</label>
                    <input type="number" name="duracion_minutos" class="input-style" value="60">
                </div>

                <div>
                    <label>Tipo de Turno</label>
                    <select name="tipo_turno" id="tipo_turno" class="input-style">
                        <option value="extra">Extra (Solo una vez)</option>
                        <option value="fijo">Fijo (Semanal / Suscripción)</option>
                    </select>
                </div>
                <div>
                    <label>Modalidad</label>
                    <select name="modalidad" class="input-style">
                        <option value="Presencial">Presencial</option>
                        <option value="Virtual">Virtual</option>
                        <option value="A domicilio">A domicilio</option>
                    </select>
                </div>

                <div id="capacidad_container" style="grid-column: span 2; display: none;">
                    <label>Capacidad (Alumnos en simultáneo)</label>
                    <input type="number" name="capacidad" class="input-style" value="1" min="1">
                </div>

                <div style="grid-column: span 2; margin-top: 10px;">
                    <button type="submit" name="btn_crear" class="btn-submit">
                        <i class="fas fa-plus-circle"></i> PUBLICAR HORARIO
                    </button>
                    <a href="calendario.php" class="btn-cancel">
                        <i class="fas fa-arrow-left"></i> Cancelar y volver al calendario
                    </a>
                </div>
            </form>
        </div>
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

document.getElementById('formCrearReserva').addEventListener('submit', function(e) {
    const horaInput = document.querySelector('input[name="hora"]').value;
    if (horaInput < H_APERTURA || horaInput > H_CIERRE) {
        e.preventDefault();
        alert(`Error: La academia está cerrada. Elija entre ${H_APERTURA} y ${H_CIERRE}.`);
    }
});
</script>

</body>
</html>