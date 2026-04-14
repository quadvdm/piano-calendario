<?php
// admin/horarios-eliminar.php — ELIMINAR HORARIO 
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/auth.php';

$db = Database::getInstance();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: horarios.php?error=' . urlencode('ID inválido'));
    exit;
}

if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    header('Location: horarios.php?error=' . urlencode('Falta confirmación'));
    exit;
}

try {
    $conn = $db->getConnection();

    // 1. Verificar si hay RESERVAS vinculadas
    // Si hay reservas, es peligroso borrar el horario porque el alumno no sabrá qué clase tiene.
    $reservas = $db->fetchAll("SELECT id FROM reservas WHERE horario_id = ? LIMIT 1", [$id]);
    
    if (!empty($reservas)) {
        header('Location: horarios.php?error=' . urlencode('No se puede eliminar: El horario tiene reservas realizadas. Cancela o traslada las reservas primero.'));
        exit;
    }

    // 2. Iniciar transacción para asegurar que no se borre nada si algo falla
    $conn->begin_transaction();

    // 3. Obtenemos info para el mensaje antes de borrar
    $h = $db->fetchAll("SELECT dia_semana, hora, instrumento, profesor_id FROM horarios WHERE id = ? LIMIT 1", [$id]);
    if (!$h) {
        throw new Exception("El horario ya no existe.");
    }
    $desc = $h[0]['dia_semana'] . ' ' . substr($h[0]['hora'],0,5) . ' — ' . ($h[0]['instrumento'] ?? 'Clase');

    // 4. Desvincular suscripciones (Poner horario_id en NULL)
    $db->query("UPDATE suscripciones SET horario_id = NULL, activo = 0 WHERE horario_id = ?", [$id]);

    // 5. Borrar el horario
    $db->query("DELETE FROM horarios WHERE id = ?", [$id]);

    // --- NOTIFICAR AL PROFESOR DE LA ELIMINACIÓN ---
    $id_sesion = (int)($_SESSION['user_id'] ?? 0);
    $prof_id_afectado = (int)($h[0]['profesor_id'] ?? 0);
    if ($prof_id_afectado > 0 && $id_sesion !== $prof_id_afectado) {
        $hora_f = substr($h[0]['hora'], 0, 5);
        $msg_prof = "La administración ha eliminado tu horario de {$h[0]['instrumento']} los {$h[0]['dia_semana']} a las {$hora_f} hs.";
        enviarNotificacion($conn, $prof_id_afectado, $msg_prof, 'danger', 'mis-reservas.php');
    }
    // ------------------------------------------------------

    // 6. Confirmar cambios
    $conn->commit();

    header('Location: horarios.php?msg=' . urlencode("Horario eliminado correctamente y suscripciones desvinculadas: {$desc}"));
    exit;

} catch (Throwable $e) {
    try {
        if (isset($conn)) {
            $conn->rollback();
        }
    } catch (Exception $rollbackEx) {
            // Ignorar error de rollback
    }
    
    header('Location: horarios.php?error=' . urlencode('Error técnico: ' . $e->getMessage()));
    exit;
}