# Design

## Context

Ver proposal.md. Estado actual relevante:

- **Reverb** corre dentro del contenedor del orquestador (`deploy/entrypoint.sh`: `php artisan reverb:start --host=0.0.0.0 --port=8080`), HTTP plano; solo expuesto al host vía `TAAP_WS_PORT`. El broadcaster interno (proceso web y worker de cola) lo alcanza por `127.0.0.1:8080` / `orchestrator:8080` según contenedor (env `REVERB_*`), sin TLS.
- **Clientes**: la web inyecta `data-reverb` en el layout (host/esquema/puerto de `config('taap.public')` + credenciales de `config('broadcasting...')`) y pusher-js conecta con `forceTLS` según esquema; la app iOS pide `GET /api/v1/reverb-config` y arma `ws[s]://host:port/app/{key}`. Ambos ya son 100% orientados a la configuración pública.
- **`config('taap.public')`** (`orchestrator/config/taap.php`): `host` = `TAAP_PUBLIC_HOST` (fallback `REVERB_HOST` → `127.0.0.1`), `port` = `TAAP_REVERB_PUBLIC_PORT` (fallback `REVERB_PORT` → 8080), `scheme` = `TAAP_PUBLIC_SCHEME` (fallback `REVERB_SCHEME` → `http`). En `deploy/.env` solo se define hoy `TAAP_REVERB_PUBLIC_PORT=8081` → los clientes remotos reciben `ws://127.0.0.1:8081`, inalcanzable (y contenido mixto en el navegador).
- **Traefik 3** (`deploy/compose.traefik.yaml` + `deploy/traefik/traefik.yml`): entrypoints `web` (80, redirige a `websecure`) y `websecure` (443), un único certificado por defecto (SAN `taap.test *.taap.test`), provider docker con `exposedByDefault: false`. Routers actuales: dashboard (traefik) y `lamb` → orquestador:8000 para `FQDN_TAAP` (`taap.test`). No hay router para el host del websocket.
- La app Reverb tiene `allowed_origins: ['*']`, así que el host del origen no limita la conexión.

## Goals / Non-Goals

**Goals:**

- Que el websocket funcione en `https://ws.taap.test` (TLS, puerto 443) en el despliegue actual, consumido por la web y la app iOS sin cambios de código en clientes.
- Que el endpoint público (host/esquema/puerto) y el FQDN del router sean reconfigurables por variables de entorno, de modo que la publicación en `taap.itlent.eus` / `ws.taap.itlent.eus` no exija tocar código.
- Que el orquestador siga sirviéndose en `FQDN_TAAP` (`taap.test`) exactamente como hoy.

**Non-Goals:**

- No escalar Reverb a varios nodos (el `scaling` por Redis ya existe en config; no lo tocamos).
- No cambiar el protocolo, los canales ni la autenticación de broadcasting.
- No mover Reverb a un contenedor propio ni gestionar certificados dentro de la app.
- No tocar el broadcaster interno (sigue hablando http plano dentro de la red).

## Decisions

### D1. Router + servicio propios de Traefik para el host del websocket, sobre el contenedor del orquestador

Añadimos al servicio `orchestrator` de `deploy/compose.traefik.yaml` un segundo router/servicio (patrón idéntico al existente `lamb`):

- router `taap-ws`: rule `Host(\`${FQDN_WS:-ws.taap.test}\`)`, entrypoint `websecure`, `tls=true` y `service=taap-ws` explícito (con dos servicios en el contenedor, el auto-linking de Traefik falla para los dos routers; `lamb` recibe el suyo también). El upgrade de websocket lo gestiona Traefik de forma nativa (no existe la opción `transport.websockets` en v3: la etiqueta `traefik.http.routers.<x>.transport` es inválida y hace que el provider docker rechace el contenedor entero — verificado con Traefik 3.7.19).
- servicio `taap-ws`: loadbalancer → puerto `8080` (Reverb) con esquema `http` interno.

