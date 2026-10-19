# Raíz limpia del CRM Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Dejar la raíz del proyecto sin pantallas ni acciones PHP, manteniendo las URLs públicas históricas mediante reglas internas de Apache.

**Architecture:** Las pantallas permanecen en `app/pages`, los procesadores en `app/actions` y la herramienta en `app/tools`. Un `.htaccess` en la raíz reescribe internamente las rutas públicas antiguas hacia esas ubicaciones; de ese modo no quedan archivos puente PHP en la raíz ni se rompen enlaces, formularios o marcadores.

**Tech Stack:** PHP 8, Apache/mod_rewrite de WampServer, Git.

---

### Task 1: Crear el enrutamiento de compatibilidad

**Files:**
- Create: `.htaccess`
- Test: solicitudes a las rutas históricas de páginas, acciones, CSS e instructivos.

**Step 1: Definir las rutas públicas que ya existían.**

Separar la lista en pantallas (`app/pages`), acciones (`app/actions`), herramienta, CSS e instructivos.

**Step 2: Crear reglas de reescritura interna.**

Agregar `RewriteRule` para que, por ejemplo, `dashboard.php` cargue internamente `app/pages/dashboard.php`, sin cambiar la URL del navegador. Incluir la regla de `/` hacia la pantalla de acceso y bloquear el acceso web al directorio de configuración.

**Step 3: Verificar la sintaxis y la cobertura.**

Ejecutar una comprobación de que todas las rutas reubicadas tengan una regla declarada y revisar `.htaccess` manualmente.

### Task 2: Reubicar configuración y documentación pública

**Files:**
- Move: `config.php` → `config/database.php`
- Move: `config.example.php` → `config/database.example.php`
- Move: `instructivo_vendedor.html` → `docs/guides/instructivo_vendedor.html`
- Move: `instructivo_verificador.html` → `docs/guides/instructivo_verificador.html`
- Modify: `includes/db.php`
- Modify: `.gitignore`

**Step 1: Crear directorios de destino.**

Crear `config` y `docs/guides`.

**Step 2: Mover la configuración y actualizar su consumidor.**

Modificar `includes/db.php` para incluir `config/database.php`; cambiar `.gitignore` para que el archivo de credenciales continúe excluido del repositorio.

**Step 3: Mover los instructivos.**

Conservar sus rutas antiguas mediante las reglas de Apache.

### Task 3: Retirar puentes de la raíz y validar

**Files:**
- Delete: los 27 puentes PHP de la raíz
- Delete: `style.css` de la raíz
- Test: todos los PHP, reglas declaradas, renders CLI y estado Git.

**Step 1: Eliminar únicamente los puentes identificados.**

No tocar `includes`, `img`, `uploads`, `migrations`, `sql`, `README.md` ni archivos de herramientas del repositorio.

**Step 2: Ejecutar las validaciones.**

Ejecutar `php -l` para todos los PHP, validar que los destinos de las reglas existen y renderizar login, dashboard y vendedores desde sus rutas finales.

**Step 3: Confirmar y publicar.**

Revisar `git diff --check`, crear el commit y subir `main`.
