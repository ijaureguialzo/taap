# Design: Explorador de ficheros en la app iOS

## Context
Ver proposal.md — Why. Estado actual: la app SwiftUI tiene «Agentes» y «Configuración»; no hay vista de archivos. La API v1 expone `/api/v1/files` (index, download, folders, rename, uploads, destroy). La API es el contrato estable; el web consume la misma API.

## Goals / Non-Goals
**Goals:**
- Explorador de ficheros con la misma funcionalidad que la web (vía API v1).
- Localización es/en/eu + tokens de diseño existentes.

**Non-Goals:**
- Edición de archivos dentro de la app (escribir contenido); sync local a disco; soporte iPad distinto del iPhone.

## Decisions

1. **Modelo Swift UI contra el API v1** — modelos DTO reusando el patrón de `Dtos.swift` (`FileEntryDTO` con `path`, `type`, `sizeBytes`, `modifiedAt`); el cliente `ApiClient` conoce los endpoints `/files/*` (reusar el patrón de llamada con token Sanctum).
2. **Navegación** — `FilesView` (push per-folder: estado `path: String`); cierre/explícito de permisos en /`Files` tab o sheet; el `MainTabView` gana una nueva sección «Archivos» en la barra de navegación (decisión: sección lateral/tab — decisión documentada como sección de la app; mínimo cambio de estructura).
3. **Upload** — `UIDocumentPicker` (`.documentPicker` de SwiftUI) para elegir un fichero, subir vía `POST /api/v1/files/uploads`; manejo del error 413 (tamaño excedido) con mensaje claro; mostrar el progreso si la API lo soporta o estado de carga.
4. **Download** — fichero: descarga vía `GET /api/v1/files/download?path=` → guardar como archivo (compartido/abierto en el iPad); carpeta: descarga del `.zip` → compartir vía `UIActivityViewController`.
5. **Borrado** — confirmación antes de borrar (`Alert` con título y riesgo de irreversibilidad); errores mostrados como banner.
6. **Renombrado** — `TextField` en un `.formSheet` con validación local + respuesta del servidor.
7. **Errores** — mapear códigos HTTP a mensajes es/en/eu (400/403/404/413...); sin silenciar errores.
8. **Refresco** — pull-to-refresh y volver de subir/crear → refresh de la raíz; no hay WebSocket para archivos (el punto de verdad es el servidor de API).

## Risks / Trade-offs
- [El servidor puede cambiar de shape] → la API v1 es el contrato; el DTO es tolerante (decodificar solo los campos conocidos).
- [Descarga de .zip grande] → mostrar progreso y error; sin streaming sofisticado (decisión: carga completa).

## Migration Plan
- Sin migraciones; la app se actualiza con el endpoint existente. Rollout: nueva sección «Archivos» (retrocompatible). Rollback: quitar la sección.

## Open Questions
Ninguna diferible. (La UI de carga de progreso queda para el design detallado en implementación.)
