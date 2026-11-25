# shared-filesystem Specification

## Purpose

Define el almacenamiento de ficheros compartido de TAAP: un árbol físico único con vistas virtuales por rol (administrador, usuario, agente), acceso por agente con modos de permiso, explorador web y contrato de API estable para clientes.

## Requirements

### Requirement: Raíz de almacenamiento físico
El sistema SHALL mantener el almacenamiento compartido de ficheros en una única raíz física cuya ubicación se define en la variable de entorno `TAAP_FS_ROOT` del despliegue del orquestador. Esa raíz SHALL estar montada por bind en los contenedores del orquestador y en cada contenedor de agente, y su punto de montaje SHALL ser el "/" del sistema de archivos virtual para agentes y orquestador.

#### Scenario: Contenedor de agente con la raíz compartida
- **WHEN** se provisiona un contenedor de agente
- **THEN** la raíz física queda montada en su interior como "/" del sistema de archivos compartido, y un fichero que el agente crea bajo una ruta aparece en la raíz del host con esa misma ruta

#### Scenario: Administrador visualiza la ubicación física
- **WHEN** un administrador abre la sección "Archivos"
- **THEN** ve la ubicación de la raíz física configurada, en solo lectura (no es modificable desde la interfaz)

### Requirement: Directorio de usuario
Cada usuario SHALL tener un directorio en el primer nivel de la raíz física, nombrado con un slug estable derivado de su nombre, creado en su primer uso. Para el usuario, ese directorio SHALL ser el "/" de su sistema de archivos virtual: no podrá ver ni modificar los ficheros de otros usuarios ni de niveles superiores, y el slug SHALL permanecer estable aunque cambie el nombre de visualización del usuario.

#### Scenario: Usuario nuevo obtiene su directorio
- **WHEN** un usuario nuevo se registra y abre "Archivos" por primera vez
- **THEN** se crea su directorio con el slug en el primer nivel de la raíz y el explorador lo muestra como "/" (vacío)

#### Scenario: El usuario no sale de su raíz
- **WHEN** un usuario solicita por interfaz o API una ruta que, tras resolverla, queda fuera de su directorio (p. ej. `../otrouser` o un enlace simbólico que apunte fuera)
- **THEN** el sistema rechaza la operación con error y el usuario no ve ni modifica contenido de otros usuarios

### Requirement: Acceso total del administrador
El administrador SHALL poder navegar y gestionar todo el árbol físico en el explorador de ficheros, incluyendo todos los directorios de usuario y cualquier otro contenido de la raíz, con las mismas acciones que los usuarios. Los directorios de usuario SHALL ser identificables en la vista del administrador, y renombrar o borrar uno de ellos SHALL requerir confirmación explícita.

#### Scenario: El administrador navega el árbol completo
- **WHEN** un administrador abre "Archivos"
- **THEN** el "/" del explorador es la raíz física y ve todos los directorios de usuario y cualquier otro contenido

#### Scenario: El administrador borra un directorio de usuario
- **WHEN** un administrador solicita borrar un directorio que es el de inicio de un usuario registrado
- **THEN** el sistema pide confirmación explícita y, al confirmar, lo borra recursivamente

### Requirement: Explorador web de ficheros
El sistema SHALL ofrecer una sección web accesible desde el menú superior como "Archivos", visible para todos los usuarios autenticados, que muestre un árbol navegable de carpetas y ficheros. El usuario SHALL poder crear carpetas, subir ficheros, renombrar carpetas y ficheros y borrar carpetas y ficheros; el borrado de carpetas, por ser recursivo, SHALL requerir confirmación.

#### Scenario: Crear carpeta y subir fichero
- **WHEN** un usuario crea la carpeta `datos/pruebas` y sube `fichero.txt` dentro de ella
- **THEN** la carpeta y el fichero aparecen en el explorador con sus rutas Unix y el fichero queda guardado en el almacenamiento físico del usuario

#### Scenario: Renombrar y borrar
- **WHEN** un usuario renombra un fichero y borra una carpeta con contenido
- **THEN** el fichero pasa a la nueva ruta y la carpeta con su contenido desaparece tras la confirmación de borrado recursivo

#### Scenario: Nombre inválido
- **WHEN** un usuario crea o renombra una entrada con un nombre que contiene caracteres no válidos para el sistema de archivos o que ya existe en el destino
- **THEN** el sistema lo rechaza con un error de validación y no modifica nada

### Requirement: Descarga de ficheros y carpetas
El usuario y el administrador SHALL poder descargar desde el explorador cualquier fichero que puedan ver, y cualquier carpeta que puedan ver como archivo .zip comprimido que reproduzca el contenido completo de la carpeta con sus rutas relativas.

#### Scenario: Descarga de un fichero
- **WHEN** un usuario solicita la descarga de `datos/pruebas/fichero.txt`
- **THEN** el sistema sirve el fichero con su nombre y contenido

#### Scenario: Descarga de una carpeta como .zip
- **WHEN** un usuario solicita la descarga de la carpeta `datos`
- **THEN** el sistema le proporciona `datos.zip`, que al descomprimirlo reproduce el árbol completo de la carpeta con rutas relativas

### Requirement: Configuración de carpetas por agente
La configuración de cada agente SHALL permitir añadir, editar y borrar múltiples carpetas del sistema de archivos compartido, cada una con un modo `desactivada`, `solo lectura` o `lectura/escritura`. El propietario del agente solo podrá seleccionar carpetas dentro de su propio directorio de usuario; el administrador podrá seleccionar cualquier carpeta del árbol. Una carpeta en modo `desactivada` no SHALL estar expuesta al agente.

