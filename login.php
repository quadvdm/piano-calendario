<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generamos un Token CSRF interno para asegurar el POST de la ventanita
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));

/**
 * Lee el JSON de credenciales
 */
function load_google_oauth_config(): array {
    $jsonPath = __DIR__ . '/client_secret_35991205187-oasjfgotcqsavn222k7frmlvhidt6f2m.apps.googleusercontent.com.json';

    if (!file_exists($jsonPath)) { throw new RuntimeException("No se encontró el JSON de credenciales."); }
    $raw = file_get_contents($jsonPath);
    if ($raw === false) { throw new RuntimeException("No se pudo leer el JSON."); }
    
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['web'])) { throw new RuntimeException("JSON inválido."); }
    $web = $data['web'];

    return [
        'client_id'     => (string)($web['client_id'] ?? ''),
        'client_secret' => (string)($web['client_secret'] ?? ''),
        'token_uri'     => (string)($web['token_uri'] ?? 'https://oauth2.googleapis.com/token')
    ];
}

function http_post_form(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err    = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) { throw new RuntimeException("cURL error: {$err}"); }
    $json = json_decode((string)$resp, true);
    return [$code, $json ?? []];
}

function http_get_json(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) { throw new RuntimeException("cURL error."); }
    return [$code, json_decode((string)$resp, true) ?? []];
}

function upsert_usuario($db, array $u): int {
    $google_id = $u['id'] ?? null;
    $email     = $u['email'] ?? null;
    $nombre    = $u['name'] ?? 'Usuario';

    if (!$email) { throw new RuntimeException("Google no devolvió email."); }

    $r = $db->fetchAll("SELECT id FROM usuarios WHERE email=? LIMIT 1", [$email]);
    if (!empty($r)) {
        $id = (int)$r[0]['id'];
        $db->query("UPDATE usuarios SET google_id=?, nombre=?, ultimo_login=NOW(), activo=1 WHERE id=?", [$google_id, $nombre, $id]);
        return $id;
    }

    $db->query("INSERT INTO usuarios (google_id,email,nombre,rol,fecha_registro,ultimo_login,activo) VALUES (?,?,?,?,NOW(),NOW(),1)", [$google_id, $email, $nombre, 'alumno']);
    $r2 = $db->fetchAll("SELECT id FROM usuarios WHERE email=? LIMIT 1", [$email]);
    return (int)$r2[0]['id'];
}

if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance();

