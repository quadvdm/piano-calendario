<?php
require_once 'calendario_logic.php'; 

$db = Database::getInstance();
$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id <= 0) { header('Location: login.php'); exit; }


$sql = "SELECT u.*, p.email AS email_profesor, p.descripcion, p.clases_dictadas 
        FROM usuarios u 
        LEFT JOIN profesores p ON u.id = p.id 
        WHERE u.id = ?";
$res = $db->fetchAll($sql, [$user_id]);
$usuario_actual = $res[0] ?? [];

$rol_db = $usuario_actual['rol'] ?? 'alumno';
$rol_display = ($rol_db === 'profesor' || $rol_db === 'admin-profesor') ? 'profesor' : $rol_db;
$es_profe = ($rol_display === 'profesor');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_perfil'])) {
    $nuevo_nombre = $_POST['nombre'] ?? $usuario_actual['nombre'];
    $nuevo_apellido = $_POST['apellido'] ?? $usuario_actual['apellido'];
    $nuevo_tel = $_POST['telefono'] ?? '';
    $avatar_path = $usuario_actual['avatar'] ?? null;

    if (!empty($_FILES['avatar']['name'])) {
        $dir = "uploads/avatars/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $nombre_archivo = "user_" . $user_id . "_" . time() . "." . $ext;
        $ruta_final = $dir . $nombre_archivo;

        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $ruta_final)) {
            if (!empty($usuario_actual['avatar']) && file_exists($usuario_actual['avatar'])) {
                unlink($usuario_actual['avatar']);
            }
            $avatar_path = $ruta_final;
        }
    }

    $sqlU = "UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ?, avatar = ? WHERE id = ?";
    $db->query($sqlU, [$nuevo_nombre, $nuevo_apellido, $nuevo_tel, $avatar_path, $user_id]);

    if ($es_profe) {
        $email_alt = $_POST['email_profesor'] ?? '';
        $desc = $_POST['descripcion'] ?? '';
        $sqlP = "UPDATE profesores SET nombre = ?, telefono = ?, email = ?, descripcion = ? WHERE id = ?";
        $db->query($sqlP, [$nuevo_nombre, $nuevo_tel, $email_alt, $desc, $user_id]);
    }

    header("Location: perfil.php?success=1");
    exit;
}

include_once 'navbar.php';

$asistidas = (int)($usuario_actual['clases_asistidas'] ?? 0);
$dictadas  = (int)($usuario_actual['clases_dictadas'] ?? 0);

$sql_activas = "SELECT COUNT(*) as total FROM reservas WHERE usuario_id = ? AND estado IN ('confirmada', 'pendiente')";
$res_activas = $db->fetchAll($sql_activas, [$user_id]);
$total_activas = (int)($res_activas[0]['total'] ?? 0);

