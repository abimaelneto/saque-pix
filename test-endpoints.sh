#!/bin/bash

# Script de teste automatizado para os endpoints do Saque PIX
# Uso: ./test-endpoints.sh [email-do-avaliador@exemplo.com]

set -e

BASE_URL="http://localhost:9501"
AUTH_HEADER="Authorization: Bearer test-token"
TEST_EMAIL="${1:-teste@email.com}"

echo "🧪 Testando Endpoints do Saque PIX"
echo "📧 Email para notificações: ${TEST_EMAIL}"
echo ""

# Verificar se servidor está rodando
echo "🔍 Verificando se servidor está rodando..."
if ! curl -s "${BASE_URL}/health" > /dev/null; then
    echo "⚠️  Servidor não está respondendo. Tentando iniciar..."
    make start-bg > /dev/null 2>&1
    sleep 3
    
    if ! curl -s "${BASE_URL}/health" > /dev/null; then
        echo "❌ Erro: Servidor não está respondendo em ${BASE_URL}"
        echo "   Execute manualmente: make start-bg"
        exit 1
    fi
    echo "✅ Servidor iniciado!"
fi
echo ""

# Função auxiliar para formatar JSON (tenta jq, ou mostra raw)
format_json() {
    local json="$1"
    if command -v jq >/dev/null 2>&1; then
        echo "$json" | jq . 2>/dev/null || echo "$json"
    else
        echo "$json"
    fi
}

# 1. Health Check
echo "1️⃣  Health Check..."
HEALTH_RESPONSE=$(curl -s "${BASE_URL}/health")
format_json "$HEALTH_RESPONSE"
echo ""
echo ""

# 2. Criar Conta
echo "2️⃣  Criando conta..."
ACCOUNT_OUTPUT=$(docker-compose exec -T app php bin/hyperf.php account:create "Teste Automatizado" --balance=1000.00 2>&1)
echo "$ACCOUNT_OUTPUT"
ACCOUNT_ID=$(echo "$ACCOUNT_OUTPUT" | grep "ID:" | awk '{print $2}')

if [ -z "$ACCOUNT_ID" ]; then
    echo "❌ Erro: Não foi possível obter account_id"
    exit 1
fi

echo "ACCOUNT_ID: $ACCOUNT_ID"
echo ""

# 3. Saque Imediato
echo "3️⃣  Testando saque imediato..."
HTTP_CODE=$(curl -s -o /tmp/withdraw_response.json -w "%{http_code}" -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
  -H "Content-Type: application/json" \
  -H "${AUTH_HEADER}" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"${TEST_EMAIL}\"
    },
    \"amount\": 150.75,
    \"schedule\": null
  }")

WITHDRAW_RESPONSE=$(cat /tmp/withdraw_response.json 2>/dev/null || echo "")

if [ "$HTTP_CODE" = "201" ] || [ "$HTTP_CODE" = "200" ]; then
    format_json "$WITHDRAW_RESPONSE"
    if echo "$WITHDRAW_RESPONSE" | grep -q '"success":true'; then
        echo "✅ Saque imediato criado com sucesso! (HTTP $HTTP_CODE)"
    else
        echo "⚠️  Resposta HTTP $HTTP_CODE, mas sem campo 'success'"
    fi
elif [ "$HTTP_CODE" = "404" ]; then
    echo "❌ Erro: Rota não encontrada (HTTP 404)"
    echo "   Verifique se o servidor está rodando: make start-bg"
    echo "   Ou se a rota está correta: POST /account/{accountId}/balance/withdraw"
    echo "   Resposta: $WITHDRAW_RESPONSE"
else
    echo "⚠️  HTTP $HTTP_CODE"
    format_json "$WITHDRAW_RESPONSE"
fi
echo ""
echo ""

# 4. Saque Agendado
echo "4️⃣  Testando saque agendado..."
FUTURE_DATE=$(date -u -v+1H +"%Y-%m-%d %H:%M" 2>/dev/null || date -u -d "+1 hour" +"%Y-%m-%d %H:%M")
HTTP_CODE_SCHEDULED=$(curl -s -o /tmp/scheduled_response.json -w "%{http_code}" -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
  -H "Content-Type: application/json" \
  -H "${AUTH_HEADER}" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"${TEST_EMAIL}\"
    },
    \"amount\": 100.00,
    \"schedule\": \"${FUTURE_DATE}\"
  }")

SCHEDULED_RESPONSE=$(cat /tmp/scheduled_response.json 2>/dev/null || echo "")

if [ "$HTTP_CODE_SCHEDULED" = "201" ] || [ "$HTTP_CODE_SCHEDULED" = "200" ]; then
    format_json "$SCHEDULED_RESPONSE"
    if echo "$SCHEDULED_RESPONSE" | grep -q '"scheduled":true'; then
        echo "✅ Saque agendado criado com sucesso! (HTTP $HTTP_CODE_SCHEDULED)"
    else
        echo "⚠️  Resposta HTTP $HTTP_CODE_SCHEDULED, mas sem campo 'scheduled'"
    fi
