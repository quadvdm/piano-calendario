<?php
// alumnos.php - Gestión de Alumnos con Integración de Procesadores Externos
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. INICIAR SESIÓN PRIMERO 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// 2. NORMALIZAR ROL
$user_id = (int)($_SESSION['user_id'] ?? 0); 
$profesor_logueado_id = $user_id; 
$user_rol = strtolower(trim(str_replace(["\r", "\n"], '', (string)($_SESSION['user_rol'] ?? ''))));

// 3. SEGURIDAD: VERIFICACIÓN DE ACCESO
if ($user_rol !== 'profesor' && $user_rol !== 'admin-profesor') {
    header('Location: dashboard.php');
    exit;
}


// --- 1. Obtener Configuración Dinámica de Horarios ---
$resConfig = $conn->query("SELECT clave, valor FROM configuraciones WHERE clave IN ('horario_apertura', 'horario_cierre')");
$config = [];
while ($rowC = $resConfig->fetch_assoc()) {
    $config[$rowC['clave']] = $rowC['valor'];
}
$h_apertura = $config['horario_apertura'] ?? '08:00';
$h_cierre   = $config['horario_cierre']   ?? '20:00';


// --- LÓGICA DE ACTUALIZACIÓN DE NIVEL (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_nivel') {
    $id_alum = (int)($_POST['id'] ?? 0);
    $nuevo_nivel = strtolower($_POST['nivel'] ?? ''); 
    
    if ($id_alum > 0 && in_array($nuevo_nivel, ['principiante', 'intermedio', 'avanzado'])) {
        $stmt_u = $conn->prepare("UPDATE usuarios SET nivel = ? WHERE id = ? AND rol = 'alumno'");
        $stmt_u->bind_param("si", $nuevo_nivel, $id_alum);
        $success = $stmt_u->execute();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => $success]);
        exit;
    }
}

$mensaje_exito = $_SESSION['msg_alumnos'] ?? null;
unset($_SESSION['msg_alumnos']);
$mensaje_js = null;

