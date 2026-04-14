<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['user_rol'])) {
    $_SESSION['user_rol'] = strtolower(trim(str_replace(["\r", "\n"], '', (string)$_SESSION['user_rol'])));
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$userRol = $_SESSION['user_rol'] ?? 'alumno';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

include_once 'navbar.php';


date_default_timezone_set('America/Argentina/Buenos_Aires');
$ahora = date('Y-m-d H:i:s');

if (str_contains($userRol, 'profesor')) {
    $sqlIdProf = "SELECT id FROM profesores WHERE nombre = (SELECT nombre FROM usuarios WHERE id = ?)";
    $profesorData = $db->fetchAll($sqlIdProf, [$userId]);
    $profesorIdReal = !empty($profesorData) ? (int)$profesorData[0]['id'] : 0;

    $sqlTurnos = "SELECT r.fecha, r.estado, r.es_recurrente,
                         h.hora, h.dia_semana, h.duracion_minutos, h.instrumento, h.modalidad,
                         u.nombre as persona_nombre,
                         IF(r.es_recurrente=1, 'Fijo', 'Extra') as tipo
                  FROM reservas r
                  JOIN horarios h ON r.horario_id = h.id
                  JOIN usuarios u ON r.usuario_id = u.id
                  WHERE h.profesor_id = ? 
                  -- Filtro dinámico: Fecha mayor a hoy O (es hoy y la hora es futura)
                  AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND h.hora >= CURTIME()))
                  AND r.estado IN ('confirmada', 'pendiente')
                  ORDER BY r.fecha ASC, h.hora ASC"; // SE ELIMINÓ EL LIMIT 5
                  
    $proximos = $db->fetchAll($sqlTurnos, [$profesorIdReal]);
} else {
    $sqlTurnos = "SELECT r.fecha, r.estado, r.es_recurrente,
                         h.hora, h.dia_semana, h.duracion_minutos, h.instrumento, h.modalidad,
                         p.nombre as persona_nombre, 
                         IF(r.es_recurrente=1, 'Fijo', 'Extra') as tipo
                  FROM reservas r
                  JOIN horarios h ON r.horario_id = h.id
                  JOIN profesores p ON h.profesor_id = p.id
                  WHERE r.usuario_id = ?
                  AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND h.hora >= CURTIME()))
                  AND r.estado IN ('confirmada', 'pendiente')
                  ORDER BY r.fecha ASC, h.hora ASC"; // SE ELIMINÓ EL LIMIT 5
                  
    $proximos = $db->fetchAll($sqlTurnos, [$userId]);
}

