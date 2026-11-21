# Spec Delta

## ADDED Requirements

### Requirement: Registro expuesto a los clientes
El sistema SHALL exponer el registro de runtimes a los clientes a través de la API pública: una lista que contenga exactamente los runtimes del registro, cada uno con su identificador, su nombre para mostrar y los formatos de alambre de modelo que consume.

#### Scenario: Un cliente consulta los runtimes
- **WHEN** un cliente autenticado consulta la lista de runtimes por la API
- **THEN** recibe exactamente los runtimes del registro (cinco en esta versión) con identificador, etiqueta y formatos

#### Scenario: Runtime nuevo en el registro
- **WHEN** un runtime se añade al registro del sistema y se despliega
- **THEN** aparece en la lista que la API expone, sin cambios adicionales en los clientes
