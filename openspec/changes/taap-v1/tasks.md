# Tasks

## 1. Scaffolding y despliegue

- [x] 1.1 Crear el layout del monorepo (`orchestrator/`, `agents/pi/`, `agents/hermes/`, `mobile/ios/`, `deploy/`, `docs/`) con un README que explique la arquitectura y verifique que `openspec list` y las herramientas de cada subproyecto se ejecutan en sus directorios
- [x] 1.2 Escribir `deploy/compose.yaml` (orquestador + Postgres + proxy de egress, redes `taap-internal` sin default route y `taap-egress`, socket de Docker montado solo en el orquestador) y verificar con `docker compose config` y `docker compose up -d` que los contenedores arrancan en las redes correctas (un `curl` desde un agente de prueba no alcanza Internet si solo está en `taap-internal`)
- [x] 1.3 Añadir CI mínima (lint + test del orquestador en cada PR) y verificar que el job pasa con el esqueleto del repo
- [x] 1.4 Empaquetar el orquestador en imagen (`deploy/Dockerfile` multi-stage + entrypoint con reverb/scheduler/bridge/servidor) y verificar `docker compose down && up` desde cero: 3 servicios healthy, agente Pi creado/arrancado (idle), chat E2E con modelo local y broadcast Reverb por WSS (notas: bind mounts host en dev vía `TAAP_AGENT_DATA_HOST`, etiqueta `taap.instance` para aislar reconciliaciones que comparten el demonio, `Host` del upstream en el Model Proxy, `options` del conector reverb)

## 2. Orquestador: fundación (Laravel 13)

- [ ] 2.1 Crear la aplicación Laravel 13 con Livewire 4, Bootstrap 5 y Alpine (layout, página de inicio, navegación por áreas) y verificar que `php artisan serve` muestra la UI y que el lint pasa
- [ ] 2.2 Crear las migraciones del modelo de datos (users con role/language/active/max_agents_running, agents, agent_mounts, model_providers, models, mcp_servers, agent_mcp_servers, chat_messages, agent_events, agent_tokens, user_api_tokens) con factories y verificar con un test que afirma el esquema tras `migrate:fresh`
- [ ] 2.3 Implementar autenticación local (email+contraseña, argon2id), sesión de cookie, tokens API por usuario, roles admin/user con gates/policies y la UI de gestión de usuarios del admin y verificar con feature tests de login, denegación por rol y CRUD de usuarios
- [ ] 2.4 Implementar un cliente fino de la Docker Engine API (crear/arrancar/detener/inspeccionar contenedores, attach a stdin/stdout, redes, volúmenes, logs, healthcheck) y verificar con tests unitarios frente a un daemon Docker local (contenedor de prueba)

## 3. Ciclo de vida de agentes

- [ ] 3.1 Implementar el dominio de agentes (crear/detener/arrancar/recre/borrar, nombre único por propietario, cuota de agentes en ejecución) y verificar con feature tests: creación arranca contenedor, nombre duplicado rechazado, cuota agotada rechazada
- [ ] 3.2 Implementar el aprovisionamiento del contenedor (imagen, volúmenes workspace+datos, redes dual, env con URL del Model Proxy y token por agente, límites CPU/memoria, `restart: unless-stopped`, healthcheck) y verificar con un test de integración que inspecciona el contenedor creado (redes, volúmenes, límites)
- [ ] 3.3 Implementar la monitorización de estado (transiciones por eventos y healthcheck, auto-reinicio al crash, historial de estados) y verificar con un test que mata el contenedor y observa `working→error→starting→idle` más la fila del historial
- [ ] 3.4 Implementar la UI web de agentes (lista con estado en vivo, detalle con montajes/recursos/internet/botones de ciclo de vida) y verificar con tests Livewire de las interacciones clave (crear, detener, borrar con confirmación)

## 4. Gestión de modelos

- [ ] 4.1 Implementar el CRUD de proveedores (nombre, URL base, clave secreta, formato openai|anthropic, check de conectividad, descubrimiento de modelos desde `/v1/models` editable) y verificar con feature tests usando un endpoint fake en el entorno de test
- [ ] 4.2 Implementar el Model Proxy (rutas OpenAI por agente, token por agente, inyección de la clave real, restricción al proveedor/modelo configurado, log de uso) y verificar con tests de integración: petición con token válido llega al upstream con la clave; token ajeno → 401; agente sin Internet lo alcanza por la red interna
- [ ] 4.3 Implementar la selección de modelo por agente (UI + entrega a la configuración del runtime) y verificar que al cambiar el modelo la configuración nativa del agente se re-renderiza sin perder su sesión
- [ ] 4.4 Implementar la superficie de fallos de proveedor (estado del agente «error» + mensaje en chat cuando el endpoint falla) y verificar con un test con el endpoint caído

## 5. Gestión de MCP

- [ ] 5.1 Implementar el CRUD del catálogo MCP (stdio: comando/args/env; HTTP: URL/cabeceras; secretos enmascarados en UI y API) y verificar con feature tests incluyendo la enmascaración
- [ ] 5.2 Implementar conectar/desconectar por agente con entrega de configuración y verificar que al alternar, la configuración nativa del runtime incluye/excluye el servidor y el agente lo aplica sin perder la sesión
- [ ] 5.3 Implementar el estado MCP por agente (conectado/fallo/herramientas descubiertas) y verificar con un test donde un servidor que falla muestra su error en la UI del agente

## 6. Runtimes v1 (Pi y Hermes)