// --- LÓGICA DE ASIGNACIÓN DE CLASE  ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_clase'])) {
    $alumno_id = (int)($_POST['alumno_id'] ?? 0);
    $alumno_nombre = $_POST['alumno_nombre_hidden'] ?? 'Alumno';
    $fecha = $_POST['fecha'] ?? '';
    $hora_inicio = $_POST['hora'] ?? '';
    $duracion = (int)($_POST['duracion_minutos'] ?? 60); 
    $tipo_turno = $_POST['tipo_turno'] ?? 'extra';
    $instrumento = $_POST['instrumento'] ?? '';
    $modalidad = $_POST['modalidad'] ?? 'presencial';
    $observaciones = $_POST['observaciones'] ?? '';

    try {
        if ($alumno_id <= 0 || empty($fecha) || empty($hora_inicio)) throw new Exception("Faltan datos obligatorios.");

        // Cálculo del fin de la nueva clase basado en la duración recibida
        $hora_fin = date('H:i:s', strtotime("+$duracion minutes", strtotime($hora_inicio)));
        $dia_nombre = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'][date('w', strtotime($fecha))];

        // --- 0. VALIDACIÓN DE LÍMITE SEMANAL DEL ALUMNO ANTES DE CREAR NADA ---
        $resConfig_lim = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'max_reservas_semana' LIMIT 1");
        $max_permitido = (int)($resConfig_lim->fetch_assoc()['valor'] ?? 2);

        if ($max_permitido > 0) {
            $fecha_obj_lim = new DateTime($fecha);
            $lunes_lim = (clone $fecha_obj_lim)->modify('monday this week')->format('Y-m-d');
            $domingo_lim = (clone $fecha_obj_lim)->modify('sunday this week')->format('Y-m-d');

            $sql_lim = "SELECT COUNT(*) as total FROM reservas 
                        WHERE usuario_id = ? AND fecha BETWEEN ? AND ? 
                        AND estado IN ('confirmada', 'pendiente')";
            $st_lim = $conn->prepare($sql_lim);
            $st_lim->bind_param('iss', $alumno_id, $lunes_lim, $domingo_lim);
            $st_lim->execute();
            $ya_tiene = (int)$st_lim->get_result()->fetch_assoc()['total'];

            if ($ya_tiene >= $max_permitido) {
                throw new Exception("Límite alcanzado: El alumno ya tiene {$ya_tiene} de {$max_permitido} clases para la semana del " . $fecha_obj_lim->format('d/m') . ".");
            }
        }

        // 1. VALIDACIÓN INTEGRAL DE CONFLICTOS
        $sql_check = "SELECT h.id, h.profesor_id, h.modalidad, h.instrumento, h.hora, h.duracion_minutos, h.tipo_turno, h.fecha_especifica,
                             r.usuario_id, 
                             p.nombre AS prof_nombre
                      FROM horarios h
                      LEFT JOIN reservas r ON h.id = r.horario_id AND r.estado IN ('pendiente', 'confirmada')
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
                          -- Caso A: Una clase existente empieza antes de que termine la nueva Y termina después de que empiece la nueva
                          (h.hora < ? AND ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) > ?)
                          OR 
                          -- Caso B: La nueva clase empieza antes de que termine una existente Y termina después de que empiece la existente
                          (? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) AND ? > h.hora)
                      )";

        $stmt_check = $conn->prepare($sql_check);
        
        $stmt_check->bind_param("ssssssssss", 
            $dia_nombre, $tipo_turno, $fecha, $fecha, $tipo_turno, $fecha, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin       
        );
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        while ($conflict = $res_check->fetch_assoc()) {
            $inicio_f = date('H:i', strtotime($conflict['hora']));
            $dur_c = (int)$conflict['duracion_minutos'];
            $fin_f = date('H:i', strtotime("+{$dur_c} minutes", strtotime($conflict['hora'])));
            $rango = "{$inicio_f} a {$fin_f}";
            
            $instr_c = htmlspecialchars($conflict['instrumento'] ?? 'Clase');
            $prof_c = htmlspecialchars($conflict['prof_nombre'] ?? 'Otro profesor');
            $es_fijo_conflicto = ($conflict['tipo_turno'] === 'fijo');
            $fecha_conflicto = $conflict['fecha_especifica'];

            $detalle_fecha = $es_fijo_conflicto ? "en su turno recurrente" : "el día " . date('d/m', strtotime($fecha_conflicto));

            // ¡Corrección del mensaje! 
            if ((int)$conflict['profesor_id'] === $profesor_logueado_id) {
                throw new Exception("TÚ ya tienes una clase de {$instr_c} dictándose {$detalle_fecha} de {$rango}.");
            }

            if (isset($conflict['usuario_id']) && (int)$conflict['usuario_id'] === $alumno_id) {
                throw new Exception("EL ALUMNO ya tiene clase de {$instr_c} con {$prof_c} {$detalle_fecha} de {$rango}.");
            }

            if (strtolower($modalidad) === 'presencial' && strtolower((string)$conflict['modalidad']) === 'presencial') {
                throw new Exception("La sala presencial ya está ocupada {$detalle_fecha} de {$rango} por el profesor {$prof_c}.");
            }
        }

        // 2. CREACIÓN DEL HORARIO
        $sql_h = "INSERT INTO horarios (profesor_id, dia_semana, hora, duracion_minutos, instrumento, tipo_turno, fecha_especifica, modalidad, capacidad, reservas_actuales, activo) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 1)";
        $stmt_h = $conn->prepare($sql_h);
        $stmt_h->bind_param("ississss", $profesor_logueado_id, $dia_nombre, $hora_inicio, $duracion, $instrumento, $tipo_turno, $fecha, $modalidad);
        $stmt_h->execute();
        $nuevo_horario_id = $conn->insert_id;

        // 3. PASAMOS DATOS AL PROCESADOR
        $_POST['horario_id'] = $nuevo_horario_id;
        $_POST['fecha_seleccionada'] = $fecha;
        $_POST['observaciones'] = $observaciones; 
        $_POST['alumno_id'] = $alumno_id; 

        if ($tipo_turno === 'fijo') {
            require 'procesar-suscripcion.php';
        } else {
            require 'procesar-reserva.php';
        }

        // --- 4. ESCUCHAR SI HUBO UN ERROR EN EL PROCESADOR ---
        if (isset($_SESSION['mensaje_error'])) {
            $err_proc = $_SESSION['mensaje_error'];
            unset($_SESSION['mensaje_error']); // Limpiamos
            // Eliminamos el horario fantasma
            $conn->query("DELETE FROM horarios WHERE id = $nuevo_horario_id");
            throw new Exception($err_proc);
        }

        $mensaje_js = "{status: 'success', text: '¡Clase agendada correctamente!', id: $alumno_id}";

    } catch (Exception $e) {
        $mensaje_js = "{status: 'error', text: '" . $e->getMessage() . "', id: $alumno_id, nombre: '$alumno_nombre'}";
    }
}

