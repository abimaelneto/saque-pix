.PHONY: help build up down install start test test-unit test-integration test-stress clean logs dev dev-clean restart clear-cache

help: ## Mostra esta mensagem de ajuda
	@echo "Comandos disponíveis:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build das imagens Docker
	docker-compose build

up: ## Iniciar containers essenciais (sem Prometheus/Grafana)
	docker-compose up -d mysql redis mailhog app

up-all: ## Iniciar todos os containers (incluindo Prometheus/Grafana)
	docker-compose --profile observability up -d

down: ## Parar containers
	docker-compose down

install: ## Instalar dependências
	docker-compose exec app composer install

migrate: ## Executar migrations do banco de dados
	docker-compose exec app php bin/hyperf.php migrate

seed: ## Popular banco de dados com dados de exemplo
	docker-compose exec app php bin/hyperf.php db:seed

setup: build up install migrate ## Setup completo (build + up + install + migrate)
	@echo "⏳ Aguardando MySQL inicializar (30 segundos)..."
	@sleep 30
	@echo "🚀 Iniciando servidor..."
	@$(MAKE) start-bg
	@echo ""
	@echo "✅ Setup completo!"
	@echo "📡 Servidor rodando em http://localhost:9501"
	@echo "📧 Mailhog em http://localhost:8025"
	@echo ""
	@echo "🧪 Para testar, veja o README.md"

start: ## Iniciar servidor Hyperf (foreground)
	docker-compose exec app php bin/hyperf.php start

start-bg: ## Iniciar servidor Hyperf em background
	docker-compose exec -d app php bin/hyperf.php start

dev: ## Iniciar servidor em modo desenvolvimento com hot reload (usando hyperf/watcher)
	@echo "🔥 Iniciando modo desenvolvimento com hot reload..."
	@echo "📝 Usando hyperf/watcher (pacote oficial)"
	@echo "📁 Monitorando: app/ e config/"
	@echo "🛑 Pressione Ctrl+C para parar"
	@echo ""
	@docker-compose exec app bash -c "if [ -f /var/www/runtime/hyperf.pid ]; then PID=\$$(cat /var/www/runtime/hyperf.pid 2>/dev/null); if [ ! -z \"\$$PID\" ] && kill -0 \"\$$PID\" 2>/dev/null; then echo '🛑 Parando processo anterior...'; kill \"\$$PID\" 2>/dev/null; sleep 1; fi; fi; php bin/hyperf.php server:watch"

dev-legacy: ## Iniciar servidor em modo desenvolvimento com hot reload (script customizado)
	@echo "🔥 Iniciando modo desenvolvimento com hot reload (legacy)..."
	@echo "📝 O servidor será reiniciado automaticamente a cada mudança de código"
	@echo "📁 Monitorando: app/ e config/"
	@echo "🛑 Pressione Ctrl+C para parar"
	@echo ""
	@docker-compose exec -T app bash /var/www/docker/watch-simple.sh || true

dev-clean: ## Limpar cache e iniciar em modo desenvolvimento
	docker-compose exec app rm -rf /var/www/runtime/container/* 2>/dev/null || true
	@$(MAKE) dev

restart: ## Reiniciar servidor (limpa cache e reinicia)
	@echo "🔄 Reiniciando servidor..."
	docker-compose exec app bash -c "rm -rf /var/www/runtime/container/* 2>/dev/null; pkill -f 'hyperf.php start' || true; sleep 1; php bin/hyperf.php start > /dev/null 2>&1 &"
	@echo "✅ Servidor reiniciado!"

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

clear-cache: ## Limpar cache do Hyperf
	docker-compose exec app rm -rf /var/www/runtime/container/* 2>/dev/null || true
	@echo "✅ Cache limpo!"