**Por qué**: Reverb sigue siendo un servidor HTTP plano dentro del contenedor; Traefik termina el TLS. No hay que montar certificados en el orquestador, no se toca `config/reverb.php` ni `entrypoint.sh`, y el broadcaster interno (http) no se ve afectado. El certificado comodín `*.taap.test` ya cubre `ws.taap.test` sin cambios en `tls.yml`.
**Alternativas descartadas**:
1. TLS dentro de Reverb (montar el certificado en el contenedor, `REVERB_SCHEME=https` + opciones TLS): duplicaría la gestión del certificado en dos lugares y obligaría a un esquema distinto entre el broadcaster interno y el público.
2. Segundo entrypoint de Traefik sin TLS (:8080 directo): el navegador bloquearía `ws://` desde la página `https://taap.test` (contenido mixto) — es justo el fallo actual.
3. Contenedor Reverb separado: no aporta nada ahora (el scaling multi-nodo ya se resuelve por config) y añadiría un servicio más al compose.

### D2. El endpoint público se configura con el triple `TAAP_PUBLIC_HOST` / `TAAP_PUBLIC_SCHEME` / `TAAP_REVERB_PUBLIC_PORT`

La forma del contrato con los clientes se **mantiene** (host, scheme, port; el layout ya los emite y `reverb-config` ya los sirve). Solo cambian los valores por defecto del despliegue:

- `deploy/env.example` añade (con comentario explicando los dos escenarios): `FQDN_TAAP=taap.test`, `FQDN_WS=ws.taap.test`, `TAAP_PUBLIC_HOST=ws.taap.test`, `TAAP_PUBLIC_SCHEME=https`, `TAAP_REVERB_PUBLIC_PORT=443`, y los valores de producción como ejemplo comentado (`FQDN_TAAP=taap.itlent.eus`, `FQDN_WS=ws.taap.itlent.eus`, `TAAP_PUBLIC_HOST=ws.taap.itlent.eus`).
- `deploy/.env` (local) adopta los mismos valores del bloque proxy (reemplaza el `TAAP_REVERB_PUBLIC_PORT=8081` heredado).
- El puerto se fija explícito a **443** en lugar de 0/omitido: pusher-js usa `cfg.port` para `wssPort` y el cliente iOS concatena `:port` en la URL; un 443 explícito es idéntico al defecto del esquema y evita ambigüedad en ambos.
- La cadena de fallback de `taap.php` (`TAAP_PUBLIC_*` → `REVERB_*` → `127.0.0.1:8080/http`) **no se toca**: sin proxy ni variables, el desarrollo local desnudo sigue funcionando igual.

**Alternativa descartada**: una variable URL única `TAAP_PUBLIC_WS_URL`. Cambiaría la forma de la API `reverb-config` y obligaría a tocar el cliente iOS y el layout; el triple ya existe y ya es configurable.

### D3. Cambio a producción = variables de entorno + certificado

Publicar en el dominio real no requiere cambio de código: en el `.env` del host de producción `FQDN_TAAP=taap.itlent.eus`, `FQDN_WS=ws.taap.itlent.eus`, `TAAP_PUBLIC_HOST=ws.taap.itlent.eus` (esquema `https` y puerto 443 quedan igual) y `make certs 'taap.itlent.eus ws.taap.itlent.eus'` (o comodín) para que el certificado cubra ambos hosts. `docs/deployment.md` documenta el bloque de variables y este paso.

### D4. `make certs` parametrizable

El objetivo `certs` del Makefile pasa a aceptar dominios como argumento (`make certs DOMAINS="taap.itlent.eus ws.taap.itlent.eus"`), conservando el defecto actual `taap.test *.taap.test`. `crear_certs.sh` ya recibía los dominios como `$1`; solo el Makefile no lo exponía.

### D5. Compatibilidad con orígenes y con el estado actual

