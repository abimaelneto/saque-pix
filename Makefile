.PHONY: help build up down install start test test-unit test-integration test-stress clean reset logs dev dev-clean restart clear-cache check-docker

help: ## Mostra esta mensagem de ajuda
	@echo "Comandos disponíveis:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

check-docker: ## Verifica se Docker e Docker Compose estão instalados
	@command -v docker >/dev/null 2>&1 || { echo "❌ Erro: Docker não encontrado. Instale Docker primeiro."; exit 1; }
	@command -v docker-compose >/dev/null 2>&1 || { echo "❌ Erro: Docker Compose não encontrado. Instale Docker Compose primeiro."; exit 1; }
	@echo "✅ Docker e Docker Compose encontrados"

build: check-docker ## Build das imagens Docker
	docker-compose build

up: ## Iniciar containers essenciais (sem Prometheus/Grafana)
	docker-compose up -d mysql redis mailhog app

up-all: ## Iniciar todos os containers (incluindo Prometheus/Grafana)
	docker-compose --profile observability up -d

down: ## Parar containers
	docker-compose down

install: ## Instalar dependências
	docker-compose exec app composer install

wait-mysql: ## Aguardar MySQL estar pronto para conexões
	@echo "⏳ Aguardando MySQL estar pronto..."
	@timeout=60; \
	elapsed=0; \
	while [ $$elapsed -lt $$timeout ]; do \
		if docker-compose exec -T app php -r "try { \$$pdo = new PDO('mysql:host=mysql;port=3306', 'root', 'root', [PDO::ATTR_TIMEOUT => 2]); echo 'OK'; } catch (Exception \$e) { exit(1); }" >/dev/null 2>&1; then \
			echo "✅ MySQL está pronto!"; \
			sleep 2; \
			exit 0; \
		fi; \
		echo "   Aguardando MySQL... ($$elapsed/$$timeout segundos)"; \
		sleep 2; \
		elapsed=$$((elapsed + 2)); \
	done; \
	echo "❌ Timeout: MySQL não ficou pronto em $$timeout segundos"; \
	exit 1

migrate: wait-mysql ## Executar migrations do banco de dados
	docker-compose exec app php bin/hyperf.php migrate

seed: ## Popular banco de dados com dados de exemplo
	docker-compose exec app php bin/hyperf.php db:seed

setup: check-docker build up install migrate ## Setup completo (build + up + install + migrate)
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
	@echo "📋 Logs aparecerão no terminal"
	@echo "🛑 Pressione Ctrl+C para parar"
	@echo ""
	@docker-compose exec -T app bash -c "rm -rf /var/www/runtime/container/* 2>/dev/null || true; if [ -f /var/www/runtime/hyperf.pid ]; then PID=\$$(cat /var/www/runtime/hyperf.pid 2>/dev/null); if [ ! -z \"\$$PID\" ] && kill -0 \"\$$PID\" 2>/dev/null; then echo '🛑 Parando processo anterior...'; kill \"\$$PID\" 2>/dev/null; sleep 1; fi; fi; php bin/hyperf.php server:watch"

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
	@docker-compose exec -T app bash -c "rm -rf /var/www/runtime/container/* 2>/dev/null || true; if [ -f /var/www/runtime/hyperf.pid ]; then PID=\$$(cat /var/www/runtime/hyperf.pid 2>/dev/null); if [ ! -z \"\$$PID\" ] && kill -0 \"\$$PID\" 2>/dev/null; then kill \"\$$PID\" 2>/dev/null; sleep 1; fi; fi; php bin/hyperf.php start > /dev/null 2>&1 &"
	@sleep 3
	@echo "✅ Servidor reiniciado (cache limpo)"

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
	@echo "🧹 Limpando containers k6 temporários..."
	@docker ps -a --filter "name=k6" --format "{{.ID}}" | xargs -r docker rm -f 2>/dev/null || true

reset: ## Reset completo: remove containers, volumes, imagens e cache (para testar do zero)
	@echo "🔄 Reset completo do ambiente..."
	@echo "⚠️  Isso irá remover TODOS os containers, volumes e dados do banco!"
	@echo ""
	@docker-compose down -v --remove-orphans 2>/dev/null || true
	@echo "🧹 Limpando containers k6 temporários..."
	@docker ps -a --filter "name=k6" --format "{{.ID}}" | xargs -r docker rm -f 2>/dev/null || true
	@echo "🧹 Limpando containers órfãos..."
	@docker ps -a --filter "name=saque-pix" --format "{{.ID}}" | xargs -r docker rm -f 2>/dev/null || true
	@echo "🗑️  Removendo volumes órfãos..."
	@docker volume ls --filter "name=saque-pix" -q | xargs -r docker volume rm 2>/dev/null || true
	@echo "🗑️  Removendo volumes do MySQL..."
	@docker volume ls --filter "name=mysql" -q | xargs -r docker volume rm 2>/dev/null || true
	@echo "🗑️  Removendo volumes do Redis..."
	@docker volume ls --filter "name=redis" -q | xargs -r docker volume rm 2>/dev/null || true
	@echo "✅ Reset completo! Execute 'make setup' para iniciar novamente."

logs: ## Ver logs dos containers
	docker-compose logs -f

