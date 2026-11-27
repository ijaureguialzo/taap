# Proposal: Seguimiento de uso de modelos (tokens, requests, errores)

## Why
El Model Proxy registra cada petición y parsea el `usage` de cada respuesta (`ModelProxyController`), y el runtime Codex lo loguea (`CodexAdapter`), pero no hay persistencia ni agregado: no se puede saber cuántos tokens ha quemado un agente, cuántos requests ha generado un proveedor ni qué latencia hay en la cadena de inferencia. Sin uso observable no hay forma de controlar costes, depurar fugas ni decidir si ampliar o reducir la flota.

## What Changes
- Persistencia por petición a través del proxy: tabla `model_usage` (agente, proveedor, modelo, tokens entrada/salida, código de estado, latencia, timestamp), escrita en el mismo ciclo de respuesta con datos ya en mano.
- Vista admin de uso: agregados por agente y por proveedor (requests, tokens, errores, latencia) para planear costes y detectar un agente que se desvíe.
- Endpoint API v1 de uso (admin) con reproducción por periodo.
- Persistencia best-effort: si la escritura falla, la respuesta se sirve igualmente (no empeorar el camino feliz por telemetría).

**Out of scope:** enforcement de cuotas de coste/tokens (capa posterior, con límites por usuario/agente); contabilidad chunk a chunk de streams; facturación monetaria (no hay proveedor de facturación).

## Capabilities

### New Capabilities
*(ninguna — se extiende el proxy existente)*

### Modified Capabilities
- `model-management`: el proxy adquiere trazabilidad durable (tabla de uso + agregados + endpoint de lectura) además de servir requests. Las políticas de salud/compatibilidad no cambian.

## Impact
- Nueva tabla + servicio de persistencia; `ModelProxyController` y `CodexAdapter` ganan una escritura.
- Nueva vista Livewire admin (o sección dentro de Providers/Models) + API v1 admin.
- Sin cambios de contrato público; cero breaking changes.
