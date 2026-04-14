<?php
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/header.php';

$db   = Database::getInstance();
$conn = $db->getConnection();

// --- LÓGICA DE PAGINACIÓN Y BÚSQUEDA ---
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = " WHERE 1=1";
$params = [];
$types  = '';

if ($q !== '') {
    $where .= " AND (email LIKE ? OR nombre LIKE ? OR apellido LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
    $types  = 'sss';
}

// 1. Contar total para paginación
$sqlCount = "SELECT COUNT(*) as total FROM usuarios $where";
$stmtCount = $conn->prepare($sqlCount);
if ($params) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// 2. Obtener usuarios 
$sql = "SELECT id, email, nombre, apellido, rol, activo, fecha_registro, ultimo_login, nivel, fecha_eliminacion
        FROM usuarios
        $where
        ORDER BY fecha_eliminacion IS NULL ASC, fecha_eliminacion ASC, id DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$finalParams = array_merge($params, [$limit, $offset]);
$finalTypes = $types . 'ii';
$stmt->bind_param($finalTypes, ...$finalParams);
$stmt->execute();
$res = $stmt->get_result();

?>
<style>
:root {
    --bg: #0b1220;
    --card-bg: rgba(255, 255, 255, 0.03);
    --border: rgba(255, 255, 255, 0.08);
    --text: #f3f4f6;
    --muted: #9ca3af;
    --accent: #8b5cf6;
    --radius: 14px;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: 'Inter', system-ui, sans-serif;
    background-color: var(--bg);
    color: var(--text);
}

h1 { font-size: 1.5rem; letter-spacing: -0.5px; font-weight: 800; margin-bottom: 20px; }

.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin: 20px 0;
}

.input {
    flex: 1 1 200px;
    padding: 10px 14px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: rgba(0,0,0,0.2);
    color: #f3f4f6;
    outline: none;
    font-size: 14px;
    transition: 0.2s;
}
.input:focus { border-color: var(--accent); }

.btn2 {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.05);
    color: #f3f4f6;
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
    transition: 0.2s;
    cursor: pointer;
    white-space: nowrap;
}
.btn2:hover { background: rgba(255,255,255,0.1); transform: translateY(-1px); }

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--card-bg);
}

.table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
.table th, .table td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 13px; vertical-align: middle; }
.table th { color: var(--muted); text-align: left; font-weight: 800; text-transform: uppercase; background: rgba(0,0,0,0.2); letter-spacing: 0.5px; }

