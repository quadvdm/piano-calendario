<?php
declare(strict_types=1);

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

$reserva_id = (int)($_POST['reserva_id'] ?? 0);

if ($reserva_id <= 0) {
    header('Location: mis-reservas.php');
    exit;
}

// 1. Obtener datos completos
$sql = "
    SELECT r.*, 
           h.id as hor_id, h.profesor_id, h.hora, h.tipo_turno, h.instrumento,
           p.nombre AS profesor, 
           u.nombre AS alumno_nombre, u.apellido AS alumno_apellido
    FROM reservas r
    INNER JOIN horarios h ON r.horario_id = h.id
    INNER JOIN profesores p ON h.profesor_id = p.id
    INNER JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reserva_id);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reserva) {
    header('Location: mis-reservas.php');
    exit;
}

// 2. Validar permisos
if ($userRol === 'profesor') {
    if ((int)$reserva['profesor_id'] !== $user_id) exit;
} else if ($userRol === 'alumno') {
    if ((int)$reserva['usuario_id'] !== $user_id) exit;
}

// 3. Validar que sea un turno fijo
if ($reserva['tipo_turno'] !== 'fijo') {
    header('Location: mis-reservas.php?err=solo_fijos');
    exit;
}

$conn->begin_transaction();

try {
    $nombre_completo_alumno = trim($reserva['alumno_nombre'] . ' ' . $reserva['alumno_apellido']);

    // 4. GUARDAR EN HISTORIAL COMO 'TRASLADADA'
    $stmtHist = $conn->prepare("
        INSERT INTO historial_reservas 
        (reserva_id, usuario_id, profesor_id, nombre_alumno, nombre_profesor, instrumento, fecha_clase, hora, tipo_turno, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'trasladada')
    ");

    $stmtHist->bind_param(
        "iiissssss",
        $reserva['id'],
        $reserva['usuario_id'],
        $reserva['profesor_id'],
        $nombre_completo_alumno,
        $reserva['profesor'],
        $reserva['instrumento'],
        $reserva['fecha'],
        $reserva['hora'],
        $reserva['tipo_turno']
    );
    $stmtHist->execute();
    $stmtHist->close();

    // 5. Calcular nueva fecha +7 días
    $fechaObj = new DateTime($reserva['fecha']);
    $fechaObj->modify('+7 days');
    $nueva_fecha = $fechaObj->format('Y-m-d');

    // 6. ACTUALIZAR LA RESERVA
    $stmtUpdateR = $conn->prepare("UPDATE reservas SET fecha = ? WHERE id = ?");
    $stmtUpdateR->bind_param("si", $nueva_fecha, $reserva_id);
    $stmtUpdateR->execute();
    $stmtUpdateR->close();

    // NOTIFICAR EL CAMBIO DE SEMANA

    $id_sesion = (int)($_SESSION['user_id'] ?? 0);
    $id_alumno = (int)$reserva['usuario_id'];
    
    $nom_prof = $reserva['profesor'];
    $hora_f = substr($reserva['hora'], 0, 5);
    $dias = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
    $dia_esp = $dias[date('l', strtotime($nueva_fecha))] ?? '';
    $nueva_f = date('d/m', strtotime($nueva_fecha));

    if ($id_sesion !== $id_alumno) {
        $mensaje_notif = "Tu clase fija de {$reserva['instrumento']} con $nom_prof a las $hora_f hs ha sido trasladada a la próxima semana: $dia_esp $nueva_f.";
        enviarNotificacion($conn, $id_alumno, $mensaje_notif, 'info', 'mis-reservas.php');
    }

    // 7. ACTUALIZAR LA TABLA HORARIOS
    $stmtUpdateH = $conn->prepare("UPDATE horarios SET fecha_especifica = ?, reservas_actuales = 1 WHERE id = ?");
    $stmtUpdateH->bind_param("si", $nueva_fecha, $reserva['hor_id']);
    $stmtUpdateH->execute();
    $stmtUpdateH->close();

    $conn->commit();
    header('Location: mis-reservas.php?ok=reprogramado');
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    header('Location: mis-reservas.php?err=error_proceso');
}
exit;