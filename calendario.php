<?php 
require_once 'calendario_logic.php';
include_once 'navbar.php'; 
$mensaje = $_SESSION['msg_calendario'] ?? null;
unset($_SESSION['msg_calendario']);

if ($mensaje): 
    $esError = (strpos(strtolower($mensaje), 'límite') !== false || strpos(strtolower($mensaje), 'error') !== false || strpos(strtolower($mensaje), 'lo sentimos') !== false);
    $bg_color = $esError ? '#ef4444' : '#6366f1'; 
?>
    <div id="temp-msg" style="position:fixed; top:20px; right:20px; background:<?= $bg_color ?>; color: #fff; padding:15px; z-index:9999; border-radius:8px; font-weight:bold; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">
        <?= $mensaje ?>
    </div>
    <script>
        setTimeout(() => { 
            const msg = document.getElementById('temp-msg');
            if(msg) msg.style.display='none'; 
        }, 3000);
    </script>
<?php endif; ?>

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

<style>
    :root {
        --bg-color: #131527; 
        --bg-darker: #0d101b;
        --glass-bg: rgba(255, 255, 255, 0.05); 
        --glass-border: rgba(255, 255, 255, 0.12); 
        --text: #f8fafc; 
        --muted: #cbd5e1; 
        --accent: #8b5cf6; 
        --accent-glow: rgba(139, 92, 246, 0.5);
        --gradient-primary: linear-gradient(135deg, #8b5cf6, #6d28d9);
        --radius: 24px;
        --glass: #111827; 
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

    .full-container { 
        width: 100%; 
        padding: 20px; 
        box-sizing: border-box; 
        min-height: 110vh; 
        display: flex; 
        flex-direction: column;
    }

    .cal-header { 
        background: var(--glass-bg); 
        backdrop-filter: blur(20px); 
        border: 1px solid var(--glass-border); 
        border-radius: var(--radius); 
        padding: 15px 30px; 
        margin-bottom: 30px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        flex-wrap: wrap; 
        gap: 15px; 
        box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    }

    .filter-btn { 
        background: rgba(139, 92, 246, 0.05); 
        border: 1px solid var(--glass-border); 
        color: #fff; 
        padding: 10px 20px; 
        border-radius: 12px; 
        cursor: pointer; 
        font-size: 14px; 
        font-weight: 700; 
        transition: all 0.3s ease; 
        text-transform: capitalize; 
    }
    .filter-btn:hover {
        background: rgba(139, 92, 246, 0.15);
    }
    .filter-btn.active { 
        background: var(--gradient-primary); 
        border-color: transparent; 
        box-shadow: 0 5px 15px var(--accent-glow); 
    }

    .week-days { 
        display: grid; 
        grid-template-columns: repeat(7, 1fr); 
        gap: 15px; 
        margin-bottom: 10px; 
        text-align: center; 
    }
    .week-label { 
        font-size: 18px; 
        font-weight: 800; 
        color: #ffffff; 
        text-transform: uppercase; 
        text-shadow: 0 0 10px var(--accent-glow); 
    }

    .cal-grid { 
        display: grid; 
        grid-template-columns: repeat(7, 1fr); 
        gap: 15px; 
    }

    .day-card { 
        background: var(--glass-bg); 
        border: 1px solid var(--glass-border); 
        border-radius: 18px; 
        padding: 15px; 
        height: 95px; 
        position: relative; 
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
        cursor: pointer; 
        backdrop-filter: blur(10px);
    }
    
    .day-card:hover:not(.disabled):not(.past) {
        transform: translateY(-5px);
        border-color: rgba(139, 92, 246, 0.5);
        box-shadow: 0 10px 20px rgba(0,0,0,0.3);
    }

    .day-card.disabled { opacity: 0.15; pointer-events: none; filter: grayscale(1); transform: scale(0.95); }
    .day-card.highlight { 
        border-color: var(--accent); 
        border-width: 2px; 
        box-shadow: 0 0 25px var(--accent-glow); 
        background: rgba(139, 92, 246, 0.15); 
    }
    .day-card.past { opacity: 0.1; filter: grayscale(1); pointer-events: none; }
    .day-card.is-today { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.2); }

    .day-num { font-size: 2rem; font-weight: 900; color: #fff; line-height: 1; }
    .status-msg { font-size: 10px; font-weight: 800; text-transform: uppercase; margin-top: 8px; }
    .status-available { color: #4ade80; } /* Verde más brillante para resaltar en el fondo oscuro */
    .status-none { color: #f87171; opacity: 0.8; } /* Rojo más brillante */

    .cal-footer { 
        text-align: center; 
        padding: 80px 20px; 
        margin-top: auto; 
    }
    .musical-quote { 
        font-family: 'Georgia', serif; 
        font-style: italic; 
        font-size: 22px; 
        color: rgba(255,255,255,0.4); 
        letter-spacing: 1px;
    }
    .musical-quote span { color: var(--accent); text-shadow: 0 0 10px var(--accent-glow); }

    /* ESTILOS DEL MODAL */
    .modal-overlay { 
        position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(15px); 
        display: none; align-items: center; justify-content: center; z-index: 9999; padding: 20px; 
    }
    .modal-card { 
        background: var(--bg-darker); border: 1px solid var(--glass-border); width: 100%; max-width: 500px; 
        border-radius: var(--radius); padding: 30px; max-height: 85vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.6), 0 0 40px rgba(139,92,246,0.1); 
    }
    .slot-row {
        background: var(--glass-bg); border: 1px solid var(--glass-border); padding: 15px; 
        border-radius: 15px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
        transition: 0.3s ease;
    }
    .slot-row:hover { background: rgba(255, 255, 255, 0.08); border-color: rgba(139, 92, 246, 0.4); }

    @media only screen and (max-width: 768px) {
        .full-container { min-height: 100vh; }
        .week-days { display: none !important; }
        .cal-grid { 
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)) !important; 
            gap: 10px !important;
        }
        .day-num { font-size: 1.5rem !important; }
        .cal-header h1 { font-size: 18px !important; }
        .cal-footer { padding: 40px 20px; }
        .musical-quote { font-size: 16px; }
    }

    .btn-crear-reserva {
        background: var(--gradient-primary);
        color: #fff;
        padding: 12px 24px;
        border-radius: 14px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        border: none;
        box-shadow: 0 10px 25px var(--accent-glow);
        margin-left: 10px;
    }

    .btn-crear-reserva:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 35px rgba(139, 92, 246, 0.7);
    }

    .btn-crear-reserva i {
        font-size: 12px;
    }

    @media only screen and (max-width: 768px) {
        .btn-crear-reserva {
            width: 100%;
            justify-content: center;
            margin-left: 0;
            margin-top: 10px;
        }
    }