.badge { display: inline-flex; padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 10px; border: 1px solid var(--border); text-transform: uppercase; white-space: nowrap; }
.badge-ok { background: rgba(34,197,94,0.1); color: #4ade80; border-color: rgba(34,197,94,0.2); }
.badge-off { background: rgba(239,68,68,0.1); color: #f87171; border-color: rgba(239,68,68,0.2); }
.badge-admin { background: rgba(99,102,241,0.15); color: #a5b4fc; }
.badge-profesor { background: rgba(139, 92, 246, 0.15); color: #c4b5fd; }
.badge-alumno { background: rgba(245,158,11,0.1); color: #fcd34d; }
.badge-nivel { background: rgba(255,255,255,0.05); color: #d1d5db; }

.actions { display: flex; gap: 6px; justify-content: flex-end; }
.small { padding: 8px 12px; border-radius: 10px; font-size: 11px; }

.btn-ver { background: rgba(79, 70, 229, 0.2) !important; border-color: rgba(79, 70, 229, 0.3) !important; color: #a5b4fc !important; }
.btn-danger { background: rgba(239,68,68,0.1) !important; border-color: rgba(239,68,68,0.2) !important; color: #f87171 !important; }
.btn-restore { background: rgba(34, 197, 94, 0.15) !important; border-color: rgba(34, 197, 94, 0.3) !important; color: #4ade80 !important; }

.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; }
.active-pag { background: var(--accent) !important; border-color: var(--accent) !important; color: #fff !important; }

.txt-elimina { color: #fca5a5; font-size: 10px; font-weight: 800; display: block; margin-top: 4px; }

@media (max-width: 600px) {
    .toolbar .btn2 { flex: 1; }
    .toolbar .input { flex: 1 1 100%; }
}
</style>

<h1>Gestión de Usuarios</h1>

<form class="toolbar" method="get" action="usuarios.php">
  <input class="input" name="q" placeholder="Buscar por email, nombre o apellido..." value="<?= htmlspecialchars($q) ?>">
  <button class="btn2" type="submit"><i class="fas fa-search"></i> Buscar</button>
  <a class="btn2 btn-all" href="usuarios.php">Limpiar filtros</a>
</form>

<div class="table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Usuario</th>
          <th>Nivel</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Registro</th>
          <th>Último login</th>
          <th style="text-align:right">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($u = $res->fetch_assoc()): 
          $fechaE = $u['fecha_eliminacion'] ?? null;
      ?>
        <tr style="<?= $fechaE ? 'background: rgba(239, 68, 68, 0.04);' : '' ?>">
          <td style="font-family: monospace; opacity: 0.5;">#<?= (int)$u['id'] ?></td>
          <td>
            <div style="font-weight: 700; color: #fff;">
                <?= htmlspecialchars(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''))) ?>
            </div>
            <div style="font-size: 11px; color: var(--muted);"><?= htmlspecialchars((string)$u['email']) ?></div>
            <?php if ($fechaE): ?>
                <span class="txt-elimina"><i class="fas fa-clock"></i> ELIMINACIÓN: <?= date('d/m/Y', strtotime($fechaE)) ?></span>
            <?php endif; ?>
          </td>
          <td>
             <span class="badge badge-nivel"><?= htmlspecialchars(ucfirst($u['nivel'] ?? 'principiante')) ?></span>
          </td>
          <td>
            <?php 
            $rol = $u['rol'] ?? 'alumno';
            switch($rol) {
                case 'admin': echo '<span class="badge badge-admin">admin</span>'; break;
                case 'profesor': echo '<span class="badge badge-profesor">profesor</span>'; break;
                case 'admin-profesor': echo '<span class="badge badge-admin" style="border-style: dashed;">admin-prof</span>'; break;
                default: echo '<span class="badge badge-alumno">alumno</span>';
            }
            ?>
          </td>
          <td>
            <?php if ($fechaE): ?>
                <span class="badge badge-off">Eliminando</span>
            <?php elseif ((int)$u['activo'] === 1): ?>
              <span class="badge badge-ok">activo</span>
            <?php else: ?>
              <span class="badge badge-off">inactivo</span>
            <?php endif; ?>
          </td>
          <td style="font-size: 11px; color: var(--muted);"><?= date('d/m/y', strtotime($u['fecha_registro'])) ?></td>
          <td style="font-size: 11px; color: #818cf8; font-weight: 600;"><?= $u['ultimo_login'] ? date('d/m/y H:i', strtotime($u['ultimo_login'])) : 'Nunca' ?></td>
          <td>
            <div class="actions">
              <a class="btn2 small btn-ver" href="usuarios-ver.php?id=<?= (int)$u['id'] ?>">Ver</a>
              
              <?php if ($fechaE): ?>
                  <a class="btn2 small btn-restore" href="usuarios-restaurar.php?id=<?= (int)$u['id'] ?>" 
                     onclick="return confirm('¿Deseas restaurar este usuario y cancelar su eliminación?')">
                     Restaurar
                  </a>
              <?php else: ?>
                  <a class="btn2 small" href="usuarios-editar.php?id=<?= (int)$u['id'] ?>">Editar</a>
                  <a class="btn2 small btn-danger" href="usuarios-eliminar.php?id=<?= (int)$u['id'] ?>&confirm=yes" 
                     onclick="return confirm('¿Confirmas que deseas eliminar este usuario? Podrás restaurarlo durante los próximos 7 días.')">
                     Eliminar
                  </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endwhile; ?>
      <?php if ($res->num_rows === 0): ?>
        <tr><td colspan="8" style="text-align:center; padding:50px; color:var(--muted)">No se encontraron usuarios registrados con esos criterios.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
</div>

<div class="pagination">
    <?php if ($totalPages > 1): ?>
        <?php for($i=1; $i<=$totalPages; $i++): ?>
            <a href="usuarios.php?q=<?= urlencode($q) ?>&p=<?= $i ?>" 
               class="btn2 small page-link <?= $i == $page ? 'active-pag' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    <?php endif; ?>
</div>

<script>
function cargarUsuarios(url, push = true) {
    const container = document.querySelector('.table-wrapper');
    container.style.opacity = '0.5';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            document.querySelectorAll('.table-wrapper').forEach((wrapper, index) => {
                const newContent = doc.querySelectorAll('.table-wrapper')[index];
                if(newContent) wrapper.innerHTML = newContent.innerHTML;
            });
            document.querySelectorAll('.pagination').forEach((pag, index) => {
                const newPag = doc.querySelectorAll('.pagination')[index];
                if(newPag) pag.innerHTML = newPag.innerHTML;
            });
            container.style.opacity = '1';
            if (push) history.pushState(null, '', url);
        });
}
document.addEventListener('click', function(e) {
    const link = e.target.closest('.page-link');
    if (link) {
        e.preventDefault();
        cargarUsuarios(link.href);
    }
});
document.querySelectorAll('.toolbar').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const params = new URLSearchParams(new FormData(this));
        const url = this.action + '?' + params.toString();
        cargarUsuarios(url);
    });
});
document.addEventListener('click', function(e) {
    const link = e.target.closest('.btn-all');
    if (link) {
        e.preventDefault();
        cargarUsuarios(link.href);
    }
});
window.addEventListener('popstate', () => {
    cargarUsuarios(location.href, false);
});
</script>