<?php 
require_once __DIR__ . '/profesores1_logic.php'; 
require_once __DIR__ . '/header.php'; 
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

h1 { font-size: 1.5rem; letter-spacing: -0.5px; font-weight: 800; }

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

.container-center { display: flex; justify-content: center; width: 100%; margin-bottom: 30px; }

.card { 
    border: 1px solid var(--border); 
    background: var(--card-bg); 
    border-radius: 20px; 
    padding: 25px; 
    width: 100%; 
    max-width: 700px;
    backdrop-filter: blur(10px);
}

.row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

@media (max-width: 600px) {
    .row { grid-template-columns: 1fr; }
    .toolbar .btn2 { flex: 1; }
    .card { padding: 20px; }
}

label { display: block; font-weight: 800; font-size: 11px; color: var(--muted); text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px; }

.instrumentos-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); 
    gap: 10px; 
    background: rgba(0,0,0,0.2); 
    padding: 15px; 
    border-radius: 12px; 
    border: 1px solid var(--border); 
    max-height: 200px; 
    overflow-y: auto; 
}

.check-tag { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: #cbd5e1; }

.select-dark { 
    width: 100%; 
    background: #0f172a; 
    color: #f3f4f6; 
    padding: 12px; 
    border-radius: 12px; 
    border: 1px solid var(--border); 
    font-size: 14px;
    outline: none;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--card-bg);
}

