<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirección si ya hay sesión
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
$dbConn = Database::getInstance()->getConnection();

// OBTENER 3 PROFESORES AL AZAR 
$profesores_destacados = [];
$query_profesores = "
    SELECT p.nombre, p.especialidad, p.descripcion, p.experiencia, u.avatar 
    FROM profesores p 
    LEFT JOIN usuarios u ON p.id = u.id 
    WHERE p.activo = 1 
    ORDER BY RAND() 
    LIMIT 3
";
$res_prof = $dbConn->query($query_profesores);
if ($res_prof) {
    while ($p = $res_prof->fetch_assoc()) {
        $profesores_destacados[] = $p;
    }
}

/**
 * Lee credenciales desde el JSON de Google
 */
function load_google_oauth_config(): array {
    $jsonPath = __DIR__ . '/client_secret_35991205187-oasjfgotcqsavn222k7frmlvhidt6f2m.apps.googleusercontent.com.json';
    if (!file_exists($jsonPath)) { throw new RuntimeException("No se encontró el JSON"); }
    $raw = file_get_contents($jsonPath);
    $data = json_decode($raw, true);
    $web = $data['web'];
    return [
        'client_id'     => (string)($web['client_id'] ?? ''),
        'redirect_uri'  => 'https://animamusica.ar/login.php',
        'auth_uri'      => (string)($web['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/v2/auth'),
    ];
}

function build_google_auth_url(array $cfg): string {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = [
        'client_id'     => $cfg['client_id'],
        'redirect_uri'  => $cfg['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
        'state'         => $state,
    ];
    return $cfg['auth_uri'] . '?' . http_build_query($params);
}

try {
    $cfg = load_google_oauth_config();
    $authUrl = build_google_auth_url($cfg);
} catch (Throwable $e) { exit("Error configurando OAuth"); }

$is_local = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || $_SERVER['HTTP_HOST'] === '127.0.0.1');
$login_link = $is_local ? 'login.php' : htmlspecialchars($authUrl);
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anima Música - Inicio</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

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

* { box-sizing: border-box; margin: 0; padding: 0; }

html { min-height: 100vh; }
body { 
    font-family: 'Inter', sans-serif; 
    background: 
        radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), 
        radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), 
        linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
    background-attachment: fixed;
    color: var(--text); 
    min-height: 100vh; 
    position: relative; 
    overflow-x: hidden; 
}

.particles { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; overflow: hidden; pointer-events: none;}
.particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
.particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
.particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
.particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
.particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
@keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }


.header { display: flex; justify-content: space-between; align-items: center; padding: 24px 48px; max-width: 1200px; margin: 0 auto; }
.logo-container { display: flex; align-items: center; gap: 12px; text-decoration: none; }
.logo-icon { width: 48px; height: 48px; border-radius: 14px; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; box-shadow: 0 0 25px var(--accent-glow); }
.logo-text { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #fff; }

.nav-links { display: flex; gap: 32px; align-items: center; }
.login-btn { background: var(--gradient-primary); color: white; border: none; padding: 12px 28px; border-radius: 12px; font-weight: 700; font-size: 15px; cursor: pointer; transition: 0.3s ease; text-decoration: none; box-shadow: 0 8px 24px var(--accent-glow); }
.login-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(139, 92, 246, 0.7); }

