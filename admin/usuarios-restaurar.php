<?php
// admin/usuarios-restaurar.php — CANCELAR ELIMINACIÓN Y RESTAURAR
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/auth.php';
require_admin();

$db = Database::getInstance();
$conn = $db->getConnection();

$usuarioId = (int)($_GET['id'] ?? 0);

if ($usuarioId <= 0) {
    header('Location: usuarios.php?error=' . urlencode('ID de usuario no válido.'));
    exit;
}

try {
    // 1. Verificamos que el usuario exista
    $check = $db->fetchAll("SELECT email FROM usuarios WHERE id = ? LIMIT 1", [$usuarioId]);
    if (!$check) {
        throw new Exception("El usuario no existe.");
    }

    $email = $check[0]['email'];

    // 2. Restauramos: ponemos fecha_eliminacion en NULL y activo en 1
    $sql = "UPDATE usuarios SET fecha_eliminacion = NULL, activo = 1 WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $usuarioId);
    
    if ($stmt->execute()) {
        header('Location: usuarios.php?msg=' . urlencode("Usuario $email restaurado correctamente."));
    } else {
        throw new Exception("No se pudo actualizar el registro en la base de datos.");
    }

} catch (Exception $e) {
    header('Location: usuarios.php?error=' . urlencode($e->getMessage()));
}
exit;