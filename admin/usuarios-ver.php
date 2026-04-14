<?php
// admin/usuarios-ver.php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();

$db = Database::getInstance();
$conn = $db->getConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: usuarios.php'); exit; }

// --- 1. LÓGICA PARA ELIMINAR AVATAR ---
if (isset($_POST['btn_eliminar_avatar'])) {
    $st_img = $conn->prepare("SELECT avatar FROM usuarios WHERE id = ? LIMIT 1");
    $st_img->bind_param('i', $id);
    $st_img->execute();
    $img_data = $st_img->get_result()->fetch_assoc();
    
    if ($img_data && !empty($img_data['avatar'])) {
        // Se asume que la carpeta uploads está en la raíz del proyecto
        $ruta_fisica = __DIR__ . '/../' . $img_data['avatar']; 
        if (file_exists($ruta_fisica)) {
            unlink($ruta_fisica);
        }
        
        $st_del = $conn->prepare("UPDATE usuarios SET avatar = NULL WHERE id = ?");
        $st_del->bind_param('i', $id);
        $st_del->execute();
        header("Location: usuarios-ver.php?id=" . $id . "&msg=avatar_eliminado");
        exit;
    }
}

// --- 2. OBTENER DATOS DEL USUARIO PERFIL ---
$st = $conn->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();

if (!$u) { 
    require_once __DIR__ . '/header.php';
    echo "<div class='alert alert-err'>Usuario no encontrado.</div>"; 
    exit; 
}

