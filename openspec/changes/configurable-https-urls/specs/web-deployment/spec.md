# Spec Delta

## Purpose

Define cómo se expone la aplicación web a los clientes: el esquema (http/https) de las URLs que la aplicación genera —assets, redirects, URLs absolutas— y cómo se configura por entorno para despliegues tras proxy TLS.

## ADDED Requirements

### Requirement: Esquema de URL configurable
El sistema SHALL permitir configurar el esquema de las URLs que la aplicación genera (assets de Vite, redirects, `url()`/`asset()`) mediante la variable de entorno `TAAP_FORCE_HTTPS` del orquestador. El valor `true` SHALL hacer que todas las URLs generadas usen https; `false` SHALL hacer que ninguna lo haga; si la variable NO está definida, el esquema SHALL derivarse de `APP_URL` (https solo si `APP_URL` usa https), preservando el comportamiento actual.

#### Scenario: Despliegue TLS con APP_URL interna http
- **WHEN** un despliegue define `TAAP_FORCE_HTTPS=true` y `APP_URL` es una URL interna con esquema `http` (p. ej. `http://127.0.0.1:8000`)
- **THEN** las URLs de assets de la web (Vite) y los redirects se generan con esquema `https`, y el navegador no bloquea el CSS/JS por contenido mixto

#### Scenario: Desarrollo local sin la variable
- **WHEN** `TAAP_FORCE_HTTPS` no está definida y `APP_URL` usa esquema `http`
- **THEN** las URLs generadas mantienen `http` (sin redirects a https innecesarios, sin romper el servidor de desarrollo)

#### Scenario: HTTPS explícitamente desactivado
- **WHEN** `TAAP_FORCE_HTTPS=false` y `APP_URL` usa esquema `https`
- **THEN** las URLs generadas usan `http` (el valor explícito de la variable tiene preferencia sobre `APP_URL`)

### Requirement: Plantilla de despliegue
La plantilla de variables del despliegue (`deploy/env.example`) SHALL incluir `TAAP_FORCE_HTTPS` documentando sus tres estados, y la configuración de despliegue con TLS (Traefik) SHALL activarla (`true`), de modo que una instalación por defecto tras TLS sirva los assets de la web sin contenido mixto.

#### Scenario: Instalación TLS por defecto
- **WHEN** se arranca el despliegue con la plantilla de Traefik (TLS) sin tocar variables
- **THEN** la página web carga sus assets (CSS/JS de Vite) sin bloqueos de contenido mixto

#### Scenario: Despliegue sin TLS
- **WHEN** se arranca el despliegue sin Traefik (solo http)
- **THEN** `TAAP_FORCE_HTTPS` queda `false` o sin definir y la web sirve y carga sus assets por http
