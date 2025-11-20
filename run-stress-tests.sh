#!/bin/bash

# Script para executar testes de stress
# Uso: ./run-stress-tests.sh

set -e

echo "🚀 Executando testes de stress e performance..."
echo ""

# Verificar se está no container ou local
if [ -f "/.dockerenv" ] || [ -n "$DOCKER_CONTAINER" ]; then
    # Dentro do container
    PHPUNIT="vendor/bin/phpunit"
else
    # Fora do container, usar docker-compose
    echo "Executando via Docker..."
    docker-compose exec -T app vendor/bin/phpunit --testsuite=Stress --testdox
    exit $?
fi

# Executar testes
$PHPUNIT --testsuite=Stress --testdox --verbose

echo ""
echo "✅ Testes de stress concluídos!"

