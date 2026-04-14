<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$current_page = basename($_SERVER['PHP_SELF']);
$rol_actual = $_SESSION['user_rol'] ?? 'alumno'; 
$user_id_sesion = $_SESSION['user_id'] ?? 0;
$check_rol = trim(str_replace(["\r", "\n"], '', $rol_actual));

$cant_notif = 0;

if (!isset($conn) && class_exists('Database')) {
    $conn = Database::getInstance()->getConnection();
}

if ($user_id_sesion > 0 && isset($conn)) {
    $sql_n = "SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ? AND leido = 0";
    $stmt_n = $conn->prepare($sql_n);
    $stmt_n->bind_param("i", $user_id_sesion);
    $stmt_n->execute();
    $res_n = $stmt_n->get_result();
    if ($res_n) {
        $cant_notif = $res_n->fetch_assoc()['total'];
    }
}
?>

<style>
    .nav-notif {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px !important;
        margin-right: 5px;
    }

    .nav-notif i {
        font-size: 1.1rem;
        color: rgba(255, 255, 255, 0.7);
        transition: 0.3s;
    }

    .nav-notif:hover i {
        color: #a78bfa;
    }

    .notif-badge {
        position: absolute;
        top: 4px; 
        right: 4px;
        background: #ef4444;
        color: white;
        font-size: 0.6rem; 
        font-weight: 800;
        min-width: 12px; 
        height: 12px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #0b1220;
        box-shadow: 0 0 8px rgba(239, 68, 68, 0.8); 
        padding: 2px;
        line-height: 1;
    }

    @keyframes pulse-red {
        0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
        70% { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(239, 68, 68, 0); }
        100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }

    .notif-badge {
        animation: pulse-red 2s infinite;
    }

    .main-navbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.8rem 2rem;
        background: rgba(11, 18, 32, 0.8);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        font-family: 'Inter', sans-serif;
    }

    .nav-brand {
        font-size: 1.25rem;
        font-weight: 900;
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 2px;
        text-shadow: 0 0 15px rgba(139, 92, 246, 0.4);
    }

    .nav-menu {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .menu-toggle {
        display: none; 
        flex-direction: column;
        gap: 5px;
        background: none;
        border: none;
        cursor: pointer;
    }

    .menu-toggle span {
        width: 25px; height: 3px;
        background: #fff; border-radius: 2px;
    }

    .nav-link {
        color: rgba(255, 255, 255, 0.6);
        text-decoration: none;
        padding: 10px 16px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 700;
        transition: 0.3s;
    }

    .nav-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.05);
    }

    .nav-link.active {
        color: #fff;
        background: rgba(139, 92, 246, 0.2);
        box-shadow: 0 0 15px rgba(139, 92, 246, 0.1);
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .nav-link-highlight { 
        color: #fff !important; 
    }

    @media (max-width: 1024px) {
        .menu-toggle { display: flex; }
        .nav-menu {
            display: none;
            position: absolute;
            top: 100%; left: 0; width: 100%;
            flex-direction: column;
            background: rgba(11, 18, 32, 0.95);
            padding: 20px 0;
            backdrop-filter: blur(20px);
        }
        .nav-menu.active { display: flex; }
        .nav-link { width: 85%; text-align: center; margin: 5px 0; }
    }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<nav class="main-navbar">
    <div class="nav-brand">Anima Music</div>

    <button class="menu-toggle" onclick="toggleMenu()">
        <span></span><span></span><span></span>
    </button>

    <div class="nav-menu" id="navMenu">
        <a href="dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">Inicio</a>
        <a href="calendario.php" class="nav-link <?= ($current_page == 'calendario.php') ? 'active' : '' ?>">Calendario</a>
        <a href="mis-reservas.php" class="nav-link <?= ($current_page == 'mis-reservas.php') ? 'active' : '' ?>">Reservas</a>
        <a href="profesores.php" class="nav-link <?= ($current_page == 'profesores.php') ? 'active' : '' ?>">Profesores</a>

        <?php if ($check_rol === 'profesor' || $check_rol === 'admin-profesor'): ?>
            <a href="alumnos.php" class="nav-link <?= ($current_page == 'alumnos.php') ? 'active' : '' ?>">Alumnos</a>
        <?php endif; ?>

        <a href="notificaciones.php" class="nav-link nav-notif <?= ($current_page == 'notificaciones.php') ? 'active' : '' ?>" title="Notificaciones">
    <i class="fas fa-bell"></i>
    <?php if($cant_notif > 0): ?>
        <span class="notif-badge">
            <?= $cant_notif > 9 ? '' : $cant_notif ?> 
            </span>
    <?php endif; ?>
</a>
        
        <?php if ($check_rol === 'admin' || $check_rol === 'admin-profesor'): ?>
            <a href="admin/" class="nav-link nav-link-highlight <?= (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'active' : '' ?>" 
               style="background: rgba(167, 139, 250, 0.2); border: 1.5px solid #a78bfa;">
                Administración
            </a>
        <?php endif; ?>

        <a href="perfil.php" class="nav-link <?= ($current_page == 'perfil.php') ? 'active' : '' ?>">Perfil</a>
        
        <a href="logout.php" class="nav-link" style="color: #f87171; margin-left: 10px;">Salir</a>
    </div>
</nav>

<script>
    function toggleMenu() {
        const menu = document.getElementById('navMenu');
        menu.classList.toggle('active');
    }

    window.onclick = function(event) {
        const menu = document.getElementById('navMenu');
        const toggle = document.querySelector('.menu-toggle');
        if (menu && !menu.contains(event.target) && toggle && !toggle.contains(event.target)) {
            menu.classList.remove('active');
        }
    }
</script>