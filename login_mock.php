<?php
// login_mock.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

// 1. Elegimos qué usuario queremos simular (puedes cambiar este ID según tus pruebas)
// Ejemplo: Cambia a 1, 2, 3 según los usuarios que tengas en tu tabla 'usuarios'
$id_a_simular = 7; 

$db = Database::getInstance();
$conn = $db->getConnection();

// 2. Buscamos al usuario en la DB para que las variables sean reales
$st = $conn->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ?");
$st->bind_param('i', $id_a_simular);
$st->execute();
$usuario = $st->get_result()->fetch_assoc();
$st->close();

if ($usuario) {
    // 3. Seteamos la sesión exactamente como lo haría tu login de Google real
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = (int)$usuario['id'];
    $_SESSION['user_nombre'] = $usuario['nombre'];
    $_SESSION['user_email'] = $usuario['email'];
    $_SESSION['user_rol'] = $usuario['rol']; // Traemos el rol real de la DB

    // 4. Redirigimos al dashboard
    header('Location: dashboard.php');
    exit;
} else {
    die("Error: No se encontró el usuario con ID $id_a_simular en la base de datos.");
}
?>