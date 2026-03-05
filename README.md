# CRM Imperio

Sistema CRM para gestión de ventas a crédito (financiamiento en cuotas). Desarrollado en PHP/MySQL con interfaz moderna usando Tailwind CSS.

---

## Descripción

CRM Imperio permite a equipos de ventas registrar, supervisar y gestionar créditos de manera estructurada. Cada venta sigue un ciclo de vida controlado por roles, con trazabilidad completa de acciones y documentación adjunta.

### Ciclo de vida de una venta

```
revision → aprobado → entregado
              ↘
            rechazado
```

| Estado | Descripción |
|--------|-------------|
| `revision` | Venta recién cargada, pendiente de aprobación |
| `aprobado` | Crédito aprobado, pendiente de entrega |
| `entregado` | Producto entregado al cliente |
| `rechazado` | Venta rechazada con motivo registrado |

---

## Roles y permisos

| Rol | Descripción | Permisos |
|-----|-------------|----------|
| `vendedor` | Agente de ventas | Cargar ventas, ver su propio historial y comisiones |
| `verificador` | Verifica documentación | Aprobar/rechazar/entregar ventas |
| `supervisor` | Supervisa equipo | Todo lo del verificador + ver comisiones globales |
| `admin` | Administrador total | Todo + gestión de usuarios + log de auditoría |

---

## Stack tecnológico

- **Backend:** PHP 7.4+ con PDO (prepared statements)
- **Base de datos:** MySQL / MariaDB
- **Frontend:** Tailwind CSS (CDN), Lucide Icons
- **Seguridad:** CSRF tokens, bcrypt, rate limiting, audit log
- **Servidor local:** WampServer / XAMPP

---

## Estructura de archivos

```
crm/
├── index.php                 # Login con rate limiting
├── dashboard.php             # Panel principal por rol
├── cargar_venta.php          # Formulario nueva venta
├── save_sale.php             # Procesador de carga de venta
├── ver_ficha.php             # Detalle completo de una venta
├── historial_ventas.php      # Historial con búsqueda
├── update_status.php         # Cambiar estado de venta (POST)
├── rechazar_venta.php        # Rechazar venta con motivo
├── perfil_vendedor.php       # Perfil + cambio de contraseña
├── comisiones.php            # Reporte de comisiones (admin/supervisor)
├── entregas.php              # Panel de entregas pendientes
├── lista_vendedores.php      # Lista de vendedores (admin)
├── register_user.php         # Alta de usuarios (admin)
├── admin_audit.php           # Log de auditoría (admin)
├── logout.php                # Cerrar sesión
├── config.php                # Credenciales DB (NO subir al repo)
├── config.example.php        # Plantilla de configuración
├── migration_fase3.sql       # SQL de tablas adicionales (NO subir al repo)
├── includes/
│   ├── db.php                # Conexión PDO + sesión + timeout
│   ├── functions.php         # CSRF, paginación, log_audit()
│   ├── header.php            # Navbar con control de roles
│   └── footer.php            # Pie de página + lucide.createIcons()
├── uploads/                  # Archivos adjuntos de ventas (ignorado en git)
└── img/                      # Logo e imágenes estáticas
```

---

## Tablas de base de datos

### `users`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `name` | VARCHAR | Nombre completo |
| `username` | VARCHAR | DNI (usado para login) |
| `password` | VARCHAR | Hash bcrypt |
| `role` | ENUM | vendedor / verificador / supervisor / admin |
| `phone` | VARCHAR | Teléfono de contacto |

### `sales`
Contiene todos los datos del cliente (nombre, DNI, dirección, teléfono), datos laborales, producto, plan de cuotas, estado, vendedor asignado, y campos de auditoría (created_at, updated_at, revisado_por, etc.).

### `sale_files`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `sale_id` | INT FK | Referencia a `sales.id` |
| `file_path` | VARCHAR | Ruta relativa del archivo |

### `login_attempts` *(Fase 3)*
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `ip` | VARCHAR(45) | IP del intento |
| `username` | VARCHAR | Usuario intentado |
| `attempted_at` | DATETIME | Timestamp del intento |

### `audit_log` *(Fase 3)*
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `user_id` | INT | Usuario que realizó la acción |
| `action` | VARCHAR | Acción (login, create_sale, aprobado…) |
| `target_type` | VARCHAR | Tipo de entidad afectada (sale, user) |
| `target_id` | INT | ID de la entidad afectada |
| `details` | TEXT | Descripción adicional |
| `ip` | VARCHAR(45) | IP desde donde se realizó |
| `created_at` | DATETIME | Timestamp |

---

## Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/danqueve/crm_imperio.git
cd crm_imperio
```

### 2. Configurar base de datos

Crear la base de datos en MySQL:

```sql
CREATE DATABASE crm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Crear archivo de configuración

Copiar la plantilla y completar con las credenciales reales:

```bash
cp config.example.php config.php
```

