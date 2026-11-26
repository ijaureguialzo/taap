# Spec Delta

## ADDED Requirements

### Requirement: File browser
La aplicación SHALL ofrecer un explorador de ficheros (navegable por la raíz del usuario) con el mismo contrato de la API v1 de ficheros: listar, crear carpeta, subir ficheros, descargar ficheros (y `.zip` de carpetas), renombrar y borrar, con confirmación destructiva y errores claros. La interfaz SHALL seguir las mismas reglas de localización (es/en/eu) y los tokens del sistema de diseño.

#### Scenario: Browse and navigate
- **WHEN** el usuario abre «Archivos» y entra en una carpeta
- **THEN** la vista muestra el contenido, los ficheros y carpetas, y el botón de volver

#### Scenario: Upload file
- **WHEN** el usuario sube un fichero desde el explorador
- **THEN** el fichero aparece en la lista y la subida devuelve la respuesta del servidor con los errores claros (413, 403…)

#### Scenario: Download file
- **WHEN** el usuario toca un fichero en el explorador
- **THEN** la descarga del fichero (o del .zip de la carpeta) se inicia y se comparte/guarda como en iOS

#### Scenario: Delete with confirmation
- **WHEN** el usuario borra un fichero o carpeta
- **THEN** la acción requiere confirmación y el sistema muestra el resultado (éxito o error)