</style>

<div class="full-container">
  <header class="cal-header">
    <div style="display:flex; align-items:center; gap:15px;">
        <a href="?mes=<?= $prevMonth->format('m') ?>&anio=<?= $prevMonth->format('Y') ?>" class="filter-btn">« Anterior</a>
        
        <div style="text-align: center;">
            <h1 style="margin:0; font-size: 24px; font-weight: 900;">
                <?= $mesNombre ?> <span style="color: var(--accent);"><?= $anioActual ?></span>
            </h1>
            
            <?php if ($currentView->format('Y-m') === $today->format('Y-m')): ?>
                <span style="font-size: 10px; color: var(--accent); text-transform: uppercase; letter-spacing: 1px; font-weight: 800;">
                    ● Mes Actual
                </span>
            <?php endif; ?>
        </div>
        
        <a href="?mes=<?= $nextMonth->format('m') ?>&anio=<?= $nextMonth->format('Y') ?>" class="filter-btn">Siguiente »</a>
    </div>

    <div style="display:flex; gap:10px; flex-wrap: wrap; align-items: center;">
    <button class="filter-btn active" id="btn-all" onclick="filterType('all', this)">Todos</button>
    <?php foreach($todosLosInstrumentos as $inst): ?>
        <button class="filter-btn" onclick="filterType('<?= strtolower(h($inst)) ?>', this)"><?= h($inst) ?></button>
    <?php endforeach; ?>

    <?php if (in_array($_SESSION['user_rol'], ['profesor', 'admin-profesor'])): ?>
    <a href="crear-reserva.php" class="btn-crear-reserva">
        <i class="fas fa-plus"></i> Crear o Eliminar Horario
    </a>
<?php endif; ?>
</div>
    
