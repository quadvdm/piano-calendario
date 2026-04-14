<?php
declare(strict_types=1);
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
        // Traemos los datos necesarios, incluyendo el horario_id
        $query = "SELECT r.*, u.nombre, u.apellido, h.hora, h.instrumento, h.tipo_turno, p.nombre as prof_n 
                  FROM reservas r 
                  JOIN horarios h ON r.horario_id = h.id 
                  JOIN usuarios u ON r.usuario_id = u.id
                  LEFT JOIN profesores p ON h.profesor_id = p.id
                  WHERE r.id = ? AND h.tipo_turno = 'fijo'";
        
        $st = $conn->prepare($query);
        $st->bind_param('i', $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();

        if ($r) {
            $fecha_vieja = $r['fecha'];
            $nueva_fecha = date('Y-m-d', strtotime($fecha_vieja . " + 7 days"));
            $nombre_alu = trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? ''));
            $horario_id = (int)$r['horario_id'];

            // 1. Guardar en historial como 'trasladada'
            $stH = $conn->prepare("INSERT INTO historial_reservas (reserva_id, usuario_id, profesor_id, nombre_alumno, nombre_profesor, instrumento, fecha_clase, hora, tipo_turno, estado) VALUES (?,?,?,?,?,?,?,?,?,'trasladada')");
            $stH->bind_param('iiissssss', $id, $r['usuario_id'], $r['horario_id'], $nombre_alu, $r['prof_n'], $r['instrumento'], $fecha_vieja, $r['hora'], $r['tipo_turno']);
            $stH->execute();

            // 2. Actualizar la fecha en la tabla RESERVAS
            $stR = $conn->prepare("UPDATE reservas SET fecha = ? WHERE id = ?");
            $stR->bind_param('si', $nueva_fecha, $id);
            $stR->execute();

            // --- NOTIFICAR TRASLADO A AMBOS ---
            $id_sesion = (int)($_SESSION['user_id'] ?? 0);
            $nueva_f = date('d/m', strtotime($nueva_fecha));
            $prof_id = (int)$r['profesor_id'];

            if ($id_sesion !== (int)$r['usuario_id']) {
                $msg_alu = "La clase de {$r['instrumento']} con {$r['prof_n']} ha sido trasladada por la administración al día $nueva_f.";
                enviarNotificacion($conn, (int)$r['usuario_id'], $msg_alu, 'warning', 'mis-reservas.php');
            }

            if ($prof_id > 0 && $id_sesion !== $prof_id) {
                $msg_pro = "Tu clase de {$r['instrumento']} con $nombre_alu ha sido trasladada por la administración al día $nueva_f.";
                enviarNotificacion($conn, $prof_id, $msg_pro, 'info', 'mis-reservas.php');
            }

            // 3. Sincronizar la tabla HORARIOS
            $stHor = $conn->prepare("UPDATE horarios SET fecha_especifica = ?, reservas_actuales = 1 WHERE id = ?");
            $stHor->bind_param('si', $nueva_fecha, $horario_id);
            $stHor->execute();

            $conn->commit();
        }
    } catch (Exception $e) {
        $conn->rollback();
        // Opcional: podrías guardar el error en una sesión para mostrarlo luego
    }
}

header('Location: ' . $from);
exit;