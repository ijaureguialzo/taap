# TAAP - The Agentic AI Playground
# Uso: make <objetivo>  (desde la raíz del repo)

COMPOSE = docker compose -f deploy/compose.yaml
DEPLOY_ENV = deploy/.env

.PHONY: help env build up seed down ps logs test lint images shell clean

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

env: ## Crea deploy/.env a partir del ejemplo si no existe
	@test -f $(DEPLOY_ENV) || cp deploy/env.example $(DEPLOY_ENV)
	@echo "Revisa deploy/.env (APP_KEY, contraseñas)."

build: ## Construye las imágenes (orquestador + egress)
	$(COMPOSE) build

up: ## Arranca la pila en segundo plano
	$(COMPOSE) up -d

seed: ## Siembra la BD (solo 1ª vez: admin + proveedor/modelo de ejemplo)
	$(COMPOSE) exec -T orchestrator php artisan db:seed --force

down: ## Para y elimina la pila (los volúmenes de agentes persisten)
	$(COMPOSE) down

ps: ## Estado de la pila
	$(COMPOSE) ps

logs: ## Logs del orquestador
	$(COMPOSE) logs -f orchestrator

test: ## Tests del orquestador (PHP + JS)
	cd orchestrator && php artisan test
	cd orchestrator && npm run build >/dev/null

lint: ## Pint (PHP) + build de assets
	cd orchestrator && ./vendor/bin/pint --test
	cd orchestrator && npm run build >/dev/null

images: ## Construye las imágenes de agentes (Pi, Hermes, Claude, Codex, OpenCode)
	docker build -t taap/agent-pi:local agents/pi
	docker build -t taap/agent-hermes:local agents/hermes
	docker build -t taap/agent-claude:local agents/claude
	docker build -t taap/agent-codex:local agents/codex
	docker build -t taap/agent-opencode:local agents/opencode

shell: ## Shell en el orquestador
	$(COMPOSE) exec orchestrator sh

clean: ## Para la pila y elimina volúmenes (¡BORRA DATOS!)
	$(COMPOSE) down -v