.table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 850px; }
.table th, .table td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 13px; }
.table th { color: var(--muted); text-align: left; font-weight: 800; text-transform: uppercase; background: rgba(0,0,0,0.2); letter-spacing: 0.5px; }
.table td { color: #e2e8f0; vertical-align: middle; }

.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.small { padding: 8px 12px; border-radius: 10px; font-size: 11px; }

.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; flex-wrap: wrap; }
.active-pag { background: var(--accent) !important; border-color: var(--accent) !important; color: #fff !important; }

.text-prof { font-weight: 800; color: #fff; font-size: 14px; }
.text-sub { font-size: 11px; color: var(--muted); }
.text-esp { color: #a78bfa; font-weight: 700; }
</style>

<h1>Profesores</h1>

<?php if ($msg): ?><p style="color:#4ade80; font-weight:800; font-size: 14px;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p style="color:#f87171; font-weight:800; font-size: 14px;"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<?php if (isset($_GET['new']) || $id > 0): ?>
<div class="container-center">
    <div class="card">
        <h2 style="color:var(--accent); margin-top:0; text-align:center; font-size: 1.2rem;"><?= $id > 0 ? 'Editar perfil de profesor' : 'Ascender usuario a profesor' ?></h2>
        <form method="post" action="profesores1.php<?= $id > 0 ? '?id='.$id : '' ?>">
            <?php if ($id === 0): ?>
                <div style="margin-bottom: 20px;">
                    <label>Seleccionar Usuario</label>
                    <select name="usuario_id" class="select-dark" required>
                        <option value="" disabled selected>-- Buscar usuario para ascender --</option>
                        <?php foreach($candidatos as $can): ?>
                            <option value="<?= $can['id'] ?>"><?= htmlspecialchars($can['nombre']) ?> (<?= htmlspecialchars($can['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="row">
                <div style="grid-column: 1 / -1;">
                    <label>Nombre Público en la Academia</label>
                    <input class="input" name="nombre" value="<?= htmlspecialchars($prof['nombre']) ?>" required style="width:100%">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label>Especialidades e Instrumentos</label>
                    <div class="instrumentos-grid">
                        <?php 
                        $actuales = array_map('trim', explode(',', (string)$prof['especialidad']));
                        foreach($instrumentos_db as $ins): 
                        ?>
                            <label class="check-tag">
                                <input type="checkbox" name="instrumentos_array[]" value="<?= htmlspecialchars($ins) ?>" <?= in_array($ins, $actuales) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($ins) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div><label>Experiencia / Título</label><input class="input" name="experiencia" value="<?= htmlspecialchars($prof['experiencia']) ?>" style="width:100%"></div>
                <div><label>WhatsApp / Teléfono</label><input class="input" name="telefono" value="<?= htmlspecialchars($prof['telefono']) ?>" style="width:100%"></div>
                <div style="grid-column: 1 / -1;"><label>Email de contacto público</label><input class="input" name="email" value="<?= htmlspecialchars($prof['email']) ?>" style="width:100%"></div>
            </div>
            <div style="margin-top:15px">
                <label>Descripción / Bio para Alumnos</label>
                <textarea class="input" style="min-height:100px; width:100%; resize: vertical;" name="descripcion"><?= htmlspecialchars($prof['descripcion']) ?></textarea>
            </div>
            <div class="actions" style="margin-top:25px; justify-content: center;">
                <button class="btn2" type="submit" style="background:var(--accent); border-color:var(--accent); padding:12px 30px;">Guardar cambios</button>
                <a class="btn2" href="profesores1.php" style="padding:12px 30px;">Descartar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<form class="toolbar" method="get" action="profesores1.php">
    <input class="input" name="q" placeholder="Nombre, instrumento o email..." value="<?= htmlspecialchars($q) ?>">
    <button class="btn2" type="submit"><i class="fas fa-search"></i> Buscar</button>
    <a class="btn2 btn-all" href="profesores1.php">Ver todo</a>
    <a class="btn2" href="profesores1.php?new=1" style="border-color:var(--accent); background:rgba(139,92,246,0.1)">
        <i class="fas fa-user-plus"></i> Nuevo profesor
    </a>
</form>

<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th>Profesor</th>
                <th>Especialidades</th>
                <th>Descripción</th>
                <th>Teléfono</th>
                <th style="text-align:center">Horarios activos</th>
                <th style="text-align:right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php if($resList->num_rows > 0): ?>
            <?php while($p = $resList->fetch_assoc()): ?>
                <tr>
                    <td>
                        <div class="text-prof"><?= htmlspecialchars($p['nombre']) ?></div>
                        <div class="text-sub"><?= htmlspecialchars($p['email']) ?></div>
                    </td>
                    <td class="text-esp"><?= htmlspecialchars($p['especialidad']) ?></td>
                    <td>
                        <div style="max-width:250px; font-size:12px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
                            <?= htmlspecialchars($p['descripcion'] ?: 'Sin descripción pública') ?>
                        </div>
                    </td>
                    <td style="color:var(--muted); font-family:monospace; font-weight: 600;"><?= htmlspecialchars($p['telefono'] ?: '-') ?></td>
                    <td style="text-align:center">
                        <span style="font-weight:900; color:#60a5fa; background: rgba(96, 165, 250, 0.1); padding: 4px 8px; border-radius: 8px;">
                            <?= (int)$p['total_horarios'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions" style="justify-content:flex-end">
                            <a class="btn2 small" href="profesores1.php?id=<?= (int)$p['id'] ?>">Editar</a>
                            <a class="btn2 small" style="color:#f87171" 
                               href="profesores1_logic.php?action=delete&id=<?= (int)$p['id'] ?>&confirm=yes"
                               onclick="return confirm('¿Quitar rol de profesor a este usuario? Los horarios quedarán sin docente asignado.');">Baja</a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="6" style="text-align:center; padding:50px; color:var(--muted)">No se encontraron profesores registrados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="pagination">
    <?php if ($totalPages > 1): ?>
        <?php for($i=1; $i<=$totalPages; $i++): ?>
            <a href="profesores1.php?q=<?= urlencode($q) ?>&p=<?= $i ?>" 
               class="btn2 small page-link <?= $i == $page ? 'active-pag' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    <?php endif; ?>
</div>

<script>
function cargarProfesores(url, push = true) {
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
        cargarProfesores(link.href);
    }
});

document.querySelectorAll('.toolbar').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const params = new URLSearchParams(new FormData(this));
        const url = this.action + '?' + params.toString();
        cargarProfesores(url);
    });
});

document.addEventListener('click', function(e) {
    const link = e.target.closest('.btn-all');
    if (link) {
        e.preventDefault();
        cargarProfesores(link.href);
    }
});

window.addEventListener('popstate', () => {
    cargarProfesores(location.href, false);
});
</script>