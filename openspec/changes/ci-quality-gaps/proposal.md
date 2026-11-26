# Proposal: Gaps de calidad y CI (imágenes de agentes + cobertura iOS)

## Why
La CI `.github/workflows/ci.yml` construye solo las imágenes `agent-pi` y `agent-hermes` (las únicas dos que existen en la v1), pero `.github/workflows/ci.yml` fue escrito antes de que v1.1 añadiera Claude Code, Codex y OpenCode — esas imágenes ya no se construyen en CI y pueden romper en silencio hasta el `make images`. Además, la app iOS tiene una suite de unit tests (`Tests/Unit/*.swift`) sin job de CI ni gate de cobertura: las regresiones en iOS se enteran tarde.

## What Changes
- Job `images` de CI que construye las **cinco** imágenes de agente (`agent-pi`, `agent-hermes`, `agent-claude`, `agent-codex`, `agent-opencode`) — o al menos valida el build de las tres añadidas en v1.1.
- Job de CI que corre los tests unitarios de iOS (o al menos el camino reproducible documentado) así como fijar la deuda de cobertura iOS (ApiClient, ChatSession, ReverbSocket, Theme).
- Sin cambios en specs de producto: **skip_specs**.

**Out of scope:** rediseño de la estrategia de tests de iOS (solo que exista y corra en CI); E2E completo de iOS en CI (requiere Xcode/macOS runner); despliegue automatizado de imágenes.

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
*(ninguna)*

## Impact
- `.github/workflows/ci.yml` (images job + nuevo job iOS).
- `mobile/ios/Tests/` se amplía: ApiClient, ChatSession, ReverbSocket, Theme.
- Sin cambios de código de producción.
