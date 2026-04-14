<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires'); 

require_once __DIR__ . '/auth.php';
require_admin();

$db = Database::getInstance();
$conn = $db->getConnection();

// --- LÓGICA DE RETORNO DINÁMICO ---
$id = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? 'reservas.php'; 
// ----------------------------------

if ($id > 0) {
    $conn->begin_transaction();
    try {
        // Obtener datos antes de procesar
        $query = "SELECT r.*, u.nombre, u.apellido, h.id as hid, h.hora, h.instrumento, h.tipo_turno, p.nombre as prof_n, p.id as pid 
                  FROM reservas r 
                  JOIN horarios h ON r.horario_id = h.id 
                  JOIN usuarios u ON r.usuario_id = u.id
                  LEFT JOIN profesores p ON h.profesor_id = p.id
                  WHERE r.id = ?";
        $st = $conn->prepare($query);
        $st->bind_param('i', $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();

        if ($r) {
            $nombre_alu = trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? ''));
            $hoy = date('Y-m-d'); 
            $usuario_id = (int)$r['usuario_id'];

            // 1. Guardar en historial como 'cancelada'
            $stH = $conn->prepare("INSERT INTO historial_reservas (reserva_id, usuario_id, profesor_id, nombre_alumno, nombre_profesor, instrumento, fecha_clase, hora, tipo_turno, estado) VALUES (?,?,?,?,?,?,?,?,?,'cancelada')");
            $stH->bind_param('iiissssss', $id, $usuario_id, $r['pid'], $nombre_alu, $r['prof_n'], $r['instrumento'], $r['fecha'], $r['hora'], $r['tipo_turno']);
            $stH->execute();

            // 2. Borrar la reserva activa
            $conn->query("DELETE FROM reservas WHERE id = $id");
            // --- NOTIFICAR CANCELACIÓN A AMBOS ---
            $id_sesion = (int)($_SESSION['user_id'] ?? 0);
            $fecha_f = date('d/m', strtotime($r['fecha']));
            $hora_f = substr($r['hora'], 0, 5);
            $prof_id = (int)$r['pid'];
            
            if ($id_sesion !== $usuario_id) {
                $msg_alu = "La administración ha cancelado tu reserva de {$r['instrumento']} ({$r['tipo_turno']}) el $fecha_f a las $hora_f hs con {$r['prof_n']}.";
                enviarNotificacion($conn, $usuario_id, $msg_alu, 'danger', 'mis-reservas.php');
            }

            if ($prof_id > 0 && $id_sesion !== $prof_id) {
                $msg_pro = "La administración ha cancelado tu reserva de {$r['instrumento']} ({$r['tipo_turno']}) el $fecha_f a las $hora_f hs con $nombre_alu.";
                enviarNotificacion($conn, $prof_id, $msg_pro, 'danger', 'mis-reservas.php');
            }
            // --------------------------------------------

            // 3. Marcamos la suscripción como inactiva y disminuimos el contador del usuario
            $stS = $conn->prepare("UPDATE suscripciones SET activo = 0, fecha_fin = ? WHERE usuario_id = ? AND horario_id = ? AND activo = 1");
            $stS->bind_param('sii', $hoy, $usuario_id, $r['hid']);
            $stS->execute();

            // Si se desactivó una suscripción (affected_rows > 0), restamos 1 al usuario
            if ($stS->affected_rows > 0) {
                $conn->query("UPDATE usuarios SET tiene_suscripcion = GREATEST(0, tiene_suscripcion - 1) WHERE id = $usuario_id");
            }

            // 4. Liberar cupo en el horario
            $conn->query("UPDATE horarios SET reservas_actuales = GREATEST(0, reservas_actuales - 1) WHERE id = " . (int)$r['hid']);
            
            $conn->commit();
        }
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
    }
}

header('Location: ' . $from);
exit;