.main-content { max-width: 1200px; margin: 0 auto; padding: 40px 48px; display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
.hero-text { max-width: 540px; }
.hero-text h1 { font-size: 56px; font-weight: 800; line-height: 1.1; letter-spacing: -1px; margin-bottom: 24px; }
.highlight { position: relative; display: inline-block; color: #fff; }
.highlight::after { content: ''; position: absolute; bottom: 4px; left: 0; width: 100%; height: 8px; background: rgba(139, 92, 246, 0.5); z-index: -1; border-radius: 4px; }
.hero-text p { font-size: 18px; line-height: 1.6; color: var(--muted); margin-bottom: 36px; }

.primary-btn { background: var(--gradient-primary); color: white; padding: 18px 36px; border-radius: 14px; font-weight: 800; font-size: 16px; text-decoration: none; display: inline-flex; align-items: center; gap: 12px; box-shadow: 0 10px 30px var(--accent-glow); transition: 0.3s; }
.primary-btn:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(139, 92, 246, 0.7); }
.btn-icon { width: 20px; height: 20px; }

.features-card { background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--radius); padding: 40px; box-shadow: 0 30px 60px rgba(0,0,0,0.5); }
.feature-list { display: flex; flex-direction: column; gap: 28px; }
.feature-item { display: flex; align-items: flex-start; gap: 16px; }
.feature-icon { width: 48px; height: 48px; border-radius: 14px; background: rgba(139, 92, 246, 0.2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(139, 92, 246, 0.3); }
.feature-icon svg { width: 24px; height: 24px; color: var(--accent); }
.feature-content h3 { font-size: 18px; font-weight: 700; margin-bottom: 6px; color: #fff; }
.feature-content p { font-size: 14px; line-height: 1.5; color: var(--muted); margin: 0; }

.how-it-works { max-width: 1200px; margin: 80px auto 40px; padding: 0 48px; }
.section-title { font-size: 36px; font-weight: 800; text-align: center; margin-bottom: 60px; color: #fff; letter-spacing: -1px; }
.steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
.step { background: var(--glass-bg); backdrop-filter: blur(10px); border: 1px solid var(--glass-border); border-radius: 20px; padding: 32px; text-align: center; transition: 0.3s ease; }
.step:hover { transform: translateY(-8px); border-color: rgba(139, 92, 246, 0.5); box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4); }
.step-number { width: 56px; height: 56px; border-radius: 50%; background: var(--gradient-primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; margin: 0 auto 24px; box-shadow: 0 5px 20px var(--accent-glow); }
.step h3 { font-size: 20px; font-weight: 700; margin-bottom: 12px; color: #fff; }
.step p { font-size: 15px; line-height: 1.6; color: var(--muted); margin: 0; }


.profesores-section { max-width: 1200px; margin: 80px auto 80px; padding: 0 48px; }
.prof-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 32px; }
.prof-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 20px; padding: 30px; transition: 0.3s; }
.prof-card:hover { background: rgba(255, 255, 255, 0.08); transform: translateY(-5px); border-color: rgba(139, 92, 246, 0.6); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
.prof-header { display: flex; align-items: center; gap: 18px; margin-bottom: 20px; }

.prof-avatar { width: 64px; height: 64px; border-radius: 16px; object-fit: cover; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; color: #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.4); }
.prof-info h3 { font-size: 20px; font-weight: 800; margin-bottom: 4px; color: #fff; }
.prof-info p { color: var(--accent); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
.prof-tags { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.tag { background: rgba(139, 92, 246, 0.15); border: 1px solid rgba(139, 92, 246, 0.3); padding: 5px 10px; border-radius: 8px; font-size: 11px; font-weight: 700; color: #e2e8f0; }
.prof-desc { font-size: 14px; color: var(--muted); margin-bottom: 20px; font-style: italic; border-left: 2px solid var(--accent); padding-left: 12px; line-height: 1.5; }

.footer { text-align: center; padding: 40px 0; border-top: 1px solid var(--glass-border); margin: 0 48px; color: var(--muted); font-size: 14px; }

@media (max-width: 1024px) { .main-content { grid-template-columns: 1fr; gap: 48px; } .hero-text h1 { font-size: 48px; } .steps { grid-template-columns: 1fr; gap: 24px; } }
@media (max-width: 768px) { .header, .main-content, .how-it-works, .profesores-section { padding-left: 24px; padding-right: 24px; } .hero-text h1 { font-size: 38px; } .nav-links { display: none; } }
</style>
</head>

<body>
  <div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>

  <header class="header">
    <a href="index.php" class="logo-container">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 3V21M8 6V18M16 6V18M5 9V15M19 9V15" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="logo-text">Anima Música</div>
    </a>

    <nav class="nav-links">
      <a href="<?= $login_link ?>" class="login-btn">Iniciar Sesión</a>
    </nav>
  </header>

  <main class="main-content">
    <div class="hero-text">
      <h1>Gestioná tus clases de música <span class="highlight">sin complicaciones</span></h1>
      <p>Anima Música es la plataforma que te permite reservar turnos fijos y clases adicionales con tus profesores favoritos. Optimizá tu tiempo y dedicación a la música.</p>

      <div class="cta-buttons">
        <a href="<?= $login_link ?>" class="primary-btn">
          <svg class="btn-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M11 16L15 12M15 12L11 8M15 12H3M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Comenzar ahora
        </a>
      </div>
    </div>

    <div class="features-card">
      <div class="feature-list">
        <div class="feature-item">
            <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div class="feature-content"><h3>Reservas Confiables</h3><p>Asegurá tu turno fijo semanal y agendá clases extra fácilmente.</p></div>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 7V3M16 7V3M7 11H17M5 21H19C20.1046 21 21 20.1046 21 19V7C21 5.89543 20.1046 5 19 5H5C3.89543 5 3 5.89543 3 7V19C3 20.1046 3.89543 21 5 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div class="feature-content"><h3>Control Total</h3><p>Visualizá tu agenda mensual y gestioná tus horarios desde cualquier dispositivo.</p></div>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 15V17M6 21H18C19.1046 21 20 20.1046 20 19V13C20 11.8954 19.1046 11 18 11H6C4.89543 11 4 11.8954 4 13V19C4 20.1046 4.89543 21 6 21ZM16 11V7C16 4.79086 14.2091 3 12 3C9.79086 3 8 4.79086 8 7V11H16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div class="feature-content"><h3>Acceso Seguro</h3><p>Entrá con tu cuenta de Google sin necesidad de recordar nuevas contraseñas.</p></div>
        </div>
      </div>
    </div>
  </main>

  <section class="how-it-works">
    <h2 class="section-title">¿Cómo funciona?</h2>
    <div class="steps">
      <div class="step"><div class="step-number">1</div><h3>Registrate</h3><p>Accedé con tu cuenta de Google en segundos para activar tu perfil.</p></div>
      <div class="step"><div class="step-number">2</div><h3>Elegí tu profesor</h3><p>Explorá el plantel docente y encontrá el horario perfecto para vos.</p></div>
      <div class="step"><div class="step-number">3</div><h3>Gestioná</h3><p>Mantené el control total de tus reservas y tu avance musical.</p></div>
    </div>
  </section>

  <?php if (!empty($profesores_destacados)): ?>
  <section class="profesores-section">
    <h2 class="section-title">Plantel Destacado</h2>
    <div class="prof-grid">
      <?php foreach ($profesores_destacados as $prof): 
          $nombre = htmlspecialchars($prof['nombre']);
          $inicial = strtoupper(substr($nombre, 0, 1));
          $instrumentos = array_map('trim', explode(',', (string)($prof['especialidad'] ?? '')));
          $foto = $prof['avatar'] ?? null;
          $descripcion = !empty($prof['descripcion']) ? htmlspecialchars($prof['descripcion']) : 'Dedicado a guiar a los alumnos en su desarrollo técnico y artístico con un enfoque profesional.';
      ?>
      <div class="prof-card">
        <div class="prof-header">
          <?php if (!empty($foto)): ?>
              <img src="<?= htmlspecialchars($foto) ?>" alt="<?= $nombre ?>" class="prof-avatar" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
              <div class="prof-avatar" style="display:none;"><?= $inicial ?></div>
          <?php else: ?>
              <div class="prof-avatar"><?= $inicial ?></div>
          <?php endif; ?>
          
          <div class="prof-info">
            <h3><?= $nombre ?></h3>
            <p>Profesor</p>
          </div>
        </div>
        
        <div class="prof-tags">
          <?php foreach($instrumentos as $inst): if(!empty($inst)): ?>
              <span class="tag"><?= htmlspecialchars($inst) ?></span>
          <?php endif; endforeach; ?>
        </div>
        <p class="prof-desc">"<?= $descripcion ?>"</p>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <footer class="footer">
    <p>© <?= date('Y') ?> Anima Música. Todos los derechos reservados.</p>
    <p style="margin-top: 10px; font-size: 13px;">Acceso exclusivo mediante <a href="<?= $login_link ?>" style="color: var(--accent); text-decoration: none; font-weight: 600;">Google Auth</a></p>
  </footer>
</body>
</html>