$fechasOcupadas = array_column($proximos, 'fecha');
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

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
    }

    body {
        margin: 0;
        font-family: 'Inter', sans-serif;
        background: 
            radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), 
            radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), 
            linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
        background-attachment: fixed;
        color: var(--text);
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

    .dashboard-layout {
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 30px;
        padding: 40px 20px;
        max-width: 1300px;
        margin: 0 auto;
        box-sizing: border-box;
    }

    .glass-card {
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        border-radius: var(--radius);
        padding: 30px;
        backdrop-filter: blur(20px);
        box-shadow: 0 20px 50px rgba(0,0,0,0.4), 0 0 30px rgba(139, 92, 246, 0.05);
        box-sizing: border-box;
    }

    .helper-box {
        background: rgba(139, 92, 246, 0.1);
        border: 1px solid rgba(139, 92, 246, 0.3);
        padding: 20px;
        border-radius: 20px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 18px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }

    .mini-cal-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 8px;
        margin-top: 15px;
    }

    .cal-dot {
        aspect-ratio: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        font-size: 0.85rem;
        background: rgba(255,255,255,0.03);
        transition: 0.3s;
        border: 1px solid transparent;
    }

    .cal-dot.active { background: var(--gradient-primary); color: white; box-shadow: 0 5px 15px var(--accent-glow); font-weight: bold; border: none; }
    .cal-dot.today { border: 1px solid var(--accent); color: #fff; }

    .turno-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 0;
        border-bottom: 1px solid var(--glass-border);
        gap: 15px;
        transition: 0.3s ease;
    }
    
    .turno-item:hover {
        background: rgba(255, 255, 255, 0.02);
        border-radius: 12px;
        padding-left: 10px;
        padding-right: 10px;
        margin-left: -10px;
        margin-right: -10px;
        border-bottom-color: transparent;
    }

    /* --- ESTILOS DE BADGES (ESTADO Y TIPO) --- */
    .badge-tipo, .badge-estado {
        font-size: 11px;
        padding: 6px 14px;
        border-radius: 10px;
        text-transform: uppercase;
        font-weight: 800;
        display: inline-block;
        letter-spacing: 0.5px;
    }

    /* Colores Estados */
    .estado-confirmada { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
    .estado-pendiente { background: rgba(234, 179, 8, 0.15); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.3); }

    /* Diferenciación de Tipos de Turno */
    .tipo-fijo { 
        background: rgba(139, 92, 246, 0.2); 
        color: #c4b5fd; 
        border: 1px solid rgba(139, 92, 246, 0.4); 
    }
    .tipo-extra { 
        background: rgba(14, 165, 233, 0.2); 
        color: #7dd3fc; 
        border: 1px solid rgba(14, 165, 233, 0.4); 
    }

    .btn-glow {
        background: var(--gradient-primary);
        color: #fff;
        padding: 14px;
        border-radius: 14px;
        font-weight: 800;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.3s;
        text-transform: uppercase;
        box-shadow: 0 10px 25px var(--accent-glow);
        letter-spacing: 1px;
    }
    .btn-glow:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 35px rgba(139, 92, 246, 0.7);
    }

    /* --- RESPONSIVE --- */
    @media (max-width: 850px) {
        .dashboard-layout { grid-template-columns: 1fr; }
        aside { order: -1; }
        .turno-item { flex-direction: column; align-items: flex-start; }
        .turno-item > div:last-child {
            width: 100%;
            text-align: left !important;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--glass-border);
            padding-top: 15px;
        }
    }
</style>

<div class="dashboard-layout">

