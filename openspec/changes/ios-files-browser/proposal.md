# Proposal: Explorador de ficheros en la app iOS

## Why
El web y la API v1 tienen un sistema de ficheros compartido completo (listar, subir, carpeta, renombrar, borrar, descargar, `.zip` de directorios). La app iOS — que es la superficie de móvil — no tiene ningún equivalente: los tabs son «Agentes» y «Configuración», sin vista de archivos. El usuario de móvil tiene que abrir el web o quedarse con la vista previa.

## What Changes
- Nueva sección «Archivos» en la app iOS (navegable al estilo FileBrowser): árbol de la raíz del usuario, listar, crear carpeta, subir archivos, descargar archivos (y `.zip` de carpetas), renombrar y borrar — reutilizando exactamente la API v1 de ficheros como fuente de verdad.
- Localización (es/en/eu) y tokens del sistema de diseño/dark mode existentes en SwiftUI.
- Confirmaciones destructivas y errores claros (código 413, 403, 404...), igual que el web.

**Out of scope:** edición de archivos dentro de la app (escribir contenido); sincronización local a disco; soporte iPad distinto del iPhone.

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
- `ios-client`: la app gana una superficie de ficheros con las mismas capacidades de navegado/descargado que la web, vía la API v1 de shared-filesystem.

## Impact
- `mobile/ios/Taap/Views/` gana la vista de archivos (+ modelos en `ApiClient`/`ChatSession`).
- Tests SwiftUI de navegado/subida/descarga (patrón de `Tests/Unit` existente).
- Sin cambios de esquema ni de API: consume `/api/v1/files/*`.
