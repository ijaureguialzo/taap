# mcp-management Specification

## Purpose

Permite al administrador mantener un catálogo de servidores MCP (stdio y HTTP) y a cada usuario conectar o desconectar esos servidores de sus agentes a petición.

## Requirements

### Requirement: MCP catalog
El administrador SHALL poder definir servidores MCP con nombre, descripción y transporte: stdio (comando, argumentos, variables de entorno) o HTTP (URL, cabeceras). El catálogo SHALL ser visible para los usuarios como el conjunto de servidores MCP disponibles para sus agentes.

#### Scenario: Add stdio server
- **WHEN** el admin define un servidor MCP stdio con su comando
- **THEN** aparece en el catálogo y los usuarios pueden ofrecerlo a sus agentes

### Requirement: Per-agent connect and disconnect
El usuario SHALL poder conectar o desconectar a sus agentes cualquier servidor MCP del catálogo en cualquier momento. El cambio SHALL surtir efecto en el agente en ejecución sin perder su sesión.

#### Scenario: Connect
- **WHEN** un usuario conecta un servidor MCP a un agente
- **THEN** las herramientas de ese servidor quedan disponibles para el agente a partir de la siguiente interacción

#### Scenario: Disconnect
- **WHEN** un usuario desconecta un servidor MCP de un agente
- **THEN** las herramientas de ese servidor dejan de estar disponibles para el agente a partir de la siguiente interacción

### Requirement: MCP secrets handling
Los valores secretos de los servidores MCP (variables de entorno, cabeceras) SHALL almacenarse en el orquestador, entregarse a la configuración o entorno del agente, y mostrarse enmascarados en todas las interfaces.

#### Scenario: Masked secret
- **WHEN** un usuario ve la configuración de un servidor MCP
- **THEN** los valores secretos aparecen enmascarados

### Requirement: stdio server execution environment
Los servidores MCP stdio SHALL ejecutarse dentro del contenedor del agente. Las imágenes de agente de la v1.0 SHALL incluir los runtimes necesarios para los servidores stdio (Node.js y Python).

#### Scenario: npx server runs
- **WHEN** un agente conecta un servidor MCP stdio basado en npx
- **THEN** el servidor arranca dentro del contenedor del agente y sus herramientas son utilizables

### Requirement: Per-agent MCP status
El sistema SHALL informar, por agente, del estado de conexión de cada servidor MCP configurado (conectado, fallo, número de herramientas descubiertas), incluyendo el error cuando un servidor falla al arrancar.

#### Scenario: Failed server
- **WHEN** un servidor MCP conectado falla al arrancar
- **THEN** el usuario ve el fallo y su causa en la pantalla de MCP del agente
