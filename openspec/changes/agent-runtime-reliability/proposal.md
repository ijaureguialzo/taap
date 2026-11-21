# Cambio: agent-runtime-reliability

## Why

Dos fallos reales observados en el despliegue compose (8011) que rompen la
confianza en el estado del sistema:

1. **Falso negativo de salud de proveedor.** El gateway "splash"
   (llama.cpp en `host.docker.internal:8000`) funciona — los agentes chatan
   a través del proxy — pero el catálogo lo marca «No sano». El probe de
   salud (`Providers/Index.php`) envía el `Host` literal
   (`host.docker.internal:8000`) y el gateway lo rechaza con 403
   (`--allowed-host`); el `ModelProxyController` ya resuelve esto mapeando
   `host.docker.internal → 127.0.0.1` en el header Host. La comprobación de
   salud mide con otra convención que el tráfico real: un gateway sano puede
   marcarse no sano, y los modelos de un proveedor sano aparecen atenuados
   en los selectores.

2. **Agente atascado offline por colisión de IP.** El agente "Claudia"
   (runtime claude) no arranca: `allocateIp()` asignó `10.163.3.5` tanto a
   ella como a "Pi" (solo excluye IPs en uso por contenedores *apegados* a
   la red; las reservas de la tabla `agents` y los contenedores `Created`
   no cuentan). El contenedor de Claudia quedó `Created` con
   `Address already in use` (exit 128) y `reconcile()` no maneja el estado
   `created` — nada lo re-arranca, el agente queda offline para siempre a
   pesar de `auto_restart`.

## What changes

- **model-management**: el sondeo de salud usa la misma identidad upstream
  que el proxy (mapeo `host.docker.internal → 127.0.0.1` en el header Host),
  extrayendo la convención a un helper compartido por `probe()` y
  `ModelProxyController`. Un gateway que sirve al tráfico de agentes no se
  marca «No sano» por el alias de red del contenedor.
- **agent-lifecycle**: la asignación de IP fija trata la tabla `agents`
  como fuente de verdad de reservas (además del uso en vivo de la red) y el
  reconciliador cubre el estado `created` (contenedor creado sin arrancar)
  con el mismo criterio que `exited`: re-arrancar si el agente debe estar
  vivo.

Cambio puramente backend (orquestador). No toca API pública, web ni iOS.

## Impact

- **Affected specs**: `model-management` (ADDED: identidad del sondeo de
  salud), `agent-lifecycle` (ADDED: direccionamiento de red único y
  estable; MODIFIED: disponibilidad 24/7)
- **Affected code**: `app/Livewire/Providers/Index.php` (probe),
  `app/Http/Controllers/Proxy/ModelProxyController.php` (uso del helper),
  `app/Support/DockerClient.php` (`allocateIp`/`usedIps`),
  `app/Agents/AgentManager.php` (reconcile + provision), tests de
  integración de runtime.
- **Operativo**: permite re-provisionar "Claudia" y re-comprobar "splash"
  en el despliegue existente sin tocar los gateways (que corren fuera de
  nuestro control: 8000 llama.cpp, 11434 ollama).