Editar `config.php`:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'crm_db');
define('DB_USER', 'root');
define('DB_PASS', 'tu_password');
```

### 4. Importar estructura de la base de datos

Importar el schema principal en phpMyAdmin (o por CLI). Luego, para activar las funcionalidades de Fase 3, ejecutar en phpMyAdmin el contenido de `migration_fase3.sql`:

```sql
-- Tabla para rate limiting de login
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(100) NOT NULL,
  attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de auditoría
CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  target_type VARCHAR(20) DEFAULT NULL,
  target_id INT DEFAULT NULL,
  details TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5. Configurar carpeta de uploads

```bash
mkdir uploads
chmod 755 uploads
```

### 6. Acceder al sistema

Abrir en el navegador: `http://localhost/crm/`

El primer usuario admin debe crearse directamente en la base de datos con contraseña hasheada:

```sql
INSERT INTO users (name, username, password, role)
VALUES ('Administrador', '12345678', '$2y$10$...hash_bcrypt...', 'admin');
```

---

## Seguridad implementada

### Fase 1 — Correcciones críticas
- **Credenciales fuera del código:** `config.php` con constantes DB, ignorado en git
- **SQL Injection eliminada:** Todas las consultas usan PDO prepared statements
- **Protección CSRF:** Token en sesión (`csrf_token()`, `csrf_verify()`, `csrf_field()`) en todos los formularios POST
- **Debug mode desactivado:** Sin `var_dump`, `print_r` ni mensajes de error expuestos

### Fase 2 — Seguridad de sesión y uploads
- **Session timeout:** 30 minutos de inactividad → logout automático
- **Session regeneration:** `session_regenerate_id(true)` tras login exitoso (previene session fixation)
- **Validación de uploads:** Verificación de tipo MIME real con `finfo_file()`, límite de 5MB, extensiones permitidas
- **Output escaping:** `htmlspecialchars()` en toda salida dinámica

### Fase 3 — Madurez operativa
- **Rate limiting en login:** Máximo 5 intentos por IP en 15 minutos. El 6to intento muestra tiempo de espera
- **Cambio de contraseña:** Cada usuario puede cambiar su propia clave desde su perfil (validación de clave actual, mínimo 6 caracteres, confirmación)
- **Audit log completo:** Registro automático de: login, create_sale, aprobado, entregado, revision, reject, change_password
- **Vista de auditoría:** `admin_audit.php` con filtros por acción y usuario, paginación

---

## Mejoras visuales implementadas

### Dashboard (`dashboard.php`)
- **Toast de confirmación:** Notificación deslizante al guardar una venta (reemplaza banner estático)
- **Filas con borde de color:** Indicador visual lateral según estado (amarillo=revisión, verde=aprobado)
- **Badges animados:** Punto parpadeante en ventas en revisión
- **Estado vacío:** Ilustración y mensaje cuando no hay ventas
- **Tarjetas de estadísticas:** Efecto hover con sombra de color y elevación suave
- **Contadores animados:** Números que incrementan con easing al cargar la página

### Ficha de venta (`ver_ficha.php`)
- **Pipeline visual:** Stepper horizontal mostrando el progreso del estado actual (revisión → aprobado → entregado)
- **Modal glassmorphism:** Ventana modal con efecto de cristal esmerilado y blur de fondo

### Cabecera (`includes/header.php`)
- **Avatar dinámico:** Inicial del usuario sobre gradiente de color calculado a partir del nombre
- **Navegación móvil rediseñada:** Íconos + etiqueta + punto indicador de página activa

---

## Flujo de uso típico

```
1. Vendedor carga venta en cargar_venta.php
   └─ Estado inicial: "revision"

2. Verificador/supervisor revisa en dashboard o historial
   ├─ Aprueba → estado: "aprobado"
   └─ Rechaza → estado: "rechazado" (con motivo)

3. Verificador/supervisor entrega en entregas.php
   └─ Estado: "entregado"

4. Admin consulta comisiones en comisiones.php
   └─ 5% del valor de ventas entregadas

5. Admin revisa acciones en admin_audit.php
   └─ Log completo con IP, usuario, fecha, detalles
```

---

## Variables de entorno / configuración

| Constante | Archivo | Descripción |
|-----------|---------|-------------|
| `DB_HOST` | config.php | Host de MySQL |
| `DB_NAME` | config.php | Nombre de la base de datos |
| `DB_USER` | config.php | Usuario de MySQL |
| `DB_PASS` | config.php | Contraseña de MySQL |
| `SESSION_TIMEOUT` | includes/db.php | Tiempo de inactividad (1800 seg) |
| `MAX_LOGIN_ATTEMPTS` | index.php | Intentos antes de bloqueo (5) |
| `LOCKOUT_WINDOW` | index.php | Ventana de bloqueo (900 seg = 15 min) |
| `MAX_FILE_SIZE` | save_sale.php | Tamaño máximo de upload (5 MB) |

---

## Archivos ignorados por git

```gitignore
config.php        # Credenciales de base de datos
uploads/          # Archivos subidos por usuarios
*.log             # Logs del servidor
*.sql             # Dumps con datos reales de clientes
.claude/          # Archivos de IDE/asistente
```

---

## Licencia

Uso interno. Proyecto privado de gestión comercial.
