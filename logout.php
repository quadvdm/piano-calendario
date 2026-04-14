<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vaciar sesión
$_SESSION = [];

// Borrar cookie de sesión (si existe)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        (bool)$params["secure"], (bool)$params["httponly"]
    );
}

session_destroy();

header('Location: index.php');
exit;