</header>
<div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
  <div class="week-days">
    <?php foreach(['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'] as $l): ?> 
        <div class="week-label"><?= $l ?></div> 
    <?php endforeach; ?>
  </div>

  <div class="cal-grid">
    <?php 
    $primerDiaMesW = (int)$currentView->format('w');
    for ($x = 0; $x < $primerDiaMesW; $x++) echo '<div class="day-card" style="opacity:0; pointer-events:none;"></div>';

    for ($d = 1; $d <= $diasEnMes; $d++): 
        $fechaStr = $currentView->format('Y-m-') . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $fechaDT = new DateTime($fechaStr);
        $esPasado = ($fechaDT < $today);
        
        $instsDelDia = $disponibilidadPorDia[$fechaStr] ?? [];

        // Filtro de instrumentos
        $instrumentosReales = array_filter($instsDelDia, function($item) {
            return !str_starts_with($item, 'tipo_');
        });

        // Un día está deshabilitado si ya pasó O si se acabaron los cupos 
        $estaDeshabilitado = ($esPasado || empty($instrumentosReales));

        $claseCard = 'day-card';
        if ($d == $hoy && $currentView->format('Y-m') == $today->format('Y-m')) $claseCard .= ' is-today';
        if ($estaDeshabilitado) $claseCard .= ' disabled';
    ?>

    <div class="<?= $claseCard ?>" 
         id="day-<?= $d ?>"
         data-fecha="<?= $fechaStr ?>"
         data-insts='<?= json_encode($instsDelDia) ?>'
         data-disponible="<?= $estaDeshabilitado ? '0' : '1' ?>" 
         <?= !$estaDeshabilitado ? 'onclick="openDay(this)"' : '' ?>>
        
        <div class="day-num"><?= $d ?></div>
        
        <?php if(!$esPasado): ?>
            <div class="status-msg <?= empty($instrumentosReales) ? 'status-none' : 'status-available' ?>">
                <?php if(empty($instrumentosReales)): ?>
                    Sin Cupos
                <?php else: ?>
                    <div>Disponible</div>
                    <div style="display:flex; gap:3px; margin-top:5px; flex-wrap:wrap; justify-content:center;">
                        <?php if(in_array('tipo_fijo', $instsDelDia)): ?>
                            <span style="font-size:9px; padding:2px 4px; border:1px solid #8b5cf6; border-radius:4px; color:#fff; background:rgba(139,92,246,0.3); font-weight:bold;">FIJO</span>
                        <?php endif; ?>
                        <?php if(in_array('tipo_extra', $instsDelDia)): ?>
                            <span style="font-size:9px; padding:2px 4px; border:1px solid #10b981; border-radius:4px; color:#fff; background:rgba(16,185,129,0.3); font-weight:bold;">EXTRA</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

      </div>
    <?php endfor; ?>
  </div>

  <footer class="cal-footer">
      <div class="musical-quote">
          "Donde las palabras fallan, la <span>música</span> habla."
      </div>
      <p style="font-size: 11px; opacity: 0.3; margin-top: 15px; letter-spacing: 2px; text-transform: uppercase;">Anima Music</p>
  </footer>
</div>

<div id="modalDia" class="modal-overlay" onclick="cerrarModalDia(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <h2 id="modalDiaTitle" style="margin:0; font-size: 1.5rem; color:#fff; font-weight:900;"></h2>
            <button style="background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:22px;" onclick="document.getElementById('modalDia').style.display='none'">✕</button>
        </div>
        <div id="modalDiaContent" style="display:flex; flex-direction:column;"></div>
    </div>
</div>

<script>
const datosHorarios = <?= json_encode($horariosView) ?>;
const currentUserId = <?= (int)$user_id ?>; 
const maxSemanal = <?= (int)$max_semanal ?>;
const btnHtml = esMiClase 
    ? `<span style="font-size:10px; color:var(--accent); font-weight:800; text-transform:uppercase; margin-right:10px;">Tu horario</span>` 
    : `<form action="procesar.php" method="POST" style="margin:0;">
            <input type="hidden" name="accion" value="reservar">
            <input type="hidden" name="horario_id" value="${h.id}">
            <input type="hidden" name="fecha_seleccionada" value="${fecha}">
            <input type="hidden" name="tipo_turno_elegido" value="${h.tipo}">
            <input type="hidden" name="from" value="calendario.php">
            <button type="submit" style="background:var(--accent); color:#fff; border:none; padding:8px 18px; border-radius:10px; font-weight:bold; cursor:pointer;" 
            onclick="return confirmarReservaConLimite()">Reservar</button>
       </form>`;

