<?php
// admin/reservas-editar.php — EDITAR RESERVA (Admin) con Reciclaje de Suscripciones
declare(strict_types=1);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$conn = $db->getConnection();

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$error = '';
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: reservas.php?err=' . urlencode('ID inválido'));
    exit;
}

// 1. CARGAR DATOS DE LA RESERVA ACTUAL
$query = "SELECT r.*, u.id AS alumno_id, u.nombre AS alu_nom, u.apellido AS alu_ape, 
                 h.id AS hid, h.dia_semana, h.hora, h.duracion_minutos, h.instrumento, h.tipo_turno, h.fecha_especifica, 
                 h.profesor_id, p.nombre AS profesor_nombre
          FROM reservas r
          JOIN usuarios u ON u.id = r.usuario_id
          JOIN horarios h ON h.id = r.horario_id
          LEFT JOIN profesores p ON p.id = h.profesor_id
          WHERE r.id = ? LIMIT 1";
$st = $conn->prepare($query);
$st->bind_param('i', $id);
$st->execute();
$reserva = $st->get_result()->fetch_assoc();

if (!$reserva) {
    header('Location: reservas.php?err=' . urlencode('Reserva no encontrada'));
    exit;
}

// 2. OBTENER USUARIOS ACTIVOS EXCLUYENDO AL PROFESOR DEL HORARIO
$profesor_id_excluir = (int)$reserva['profesor_id'];
$resUsuarios = $conn->query("SELECT id, nombre, apellido, rol FROM usuarios WHERE activo=1 AND id != $profesor_id_excluir ORDER BY apellido ASC");
$usuarios_lista = $resUsuarios->fetch_all(MYSQLI_ASSOC);

