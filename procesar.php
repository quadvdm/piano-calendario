<?php
// procesar.php - Enrutador Principal
require_once 'config/database.php';
session_start();

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$accion = $_POST['accion'] ?? 'reservar';
$redirect = $_POST['from'] ?? 'index.php';

try {
    switch ($accion) {
        case 'reservar':
            $tipo_turno = strtolower($_POST['tipo_turno_elegido'] ?? ''); 
            if ($tipo_turno === 'extra') {
                require_once 'procesar-reserva.php';
            } elseif ($tipo_turno === 'fijo') {
                require_once 'procesar-suscripcion.php';
            }
            break;

        case 'cancelar':
            if (!empty($_POST['suscripcion_id']) && (int)$_POST['suscripcion_id'] > 0) {
                require_once 'procesar-cancelar-suscripcion.php';
            } else {
                require_once 'procesar-cancelacion.php';
            }
            break;

        case 'trasladar':
            require_once 'procesar-pasar-semana.php';
            break;

        case 'editar_clase':
            require_once 'procesar-editar-clase.php';
            break;

        default:
            $_SESSION['mensaje_error'] = "Acción no reconocida.";
            header("Location: mis-reservas.php");
            exit;
    }

    // --- LÓGICA DE MENSAJES DINÁMICA ---
    $origen = $_POST['from'] ?? 'index.php';
    
    if ($origen === 'calendario.php') {
        // Prioridad al mensaje de error si existe (por ejemplo, el del límite semanal)
        if (isset($_SESSION['mensaje_error'])) {
            $_SESSION['msg_calendario'] = $_SESSION['mensaje_error'];
            unset($_SESSION['mensaje_error']);
        } else {
            // Si no hay error, usamos el de éxito que viene de los archivos de procesamiento
            $_SESSION['msg_calendario'] = $_SESSION['mensaje_exito'] ?? "Reserva confirmada en el calendario.";
            unset($_SESSION['mensaje_exito']);
        }
    } elseif ($origen === 'profesores.php') {
        $_SESSION['msg_profesores'] = "Operación realizada con éxito.";
    } elseif ($origen === 'alumnos.php') {
        $_SESSION['msg_alumnos'] = "Operación realizada correctamente.";
    } else {
        $_SESSION['mensaje_exito'] = "Operación exitosa.";
    }

    header("Location: $origen");
    exit;

} catch (Exception $e) {
    // Si algo falló en los procesar-*.php, capturamos el mensaje real aquí
    if (isset($conn)) $conn->rollback();
    
    $msg_error = $e->getMessage();
    $origen = $_POST['from'] ?? 'index.php';

    if ($origen === 'calendario.php') {
        $_SESSION['msg_calendario'] = $msg_error;
    } else {
        $_SESSION['mensaje_error'] = $msg_error;
    }

    header("Location: " . $origen);
    exit;
}