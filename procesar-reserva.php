<?php
// procesar-reserva.php - Lógica para Turnos Extra corregida con solapamiento asimétrico
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (!isset($db)) {
    require_once 'config/database.php';
    $db = Database::getInstance();
}
$conn = $db->getConnection();

$horario_id = (int)($_POST['horario_id'] ?? 0);
$fecha_reserva = $_POST['fecha_seleccionada'] ?? ''; 
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_rol_actual = strtolower($_SESSION['user_rol'] ?? 'alumno');

if (!empty($_POST['alumno_id']) && in_array($user_rol_actual, ['profesor', 'admin-profesor'])) {
    $user_id = (int)$_POST['alumno_id'];
}
try {
    if ($horario_id <= 0 || empty($fecha_reserva)) {
        throw new Exception("Datos de reserva incompletos.");
    }

    $conn->begin_transaction();

    // 1. OBTENER DATOS DEL HORARIO SOLICITADO
    $st_h = $conn->prepare("SELECT hora, duracion_minutos, capacidad, reservas_actuales, profesor_id, instrumento FROM horarios WHERE id = ?");
    $st_h->bind_param('i', $horario_id);
    $st_h->execute();
    $h_data = $st_h->get_result()->fetch_assoc();
    
    if (!$h_data) throw new Exception("El horario seleccionado no existe.");

    $hora_inicio = $h_data['hora'];
    $duracion_nueva = (int)$h_data['duracion_minutos'];
    $hora_fin = date('H:i:s', strtotime($hora_inicio . " + $duracion_nueva minutes"));

    // 2. VALIDACIÓN DE LÍMITE SEMANAL
    $resConfig = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'max_reservas_semana' LIMIT 1");
    $max_permitido = (int)($resConfig->fetch_assoc()['valor'] ?? 2);

    $fecha_obj = new DateTime($fecha_reserva);
    $inicio_semana = (clone $fecha_obj)->modify('monday this week')->format('Y-m-d');
    $fin_semana = (clone $fecha_obj)->modify('sunday this week')->format('Y-m-d');

    $sqlCheck = "SELECT COUNT(*) as total FROM reservas 
                 WHERE usuario_id = ? 
                 AND fecha BETWEEN ? AND ? 
                 AND estado IN ('confirmada', 'pendiente')";
    $stCheck = $conn->prepare($sqlCheck);
    $stCheck->bind_param("iss", $user_id, $inicio_semana, $fin_semana);
    $stCheck->execute();
    $ya_tiene = $stCheck->get_result()->fetch_assoc()['total'];

    if ($ya_tiene >= $max_permitido) {
        throw new Exception("Límite alcanzado: Ya tienes $ya_tiene reservas en la semana del " . $fecha_obj->format('d/m') . ".");
    }

    // 3. VALIDACIÓN DE SOLAPAMIENTO 
    $sql_solape = "
        SELECT r.id, h.tipo_turno, r.fecha 
        FROM reservas r
        JOIN horarios h ON r.horario_id = h.id
        WHERE r.usuario_id = ? 
          AND r.estado != 'cancelada'
          AND (
              -- Choca el mismo día exacto
              r.fecha = ?
              OR 
              -- Choca con una suscripción fija que ya está activa (empezó antes o hoy)
              (h.tipo_turno = 'fijo' AND DAYOFWEEK(r.fecha) = DAYOFWEEK(?) AND r.fecha <= ?)
          )
          AND (
              -- Comparación de tiempo dinámica usando la duración de cada clase en DB
              (? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) 
               AND 
               ? > h.hora)
          )";

    $st_sol = $conn->prepare($sql_solape);
    $st_sol->bind_param('isssss', 
        $user_id, 
        $fecha_reserva, 
        $fecha_reserva, 
        $fecha_reserva, 
        $hora_inicio, 
        $hora_fin
    );
    $st_sol->execute();
    $res_sol = $st_sol->get_result();

    if ($res_sol->num_rows > 0) {
        $colision = $res_sol->fetch_assoc();
        $tipo_msg = ($colision['tipo_turno'] === 'fijo') ? "una suscripción fija" : "otra clase";
        throw new Exception("Ya tienes $tipo_msg que se solapa con este horario.");
    }

    // 4. VERIFICAR CUPO
    if ($h_data['reservas_actuales'] >= $h_data['capacidad']) {
        throw new Exception("Lo sentimos, ya no queda cupo para esta clase.");
    }

    // 5. INSERTAR RESERVA
    $sql = "INSERT INTO reservas (usuario_id, horario_id, fecha, estado, fecha_reserva, es_recurrente, observaciones)
            VALUES (?, ?, ?, 'confirmada', NOW(), 0, '')
            ON DUPLICATE KEY UPDATE estado = 'confirmada', fecha_reserva = NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $user_id, $horario_id, $fecha_reserva);
    
    if ($stmt->execute()) {
        $conn->query("UPDATE horarios SET reservas_actuales = reservas_actuales + 1 WHERE id = $horario_id");
        $_SESSION['mensaje_exito'] = "¡Reserva confirmada con éxito!";

        // NOTIFICACIONES (Turnos Extras)
        $id_sesion = (int)($_SESSION['user_id'] ?? 0);
        $id_alumno = (int)$user_id; 
        $id_usuario_profe = (int)($h_data['profesor_id'] ?? 0);

        // Obtener nombres completos del alumno y profesor
        $resNames = $conn->query("SELECT u.nombre as alu_n, u.apellido as alu_a, p.nombre as prof_n FROM usuarios u LEFT JOIN profesores p ON p.id = $id_usuario_profe WHERE u.id = $id_alumno");
        $names = $resNames->fetch_assoc();
        $nom_alu = trim(($names['alu_n'] ?? '') . ' ' . ($names['alu_a'] ?? ''));
        $nom_prof = $names['prof_n'] ?? 'Profesor';

        $dias = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
        $dia_esp = $dias[date('l', strtotime($fecha_reserva))] ?? '';
        $fecha_f = date('d/m', strtotime($fecha_reserva));
        $hora_f = substr($hora_inicio, 0, 5);
        $inst = $h_data['instrumento'] ?? 'clase';

        // 1. Notificar SIEMPRE al alumno
        $mensaje_alumno = "Se ha asignado un turno extra de $inst con $nom_prof para el $dia_esp $fecha_f a las $hora_f hs.";
        enviarNotificacion($conn, $id_alumno, $mensaje_alumno, 'success', 'mis-reservas.php');

        // 2. Notificar al profesor
        if ($id_usuario_profe > 0 && $id_sesion !== $id_usuario_profe) {
            $prefijo = ($id_sesion === $id_alumno) ? "El alumno $nom_alu" : "La administración";
            $mensaje_notif = "$prefijo ha reservado un turno extra de $inst para el $dia_esp $fecha_f a las $hora_f hs.";
            enviarNotificacion($conn, $id_usuario_profe, $mensaje_notif, 'info', 'mis-reservas.php');
        }
        
        // REGISTRO EN AUDITORÍA: Profesor asigna a alumno
        if ($id_sesion === $id_usuario_profe) {
            // Si el que hizo la reserva es el mismo profesor que la dicta
            $msg_audit = "El profesor $nom_prof ha asignado un turno extra de $inst al alumno $nom_alu para el $dia_esp $fecha_f a las $hora_f hs.";
            
            $stmtAudit = $conn->prepare("INSERT INTO notificaciones (usuario_id, mensaje, tipo, leido) VALUES (0, ?, 'info', 1)");
            $stmtAudit->bind_param("s", $msg_audit);
            $stmtAudit->execute();
            $stmtAudit->close();
        }

        // Estadísticas
        $sqlStats = "UPDATE usuarios SET 
                        total_reservas = total_reservas + 1,
                        primera_reserva = IFNULL(primera_reserva, ?),
                        ultima_reserva = GREATEST(IFNULL(ultima_reserva, ?), ?)
                     WHERE id = ?";
        $stStats = $conn->prepare($sqlStats);
        $stStats->bind_param("sssi", $fecha_reserva, $fecha_reserva, $fecha_reserva, $user_id);
        $stStats->execute();

        $conn->commit();
    } else {
        throw new Exception("Error al procesar la reserva.");
    }

} catch (Exception $e) {
    if (isset($conn)) { $conn->rollback(); }
    $_SESSION['mensaje_error'] = $e->getMessage();
}