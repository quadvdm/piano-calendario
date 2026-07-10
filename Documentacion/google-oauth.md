# Integración Google OAuth — Anima Música

## Credenciales del proyecto

| Campo | Valor |
|---|---|
| **Client ID** | `35991205187-oasjfgotcqsavn222k7frmlvhidt6f2m.apps.googleusercontent.com` |
| **Número de proyecto** | `35991205187` |
| **Tipo de credencial** | OAuth 2.0 — Aplicación Web |
| **Archivo de credenciales** | `client_secret_35991205187-oasjfgotcqsavn222k7frmlvhidt6f2m.apps.googleusercontent.com.json` |

> ⚠️ El archivo JSON **no está en el repositorio** (ni debe estarlo). Hay que descargarlo manualmente desde Google Cloud Console y colocarlo en la raíz del proyecto.

---

## Cómo acceder a Google Cloud Console

1. Ir a [https://console.cloud.google.com/](https://console.cloud.google.com/)
2. **Loguearse con la cuenta de Google propietaria del proyecto** (ver sección abajo).
3. En el selector de proyectos (arriba a la izquierda), buscar el proyecto con número **35991205187**.
4. Ir a **APIs y servicios → Credenciales** para ver/editar el cliente OAuth.

### Cuenta de Google requerida
La cuenta propietaria es la que creó el proyecto en Google Cloud. El Client ID `35991205187-...` identifica el proyecto de forma única, pero **no expone el email del dueño**. Si no se recuerda la cuenta, se puede intentar con las cuentas de Google asociadas al proyecto (tipicamente la del administrador del sitio o del desarrollador original).

Una vez logueado en Cloud Console, en **IAM y administración → IAM** se pueden ver todos los usuarios con acceso al proyecto.

---

## Cómo funciona en el código

### Flujo de autenticación

```
Usuario hace clic en "Ingresar con Google"
        ↓
Se abre popup usando Google Identity Services (GSI)
        ↓
Google devuelve un código de autorización al popup
        ↓
El popup hace POST a login.php con el código + CSRF token
        ↓
login.php intercambia el código por un access_token
(POST a https://oauth2.googleapis.com/token con redirect_uri='postmessage')
        ↓
login.php consulta la userinfo de Google con el access_token
(GET https://www.googleapis.com/oauth2/v2/userinfo)
        ↓
Se crea o actualiza el usuario en la BD (tabla `usuarios`)
        ↓
Sesión iniciada → redirect a dashboard.php
```

### Archivos involucrados

| Archivo | Rol |
|---|---|
| `login.php` | Lógica principal del OAuth (intercambio de código, upsert de usuario, inicio de sesión) |
| `index.php` | Genera URL de autenticación alternativa (flujo redirect) |
| `client_secret_*.json` | Credenciales OAuth (no versionado en git) |

### Scopes solicitados
- `email` — dirección de correo del usuario
- `profile` — nombre y foto de perfil

### Librería de Google usada
```html
<script src="https://accounts.google.com/gsi/client" async defer></script>
```
Google Identity Services (GIS/GSI) — la librería moderna de Google para OAuth 2.0.

### redirect_uri
- En el flujo **popup** (login.php): `postmessage` (requerimiento estricto de Google para popups)
- En el flujo **redirect** (index.php): `https://animamusica.ar/login.php`

---

## Configuración necesaria en Google Cloud Console

En **APIs y servicios → Credenciales → [el cliente OAuth]** deben estar configurados:

### Orígenes de JavaScript autorizados
- `https://animamusica.ar`
- `http://localhost` (para desarrollo local)

### URIs de redireccionamiento autorizados
- `https://animamusica.ar/login.php`

---

## Entorno local (desarrollo)

En `login.php` hay un bypass para `localhost`:
```php
$is_local = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || $_SERVER['HTTP_HOST'] === '127.0.0.1');
```
Si estás en local, el botón de Google es reemplazado por un acceso de prueba (`login_mock.php`) para evitar restricciones de OAuth en dominios no registrados.

---

## Para regenerar o descargar las credenciales

1. [https://console.cloud.google.com/apis/credentials](https://console.cloud.google.com/apis/credentials)
2. Seleccionar el cliente OAuth con número de proyecto `35991205187`
3. Clic en **Descargar JSON**
4. Renombrar si es necesario y colocar en la raíz del proyecto con el nombre exacto:
   `client_secret_35991205187-oasjfgotcqsavn222k7frmlvhidt6f2m.apps.googleusercontent.com.json`
