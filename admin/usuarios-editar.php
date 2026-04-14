<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();

$db   = Database::getInstance();
$conn = $db->getConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: usuarios.php'); exit; }

// 1. CARGAR DATOS
$st = $conn->prepare("SELECT id, email, nombre, apellido, telefono, nivel, rol FROM usuarios WHERE id = ? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();

if (!$u) { header('Location: usuarios.php'); exit; }

$msg = ''; $err = '';

// 2. PROCESAR ACCIÓN: ÚNICAMENTE ACTUALIZACIÓN DE INFORMACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_info') {
        $nombre   = trim((string)$_POST['nombre']);
        $apellido = trim((string)$_POST['apellido']);
        $telefono = trim((string)$_POST['telefono']);
        $nivel    = (string)$_POST['nivel'];

        if (empty($nombre)) {
            $err = "El nombre es obligatorio.";
        } else {
            $upd = $conn->prepare("UPDATE usuarios SET nombre=?, apellido=?, telefono=?, nivel=? WHERE id=?");
            $upd->bind_param('ssssi', $nombre, $apellido, $telefono, $nivel, $id);
            if ($upd->execute()) {
                header('Location: usuarios.php?msg=updated'); 
                exit;
            } else { $err = "Error al guardar cambios."; }
            $upd->close();
        }
    }
}

require_once __DIR__ . '/header.php';

$nombreVisual = htmlspecialchars(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')));
$currentRole = $u['rol'];
?>

<style>
    .container-edit { max-width: 600px; margin: 0 auto; display: flex; flex-direction: column; align-items: center; }
    .header-edit { text-align: center; margin-bottom: 30px; width: 100%; }
    .card { border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.03); border-radius: 20px; padding: 30px; width: 100%; box-sizing: border-box; }
    
    .role-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 900; text-transform: uppercase; margin-top: 10px; border: 1px solid; }
    .role-alumno { background: rgba(245,158,11,.1); color: #fcd34d; border-color: rgba(245,158,11,.3); }
    .role-profesor { background: rgba(139,92,246,.1); color: #c4b5fd; border-color: rgba(139,92,246,.3); }
    .role-admin { background: rgba(99,102,241,.1); color: #a5b4fc; border-color: rgba(99,102,241,.3); }
    .role-admin-profesor { background: rgba(167,139,250,.1); color: #ddd6fe; border-color: rgba(167,139,250,.3); }

    label { display: block; font-weight: 900; font-size: 11px; color: #9ca3af; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .input, select { width: 100%; padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,.14); background: rgba(0,0,0,.3); color: #fff; margin-bottom: 15px; box-sizing: border-box; }
    .row-nombres { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; width: 100%; }
    .btn-save { background: #4f46e5; color: white; border: none; padding: 16px; border-radius: 12px; font-weight: 900; cursor: pointer; width: 100%; transition: 0.2s; font-size: 15px; }
    .btn-save:hover { background: #4338ca; transform: translateY(-1px); }

    .bad { color: #fca5a5; background: rgba(239,68,68,.1); padding: 12px; border-radius: 10px; margin-bottom: 20px; border: 1px solid rgba(239,68,68,0.2); text-align: center; width: 100%; }
</style>

<div class="container-edit">
    <div class="header-edit">
        <h1>Editar Usuario</h1>
        <p style="color: #9ca3af; margin-bottom: 5px;">Gestionando perfil de: <strong><?= $nombreVisual ?></strong></p>
        <span class="role-badge role-<?= $currentRole ?>">Rol: <?= $currentRole ?></span>
    </div>

    <div class="card">
        <?php if($err): ?><div class="bad">✗ <?= $err ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="update_info">
            
            <label>Correo Electrónico (No editable)</label>
            <input type="text" class="input" value="<?= htmlspecialchars($u['email']) ?>" disabled style="opacity: 0.6; cursor: not-allowed;">

            <div class="row-nombres">
                <div>
                    <label>Nombre</label>
                    <input type="text" name="nombre" class="input" value="<?= htmlspecialchars($u['nombre'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Apellido</label>
                    <input type="text" name="apellido" class="input" value="<?= htmlspecialchars($u['apellido'] ?? '') ?>">
                </div>
            </div>

            <label>Teléfono de Contacto</label>
            <input type="text" name="telefono" class="input" value="<?= htmlspecialchars($u['telefono'] ?? '') ?>" placeholder="Ej: +54 9 11 ...">

            <label>Nivel de Conocimiento</label>
            <select name="nivel" class="input">
                <option value="principiante" <?= ($u['nivel'] ?? '') === 'principiante' ? 'selected' : '' ?>>Principiante</option>
                <option value="intermedio" <?= ($u['nivel'] ?? '') === 'intermedio' ? 'selected' : '' ?>>Intermedio</option>
                <option value="avanzado" <?= ($u['nivel'] ?? '') === 'avanzado' ? 'selected' : '' ?>>Avanzado</option>
            </select>

            <button type="submit" class="btn-save">Guardar Cambios</button>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="usuarios.php" style="color:#9ca3af; text-decoration:none; font-weight: bold; font-size: 13px; opacity: 0.7;">
                    ← Volver a la lista sin guardar
                </a>
            </div>
        </form>
    </div>
    
    <p style="font-size: 11px; color: #64748b; margin-top: 20px; text-align: center; max-width: 400px;">
        * Para cambiar el rol de un usuario a Profesor, utiliza el módulo de <a href="profesores1.php" style="color: #8b5cf6;">Profesores</a>. 
        Los roles de Administrador se gestionan directamente por base de datos por seguridad.
    </p>
</div>