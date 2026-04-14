<?php
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }


$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0 || (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] !== true)) { 
    header('Location: login.php'); 
    exit; 
}

if (isset($_SESSION['user_rol'])) {
    $_SESSION['user_rol'] = strtolower(trim(str_replace(["\r", "\n"], '', (string)$_SESSION['user_rol'])));
}

require_once 'config/database.php';
$db = Database::getInstance();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// FECHAS

$today = new DateTime('today');

$mesURL = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)$today->format('m');
$anioURL = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)$today->format('Y');

$currentView = new DateTime("$anioURL-$mesURL-01");

$mesesEspañol = [
    "January"=>"Enero","February"=>"Febrero","March"=>"Marzo","April"=>"Abril",
    "May"=>"Mayo","June"=>"Junio","July"=>"Julio","August"=>"Agosto",
    "September"=>"Septiembre","October"=>"Octubre","November"=>"Noviembre","December"=>"Diciembre"
];

$mesNombre = $mesesEspañol[$currentView->format('F')];
$anioActual = $currentView->format('Y');
$diasEnMes = (int)$currentView->format('t');
$hoy = (int)$today->format('d');

$prevMonth = (clone $currentView)->modify('-1 month');
$nextMonth = (clone $currentView)->modify('+1 month');

$monthEnd = (clone $currentView)->modify('last day of this month')->setTime(23,59,59);


// INSTRUMENTOS
$todosLosInstrumentos = [];
$resI = $db->fetchAll("SELECT DISTINCT nombre FROM instrumentos ORDER BY nombre ASC");
foreach($resI as $inst) { 
    $todosLosInstrumentos[] = $inst['nombre']; 
}

// HORARIOS
$horarios = $db->fetchAll("
  SELECT h.id, h.dia_semana, TIME_FORMAT(h.hora,'%H:%i') AS hora, h.sala, h.capacidad,
         p.id AS prof_id, p.nombre AS profesor, h.instrumento,
         h.tipo_turno, h.fecha_especifica, h.modalidad
  FROM horarios h
  LEFT JOIN profesores p ON p.id = h.profesor_id
  WHERE h.activo=1
  ORDER BY p.nombre, h.hora
");

$horariosView = [];
$disponibilidadPorDia = [];

foreach ($horarios as $hrow) {

    $prof_id = (int)$hrow['prof_id'];
    if ($prof_id <= 0) continue;

    $tipo = strtolower(trim((string)$hrow['tipo_turno'] ?? 'fijo'));
    $cap = (int)$hrow['capacidad'];
    $hid = (int)$hrow['id'];
    $instrumento = strtolower(trim((string)$hrow['instrumento']));
    $instrumento = iconv('UTF-8', 'ASCII//TRANSLIT', $instrumento);

    $fechas_a_procesar = [];


    // UNIFICACIÓN: TURNOS FIJOS Y EXTRAS

    $f = $hrow['fecha_especifica'];
    $diaStr = strtolower(trim((string)$hrow['dia_semana']));

    if ($f >= $today->format('Y-m-d') && $f <= $monthEnd->format('Y-m-d')) {

        $res_cupo = $db->fetchAll("
            SELECT 
                (SELECT COUNT(*) FROM suscripciones WHERE horario_id = ? AND activo = 1)
                +
                (SELECT COUNT(*) FROM reservas WHERE horario_id = ? AND fecha = ? AND estado IN ('pendiente','confirmada'))
            AS total
        ", [$hid, $hid, $f]);

        $ocupados = (int)$res_cupo[0]['total'];

        if ($ocupados < $cap) {
            $fechas_a_procesar[] = ['f' => $f, 'dn' => $diaStr];
        }
    }


    foreach ($fechas_a_procesar as $data) {

        $f = $data['f'];
        $dn = trim($data['dn']);

        if (!isset($disponibilidadPorDia[$f])) {
            $disponibilidadPorDia[$f] = [];
        }

        $disponibilidadPorDia[$f][] = $instrumento;
        $disponibilidadPorDia[$f][] = "tipo_" . $tipo;

        $disponibilidadPorDia[$f] = array_values(array_unique($disponibilidadPorDia[$f]));

        if (!isset($horariosView[$f])) $horariosView[$f] = [];
        if (!isset($horariosView[$f][$instrumento])) $horariosView[$f][$instrumento] = [];

        if (!isset($horariosView[$f][$instrumento][$prof_id])) {
            $horariosView[$f][$instrumento][$prof_id] = [
                'nombre' => (string)$hrow['profesor'],
                'horarios' => []
            ];
        }

        $horariosView[$f][$instrumento][$prof_id]['horarios'][] = [
            'id'   => $hid,
            'hora' => substr((string)$hrow['hora'], 0, 5),
            'tipo' => $tipo,
            'sala' => (string)$hrow['sala'],
            'modalidad' => (string)($hrow['modalidad'] ?? 'Presencial')
        ];
    }
}

// CONFIGURACIÓN DE LÍMITE SEMANAL
$resConfig = $db->query("SELECT valor FROM configuraciones WHERE clave = 'max_reservas_semana' LIMIT 1");
$max_semanal = 2; // Valor por defecto


if (is_array($resConfig) && count($resConfig) > 0) {
    $fila = $resConfig[0];
    $max_semanal = (int)($fila['valor'] ?? 2);
}
?>