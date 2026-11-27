# ops-health Specification

## Purpose

Panel operativo del orquestador: una vista única para el administrador que agrega el estado vivo de la flota de agentes (por estado), las últimas fallas y la salud de los proveedores de modelos — todo a partir de datos ya persistidos — sin exponer credenciales ni datos privados de usuarios.

## Requirements

### Requirement: Agents by status
El sistema SHALL presentar, para el administrador, un desglose de agentes por estado vivo (starting, idle, working, waiting-approval, error, stopped, offline), actualizado en tiempo real sin recarga manual.

#### Scenario: Live counts by status
- **WHEN** el admin abre el panel de salud
- **THEN** la vista muestra el número de agentes en cada estado, y cuando un agente cambia de estado la lista se actualiza en vivo

### Requirement: Recent agent failures
El sistema SHALL mostrar las últimas N fallas de agentes (con agente, runtime, causa y timestamp) en la vista del panel, para que el admin pueda actuar sin abrir cada agente.

#### Scenario: Failure visible in the panel
- **WHEN** un agente transiciona a error
- **THEN** la falla aparece en el panel de salud con el nombre del agente, la causa y la hora

### Requirement: Provider health in the panel
El sistema SHALL mostrar, en el panel de salud, la salud de cada proveedor de modelos (sano/no sano, última comprobación) — los datos persistidos por la comprobación de salud existente.

#### Scenario: Provider unhealthy visible
- **WHEN** un proveedor es marcado como no sano por la comprobación de salud
- **THEN** el panel del admin lo muestra como no sano con la fecha de última comprobación

### Requirement: Health API v1
El sistema SHALL exponer `GET /api/v1/health/summary` (admin) con la forma `{ agents_by_status, recent_failures, providers }`, accesible solo a admins, para que la web y la app iOS consulten la misma información.

#### Scenario: Health summary by API
- **WHEN** un admin llama a `GET /api/v1/health/summary`
- **THEN** la API responde con la forma esperada, restringida a admins (403 para no admins)
