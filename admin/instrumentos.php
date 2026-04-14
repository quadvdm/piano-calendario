<?php
// instrumentos.php
require_once __DIR__ . '/auth.php';
require_admin();
require_once dirname(__DIR__) . '/config/database.php';

$db = Database::getInstance();

// 1. DEFINIR FUNCIÓN DE SEGURIDAD
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}

// 2. LÓGICA DE ACCIONES
if (isset($_POST['nuevo_instrumento'])) {
    $nombre = trim($_POST['nombre']);
    if ($nombre !== '') {
        $db->query("INSERT IGNORE INTO instrumentos (nombre) VALUES (?)", [$nombre]);
        header("Location: instrumentos.php?msg=Instrumento agregado");
        exit;
    }
}

if (isset($_GET['delete'])) {
    $nombre_del = $_GET['delete'];
    $db->query("DELETE FROM instrumentos WHERE nombre = ?", [$nombre_del]);
    header("Location: instrumentos.php?msg=Instrumento eliminado");
    exit;
}

$lista = $db->fetchAll("SELECT * FROM instrumentos ORDER BY nombre ASC");
$msg = $_GET['msg'] ?? '';

include_once 'header.php'; 
?>

<style>
.toolbar{display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin: 10px 0 24px;}
.input{flex:1 1 260px; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background: rgba(0,0,0,.16); color:#f3f4f6; outline: none;}
.btn2{display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.06); color:#f3f4f6; text-decoration:none; font-weight:900; cursor:pointer; transition:.15s}
.btn2:hover{transform:translateY(-1px); background: rgba(255,255,255,.10);}
.btn-primary { background: #8b5cf6 !important; border-color: #8b5cf6 !important; }

.table{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:14px; border:1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.03);}
.table th,.table td{padding:12px 15px; border-bottom:1px solid rgba(255,255,255,.08); font-size:14px}
.table th{color:#9ca3af; text-align:left; font-weight:900; background: rgba(0,0,0,.20)}
.table tr:last-child td { border-bottom: none; }

.card{border:1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.03); border-radius:16px; padding:20px; margin-bottom:30px}
label{display:block; font-weight:900; font-size:12px; color:#9ca3af; margin-bottom:8px; text-transform: uppercase; letter-spacing: 0.5px;}

.container { max-width: 900px; margin: 0 auto; padding: 20px; }
</style>

<div class="container">
    <h1 style="font-weight: 900; margin-bottom: 20px;">Gestión de Instrumentos</h1>

    <?php if ($msg): ?>
        <p style="color:#86efac; font-weight:900; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?= h($msg) ?>
        </p>
    <?php endif; ?>

    <div class="card">
        <h2 style="color:#8b5cf6; margin-top:0; font-size: 1.2rem; margin-bottom: 20px;">Agregar nuevo instrumento</h2>
        <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap: wrap;">
            <div style="flex:2; min-width: 250px;">
                <label>Nombre del Instrumento</label>
                <input type="text" name="nombre" class="input" placeholder="Ej: Saxofón, Bajo, Canto..." required style="width: 100%;">
            </div>
            <button type="submit" name="nuevo_instrumento" class="btn2 btn-primary">
                <i class="fas fa-plus"></i> Guardar Instrumento
            </button>
        </form>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Nombre del Instrumento</th>
                <th style="text-align: right;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($lista)): ?>
                <tr>
                    <td colspan="2" style="text-align: center; color: #64748b; padding: 30px;">
                        No hay instrumentos registrados aún.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach($lista as $ins): ?>
                <tr>
                    <td style="font-weight: 600; color: #e2e8f0;">
                        <i class="fas fa-music" style="color: #8b5cf6; margin-right: 10px; opacity: 0.7;"></i>
                        <?= h($ins['nombre']) ?>
                    </td>
                    <td style="text-align: right;">
                        <a class="btn2" style="color:#fca5a5; padding: 6px 12px; font-size: 12px;" 
                           href="?delete=<?= urlencode($ins['nombre']) ?>" 
                           onclick="return confirm('¿Estás seguro de eliminar este instrumento? Esto podría afectar la visualización en los perfiles de profesores.');">
                            <i class="fas fa-trash-alt"></i> Eliminar
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <p style="margin-top: 20px; color: #64748b; font-size: 13px;">
        <i class="fas fa-info-circle"></i> Estos instrumentos aparecerán disponibles para asignar en la sección de <strong>Profesores</strong>.
    </p>
</div>

</body>
</html>