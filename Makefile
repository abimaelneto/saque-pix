.PHONY: help build up down restart logs shell install migrate test

help: ## Mostra esta mensagem de ajuda
	@echo "Comandos disponíveis:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

build: ## Constrói as imagens Docker
	docker-compose build

up: ## Inicia os containers
	docker-compose up -d

down: ## Para os containers
	docker-compose down

restart: ## Reinicia os containers
	docker-compose restart

logs: ## Mostra os logs dos containers
	docker-compose logs -f

shell: ## Abre shell no container da aplicação
	docker-compose exec app bash

install: ## Instala as dependências do Composer
	docker-compose exec app composer install

migrate: ## Executa as migrations
	docker-compose exec app php bin/hyperf.php migrate

start: ## Inicia o servidor Hyperf
	docker-compose exec app php bin/hyperf.php start

test: ## Executa os testes
	docker-compose exec app composer test

setup: build up install ## Setup completo: build, up e install

