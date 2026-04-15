<?php
// procesar-suscripcion.php - Lógica para Turnos Fijos
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (!isset($db)) {
    require_once 'config/database.php';
    $db = Database::getInstance();
}
$conn = $db->getConnection();

$horario_id = (int)($_POST['horario_id'] ?? 0);
$fecha_inicio = $_POST['fecha_seleccionada'] ?? date('Y-m-d');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_rol_actual = strtolower($_SESSION['user_rol'] ?? 'alumno');

if (!empty($_POST['alumno_id']) && in_array($user_rol_actual, ['profesor', 'admin-profesor'])) {
    $user_id = (int)$_POST['alumno_id'];
}

try {
    if ($horario_id <= 0) throw new Exception("ID de horario no válido.");

    $conn->begin_transaction();

    // 1. OBTENER DATOS DEL HORARIO
    $st_h = $conn->prepare("SELECT hora, duracion_minutos, profesor_id, instrumento, capacidad, reservas_actuales FROM horarios WHERE id = ?");
    $st_h->bind_param('i', $horario_id);
    $st_h->execute();
    $h_data = $st_h->get_result()->fetch_assoc();
    
    if (!$h_data) throw new Exception("Horario no encontrado.");
    if ($h_data['reservas_actuales'] >= $h_data['capacidad']) throw new Exception("Ya no queda cupo para esta suscripción fija.");

    $hora_inicio = $h_data['hora'];
    $duracion_nueva = (int)$h_data['duracion_minutos'];
    $hora_fin = date('H:i:s', strtotime($hora_inicio . " + $duracion_nueva minutes"));

    // 2. VALIDACIÓN DE LÍMITE SEMANAL
    $resConfig = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'max_reservas_semana' LIMIT 1");
    $max_permitido = (int)($resConfig->fetch_assoc()['valor'] ?? 2);

    $fecha_obj = new DateTime($fecha_inicio);
    $lunes = (clone $fecha_obj)->modify('monday this week')->format('Y-m-d');
    $domingo = (clone $fecha_obj)->modify('sunday this week')->format('Y-m-d');

    $sql_count = "SELECT COUNT(*) as total FROM reservas 
                  WHERE usuario_id = ? AND fecha BETWEEN ? AND ? 
                  AND estado IN ('confirmada', 'pendiente')";
    $st_count = $conn->prepare($sql_count);
    $st_count->bind_param('iss', $user_id, $lunes, $domingo);
    $st_count->execute();
    $actuales = $st_count->get_result()->fetch_assoc()['total'];

    if ($actuales >= $max_permitido) {
        throw new Exception("Límite semanal alcanzado ($max_permitido). Ya tienes clases en la semana del " . $fecha_obj->format('d/m'));
    }

    // 3. VALIDACIÓN DE SOLAPAMIENTO
$sql_solape = "
    SELECT r.id, h.tipo_turno, r.fecha 
    FROM reservas r 
    JOIN horarios h ON r.horario_id = h.id
    WHERE r.usuario_id = ? 
      AND r.estado != 'cancelada'
      AND DAYOFWEEK(r.fecha) = DAYOFWEEK(?)
      AND (
          -- Caso A: Choca con otra suscripción FIJA (Se bloquean siempre)
          h.tipo_turno = 'fijo'
          OR
          -- Caso B: Choca con un turno EXTRA, pero SOLO si el extra es 
          -- en la misma fecha o después de que empiece esta nueva suscripción.
          (h.tipo_turno = 'extra' AND r.fecha >= ?)
      )
      AND (
          ? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) 
          AND 
          ? > h.hora
      )";

