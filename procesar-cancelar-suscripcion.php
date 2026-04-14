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

$db = Database::getInstance();
$conn = $db->getConnection();

$user_id = (int)$_SESSION['user_id'];
$userRol = strtolower($_SESSION['user_rol'] ?? 'alumno');

$sus_id = (int)($_POST['suscripcion_id'] ?? 0);

if ($sus_id <= 0) {
    header('Location: mis-reservas.php');
    exit;
}

// 1. Obtener datos de la suscripción
$sqlSus = "
    SELECT s.*, h.profesor_id, h.id as hor_id, h.instrumento, p.nombre AS nombre_profesor, 
           u.nombre, u.apellido
    FROM suscripciones s
    INNER JOIN horarios h ON s.horario_id = h.id
    INNER JOIN profesores p ON h.profesor_id = p.id
    INNER JOIN usuarios u ON s.usuario_id = u.id
    WHERE s.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sqlSus);
$stmt->bind_param("i", $sus_id);
$stmt->execute();
$sus = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sus) {
    header('Location: mis-reservas.php');
    exit;
}

// Validar permisos
if ($userRol === 'profesor') {
    if ((int)$sus['profesor_id'] !== $user_id) exit;
} else if ($userRol === 'alumno') {
    if ((int)$sus['usuario_id'] !== $user_id) exit;
}

$hoy = date('Y-m-d');

// 2. Obtener reservas futuras para el historial
$reservasFuturas = $db->fetchAll("
    SELECT r.id, r.usuario_id, r.fecha, h.hora, p.especialidad AS instrumento
    FROM reservas r
    INNER JOIN horarios h ON r.horario_id = h.id
    INNER JOIN profesores p ON h.profesor_id = p.id
    WHERE r.suscripcion_id = ? AND r.fecha >= ?
", [$sus_id, $hoy]);

$conn->begin_transaction();
try {
    // 3. GUARDAR EN HISTORIAL
    foreach ($reservasFuturas as $r) {
        $nombre_alu = trim(($sus['nombre'] ?? '') . ' ' . ($sus['apellido'] ?? ''));
        
        $db->query("
            INSERT INTO historial_reservas 
            (reserva_id, usuario_id, profesor_id, nombre_alumno, nombre_profesor, instrumento, fecha_clase, hora, tipo_turno, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'fijo', 'cancelada')
        ", [
            $r['id'], 
            $r['usuario_id'], 
            $sus['profesor_id'], 
            $nombre_alu, 
            $sus['nombre_profesor'], 
            $sus['instrumento'], 
            $r['fecha'], 
            $r['hora']
        ]);
    }

    // 4. BORRAR RESERVAS FUTURAS
    $db->query("DELETE FROM reservas WHERE suscripcion_id = $sus_id AND fecha >= '$hoy'");

    // 5. DESACTIVAR SUSCRIPCIÓN Y ACTUALIZAR CONTADOR DEL USUARIO
    $stmtS = $conn->prepare("UPDATE suscripciones SET activo = 0, fecha_fin = ? WHERE id = ?");
    $stmtS->bind_param('si', $hoy, $sus_id);
    $stmtS->execute();
    $stmtS->close();

    $sqlSub = "UPDATE usuarios SET tiene_suscripcion = GREATEST(0, tiene_suscripcion - 1) WHERE id = ?";
    $stSub = $conn->prepare($sqlSub);
    $stSub->bind_param("i", $sus['usuario_id']);
    $stSub->execute();
    $stSub->close();

    // 6. LIBERAR CUPO EN EL HORARIO
    $hid = (int)$sus['hor_id'];
    $conn->query("UPDATE horarios SET reservas_actuales = GREATEST(0, reservas_actuales - 1) WHERE id = $hid");


    // NOTIFICAR CANCELACIÓN (Suscripción Fija)
    $id_sesion = (int)($_SESSION['user_id'] ?? 0);
    $id_alumno = (int)$sus['usuario_id']; 
    $inst = $sus['instrumento'] ?? 'clase';
    
    $nom_alu = trim(($sus['nombre'] ?? '') . ' ' . ($sus['apellido'] ?? ''));
    $nom_prof = $sus['nombre_profesor'] ?? 'Profesor';
    
    $st_hora = $conn->query("SELECT hora, dia_semana FROM horarios WHERE id = " . (int)$sus['hor_id']);
    $h_datos = $st_hora->fetch_assoc();
    $hora_f = substr($h_datos['hora'] ?? '00:00', 0, 5);
    $dia_esp = $h_datos['dia_semana'] ?? 'la semana';

    if ($id_sesion !== $id_alumno) {
        $msg_alumno = "Tu suscripción fija de $inst los días $dia_esp a las $hora_f hs con $nom_prof ha sido cancelada.";
        enviarNotificacion($conn, $id_alumno, $msg_alumno, 'danger', 'mis-reservas.php');
    } else {
        $res_u = $conn->query("SELECT u.id FROM usuarios u JOIN profesores p ON u.email = p.email WHERE p.id = " . (int)$sus['profesor_id']);
        $user_profe = $res_u->fetch_assoc();
        if ($user_profe) {
            $msg_profe = "El alumno $nom_alu ha cancelado su suscripción fija de $inst los días $dia_esp a las $hora_f hs.";
            enviarNotificacion($conn, (int)$user_profe['id'], $msg_profe, 'danger', 'mis-reservas.php');
        }
    }

    $conn->commit();
    header('Location: mis-reservas.php?ok=sus_cancelada');
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    header('Location: mis-reservas.php?err=error_proceso');
}
exit;