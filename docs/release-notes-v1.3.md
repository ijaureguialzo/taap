# Release Notes — TAAP v1.3

**The Agentic AI Playground 1.3** — modernización de la app iOS con Liquid
Glass (iOS 26) y una batería de mejoras identificadas tras la v1.2.

## App iOS — Liquid Glass

- **Deployment target iOS 26.0** (Opción A): la app pasa del iOS 17 al
  lenguaje de diseño Liquid Glass del sistema operativo. La app no se
  instala en dispositivos anteriores a iOS 26.
- **Botones con vidrio**: CTAs primarios (`.glassProminent`) en login,
  creación de agente, archivos, logs, composer del chat y toolbar (`+`).
  Los CTAs secundarios usan `.glass` (p. ej. «Cancelar» en el 2FA).
- **Runtime badge en la lista de agentes**: fondo de vidrio con el tinte
  sutil de la marca (`glassEffect` con ShapeCapsule).
- **Helpers centralizados** en `Support/LiquidGlass.swift`
  (`liquidGlassCard` / `.regular.tint()`) para que toda la app reaccione al
  mismo cambio de look. Los tokens de marca (`TaapColor`, dark mode a 3
  estados, i18n es/en/eu) no cambian: el glass es la presentación, no la
  identidad.
- **Accesibilidad**: los materiales de vidrio no sustituyen al tinte sólido
  para texto sobre glass — contraste AA garantizado; el sistema aplica Reduce
  Motion y Increase Contrast de forma automática.

## Otros cambios de esta versión

- **2FA (TOTP) + passkeys** — opt-in. Perfil web + API v1 + app iOS (reto
  stateless HMAC). Login sigue siendo opt-in: sin 2FA, todo igual.
- **Auditoría inmutable** — acciones de admin (alta/baja/rol/reseteo de
  usuarios, proveedores/modelos/MCP alta/edición/borrado, borrado de agentes,
  toggle de registro) en `/audit` (web) y `GET /api/v1/audit`.
- **Telemetría de uso de modelos** — `model_usage` por petición al proxy
  (tokens, estado, latencia), vista `/usage` y `GET /api/v1/usage`.
- **Panel de salud** — `/health`: agentes por estado en vivo, últimos fallos,
  salud de proveedores + API.
- **Backup y restore** — `taap:backup` / `taap:backup:restore` (bundle
  fechado con sha256 de DB + FS root + datos de agentes, restore destructivo
  con `--yes` y verificación previa).
- **Webhook de fallo** — POST saliente al transicionar un agente a error
  (HMAC, retry acotado, solo admin).
- **Plantillas de agente** — `GET/POST/PUT/DELETE /api/v1/templates` +
  picker en creación (web y iOS).
- **Búsqueda y exportación del chat** — Full-text en Postgres (tsvector +
  ts_rank + GIN) / sqlite (LIKE); exportación md/json desde la web e iOS.
- **Visor de logs del agente** — API `GET /api/v1/agents/{id}/logs` +
  sección Logs en la ficha (web y iOS), ring buffer 50/200/500.
- **UI de la app iOS** — explorador de ficheros (`/files` en la app: navegación,
  subida, descarga, rename, borrado).