clear-cache: ## Limpar cache do Hyperf
	docker-compose exec app rm -rf /var/www/runtime/container/* 2>/dev/null || true
	@echo "✅ Cache limpo!"

process-scheduled: ## Processar saques agendados manualmente (para testes)
	@echo "⏰ Processando saques agendados pendentes..."
	@docker-compose exec app php bin/hyperf.php withdraw:process-scheduled

test-scheduled: ## Testar saques agendados (cria saques para o minuto seguinte)
	@echo "🧪 Testando saques agendados..."
	@docker-compose exec app php scripts/test-scheduled-withdraws.php

test-immediate: ## Testar saques imediatos (verifica se são processados automaticamente)
	@echo "🧪 Testando saques imediatos..."
	@docker-compose exec app php scripts/test-immediate-withdraws.php

dev-with-cron: ## Iniciar servidor em modo dev + cron job em paralelo (2 terminais)
	@echo "🔥 Iniciando servidor + cron job..."
	@echo "📝 Este comando inicia o servidor em background e o cron em foreground"
	@echo "💡 Para ver logs do servidor, use: docker-compose logs -f app"
	@echo ""
	@docker-compose exec -d app bash -c "rm -rf /var/www/runtime/container/* 2>/dev/null || true; php bin/hyperf.php start"
	@sleep 3
	@echo "✅ Servidor iniciado em background"
	@echo "⏰ Iniciando cron job (pressione Ctrl+C para parar)..."
	@echo ""
	@bash scripts/run-cron.sh

load-test: ## Gerar carga de 1000 req/s por 60 segundos (load test completo)
	@echo "🔥 Iniciando Load Test - 1000 req/s por 60 segundos..."
	@echo "💡 Abra o Grafana em http://localhost:3001 para ver métricas em tempo real"
	@echo ""
	@docker-compose exec -T app php scripts/load-test.php $(ARGS) 60

stress-test: ## Stress test completo com ondas de carga variável (60 segundos, cria 10 contas automaticamente)
	@echo "🔥 Iniciando Stress Test Completo - Ondas de carga variável..."
	@echo "💡 Abra o Grafana em http://localhost:3001 para ver métricas em tempo real"
	@echo "💡 O script criará 10 contas automaticamente para distribuir a carga"
	@echo ""
	@echo "Uso: make stress-test [ou com ARGS=\"account_id email duration num_accounts\"]"
	@echo "Exemplo: make stress-test ARGS=\"\" \"test@email.com\" 60 20"
	@echo ""
	@docker-compose exec -T app php scripts/stress-test-complete.php $(ARGS)

load-test-continuous: ## Gerar carga contínua para visualizar no Grafana (1 req/s)
	@echo "🔥 Gerando carga contínua (1 req/s)..."
	@echo "💡 Abra o Grafana em http://localhost:3001 para ver métricas em tempo real"
	@echo ""
	@bash scripts/generate-load.sh

stress-test-k6: ## Stress test usando k6 (recomendado - mais performático)
	@echo "🔥 Iniciando Stress Test com k6..."
	@echo "💡 Abra o Grafana em http://localhost:3001 para ver métricas em tempo real"
	@echo "💡 O script criará 10 contas automaticamente para distribuir a carga"
	@echo ""
	@echo "🔍 Verificando se servidor está rodando..."
	@if ! curl -s http://localhost:9501/health > /dev/null 2>&1; then \
		echo "⚠️  Servidor não está respondendo. Iniciando servidor..."; \
		$(MAKE) start-bg > /dev/null 2>&1; \
		echo "⏳ Aguardando servidor inicializar (10 segundos)..."; \
		sleep 10; \
		for i in 1 2 3 4 5; do \
			if curl -s http://localhost:9501/health > /dev/null 2>&1; then \
				echo "✅ Servidor está respondendo!"; \
				break; \
			fi; \
			echo "   Tentativa $$i/5..."; \
			sleep 2; \
		done; \
		if ! curl -s http://localhost:9501/health > /dev/null 2>&1; then \
			echo "❌ Erro: Servidor não está respondendo após tentativas"; \
			echo "   Execute manualmente: make start-bg"; \
			echo "   E aguarde alguns segundos antes de executar o teste novamente"; \
			exit 1; \
		fi; \
	else \
		echo "✅ Servidor está respondendo!"; \
	fi
	@echo ""
	@echo "🚀 Executando k6 (container temporário será removido ao final)..."
	@docker-compose run --rm --name saque-pix-k6-temp \
		-e BASE_URL=http://app:9501 \
		-e AUTH_TOKEN="Bearer test-token" \
		-e EMAIL=stress-test@example.com \
		-e NUM_ACCOUNTS=10 \
		k6 run /scripts/k6-stress-test.js || ( \
			echo ""; \
			echo "⚠️  Limpando container temporário..."; \
			docker rm -f saque-pix-k6-temp 2>/dev/null || true; \
			exit 1; \
		)

stress-test-k6-custom: ## Stress test k6 com parâmetros customizados
	@echo "🔥 Stress Test k6 - Parâmetros customizados"
	@echo "Uso: make stress-test-k6-custom EMAIL=test@email.com NUM_ACCOUNTS=20"
	@echo ""
	@docker-compose run --rm --name saque-pix-k6-temp \
		-e BASE_URL=http://app:9501 \
		-e AUTH_TOKEN="Bearer test-token" \
		-e EMAIL=$(EMAIL) \
		-e NUM_ACCOUNTS=$(NUM_ACCOUNTS) \
		k6 run /scripts/k6-stress-test.js || ( \
			echo ""; \
			echo "⚠️  Limpando container temporário..."; \
			docker rm -f saque-pix-k6-temp 2>/dev/null || true; \
			exit 1; \
		)

stress-test-legacy: ## Stress testing básico via Apache Bench (script antigo)
	@echo "⚠️  Executando stress testing legado (Apache Bench)..."
	@echo "💡 Prefira 'make stress-test' ou 'make stress-test-k6' para o cenário completo"
	@echo ""
	@bash scripts/stress-test.sh

verify-metrics: ## Verificar se as métricas do Prometheus estão corretas
	@echo "🔍 Verificando métricas do Prometheus..."
	@docker-compose exec -T app php scripts/verify-metrics.php
