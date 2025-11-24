#!/bin/bash

# Script para gerar carga e visualizar métricas em tempo real no Grafana
# Uso: ./scripts/generate-load.sh [account_id] [email]

set -e

BASE_URL="${BASE_URL:-http://localhost:9501}"
AUTH_TOKEN="${AUTH_TOKEN:-Bearer test-token}"
ACCOUNT_ID="${1:-}"
EMAIL="${2:-test@example.com}"

# Cores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}🔥 Gerador de Carga - Saque PIX${NC}"
echo "=================================="
echo ""

# Verificar se servidor está rodando
if ! curl -s "${BASE_URL}/health" > /dev/null; then
    echo -e "${YELLOW}⚠️  Servidor não está respondendo. Iniciando...${NC}"
    make start-bg
    sleep 5
fi

# Criar conta se não tiver ACCOUNT_ID
if [ -z "$ACCOUNT_ID" ]; then
    echo -e "${YELLOW}📝 Criando conta de teste...${NC}"
    ACCOUNT_RESPONSE=$(curl -s -X POST "${BASE_URL}/accounts" \
        -H "Content-Type: application/json" \
        -d "{\"name\":\"Load Test Account\",\"balance\":\"100000.00\"}")
    
    ACCOUNT_ID=$(echo "$ACCOUNT_RESPONSE" | grep -o '"id":"[^"]*' | cut -d'"' -f4)
    
    if [ -z "$ACCOUNT_ID" ]; then
        echo "❌ Falha ao criar conta"
        echo "$ACCOUNT_RESPONSE"
        exit 1
    fi
    
    echo -e "${GREEN}✅ Conta criada: ${ACCOUNT_ID}${NC}"
    echo ""
fi

echo -e "${BLUE}📊 Iniciando geração de carga...${NC}"
echo "   URL: ${BASE_URL}"
echo "   Conta: ${ACCOUNT_ID}"
echo "   Email: ${EMAIL}"
echo ""
echo -e "${YELLOW}💡 Abra o Grafana em: http://localhost:3001${NC}"
echo -e "${YELLOW}   Dashboard: 'Saque PIX - Observabilidade'${NC}"
echo ""
echo "Pressione Ctrl+C para parar"
echo ""

# Contador
COUNT=0

# Loop infinito gerando requisições
while true; do
    COUNT=$((COUNT + 1))
    
    # Alternar entre saque imediato e agendado
    if [ $((COUNT % 2)) -eq 0 ]; then
        # Saque agendado (para 1 hora no futuro)
        FUTURE_DATE=$(date -u -v+1H +"%Y-%m-%d %H:%M" 2>/dev/null || date -u -d "+1 hour" +"%Y-%m-%d %H:%M")
        SCHEDULE="\"${FUTURE_DATE}\""
        TYPE="agendado"
    else
        # Saque imediato
        SCHEDULE="null"
        TYPE="imediato"
    fi
    
    # Valor aleatório entre 10 e 100
    AMOUNT=$(awk "BEGIN {printf \"%.2f\", $RANDOM/327.68 + 10}")
    
    # Fazer requisição
    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
        -H "Content-Type: application/json" \
        -H "Authorization: ${AUTH_TOKEN}" \
        -d "{
            \"method\": \"PIX\",
            \"pix\": {
                \"type\": \"email\",
                \"key\": \"${EMAIL}\"
            },
            \"amount\": ${AMOUNT},
            \"schedule\": ${SCHEDULE}
        }")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    BODY=$(echo "$RESPONSE" | sed '$d')
    
    if [ "$HTTP_CODE" = "201" ]; then
        echo -e "${GREEN}✅ [${COUNT}] Saque ${TYPE} criado: R$ ${AMOUNT}${NC}"
    else
        echo -e "${YELLOW}⚠️  [${COUNT}] HTTP ${HTTP_CODE}${NC}"
    fi
    
    # Aguardar 1 segundo entre requisições
    sleep 1
done

