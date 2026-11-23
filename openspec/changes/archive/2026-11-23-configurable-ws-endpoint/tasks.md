# Tasks

## 1. Router de Traefik para el host del websocket

- [x] 1.1 Añadir al servicio `orchestrator` de `deploy/compose.traefik.yaml` un router `taap-ws` (rule `Host(\`${FQDN_WS:-ws.taap.test}\`)`, entrypoint `websecure`, `tls=true`) y su servicio `taap-ws` (loadbalancer → puerto `8080`, esquema `http`), siguiendo el patrón del router `lamb` existente (el upgrade de websocket lo gestiona Traefik de forma nativa; la etiqueta `transport.websockets` no existe en v3 y haría rechazar el contenedor). Verificación: `docker compose -f deploy/compose.yaml -f deploy/compose.traefik.yaml config` renderiza los labels y `make up` deja el contenedor healthy sin errores de config en `docker compose ... logs traefik`
- [x] 1.2 Parametrizar el objetivo `certs` del `Makefile` para aceptar dominios (`make certs DOMAINS="…"`), conservando el defecto `taap.test *.taap.test`. Verificación: `make certs DOMAINS='taap.itlent.eus ws.taap.itlent.eus'` genera `deploy/traefik/certs/cert.crt` con esos dos nombres en `subjectAltName` (`openssl x509 -in deploy/traefik/certs/cert.crt -noout -ext subjectAltName`) y `make certs` sin argumentos vuelve a generar el certificado local
- [x] 1.3 Fijar la red upstream en el orquestador con la etiqueta `traefik.docker.network=taap-internal` en `deploy/compose.traefik.yaml` (el contenedor va en taap-egress + taap-internal; sin la etiqueta y sin puertos publicados, Traefik elige al azar la primera IP del map de redes y puede apuntar a taap-egress, inalcanzable, → 504 intermitente en taap.test). Verificación: con el orquestador recreado sin puertos publicados, los upstreams `taap-lamb`/`taap-ws` (API de Traefik) apuntan siempre a la IP taap-internal y `https://taap.test/up` responde 200 tras varias recreaciones

## 2. Endpoint público por variables de entorno

- [x] 2.1 Añadir a `deploy/env.example` las variables `FQDN_TAAP=taap.test`, `FQDN_WS=ws.taap.test` y el bloque público `TAAP_PUBLIC_HOST=ws.taap.test`, `TAAP_PUBLIC_SCHEME=https`, `TAAP_REVERB_PUBLIC_PORT=443`, con comentarios: producción = `FQDN_TAAP=taap.itlent.eus` / `FQDN_WS=ws.taap.itlent.eus` / `TAAP_PUBLIC_HOST=ws.taap.itlent.eus` y alternativa LAN = host con la IP del host + esquema `http` + puerto `TAAP_WS_PORT`. Verificación: `grep -E 'FQDN_|TAAP_PUBLIC|TAAP_REVERB_PUBLIC' deploy/env.example` muestra las cinco variables con defectos locales
- [x] 2.2 Actualizar `deploy/.env` (local, no commiteado) con el mismo bloque que 2.1: `FQDN_WS=ws.taap.test`, `TAAP_PUBLIC_HOST=ws.taap.test`, `TAAP_PUBLIC_SCHEME=https` y reemplazar `TAAP_REVERB_PUBLIC_PORT=8081` por `443` (mantener `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` internos intactos). Verificación: tras `make up`, `curl -sk -H "Authorization: Bearer <token>" https://taap.test/api/v1/reverb-config` devuelve `{"config":{"host":"ws.taap.test","port":443,"scheme":"https",…}}`
- [x] 2.3 Actualizar `docs/deployment.md`: sección "Endpoints públicos" (qué son `FQDN_TAAP`/`FQDN_WS`/`TAAP_PUBLIC_*`, cómo repuntemar a `taap.itlent.eus`/`ws.taap.itlent.eus` y regenerar el certificado), corregir la sección App iOS (el WSS se obtiene de `reverb-config`, no se deriva del base URL) y la fila "WSS no conecta" de solución de problemas (ahora `wss://ws.taap.test`: comprobar DNS, certificado y `TAAP_PUBLIC_*`; alternativa LAN). Verificación: las variables y FQDN documentados coinciden uno a uno con `deploy/env.example`

## 3. Verificación de extremo a extremo

- [x] 3.1 `make up` con los cambios aplicados y `make ps`: orquestador y traefik healthy, sin errores de carga de config en los logs de traefik
- [x] 3.2 Handshake del websocket por TLS: `curl -skI -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" "https://ws.taap.test/app/<REVERB_APP_KEY>"` responde `101` (o el error de app de Reverb, nunca el 404 por defecto de Traefik) y un cliente real (pusher-js en el navegador) alcanza `pusher:connection_established`
- [x] 3.3 Streaming en web: desde `https://taap.test` abrir el chat de un agente con trabajo activo y comprobar en la consola/pestaña de red del navegador la conexión a `wss://ws.taap.test:443/app/…` y que los eventos (estado/actividad) llegan en vivo
- [x] 3.4 Streaming en iOS: con la app apuntando al orquestador, comprobar en `taap-ws.log` (DEBUG) o por estado de conexión que la socket se establece a `wss://ws.taap.test` y recibe `pusher:subscription_succeeded` + eventos
- [x] 3.5 Regresión del orquestador: `curl -s https://taap.test/up` responde 200 y el login + dashboard web funcionan como antes (el router `lamb` no ha cambiado)
- [x] 3.6 `cd orchestrator && php artisan test` y `make lint` sin fallos (la pila no ha cambiado código PHP/JS, solo despliegue; guarda contra regresiones)
- [x] 3.7 Determinismo del upstream sin puertos publicados: comentar temporalmente el bloque `ports` del orquestador, `make up` × 3 recreaciones y comprobar tras cada una que `https://taap.test/up` → 200 y el upstream de `taap-lamb`/`taap-ws` es la IP taap-internal (API de Traefik); al terminar, restaurar los `ports` y verificar de nuevo 200 + handshake WSS

## Workflow follow-up

- Tras la verificación, proponer el archivado del cambio (`openspec archive`) y sincronizar la delta en `openspec/specs/agent-chat/spec.md` si procede.
