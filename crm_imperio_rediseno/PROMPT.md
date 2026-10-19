# Prompt para aplicar el rediseño de CRM Imperio con VS Code

Este archivo tiene **el prompt para pegar en el agente de VS Code** (GitHub Copilot en modo *Agent* o la extensión de Claude Code) y los prompts cortos para seguir fase por fase.

---

## Antes de empezar (lo hacés vos)

1. Descomprimí el ZIP y copiá la carpeta completa **dentro de la raíz del repo** `crm_imperio`, con el nombre `_rediseno/`. Tiene que quedar así:
   ```
   crm_imperio/
   ├── _rediseno/
   │   ├── PROMPT.md   ← este archivo
   │   ├── README.md
   │   ├── base/       ← style.css nuevo, ui_helpers.php, header/footer de referencia
   │   └── diseno/     ← index.html, preview/*.html, capturas/*.png, fuente/*.dc.html
   ├── dashboard.php
   ├── includes/
   └── ...
   ```
2. Agregá `_rediseno/` al `.gitignore` (es material de referencia, no se sube al servidor).
3. Abrí `_rediseno/diseno/index.html` en el navegador para tener las pantallas a mano mientras probás.
4. Abrí el repo en VS Code, abrí el chat del agente en **modo Agent** y pegá el prompt principal de abajo.
5. El agente hace **una fase y se detiene**. Probás en local (WampServer/XAMPP) con la lista de control de esa fase y, si está bien, le pedís la siguiente con los prompts cortos del final.

---

## Prompt principal (copiar todo el bloque)

