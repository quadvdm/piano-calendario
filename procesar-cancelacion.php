<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$db   = Database::getInstance();
$conn = $db->getConnection();

$user_id = (int)$_SESSION['user_id'];
$userRol = strtolower($_SESSION['user_rol'] ?? 'alumno');

$reserva_id = (int)($_POST['reserva_id'] ?? 0);

if ($reserva_id <= 0) {
    header('Location: mis-reservas.php');
    exit;
}

// 1. Obtener datos antes de borrar para el historial
$sql = "
    SELECT r.*, h.profesor_id, h.hora, p.nombre AS profesor, h.instrumento AS instrumento, 
           u.nombre, u.apellido
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

// Validar permisos 
if ($userRol === 'profesor') {
    if ((int)$reserva['profesor_id'] !== $user_id) exit;
} else if ($userRol === 'alumno') {
    if ((int)$reserva['usuario_id'] !== $user_id) exit;
}

$conn->begin_transaction();
try {
    // 2. GUARDAR HISTORIAL
    $stmtH = $conn->prepare("
        INSERT INTO historial_reservas 
        (reserva_id, usuario_id, profesor_id, nombre_alumno, nombre_profesor, instrumento, fecha_clase, hora, tipo_turno, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'extra', 'cancelada')
    ");
    $nombre_alu = trim(($reserva['nombre'] ?? '') . ' ' . ($reserva['apellido'] ?? ''));

    $stmtH->bind_param(
        "iiisssss",
        $reserva_id, $reserva['usuario_id'], $reserva['profesor_id'],
        $nombre_alu, $reserva['profesor'], $reserva['instrumento'],
        $reserva['fecha'], $reserva['hora']
    );
    $stmtH->execute();

    // 3. BORRAR RESERVA ACTIVA
    $stmtDel = $conn->prepare("DELETE FROM reservas WHERE id = ?");
    $stmtDel->bind_param("i", $reserva_id);
    $stmtDel->execute();

    // NOTIFICAR CANCELACIÓN (Turnos Extras)
    $id_sesion = (int)($_SESSION['user_id'] ?? 0);
    $id_alumno = (int)$reserva['usuario_id']; 
    $inst = $reserva['instrumento'] ?? 'clase';
    
    $nom_alu = trim(($reserva['nombre'] ?? '') . ' ' . ($reserva['apellido'] ?? ''));
    $nom_prof = $reserva['profesor'] ?? 'Profesor';
    $dias = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
    $dia_esp = $dias[date('l', strtotime($reserva['fecha']))] ?? '';
    $fecha_f = date('d/m', strtotime($reserva['fecha']));
    $hora_f = substr($reserva['hora'], 0, 5);

    if ($id_sesion !== $id_alumno) {
        $msg_alumno = "Tu clase extra de $inst con $nom_prof del $dia_esp $fecha_f a las $hora_f hs ha sido cancelada.";
        enviarNotificacion($conn, $id_alumno, $msg_alumno, 'danger', 'mis-reservas.php');
    } else {
        $res_u = $conn->query("SELECT u.id FROM usuarios u JOIN profesores p ON u.email = p.email WHERE p.id = " . (int)$reserva['profesor_id']);
        $user_profe = $res_u->fetch_assoc();
        if ($user_profe) {
            $msg_profe = "El alumno $nom_alu ha cancelado su clase extra de $inst del $dia_esp $fecha_f a las $hora_f hs.";
            enviarNotificacion($conn, (int)$user_profe['id'], $msg_profe, 'danger', 'mis-reservas.php');
        }
    }

    // 4. LIBERAR CUPO EN EL HORARIO
    $hid = (int)$reserva['horario_id'];
    $conn->query("UPDATE horarios SET reservas_actuales = GREATEST(0, reservas_actuales - 1) WHERE id = $hid");

    $conn->commit();
    header('Location: mis-reservas.php?ok=cancelado');
} catch (Exception $e) {
    $conn->rollback();
    header('Location: mis-reservas.php?err=error_proceso');
}
exit;