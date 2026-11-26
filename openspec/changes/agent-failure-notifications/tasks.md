# Tasks

## 1. Configuración y envío

- [x] 1.1 Extender `Settings`: claves `failure_webhook_url` y `failure_webhook_secret` (enmascaradas en la UI); verificar con test que las claves se persisten y se ocultan
- [x] 1.2 Crear job `SendAgentFailureNotification` (POST con HMAC + reintentos 10/30/60s); verificar con test que un POST que falla 500 reintenta y que un 4xx no reintenta

## 2. Disparo de la transición

- [x] 2.1 Hook en la transición de estado del agente: encolar el job al transicionar a «error» (si webhook habilitado); verificar con feature test que la transición a error produce el envío (con webhook local de test)

## 3. Panel admin

- [x] 3.1 Mostrar resumen de notificaciones en el panel admin (último intento, resultado); verificar con feature test que un admin ve el resumen y un no admin no tiene esta sección

## 4. Verificación final

- [x] 4.1 Suite completa + docs (sección de webhooks en docs/deployment.md); verificar con `php artisan test` verde
