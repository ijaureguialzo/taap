# Release Notes — TAAP v1.3

**The Agentic AI Playground 1.3** — modernización de la app iOS con Liquid
Glass (iOS 26) y una ronda completa de mejoras: seguridad (2FA/passkeys),
plataforma operativa (auditoría, telemetría, salud, backup/restore, webhooks)
y producto (plantillas, búsqueda/export, visor de logs, explorador de
ficheros en la app iOS).

## App iOS — Liquid Glass y archivo

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
  para texto sobre glass — contraste AA garantizado; la app aplica Reduce
  Motion e Increase Contrast del sistema de forma automática.
- **Explorador de ficheros**: la app iOS recibe la superficie «Archivos»
  (navegación por la raíz del usuario, creación de carpetas, subida,
  descarga de ficheros y `.zip` de carpetas, renombrado, borrado con
  confirmación) — mismo contrato que la web, vía la misma API v1
  (`/api/v1/files`). NUEVA superficie.
- **Chat**: búsqueda de mensajes (filtro local sobre la sesión) y
  exportación de historial (`.md` / `.json` vía share sheet).

## Seguridad

- **2FA (TOTP) + passkeys, opt-in**: vía Fortify. Perfil web (habilitar,
  confirmar, regenerar recovery codes, deshabilitar; gestionar passkeys en
  la misma sección), API v1 (`GET/POST/DELETE /api/v1/auth/profile/two-factor`,
  `/passkeys`), login web con challenge de Fortify, login iOS con challenge
  stateless (HMAC) sobre la API v1. Las columnas `two_factor_*` y la tabla
  `passkeys` ya existían desde v1.0; la v1.3 las cablea por primera vez.
  El 2FA no es obligatorio por rol en esta versión (los admins lo optan
  para sí mismos; la enforcement viene en una fase posterior).

## Plataforma operativa

- **Auditoría inmutable**: toda acción de admin aplicable (alta/edición/baja
  de usuarios, reset de contraseña, proveedores, modelos, MCP, borrado de
  agentes, toggle de registro) se registra en `audit_log` en la misma
  transacción de la operación. `/audit` (web) con filtros por actor,
  acción, objetivo y rango de fechas; `GET /api/v1/audit` (admin).
- **Telemetría de uso de modelos**: `model_usage` por petición al proxy
  (agente, proveedor, modelo, tokens entrada/salida, estado, latencia),
  best-effort — la telemetría nunca rompe la inferencia. `/usage` (web) y
  `GET /api/v1/usage` con agregados por agente/proveedor.
- **Panel de salud**: `/health` (web) con agentes por estado en vivo,
  últimos fallos (`agent_events`) y salud de proveedores (con «sin
  comprobar» cuando no hay última comprobación). `GET /api/v1/health/summary`.
- **Backup y restore**: `taap:backup` genera un bundle fechado (DB dump
  Postgres/sqlite, tar de FS root, tar de datos de agentes, manifest con
  sha256 por parte). `taap:backup:restore` verifica integridad ANTES de
  escribir —aborta en cualquier fallo—, y borra y sobrescribe los datos
  existentes (requiere `--yes`; los secretos `.env` quedan fuera — reponer
  manualmente, documentado en `docs/deployment.md`).
- **Webhook de fallo**: al transicionar un agente a «error», si está
  configurado, se envía POST al webhook (HMAC-SHA256, payload estructurado,
  retry acotado 10/30/60s, 4xx no reintenta). Configurable vía
  `PUT /api/v1/settings` (`failure_webhook_url`, `failure_webhook_secret`).
  Best-effort: nunca bloquea la transición.

## Producto

- **Plantillas de agente**: nombre, runtime, modelo sugerido, MCP precargado,
  Internet y límites — `GET/POST/PUT/DELETE /api/v1/templates` (owner-only;
  admin lista con `?all=true`). Picker «Plantilla» en la creación (web y
  iOS). Valida runtime (registro), modelo (compatible) y nombre único por
  usuario.