// 3. PROCESAR GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevo_usuario_id = (int)($_POST['usuario_id'] ?? 0);
    $nuevo_estado = (string)($_POST['estado'] ?? 'confirmada');
    $observaciones = trim((string)($_POST['descripcion'] ?? '')); 
    
    $antiguo_usuario_id = (int)$reserva['alumno_id'];
    $horario_id = (int)$reserva['hid'];
    $fecha_clase = $reserva['fecha'];
    $hoy = date('Y-m-d');

    if ($nuevo_usuario_id <= 0) {
        $error = 'Debes seleccionar un usuario válido.';
    } else {
        $conn->begin_transaction();
        try {
            // --- VALIDACIÓN DE SOLAPAMIENTO ---
            $hora_inicio = $reserva['hora'];
            $duracion = (int)$reserva['duracion_minutos'];
            $hora_fin = date('H:i:s', strtotime($hora_inicio . " + $duracion minutes"));

            $sql_solape = "SELECT r.id FROM reservas r
                           JOIN horarios h ON r.horario_id = h.id
                           WHERE r.usuario_id = ? AND r.id != ? AND r.fecha = ?
                             AND r.estado IN ('confirmada', 'pendiente')
                             AND ((h.hora < ? AND ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) > ?) OR
                                 (? < ADDTIME(h.hora, SEC_TO_TIME(h.duracion_minutos * 60)) AND ? >= h.hora))";

            $st_sol = $conn->prepare($sql_solape);
            $st_sol->bind_param('iisssss', $nuevo_usuario_id, $id, $fecha_clase, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin);
            $st_sol->execute();
            if ($st_sol->get_result()->num_rows > 0) {
                throw new Exception("El usuario seleccionado ya tiene otra clase programada en este horario.");
            }

            // --- GESTIÓN DE SUSCRIPCIONES (Lógica de Reciclaje) ---
            $nueva_suscripcion_id = $reserva['suscripcion_id'];

            if ($reserva['tipo_turno'] === 'fijo' && $nuevo_usuario_id !== $antiguo_usuario_id) {
                
                // 1. DESACTIVAR SUSCRIPCIÓN DEL ALUMNO ANTERIOR
                $stOld = $conn->prepare("UPDATE suscripciones SET activo = 0, fecha_fin = ? WHERE usuario_id = ? AND horario_id = ? AND activo = 1");
                $stOld->bind_param('sii', $hoy, $antiguo_usuario_id, $horario_id);
                $stOld->execute();
                
                // RESTAR contador al usuario anterior (solo si realmente se desactivó una suscripción)
                if ($stOld->affected_rows > 0) {
                    $conn->query("UPDATE usuarios SET tiene_suscripcion = GREATEST(0, tiene_suscripcion - 1) WHERE id = $antiguo_usuario_id");
                }

                // 2. RECICLAR O CREAR PARA EL NUEVO ALUMNO
                $stRec = $conn->prepare("SELECT id, activo FROM suscripciones WHERE usuario_id = ? AND horario_id = ? LIMIT 1");
                $stRec->bind_param('ii', $nuevo_usuario_id, $horario_id);
                $stRec->execute();
                $resRec = $stRec->get_result()->fetch_assoc();

                if ($resRec) {
                    // REUTILIZAR: Reactivamos la existente
                    $nueva_suscripcion_id = (int)$resRec['id'];
                    $conn->query("UPDATE suscripciones SET activo = 1, fecha_inicio = '$fecha_clase', fecha_fin = NULL WHERE id = $nueva_suscripcion_id");
                } else {
                    // CREAR: Nueva entrada
                    $stInsSus = $conn->prepare("INSERT INTO suscripciones (usuario_id, horario_id, fecha_inicio, activo) VALUES (?, ?, ?, 1)");
                    $stInsSus->bind_param('iis', $nuevo_usuario_id, $horario_id, $fecha_clase);
                    $stInsSus->execute();
                    $nueva_suscripcion_id = (int)$conn->insert_id;
                }
                
                // SUMAR contador al nuevo alumno
                $conn->query("UPDATE usuarios SET tiene_suscripcion = tiene_suscripcion + 1 WHERE id = $nuevo_usuario_id");
            }

            // --- ACTUALIZAR ESTADÍSTICAS ---
            if ($nuevo_usuario_id !== $antiguo_usuario_id) {
                $conn->query("UPDATE usuarios SET total_reservas = GREATEST(0, total_reservas - 1) WHERE id = $antiguo_usuario_id");

                $sqlNew = "UPDATE usuarios SET 
                            total_reservas = total_reservas + 1,
                            primera_reserva = IFNULL(primera_reserva, ?),
                            ultima_reserva = GREATEST(IFNULL(ultima_reserva, ?), ?)
                            WHERE id = ?";
                $stNewUser = $conn->prepare($sqlNew);
                $stNewUser->bind_param('sssi', $fecha_clase, $fecha_clase, $fecha_clase, $nuevo_usuario_id);
                $stNewUser->execute();
            }

            // 4. ACTUALIZAR EL REGISTRO DE LA RESERVA
            $sqlUpd = "UPDATE reservas SET usuario_id = ?, estado = ?, observaciones = ?, suscripcion_id = ? WHERE id = ?";
            $stUpd = $conn->prepare($sqlUpd);
            $stUpd->bind_param('issii', $nuevo_usuario_id, $nuevo_estado, $observaciones, $nueva_suscripcion_id, $id);
            $stUpd->execute();

            // --- NUEVO: NOTIFICACIONES DE EDICIÓN ---
            $id_sesion = (int)($_SESSION['user_id'] ?? 0);
            $fecha_f = date('d/m', strtotime($fecha_clase));
            $hora_f = substr($hora_inicio, 0, 5);
            $estado_txt = ($nuevo_estado === 'pendiente') ? ' (Pendiente a confirmar)' : '';
            $prof_id = (int)$reserva['profesor_id'];

            if ($nuevo_usuario_id !== $antiguo_usuario_id) {
                // Se cambió el alumno de la clase
                $res_new = $conn->query("SELECT nombre, apellido FROM usuarios WHERE id=$nuevo_usuario_id");
                $new_u = $res_new->fetch_assoc();
                $nombre_new = trim(($new_u['nombre']??'') . ' ' . ($new_u['apellido']??''));

                // Aviso a quien perdió el turno
                if ($id_sesion !== $antiguo_usuario_id) {
                    $msg_old = "Tu reserva de {$reserva['instrumento']} el $fecha_f a las $hora_f hs con {$reserva['profesor_nombre']} ha sido reasignada a otro usuario.";
                    enviarNotificacion($conn, $antiguo_usuario_id, $msg_old, 'warning', 'mis-reservas.php');
                }
                // Aviso a quien ganó el turno
                if ($id_sesion !== $nuevo_usuario_id) {
                    $msg_new = "Se te ha asignado la reserva de {$reserva['instrumento']} el $fecha_f a las $hora_f hs con {$reserva['profesor_nombre']}$estado_txt.";
                    enviarNotificacion($conn, $nuevo_usuario_id, $msg_new, 'success', 'mis-reservas.php');
                }
                // Aviso al profesor del cambio
                if ($id_sesion !== $prof_id) {
                    $msg_pro = "Tu clase de {$reserva['instrumento']} del $fecha_f a las $hora_f hs fue reasignada de {$reserva['alu_nom']} a $nombre_new$estado_txt.";
                    enviarNotificacion($conn, $prof_id, $msg_pro, 'info', 'mis-reservas.php');
                }
            } else if ($nuevo_estado !== $reserva['estado']) {
                // Solo cambió el estado a pendiente o confirmada
                if ($id_sesion !== $antiguo_usuario_id) {
                    $msg_est_alu = "El estado de tu clase de {$reserva['instrumento']} el $fecha_f a las $hora_f hs ha cambiado a: " . strtoupper($nuevo_estado) . ".";
                    enviarNotificacion($conn, $antiguo_usuario_id, $msg_est_alu, 'info', 'mis-reservas.php');
                }
                if ($id_sesion !== $prof_id) {
                    $msg_est_pro = "El estado de tu clase de {$reserva['instrumento']} del $fecha_f con {$reserva['alu_nom']} ha cambiado a: " . strtoupper($nuevo_estado) . ".";
                    enviarNotificacion($conn, $prof_id, $msg_est_pro, 'info', 'mis-reservas.php');
                }
            }

            if (!empty($observaciones)) {
                $msg_obs = "Administración dejó una nota en tu clase de {$reserva['instrumento']} ($fecha_f): \"$observaciones\"";
                enviarNotificacion($conn, $nuevo_usuario_id, $msg_obs, 'info', 'mis-reservas.php');
            }


            $conn->commit();
            echo "<script>window.location.href='reservas.php?ok=1';</script>";
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$fecha_f = date('d/m/Y', strtotime($reserva['fecha']));
$hora_f = substr($reserva['hora'], 0, 5);
?>

<style>
    :root { --card:#1f2937; --bg:#111827; --muted:#9ca3af; --border:rgba(255,255,255,0.08); --pri:#4f46e5; --accent:#6366f1; }
    .container-edit { max-width: 700px; margin: 40px auto; padding: 0 20px; font-family: 'Inter', sans-serif; }
    .info-card { background: linear-gradient(165deg, #1e293b 0%, #0f172a 100%); border: 1px solid var(--border); border-radius: 24px; padding: 30px; margin-bottom: 25px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
    .profesor-name { font-size: 28px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 12px; }
    .form-edit { background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: 24px; padding: 35px; color: #fff; }
    .field-group { margin-bottom: 25px; }
    label { display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 10px; }
    select, textarea { width: 100%; padding: 16px; border-radius: 14px; background: #0f172a; border: 1px solid var(--border); color: #fff; font-size: 15px; outline: none; }
    .btn-group { display: flex; gap: 15px; margin-top: 35px; }
    .btn { padding: 16px; border-radius: 14px; font-weight: 800; font-size: 14px; cursor: pointer; text-align: center; border: none; flex: 1; text-decoration: none; }
    .btn-save { background: var(--pri); color: #fff; }
    .btn-cancel { background: rgba(255,255,255,0.05); color: #9ca3af; border: 1px solid var(--border); }
    .alert-err { background: rgba(239,68,68,0.1); color: #fca5a5; padding: 15px; border-radius: 12px; border: 1px solid rgba(239,68,68,0.2); margin-bottom: 20px; font-weight: 700; text-align: center; }
</style>

<div class="container-edit">
    <div class="info-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
            <span style="font-size: 10px; color: var(--muted); font-weight: 700;">ID RESERVA #<?= $id ?></span>
            <span style="padding: 5px 12px; border-radius: 8px; font-size: 10px; font-weight: 900; background: rgba(99, 102, 241, 0.2); color: #a5b4fc;">
                <?= strtoupper($reserva['tipo_turno']) ?>
            </span>
        </div>
        <h2 class="profesor-name"><i class="fas fa-user-tie" style="color: var(--pri);"></i> <?= h($reserva['profesor_nombre']) ?></h2>
        <p style="color: var(--muted); margin-top: 10px; font-size: 14px;"><i class="fas fa-music"></i> Instrumento: <strong><?= h($reserva['instrumento']) ?></strong></p>
        <div style="display: flex; gap: 40px; border-top: 1px solid var(--border); margin-top: 20px; padding-top: 20px;">
            <div>
                <span style="font-size: 10px; color: var(--muted); display: block;">FECHA</span>
                <span style="color: #f1f5f9; font-weight: 600;"><?= $reserva['dia_semana'] ?> <?= $fecha_f ?></span>
            </div>
            <div>
                <span style="font-size: 10px; color: var(--muted); display: block;">HORA</span>
                <span style="color: #f1f5f9; font-weight: 600;"><?= $hora_f ?> HS</span>
            </div>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-err">✗ <?= h($error) ?></div><?php endif; ?>

    <div class="form-edit">
        <form method="POST" onsubmit="return confirm('¿Confirmas que deseas aplicar los cambios?');">
            <div class="field-group">
                <label>Usuario Responsable</label>
                <select name="usuario_id" required>
                    <?php foreach($usuarios_lista as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($u['id'] == $reserva['alumno_id']) ? 'selected' : '' ?>>
                            <?= h($u['apellido'] . " " . $u['nombre']) ?> [<?= strtoupper($u['rol']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-group">
                <label>Estado</label>
                <select name="estado">
                    <option value="confirmada" <?= ($reserva['estado'] !== 'pendiente') ? 'selected' : '' ?>>✓ Confirmada</option>
                    <option value="pendiente" <?= ($reserva['estado'] === 'pendiente') ? 'selected' : '' ?>>⏳ Pendiente</option>
                </select>
            </div>

            <div class="field-group">
                <label>Descripción / Notas</label>
                <textarea name="descripcion" rows="4"><?= h((string)$reserva['observaciones']) ?></textarea>
            </div>

            <div class="btn-group">
                <a href="reservas.php" class="btn btn-cancel">Descartar</a>
                <button type="submit" class="btn btn-save">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>