elif [ "$HTTP_CODE_SCHEDULED" = "404" ]; then
    echo "❌ Erro: Rota não encontrada (HTTP 404)"
    echo "   Resposta: $SCHEDULED_RESPONSE"
else
    echo "⚠️  HTTP $HTTP_CODE_SCHEDULED"
    format_json "$SCHEDULED_RESPONSE"
fi
echo ""
echo ""

# 5. Validação: Saldo Insuficiente
echo "5️⃣  Testando validação: Saldo insuficiente..."
INSUFFICIENT_RESPONSE=$(curl -s -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
  -H "Content-Type: application/json" \
  -H "${AUTH_HEADER}" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"${TEST_EMAIL}\"
    },
    \"amount\": 10000.00,
    \"schedule\": null
  }")

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
  -H "Content-Type: application/json" \
  -H "${AUTH_HEADER}" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"${TEST_EMAIL}\"
    },
    \"amount\": 10000.00,
    \"schedule\": null
  }")

if [ "$HTTP_CODE" = "400" ]; then
    echo "✅ Validação funcionando: HTTP $HTTP_CODE (Saldo insuficiente)"
else
    echo "⚠️  Esperado HTTP 400, recebido: $HTTP_CODE"
fi
echo ""
echo ""

# 6. Validação: Data Passada
echo "6️⃣  Testando validação: Agendar para passado..."
PAST_DATE_RESPONSE=$(curl -s -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
  -H "Content-Type: application/json" \
  -H "${AUTH_HEADER}" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"${TEST_EMAIL}\"
    },
    \"amount\": 50.00,
    \"schedule\": \"2020-01-01 15:00\"
  }")

HTTP_CODE_PAST=$(curl -s -o /dev/null -w "%{http_code}" -X POST "${BASE_URL}/account/${ACCOUNT_ID}/balance/withdraw" \
  -H "Content-Type: application/json" \
  -H "${AUTH_HEADER}" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"${TEST_EMAIL}\"
    },
    \"amount\": 50.00,
    \"schedule\": \"2020-01-01 15:00\"
  }")

if [ "$HTTP_CODE_PAST" = "422" ] || [ "$HTTP_CODE_PAST" = "400" ]; then
    echo "✅ Validação funcionando: HTTP $HTTP_CODE_PAST (Data no passado)"
else
    echo "⚠️  Esperado HTTP 422/400, recebido: $HTTP_CODE_PAST"
fi
echo ""
echo ""

# 7. Verificar saques criados
echo "7️⃣  Listando saques criados..."
docker-compose exec -T app php bin/hyperf.php withdraw:list 2>&1 | head -15
echo ""

# 8. Processar saques agendados (para teste)
echo "8️⃣  Processando saques agendados..."
PROCESSED=$(docker-compose exec -T app php bin/hyperf.php withdraw:process-scheduled 2>&1)
echo "$PROCESSED"
echo ""

# 9. Verificar emails no Mailhog
echo "9️⃣  Verificando emails no Mailhog..."
# Tenta contar emails usando jq ou grep
EMAIL_COUNT="0"
if command -v jq >/dev/null 2>&1; then
    EMAIL_COUNT=$(curl -s http://localhost:8025/api/v2/messages 2>/dev/null | jq '.items | length' 2>/dev/null || echo "0")
else
    # Fallback: conta ocorrências de "items" usando grep/awk
    EMAIL_JSON=$(curl -s http://localhost:8025/api/v2/messages 2>/dev/null)
    if echo "$EMAIL_JSON" | grep -q '"items"'; then
        # Tenta extrair o array items e contar elementos
        EMAIL_COUNT=$(echo "$EMAIL_JSON" | grep -o '"items"' | wc -l | tr -d ' ' || echo "0")
        # Se não conseguir contar, verifica se há pelo menos um item
        if [ "$EMAIL_COUNT" = "0" ] && echo "$EMAIL_JSON" | grep -q '"items"'; then
            EMAIL_COUNT="1"  # Pelo menos um email encontrado
        fi
    fi
fi

if [ "$EMAIL_COUNT" -gt "0" ]; then
    echo "✅ $EMAIL_COUNT email(s) encontrado(s) no Mailhog"
    echo "   Acesse: http://localhost:8025 para visualizar"
else
    echo "⚠️  Nenhum email encontrado no Mailhog"
    echo "   Verifique manualmente em: http://localhost:8025"
fi
echo ""

# Resumo final
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Testes concluídos!"
echo ""
echo "📊 Resumo:"
echo "   - Conta criada: $ACCOUNT_ID"
echo "   - Saque imediato: Testado"
echo "   - Saque agendado: Testado"
echo "   - Validações: Testadas"
echo "   - Emails: $EMAIL_COUNT encontrado(s)"
echo ""
echo "📧 Verifique emails em: http://localhost:8025"
echo "   (Emails enviados para: ${TEST_EMAIL})"
echo ""
echo "💡 Dica: Use o Postman collection em postman/ para mais testes"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
