# Reordenamiento seguro del CRM Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ordenar la implementación del CRM sin cambiar ninguna URL pública ni el comportamiento de sus formularios y acciones.

**Architecture:** Las pantallas se alojan en `app/pages/` y los endpoints de negocio en `app/actions/`. Cada archivo PHP histórico de raíz queda como un puente mínimo que requiere su nueva implementación, con lo cual permanecen válidos los enlaces, redirecciones, formularios, marcadores y accesos externos existentes. `style.css` conserva su URL pública y carga la implementación desde `assets/css/style.css`.

**Tech Stack:** PHP 7.4, MySQL/PDO, CSS, Git.

---

### Task 1: Crear la estructura y mover la implementación

**Files:**
- Create: `app/pages/`, `app/actions/`, `assets/css/`
- Move: pantallas desde raíz a `app/pages/`
- Move: endpoints desde raíz a `app/actions/`
- Move: `style.css` a `assets/css/style.css`

**Step 1: Comprobar que cada archivo de origen y destino existe o está libre.**

Run: `Test-Path <cada-origen>; Test-Path <cada-destino>`
Expected: todos los orígenes existen y los destinos no.

**Step 2: Mover archivos por categoría.**

Run: `Move-Item -LiteralPath <origen> -Destination <destino>`
Expected: los archivos quedan exclusivamente en sus carpetas de implementación.

**Step 3: Ajustar includes PHP internos.**

Cada pantalla o acción movida usa:

```php
require dirname(__DIR__, 2) . '/includes/db.php';
include dirname(__DIR__, 2) . '/includes/header.php';
```

Expected: los includes se resuelven desde la nueva profundidad sin alterar sesiones, SQL ni permisos.

### Task 2: Mantener compatibilidad de rutas públicas

**Files:**
- Create: puentes PHP de raíz para cada pantalla y endpoint anterior
- Create: `style.css` como puente CSS

**Step 1: Crear un puente por cada ruta PHP.**

```php
<?php
require __DIR__ . '/app/pages/dashboard.php';
```

Los endpoints usan el mismo patrón con `app/actions/`.

**Step 2: Crear el puente de CSS.**

```css
@import url("assets/css/style.css");
```

Expected: `dashboard.php`, `save_sale.php`, `style.css` y el resto conservan exactamente sus URLs históricas.

### Task 3: Verificar e informar

**Files:**
- Test: todos los `*.php` fuera de `crm_imperio_rediseno/`

**Step 1: Ejecutar análisis de sintaxis.**

Run: `rg --files -g '*.php' | % { php -l $_ }`
Expected: sin errores de sintaxis.

**Step 2: Renderizar por CLI las pantallas representativas sin POST.**

Run: `php index.php` y renders con sesión simulada para panel y administración.
Expected: salida sin warnings ni errores fatales.

**Step 3: Revisar referencias de rutas.**

Run: `rg -n 'app/(pages|actions)' app` y `git diff --check`.
Expected: solo los puentes de raíz referencian rutas internas y no hay errores de formato.

**Step 4: Commit y push.**

Run: `git add ...; git commit -m "refactor: ordena la estructura del CRM"; git push -u origin rediseno-ui`
Expected: rama publicada sin sobrescribir cambios remotos.