<div class="particles">
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
</div>
    
    <main>
        <div class="helper-box">
            <div style="background: var(--accent); width: 45px; height: 45px; border-radius: 12px; display:flex; align-items:center; justify-content:center; flex-shrink: 0;">
                <i class="fas fa-lightbulb" style="color: white;"></i>
            </div>
            <div>
                <h4 style="margin:0; font-size: 1rem;">Asistente Anima</h4>
                <p style="margin:0; font-size: 0.85rem; color: #94a3b8;">
                    <?php if(str_contains($userRol, 'profesor')): ?>
                        Agenda activa. Revisa los turnos que requieren confirmación.
                    <?php else: ?>
                        Tus próximas clases programadas.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="glass-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 10px;">
                <h2 style="margin:0; font-size: 1.8rem;">Próximos Turnos</h2>
                <span style="font-size: 0.9rem; opacity: 0.7; font-weight: 500;">Sesión: <?= h(ucfirst($userRol)) ?></span>
            </div>

            <div class="list">
                <?php if (empty($proximos)): ?>
                    <div style="text-align: center; padding: 50px 0;">
                        <i class="far fa-calendar-times" style="font-size: 2.5rem; color: var(--stroke); margin-bottom: 15px;"></i>
                        <p style="color: #94a3b8; font-size: 1.1rem;">No hay turnos registrados.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($proximos as $t): ?>
                        <div class="turno-item">
                            <div style="flex: 1;">
                                <div style="font-weight: 800; font-size: 1.3rem; display: flex; align-items: center; color: #fff; flex-wrap: wrap; gap: 12px;">
                                    <?= date('d/m', strtotime((string)$t['fecha'])) ?> — <?= substr((string)$t['hora'], 0, 5) ?> hs
                                    <span class="badge-estado estado-<?= strtolower($t['estado']) ?>">
                                        <?= h(ucfirst($t['estado'])) ?>
                                    </span>
                                </div>
                                
                                <div style="font-size: 1.1rem; margin-top: 8px; color: #cbd5e1;">
                                    <i class="fas fa-user-circle" style="color: var(--accent); margin-right: 5px;"></i>
                                    <?= str_contains($userRol, 'profesor') ? 'Alumno' : 'Profesor' ?>: 
                                    <strong style="color: #fff;"><?= h((string)$t['persona_nombre']) ?></strong>
                                </div>

                                <div style="display: flex; gap: 12px; margin-top: 10px; flex-wrap: wrap;">
                                    <span style="color: #94a3b8; font-size: 0.9rem;"><i class="far fa-clock"></i> <?= $t['duracion_minutos'] ?> min</span>
                                    <span style="color: #94a3b8; font-size: 0.9rem;"><i class="fas fa-music"></i> <?= h((string)$t['instrumento']) ?></span>
                                </div>
                            </div>

                            <div style="text-align: right; min-width: 160px;">
                                <div class="badge-tipo tipo-<?= strtolower($t['tipo']) ?>" style="margin-bottom: 10px;">
                                    TURNO <?= strtoupper($t['tipo']) ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #fff;">
                                    <i class="fas fa-video" style="color: var(--accent);"></i> <?= h((string)($t['modalidad'] ?? 'Presencial')) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <aside>
    <div class="glass-card mini-calendar">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; text-transform: capitalize;"><?= date('F Y') ?></h3>
                <p style="margin: 0; font-size: 0.75rem; color: #94a3b8;">Actividad mensual</p>
            </div>
            <div style="background: rgba(139, 92, 246, 0.1); padding: 5px 10px; border-radius: 8px; border: 1px solid rgba(139, 92, 246, 0.2);">
                <span style="font-size: 0.75rem; color: var(--accent); font-weight: 700;">
                    <?= count(array_unique($fechasOcupadas)) ?> Clases
                </span>
            </div>
        </div>

        <div class="mini-cal-grid">
            <?php
            // Calculamos cuántos días faltan hasta el último día del mes
            $hoy = new DateTime('today');
            $ultimoDiaMes = new DateTime('last day of this month');
            $intervalo = $hoy->diff($ultimoDiaMes);
            $diasRestantes = $intervalo->days;

            $inicio = clone $hoy;
            // Iteramos desde hoy hasta el último día del mes
            for($i = 0; $i <= $diasRestantes; $i++):
                $fActual = $inicio->format('Y-m-d');
                $esHoy = ($i === 0);
                $tieneClase = in_array($fActual, $fechasOcupadas);
            ?>
                <div class="cal-dot <?= $esHoy ? 'today' : '' ?> <?= $tieneClase ? 'active' : '' ?>" 
                     title="<?= $tieneClase ? 'Tienes clase este día' : '' ?>">
                    <strong style="font-size: 0.9rem;"><?= $inicio->format('d') ?></strong>
                </div>
            <?php 
                $inicio->modify('+1 day'); 
            endfor; 
            ?>
        </div>
        
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--stroke); display: flex; gap: 15px;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--accent);"></div>
                <span style="font-size: 0.7rem; color: #94a3b8;">Día de clase</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 8px; height: 8px; border-radius: 50%; border: 1px solid var(--accent);"></div>
                <span style="font-size: 0.7rem; color: #94a3b8;">Hoy</span>
            </div>
        </div>
    </div>

    <div class="glass-card" style="margin-top: 20px;">
        <a href="calendario.php" class="btn-glow" style="margin-bottom: 12px;">Reservar Clase</a>
        <a href="mis-reservas.php" style="display: block; text-decoration: none; color: #94a3b8; font-size: 0.9rem; text-align: center; padding: 10px; border: 1px solid var(--stroke); border-radius: 12px; transition: 0.3s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--stroke)'">
            Ver Historial Completo
        </a>
    </div>
</aside>
</div>

<script>
    document.querySelectorAll('.turno-item').forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(10px)';
        el.style.transition = 'all 0.5s ease';
        setTimeout(() => {
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        }, i * 120);
    });
</script>