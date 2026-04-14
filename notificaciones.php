<?php
// notificaciones.php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
session_start();

$db = Database::getInstance();
$conn = $db->getConnection();
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

// 1. MARCAR TODAS COMO LEÍDAS AL ENTRAR
$conn->query("UPDATE notificaciones SET leido = 1 WHERE usuario_id = $user_id AND leido = 0");

// 2. LÓGICA DE PAGINACIÓN
$resultados_por_pagina = 10; 
$pagina_actual = isset($_GET['pagina']) ? (int)($_GET['pagina']) : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $resultados_por_pagina;

// Obtener el total exacto de notificaciones de este usuario
$queryCount = "SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ?";
$stmtCount = $conn->prepare($queryCount);
$stmtCount->bind_param("i", $user_id);
$stmtCount->execute();
$total_registros = $stmtCount->get_result()->fetch_assoc()['total'] ?? 0;
$total_paginas = ceil($total_registros / $resultados_por_pagina);
$stmtCount->close();

// 3. OBTENER NOTIFICACIONES 
$query = "SELECT * FROM notificaciones WHERE usuario_id = ? ORDER BY creado_en DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $user_id, $resultados_por_pagina, $offset);
$stmt->execute();
$notificaciones = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Notificaciones | Anima Music</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        padding: 0;
        min-height: 100vh;
    }

    /* Partículas de "escenario" */
    .particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none;}
    .particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
    .particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
    .particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
    .particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
    .particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }

    .container {
        max-width: 750px;
        margin: 40px auto;
        padding: 20px;
        box-sizing: border-box;
    }

    .header-section {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 35px;
    }

    h2 {
        font-weight: 800;
        letter-spacing: -0.5px;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
        font-size: 1.8rem;
        text-shadow: 0 0 15px rgba(139, 92, 246, 0.3);
    }

    h2 i { color: var(--accent); filter: drop-shadow(0 0 8px var(--accent-glow)); }

    .notif-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .notif-item {
        background: var(--glass-bg);
        backdrop-filter: blur(15px);
        border: 1px solid var(--glass-border);
        padding: 20px;
        border-radius: 20px;
        display: flex;
        gap: 18px;
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        text-decoration: none;
        color: inherit;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }

    .notif-item:hover {
        background: rgba(255, 255, 255, 0.08);
        border-color: rgba(139, 92, 246, 0.4);
        transform: translateY(-4px);
        box-shadow: 0 15px 40px rgba(0,0,0,0.4), 0 0 20px rgba(139, 92, 246, 0.15);
    }

    .notif-icon {
        width: 45px;
        height: 45px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1.1rem;
    }

    .notif-info { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); box-shadow: inset 0 0 10px rgba(59, 130, 246, 0.2); }
    .notif-success { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); box-shadow: inset 0 0 10px rgba(34, 197, 94, 0.2); }
    .notif-warning { background: rgba(234, 179, 8, 0.15); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.3); box-shadow: inset 0 0 10px rgba(234, 179, 8, 0.2); }
    .notif-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); box-shadow: inset 0 0 10px rgba(239, 68, 68, 0.2); }

    .notif-content { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; }

    .notif-message {
        font-size: 0.95rem;
        line-height: 1.5;
        margin-bottom: 6px;
        color: #fff;
    }

    .notif-time {
        font-size: 0.75rem;
        color: var(--text-dim);
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: var(--glass-bg);
        border-radius: var(--radius);
        border: 1px solid var(--glass-border);
        backdrop-filter: blur(10px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    }
    
    .empty-state i {
        font-size: 3rem;
        color: rgba(255, 255, 255, 0.1);
        margin-bottom: 15px;
    }

    .btn-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--text-dim);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 600;
        transition: 0.3s;
        padding: 8px 16px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid transparent;
    }

    .btn-back:hover { 
        color: #fff; 
        background: rgba(255, 255, 255, 0.1);
        border-color: var(--glass-border);
        transform: translateX(-3px);
    }

    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
        margin-top: 40px;
        padding-top: 25px;
        border-top: 1px solid var(--glass-border);
    }

    .btn-pag {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        border-radius: 14px;
        color: #fff;
        text-decoration: none;
        transition: 0.3s;
        backdrop-filter: blur(10px);
    }

    .btn-pag:hover:not(.disabled) {
        background: var(--gradient-primary);
        border-color: transparent;
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px var(--accent-glow);
    }

    .btn-pag.disabled {
        opacity: 0.3;
        cursor: not-allowed;
        background: transparent;
    }

    .pag-info {
        font-size: 0.85rem;
        color: var(--text-dim);
        font-weight: 600;
        letter-spacing: 1px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .notif-item { padding: 15px; flex-direction: column; gap: 12px; }
        .notif-icon { width: 35px; height: 35px; font-size: 0.9rem; }
    }
</style>
</head>
<body>
<div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
    <?php include __DIR__ . '/navbar.php'; ?>

    <div class="container">
        <div class="header-section">
            <h2><i class="fas fa-bell"></i> Notificaciones</h2>
        </div>

        <div class="notif-list">
            <?php if ($notificaciones->num_rows > 0): ?>
                <?php while ($n = $notificaciones->fetch_assoc()): 
                    $icon = 'info-circle';
                    if($n['tipo'] == 'success') $icon = 'check-circle';
                    if($n['tipo'] == 'warning') $icon = 'exclamation-triangle';
                    if($n['tipo'] == 'danger') $icon = 'times-circle';
                ?>
                    <a href="<?= $n['link'] ?? '#' ?>" class="notif-item">
                        <div class="notif-icon notif-<?= $n['tipo'] ?>">
                            <i class="fas fa-<?= $icon ?>"></i>
                        </div>
                        <div class="notif-content">
                            <div class="notif-message"><?= htmlspecialchars($n['mensaje']) ?></div>
                            <div class="notif-time">
                                <i class="far fa-clock"></i> 
                                <?= date('d/m/y — H:i', strtotime($n['creado_en'])) ?>
                            </div>
                        </div>
                    </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 15px; opacity: 0.5;"></i>
                    <p>No tienes notificaciones por el momento.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <a href="?pagina=<?= $pagina_actual - 1 ?>" class="btn-pag <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
                <i class="fas fa-chevron-left"></i>
            </a>

            <span class="pag-info">
                Página <strong style="color: #fff;"><?= $pagina_actual ?></strong> de <?= $total_paginas ?>
            </span>

            <a href="?pagina=<?= $pagina_actual + 1 ?>" class="btn-pag <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>