.PHONY: help build up down install start test test-unit test-integration test-stress clean logs

help: ## Mostra esta mensagem de ajuda
	@echo "Comandos disponíveis:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build das imagens Docker
	docker-compose build

up: ## Iniciar containers
	docker-compose up -d

down: ## Parar containers
	docker-compose down

install: ## Instalar dependências
	docker-compose exec app composer install

migrate: ## Executar migrations do banco de dados
	docker-compose exec app php database/migrate.php

setup: build up install migrate ## Setup completo (build + up + install + migrate)

start: ## Iniciar servidor Hyperf
	docker-compose exec app php bin/hyperf.php start

test: ## Executar todos os testes
	docker-compose exec app composer test

test-unit: ## Executar testes unitários
	docker-compose exec app composer test:unit

test-integration: ## Executar testes de integração
	docker-compose exec app composer test:integration

test-stress: ## Executar stress tests
	docker-compose exec app composer test:stress

clean: ## Limpar containers e volumes
	docker-compose down -v

logs: ## Ver logs dos containers
	docker-compose logs -f

# Comandos para simular Lambda localmente
lambda-http: ## Simular requisição HTTP Lambda (uso: make lambda-http METHOD=GET PATH=/)
	@docker-compose exec app php local-lambda.php http $(METHOD) $(PATH) $(BODY)

lambda-cron: ## Simular evento cron Lambda
	@docker-compose exec app php local-lambda.php cron

lambda-help: ## Ajuda para comandos Lambda
	@docker-compose exec app php local-lambda.php help
