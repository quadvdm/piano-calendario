<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();
require_once dirname(__DIR__) . '/config/database.php';

$db   = Database::getInstance();
$conn = $db->getConnection();

$id = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$err = $_GET['error'] ?? '';

// --- ACCIÓN: ELIMINAR ---
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $profId = (int)($_GET['id'] ?? 0);
    if ($profId > 0 && isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
        try {
            $conn->begin_transaction();
            $resUser = $conn->query("SELECT rol FROM usuarios WHERE id = $profId");
            $userData = $resUser->fetch_assoc();
            $rolActual = $userData['rol'] ?? 'profesor';
            $rolRetorno = ($rolActual === 'admin-profesor') ? 'admin' : 'alumno';

            $db->query("UPDATE horarios SET profesor_id = NULL WHERE profesor_id = ?", [$profId]);
            $db->query("UPDATE usuarios SET rol = ? WHERE id = ?", [$rolRetorno, $profId]);
            $db->query("DELETE FROM profesores WHERE id = ?", [$profId]);
            // Notificar que se quitaron los permisos
            $id_sesion = (int)($_SESSION['user_id'] ?? 0);
            if ($id_sesion !== $profId) {
                $msg_baja = "Tus privilegios de profesor han sido revocados por la administración.";
                enviarNotificacion($conn, $profId, $msg_baja, 'danger', 'mis-reservas.php'); 
            }

            $conn->commit();
            header('Location: profesores1.php?msg=' . urlencode("Perfil de profesor quitado correctamente."));
            exit;
        } catch (Throwable $e) {
            if (isset($conn)) $conn->rollback();
            header('Location: profesores1.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// --- ACCIÓN: GUARDAR ---
$prof = ['nombre'=>'','especialidad'=>'','experiencia'=>'5 años','telefono'=>'','email'=>'','descripcion'=>'','activo'=>1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = ($id > 0) ? $id : (int)($_POST['usuario_id'] ?? 0);
    $nombre   = trim((string)$_POST['nombre']);
    
    if ($targetId <= 0) $err = 'Debe seleccionar un usuario.';
    elseif ($nombre === '') $err = 'Nombre requerido.';
    else {
        $instrumentos_seleccionados = $_POST['instrumentos_array'] ?? [];
        $especialidad = implode(', ', $instrumentos_seleccionados);

        $experiencia  = trim((string)$_POST['experiencia']);
        $telefono     = trim((string)$_POST['telefono']);
        $email        = trim((string)$_POST['email']);
        $descripcion  = trim((string)$_POST['descripcion']);
        
        // Forzamos siempre activo
        $siempre_activo = 1; 

        try {
            $conn->begin_transaction();

            if ($id > 0) {
                $st = $conn->prepare("UPDATE profesores SET nombre=?, especialidad=?, experiencia=?, telefono=?, email=?, descripcion=?, activo=? WHERE id=?");
                $st->bind_param('ssssssii', $nombre, $especialidad, $experiencia, $telefono, $email, $descripcion, $siempre_activo, $id);
                $st->execute(); 
                $st->close();
            } else {
                // INSERT: Nuevo profesor
                $resU = $conn->query("SELECT rol FROM usuarios WHERE id = $targetId");
                $uData = $resU->fetch_assoc();
                $nuevoRol = (($uData['rol'] ?? 'alumno') === 'admin') ? 'admin-profesor' : 'profesor';
                // Notificar el ascenso
                $id_sesion = (int)($_SESSION['user_id'] ?? 0);
                if ($id_sesion !== $targetId) {
                    $msg_ascenso = "Se te ha otorgado el rol de " . strtoupper($nuevoRol) . ". Ahora tienes acceso a herramientas de enseñanza.";
                    enviarNotificacion($conn, $targetId, $msg_ascenso, 'success', 'mis-reservas.php');
                }

                $stU = $conn->prepare("UPDATE usuarios SET rol = ?, nivel = 'Avanzado' WHERE id = ?");
                $stU->bind_param('si', $nuevoRol, $targetId);
                $stU->execute(); 
                $stU->close();
                
                $st = $conn->prepare("INSERT INTO profesores (id, nombre, especialidad, experiencia, telefono, email, descripcion, activo) VALUES (?,?,?,?,?,?,?,?)");
                $st->bind_param('issssssi', $targetId, $nombre, $especialidad, $experiencia, $telefono, $email, $descripcion, $siempre_activo);
                $st->execute(); 
                $st->close();
            }

            $conn->commit();
            header('Location: profesores1.php?msg=Cambios guardados con éxito');
            exit;
        } catch (Exception $e) {
            if (isset($conn)) $conn->rollback();
            $err = $e->getMessage();
        }
    }
}
// DATOS PARA LA VISTA
$instrumentos_db = [];
$resI = $conn->query("SELECT nombre FROM instrumentos ORDER BY nombre ASC");
while($inst = $resI->fetch_assoc()) $instrumentos_db[] = $inst['nombre'];

$candidatos = [];
if ($id === 0) {
    $resC = $conn->query("SELECT id, nombre, email FROM usuarios WHERE rol NOT IN ('profesor', 'admin-profesor') AND id NOT IN (SELECT id FROM profesores)");
    while($c = $resC->fetch_assoc()) $candidatos[] = $c;
}

if ($id > 0) {
    $st = $conn->prepare("SELECT * FROM profesores WHERE id=? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row) $prof = array_merge($prof, $row);
    $st->close();
}

// --- LÓGICA DE BÚSQUEDA Y PAGINACIÓN ---
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = " WHERE 1=1";
$params = []; 
$types = '';
if ($q !== '') {
    $where .= " AND (p.nombre LIKE ? OR p.especialidad LIKE ? OR p.email LIKE ?)";
    $like = "%{$q}%";
    $params = [$like, $like, $like]; 
    $types = 'sss';
}

// 1. Contar total de registros
$sqlCount = "SELECT COUNT(*) as total FROM profesores p $where";
$stCount = $conn->prepare($sqlCount);
if ($params) $stCount->bind_param($types, ...$params);
$stCount->execute();
$totalRows = (int)$stCount->get_result()->fetch_assoc()['total'];
$totalPages = (int)ceil($totalRows / $limit);

// 2. Obtener registros paginados
$sql = "SELECT p.id, p.nombre, p.especialidad, p.email, p.telefono, p.activo, p.descripcion,
        (SELECT COUNT(*) FROM horarios h WHERE h.profesor_id = p.id) as total_horarios
        FROM profesores p $where 
        ORDER BY p.id DESC 
        LIMIT $limit OFFSET $offset";

$stList = $conn->prepare($sql);
if ($params) $stList->bind_param($types, ...$params);
$stList->execute();
$resList = $stList->get_result();