<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Tu error mostró que Database está en:
 * /public/config/database.php
 * Desde /public/admin/auth.php => ../config/database.php
 */
$databasePath = __DIR__ . '/../config/database.php';
if (!file_exists($databasePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: No se encontró config/database.php en: {$databasePath}";
    exit;
}

require_once $databasePath;

if (!class_exists('Database')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: Se incluyó {$databasePath} pero no existe la clase Database.";
    exit;
}

/**
 * Validación real contra DB:
 * - requiere sesión de login normal (Google)
 * - consulta usuarios.rol
 */
function require_admin(): void
{
    if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    $db = Database::getInstance();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT rol, activo, nombre, email FROM usuarios WHERE id = ? LIMIT 1");
    if (!$stmt) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: No se pudo preparar la consulta de usuario (auth admin).";
        exit;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || (int)($row['activo'] ?? 0) !== 1) {
        session_destroy();
        header('Location: ../login.php');
        exit;
    }

    $rolActual = (string)($row['rol'] ?? '');
    
    // Si no es admin o admin-profesor, lo mandamos al dashboard 
    if ($rolActual !== 'admin' && $rolActual !== 'admin-profesor') {
        header('Location: ../dashboard.php');
        exit;
    }

    // Datos útiles para UI admin
    $_SESSION['is_admin']   = true;
    $_SESSION['user_role']  = $rolActual; 
    $_SESSION['user_name']  = (string)($row['nombre'] ?? 'Administrador');
    $_SESSION['user_email'] = (string)($row['email'] ?? '');

    // Datos útiles para UI admin
    $_SESSION['is_admin']   = true;
    $_SESSION['user_role']  = $rolActual;
    $_SESSION['user_name']  = (string)($row['nombre'] ?? 'Administrador');
    $_SESSION['user_email'] = (string)($row['email'] ?? '');
}