```text
Sos un desarrollador front-end senior trabajando en "CRM Imperio", un sistema PHP 7.4 + MySQL (PDO) de ventas a crédito con roles (vendedor, verificador, entregador, supervisor, admin). Vas a aplicar un REDISEÑO VISUAL completo, para PC y para celular, sin cambiar el comportamiento del sistema.

## Material de referencia (fuente de verdad del diseño)
Todo está en la carpeta _rediseno/ en la raíz del repo. Leelo antes de tocar nada:
- _rediseno/README.md — qué hay en el paquete y el mapa pantalla → archivo PHP.
- _rediseno/diseno/preview/*.html — las 16 pantallas diseñadas en HTML estático con estilos inline. Son la referencia EXACTA de layout, textos, tamaños y colores. Los datos que muestran son de EJEMPLO.
- _rediseno/diseno/capturas/*.png — capturas de esas mismas pantallas (miralas si podés ver imágenes).
- _rediseno/base/style.css — el NUEVO style.css completo: tokens (variables), componentes (.card, .cell, .group, .btn, .input, .segmented, .chip, .status, .tag, .avatar, .kpi, .ring, .lifecycle, .tabbar, .fab, .action-bar, .data-table, etc.) y una sección de COMPATIBILIDAD que remapea las variables viejas (--ink, --accent, --rev-bg…) a la paleta nueva.
- _rediseno/base/ui_helpers.php — helpers de presentación: ui_icon(), ui_initials(), ui_avatar(), ui_money(), ui_status(), ui_nav() (menú por rol) y ui_is_active().
- _rediseno/base/header.reference.php y footer.reference.php — el nuevo layout: barra superior + barra lateral en PC, barra inferior + botón flotante en celular. Ya probados con sesiones de admin, vendedor y entregador.
- _rediseno/base/tokens.agenda-daq.json — el sistema de diseño original (Agenda DAQ), por si necesitás un valor.

## Sistema de diseño (resumen)
- Fuente Public Sans (400/500/600/700), letter-spacing -0.01em; títulos grandes -0.04em. Números con font-variant-numeric: tabular-nums (clase .tab).
- Fondo de pantalla #f6f8fb; tarjetas #ffffff con borde 1px #dee2e6, radio 8px y sombra 0 2px 8px rgba(9,30,66,.06); campos y chips sobre #f0f4f7 con radio 4px.
- Texto principal #2f384f, secundario #4a5a6b. #8c98a4 SOLO para íconos/chevrons, nunca para texto.
- Color de marca #108dff (botón principal, pestaña activa, anillo, foco). Para links de texto chico usar --tint-text (#0b63c4).
- Estados de venta, significado FIJO y siempre con punto + palabra (nunca solo color):
  revision = naranja #e77636 · aprobado = azul #108dff · entregado = verde #287f71 · rechazado = rojo #e7366b.
  RapiCompra se marca con índigo #522c8f.
- Lo único llamativo de una pantalla es el anillo de objetivo. Todo lo demás es sobrio.
- Íconos Lucide con versión fija 0.460.0 (no @latest), trazo 2px.
- Texto en español rioplatense con voseo ("Cargá", "Ingresá", "Tenés"). Sin emojis.
- Breakpoint principal: 1024px. Desde 1024px → barra lateral de 260px. Por debajo → barra inferior fija (4 accesos + "Más") y botón flotante "+".
- Áreas táctiles de al menos 44px. Inputs a 16px de fuente (evita el zoom de iOS).

## REGLAS QUE NO SE ROMPEN
1. NO cambies lógica de negocio: consultas SQL, cálculos, validaciones del servidor, permisos (require_role, in_array de roles), sesiones, redirecciones ni rutas.
2. NO cambies los atributos name= de los formularios, los action=, los method=, ni los parámetros GET que usa cada página (search, status, start, end, tab, locality, page, id, etc.).
3. Todo formulario POST conserva <?= csrf_field() ?>. Todo dato impreso sigue pasando por htmlspecialchars() (o helpers que ya escapan).
4. Compatible con PHP 7.4: nada de match(), enums, readonly ni named arguments.
5. No agregues frameworks nuevos (ni Alpine, ni React, ni Bootstrap). JavaScript vanilla mínimo y solo donde haga falta.
6. Mantené Tailwind CDN mientras queden clases utilitarias en las páginas. El markup NUEVO se escribe con las clases de style.css, no con Tailwind.
7. Los datos de las pantallas de referencia son de ejemplo: usá SIEMPRE los datos reales que la página ya consulta. Si un elemento del diseño necesita un dato que la página no tiene, agregalo solo si sale con una consulta simple y de solo lectura en esa misma página; si no, omitilo y anotalo en el reporte.
8. Trabajá UNA FASE POR VEZ. Al terminar cada fase: hacé commit con un mensaje claro, escribí el reporte (formato al final) y DETENETE a esperar que te diga "seguí".
9. Si algo del diseño choca con una regla, gana la regla. Avisame en el reporte.

## Mapa de pantallas
| Diseño (en _rediseno/diseno/preview/) | Archivo del sistema |
|---|---|
| Main.html (PC) + Movil.html (celular) | dashboard.php |
| Ficha.html + FichaMovil.html | ver_ficha.php |
| NuevaVenta.html + NuevaVentaMovil.html | cargar_venta.php y editar_venta.php |
| Login.html + LoginMovil.html | index.php |
| HistorialMovil.html | historial_ventas.php |
| EntregasMovil.html | entregas.php |
| ComisionesMovil.html | comisiones.php |
| MisVentasMovil.html | perfil_vendedor.php |
| VendedoresMovil.html | lista_vendedores.php y ventas_vendedor.php |
| UsuariosMovil.html | register_user.php |
| AuditoriaMovil.html | admin_audit.php |
| MasMovil.html | mas.php (NUEVO) |
Las secciones que solo tienen diseño de celular, en PC usan los mismos componentes dentro del layout con barra lateral: listas → .data-table dentro de .card, filtros en una fila, encabezado .page-head.

## FASES

### Fase 0 — Preparación (no modifica archivos)
- Creá la rama: git checkout -b rediseno-ui
- Leé _rediseno/README.md, los archivos de _rediseno/base/ y recorré las previews.
- Listá qué páginas incluyen includes/header.php y includes/footer.php y cuáles no (index.php, endpoints POST, export_csv.php, instructivo_*.html).
- Respondeme con el plan y las dudas concretas. Detenete.

### Fase 1 — Base visual y layout (toda la app cambia de aspecto)
- Reemplazá style.css por _rediseno/base/style.css (completo).
- Copiá _rediseno/base/ui_helpers.php a includes/ui_helpers.php y cargalo al final de includes/functions.php con require_once __DIR__ . '/ui_helpers.php';
- Hacé que status_badge() devuelva ui_status($status) (misma firma, las páginas no cambian).
- Reemplazá includes/header.php por _rediseno/base/header.reference.php y includes/footer.php por _rediseno/base/footer.reference.php. Compará con los originales y conservá cualquier cosa que el original hacía y la referencia no (si encontrás algo, avisá).
- Creá mas.php siguiendo MasMovil.html: require 'includes/db.php', mismo control de sesión que dashboard.php, usa ui_nav() para listar las secciones a las que el rol tiene acceso, más "Cambiar contraseña" (link a la pantalla donde hoy se cambia, perfil_vendedor.php?id=<propio>) y "Cerrar sesión". Se usa sobre todo en celular, pero en PC también se tiene que ver bien.
- Quitá la fuente Outfit/Caveat de cualquier lugar donde siga cargándose.
- Probá: sin errores de PHP, todas las páginas cargan, el menú muestra lo mismo que antes según rol.

### Fase 2 — Panel (dashboard.php)
- PC según Main.html: encabezado (fecha + "Panel" + saludo), anillo de objetivo con $myTarget/$myProgress/$myPct (stroke-dashoffset = 326.7 * (1 - min(pct,100)/100)), 4 KPI para admin con $stats/$statsMonto, filtro de período (form GET start/end existente) — el segmented Hoy/Semana/Mes puede armar los links con start/end; "Personalizado" muestra los inputs date.
- Bandeja: tabla .data-table en PC con avatar, cliente + tags (Contado, RapiCompra, N intentos), localidad, artículo + total, vendedor, verificador (o botón "Asignar" para quien puede asignar, usando el mecanismo que ya existe), estado y fecha. La fila entera lleva a ver_ficha.php?id=.
- Celular según Movil.html: anillo compacto, segmented por estado si los datos ya están, lista .group/.cell con avatar, nombre, artículo · total, punto de estado + verificador/intentos.
- Objetivos del equipo (admin): grilla de tarjetas con .progress.
- Conservá: exportación PDF de pendientes, filtro por verificador, toast de "venta registrada" (usá .toast).

### Fase 3 — Ficha de venta (ver_ficha.php)
- Según Ficha.html (PC, 2 columnas) y FichaMovil.html (celular, una columna).
- Cabecera: avatar grande, nombre, estado, DNI · localidad · tipo. Ciclo de vida .lifecycle (Cargada → En verificación → Aprobada → Entregada; si está rechazada, el paso correspondiente con .is-rejected y el motivo).
- Datos en filas .kv (en celular quedan etiqueta arriba y valor abajo, ya lo resuelve el CSS).
- Acciones rápidas en celular: WhatsApp (wa.me con normalize_ar_whatsapp() que ya existe), Registrar contacto (el formulario/modal actual de registrar_contacto.php), Mapa (client_map_link).
- Aprobar / Rechazar / Entregar: usan EXACTAMENTE los formularios actuales (update_status.php, rechazar_venta.php, csrf incluidos). En celular van en .action-bar fija abajo; en PC arriba a la derecha.
- Documentación: miniaturas de las imágenes reales; PDF con ícono. Conservá borrar archivo (delete_sale_file.php) y subir más.
- Historial de contacto como lista con ícono por resultado (contact_result_label()).
- El modal de edición de managers pasa a .sheet.

### Fase 4 — Nueva venta y edición (cargar_venta.php, editar_venta.php)
- PC según NuevaVenta.html: secciones numeradas en tarjetas (Tipo de venta, Cliente, Laborales, Detalle, Documentación) + columna derecha con Resumen (total final calculado en vivo con JS: cuotas × monto + adelanto; en contado = precio) y botón Guardar.
- Tipo y método de pago como tarjetas .choice (los mismos radios name="sale_type" / name="payment_method").
- Celular según NuevaVentaMovil.html: el MISMO <form> dividido en 4 pasos (Cliente, Domicilio, Venta, Fotos y cierre) con .steps-progress, botones Atrás/Siguiente en .action-bar y "Guardar venta" en el último. Por debajo de 1024px el JS muestra un paso por vez y valida los campos del paso con reportValidity() antes de avanzar; en PC se ven todas las secciones. Si JS falla, se ve el formulario completo (mejora progresiva).
- Frecuencia y día de cobro en celular como botones (segmented/chips) que actualizan los <select> o inputs reales ocultos. Conservá la regla existente: Sábado solo para Tafí del Valle y Leales.
- Botón "Usar mi ubicación actual": navigator.geolocation → completa client_map_link con https://www.google.com/maps?q=LAT,LNG. Si el permiso se niega, mostrá un .notice y dejá el campo para pegar el enlace.
- Fotos: en celular dos botones, "Sacar foto" (input file accept="image/*" capture="environment") y "Elegir de la galería"; ambos inputs con name="sale_files[]" (verificá en save_sale.php que recibe bien los dos).
- Mantené: campos obligatorios según crédito/contado (data-required-credito), valores "sticky" con old(), previsualización de archivos.

### Fase 5 — Resto de secciones
Una por commit, en este orden: historial_ventas.php, entregas.php, comisiones.php, perfil_vendedor.php, lista_vendedores.php + ventas_vendedor.php, register_user.php, admin_audit.php.
- Celular: copiá la estructura de su preview (filtros en .chips / .segmented, listas .group/.cell agrupadas por día o por localidad, tarjetas de entrega con "Cómo llegar" y "Entregar").
- PC: mismo encabezado .page-head, filtros en una fila dentro de una .card, tabla .data-table, paginación existente (renderPagination) con estilo nuevo.
- Entregas: el botón "Entregar" dispara el mismo POST que hoy. "Cómo llegar" usa client_map_link.
- Usuarios: el alta de usuario pasa a un .sheet que se abre con "Nuevo"; mismo formulario y mismos campos.
- Al terminar: si ya no quedan clases de Tailwind en las páginas, sacá el <script> de Tailwind CDN del header; si quedan, listalas en el reporte y NO lo saques.

### Fase 6 — Ingreso (index.php) y pulido
- index.php según Login.html (PC, dos paneles) y LoginMovil.html (celular, hoja blanca sobre azul oscuro). Mismo form POST, mismos name="username"/"password", csrf y mensajes de error / rate limiting / timeout que ya existen. Botón para mostrar la contraseña.
- Revisá en 375px, 768px y 1280px todas las páginas: sin scroll horizontal (salvo tablas dentro de .table-wrap), nada tapado por la barra inferior o la action-bar.
- Contraste: ningún texto en #8c98a4; links chicos en --tint-text.
- Borrá de style.css la sección de COMPATIBILIDAD solo si ya ninguna página usa var(--ink…), var(--accent…), .table-light, .input-light, .chip-*, .navpill-*.

## Lista de control al final de cada fase
- [ ] php -l sin errores en todos los archivos tocados.
- [ ] Cada rol (admin, supervisor, verificador, vendedor, entregador) ve exactamente las mismas secciones y acciones que antes.
- [ ] Los formularios envían los mismos campos (comparar con git diff que no cambió ningún name=).
- [ ] Funciona en 375px y en 1280px.
- [ ] Sin errores en la consola del navegador; los íconos aparecen.

## Formato del reporte (al terminar cada fase)
1. Qué archivos cambiaste (lista).
2. Qué quedó distinto al diseño y por qué.
3. Elementos del diseño que omitiste por falta de datos.
4. Cómo probarlo (URL y rol).
5. Riesgos o dudas.
Después del reporte, detenete.

Empezá por la Fase 0.
```

---

## Prompts cortos para seguir

- **Seguir:** `La fase anterior está probada y funciona. Seguí con la Fase N del prompt principal (_rediseno/PROMPT.md). Mismas reglas.`
- **Corregir algo:** `En [archivo] en celular (375px) [describí qué ves]. Comparalo con _rediseno/diseno/preview/[Pantalla].html y corregilo sin tocar la lógica.`
- **Revisar un rol:** `Revisá todas las páginas como rol "entregador": decime qué ve en la barra lateral, en la barra inferior y en mas.php, y si alguna acción quedó visible sin permiso.`
- **Volver atrás una fase:** `Revertí el último commit de la rama rediseno-ui (git revert) y explicame qué se deshizo.`
- **Si el agente se desvía:** `Volvé a leer las REGLAS QUE NO SE ROMPEN del prompt principal en _rediseno/PROMPT.md y revisá tus cambios contra ellas antes de seguir.`

---

## Para subir a producción

1. Probá todo en local y después en el VPS de prueba (donde ya replicaste CRM Imperio).
2. Hacé backup de la base y de los archivos del servidor.
3. Mergeá la rama `rediseno-ui` y subí. El rediseño no cambia la base de datos, así que no hay migraciones.
