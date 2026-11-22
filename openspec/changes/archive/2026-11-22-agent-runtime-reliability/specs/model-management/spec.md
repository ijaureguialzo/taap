# Spec Delta

## ADDED Requirements

### Requirement: El sondeo de salud presenta la misma identidad que el proxy
La comprobación de salud de un proveedor SHALL usar la misma identidad
upstream que el proxy de modelos hacia ese proveedor: con un `base_url` en
`host.docker.internal`, el sondeo SHALL presentar como `Host`
`127.0.0.1[:puerto]` — la dirección por la que el gateway se ve a sí mismo,
igual que el proxy. Un gateway que sirva el tráfico de agentes a través del
proxy SHALL marcarse sano: el veredicto no depende del alias de red del
contenedor orquestador.

#### Scenario: Gateway con restricción de Host
- **WHEN** un gateway OpenAI-compatible (p. ej. llama.cpp con
  `--allowed-host`) solo acepta `Host: 127.0.0.1` y su `base_url` está
  declarada con `host.docker.internal`
- **THEN** la comprobación de salud presenta `Host: 127.0.0.1:puerto`,
  recibe 200 y el catálogo muestra el proveedor como sano

#### Scenario: Sincronía probe/proxy
- **WHEN** el proxy de modelos enruta peticiones de un agente al upstream
  con éxito
- **THEN** la comprobación de salud del mismo proveedor devuelve el mismo
  veredicto que el tráfico real (no sano solo si el gateway también falló
  para el proxy)
