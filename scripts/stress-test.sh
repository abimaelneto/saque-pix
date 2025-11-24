#!/bin/bash

# Script de Stress Testing para Saque PIX API
# Ferramenta: Apache Bench (ab) - simples e eficiente
# Alternativas: k6, wrk, Artillery

set -e

BASE_URL="${BASE_URL:-http://localhost:9501}"
AUTH_TOKEN="${AUTH_TOKEN:-Bearer test-token}"
ACCOUNT_ID="${ACCOUNT_ID:-}"
EMAIL="${EMAIL:-test@example.com}"

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "🔥 Stress Testing - Saque PIX API"
echo "=================================="
echo ""

# Verificar se ab está instalado
if ! command -v ab &> /dev/null; then
    echo -e "${RED}❌ Apache Bench (ab) não está instalado${NC}"
    echo "Instale com: brew install httpd (macOS) ou apt-get install apache2-utils (Linux)"
    exit 1
fi

# Verificar se servidor está rodando
if ! curl -s "${BASE_URL}/health" > /dev/null; then
    echo -e "${RED}❌ Servidor não está respondendo em ${BASE_URL}${NC}"
    echo "Inicie o servidor com: make dev ou make start-bg"
    exit 1
fi

echo -e "${GREEN}✅ Servidor está respondendo${NC}"
echo ""

# Criar conta se não tiver ACCOUNT_ID
if [ -z "$ACCOUNT_ID" ]; then
    echo "📝 Criando conta de teste..."
    ACCOUNT_RESPONSE=$(curl -s -X POST "${BASE_URL}/accounts" \
        -H "Content-Type: application/json" \
        -d '{"name":"Stress Test Account","balance":"100000.00"}')
    
    ACCOUNT_ID=$(echo "$ACCOUNT_RESPONSE" | grep -o '"id":"[^"]*' | cut -d'"' -f4)
    
    if [ -z "$ACCOUNT_ID" ]; then
        echo -e "${RED}❌ Falha ao criar conta${NC}"
        echo "$ACCOUNT_RESPONSE"
        exit 1
    fi
    
    echo -e "${GREEN}✅ Conta criada: ${ACCOUNT_ID}${NC}"
    echo ""
fi

# Preparar arquivo temporário para requisições
TMP_DIR=$(mktemp -d)
TMP_FILE="${TMP_DIR}/withdraw_request.json"

cat > "$TMP_FILE" <<EOF
{
  "method": "PIX",
  "pix": {
    "type": "email",
    "key": "${EMAIL}"
  },
  "amount": 10.00,
  "schedule": null
}
EOF

echo "📊 Iniciando testes de stress..."
echo ""

# Teste 1: Health Check (baseline)
echo -e "${YELLOW}1. Health Check (baseline)${NC}"
echo "   Requisições: 1000, Concorrência: 10"
ab -n 1000 -c 10 "${BASE_URL}/health" | grep -E "Requests per second|Time per request|Failed requests"
echo ""

# Teste 2: Listar Contas
echo -e "${YELLOW}2. GET /accounts (listar)${NC}"
echo "   Requisições: 500, Concorrência: 5"
ab -n 500 -c 5 "${BASE_URL}/accounts" | grep -E "Requests per second|Time per request|Failed requests"
echo ""

# Teste 3: Criar Saque (endpoint crítico)
echo -e "${YELLOW}3. POST /account/{id}/balance/withdraw (criar saque)${NC}"
echo "   Requisições: 100, Concorrência: 5"
echo "   ⚠️  Nota: Este teste cria saques reais. Use com cuidado!"
ab -n 100 -c 5 -p "$TMP_FILE" -T "application/json" -H "Authorization: ${AUTH_TOKEN}" \
    "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" | grep -E "Requests per second|Time per request|Failed requests"
echo ""

# Teste 4: Alta concorrência (simulação de pico)
echo -e "${YELLOW}4. Health Check (alta concorrência)${NC}"
echo "   Requisições: 5000, Concorrência: 50"
ab -n 5000 -c 50 "${BASE_URL}/health" | grep -E "Requests per second|Time per request|Failed requests"
echo ""

# Limpar
rm -rf "$TMP_DIR"

echo -e "${GREEN}✅ Stress testing concluído!${NC}"
echo ""
echo "📈 Métricas coletadas acima"
echo "💡 Para análise detalhada, use ferramentas como k6 ou Artillery"