$mensaje_exito = isset($_GET['success']) ? "¡Perfil actualizado con éxito!" : null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Anima</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --bg-color: #131527; 
        --bg-darker: #0d101b;
        --glass-bg: rgba(255, 255, 255, 0.05); 
        --glass-border: rgba(255, 255, 255, 0.12); 
        --text: #f8fafc; 
        --muted: #cbd5e1; 
        --text-dim: #94a3b8;
        --accent: #8b5cf6; 
        --accent-glow: rgba(139, 92, 246, 0.5);
        --gradient-primary: linear-gradient(135deg, #8b5cf6, #6d28d9);
        --radius: 24px;
    }

    * { box-sizing: border-box; }

    body {
        background: 
            radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), 
            radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), 
            linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
        background-attachment: fixed;
        color: var(--text);
        font-family: 'Inter', sans-serif;
        margin: 0;
        padding-bottom: 50px;
        min-height: 100vh;
    }

    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
    .particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
    .particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
    .particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
    .particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }

    .container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
    
    .glass-card { 
        background: var(--glass-bg); 
        backdrop-filter: blur(20px); 
        -webkit-backdrop-filter: blur(20px); 
        border: 1px solid var(--glass-border); 
        border-radius: var(--radius); 
        padding: 35px; 
        position: relative; 
        box-shadow: 0 30px 60px rgba(0,0,0,0.5), 0 0 40px rgba(139, 92, 246, 0.1);
    }
    
    .profile-stats-grid {
        display: grid;
        grid-template-columns: 1fr 140px 1fr;
        align-items: center;
        gap: 15px;
        margin-bottom: 30px;
    }

    .stat-box { text-align: center; }
    .stat-number { display: block; font-size: clamp(1.5rem, 5vw, 2.2rem); font-weight: 900; color: #fff; line-height: 1; text-shadow: 0 2px 10px rgba(0,0,0,0.3); }
    .stat-label { font-size: 10px; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 1px; margin-top: 8px; display: block; text-shadow: 0 0 10px var(--accent-glow); }

    .avatar-wrapper { 
        width: clamp(100px, 25vw, 130px); height: clamp(100px, 25vw, 130px); 
        border-radius: 50%; margin: 0 auto; 
        padding: 4px; background: var(--gradient-primary); 
        position: relative; cursor: pointer; display: block;
        box-shadow: 0 0 25px var(--accent-glow);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .avatar-wrapper:hover { transform: scale(1.05); box-shadow: 0 0 35px rgba(139, 92, 246, 0.7); }
    
    .avatar-inner { width: 100%; height: 100%; border-radius: 50%; background: var(--bg-darker); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; overflow: hidden; color: #fff; font-weight: 900;}
    .avatar-inner img { width: 100%; height: 100%; object-fit: cover; }
    
    .upload-hint { position: absolute; bottom: 2px; right: 2px; background: var(--gradient-primary); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-darker); color: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.5); transition: transform 0.3s ease;}
    .avatar-wrapper:hover .upload-hint { transform: scale(1.1) rotate(5deg); }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .full-width { grid-column: span 2; }
    
    .form-label { font-size: 11px; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: block; margin-top: 15px; }
    
    .input-dark { width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--glass-border); padding: 14px 16px; border-radius: 14px; color: #fff; font-size: 14px; outline: none; transition: 0.3s; font-family: inherit; }
    .input-dark:focus { border-color: var(--accent); background: rgba(255,255,255,0.03); box-shadow: 0 0 15px rgba(139, 92, 246, 0.3); }
    .input-readonly { background: rgba(255,255,255,0.02); color: rgba(255,255,255,0.3); cursor: not-allowed; border-color: transparent; }

    .level-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; background: rgba(139, 92, 246, 0.15); color: #d8b4fe; border: 1px solid rgba(139, 92, 246, 0.3); margin-bottom: 5px; letter-spacing: 0.5px; }
    
    .btn-glow { background: var(--gradient-primary); color: #fff; border: none; padding: 16px; border-radius: 14px; font-weight: 800; cursor: pointer; width: 100%; text-transform: uppercase; transition: 0.3s; font-size: 13px; letter-spacing: 1px; box-shadow: 0 10px 25px var(--accent-glow); }
    .btn-glow:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(139, 92, 246, 0.7); }
    .btn-glow:active { transform: scale(0.98); }


    @media (max-width: 600px) {
        .container { margin: 20px auto; padding: 0 15px; }
        .glass-card { padding: 25px 20px; border-radius: 20px; }
        .profile-stats-grid { grid-template-columns: 1fr 110px 1fr; gap: 5px; margin-bottom: 20px;}
        .form-grid { grid-template-columns: 1fr; gap: 10px; }
        .full-width, .mobile-full { grid-column: span 1; }
        .stat-number { font-size: 1.4rem; }
        .stat-label { font-size: 8px; }
        
        #action-buttons {
            grid-template-columns: 1fr !important; 
            gap: 12px !important;
        }
    }