`allowed_origins: ['*']` en `reverb.php` admite `taap.test` y `taap.itlent.eus` sin configuración por entorno (no se modifica). El router `lamb` (`FQDN_TAAP`) queda intacto: el orquestador sigue en `taap.test`.

### D6. Red upstream fijada con `traefik.docker.network`

El contenedor del orquestador va en dos redes (`taap-egress` + `taap-internal`) y Traefik solo comparte `taap-internal`. Con puertos publicados, Traefik usa el binding del host (determinista) y no mira la IP; **sin puertos publicados** elige la primera IP del map de redes del contenedor (orden aleatorio en Go) y a veces apunta a `taap-egress` (10.163.4.x, inalcanzable desde Traefik) → 504 intermitente en `taap.test`. La etiqueta `traefik.docker.network=taap-internal` fija la red de la que se toma la IP (verificado en el código `getIPAddress` de Traefik 3.7: con la etiqueta, `Networks[label].Addr`; el binding sigue teniendo prioridad si existe). Sin la etiqueta, el arranque de cada contenedor sería una moneda al aire.

## Risks / Trade-offs

- **[La etiqueta `transport.websockets` no existe en Traefik v3]** → comprobado con el binario 3.7.19 (`field not found, node: transport`: el provider docker descarta el contenedor y desaparecen hasta los routers existentes): el upgrade de websocket va por el manejo HTTP estándar de Traefik, sin etiqueta. Si un día se necesitara control fino del transporte, la opción correspondiente no existe en v3 y habría que evaluar otro mecanismo.
- **[iOS en LAN sin DNS de `taap.test`]** → un cliente apuntando a `http://192.168.x.y:8011` recibiría el endpoint público `wss://ws.taap.test`, que falla si el móvil no resuelve el dominio. Es el trade-off inherente a un único endpoint público por despliegue; se mitiga documentando en `docs/deployment.md` cómo apuntar el triple a la LAN (host = IP del host, esquema `http`, puerto `TAAP_WS_PORT`) para pruebas sin DNS.
- **[Recrear contenedor para que surta efecto]** → las variables llegan por `env_file` en creación de contenedor: tras editar `deploy/.env` hay que `docker compose … up -d orchestrator` (o `make up`). Traefik detecta el router nuevo sin tocar el contenedor de Traefik. Se documenta en la sección de actualización.
- **[El puerto 8081 expuesto queda como comodín de desarrollo]** → `ws://` directo desde una página https sigue siendo contenido mixto; se mantiene solo para depurar / iOS en LAN, documentado.
- **[Certificado auto-firmado (mkcert)]** → la app iOS fuera de la red del host podría rechazar la CA de mkcert; es una cuestión operativa del despliegue (instalar la CA o firmar con CA real), no un cambio de código; se anota en la documentación.

## Migration Plan

1. Aplicar cambios: `deploy/compose.traefik.yaml` (router/servicio `ws`), `deploy/env.example`, `Makefile`, `docs/deployment.md`.
2. Actualizar `deploy/.env` local (triple público + `FQDN_WS`).
3. `make up` → se recrea el orquestador con el label nuevo; Traefik expone `taap-ws`.
4. Verificar en el host: handshake WSS sobre `https://ws.taap.test`, `reverb-config` devolviendo el nuevo endpoint, streaming en web e iOS (ver tasks).
5. **Rollback**: revertir el triple público a `127.0.0.1`/`http`/`8081` y recrear; el router `taap-ws` queda inerte (nadie conecta) y es eliminable con `git revert` + `make up`.
6. Producción (fuera de este cambio, documentado): host nuevo → `.env` con FQDN/`TAAP_PUBLIC_HOST` de itlent, `make certs` para sus dominios, `make build && make up`.

## Open Questions

Ninguno bloqueante. La firma del certificado de producción (mkcert vs. CA pública) y el DNS de `*.itlent.eus` son decisiones operativas del despliegue que no alteran el diseño.
