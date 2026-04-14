<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_admin();

// 1. Incluir la cabecera del admin
$headerPath = dirname(__FILE__) . '/header.php';
if (file_exists($headerPath)) {
    include_once $headerPath;
} else {
    die("Error: No se encontró el archivo header.php en la carpeta admin.");
}

// 2. LÓGICA DE PAGINACIÓN
$resultados_por_pagina = 12; 
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $resultados_por_pagina;

// Obtener el total de registros
$res_count = $conn->query("SELECT COUNT(*) as total FROM notificaciones");
$total_registros = $res_count->fetch_assoc()['total'] ?? 0;
$total_paginas = ceil($total_registros / $resultados_por_pagina);

// 3. Limpiar notificaciones (marcar como leídas para el admin)
if (isset($user_id_sesion) && $user_id_sesion > 0) {
    $conn->query("UPDATE notificaciones SET leido = 1 WHERE usuario_id = $user_id_sesion");
}

// 4. Consulta de Auditoría
$sql_audit = "SELECT 
                n.id, 
                n.mensaje, 
                n.tipo, 
                n.creado_en, 
                u.nombre as usuario_nombre,
                u.rol as usuario_rol,
                u.avatar as usuario_avatar
              FROM notificaciones n
              LEFT JOIN usuarios u ON n.usuario_id = u.id
              ORDER BY n.creado_en DESC 
              LIMIT $resultados_por_pagina OFFSET $offset";

$res_audit = $conn->query($sql_audit);
?>

<style>
    :root {
        --bg: #0b1220;
        --card-bg: rgba(255, 255, 255, 0.03);
        --border: rgba(255, 255, 255, 0.08);
        --text: #f3f4f6;
        --muted: #9ca3af;
        --accent: #4f46e5;
        --radius: 14px;
    }

    .audit-header {
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
        flex-wrap: wrap;
        gap: 15px;
    }

    .audit-container {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
        margin-top: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
    }

    .audit-table-wrapper {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .audit-table {
        width: 100%;
        border-collapse: collapse;
        color: var(--text);
        min-width: 800px;
    }

    .audit-table th {
        text-align: left;
        padding: 15px 20px;
        background: rgba(255,255,255,0.02);
        color: var(--muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        white-space: nowrap;
    }

    .audit-table td {
        padding: 15px 20px;
        border-bottom: 1px solid var(--border);
        font-size: 14px;
        vertical-align: middle;
    }

    .user-pill {
        display: flex;
        align-items: center;
        gap: 12px;
        white-space: nowrap;
    }

    .user-pill img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        border: 1px solid var(--border);
    }

    .msg-text {
        line-height: 1.5;
        font-weight: 400;
        color: #e0e0e0;
        min-width: 250px;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
        white-space: nowrap;
    }
    .badge-success { background: #10b98122; color: #10b981; border: 1px solid #10b98144; }
    .badge-danger { background: #ef444422; color: #ef4444; border: 1px solid #ef444444; }
    .badge-info { background: #3b82f622; color: #3b82f6; border: 1px solid #3b82f644; }
    .badge-warning { background: #f59e0b22; color: #f59e0b; border: 1px solid #f59e0b44; }

    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
        padding: 20px;
        background: rgba(255,255,255,0.01);
        flex-wrap: wrap;
    }

    .btn-pag {
        text-decoration: none;
        color: var(--text);
        padding: 8px 16px;
        background: rgba(255,255,255,0.05);
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 13px;
        transition: 0.2s;
    }

    .btn-pag:hover:not(.disabled) {
        background: var(--accent);
        border-color: var(--accent);
    }

    .btn-pag.disabled {
        opacity: 0.2;
        cursor: not-allowed;
    }

    @media (max-width: 768px) {
        .audit-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .audit-header div {
            text-align: left !important;
        }
        .pagination {
            flex-direction: column;
            width: 100%;
        }
        .btn-pag {
            width: 100%;
            text-align: center;
        }
    }
</style>

<div class="audit-header">
    <h2 style="margin:0; font-size: 1.25rem;"><i class="fas fa-clipboard-list" style="margin-right: 10px; color: var(--accent);"></i> Auditoría de Actividad Global</h2>
    <div style="text-align: right;">
        <span style="display: block; font-size: 12px; color: var(--muted);">Total de registros: <?= $total_registros ?></span>
    </div>
</div>

<div class="audit-container">
    <div class="audit-table-wrapper">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Fecha / Hora</th>
                    <th>Usuario Destinatario</th>
                    <th>Descripción del Movimiento</th>
                    <th>Tipo</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($res_audit && $res_audit->num_rows > 0): ?>
                    <?php while($row = $res_audit->fetch_assoc()): ?>
                    <tr>
                        <td style="color: var(--muted); width: 140px; font-family: monospace;">
                            <?= date('d/m/y H:i', strtotime($row['creado_en'])) ?>
                        </td>
                        <td>
                            <div class="user-pill">
                                <?php if (empty($row['usuario_nombre'])): ?>
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; color: #818cf8;">
                                        <i class="fas fa-server" style="font-size: 12px;"></i>
                                    </div>
                                    <div>
                                        <span style="font-weight:700; display:block; color: #a5b4fc;">Registro Global</span>
                                        <small style="color:var(--muted); font-size: 10px;">SISTEMA</small>
                                    </div>
                                <?php else: ?>
                                    <?php 
                                        $avatar = !empty($row['usuario_avatar']) ? '../' . $row['usuario_avatar'] : '../assets/img/default-avatar.png';
                                    ?>
                                    <img src="<?= $avatar ?>" onerror="this.src='../assets/img/default-avatar.png'" alt="">
                                    <div>
                                        <span style="font-weight:700; display:block;"><?= htmlspecialchars($row['usuario_nombre']) ?></span>
                                        <small style="color:var(--muted); font-size: 10px;"><?= strtoupper($row['usuario_rol']) ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="msg-text">
                                <?= htmlspecialchars($row['mensaje']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $row['tipo'] ?>">
                                <?= $row['tipo'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding: 60px; color: var(--muted);">
                            <i class="fas fa-inbox" style="display:block; font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                            No se han encontrado registros de actividad en el sistema.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <a href="?pagina=<?= $pagina_actual - 1 ?>" class="btn-pag <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
            <i class="fas fa-chevron-left"></i> Anterior
        </a>

        <span style="font-size: 13px; color: var(--muted);">
            Página <strong><?= $pagina_actual ?></strong> de <?= $total_paginas ?>
        </span>

        <a href="?pagina=<?= $pagina_actual + 1 ?>" class="btn-pag <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
            Siguiente <i class="fas fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

</main> </body>
</html>```