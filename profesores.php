<?php
// profesores.php - Interfaz de Reservas Directas con Perfil de Profesor
include_once 'navbar.php';
require_once 'config/database.php';

$db = Database::getInstance();
$user_id = (int)($_SESSION['user_id'] ?? 0);

// 1. OBTENER PROFESORES ACTIVOS 
$profesores = $db->fetchAll("
    SELECT p.*, u.avatar 
    FROM profesores p 
    INNER JOIN usuarios u ON p.id = u.id 
    WHERE p.activo = 1 
    ORDER BY p.nombre
");

// Manejo de mensajes de sesión
$mensaje = $_SESSION['msg_profesores'] ?? null;
unset($_SESSION['msg_profesores']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuestros Profesores - Anima Music</title>
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

    body {
        background: 
            radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), 
            radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), 
            linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
        background-attachment: fixed;
        color: var(--text);
        font-family: 'Inter', sans-serif;
        margin: 0;
        min-height: 100vh;
    }

    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
    .particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
    .particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
    .particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
    .particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }

    .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }

    .profesores-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 30px;
    }

    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        border-radius: var(--radius);
        padding: 30px;
        display: flex;
        flex-direction: column;
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    }
    
    .glass-card:hover { 
        transform: translateY(-8px); 
        box-shadow: 0 25px 50px rgba(0,0,0,0.4), 0 0 30px rgba(139, 92, 246, 0.1);
        border-color: rgba(139, 92, 246, 0.4);
    }

    /* ESTILO DEL AVATAR */
    .avatar-wrapper {
        width: 90px; height: 90px; border-radius: 50%;
        margin: 0 auto 20px; padding: 4px;
        background: var(--gradient-primary);
        box-shadow: 0 0 20px var(--accent-glow);
    }
    .avatar-inner {
        width: 100%; height: 100%; border-radius: 50%;
        background: var(--bg-darker); display: flex;
        align-items: center; justify-content: center;
        font-size: 2.2rem; font-weight: 900; overflow: hidden;
        color: #fff;
    }
    .avatar-inner img { width: 100%; height: 100%; object-fit: cover; }

    .profesor-meta {
        text-align: center;
        margin-bottom: 25px;
        border-bottom: 1px solid var(--glass-border);
        padding-bottom: 20px;
    }

    .contact-info {
        display: flex; justify-content: center; gap: 15px;
        font-size: 0.8rem; color: var(--muted); margin: 10px 0;
        flex-wrap: wrap; font-weight: 500;
    }
    .contact-info i { color: var(--accent); }

    .descripcion {
        font-size: 0.9rem; color: var(--muted); line-height: 1.5;
        margin: 15px 0; text-align: center; font-style: italic;
        min-height: 45px;
    }

    .info-label {
        font-size: 11px; font-weight: 800; color: #fff;
        text-transform: uppercase; letter-spacing: 1px;
        margin-bottom: 15px; display: block; text-align: center;
        text-shadow: 0 0 10px var(--accent-glow);
    }

    .horarios-list {
        display: flex; flex-direction: column; gap: 12px;
        max-height: 260px; overflow-y: auto; padding-right: 5px;
    }

    .horarios-list::-webkit-scrollbar { width: 6px; }
    .horarios-list::-webkit-scrollbar-track { background: transparent; }
    .horarios-list::-webkit-scrollbar-thumb { background: rgba(139, 92, 246, 0.5); border-radius: 10px; }

    .horario-item {
        background: rgba(0,0,0,0.2);
        border: 1px solid var(--glass-border);
        padding: 14px 16px; border-radius: 16px;
        display: flex; justify-content: space-between; align-items: center;
        transition: 0.3s ease;
    }
    .horario-item:hover {
        background: rgba(255,255,255,0.05);
        border-color: rgba(139, 92, 246, 0.4);
    }

    .horario-info strong { font-size: 0.9rem; color: #fff; }
    .meta-clase { font-size: 0.75rem; color: var(--text-dim); display: flex; align-items: center; gap: 6px; margin-top: 4px; }

    .tag { font-size: 9px; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
    .tag-fijo { background: rgba(16,185,129,0.15); color: #4ade80; border: 1px solid rgba(16,185,129,0.3); }
    .tag-extra { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }

    .btn-take {
        background: var(--gradient-primary);
        color: white; border: none;
        padding: 10px 18px; border-radius: 12px;
        font-size: 0.8rem; font-weight: 800;
        cursor: pointer; transition: 0.3s ease;
        display: flex; align-items: center; gap: 8px;
        box-shadow: 0 5px 15px var(--accent-glow);
    }
    .btn-take:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 10px 25px rgba(139, 92, 246, 0.7); 
    }

    .msg-success { 
        background: rgba(16,185,129,0.15); 
        color: #4ade80; 
        padding: 18px; 
        border-radius: 16px; 
        border: 1px solid rgba(16,185,129,0.3); 
        text-align: center; 
        margin-bottom: 35px; 
        font-weight: 600;
        backdrop-filter: blur(10px);
    }
</style>
</head>
<body>
<div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
<div class="container">

    <?php if($mensaje): ?>
        <div class="msg-success"><i class="fas fa-check-circle"></i> <?= $mensaje ?></div>
    <?php endif; ?>

    <div style="text-align: center; margin-bottom: 40px;">
        <h1 style="font-weight: 900; letter-spacing: -1.5px; margin-bottom: 5px; font-size: 2.5rem;">
            Nuestros <span style="color:var(--accent)">Profesores</span>
        </h1>
        <p style="opacity: 0.5; font-size: 0.9rem;">Selecciona un profesor y elige el horario que mejor te quede.</p>
    </div>

    <div class="profesores-grid">
        <?php foreach ($profesores as $p): ?>
            <?php
            // Buscamos horarios disponibles para este profesor
            $horarios = $db->fetchAll("
                SELECT h.*, r.id as reserva_id 
                FROM horarios h 
                LEFT JOIN reservas r ON h.id = r.horario_id AND r.estado != 'cancelada'
                WHERE h.profesor_id = ? AND h.activo = 1 AND r.id IS NULL
                ORDER BY FIELD(h.dia_semana, 'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'), h.hora
            ", [$p['id']]);
            ?>

            <div class="glass-card">
                <div class="profesor-meta">
                    <div class="avatar-wrapper">
                        <div class="avatar-inner">
                            <?php if(!empty($p['avatar']) && file_exists($p['avatar'])): ?>
                                <img src="<?= $p['avatar'] ?>" alt="<?= htmlspecialchars($p['nombre']) ?>">
                            <?php else: ?>
                                <span style="color: var(--accent)"><?= strtoupper(substr($p['nombre'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800;"><?= htmlspecialchars($p['nombre']) ?></h3>
                    
                    <p style="color: var(--accent); font-size: 0.8rem; font-weight: 700; margin: 6px 0;">
                        <?= htmlspecialchars($p['especialidad'] ?? 'Instructor') ?> 
                        <span style="opacity: 0.4; font-weight: 400; color: #fff;"> | <?= htmlspecialchars($p['experiencia'] ?? 'Exp. Pro') ?> de experiencia</span>
                    </p>

                    <div class="contact-info">
                        <?php if(!empty($p['email'])): ?>
                            <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($p['email']) ?></span>
                        <?php endif; ?>
                        <?php if(!empty($p['telefono'])): ?>
                            <span><i class="fas fa-phone"></i> <?= htmlspecialchars($p['telefono']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if(!empty($p['descripcion'])): ?>
                        <p class="descripcion">"<?= htmlspecialchars($p['descripcion']) ?>"</p>
                    <?php endif; ?>
                </div>

                <span class="info-label">Clases Disponibles</span>

                <div class="horarios-list">
                    <?php if(empty($horarios)): ?>
                        <p style="text-align:center; opacity:0.3; font-size:0.75rem; padding: 20px;">Sin turnos libres actualmente.</p>
                    <?php else: ?>
                        <?php foreach($horarios as $h): 
                            $fecha_formateada = $h['fecha_especifica'] ? date('d/m', strtotime($h['fecha_especifica'])) : '';
                            // Si el usuario logueado es el mismo profesor, no puede reservarse a sí mismo
                            $es_mi_clase = ($user_id > 0 && (int)$p['id'] === $user_id);
                        ?>
                            <div class="horario-item">
                                <div class="horario-info">
                                    <strong>
                                        <?= $h['dia_semana'] ?> <?= $fecha_formateada ?>
                                        <span style="color: var(--accent); margin-left: 4px;"><?= substr($h['hora'],0,5) ?></span>
                                    </strong>
                                    <div class="meta-clase">
                                        <span class="tag <?= ($h['tipo_turno'] == 'fijo') ? 'tag-fijo' : 'tag-extra' ?>">
                                            <?= $h['tipo_turno'] ?>
                                        </span>
                                        <span><i class="far fa-clock"></i> <?= $h['duracion_minutos'] ?> min</span>
                                    </div>
                                </div>

                                <?php if ($es_mi_clase): ?>
                                    <span style="font-size: 9px; color: var(--accent); font-weight: 800; text-transform: uppercase; opacity: 0.6;">
                                        Tu horario
                                    </span>
                                <?php else: ?>
                                    <form action="procesar.php" method="POST" style="margin:0;">
                                        <input type="hidden" name="horario_id" value="<?= $h['id'] ?>">
                                        <input type="hidden" name="fecha_seleccionada" value="<?= $h['fecha_especifica'] ?>">
                                        <input type="hidden" name="tipo_turno_elegido" value="<?= $h['tipo_turno'] ?>">
                                        <input type="hidden" name="from" value="profesores.php">

                                        <button type="submit" class="btn-take" onclick="return confirm('¿Quieres reservar este turno con <?= addslashes($p['nombre']) ?>?')">
                                            Reservar <i class="fas fa-plus"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
<script>
setTimeout(() => {
    const msg = document.querySelector('.alert-success');
    if(msg) msg.style.display = 'none';
}, 2000);
</script>