# Spec Delta

## MODIFIED Requirements

### Requirement: Per-agent filesystem access
El usuario SHALL poder definir por agente múltiples carpetas del sistema de archivos compartido (capacidad `shared-filesystem`) con modo `desactivada`, `solo lectura` o `lectura/escritura`, montadas en el contenedor del agente. El workspace del agente y su directorio de datos de runtime SHALL persistir en volúmenes Docker que sobrevivan a la recreación del contenedor.

#### Scenario: Read-write mount
- **WHEN** un agente con una carpeta en lectura/escritura modifica un fichero dentro de ella
- **THEN** el cambio es visible en el almacenamiento físico del host y en el explorador de ficheros del usuario

#### Scenario: Read-only mount
- **WHEN** un agente intenta escribir dentro de una carpeta en solo lectura
- **THEN** la escritura falla y el agente reporta el error en el chat
