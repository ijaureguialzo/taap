# Tareas: agent-runtime-reliability

## 1. Identidad upstream (D1)

- [x] 1.1 Crear `app/Support/UpstreamIdentity.php::hostFor(string $baseUrl): ?string` (mapeo `host.docker.internal → 127.0.0.1`, conserva puerto; `null` si no hay que fijar Host)
- [x] 1.2 `Providers/Index::probe()` aplica `Host` vía el helper (misma convención que el proxy)
- [x] 1.3 `ModelProxyController` usa el helper en vez del mapeo inline (sin cambio de comportamiento)
- [x] 1.4 Unit `UpstreamIdentityTest`: host.docker.internal con/sin puerto, host normal, base_url sin puerto
- [x] 1.5 Feature de salud: upstream stub que 403 con Host ≠ 127.0.0.1 → provider con base_url `host.docker.internal` pasa el probe

## 2. Direccionamiento de red (D2)

- [x] 2.1 `DockerClient::allocateIp(string $network, array $reserved = [])` — excluye `$reserved` además de `usedIps()`
- [x] 2.2 `AgentManager::provision()` pasa `Agent::whereNotNull('ip')->pluck('ip')` como reservas
- [x] 2.3 Unit: con DockerClient simulado — IP en vivo se salta, IP reservada en DB se salta, caso Claudia/Pi (misma IP en dos agentes) no se reasigna

## 3. Reconcile de `created` (D3)

- [x] 3.1 `AgentManager::reconcile()`: rama para estado `created` (start si el agente debe estar vivo; `Error` + log si el start falla)
- [x] 3.2 Test: agente con contenedor `created` y `auto_restart` queda arrancado por el reconcile

## 4. Verificación integral y curación en vivo

- [x] 4.1 Suite PHP completa: sin fallos nuevos vs. la línea base (el fallo preexistente de OpenCode documentado no cuenta)
- [x] 4.2 En vivo 8011: re-comprobar "splash" → «Sano»; sus modelos dejan de estar atenuados en el selector
- [x] 4.3 En vivo 8011: curar a Claudia (borrar contenedor + re-provision o start tras el fix) → contenedor `running`, agente `idle`, chat funcional
