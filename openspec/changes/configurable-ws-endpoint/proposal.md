# Proposal

## Why

El servidor de eventos por websocket (Reverb, :8080 dentro del orquestador) no es alcanzable desde ningún cliente externo: la configuración pública (`config('taap.public')`) resuelve a `ws://127.0.0.1:8081` (esquema http, host interno), que solo funcionaría en la propia máquina del orquestador —y en el navegador ni siquiera, porque es contenido mixto desde una página servida por https. No hay ningún router de Traefik para el host del websocket, así que al desplegar con `taap.test` el orquestador funciona pero el streaming de eventos (actividad del agente, aprobaciones, coherencia multi-dispositivo) no conecta. Además, el endpoint no es reconfigurable por despliegue: al publicar en producción (frontend en `taap.itlent.eus`, websocket en `ws.taap.itlent.eus`) habría que tocar código.

## What Changes

- Nuevo router de Traefik `ws` sobre el orquestador (puerto 8080 interno, Reverb) para el FQDN del websocket, configurable con `FQDN_WS` (defecto `ws.taap.test`): entrada `websecure` (TLS), transporte websockets, sin cambiar cómo Reverb se ejecuta dentro del contenedor.
- Endpoint público del WSS completamente por variables de entorno sin tocar código: `TAAP_PUBLIC_HOST`, `TAAP_PUBLIC_SCHEME` (defecto de despliegue `https`) y `TAAP_REVERB_PUBLIC_PORT` (443, ya que va por Traefik). Se documentan en `deploy/env.example` y `docs/deployment.md`, incluyendo los valores de producción (`FQDN_TAAP=taap.itlent.eus`, `FQDN_WS=ws.taap.itlent.eus`, `TAAP_PUBLIC_HOST=ws.taap.itlent.eus`).
- `deploy/env.example` incorpora los FQDN (`FQDN_TAAP`, `FQDN_WS`) y el triple público con defectos locales; el `.env` local queda apuntando a `wss://ws.taap.test` (443) para que el websocket funcione ya en el entorno de `taap.test`.
- `make certs` acepta dominios como argumento (defecto `taap.test *.taap.test`) para generar el certificado del despliegue de producción sin editar el Makefile.
- Sin cambios de código en clientes: la web (pusher-js vía `data-reverb` del layout) y la app iOS (`GET /api/v1/reverb-config`) ya consumen host/esquema/puerto de la configuración pública. No es BREAKING: la API `reverb-config` y el atributo `data-reverb` mantienen su forma (host, port, scheme); solo cambian los valores entregados.
- El orquestador sigue sirviéndose en `FQDN_TAAP` (hoy `taap.test`): su router no cambia.

## Capabilities

### New Capabilities

(ninguna)

### Modified Capabilities

- `agent-chat`: nuevo requisito "Configuración del endpoint de streaming": el endpoint WSS que reciben los clientes (web y móvil) SHALL ser configurable por despliegue (host, esquema TLS y puerto) sin cambios de código, servido por el propio orquestador, y los clientes SHALL poder establecer la conexión TLS (`wss`) y recibir los eventos del agente a través de él.

## Impact

| Aspecto | Detalle |
|---------|---------|
| **Código afectado** | `deploy/compose.traefik.yaml` (router + servicio `ws`), `deploy/env.example` (FQDN + `TAAP_PUBLIC_*`), `Makefile` (objetivo `certs` parametrizado), `docs/deployment.md` (documentación de endpoints públicos y repunteo a producción) |
| **Sin cambios** | `orchestrator/*` (config `taap.public`, layout, `reverb-config` y clientes ya son orientados a entorno), `config/reverb.php`, `config/broadcasting.php`, `deploy/entrypoint.sh`, `deploy/compose.yaml`, código Swift/JS de clientes |
| **Efecto funcional** | Desde `https://taap.test` (y luego `https://taap.itlent.eus`) el navegador y la app iOS abren `wss://ws.taap.test` (443) vía Traefik y reciben el streaming; en producción basta con variables de entorno y el certificado, sin tocar código |
