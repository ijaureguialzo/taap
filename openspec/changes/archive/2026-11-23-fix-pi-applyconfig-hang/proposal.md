# Proposal

## Why

Al guardar la configuración de un agente (modelo, prompt, permisos), la aplicación se queda colgada y el navegador intenta cerrarla por alto consumo de recursos. Esto ocurre porque `PiAdapter::applyConfig()` invoca `attach()`, que spawnea coprocesos `proc_open` (lector y escritor IPC) directamente desde un job de la cola de Laravel. Estos coprocesos nunca se matan al terminar el job, convirtiéndose en procesos huérfanos que se acumulan en el sistema. El problema se agrava con cada guardado, consumiendo cada vez más CPU y memoria hasta que el navegador interviene.

## What Changes

- Eliminar la llamada a `$this->attach($agent)` de `PiAdapter::applyConfig()`. El daemon `TaapBridgeCommand` (proceso 24/7) ya mantiene la sesión IPC viva y se encarga de re-adjuntar automáticamente cuando detecta un restart del contenedor.
- Evitar el double-render de config: `BridgeApplyConfig` ya llama a `ConfigRenderer::render()` antes de `applyConfig()`; al eliminar el restart interno de Pi ya no se vuelve a renderizar.
- Documentar el comportamiento esperado de `applyConfig()` para Pi: re-renderiza la config nativa y delega la reconexión IPC al bridge.

## Capabilities

### Modified Capabilities

- `agent-runtimes`: el requisito "Pi adapter" y "Per-runtime configuration delivery" — se aclara que la aplicación de config para Pi se limita a re-renderizar config nativa; la reconexión IPC la gestiona el bridge, no el adapter.

## Impact

| Aspecto | Detalle |
|---------|---------|
| **Código afectado** | `app/Agents/PiAdapter.php` (método `applyConfig`), `app/Jobs/BridgeApplyConfig.php` (ya re-renderiza antes) |
| **Sin cambios en** | `TaapBridgeCommand.php` (solo se reafirma su rol), la UI de Livewire, la DB, las imágenes Docker |
| **Efecto funcional** | Al guardar config de un agente Pi, el bridge detectará el restart y volverá a adjuntar el IPC en el siguiente ciclo. No hay pérdida de sesión ni interrupción del chat |
| **Riesgo** | Mínimo: el bridge ya re-adjunta al detectar cambio de `container_id` o `State.StartedAt`; esta línea solo limpia la lógica redundante del adapter |
