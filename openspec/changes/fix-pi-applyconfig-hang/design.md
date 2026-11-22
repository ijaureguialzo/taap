# Design

## Context

El sistema TAAP gestiona 5 runtimes de agentes (Pi, Claude, Codex, Hermes, OpenCode). Cada uno tiene un adapter que implementa `AgentRuntimeAdapter::applyConfig()`. Los 4 runtimes no-Pi (Claude, Codex, Hermes, OpenCode) simplemente re-renderizan config o reinician el contenedor vía HTTP — no spawnean procesos locales.

Pi usa una superficie de comunicación diferente: `rpc-stdio` (JSONL por stdin/stdout del contenedor). El adaptador necesita mantener viva una sesión IPC usando dos coprocesos `proc_open` (lector `docker logs -f` y escritor `docker exec -i` loop). Esta sesión es gestionada por el daemon `TaapBridgeCommand` durante toda la vida del agente.

Cuando el usuario guarda configuración (`saveConfig()` en Detail.php), se encola un job `BridgeApplyConfig` que:
1. Renderiza la config nativa del agente (`ConfigRenderer::render()`)
2. Llamar al adapter `applyConfig()` para aplicar los cambios
3. Sincroniza el ACL de egress

## Goals / Non-Goals

**Goals:**
- Eliminar el consumo excesivo de recursos al guardar configuración
- Eliminar los procesos huérfanos que se acumulan en el sistema
- Mantener el comportamiento funcional de re-aplicación de config y reconexión IPC
- No romper la sesión del agente (contexto intacto)

**Non-Goals:**
- No refactorizar la arquitectura IPC del bridge
- No cambiar la superficie de comunicación de Pi (seguirá siendo rpc-stdio)
- No modificar el bridge ni sus coprocesos
- No abordar el mismo problema en otros adaptadores (no lo tienen)

## Decisions

### Decisión 1: Eliminar `attach()` de `PiAdapter::applyConfig()`

**Elección:** Quitar las líneas `app(AgentManager::class)->restart($agent)` y `$this->attach($agent)` del método `applyConfig()` y sustituirlas por solo `app(ConfigRenderer::class)->render($agent)`.

**Por qué:**
1. El job `BridgeApplyConfig` ya llama a `render()` antes de `applyConfig()`, por lo que el double-render es innecesario.
2. El bridge (daemon 24/7) detecta el restart del contenedor y llama a `attach()` automáticamente en su próximo ciclo de reconciliación (dentro de ~30s según `--status-every`).
3. Los 4 adaptadores restantes hacen exactamente esto: su `applyConfig()` solo re-renderiza config (Claude) o hace restart sin attach (Hermes/Codex/OpenCode).

**Alternativas consideradas:**
- **Llamar solo a `restart()` sin `attach()`**: Seguiría causando double-render y el bridge tendría que esperar el ciclo de status poll (30s) para re-adjuntar. Menos limpio.
- **Llamar a `detach()` + `attach()`**: Igual de problemático — mataría una sesión viva y la recrearía desde el job, en lugar de delegar al bridge.
- **No hacer nada en `applyConfig()` y mover todo al bridge**: Cambiaría el punto de orquestación, rompiendo la responsabilidad del adapter. No necesario.

### Decisión 2: `BridgeApplyConfig` ya hace el re-render

El job ya ejecuta `ConfigRenderer::render()` en su método `handle()` antes de llamar a `applyConfig()`. Por tanto, si el adapter solo llama a `render()`, la config se renderiza dos veces. Eliminar el `restart()` y `attach()` del adapter hace que la secuencia sea:
```
BridgeApplyConfig::handle():
  render($agent)     ← primera y ÚNICA llamada a render
  applyConfig($agent) ← solo re-renderiza la config nativa (ya lista)
  egress sync()
```

**Resultado:** La config se re-renderiza una vez, el contenedor se reinicia (porque el usuario cambió config y el bridge lo maneja), y el bridge re-adjunta la sesión.

## Risks / Trade-offs

| Risk | Mitigación |
|------|-----------|
| **Gap temporal de reconexión**: Tras un restart del contenedor, el bridge tardará ~30s (intervalo de status poll) en detectar y re-adjuntar. Durante ese lapso, el agente aparecerá como `offline` o `starting` para el usuario. | Este es el comportamiento actual si el bridge no está corriendo. El bridge es el encargado 24/7 de mantener la sesión. Si el bridge no está corriendo, el sistema ya no funciona (es un requisito). |
| **Config no aplicada si bridge no corre**: Sin bridge, el `render()` del job escribe el fichero pero no se re-adjunta. Si el usuario guarda config sin bridge activo, la config se aplica pero el agente no la verá hasta que el bridge se reinicie. | Esto es correcto: la config se persiste en disco. Cuando el bridge vuelve, lee la config ya actualizada. Es idéntico al comportamiento de otros runtimes. |
| **Procesos existentes huérfanos**: Los procesos `proc_open` huérfanos creados por guardados anteriores seguirán vivos hasta que se maten manualmente. | Después de desplegar, los procesos se limpiarán solos cuando terminen (o se puede hacer un `pkill -f artisan` si es urgente). |

## Migration Plan

1. **Despliegue**: Cambiar solo un archivo PHP: `app/Agents/PiAdapter.php`. No hay migrations de DB, ni cambios de config, ni cambios de infraestructura.
2. **Rollback**: Si hay problema, revertir el cambio de `PiAdapter.php` al commit anterior. Sin impacto en otros componentes.
3. **Limpieza**: Opcional — los procesos huérfanos existentes se pueden limpiar con `pkill -f "php artisan"` o `kill` manual. No son críticos (se reiniciarán si el bridge los necesita).
4. **Verificación**: Guardar la configuración de un agente Pi y verificar:
   - La página no se cuelga (responde en <1s)
   - El agente se reinicia y vuelve a `idle` en <30s
   - No se acumulan procesos `php` huérfanos

## Open Questions

Ninguna. El comportamiento del bridge (re-attach automático) está documentado en `TaapBridgeCommand.php` y ya se usa para todos los demás casos de reconexión.