include_once 'navbar.php';

// OBTENEMOS INSTRUMENTOS 
$res_p = $conn->prepare("SELECT especialidad FROM profesores WHERE id = ?");
$res_p->bind_param("i", $profesor_logueado_id);
$res_p->execute();
$row_p = $res_p->get_result()->fetch_assoc();
$mis_instrumentos = array_map('trim', explode(',', (string)($row_p['especialidad'] ?? '')));

$alumnos = $db->fetchAll("SELECT id, nombre, apellido, email, telefono, avatar, nivel 
                           FROM usuarios 
                           WHERE (rol = 'alumno' OR rol = 'admin') 
                           AND activo = 1 
                           ORDER BY nombre ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Alumnos - Anima</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --bg-color: #131527; 
        --bg-darker: #0d101b;
        --glass-bg: rgba(255, 255, 255, 0.05); 
        --glass-border: rgba(255, 255, 255, 0.12); 
        --text: #f8fafc; 
        --muted: #cbd5e1; 
        --text-dim: #94a3b8;
        --accent: #8b5cf6; 
        --accent-glow: rgba(139, 92, 246, 0.5);
        --gradient-primary: linear-gradient(135deg, #8b5cf6, #6d28d9);
        --success: #4ade80; 
        --error: #f87171;
        --radius: 24px;
    }

    * { box-sizing: border-box; }

    body {
        background: 
            radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), 
            radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), 
            linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
        background-attachment: fixed;
        color: var(--text);
        font-family: 'Inter', sans-serif;
        margin: 0;
        padding-bottom: 50px;
        min-height: 100vh;
    }

    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
    .particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
    .particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
    .particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
    .particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }

    .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
    
    #pageAlert { display: none; background: rgba(74, 222, 128, 0.15); color: var(--success); padding: 15px; border-radius: 15px; border: 1px solid rgba(74, 222, 128, 0.3); margin-bottom: 20px; text-align: center; font-weight: 700; backdrop-filter: blur(10px); }

 
    .search-container { position: relative; max-width: 400px; margin-bottom: 30px; }
    .search-container i { position: absolute; left: 16px; top: 16px; color: var(--text-dim); }
    .search-input { width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); padding: 14px 14px 14px 45px; border-radius: 20px; color: white; font-size: 15px; outline: none; transition: 0.3s; backdrop-filter: blur(10px); }
    .search-input:focus { border-color: var(--accent); background: rgba(255, 255, 255, 0.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.3); }

    /* TARJETA DE ALUMNO  */
    .student-card { 
        background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius); 
        padding: 30px; margin-bottom: 25px; display: grid; 
        grid-template-columns: 100px 1fr 1fr auto; 
        gap: 25px; align-items: center; backdrop-filter: blur(20px); 
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        box-shadow: 0 15px 35px rgba(0,0,0,0.3);
    }
    .student-card:hover { 
        transform: translateY(-5px); 
        border-color: rgba(139, 92, 246, 0.4); 
        box-shadow: 0 20px 40px rgba(0,0,0,0.4), 0 0 20px rgba(139, 92, 246, 0.1); 
    }

    .student-card > * { min-width: 0; }
    .avatar-img, .no-avatar { width: 100px; height: 100px; border-radius: 25px; object-fit: cover; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    .no-avatar { background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 800; text-shadow: 0 2px 5px rgba(0,0,0,0.3); color: #fff; }
    
    .info-main h3 { margin: 0 0 8px 0; font-size: 1.5rem; font-weight: 900; color: #fff; }
    .info-contact { font-size: 14px; color: var(--muted); display: flex; flex-direction: column; gap: 6px; }
    .info-contact span { display: flex; align-items: center; gap: 8px; color: var(--text-dim); }
    .info-contact i { color: var(--accent); }
    .info-contact b { color: #fff; font-weight: 600; }

    /* SELECTOR DE NIVEL */
    .level-container { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
    .level-select { background: rgba(0,0,0,0.3); color: #fff; border: 1px solid var(--glass-border); padding: 8px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; width: 130px; outline: none; transition: 0.3s; }
    .level-select:focus { border-color: var(--accent); }
    .level-select option { background: var(--bg-darker); }
    
    .btn-save-level { background: rgba(255,255,255,0.1); border: 1px solid var(--glass-border); color: #fff; width: 34px; height: 34px; border-radius: 10px; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .btn-save-level:hover { background: rgba(74, 222, 128, 0.2); border-color: var(--success); color: var(--success); }
    .level-feedback { font-size: 11px; color: var(--success); font-weight: 800; opacity: 0; transition: opacity 0.3s ease; white-space: nowrap; text-shadow: 0 0 5px rgba(74,222,128,0.5); }

    /* HISTORIAL MINIATURA */
    .reservas-wrapper { 
        background: rgba(0,0,0,0.2); border-radius: 18px; padding: 15px; 
        border: 1px solid var(--glass-border); min-height: 120px; max-height: 160px; 
        overflow-y: auto; font-size: 12px; 
    }
    .reservas-wrapper::-webkit-scrollbar { width: 5px; }
    .reservas-wrapper::-webkit-scrollbar-track { background: transparent; }
    .reservas-wrapper::-webkit-scrollbar-thumb { background: rgba(139, 92, 246, 0.4); border-radius: 10px; }
    
    .reserva-item { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13px; color: var(--muted); display: flex; align-items: center; flex-wrap: wrap; gap: 5px;}
    .reserva-item:last-child { border-bottom: none; }
    .res-prof { color: #fff; font-weight: 700; }
    
    .res-type { font-size: 9px; font-weight: 900; padding: 3px 6px; border-radius: 6px; text-transform: uppercase; margin-right: 4px; letter-spacing: 0.5px; }
    .type-fijo { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.3); } 
    .type-extra { background: rgba(14, 165, 233, 0.2); color: #7dd3fc; border: 1px solid rgba(14, 165, 233, 0.3); }

    /* BOTÓN ASIGNAR */
    .btn-add-class { 
        background: var(--gradient-primary); color: #fff; border: none; 
        padding: 14px 20px; border-radius: 14px; font-weight: 800; cursor: pointer; 
        font-size: 12px; transition: 0.3s; white-space: nowrap; 
        box-shadow: 0 8px 20px var(--accent-glow); text-transform: uppercase; letter-spacing: 0.5px;
    }
    .btn-add-class:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(139, 92, 246, 0.7); }

    /* MODAL DE ASIGNACIÓN */
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.75); backdrop-filter: blur(15px);align-items: center; justify-content: center; }
    .modal-content { background: var(--bg-darker); margin: 5% auto; padding: 35px; border-radius: var(--radius); width: 95%; max-width: 550px; border: 1px solid var(--glass-border); box-shadow: 0 30px 60px rgba(0,0,0,0.6), 0 0 40px rgba(139, 92, 246, 0.15); }
    .modal-alert { display: none; background: rgba(239, 68, 68, 0.15); color: var(--error); padding: 15px; border-radius: 15px; margin-bottom: 20px; border: 1px solid rgba(239, 68, 68, 0.3); text-align: center; font-weight: 700; font-size: 14px; }
    
    .input-group { margin-bottom: 20px; }
    .input-group label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; font-weight: 800; margin-bottom: 8px; letter-spacing: 0.5px; }
    
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
    appearance: none;
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
    
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    textarea.input-style { resize: none; height: 90px; }

    /* RESPONSIVE */
    @media (max-width: 1024px) {
        .student-card { grid-template-columns: 80px 1fr; }
        .reservas-wrapper { grid-column: span 2; }
        .btn-add-class { grid-column: span 2; width: 100%; text-align: center; justify-content: center; display: flex; }
    }

    @media (max-width: 768px) {
        .student-card { grid-template-columns: 70px 1fr; gap: 15px; padding: 20px; }
        .avatar-img, .no-avatar { width: 70px; height: 70px; border-radius: 18px; font-size: 1.8rem; }
        .info-main h3 { font-size: 1.2rem; }
        .info-contact { font-size: 12px; }
        .reservas-wrapper { grid-column: span 2; max-height: 140px; }
        .btn-add-class { grid-column: span 2; width: 100%; text-align: center; font-size: 12px; }
        .level-container { flex-wrap: wrap; gap: 8px; }
        .level-select { width: 100%; }
        .modal { padding: 10px; } 
        .modal-content { margin: 0; max-height: 90vh; overflow-y: auto; padding: 25px; }
        .input-group { margin-bottom: 15px; }
        .input-style { padding: 12px; font-size: 13px; }
        textarea.input-style { height: 70px; }
        .grid-2 { grid-template-columns: 1fr; gap: 15px; } 
    }
</style>
</head>
<body>
<div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
<div class="container">
    <div id="pageAlert"></div>

    <h1 style="font-weight: 900; letter-spacing: -1.5px; font-size: 2.5rem; margin-bottom: 10px;">Gestión de Alumnos</h1>

    <div class="search-container">
        <i class="fas fa-search"></i>
        <input type="text" id="buscadorAlumnos" class="search-input" placeholder="Buscar por nombre, apellido o email...">
    </div>

    <?php foreach($alumnos as $al): 
        $nivel_actual = strtolower((string)$al['nivel']);
        $resAl = $db->fetchAll("SELECT r.fecha, h.hora, h.tipo_turno, h.instrumento, h.duracion_minutos, p.nombre as prof_nombre 
                                FROM reservas r 
                                JOIN horarios h ON r.horario_id = h.id 
                                JOIN profesores p ON h.profesor_id = p.id
                                WHERE r.usuario_id = ? AND r.estado IN ('pendiente', 'confirmada') 
                                AND r.fecha >= CURDATE() ORDER BY r.fecha ASC LIMIT 5", [$al['id']]);
    ?>
    <div class="student-card" id="card_<?= $al['id'] ?>">
        <div>
            <?php if(!empty($al['avatar'])): ?>
                <img src="<?= $al['avatar'] ?>" class="avatar-img">
            <?php else: ?>
                <div class="no-avatar"><?= substr($al['nombre'], 0, 1) ?></div>
            <?php endif; ?>
        </div>

        <div class="info-main">
            <h3><?= h($al['nombre'] . ' ' . $al['apellido']) ?></h3>
            <div class="info-contact">
                <span><i class="fas fa-envelope"></i> <b><?= h($al['email']) ?></b></span>
                <span><i class="fas fa-phone"></i> <b><?= h($al['telefono'] ?: 'Sin teléfono') ?></b></span>
                
                <div class="level-container">
                    <select class="level-select" id="nivel_<?= $al['id'] ?>">
                        <option value="principiante" <?= $nivel_actual == 'principiante' ? 'selected' : '' ?>>Principiante</option>
                        <option value="intermedio" <?= $nivel_actual == 'intermedio' ? 'selected' : '' ?>>Intermedio</option>
                        <option value="avanzado" <?= $nivel_actual == 'avanzado' ? 'selected' : '' ?>>Avanzado</option>
                    </select>
                    <button class="btn-save-level" onclick="actualizarNivel(<?= $al['id'] ?>)" title="Guardar nivel">
                        <i class="fas fa-check"></i>
                    </button>
                    <span id="feedback_<?= $al['id'] ?>" class="level-feedback">¡Cambiado!</span>
                </div>
            </div>
        </div>

        <div class="reservas-wrapper">
            <?php if(empty($resAl)): ?>
                <div style="color: var(--text-dim); font-size: 12px; text-align: center; margin-top: 45px;">Sin clases próximas</div>
            <?php else: ?>
                <?php foreach($resAl as $r): ?>
                    <div class="reserva-item">
                        <span class="res-type <?= $r['tipo_turno'] == 'fijo' ? 'type-fijo' : 'type-extra' ?>"><?= $r['tipo_turno'] ?></span>
                        <strong style="color:#fff"><?= date('d/m', strtotime($r['fecha'])) ?></strong> 
                        <span style="color:var(--text-dim)"><?= substr($r['hora'], 0, 5) ?></span>
                        <br>
                        <span class="res-prof">Profesor <?= h($r['prof_nombre']) ?></span> • <span style="font-size:11px"><?= h($r['instrumento']) ?> (<?= $r['duracion_minutos'] ?> min)</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <button class="btn-add-class" onclick="abrirModal(<?= $al['id'] ?>, '<?= h($al['nombre']) ?>')">
            <i class="fas fa-calendar-plus"></i> ASIGNAR CLASE
        </button>
    </div>
    <?php endforeach; ?>
</div>

<div id="modalClase" class="modal">
    <div class="modal-content">
        <div id="modalAlert" class="modal-alert"></div>
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h2 id="modalTitle" style="margin:0; font-weight: 900; font-size: 1.4rem; color: #fff;"></h2>
            <i class="fas fa-times" onclick="cerrarModal()" style="cursor:pointer; font-size: 20px; opacity: 0.5;"></i>
        </div>
        <form id="formAsignar" method="POST">
            <input type="hidden" name="asignar_clase" value="1">
            <input type="hidden" name="alumno_id" id="modal_alumno_id">
            <input type="hidden" name="alumno_nombre_hidden" id="modal_alumno_nombre">
            <div class="grid-2">
                <div class="input-group">
                    <label>Fecha</label>
                    <input type="date" name="fecha" class="input-style" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="input-group">
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <label>Tiempo </label>
                        <span id="horario-info" style="font-size: 10px; color: var(--accent); font-weight: 800; text-transform: uppercase;">
                            <i class="fas fa-clock"></i> <?= $h_apertura ?>AM - <?= $h_cierre ?>PM
                        </span>
                    </div>
                    <input type="time" name="hora" class="input-style" required onchange="verificarRangoHorario(this)">
                </div>
            </div>
            <div class="grid-2">
                <div class="input-group"><label>Instrumento</label>
                    <select name="instrumento" class="input-style" required>
                        <?php foreach($mis_instrumentos as $ins): if($ins): ?>
                            <option value="<?= htmlspecialchars($ins) ?>"><?= htmlspecialchars($ins) ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Duración (minutos)</label>
                    <input type="number" name="duracion_minutos" class="input-style" value="60" required placeholder="">
</div>
            </div>
            <div class="grid-2">
                <div class="input-group"><label>Modalidad</label>
                    <select name="modalidad" class="input-style">
                        <option value="presencial">Presencial</option><option value="virtual">Virtual</option><option value="a domicilio">A domicilio</option>
                    </select>
                </div>
                <div class="input-group"><label>Tipo</label>
                    <select name="tipo_turno" class="input-style">
                        <option value="extra">Extra (Solo una vez)</option><option value="fijo">Fijo (Semanal)</option>
                    </select>
                </div>
            </div>
            <div class="input-group"><label>Estado Inicial</label>
                <select name="estado" class="input-style">
                    <option value="confirmada">✓ Confirmada</option>
                    <option value="pendiente">⏳ Pendiente</option>
                </select>
            </div>
            <div class="input-group"><label>Descripcion/Nota</label>
                <textarea name="observaciones" class="input-style"></textarea>
            </div>
            <button type="submit" class="btn-add-class" style="background: var(--accent); color: white; width: 100%; margin-top: 10px; font-size: 14px;">CONFIRMAR Y AGENDAR</button>
        </form>
    </div>
</div>

<script>
// --- LÓGICA DEL BUSCADOR ---
document.getElementById('buscadorAlumnos').addEventListener('input', function() {
    const filtro = this.value.toLowerCase();
    const tarjetas = document.querySelectorAll('.student-card');

    tarjetas.forEach(tarjeta => {
        const info = tarjeta.querySelector('.info-main').innerText.toLowerCase();
        if (info.includes(filtro)) {
            tarjeta.style.display = ''; 
        } else {
            tarjeta.style.display = 'none';
        }
    });
});


const modal = document.getElementById('modalClase');
const modalAlert = document.getElementById('modalAlert');
const pageAlert = document.getElementById('pageAlert');
const H_APERTURA = "<?= $h_apertura ?>";
const H_CIERRE = "<?= $h_cierre ?>";

document.querySelector('input[name="hora"]').addEventListener('change', function() {
    verificarRangoHorario(this);
});

function verificarRangoHorario(input) {
    if (input.value) {
        if (input.value < H_APERTURA || input.value > H_CIERRE) {
            alert(`Atención: El horario seleccionado (${input.value}) está fuera del rango permitido (${H_APERTURA} a ${H_CIERRE}).`);
            input.value = ""; 
        }
    }
}

document.getElementById('formAsignar').addEventListener('submit', function(e) {
    const hora = this.querySelector('input[name="hora"]').value;
    
    if (hora < H_APERTURA || hora > H_CIERRE) {
        e.preventDefault();
        modalAlert.innerText = `Error: La academia está cerrada. Elija entre ${H_APERTURA} y ${H_CIERRE}.`;
        modalAlert.style.display = 'block';
        return false;
    }
    
    return confirm('¿Confirmar la asignación de esta clase?');
});

function abrirModal(id, nombre) {
    document.getElementById('modal_alumno_id').value = id;
    document.getElementById('modal_alumno_nombre').value = nombre;
    document.getElementById('modalTitle').innerText = 'Agendar a ' + nombre;
    modal.style.display = 'flex';
    modalAlert.style.display = 'none';
}

function cerrarModal() { modal.style.display = 'none'; }

function actualizarNivel(id) {
    const select = document.getElementById('nivel_' + id);
    const valor = select.value;
    const btn = select.nextElementSibling;
    const feedback = document.getElementById('feedback_' + id);
    const originalIcon = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    fetch('alumnos.php', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
        body: `id=${id}&nivel=${valor}&accion=actualizar_nivel` 
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.style.background = '#10b981';
            feedback.style.opacity = '1';
            
            setTimeout(() => { 
                btn.style.background = ''; 
                btn.innerHTML = originalIcon;
                btn.disabled = false;
                feedback.style.opacity = '0'; 
            }, 2500);
        }
    })
    .catch(error => {
        btn.innerHTML = '<i class="fas fa-times"></i>';
        btn.style.background = '#ef4444';
        setTimeout(() => { 
            btn.style.background = ''; 
            btn.innerHTML = originalIcon;
            btn.disabled = false;
        }, 2000);
    });
}

<?php if ($mensaje_js): ?>
    const res = <?= $mensaje_js ?>;
    if (res.status === 'success') {
        pageAlert.innerText = res.text;
        pageAlert.style.display = 'block';
        window.scrollTo(0,0);
        setTimeout(() => { pageAlert.style.display = 'none'; }, 2000);
    } else if (res.status === 'error') {
        abrirModal(res.id, res.nombre);
        modalAlert.innerText = res.text;
        modalAlert.style.display = 'block';
    }
<?php endif; ?>

window.onclick = e => { if (e.target == modal) cerrarModal(); }

function h(str) {
    if (!str) return "";
    return str.replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}
</script>

<?php function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } ?>
</body>
</html>