- [ ] 6.1 Definir la interfaz `AgentRuntimeAdapter` y el modelo `AgentEvent`, e implementar la pipeline de eventos (normalización, persistencia en `agent_events`, broadcast por Reverb autorizado a owner+admin) y verificar con tests unitarios del normalizador usando fixtures grabadas de ambos runtimes
- [ ] 6.2 Construir la imagen del agente Pi (node + `pi` global + node/python para MCP stdio, usuario no root, healthcheck, proceso principal `pi --mode rpc`) y verificar que `docker build` pasa y que el contenedor responde a un prompt RPC «hola» por el stdout
- [ ] 6.3 Implementar el adapter Pi (attach por Docker, entrega de mensajes, mapeo RPC→AgentEvent, cola de turnos, reanudación de sesión tras reinicio) y verificar con un test de integración: prompt → eventos streaming; `docker restart` → la siguiente respuesta conserva el contexto previo
- [ ] 6.4 Construir la imagen del agente Hermes (sobre la imagen oficial, volumen `/opt/data`, gateway + API server con clave por agente, usuario no root si el runtime lo permite) y verificar que `docker build` pasa y que `:8642` responde al chat OpenAI-compatible con su clave
- [ ] 6.5 Implementar el adapter Hermes (HTTP OpenAI-compatible, clave por agente, mapeo del streaming→AgentEvent incluyendo progreso de herramientas, continuidad de sesión en el volumen) y verificar con un test de integración análogo al de Pi
- [ ] 6.6 Implementar el renderer de configuración por runtime (Pi: settings + `mcp.json` + env; Hermes: `config.yaml` + `.env`) y verificar que para un agente de muestra los ficheros renderizados coinciden con el esperado (golden files)
- [ ] 6.7 Implementar el mapeo de aprobaciones por runtime (permisos de Pi y aprobaciones de Hermes → `approval.request`, decisión del usuario → continuación del agente) y verificar con un test de integración: el agente pide aprobación, se acepta por API, y el trabajo continúa

## 7. Chat (web)

- [ ] 7.1 Implementar el cliente de chat (canal Reverb `agent.{id}` con autorización, Alpine WSS, historial, streaming de texto, render de eventos de herramienta, botones de aprobación, indicadores de cola) y verificar con tests de feature que cubren el flujo completo de un turno
- [ ] 7.2 Implementar la síntesis de la línea de tiempo (ventanas de trabajo a partir de `agent_events`) y su UI («lo que hizo mientras no estabas») y verificar con un test que alimenta eventos de muestra y asiente el resumen renderizado
- [ ] 7.3 Verificar la coherencia multi-dispositivo (dos clientes WSS autorizados reciben el mismo orden de mensajes y eventos) con un test de integración

## 8. App iOS (SwiftUI)

- [ ] 8.1 Crear el proyecto Xcode (SwiftUI) con configuración de URL del servicio, login por token y estado sin conexión con reintento, y verificar que arranca en el simulador y autentica contra el compose de staging
- [ ] 8.2 Implementar la lista de agentes con estado en vivo por WSS y verificar que un cambio de estado aparece sin refrescar (simulador + escenario grabado)
- [ ] 8.3 Implementar el chat (envío, streaming, aprobaciones, historial, línea de tiempo — paridad con la web) y verificar con XCTests contra un servidor WSS de pruebas y una sesión manual de humo
- [ ] 8.4 Implementar las pantallas de configuración de agente (modelo, MCP, internet, recursos) reutilizando el API y verificar que un cambio hecho en la app tiene el mismo efecto observable que el hecho en la web
- [ ] 8.5 Implementar reconexión/resincronización tras pérdida de red y los String Catalogs es/en/eu con override del usuario, y verificar con la prueba de modo avión en el simulador y con el cambio de idioma en la app

## 9. Localización (web)

- [ ] 9.1 Añadir los ficheros de idioma `es`/`en`/`eu` completos, la preferencia por usuario persistida (default: locale del navegador soportado, si no `es`), el fallback a `en` con indicador de clave pendiente en modo debug, y verificar con tests que cambian de locale y asientan los textos clave de cada área

## 10. Endurecido e integración

- [ ] 10.1 Configurar el proxy de egress (herramienta por decidir: squid o envoy) con allowlist derivada de los hosts de los endpoints de modelo y verificar desde un agente con Internet: alcanza hosts allowlistados y no alcanza el resto
- [ ] 10.2 Endurecer contenedores (usuario no root donde el runtime lo permita, filesystem solo lectura donde sea viable, sin socket de Docker en agentes) y verificar con un script de auditoría sobre los contenedores en producción de prueba
- [ ] 10.3 Pase de seguridad (autorización por token en WSS y API, enmascarado de secretos en todas las vistas, CSRF en web) y verificar con tests de seguridad de feature
- [ ] 10.4 Smoke de extremo a extremo sobre el compose completo (admin crea proveedor → crea agente Pi → chat con aprobación → conecta MCP → crea agente Hermes → chat) mediante un script reproducIBLE y verificar que termina en 0
- [ ] 10.5 Escribir la documentación de despliegue (prerrequisitos del host, `compose up`, primer admin, añadir endpoint de modelo, guía de agente) y las release notes v1.0, y verificar siguiendo el doc en un host limpio hasta el primer chat

## Workflow follow-up

- Al completar v1.0, archivar el cambio `taap-v1` y verificar el resultado archivado.
- Planear como cambios de OpenSpec sucesivos: `taap-v1.1-claude` (adapter Claude Code + ruta Anthropic del Model Proxy) y `taap-v1.2-codex-opencode` (app-server de Codex; HTTP de OpenCode).
