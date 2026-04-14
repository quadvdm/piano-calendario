<?php
// admin/usuarios-eliminar.php — PROGRAMAR ELIMINACIÓN (BORRADO SUAVE)
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/auth.php';

$db = Database::getInstance();
$usuarioId = (int)($_GET['id'] ?? 0);

if ($usuarioId <= 0) {
    header('Location: usuarios.php?error=' . urlencode('ID inválido'));
    exit;
}

// Evitar programar tu propia eliminación
if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $usuarioId) {
    header('Location: usuarios.php?error=' . urlencode('No podés eliminar tu propio usuario'));
    exit;
}

// Confirmación necesaria
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    header('Location: usuarios.php?error=' . urlencode('Falta confirmación'));
    exit;
}

try {
    $conn = $db->getConnection();

    // 1. Obtenemos el email para el mensaje de éxito
    $u = $db->fetchAll("SELECT email FROM usuarios WHERE id = ? LIMIT 1", [$usuarioId]);
    if (!$u) {
        throw new Exception("Usuario no encontrado");
    }
    $email = $u[0]['email'];

    // 2. Hacemos un UPDATE
    // Seteamos la fecha de eliminación para dentro de 7 días
    $sql = "UPDATE usuarios SET fecha_eliminacion = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $usuarioId);
    
    if ($stmt->execute()) {
        header('Location: usuarios.php?msg=' . urlencode("Eliminación programada para: {$email} (7 días restantes)"));
    } else {
        throw new Exception("Error al ejecutar la actualización");
    }
    exit;

} catch (Throwable $e) {
    header('Location: usuarios.php?error=' . urlencode('Error al programar eliminación: ' . $e->getMessage()));
    exit;
}