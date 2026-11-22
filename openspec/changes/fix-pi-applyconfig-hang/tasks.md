# Tasks

## 1. Implementar fix de PiAdapter::applyConfig

- [x] 1.1 Reemplazar el método `applyConfig()` de `app/Agents/PiAdapter.php` para que solo re-renderice la config nativa (sin `restart()` ni `attach()`) y verificar que la sintaxis PHP es correcta (`php -l app/Agents/PiAdapter.php`)

## 2. Verificación funcional

- [ ] 2.1 Arrancar el bridge (si no está corriendo): `php artisan taap:bridge` y verificar que no hay errores en consola durante los primeros 60 segundos
- [ ] 2.2 Guardar la configuración de un agente Pi en la UI (cambiar el system prompt y hacer clic en Save): verificar que la página responde en <2 segundos y no muestra mensajes de error
- [ ] 2.3 Comprobar que el agente se reinicia y vuelve a `idle` en <30 segundos tras el guardado
- [ ] 2.4 Verificar que no se acumulan procesos `php` huérfanos tras múltiples guardados: `ps aux | grep "php artisan"` antes y después de 3 guardados consecutivos — la cuenta no debe crecer

## 3. Verificación de no regresión

- [ ] 3.1 Enviar un mensaje a un agente Pi activo: verificar que la conversación responde con normalidad
- [ ] 3.2 Cambiar el modelo de un agente Pi: verificar que se aplica tras el reinicio sin pérdida de historial
- [ ] 3.3 Detener y arrancar manualmente un agente Pi: verificar que la reconexión IPC funciona correctamente

## Workflow follow-up

- Validar el cambio con `openspec validate --strict`
- Archive the change after verification is complete
- Commit and deploy el fix al entorno de producción