try {
    $cfg = load_google_oauth_config();
    if (empty($cfg['client_id'])) { throw new RuntimeException("Falta client_id en el JSON."); }

    // NUEVO SISTEMA: RECIBIR EL CÓDIGO DESDE LA VENTANA POPUP
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
        
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new RuntimeException("Error de seguridad interno. Por favor recarga la página.");
        }

        // Exigencia estricta de Google para ventanas Popup: el redirect_uri debe ser 'postmessage'
        [$tokenHttp, $token] = http_post_form($cfg['token_uri'], [
            'code'          => $_POST['code'],
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => 'postmessage', 
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($token['access_token'])) {
            throw new RuntimeException("Error obteniendo el token de Google.");
        }

        [$uiHttp, $user] = http_get_json('https://www.googleapis.com/oauth2/v2/userinfo', [
            'Authorization: Bearer ' . $token['access_token'],
        ]);

        if (empty($user['email'])) { throw new RuntimeException("No se pudo obtener el email."); }

        $user_id = upsert_usuario($db, $user);

        $_SESSION['logged_in']   = true;
        $_SESSION['user_id']     = $user_id;
        $_SESSION['google_id']   = $user['id'] ?? null;
        $_SESSION['user_email']  = $user['email'];
        $_SESSION['user_nombre'] = $user['name'] ?? '';
        $_SESSION['user_avatar'] = $user['picture'] ?? '';

        session_write_close();
        header('Location: dashboard.php');
        exit;
    }

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingresar · Anima Música</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  
  <style>
    /* Mismo CSS Luminoso */
    :root {
      --bg-color: #131527; --bg-darker: #0d101b;
      --glass-bg: rgba(255, 255, 255, 0.05); --glass-border: rgba(255, 255, 255, 0.12); 
      --text: #f8fafc; --muted: #cbd5e1; --accent: #8b5cf6; --accent-glow: rgba(139, 92, 246, 0.5);
      --gradient-primary: linear-gradient(135deg, #8b5cf6, #6d28d9); --radius: 24px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: 'Inter', sans-serif;
      background: radial-gradient(circle at 15% 20%, rgba(139, 92, 246, 0.25) 0%, transparent 45%), radial-gradient(circle at 85% 70%, rgba(109, 40, 217, 0.25) 0%, transparent 45%), linear-gradient(180deg, var(--bg-color), var(--bg-darker)); 
      background-attachment: fixed; color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; overflow: hidden;
    }
    .particles { position: absolute; width: 100%; height: 100%; top: 0; left: 0; z-index: -1; pointer-events: none; }
    .particle { position: absolute; background: rgba(255, 255, 255, 0.1); border-radius: 50%; box-shadow: 0 0 12px rgba(139, 92, 246, 0.4); }
    .particle:nth-child(1) { width: 5px; height: 5px; top: 20%; left: 10%; animation: float 15s infinite linear; }
    .particle:nth-child(2) { width: 7px; height: 7px; top: 60%; left: 85%; animation: float 18s infinite linear reverse; }
    .particle:nth-child(3) { width: 4px; height: 4px; top: 80%; left: 15%; animation: float 12s infinite linear; }
    .particle:nth-child(4) { width: 6px; height: 6px; top: 30%; left: 90%; animation: float 20s infinite linear reverse; }
    @keyframes float { 0%, 100% { transform: translateY(0) translateX(0); } 50% { transform: translateY(-20px) translateX(20px); } }

    .card { width: min(520px, 100%); border: 1px solid var(--glass-border); border-radius: var(--radius); background: var(--glass-bg); backdrop-filter: blur(20px); box-shadow: 0 30px 60px rgba(0,0,0,0.5), 0 0 40px rgba(139, 92, 246, 0.1); padding: 40px; position: relative; z-index: 10; }
    .top { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 20px; }
    .brand { display: flex; flex-direction: column; gap: 4px; }
    .brand h1 { margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .brand p { margin: 0; color: var(--accent); font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
    .chip { padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(139, 92, 246, 0.3); background: rgba(139, 92, 246, 0.15); color: #e2e8f0; font-weight: 700; font-size: 11px; }
    .main h2 { margin: 0 0 10px; font-size: 32px; font-weight: 800; letter-spacing: -1px; }
    .main p { margin: 0 0 24px; color: var(--muted); line-height: 1.6; font-size: 15px; }

    /* Cambié de <a> a <button> por semántica, ya que ahora ejecuta Javascript */
    .btn { display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; padding: 16px; border-radius: 14px; border: none; background: var(--gradient-primary); color: #fff; text-decoration: none; font-weight: 800; font-size: 15px; box-shadow: 0 10px 30px var(--accent-glow); transition: 0.3s ease; cursor: pointer; font-family: inherit;}
    .btn:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(139, 92, 246, 0.7); }
    
    .gIcon { width: 24px; height: 24px; border-radius: 50%; background: #fff; display: inline-flex; align-items: center; justify-content: center; }
    .gIcon svg { width: 14px; height: 14px; }
    .note { margin-top: 16px; font-size: 13px; color: rgba(255,255,255,.50); line-height: 1.5; text-align: center; }
    .error { margin-top: 14px; padding: 14px; border-radius: 12px; border: 1px solid rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.1); color: #fca5a5; font-size: 13px; line-height: 1.5; text-align: center; }
    .links { margin-top: 25px; display: flex; justify-content: center; border-top: 1px solid var(--glass-border); padding-top: 25px; }
    .link { color: var(--muted); text-decoration: none; font-size: 14px; font-weight: 600; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
    .link:hover { color: #fff; }
    .link svg { width: 16px; height: 16px; }
  </style>
</head>
<body>
  <div class="particles"><div class="particle"></div><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>

  <div class="card">
    <div class="top">
      <div class="brand">
        <h1>Anima Música</h1>
        <p>Portal de Alumnos</p>
      </div>
      <div class="chip">Auth Seguro</div>
    </div>

    <div class="main">
      <h2>Ingresar</h2>
      <p>Usá tu cuenta de Google para acceder al dashboard. No guardamos tu contraseña.</p>

      <?php $is_local = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || $_SERVER['HTTP_HOST'] === '127.0.0.1'); ?>
      <?php if ($is_local): ?>
          <a class="btn" href="login_mock.php" style="background: linear-gradient(135deg, #22c55e, #16a34a); box-shadow: 0 10px 30px rgba(34, 197, 94, 0.4); margin-bottom:20px;">
              <span>⚙️ Acceso Local (Test)</span>
          </a>
      <?php endif; ?>

      <button class="btn" onclick="abrirPopupGoogle(event)">
        <span class="gIcon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none"><path d="M21.35 11.1H12v3.8h5.35c-.6 2-2.4 3.4-5.35 3.4a6.3 6.3 0 0 1 0-12.6c1.7 0 3 .7 4 1.6l2.7-2.7C17.05 1.9 14.75 1 12 1 5.9 1 1 5.9 1 12s4.9 11 11 11c6.35 0 10.6-4.45 10.6-10.7 0-.75-.08-1.3-.25-1.9Z" fill="#111827"/></svg>
        </span>
        Continuar con Google
      </button>
      
      <div class="note">Se abrirá una pequeña ventana segura de Google.</div>

      <?php if (isset($errorMsg)): ?>
        <div class="error"><?= htmlspecialchars($errorMsg) ?></div>
      <?php endif; ?>

      <div class="links">
        <a class="link" href="index.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
          </svg>
          Volver al inicio
        </a>
      </div>
    </div>
  </div>

  <script>
    let googleClient;
    window.onload = function () {
      googleClient = google.accounts.oauth2.initCodeClient({
        client_id: '<?= htmlspecialchars($cfg['client_id'] ?? '') ?>',
        scope: 'email profile',
        ux_mode: 'popup',
        callback: (response) => {
          if (response.code) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'login.php';
            
            const codeInput = document.createElement('input');
            codeInput.type = 'hidden';
            codeInput.name = 'code';
            codeInput.value = response.code;

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= $_SESSION['csrf_token'] ?>';
            
            form.appendChild(codeInput);
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            
            form.submit();
          }
        },
      });
    };

    function abrirPopupGoogle(e) {
      e.preventDefault();
      if(googleClient) {
          googleClient.requestCode();
      } else {
          alert("Cargando servicios de Google, intenta en un segundo...");
      }
    }
  </script>
</body>
</html>