</style>
</head>
<body>
<div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
<div class="container">
    <?php if($mensaje_exito): ?>
        <div style="background:rgba(16,185,129,0.2); color:#10b981; padding:15px; border-radius:12px; margin-bottom:20px; text-align:center; border:1px solid #10b981; font-size: 14px;">
            <?= $mensaje_exito ?>
        </div>
    <?php endif; ?>

    <div class="glass-card">
        <form id="perfilForm" method="POST" enctype="multipart/form-data">
            
            <div class="profile-stats-grid">
                <div class="stat-box">
                    <?php if($asistidas > 0): ?>
                        <span class="stat-number"><?= $asistidas ?></span>
                        <span class="stat-label">Clases asistidas</span>
                    <?php endif; ?>
                </div>

                <div style="text-align: center;">
                    <label for="avatar_input" class="avatar-wrapper">
                        <div class="avatar-inner" id="avatar_preview">
                            <?php if(!empty($usuario_actual['avatar'])): ?>
                                <img src="<?= $usuario_actual['avatar'] ?>">
                            <?php else: ?>
                                <?= strtoupper(substr($usuario_actual['nombre'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <div class="upload-hint"><i class="fas fa-camera"></i></div>
                    </label>
                    <input type="file" name="avatar" id="avatar_input" hidden accept="image/*">
                </div>

                <div class="stat-box">
                    <?php if($es_profe && $dictadas > 0): ?>
                        <span class="stat-number"><?= $dictadas ?></span>
                        <span class="stat-label">Clases dictadas</span>
                    <?php elseif($total_activas > 0): ?>
                        <span class="stat-number"><?= $total_activas ?></span>
                        <span class="stat-label">Reservas activas</span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="text-align: center; margin-bottom: 25px;">
                <h2 style="margin: 0; font-weight: 900; letter-spacing: -0.5px; font-size: 1.5rem;">
                    <?= h($usuario_actual['nombre'] . ' ' . $usuario_actual['apellido']) ?>
                </h2>
                <div style="margin-top: 8px;">
                    <span class="level-badge"><?= h($usuario_actual['nivel'] ?? 'Nivel 1') ?></span>
                    <span class="level-badge" style="background:rgba(255,255,255,0.05); color:#94a3b8; margin-left:5px;">
                        <?= strtoupper($rol_display) ?>
                    </span>
                </div>
            </div>

            <div class="form-grid">
                <div class="full-width">
                    <label class="form-label">Gmail Principal</label>
                    <input type="text" class="input-dark input-readonly" value="<?= h($usuario_actual['email']) ?>" readonly>
                </div>

                <div class="mobile-full">
                    <label class="form-label">Nombre</label>
                    <input type="text" name="nombre" class="input-dark detect-change" value="<?= h($usuario_actual['nombre']) ?>" required>
                </div>
                <div class="mobile-full">
                    <label class="form-label">Apellido</label>
                    <input type="text" name="apellido" class="input-dark detect-change" value="<?= h($usuario_actual['apellido']) ?>">
                </div>

                <div class="<?= $es_profe ? 'mobile-full' : 'full-width' ?>">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" class="input-dark detect-change" value="<?= h($usuario_actual['telefono']) ?>">
                </div>

                <?php if($es_profe): ?>
                    <div class="mobile-full">
                        <label class="form-label">Email Público</label>
                        <input type="email" name="email_profesor" class="input-dark detect-change" value="<?= h($usuario_actual['email_profesor'] ?? '') ?>">
                    </div>
                    <div class="full-width">
                        <label class="form-label">Descripción Académica</label>
                        <textarea name="descripcion" class="input-dark detect-change" rows="3"><?= h($usuario_actual['descripcion'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
            </div>

            <div id="action-buttons" style="display: none; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 30px;">
                <button type="button" id="btn-cancelar" class="btn-glow" style="background: rgba(255,255,255,0.1);">
                    Cancelar
                </button>
                <button type="submit" name="actualizar_perfil" class="btn-glow">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById('perfilForm');
    const actionButtons = document.getElementById('action-buttons');
    const btnCancelar = document.getElementById('btn-cancelar');
    const avatarInput = document.getElementById('avatar_input');
    const avatarPreview = document.getElementById('avatar_preview');
    
    const initialValues = {};
    const inputs = form.querySelectorAll('.detect-change');
    const initialAvatarHTML = avatarPreview.innerHTML;

    inputs.forEach(input => {
        initialValues[input.name] = input.value;
        input.addEventListener('input', checkChanges);
    });

    avatarInput.onchange = function (evt) {
        const [file] = this.files;
        if (file) {
            avatarPreview.innerHTML = `<img src="${URL.createObjectURL(file)}">`;
            checkChanges();
        }
    };

    function checkChanges() {
        let hasChanged = false;
        inputs.forEach(input => {
            if (input.value !== initialValues[input.name]) hasChanged = true;
        });
        if (avatarInput.files.length > 0) hasChanged = true;
        
        actionButtons.style.display = hasChanged ? 'grid' : 'none';
    }

    btnCancelar.addEventListener('click', () => {
        inputs.forEach(input => { input.value = initialValues[input.name]; });
        avatarInput.value = ""; 
        avatarPreview.innerHTML = initialAvatarHTML;
        checkChanges();
    });
</script>
</body>
</html>