function openDay(c) { 
    if(c.classList.contains('past') || c.classList.contains('disabled')) return; 
    
    const fecha = c.dataset.fecha;
    if(!fecha || !datosHorarios[fecha]) return;

    const modal = document.getElementById('modalDia');
    const content = document.getElementById('modalDiaContent');
    const title = document.getElementById('modalDiaTitle');

    const fParts = fecha.split('-');
    title.innerHTML = `Turnos del <span style="color:var(--accent)">${fParts[2]}/${fParts[1]}</span>`;
    content.innerHTML = '';

    const datosDia = datosHorarios[fecha];
    let hayTurnos = false;

    // Iteramos imitando profesores.php
    for (const inst in datosDia) {
        for (const pId in datosDia[inst]) {
            const prof = datosDia[inst][pId];
            prof.horarios.forEach(h => {
                hayTurnos = true;
                const esMiClase = (parseInt(pId) === currentUserId);
                
                const tipoTag = h.tipo === 'fijo' 
                    ? '<span style="background:rgba(139,92,246,0.2); color:#c084fc; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:bold; text-transform:uppercase;">Fijo</span>' 
                    : '<span style="background:rgba(16,185,129,0.2); color:#34d399; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:bold; text-transform:uppercase;">Extra</span>';
                
                const btnHtml = esMiClase 
                    ? `<span style="font-size:10px; color:var(--accent); font-weight:800; text-transform:uppercase; margin-right:10px;">Tu horario</span>` 
                    : `<form action="procesar.php" method="POST" style="margin:0;">
                            <input type="hidden" name="accion" value="reservar">
                            <input type="hidden" name="horario_id" value="${h.id}">
                            <input type="hidden" name="fecha_seleccionada" value="${fecha}">
                            <input type="hidden" name="tipo_turno_elegido" value="${h.tipo}">
                            <input type="hidden" name="from" value="calendario.php">
                            <button type="submit" style="background:var(--accent); color:#fff; border:none; padding:8px 18px; border-radius:10px; font-weight:bold; cursor:pointer;" onclick="return confirm('¿Reservar turno?')">Reservar</button>
                       </form>`;

                content.innerHTML += `
                    <div class="slot-row">
                        <div>
                            <strong style="font-size:1.15rem; color:#fff;">${h.hora} hs</strong>
                            <div style="font-size:0.8rem; color:#94a3b8; margin-top:4px; font-weight:600;">
                                ${inst.toUpperCase()} - Prof. ${prof.nombre}
                            </div>
                            <div style="margin-top:6px; display:flex; gap:8px; align-items:center;">
                                ${tipoTag}
                                <span style="font-size:11px; color:#94a3b8;"><i class="fas fa-video"></i> ${h.modalidad || 'Presencial'}</span>
                            </div>
                        </div>
                        <div>${btnHtml}</div>
                    </div>
                `;
            });
        }
    }

    if (!hayTurnos) {
        content.innerHTML = '<p style="text-align:center; color:#94a3b8; padding:20px;">No hay turnos disponibles para este día.</p>';
    }

    modal.style.display = 'flex';
}

function cerrarModalDia(e) {
    if (e.target.id === 'modalDia') {
        document.getElementById('modalDia').style.display = 'none';
    }
}

// Filtro superior original intacto
function filterType(instrumento, btn) {
    document.querySelectorAll('.filter-btn').forEach(x => x.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('.day-card').forEach(card => {
        if (card.dataset.disponible === '0') {
            card.classList.add('disabled');
            card.removeAttribute('onclick');
            return; 
        }
        
        const instsData = card.dataset.insts;
        const instsDelDia = instsData ? JSON.parse(instsData) : [];
        const instrumentosReales = instsDelDia.filter(i => !i.startsWith('tipo_'));

        if (instrumento === 'all') {
            card.classList.remove('disabled', 'highlight');
            card.setAttribute('onclick', 'openDay(this)');
        } else {
            if (instrumentosReales.includes(instrumento)) {
                card.classList.remove('disabled');
                card.classList.add('highlight');
                card.setAttribute('onclick', 'openDay(this)');
            } else {
                card.classList.add('disabled');
                card.classList.remove('highlight');
                card.removeAttribute('onclick');
            }
        }
    });
}
document.addEventListener('DOMContentLoaded', () => filterType('all', document.getElementById('btn-all')));
function confirmarReservaConLimite() {
    return confirm("¿Deseas reservar este turno?\n\nRecuerda que tienes un límite de " + maxSemanal + " reservas por semana.");
}
</script>