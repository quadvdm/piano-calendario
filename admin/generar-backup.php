<?php
// admin/generar-backup.php 
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. SEGURIDAD
$es_cron = (isset($_GET['token']) && $_GET['token'] === 'anima_seguro_2026') || php_sapi_name() === 'cli';
if (!$es_cron) {
    require_once __DIR__ . '/auth.php';
    require_admin();
}

require_once dirname(__DIR__) . '/config/database.php';
$db = Database::getInstance();
$conn = $db->getConnection();

$email_destino = "tu_correo@gmail.com"; 

$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) { mkdir($backup_dir, 0777, true); }

$fecha_hoy = date('Y-m-d');
$archivo_nombre = "emergencia_{$fecha_hoy}.html";
$ruta_archivo = $backup_dir . '/' . $archivo_nombre;

// 2. AUTOLIMPIEZA (Mantiene los últimos 5)
$archivos = glob($backup_dir . "/emergencia_*.html");
if (count($archivos) >= 5) {
    array_multisort(array_map('filemtime', $archivos), SORT_ASC, $archivos);
    while (count($archivos) >= 5) {
        $archivo_viejo = array_shift($archivos);
        unlink($archivo_viejo);
    }
}

// 3. ARMADO DEL HTML
$html = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>";
$html .= "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
$html .= "<title>Backup Ánima Completo</title>";
$html .= "<style>
            body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; color: #333; background: #f4f7f6; }
            .card { background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 1200px; margin: auto; }
            h1 { color: #8b5cf6; border-bottom: 2px solid #8b5cf6; padding-bottom: 10px; margin-top: 0; }
            h2 { color: #4b5563; margin-top: 40px; padding: 10px; border-left: 5px solid #8b5cf6; background: #f9fafb; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 13px; }
            th, td { border: 1px solid #eee; padding: 12px; text-align: left; }
            th { background-color: #f8f9fa; color: #374151; text-transform: uppercase; font-size: 10px; }
            tr:nth-child(even) { background-color: #fafafa; }
            .badge { background: #ede9fe; color: #7c3aed; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
            .badge-free { background: #dcfce7; color: #15803d; }
            .tel-link { color: #2563eb; text-decoration: none; font-weight: bold; }
            .mod-info { font-size: 11px; color: #6b7280; font-weight: 600; }
          </style>";
$html .= "</head><body><div class='card'>";
$html .= "<h1>Reporte de Emergencia - Ánima Música</h1>";
$html .= "<p><strong>Generado el:</strong> " . date('d/m/Y H:i:s') . "</p>";

// 4. TABLA 1: RESERVAS CONFIRMADAS (60 DÍAS)
$html .= "<h2>1. Clases Agendadas (Próximos 60 días)</h2>";
$sql_res = "
    SELECT r.fecha, h.hora, h.instrumento, h.modalidad, h.tipo_turno, h.duracion_minutos,
           u.nombre as alu_n, u.apellido as alu_a, u.telefono as alu_tel, p.nombre as prof_n
    FROM reservas r
    JOIN horarios h ON r.horario_id = h.id
    JOIN usuarios u ON r.usuario_id = u.id
    LEFT JOIN profesores p ON h.profesor_id = p.id
    WHERE r.estado IN ('confirmada', 'pendiente') 
      AND r.fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 MONTH)
    ORDER BY r.fecha ASC, h.hora ASC
";
$res = $conn->query($sql_res);
if ($res && $res->num_rows > 0) {
    $html .= "<table><thead><tr><th>Fecha / Hora</th><th>Duración</th><th>Instrumento / Tipo</th><th>Alumno / Contacto</th><th>Profesor</th><th>Modalidad</th></tr></thead><tbody>";
    while($r = $res->fetch_assoc()) {
        $html .= "<tr>
            <td><strong>".date('d/m', strtotime($r['fecha']))."</strong><br>".substr($r['hora'], 0, 5)." hs</td>
            <td>{$r['duracion_minutos']} min</td>
            <td>{$r['instrumento']} <br><span class='badge'>{$r['tipo_turno']}</span></td>
            <td>".htmlspecialchars($r['alu_a']." ".$r['alu_n'])."<br><a href='tel:{$r['alu_tel']}' class='tel-link'>{$r['alu_tel']}</a></td>
            <td>".htmlspecialchars((string)$r['prof_n'])."</td>
            <td class='mod-info'>{$r['modalidad']}</td>
        </tr>";
    }
    $html .= "</tbody></table>";
} else { $html .= "<p>No hay reservas.</p>"; }

// 5. TABLA 2: HORARIOS LIBRES
$html .= "<h2>2. Horarios Libres / Disponibles</h2>";
$sql_libres = "
    SELECT h.*, p.nombre as prof_n 
    FROM horarios h
    LEFT JOIN profesores p ON h.profesor_id = p.id
    WHERE h.activo = 1 
      AND h.reservas_actuales < h.capacidad
    ORDER BY h.dia_semana, h.hora
";
$res_libres = $conn->query($sql_libres);
if ($res_libres && $res_libres->num_rows > 0) {
    $html .= "<table><thead><tr><th>Día / Fecha</th><th>Hora / Duración</th><th>Instrumento / Tipo</th><th>Profesor</th><th>Modalidad</th><th>Cupos</th></tr></thead><tbody>";
    while($l = $res_libres->fetch_assoc()) {
        $fecha_info = $l['fecha_especifica'] ? date('d/m', strtotime($l['fecha_especifica'])) : $l['dia_semana'];
        $cupos_disp = $l['capacidad'] - $l['reservas_actuales'];
        $html .= "<tr>
            <td><strong>{$fecha_info}</strong></td>
            <td>".substr($l['hora'], 0, 5)." hs<br><span style='color:#6b7280;'>{$l['duracion_minutos']} min</span></td>
            <td>{$l['instrumento']} <br><span class='badge badge-free'>{$l['tipo_turno']}</span></td>
            <td>".htmlspecialchars((string)$l['prof_n'])."</td>
            <td class='mod-info'>{$l['modalidad']}</td>
            <td><strong style='color:#16a34a;'>{$cupos_disp} libres</strong></td>
        </tr>";
    }
    $html .= "</tbody></table>";
} else { $html .= "<p>No hay horarios libres.</p>"; }

$html .= "</div></body></html>";

// 6. GUARDAR Y EMAIL
file_put_contents($ruta_archivo, $html);
@mail($email_destino, "🚨 Backup Ánima Completo - " . date('d/m/Y'), $html, "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: Sistema Anima <noreply@tusitio.com>\r\n");

// 7. RESULTADO
if (!$es_cron) {
    echo "<div style='font-family: Arial; padding: 40px; text-align: center;'>";
    echo "<h2 style='color: #8b5cf6;'>¡Backup Generado Exitosamente!</h2>";
    echo "<p>El reporte incluye duración y modalidad de cada turno.</p>";
    echo "<a href='backups/{$archivo_nombre}' target='_blank' style='display:inline-block; padding: 12px 25px; background: #8b5cf6; color: #fff; text-decoration: none; border-radius: 10px; font-weight: bold;'>Ver Reporte Completo</a>";
    echo "</div>";
}
?>