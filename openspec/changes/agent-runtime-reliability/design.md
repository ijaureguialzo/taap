# Design: agent-runtime-reliability

## Context

El orquestador corre en Docker (compose). Los gateways de modelos corren en
el **host** (llama.cpp :8000, ollama :11434) y se alcanzan desde los
contenedores por `host.docker.internal`. Dos asimetrías produjeron los
fallos: el probe de salud no replica la convención Host del proxy, y la
asignación de IPs confía en el estado en vivo de la red en lugar de las
reservas.

## Decisiones

### D1 — Identidad upstream compartida entre probe y proxy

Nuevo helper `App\Support\UpstreamIdentity::hostFor(string $baseUrl): ?string`:
parsea el `base_url` y devuelve el valor del header `Host` a presentar —
`host[:puerto]`, con la excepción `host.docker.internal → 127.0.0.1` (el
upstream se ve a sí mismo por loopback; algunos validadores de Host lo
exigen). `null` = el cliente decide (sin puerto/host anómalos).

- `ModelProxyController` usa el helper (sustituye su mapeo inline; el
  comportamiento es idéntico, ahora hay un solo punto).
- `Providers/Index::probe()` aplica el mismo header
  (`Http::withHeaders(['Host' => …])`) que el proxy.

Alternativas:
- *Arrancar llama.cpp con `--allowed-host host.docker.internal`*: descarta —
  los gateways corren fuera de nuestro control (ya arrancados, de otros
  proyectos) y el fix no sería portable a cualquier gateway con validación
  de Host (uvicorn, etc.).
- *Cambiario el `base_url` a 127.0.0.1*: descarta — 127.0.0.1 desde un
  contenedor es el propio contenedor; solo es válido como header, no como
  destino.

### D2 — La asignación de IP trata la DB como fuente de verdad de reservas

`DockerClient::allocateIp(string $network, array $reserved = []): ?string`
excluye `usedIps($network)` (contenedores apegados, estado actual) **y**
`$reserved` (direcciones ya asignadas en `agents.ip`, incluidas las de
contenedores `Created` que no aparecen en la red). `AgentManager` pasa el
conjunto `Agent::whereNotNull('ip')->pluck('ip')` al llamar. La heurística
de barrido (.2 → .251, tope 250) se mantiene.

`DockerClient` sigue siendo capa de transporte: el conjunto reservado se
recibe por parámetro, no consulta modelos él.

### D3 — El reconciliador cubre el estado `created`

En `AgentManager::reconcile()`, el estado `created` (contenedor existente
sin arrancar) se trata como `exited`: agente no `Stopped` + `auto_restart`
→ `docker start` + estado `Starting`; excepción de Docker → estado `Error` +
log. El resto del reconcile no cambia.

Razón de `start` y no re-provision: el contenedor `created` ya tiene
mounts/env de su provision; `start` es la recuperación mínima. Si el fallo
persiste (p. ej. IP colisionada), el agente pasa a `Error` visible y el log
lo nombra; la prevención es D2. Para el caso vivo (Claudia): operación de
curación — borrar su contenedor y re-provisionar (re-alterna una IP libre
con D2), no migración de datos.

### D4 — Verificación

- Unit `UpstreamIdentity`: mapeo host.docker.internal/otros host/puerto.
- Unit `allocateIp` con `DockerClient` simulado: (a) IP ocupada en vivo se
  salta, (b) IP reservada en DB se salta, (c) el caso exacto Claudia/Pi
  (.5 en la DB para el agente en curso no se reasigna).
- Feature de salud de proveedor: upstream stub (el patrón de integración
  existente con puerto efímero) que 403 con Host no permitido y 200 con
  `127.0.0.1` → el probe con la base_url `host.docker.internal` debe dar
  «sano».
- Verificación en vivo (8011): re-comprobar splash → «Sano»; curar a
  Claudia (re-provision) → contenedor corriendo, agente `idle`; los modelos
  de splash dejan de estar atenuados en el selector.
