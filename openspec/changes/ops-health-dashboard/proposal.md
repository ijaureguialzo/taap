# Proposal: Panel de salud operativo (estado de flotas y proveedores)

## Why
La salud de proveedores (`status` / `last_checked_at`, `isUnhealthy()`) ya se persiste, y el estado de cada agente se propaga en tiempo real por chat — pero no existe ningún **vista general** para el operador: no hay forma rápida de ver cuántos agentes están en cada estado, qué agentes fallaron recientemente ni qué proveedores están no sanos. El admin hoy tiene que visitar cada agente, cada proveedor o los logs del host.

## What Changes
- Página `/health` (o sección del admin) que agrega la información ya disponible: agentes por estado (vivo vía canal/WebSocket), últimos errores de agente (con detalle) y salud de proveedores (sano/no sano + fecha de última comprobación).
- Endpoint API v1 de salud del sistema (admin) que la web/iOS pueden consultar.
- Actualización en vivo por los canales existentes (sin polling fuerte).

**Out of scope:** endpoint de métricas (Prometheus, etc.); alertas externas (ver `agent-failure-notifications`); historial de salud por proveedor (solo el estado actual).

## Capabilities

### New Capabilities
- `ops-health`: vista agregada del estado operativo de la flota (agentes por estado, últimas fallas, salud de proveedores) sobre datos ya persistidos.

### Modified Capabilities
*(ninguna — extiende la lectura de información que ya existe)*

## Impact
- Nueva página Livewire + endpoint API v1 (`role:admin`).
- Sin cambios de esquema: lee `agents`, health de proveedores y agent events ya existentes.
- La vista en sí es informativa; la acción sigue siendo por agente (start/stop/restart).