$nombreCompleto = htmlspecialchars(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')));
$rol = $u['rol'];
$esStaffDictante = ($rol === 'profesor' || $rol === 'admin-profesor');

/**
 * 3. CONSULTA A: CLASES QUE DICTA (Solo si es profesor/admin-profe)
 */
$reservas_dictadas = null;
if ($esStaffDictante) {
    $sql_dicta = "
        SELECT r.id AS reserva_id, r.horario_id, r.observaciones, h.fecha_especifica, h.hora, h.instrumento, h.tipo_turno, 
               u.nombre AS nombre_entidad, u.apellido AS apellido_entidad
        FROM reservas r
        JOIN horarios h ON r.horario_id = h.id
        JOIN usuarios u ON r.usuario_id = u.id
        JOIN profesores p ON h.profesor_id = p.id
        WHERE p.id = (SELECT id FROM profesores WHERE email = ? OR nombre LIKE ? LIMIT 1)
        ORDER BY h.fecha_especifica DESC, h.hora ASC";
    
    $st_dicta = $conn->prepare($sql_dicta);
    $searchTerm = $u['nombre'] . '%';
    $st_dicta->bind_param('ss', $u['email'], $searchTerm);
    $st_dicta->execute();
    $reservas_dictadas = $st_dicta->get_result();
}

/**
 * 4. CONSULTA B: CLASES A LAS QUE ASISTE (Como alumno)
 */
$sql_asiste = "
    SELECT r.id AS reserva_id, r.horario_id, r.observaciones, h.fecha_especifica, h.hora, h.instrumento, h.tipo_turno, 
           p.nombre AS nombre_entidad, '' AS apellido_entidad
    FROM reservas r
    JOIN horarios h ON r.horario_id = h.id
    LEFT JOIN profesores p ON h.profesor_id = p.id
    WHERE r.usuario_id = ?
    ORDER BY h.fecha_especifica DESC, h.hora ASC";

$st_asiste = $conn->prepare($sql_asiste);
$st_asiste->bind_param('i', $id);
$st_asiste->execute();
$reservas_asistidas = $st_asiste->get_result();

require_once __DIR__ . '/header.php';
?>

<style>
    .perfil-header { display: flex; align-items: center; gap: 30px; margin-bottom: 30px; }
    .avatar-wrapper { position: relative; width: 120px; height: 120px; flex-shrink: 0; }
    .avatar-img { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid #6366f1; box-shadow: 0 4px 15px rgba(99,102,241,0.3); }
    .avatar-placeholder { width: 120px; height: 120px; border-radius: 50%; background: #1e293b; display: flex; align-items: center; justify-content: center; font-size: 50px; color: #475569; border: 2px dashed #475569; }
    .btn-del-avatar { background: #ef4444; color: white; border: none; padding: 8px; border-radius: 50%; font-size: 12px; cursor: pointer; position: absolute; bottom: 0; right: 0; transition: 0.3s; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; }
    .btn-del-avatar:hover { background: #b91c1c; transform: scale(1.1); }

    .perfil-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 25px; margin-bottom: 30px; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
    .info-item label { display: block; color: #9ca3af; font-size: 11px; text-transform: uppercase; font-weight: 900; margin-bottom: 5px; }
    .info-item span { color: #fff; font-size: 16px; font-weight: 600; }
    .reserva-item { background: rgba(255,255,255,0.05); border-left: 5px solid #6366f1; padding: 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .reserva-info-main { font-size: 18px; color: #fff; margin-bottom: 8px; }
    .reserva-info-sub { font-size: 15px; color: #a5b4fc; font-weight: 600; margin-bottom: 5px; }
    .reserva-date-time { font-size: 16px; color: #d1d5db; display: flex; gap: 15px; align-items: center; }
    .badge-tipo { font-size: 11px; padding: 4px 10px; border-radius: 6px; background: rgba(99, 102, 241, 0.2); color: #a5b4fc; text-transform: uppercase; font-weight: 800; border: 1px solid rgba(99, 102, 241, 0.3); }
    .badge-id { font-size: 12px; color: #6366f1; font-weight: 900; margin-right: 10px; opacity: 0.7; }
    .obs-text { background: rgba(0,0,0,0.3); padding: 12px; border-radius: 8px; font-size: 14px; color: #9ca3af; margin-top: 15px; border-left: 3px solid #4b5563; max-width: 80%; }
    .actions-group { display: flex; gap: 10px; }
    .btn-action { padding: 10px 16px; border-radius: 10px; font-size: 12px; font-weight: 800; text-decoration: none; text-transform: uppercase; transition: 0.3s; border: 1px solid transparent; display: inline-flex; align-items: center; }
    .btn-edit { background: #4f46e5; color: #fff; }
    .btn-move { background: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.3); }
    .btn-del { background: rgba(239, 68, 68, 0.1); color: #ef4444; border-color: rgba(239, 68, 68, 0.3); }
    .btn-action:hover { transform: translateY(-2px); filter: brightness(1.2); }
    @media (max-width: 768px) { 
        .perfil-header { flex-direction: column; text-align: center; }
        .reserva-item { flex-direction: column; align-items: flex-start; gap: 20px; } 
        .actions-group { width: 100%; justify-content: space-between; } 
    }
</style>

<div class="perfil-header">
    <div class="avatar-wrapper">
        <?php if (!empty($u['avatar'])): ?>
            <img src="../<?= htmlspecialchars($u['avatar']) ?>" alt="Avatar" class="avatar-img">
            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar el avatar de este usuario?')">
                <button type="submit" name="btn_eliminar_avatar" class="btn-del-avatar" title="Eliminar Avatar">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </form>
        <?php else: ?>
            <div class="avatar-placeholder">
                <i class="fas fa-user"></i>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <h1 style="font-size: 28px; margin: 0;">Perfil de <?= strtoupper($rol) ?></h1>
        <p style="color: #6366f1; font-weight: bold; font-size: 24px; margin: 5px 0 0 0;"><?= $nombreCompleto ?></p>
        <p style="color: #9ca3af; font-size: 14px; margin-top: 5px;">ID Usuario: #<?= $u['id'] ?></p>
    </div>
</div>

<div class="perfil-card">
    <div class="info-grid">
        <div class="info-item"><label>Email</label><span><?= htmlspecialchars($u['email']) ?></span></div>
        <div class="info-item"><label>Rol de Sistema</label><span style="color:#a78bfa"><?= strtoupper($u['rol']) ?></span></div>
        <div class="info-item"><label>Nivel / Categoría</label><span><?= htmlspecialchars($u['nivel'] ?? 'No asignado') ?></span></div>
        <div class="info-item"><label>Teléfono de Contacto</label><span><?= htmlspecialchars($u['telefono'] ?? '—') ?></span></div>
    </div>
</div>

<?php if ($esStaffDictante): ?>
    <h3 style="margin: 40px 0 20px 0; font-size: 22px;">📅 Agenda de Clases (Como Profesor)</h3>
    <?php renderSeccionReservas($reservas_dictadas, true, $id); ?>
<?php endif; ?>

<h3 style="margin: 40px 0 20px 0; font-size: 22px;">🎵 Turnos Reservados (Como Alumno)</h3>
<?php renderSeccionReservas($reservas_asistidas, false, $id); ?>

<?php

function renderSeccionReservas($resultado, $esVistaProfesor, $usuarioId) {
    if (!$resultado || $resultado->num_rows === 0): ?>
        <div style="background: rgba(255,255,255,0.02); padding: 40px; border-radius: 16px; text-align: center; border: 1px dashed rgba(255,255,255,0.1); margin-bottom: 30px;">
            <p style="color: #6b7280; font-size: 16px;">No hay actividad registrada en esta sección.</p>
        </div>
    <?php else: 
        while($r = $resultado->fetch_assoc()): 
            $url_base = 'usuarios-ver.php?id=' . $usuarioId;
    ?>
        <div class="reserva-item">
            <div style="flex: 1;">
                <div class="reserva-info-sub">
                    <span class="badge-id">ID #<?= $r['reserva_id'] ?></span>
                    <span class="badge-tipo">Turno <?= $r['tipo_turno'] ?></span>
                    <span style="margin-left: 15px; color: #fff; font-size: 18px;">Instrumento - <?= $r['instrumento'] ?></span>
                </div>

                <div class="reserva-info-main">
                    <?= $esVistaProfesor ? 'Alumno: ' : 'Profesor: ' ?>
                    <strong><?= htmlspecialchars($r['nombre_entidad'] . ' ' . ($r['apellido_entidad'] ?? '')) ?></strong>
                </div>

                <div class="reserva-date-time">
                    <span><strong><?= date('d/m/Y', strtotime($r['fecha_especifica'])) ?></strong></span>
                    <span><strong><?= substr($r['hora'], 0, 5) ?> hs</strong></span>
                </div>

                <?php if(!empty($r['observaciones'])): ?>
                    <div class="obs-text">
                        <strong style="color: #fff; display: block; margin-bottom: 4px; font-size: 11px; text-transform: uppercase;">Notas:</strong>
                        <?= htmlspecialchars($r['observaciones']) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="actions-group">
                <a href="horarios-editar.php?id=<?= $r['horario_id'] ?>&from=<?= urlencode($url_base) ?>" 
                   class="btn-action btn-edit">Editar</a>

                <?php if($r['tipo_turno'] === 'fijo'): ?>
                    <a href="reservas-trasladar.php?id=<?= $r['reserva_id'] ?>&from=<?= urlencode($url_base) ?>" 
                       class="btn-action btn-move" 
                       onclick="return confirm('¿Trasladar este turno?')">Trasladar</a>
                <?php endif; ?>

                <a href="reservas-eliminar.php?id=<?= $r['reserva_id'] ?>&from=<?= urlencode($url_base) ?>" 
                   class="btn-action btn-del" 
                   onclick="return confirm('¿Eliminar reserva?')">Cancelar</a>
            </div>
        </div>
        <?php endwhile; ?>
    <?php endif; 
} ?>

<div style="margin-top: 40px; padding-bottom: 50px;">
    <a href="usuarios.php" style="color:#9ca3af; text-decoration:none; font-weight:bold;">← Volver a la lista</a>
</div>