$st_sol = $conn->prepare($sql_solape);
$st_sol->bind_param('issss', $user_id, $fecha_inicio, $fecha_inicio, $hora_inicio, $hora_fin);
$st_sol->execute();
$res_sol = $st_sol->get_result();

    if ($res_sol->num_rows > 0) {
    $colision = $res_sol->fetch_assoc();
    $es_fijo = ($colision['tipo_turno'] === 'fijo');
    
    if ($es_fijo) {
        $msg = "No puedes suscribirte: ya tienes otra suscripción fija activa en este horario.";
    } else {
        // Para turnos extras, mostramos la fecha exacta del conflicto
        $fecha_conflicto = date('d/m/Y', strtotime($colision['fecha']));
        $msg = "No puedes suscribirte: tienes un turno extra el día $fecha_conflicto que se solapa con el inicio de esta suscripción.";
    }
    
    throw new Exception($msg);
}

    // 4. PROCESAR SUSCRIPCIÓN
    $checkSus = $db->fetchAll("SELECT id, activo FROM suscripciones WHERE usuario_id = ? AND horario_id = ?", [$user_id, $horario_id]);
    $es_nueva_activacion = false;
    $sus_id = 0;

    if ($checkSus) {
        $sus_id = (int)$checkSus[0]['id'];
        if ((int)$checkSus[0]['activo'] === 0) $es_nueva_activacion = true;
        $conn->query("UPDATE suscripciones SET activo = 1, fecha_inicio = '$fecha_inicio', fecha_fin = NULL WHERE id = $sus_id");
    } else {
        $conn->query("INSERT INTO suscripciones (usuario_id, horario_id, fecha_inicio, activo) VALUES ($user_id, $horario_id, '$fecha_inicio', 1)");
        $sus_id = (int)$conn->insert_id;
        $es_nueva_activacion = true;
    }

    // 5. INSERTAR O ACTUALIZAR RESERVA
    $observaciones = trim((string)($_POST['observaciones'] ?? '')); 

    $sqlReserva = "INSERT INTO reservas (usuario_id, horario_id, fecha, estado, es_recurrente, suscripcion_id, observaciones)
                   VALUES (?, ?, ?, 'confirmada', 1, ?, ?)
                   ON DUPLICATE KEY UPDATE estado = 'confirmada', es_recurrente = 1, suscripcion_id = ?, observaciones = ?";
    $stmt = $conn->prepare($sqlReserva);
    $stmt->bind_param("iisiiss", $user_id, $horario_id, $fecha_inicio, $sus_id, $observaciones, $sus_id, $observaciones);
    $stmt->execute();

    // 6. ESTADÍSTICAS Y CONTADORES
    $sqlStats = "UPDATE usuarios SET 
                    total_reservas = total_reservas + 1,
                    primera_reserva = IFNULL(primera_reserva, ?),
                    ultima_reserva = GREATEST(IFNULL(ultima_reserva, ?), ?)
                 WHERE id = ?";
    $stStats = $conn->prepare($sqlStats);
    $stStats->bind_param("sssi", $fecha_inicio, $fecha_inicio, $fecha_inicio, $user_id);
    $stStats->execute();

    if ($es_nueva_activacion) {
        $conn->query("UPDATE usuarios SET tiene_suscripcion = tiene_suscripcion + 1 WHERE id = $user_id");
    }
    $conn->query("UPDATE horarios SET reservas_actuales = reservas_actuales + 1 WHERE id = $horario_id");


    // NOTIFICACIONES (Turnos Fijos)
    $id_sesion = (int)($_SESSION['user_id'] ?? 0);
    $id_alumno = (int)$user_id; 
    $id_usuario_profe = (int)($h_data['profesor_id'] ?? 0);

    // Extraer nombres
    $resNames = $conn->query("SELECT u.nombre as alu_n, u.apellido as alu_a, p.nombre as prof_n FROM usuarios u LEFT JOIN profesores p ON p.id = $id_usuario_profe WHERE u.id = $id_alumno");
    $names = $resNames->fetch_assoc();
    $nom_alu = trim(($names['alu_n'] ?? '') . ' ' . ($names['alu_a'] ?? ''));
    $nom_prof = $names['prof_n'] ?? 'Profesor';

    $dias = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
    $dia_f_esp = $dias[date('l', strtotime($fecha_inicio))] ?? '';
    $hora_f = substr($hora_inicio, 0, 5);
    $inst = $h_data['instrumento'] ?? 'clase';

    // 1. Notificar al alumno
    $msg_alumno = "Se ha asignado una suscripción fija de $inst con $nom_prof para los días $dia_f_esp a las $hora_f hs.";
    enviarNotificacion($conn, $id_alumno, $msg_alumno, 'success', 'mis-reservas.php');

    // 1.5 Notificación EXCLUSIVA de la nota
    if (!empty($observaciones)) {
        $msg_obs = "Nota sobre tu suscripción fija de $inst ($dia_f_esp): \"$observaciones\"";
        enviarNotificacion($conn, $id_alumno, $msg_obs, 'info', 'mis-reservas.php');
    }

    // 2. Notificar al profesor
    if ($id_usuario_profe > 0 && $id_sesion !== $id_usuario_profe) {
        $prefijo = ($id_sesion === $id_alumno) ? "El alumno $nom_alu" : "La administración";
        enviarNotificacion($conn, $id_usuario_profe, "$prefijo ha iniciado una suscripción fija de $inst para los días $dia_f_esp a las $hora_f hs.", 'info', 'mis-reservas.php');
    }
    
    // REGISTRO: Profesor asigna suscripción fija
    if ($id_sesion === $id_usuario_profe) {
        // Si el que hizo la reserva es el mismo profesor que la dicta
        $msg_audit = "El profesor $nom_prof ha asignado una suscripción fija de $inst al alumno $nom_alu para los días $dia_f_esp a las $hora_f hs.";
        
        $stmtAudit = $conn->prepare("INSERT INTO notificaciones (usuario_id, mensaje, tipo, leido) VALUES (0, ?, 'success', 1)");
        $stmtAudit->bind_param("s", $msg_audit);
        $stmtAudit->execute();
        $stmtAudit->close();
    }
    
    $conn->commit();
    $_SESSION['mensaje_exito'] = "¡Suscripción fija activada correctamente!";

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    $_SESSION['mensaje_error'] = $e->getMessage();
}