#### Scenario: El propietario añade una carpeta
- **WHEN** el propietario añade la carpeta `/proyectos` de su propio directorio con modo lectura/escritura a su agente
- **THEN** la carpeta figura en la configuración del agente con ese modo y el contenedor del agente la expone al agente

#### Scenario: El administrador conecta una carpeta de otro usuario
- **WHEN** un administrador conecta a un agente una carpeta que no pertenece al directorio del propietario del agente
- **THEN** la carpeta queda expuesta al agente y, en la vista del propietario, se muestra con su ruta completa marcada como externa

#### Scenario: Cambio de modo
- **WHEN** se cambia y guarda el modo de una carpeta configurada
- **THEN** el contenedor del agente se re-provisiona con los nuevos montajes y el agente reanuda su sesión sin perder estado

### Requirement: Aislamiento de rutas del agente
Un agente solo SHALL poder leer y escribir las carpetas configuradas en su configuración y los ficheros y subdirectorios que contengan, de forma recursiva. El agente no SHALL poder leer ni escribir ninguna otra ruta, ni al mismo nivel ni a niveles superiores, ni crear entradas fuera de las carpetas configuradas. La restricción SHALL ser impuesta por el propio contenedor, no por convención.

#### Scenario: Acceso dentro de una carpeta configurada
- **WHEN** el agente lee o escribe un fichero dentro de una carpeta configurada, en un modo que permite la operación
- **THEN** la operación tiene éxito y el cambio es visible en el explorador del usuario y del administrador

#### Scenario: Acceso fuera de las carpetas configuradas
- **WHEN** el agente intenta leer, listar o escribir una ruta que no está dentro de ninguna carpeta configurada (la raíz, el directorio de otro usuario o una carpeta hermana)
- **THEN** la operación falla y esas rutas no son visibles para el agente

#### Scenario: Carpeta desactivada
- **WHEN** una carpeta configurada está en modo `desactivada`
- **THEN** el agente no puede verla ni acceder a ella

### Requirement: Acceso sin confirmación en el chat
El agente SHALL poder acceder a sus carpetas conectadas (leer y, si el modo lo permite, escribir) sin que el sistema solicite confirmación al usuario en el chat. Las peticiones de aprobación SHALL seguir aplicándose únicamente a las acciones que afecten a rutas fuera de las carpetas conectadas.

#### Scenario: Operación en carpeta conectada sin aprobación
- **WHEN** el agente, durante un turno, lee o escribe un fichero en una carpeta conectada cuyo modo permite la operación
- **THEN** la operación se realiza sin emitir ninguna petición de aprobación al usuario

#### Scenario: Acción fuera de las carpetas conectadas
- **WHEN** el agente ejecuta una acción que afecta a una ruta fuera de sus carpetas conectadas
- **THEN** el sistema solicita al usuario la aprobación conforme al flujo de aprobaciones vigente

### Requirement: Coordinación multiagente
Varios agentes SHALL poder conectar la misma carpeta del sistema de archivos compartido, cada uno con su propio modo de acceso. Los cambios que un agente realice en una carpeta compartida en lectura/escritura SHALL ser visibles para los demás agentes con acceso a esa carpeta.

#### Scenario: Dos agentes sobre la misma carpeta
- **WHEN** dos agentes conectan la misma carpeta en lectura/escritura y uno de ellos crea un fichero en ella
- **THEN** el otro agente puede leer el fichero y el propietario lo ve en su explorador

### Requirement: Formato de rutas Unix
Todas las rutas del sistema de archivos SHALL mostrarse en formato Unix (comenzando por `/`, sin barra final, sin componentes `.` o `..`), p. ej. `/datos/pruebas/fichero.txt`. En las vistas del propietario del agente (su explorador y la configuración del agente) las rutas se presentarán relativas a su directorio de usuario; en las vistas del administrador y en las referencias del propio agente se presentarán relativas a la raíz física.

#### Scenario: El mismo fichero en distintas vistas
- **WHEN** la usuaria `ana` tiene un fichero en `proyectos/ideas.txt` de su directorio de inicio
- **THEN** en el explorador de ana y en la configuración de sus agentes aparece como `/proyectos/ideas.txt`, en el explorador del administrador como `/ana/proyectos/ideas.txt` y el agente lo referencia bajo su punto de montaje como `/ana/proyectos/ideas.txt`

#### Scenario: Ruta no canónica
- **WHEN** un cliente (interfaz o API) solicita una ruta que no está en formato canónico (relativa, con barras dobles, con `..` o con barra final)
- **THEN** el sistema la normaliza a su forma canónica o, si al resolverla queda fuera de la raíz permitida, la rechaza con error

### Requirement: API estable para clientes
El sistema SHALL exponer las operaciones del sistema de archivos compartido (listado, crear carpeta, renombrado, borrado, subida, descarga de fichero y descarga de carpeta en .zip) a través de la API pública v1 con autenticación por token, con un contrato versionado y estable. La raíz sobre la que operan las peticiones SHALL resolverse en el servidor según el rol del usuario autenticado, de modo que la futura app iOS pueda implementar la misma funcionalidad sin cambios de contrato.

#### Scenario: Usuario no administrador lista su raíz
- **WHEN** un usuario no administrador solicita por la API el listado de la ruta `/`
- **THEN** el sistema devuelve el contenido de su propio directorio de inicio

#### Scenario: Administrador lista la raíz
- **WHEN** un administrador solicita por la API el listado de la ruta `/`
- **THEN** el sistema devuelve el contenido de la raíz física
