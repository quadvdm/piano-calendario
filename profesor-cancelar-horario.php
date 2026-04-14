<?php
// profesor-cancelar-horario.php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

session_start();
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$user_id = (int)($_SESSION['user_id'] ?? 0);
$userRol = strtolower($_SESSION['user_rol'] ?? 'alumno');

// SEGURIDAD: Solo profesores o admin-profesores
if ($userRol !== 'profesor' && $userRol !== 'admin-profesor') {
    header("Location: dashboard.php");
    exit;
}

$id_horario = (int)($_GET['id'] ?? 0);

if ($id_horario <= 0) {
    header('Location: crear-reserva.php?error=' . urlencode('ID de horario inválido.'));
    exit;
}

try {
    // 1. Verificar que el horario pertenezca al profesor logueado
    $query = "SELECT id, dia_semana, hora, instrumento, profesor_id FROM horarios WHERE id = ? AND profesor_id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $id_horario, $user_id);
    $stmt->execute();
    $h = $stmt->get_result()->fetch_assoc();

    if (!$h) {
        throw new Exception("El horario no existe o no tienes permiso para eliminarlo.");
    }

    // 2. Verificar si hay RESERVAS activas vinculadas
    $stmtRes = $conn->prepare("SELECT id FROM reservas WHERE horario_id = ? AND estado IN ('confirmada', 'pendiente') LIMIT 1");
    $stmtRes->bind_param('i', $id_horario);
    $stmtRes->execute();
    if ($stmtRes->get_result()->num_rows > 0) {
        throw new Exception('No se puede eliminar: El horario tiene reservas activas.');
    }

    $conn->begin_transaction();

    // 3. Desvincular suscripciones inactivas
    $stmtSus = $conn->prepare("UPDATE suscripciones SET horario_id = NULL, activo = 0 WHERE horario_id = ?");
    $stmtSus->bind_param('i', $id_horario);
    $stmtSus->execute();

    // 4. Borrar el horario
    $stmtDel = $conn->prepare("DELETE FROM horarios WHERE id = ?");
    $stmtDel->bind_param('i', $id_horario);
    $stmtDel->execute();

    // 5. AUDITORÍA ADMINISTRATIVA (usuario_id = 0 para que sea global)
    $nombre_profe = $_SESSION['user_nombre'] ?? 'Un profesor';
    $hora_str = substr($h['hora'], 0, 5);
    $msg_audit = "El profesor $nombre_profe ha eliminado manualmente su horario disponible de {$h['instrumento']} los {$h['dia_semana']} a las $hora_str hs.";
    
    $stmtAudit = $conn->prepare("INSERT INTO notificaciones (usuario_id, mensaje, tipo, leido) VALUES (0, ?, 'danger', 1)");
    $stmtAudit->bind_param("s", $msg_audit);
    $stmtAudit->execute();
    
    $conn->commit();
    header('Location: crear-reserva.php?msg=' . urlencode("Horario eliminado correctamente."));
    exit;

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    header('Location: crear-reserva.php?error=' . urlencode($e->getMessage()));
    exit;
}