- **Búsqueda y exportación del chat**: full-text sobre mensajes del chat —
  `tsvector` + `ts_rank` + GIN en Postgres (`body_tsvector`), LIKE en
  sqlite — con paginación y ranking. API v1
  (`GET /api/v1/agents/{id}/messages/search`) y la web (barrita con debounce
  + resultados enlazables a `#msg-{id}`). Exportación completa a Markdown
  o JSON vía `GET .../messages/export`.
- **Visor de logs del agente**: `GET /api/v1/agents/{id}/logs?tail={50,200,500}`
  — `{status, lines}` — y sección Logs en la ficha (web y iOS), con
  selector de tamaño, refresco y copia al portapapeles. Solo lectura,
  sin retención (docker logs es el único origen).

## CI y calidad

- **Job `images`**: las 5 imágenes de agente (`agent-pi`, `agent-hermes`,
  `agent-claude`, `agent-codex`, `agent-opencode`) —la v1.1 había añadido
  tres sin ampliar CI—.
- **Job `ios`**: tests unitarios de la app iOS en un runner macOS antes de
  `main` (`brew install xcodegen` + `./regenerate.sh` + `xcodebuild test`).
- **ApiClientTests** (5 tests nuevos) + full iOS unit coverage.

## Despliegue y operaciones

- Nuevas migraciones al desplegar: `audit_log`, `model_usage`,
  `agent_templates`, `chat_messages.body_tsvector` (Postgres). Todas son
  aditivas; ninguna modifica ni borra datos existentes (la columna tsvector
  se genera; los datos de uso/auditoría empiezan en el merge).
- Nuevos comandos Artisan: `taap:backup`, `taap:backup:restore`.
- Nuevas rutas web (rol admin): `/audit`, `/usage`, `/health`.
- Nuevas rutas API v1 (admin): `/api/v1/audit`, `/api/v1/usage`,
  `/api/v1/health/summary`, `/api/v1/templates`, `/api/v1/agents/{id}/logs`,
  `/api/v1/agents/{id}/messages/search`, `/api/v1/agents/{id}/messages/export`.
- Configuración nueva de webhooks: `failure_webhook_url` /
  `failure_webhook_secret` en `settings` (admin, vía API v1). Default:
  desactivado (URL ausente).
- La iOS requiere Xcode 26+ y un despliegue en simulador/dispositivo con
  iOS 26+ (deployment target). Los tests de la app siguen siendo opt-in
  (XcodeGen + `xcodebuild test`).

## Notas para actualizar desde v1.2

1. `git pull && make build && make images` — reconstruye orquestador +
   las imágenes de agente (las tres nuevas con sus warmups; `agent-opencode`
   requiere acceso a npm en el build).
2. `docker compose … up -d --force-recreate orchestrator queue` — aplica las
   migraciones aditivas.
3. Revisar las notas de abajo:
   - **2FA/passkeys** — sin cambios de comportamiento en login (opt-in).
     El perfil ya permite habilitar el 2FA (QR + confirmación + recovery
     codes visibles una sola vez).
   - **App iOS** — actualización obligatoria a iOS 26 para reinstalar; la
     app no se instala en dispositivos anteriores.
   - **Webhook de fallo** — desactivado por defecto. Para activarlo, definir
     `failure_webhook_url` y (opcionalmente) `failure_webhook_secret` vía
     `PUT /api/v1/settings`.
   - **Auditoría/telemetría/salud** — ninguna acción manual; los datos
     empiezan a acumularse en el merge. Consulta vía `/health` y `/usage`.
   - **Plantillas de agente** — el flujo de creación ofrece el picker
     (vacío si el usuario no tiene plantillas).
   - **Búsqueda/logs/exportación** — no requieren acción manual; el
     `tsvector` se genera en el primer merge.

## Verificación

- `php artisan test` (orquestador): 576 pasan; los 2 fallos de
  `ThemeAttributeTest` son preexistentes (reproducidos en `e7eeb02`).
- iOS unit tests: 40/40 en verde (`xcodebuild test -only-testing:TaapUnitTests`).
- Building: las 4 imágenes de agentes con warmup (`agent-pi`, `agent-hermes`,
  `agent-claude`, `agent-codex`) y la verificación en simulador iOS 27.2
  (build verde, login limpio).
- `openspec list` → 0 cambios activos; todos los deltas sincronizados.
