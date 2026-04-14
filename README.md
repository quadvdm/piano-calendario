REPORTE DE ANÁLISIS DE PROYECTO PHP: ÁNIMA MÚSICA
ARCHIVOS DE ENTRADA (ENTRY POINTS)
Estos archivos representan las rutas de acceso principales del sistema

\index.php (Llanding page y acceso a login Google)

\login.php (Procesamiento de credenciales Google OAuth)

\login_mock.php (Simulador de inicio de sesión para desarrollo)

\logout.php (Cierre de sesión y destrucción de cookies)

\dashboard.php (Panel principal de usuario/profesor)

\calendario.php (Interfaz de reserva de clases)

\profesores.php (Lista de staff y reservas directas)

\perfil.php (Gestión de datos de usuario y avatar)

\alumnos.php (Gestión de alumnos para profesores/admin-profesores)

\mis-reservas.php (Historial y tablero de control de clases del usuario)

\crear-reserva.php (Alta de disponibilidad horaria por profesores)

\profesor-cancelar-horario.php (Baja de disponibilidad horaria)

\procesar.php (Enrutador central de acciones: reservas y cancelaciones)

\admin\index.php (Panel de métricas administrativas)

\admin\usuarios.php (Gestión central de cuentas y roles)

\admin\profesores1.php (Gestión de staff docente)

\admin\instrumentos.php (Configuración del catálogo de música)

\admin\horarios.php (Configuración de la agenda global)

\admin\reservas.php (Auditoría de asistencia y estados de clases)

\admin\auditoria.php (Historial de notificaciones y movimientos)

\admin\generar-backup.php (Generador de reportes HTML de emergencia)

================================================================================
RELACIÓN DE INCLUSIONES (QUIÉN INCLUYE A QUIÉN)

ARCHIVO ORIGEN: \dashboard.php
ESTE ARCHIVO INCLUYE A:
-> \config\database.php
-> \navbar.php

ARCHIVO ORIGEN: \calendario.php
ESTE ARCHIVO INCLUYE A:
-> \calendario_logic.php
-> \navbar.php

ARCHIVO ORIGEN: \alumnos.php
ESTE ARCHIVO INCLUYE A:
-> \config\database.php
-> \navbar.php
-> \procesar-reserva.php
-> \procesar-suscripcion.php

ARCHIVO ORIGEN: \perfil.php
ESTE ARCHIVO INCLUYE A:
-> \config\database.php
-> \navbar.php
-> \calendario_logic.php

ARCHIVO ORIGEN: \procesar.php
ESTE ARCHIVO INCLUYE A:
-> \config\database.php
-> \procesar-reserva.php
-> \procesar-suscripcion.php
-> \procesar-cancelar-suscripcion.php
-> \procesar-cancelacion.php
-> \procesar-pasar-semana.php
-> \procesar-editar-clase.php

ARCHIVO ORIGEN: \admin\auth.php
ESTE ARCHIVO INCLUYE A:
-> \config\database.php

ARCHIVO ORIGEN: \admin\header.php
ESTE ARCHIVO INCLUYE A:
-> \config\database.php

ARCHIVO ORIGEN: \admin\usuarios.php
ESTE ARCHIVO INCLUYE A:
-> \admin\auth.php
-> \admin\header.php

ARCHIVO ORIGEN: \admin\profesores1.php
ESTE ARCHIVO INCLUYE A:
-> \admin\profesores1_logic.php
-> \admin\header.php

================================================================================
RELACIÓN INVERSA (DESDE DÓNDE SE USA CADA ARCHIVO)

ARCHIVO INCLUIDO: \config\database.php
ES INCLUIDO DESDE:
<- \navbar.php
<- \alumnos.php
<- \perfil.php
<- \procesar.php
<- \admin\auth.php
<- \admin\header.php
<- \calendario_logic.php

ARCHIVO INCLUIDO: \navbar.php
ES INCLUIDO DESDE:
<- \dashboard.php
<- \calendario.php
<- \profesores.php
<- \perfil.php
<- \alumnos.php
<- \mis-reservas.php

ARCHIVO INCLUIDO: \admin\auth.php
ES INCLUIDO DESDE:
<- \admin\index.php
<- \admin\usuarios.php
<- \admin\usuarios-ver.php
<- \admin\usuarios-editar.php
<- \admin\horarios.php
<- \admin\reservas.php
<- \admin\auditoria.php
<- \admin\generar-backup.php

ARCHIVO INCLUIDO: \admin\header.php
ES INCLUIDO DESDE:
<- Todos los archivos de la carpeta \admin\ que requieren interfaz visual.

ARCHIVO INCLUIDO: \procesar-reserva.php
ES INCLUIDO DESDE:
<- \procesar.php (Acción: reservar tipo extra)
<- \alumnos.php (Acción: asignar clase manual)

ARCHIVO INCLUIDO: \procesar-suscripcion.php
ES INCLUIDO DESDE:
<- \procesar.php (Acción: reservar tipo fijo)
<- \alumnos.php (Acción: asignar suscripción manual)