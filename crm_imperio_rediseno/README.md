# CRM Imperio · Paquete de rediseño

Rediseño visual de CRM Imperio para PC y celular, con el sistema de diseño Agenda DAQ (Public Sans, azul #108DFF). Todo lo necesario para aplicarlo en el repo con el agente de VS Code.

## Qué hay

| Carpeta / archivo | Para qué sirve |
|---|---|
| `PROMPT.md` | **Empezá por acá.** El prompt para pegar en VS Code, por fases, más los prompts cortos para seguir. |
| `diseno/index.html` | Galería de las 16 pantallas. Abrilo con doble clic en cualquier navegador. |
| `diseno/preview/*.html` | Cada pantalla en HTML estático: es la referencia exacta para el agente. |
| `diseno/capturas/*.png` | Capturas de cada pantalla, en el tamaño real. |
| `diseno/fuente/*.dc.html` | Archivos originales del lienzo de diseño (por si querés editarlos en Claude). |
| `base/style.css` | El **nuevo style.css** completo: variables, componentes y compatibilidad con las páginas viejas. |
| `base/ui_helpers.php` | Funciones de presentación: íconos, avatares, montos, estados y el menú por rol. |
| `base/header.reference.php` / `footer.reference.php` | El nuevo layout: barra lateral en PC, barra inferior + botón "+" en celular. |
| `base/tokens.agenda-daq.json` | El sistema de diseño original, con todos los valores. |

## Mapa de pantallas

| Pantalla | PC | Celular | Archivo PHP |
|---|---|---|---|
| Panel | `Main` | `Movil` | `dashboard.php` |
| Ficha de venta | `Ficha` | `FichaMovil` | `ver_ficha.php` |
| Nueva venta | `NuevaVenta` | `NuevaVentaMovil` | `cargar_venta.php`, `editar_venta.php` |
| Ingreso | `Login` | `LoginMovil` | `index.php` |
| Más (menú) | — | `MasMovil` | `mas.php` (nuevo) |
| Historial | — | `HistorialMovil` | `historial_ventas.php` |
| Entregas | — | `EntregasMovil` | `entregas.php` |
| Comisiones | — | `ComisionesMovil` | `comisiones.php` |
| Mis ventas | — | `MisVentasMovil` | `perfil_vendedor.php` |
| Vendedores | — | `VendedoresMovil` | `lista_vendedores.php`, `ventas_vendedor.php` |
| Usuarios | — | `UsuariosMovil` | `register_user.php` |
| Auditoría | — | `AuditoriaMovil` | `admin_audit.php` |

Las secciones que solo tienen diseño de celular usan en PC los mismos componentes dentro del layout con barra lateral.

## Menú por rol (barra inferior en celular)

| Rol | Accesos fijos | En "Más" |
|---|---|---|
| admin / supervisor | Panel · Historial · Entregas · Comisiones | Nueva venta, Mis ventas y, para admin, Vendedores, Usuarios y Auditoría |
| vendedor / verificador | Panel · Nueva · Historial · Mis ventas | — |
| entregador | Panel · Entregas · Historial · Mis ventas | Nueva venta |

Los permisos son exactamente los del header actual: nadie ve una sección que hoy no ve.

## Colores de estado (fijos)

- **En revisión:** naranja
- **Aprobada:** azul
- **Entregada:** verde
- **Rechazada:** rojo
- **RapiCompra:** índigo

Cada estado va siempre con su palabra, no solo con el color.

## Notas

- Los nombres, montos y cifras de las pantallas son de ejemplo. El sistema muestra sus datos reales.
- No hay cambios en la base de datos.
- El nuevo `style.css` remapea las variables viejas (`--ink`, `--accent`, `--rev-bg`…), así que apenas lo reemplazás toda la app toma la paleta nueva, incluso las páginas que todavía no se migraron.
- Íconos: Lucide con versión fija `0.460.0` en vez de `@latest`, para que una actualización no rompa los íconos sin aviso.
