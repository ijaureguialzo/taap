# backup-restore Specification

## Purpose

Exportación y restauración soportada de todos los datos de TAAP (Postgres, la raíz física del sistema de ficheros compartido y los datos persistentes de los agentes) con integridad verificable y procedimiento documentado.

## Requirements

### Requirement: Bundle creation
El sistema SHALL exponer un comando `taap:backup` que produce un bundle fechado (timestamp) con el volcado de Postgres, la copia de `TAAP_FS_ROOT`, la copia de los datos persistentes de los agentes (`TAAP_AGENT_DATA_DIR`) y un manifiesto con hashes (sha256) de cada parte, excluyendo secretos y `.env`.

#### Scenario: Backup produced
- **WHEN** el admin ejecuta `php artisan taap:backup`
- **THEN** se genera un bundle fechado con dump de la BD, copia de FS root y datos de agentes, y manifest.json con hashes de cada archivo

#### Scenario: Bundle excludes secrets
- **WHEN** el bundle se genera
- **THEN** no contiene `.env`, claves de proveedores ni credenciales de agentes (la reposición de secretos documenta el paso manual)

### Requirement: Restore with integrity check
El sistema SHALL restaurar desde un bundle cuando el admin lo pida, verificando los hashes del manifiesto antes de cualquier escritura; si falla la verificación, NO SHALL comenzar la restauración. La restauración destruye datos existentes y SHALL requerir confirmación explícita.

#### Scenario: Restore on valid bundle
- **WHEN** el admin ejecuta `php artisan taap:backup:restore --yes` para un bundle con hashes válidos
- **THEN** el sistema restaura Postgres, FS root y datos de agentes y muestra el resumen de verificación

#### Scenario: Restore refused on corrupt bundle
- **WHEN** el admin ejecuta el restore contra un bundle cuyo hash no coincide con el manifiesto
- **THEN** el sistema rechaza la operación sin modificar ningún dato

#### Scenario: Destructive restore requires confirmation
- **WHEN** el admin intenta restaurar sin el flag de confirmación explícita
- **THEN** el sistema muestra el aviso de destrucción y no modifica los datos

### Requirement: Restore documented procedure
El sistema SHALL documentar en `docs/deployment.md` el procedimiento completo de backup y restore (comandos exactos, orden de restauración, qué verificar post-restore), accesible al operador sin conocimientos de código.

#### Scenario: Documented restore
- **WHEN** un operador sigue el procedimiento de `docs/deployment.md`
- **THEN** la restauración completa se completa sin intervención manual adicional
