# 💰 Saque PIX - Plataforma de Conta Digital

API para realizar saques PIX de contas digitais, desenvolvida com **PHP Hyperf 3**, **Docker**, **MySQL 8** e **Mailhog**.

## 🚀 Início Rápido

### Pré-requisitos
- Docker & Docker Compose instalados

**💡 Nota:** O projeto funciona "out of the box" sem configurações adicionais. Todas as variáveis de ambiente têm valores padrão. Se precisar personalizar, veja `ENV-VARIABLES.md`.

### Setup Completo (1 comando)

```bash
make setup
```

Este comando faz tudo automaticamente:
1. Build das imagens Docker
2. Inicia containers (MySQL, Redis, Mailhog, App, **Prometheus, Grafana**)
3. Instala dependências
4. Executa migrations
5. Aguarda MySQL inicializar
6. Inicia servidor em background
7. **Inicia Cron Job em foreground** (para acompanhar processamentos em tempo real)

**⏱️ Tempo: ~2-3 minutos**

**Servidor:** `http://localhost:9501`  
**Admin UI:** `http://localhost:9501/admin` ⭐ **Interface web completa para gerenciar o sistema**  
**Mailhog:** `http://localhost:8025`
**Prometheus:** `http://localhost:9091`  
**Grafana:** `http://localhost:3001` (usuário: `admin`, senha: `admin`)

**⏰ Cron Job:** O cron job de saques agendados roda automaticamente no terminal, processando saques a cada minuto. Você verá logs em tempo real como:
```
[2024-01-15 10:30:00] ⏰ Executando cron job...
⏰ [CRON] Processing scheduled withdraws...
✅ [CRON] Processed 2 scheduled withdraw(s).
```

**⚠️ Importante:** 
- Todas as requisições precisam do header `Authorization: Bearer test-token` (token de teste para desenvolvimento)
- O cron job roda no terminal após o setup - pressione `Ctrl+C` para parar
- O servidor continua rodando em background mesmo se você parar o cron

### 🔥 Modo Desenvolvimento (Hot Reload)

Para desenvolvimento com reinício automático a cada mudança de código:

```bash
make dev
```

Este comando usa o pacote oficial **hyperf/watcher** e:
- ✅ Monitora mudanças em arquivos PHP automaticamente
- ✅ Limpa cache automaticamente antes de iniciar
- ✅ Reinicia servidor a cada mudança
- ✅ Mostra logs no terminal em tempo real
- ✅ Usa driver nativo do Hyperf (mais eficiente)
- ✅ **Cron job ativo**: Processa saques agendados automaticamente a cada minuto
- ✅ Ideal para desenvolvimento ativo

**⏰ Cron Job de Saques Agendados:**

**IMPORTANTE:** 
- Com `make setup`: O cron job roda automaticamente em foreground (você vê os logs no terminal)
- Com `make start-bg` ou `make start`: O cron job roda automaticamente em background (via Hyperf Crontab)
- Com `make dev`: O cron job **NÃO roda automaticamente** (server:watch não suporta crontab)

**Opções para rodar o cron durante desenvolvimento:**

1. **Usar `make dev-with-cron`** (recomendado para desenvolvimento):
   ```bash
   make dev-with-cron
   ```
   Isso inicia o servidor em background e o cron em foreground. Você verá logs do cron a cada minuto.

2. **Rodar cron em terminal separado:**
   ```bash
   # Terminal 1: Servidor
   make dev
   
   # Terminal 2: Cron job
   bash scripts/run-cron.sh
   ```

3. **Usar `make start-bg`** (cron roda automaticamente em background):
   ```bash
   make start-bg
   # O cron roda automaticamente a cada minuto (sem logs no terminal)
   ```

**Logs do cron:**
Quando o cron está rodando em foreground, você verá mensagens como:
```
[2024-01-15 10:30:00] ⏰ Executando cron job...
⏰ [CRON] Processing scheduled withdraws...
✅ [CRON] Processed 3 scheduled withdraw(s).
```

**⚠️ ImportANTE**: Se fizer mudanças em middlewares ou configurações, use:
```bash
make restart  # Limpa cache e reinicia servidor
```

**Comandos alternativos:**
```bash
make restart     # Reinicia servidor limpando cache manualmente
make dev-legacy  # Usa script customizado (fallback)
```

---

## 🔄 Reset Completo (Para Testar do Zero)

Se você precisa resetar completamente o ambiente (como um avaliador testando pela primeira vez), use:

### Reset Rápido (1 comando)

```bash
make reset
```

Este comando:
- ✅ Para todos os containers
- ✅ Remove todos os volumes (incluindo dados do MySQL)
- ✅ Remove containers órfãos
- ✅ Limpa containers k6 temporários
- ✅ Remove volumes do MySQL e Redis

**Depois do reset, execute:**
```bash
make setup
```

### Reset Manual (Passo a Passo)

Se preferir fazer manualmente:

```bash
# 1. Parar e remover containers e volumes
docker-compose down -v

# 2. Remover containers k6 temporários (se houver)
docker ps -a --filter "name=k6" --format "{{.ID}}" | xargs -r docker rm -f

# 3. Remover volumes órfãos (opcional, mas recomendado)
docker volume prune -f

# 4. Reconstruir e iniciar do zero
make setup
```

### Verificar se Resetou

Após o reset, você pode verificar se está tudo limpo:

```bash
# Verificar containers
docker ps -a | grep saque-pix

# Verificar volumes
docker volume ls | grep saque-pix

# Se estiver tudo limpo, execute:
make setup
```

---

## 🎛️ Interface Administrativa (Admin UI)

A aplicação inclui uma **interface web completa** para gerenciar contas, saques e visualizar métricas do sistema.

### Acessar a Interface Admin

**URL:** http://localhost:9501/admin

A interface está disponível automaticamente após o `make setup`. Não requer autenticação adicional (apenas o servidor precisa estar rodando).

### Funcionalidades Disponíveis

A interface admin possui **4 abas principais**:

#### 1. 📊 Dashboard (Visão Geral)
- **Estatísticas Gerais:**
  - Total de contas cadastradas
  - Total de saques (processados, pendentes, com erro)
  - Valores totais sacados
  - Taxa de sucesso
- **Links Rápidos:**
  - Mailhog (visualizar emails)
  - Grafana (métricas avançadas)
  - Prometheus (queries diretas)
  - Health Check
  - Métricas em JSON

#### 2. 👥 Contas
- **Criar Nova Conta:**
  - Formulário simples com nome e saldo inicial
  - Validação em tempo real
  - Feedback visual de sucesso/erro
- **Listar Contas:**
  - Tabela com todas as contas (até 50 mais recentes)
  - Mostra: ID, Nome, Saldo, Data de criação
  - Botão para atualizar lista

#### 3. 💰 Saques
- **Listar Todos os Saques:**
  - Visualização completa de todos os saques do sistema
  - Filtros por status (processados, pendentes, erros)
  - Informações detalhadas: valor, data, status, dados PIX
- **Saques Agendados Pendentes:**
  - Contador de saques agendados aguardando processamento
  - Botão para processar manualmente
  - Atualização em tempo real

#### 4. ⚙️ Ações Administrativas
- **Processar Saques Agendados:**
  - Botão para processar manualmente todos os saques agendados pendentes
  - Mostra quantos foram processados
  - Útil para testes sem esperar o cron job
- **Atualizar Saques para Passado (Teste):**
  - ⚠️ **Apenas para testes**
  - Atualiza saques agendados para 1 hora no passado
  - Permite testar processamento imediato sem esperar
- **Ver Métricas:**
  - Métricas do sistema em formato JSON
  - Performance, contadores, estatísticas
- **Ver Estatísticas:**
  - Resumo completo do sistema
  - Totais, médias, percentuais

### Como Usar

1. **Acesse:** http://localhost:9501/admin
2. **Crie uma conta:** Aba "Contas" → Preencha nome e saldo → Clique em "Criar Conta"
3. **Visualize saques:** Aba "Saques" → Veja todos os saques criados
4. **Processe saques agendados:** Aba "Saques" → Clique em "Processar Saques Agendados"
5. **Veja métricas:** Aba "Dashboard" → Links para métricas e estatísticas

### API Admin (Endpoints REST)

A interface admin também expõe endpoints REST para integração:

```bash
# Listar contas
GET /admin/api/accounts

# Criar conta
POST /admin/api/accounts
{
  "name": "João Silva",
  "balance": 1000.00
}

# Listar saques
GET /admin/api/withdraws

# Saques pendentes
GET /admin/api/withdraws/pending

# Processar saques agendados
POST /admin/api/process-scheduled

# Métricas
GET /admin/api/metrics

# Estatísticas
GET /admin/api/stats
```

**💡 Dica:** Todos esses endpoints também estão disponíveis na collection do Postman na seção "6. Admin & Observabilidade".

### Recursos Visuais

- ✅ Interface responsiva (funciona em desktop e mobile)
- ✅ Atualização em tempo real (sem necessidade de refresh)
- ✅ Feedback visual para todas as ações
- ✅ Tabelas organizadas e fáceis de ler
- ✅ Links rápidos para ferramentas externas (Mailhog, Grafana, etc.)

---

## 🧪 Testando os Requisitos

### Opção 1: Script Automatizado (Recomendado)

```bash
# Com seu email (receberá notificações dos saques)
./test-endpoints.sh seu-email@exemplo.com

# Ou sem email (usa padrão)
./test-endpoints.sh
```

O script testa automaticamente:
- ✅ Health check
- ✅ Criação de conta
- ✅ Saque imediato
- ✅ Saque agendado
- ✅ Validações (saldo insuficiente, data passada)
- ✅ Verificação de emails no Mailhog

**Nota:** Se o script mostrar erro 404, verifique se o servidor está rodando:
```bash
make start-bg
sleep 3
./test-endpoints.sh seu-email@exemplo.com
```

### Opção 2: Postman Collection

Importe `postman/Saque-PIX-API.postman_collection.json` no Postman para testes interativos.

### Opção 3: Testes Manuais

Siga os testes manuais abaixo:

### 1. Criar Conta

```bash
docker-compose exec app php bin/hyperf.php account:create "João Silva" --balance=1000.00
```

**Copie o `account_id` retornado** (ex: `550e8400-e29b-41d4-a716-446655440000`)

---

### 2. Saque Imediato ✅

```bash
# Substitua {accountId} pelo ID copiado acima
ACCOUNT_ID="550e8400-e29b-41d4-a716-446655440000"

curl -X POST http://localhost:9501/account/${ACCOUNT_ID}/balance/withdraw \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-token" \
  -d '{
    "method": "PIX",
    "pix": {
      "type": "email",
      "key": "joao@email.com"
    },
    "amount": 150.75,
    "schedule": null
  }'
```


**✅ Verificações:**
- Resposta HTTP 201
- Campo `"done": true`
- Email no Mailhog: http://localhost:8025

---

### 3. Saque Agendado ✅

```bash
# Agendar para 1 hora no futuro
FUTURE_DATE=$(date -u -v+1H +"%Y-%m-%d %H:%M" 2>/dev/null || date -u -d "+1 hour" +"%Y-%m-%d %H:%M")

curl -X POST http://localhost:9501/account/${ACCOUNT_ID}/balance/withdraw \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-token" \
  -d "{
    \"method\": \"PIX\",
    \"pix\": {
      \"type\": \"email\",
      \"key\": \"joao@email.com\"
    },
    \"amount\": 100.00,
    \"schedule\": \"${FUTURE_DATE}\"
  }"
```

**✅ Verificações:**
- Resposta HTTP 201
- Campo `"scheduled": true`
- Campo `"done": false` (será processado pelo cron)

**⏰ Processamento Automático (Cron Job):**

O Hyperf executa automaticamente um **cron job a cada minuto** que processa todos os saques agendados cuja data/hora já passou. O cron está **sempre ativo** quando o servidor está rodando (incluindo `make dev`).

**Verificar se o cron está funcionando:**
```bash
# Ver logs do servidor (o cron mostra mensagens a cada execução)
docker-compose logs -f app | grep -i "scheduled\|cron"

# Ou verificar diretamente no terminal onde roda `make dev`
# Você verá mensagens como: "Processing scheduled withdraws..."
```

**Processar manualmente (para teste imediato):**
```bash
# Processa todos os saques agendados pendentes imediatamente
make process-scheduled

# Ou via endpoint admin (mais fácil para testes)
curl -X POST http://localhost:9501/admin/api/process-scheduled
```

**💡 Dica para Teste Rápido:**
1. Crie um saque agendado para 1 minuto no futuro
2. Aguarde 1 minuto (o cron roda automaticamente)
3. Verifique que o saque foi processado (`done: true`)
4. Ou use o comando manual acima para processar imediatamente

**🧪 Scripts de Teste Automatizados:**

Teste de saques agendados (cria saques para o minuto seguinte):
```bash
make test-scheduled
# Depois execute: make process-scheduled
```

Teste de saques imediatos (verifica se são processados automaticamente):
```bash
make test-immediate
```

Atualizar saques agendados para o passado (para testar processamento imediato):
```bash
curl -X POST http://localhost:9501/admin/api/update-scheduled-for-past
make process-scheduled
```

---

### 4. Validações de Negócio ✅

#### Não permite sacar mais que o saldo:
```bash
curl -X POST http://localhost:9501/account/${ACCOUNT_ID}/balance/withdraw \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-token" \
  -d '{
    "method": "PIX",
    "pix": {"type": "email", "key": "teste@email.com"},
    "amount": 10000.00,
    "schedule": null
  }'
```
**✅ Resultado:** HTTP 400 - "Insufficient balance"

#### Não permite agendar para passado:
```bash
curl -X POST http://localhost:9501/account/${ACCOUNT_ID}/balance/withdraw \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-token" \
  -d '{
    "method": "PIX",
    "pix": {"type": "email", "key": "teste@email.com"},
    "amount": 50.00,
    "schedule": "2020-01-01 15:00"
  }'
```
**✅ Resultado:** HTTP 422 - Erro de validação

---

## 📋 Endpoint

```http
POST /account/{accountId}/balance/withdraw
Content-Type: application/json
Authorization: Bearer test-token

{
  "method": "PIX",
  "pix": {
    "type": "email",
    "key": "fulano@email.com"
  },
  "amount": 150.75,
  "schedule": null  // null = imediato, "2026-01-01 15:00" = agendado
}
```

**Nota:** Para desenvolvimento local, use o token `test-token` no header Authorization.

---

## ✅ Requisitos Implementados

- ✅ Endpoint `POST /account/{accountId}/balance/withdraw`
- ✅ Saque imediato processa na hora
- ✅ Saque agendado processado via cron (a cada minuto)
- ✅ Email enviado após saque (ver em http://localhost:8025)
- ✅ Validações: saldo insuficiente, data passada, etc.
- ✅ Registro no banco (tabelas `account_withdraw` e `account_withdraw_pix`)

---

## 🗄️ Estrutura do Banco

- `account`: id (uuid), name, balance
- `account_withdraw`: id, account_id, method, amount, scheduled, scheduled_for, done, error
- `account_withdraw_pix`: account_withdraw_id, type, key

---

## 📮 Postman Collection

Uma collection completa do Postman está disponível em `postman/Saque-PIX-API.postman_collection.json` com todos os testes organizados:

- ✅ Health check
- ✅ Saque imediato
- ✅ Saque agendado
- ✅ Todas as validações de negócio
- ✅ Casos de erro

**Importe no Postman e configure a variável `account_id` após criar uma conta.**

## 📝 Comandos Úteis

```bash
# Ver logs
docker-compose logs -f app

# Parar tudo
make down

# Reiniciar servidor
make start-bg

# Verificar status
curl http://localhost:9501/health
```

---

## 🐛 Problemas?

### Servidor não responde
```bash
make restart
sleep 3
curl http://localhost:9501/health
```

### Autenticação não funciona (retorna 200 ao invés de 401)
```bash
# Limpar cache e reiniciar
make restart
sleep 3

# Testar autenticação
curl -X POST http://localhost:9501/account/test-id/balance/withdraw \
  -H "Content-Type: application/json" \
  -d '{"method":"PIX","pix":{"type":"email","key":"test@test.com"},"amount":10}'
# Deve retornar 401 Unauthorized
```

### Porta em uso
Se as portas 9091 (Prometheus) ou 3001 (Grafana) estiverem em uso, você pode:
- Parar os containers: `docker-compose stop prometheus grafana`
- Ou alterar as portas no `docker-compose.yml`

**Nota:** Prometheus e Grafana são iniciados automaticamente no `make setup` para permitir observabilidade durante os testes de stress.

### MySQL não inicia
```bash
make clean
make setup
# O setup já inicia o servidor automaticamente
# Se precisar apenas do servidor sem o cron, use: make start-bg
```

### Grafana não acessível
```bash
# Verificar se está rodando
docker-compose ps grafana

# Se não estiver rodando, iniciar observabilidade
docker-compose --profile observability up -d prometheus grafana
sleep 10
curl http://localhost:3001/api/health
```

**Nota:** O `make setup` já inicia Prometheus e Grafana automaticamente.

---

---

## 📊 Observabilidade (Grafana + Prometheus)

**✅ Prometheus e Grafana são iniciados automaticamente no `make setup`**

### Iniciar Observabilidade Manualmente

Se você precisar iniciar apenas os serviços de observabilidade:

```bash
# Iniciar Prometheus e Grafana
docker-compose --profile observability up -d prometheus grafana

# Ou iniciar todos os serviços
make up-all
```

**Aguarde ~10 segundos** para os serviços iniciarem completamente.

### Acessar Grafana

- **URL**: http://localhost:3001
- **Usuário**: `admin`
- **Senha**: `admin`

**⚠️ Importante**: Altere a senha no primeiro login!

### Dashboard Automático

O dashboard **"Saque PIX - Observabilidade"** já está configurado automaticamente e aparece na lista de dashboards.

**Painéis simplificados (8 métricas essenciais):**
1. **Throughput HTTP (req/s)** - Total e endpoint de saque
2. **Status Codes HTTP (req/s)** - 2xx, 4xx, 5xx separados
3. **Saques Criados (últimos 5 min)** - Imediatos, Agendados, Erros
4. **Saques Processados (últimos 5 min)** - Sucesso vs Erro
5. **Tempo Médio de Resposta** - Latência do endpoint de saque
6. **Taxa de Sucesso (%)** - Percentual de saques bem-sucedidos
7. **Emails Enviados (últimos 5 min)** - Contador de notificações
8. **Erros de Saldo Insuficiente (últimos 5 min)** - Proteções de negócio

> **Nota:** O dashboard foi simplificado para focar apenas nas métricas que funcionam durante o stress test. Todas as queries foram testadas e atualizam em tempo real.

### Load Test de Alta Performance (1000 req/s por 60s)

**Teste completo e realista com duração de 60 segundos:**

Para testar o comportamento do servidor sob carga intensa:

```bash
# Load test: 1000 requisições/segundo durante 60 segundos
make load-test

# Stress test: Ondas de carga variável (mais realista)
make stress-test

# Ou com parâmetros customizados:
docker-compose exec app php scripts/load-test.php [account_id] [email]
```

**O que o load test faz:**
- ✅ Cria uma conta automaticamente (ou usa uma existente)
- ✅ Gera **1000 requisições por segundo** durante **60 segundos**
- ✅ Usa requisições concorrentes (até 200 simultâneas)
- ✅ Mostra estatísticas em tempo real (RPS, sucesso, erros)
- ✅ Exibe relatório final completo com códigos HTTP

**Stress test completo (ondas de carga variável):**

**Com k6 (recomendado):**
```bash
make stress-test-k6
```

**Com script PHP (atual):**
```bash
make stress-test
```

Este teste simula cenário real com 5 ondas de carga diferentes (500 → 1000 → 800 → 1200 → 600 req/s), mais realista para demonstração.

**Exemplo de saída:**
```
🔥 Load Test - Saque PIX API
==================================

🔍 Verificando servidor...
✅ Servidor está respondendo

📝 Criando conta de teste...
✅ Conta criada: 550e8400-e29b-41d4-a716-446655440000

📊 Iniciando Load Test
   URL: http://localhost:9501
   Target: 1000 req/s
   Duração: 5 segundos

🚀 Iniciando...

[1.0s] Total: 1002 | RPS: 1002.0 | Sucesso: 98.5% | Erros: 15
[2.0s] Total: 2005 | RPS: 1002.5 | Sucesso: 98.2% | Erros: 36
[3.0s] Total: 3008 | RPS: 1002.7 | Sucesso: 98.0% | Erros: 60
[4.0s] Total: 4010 | RPS: 1002.5 | Sucesso: 97.8% | Erros: 88
[5.0s] Total: 5012 | RPS: 1002.4 | Sucesso: 97.6%

==================================================
📊 Estatísticas Finais
==================================================
Tempo total: 5.00s
Total de requisições: 5012
Requisições por segundo (média): 1002.40 req/s
Taxa de sucesso: 97.60%
Sucessos: 4892
Erros: 120

```

**💡 Dica**: Execute o load test enquanto observa o Grafana em tempo real para ver como o servidor se comporta sob carga!

### Stress Test de Escalabilidade (ondas de carga)

Quando precisamos **provar** que o Hyperf está sustentando a escalabilidade horizontal exigida no `descricao-case.txt`, você pode usar:

#### Opção 1: k6 (⭐ Recomendado - Mais Performático)

```bash
# Stress test com k6 (recomendado)
make stress-test-k6

# Com parâmetros customizados
make stress-test-k6-custom EMAIL=test@email.com NUM_ACCOUNTS=20
```

**Nota:** Na primeira execução, o docker-compose baixará a imagem do k6 (~30MB). Nas próximas execuções, usará a imagem em cache.

**Vantagens do k6:**
- ✅ Mais performático (escrito em Go)
- ✅ Scripts em JavaScript (mais fácil de manter)
- ✅ Relatórios HTML automáticos
- ✅ Integração nativa com Prometheus/Grafana
- ✅ Melhor para CI/CD
- ✅ Usa a mesma rede Docker do projeto (não precisa baixar toda vez)

**Documentação completa:** Veja `docs/FERRAMENTAS-STRESS-TESTING.md`

#### Opção 2: Script PHP (Atual)

Use o stress test completo (script `scripts/stress-test-complete.php`). 

**Como funciona:**
- ✅ **Cria automaticamente 10 contas** com saldo de 50 milhões cada (distribui carga de forma realista)
- ✅ Roda durante 60 s com **800 conexões concorrentes** (permite 1000+ req/s)
- ✅ Alterna 80% de saques imediatos e 20% agendados
- ✅ Aplica ondas de carga realistas (500 → 1000 → 800 → 1200 → 600 req/s)
- ✅ Distribui requisições entre as contas (simula múltiplos usuários)

> **Nota**: O rate limiting foi desabilitado em ambiente local para permitir o stress test. Em produção, o rate limiting está ativo com limites apropriados.

**Uso:**
```bash
# Uso padrão (cria 10 contas automaticamente)
make stress-test

# Com parâmetros customizados
make stress-test ARGS="" "test@email.com" 60 20
# Parâmetros: [account_id] [email] [duration] [num_accounts]
# Se account_id for vazio (""), cria contas automaticamente
```

| Janela do teste (percentual do tempo total) | Alvo de RPS |
| --- | --- |
| 0 – 20 % | 500 req/s |
| 20 – 40 % | 1000 req/s |
| 40 – 60 % | 800 req/s |
| 60 – 80 % | 1200 req/s |
| 80 – 100 % | 600 req/s |

**Passo a passo para medir e observar:**

1. `make up-all` para iniciar Prometheus + Grafana (scrape de 1 s).
2. `make restart` (ou `make start-bg`) para garantir que o servidor esteja limpo.
3. `make verify-metrics` para verificar se as métricas estão sendo expostas corretamente.
4. `make stress-test` inicia o cenário completo. **O script cria automaticamente 10 contas** para distribuir a carga de forma realista.
   - **Uso padrão**: `make stress-test` (cria 10 contas automaticamente)
   - **Customizar número de contas**: `make stress-test ARGS="" "test@email.com" 60 20` (cria 20 contas)
   - **Usar conta específica**: `make stress-test ARGS="account-id-here" "test@email.com" 60` (não cria novas)
5. Abra o dashboard `Saque PIX - Observabilidade` no Grafana (`http://localhost:3001`) com range "Last 15 minutes".
6. Após o teste, execute `make verify-metrics` novamente para comparar os números.

**O que validar no Grafana durante o teste:**

1. **Throughput HTTP (req/s)**: Deve mostrar claramente as cinco ondas de carga (500 → 1000 → 800 → 1200 → 600 req/s). A linha "Withdraw Endpoint" deve acompanhar o padrão.
2. **Status Codes HTTP**: Durante o pico de 1200 req/s, você verá principalmente 2xx (sucesso), com alguns 4xx (esperados por saldo insuficiente). **Se ver muitos 4xx, verifique:**
   - Saldo da conta (deve ser 100 milhões)
   - Rate limiting desabilitado em local
   - Execute `make verify-metrics` para comparar com CLI
3. **Saques Criados**: Mostra o volume total criado nas últimas 5 min. Imediatos devem ser ~80% do total. **Se mostrar 0:**
   - Verifique se métricas estão sendo expostas: `curl http://localhost:9501/metrics | grep withdraws_created`
   - Reinicie Prometheus: `docker-compose restart prometheus`
4. **Saques Processados**: Confirma que os saques imediatos estão sendo processados em tempo real.
5. **Tempo Médio de Resposta**: Deve permanecer <0.4s durante todo o teste. Valores >0.5s indicam gargalo.
6. **Taxa de Sucesso**: Deve mostrar >97% durante o teste completo.
7. **Emails Enviados**: Confirma que as notificações estão sendo enviadas (um por saque processado).
8. **Erros de Saldo Insuficiente**: Mostra quantos saques foram bloqueados por falta de saldo (esperado durante o teste).

**⚠️ Se houver discrepância entre CLI e Grafana:**
- Execute `make verify-metrics` para ver métricas brutas
- Consulte `docs/TROUBLESHOOTING-METRICAS.md` para diagnóstico completo

Ao finalizar, o CLI imprime um resumo com média de RPS e distribuição de códigos HTTP. Use esse resultado junto com as capturas do Grafana para comprovar o requisito de escalabilidade do case. Para referência ou comparação histórica, o script legado em Bash (Apache Bench) segue acessível via `make stress-test-legacy`, mas não entrega as ondas nem métricas detalhadas.

### Gerar Carga Contínua (1 req/s)

Para gerar carga contínua e leve para visualização no Grafana:

```bash
# Carga contínua: 1 requisição por segundo
make load-test-continuous
```

Este script:
- ✅ Cria uma conta automaticamente
- ✅ Gera saques imediatos e agendados alternadamente
- ✅ Valores aleatórios entre R$ 10 e R$ 100
- ✅ 1 requisição por segundo
- ✅ Pressione Ctrl+C para parar

### Verificar Métricas da API

```bash
# Métricas em formato Prometheus
curl http://localhost:9501/metrics

# Métricas em JSON (mais legível)
curl http://localhost:9501/metrics/json
```

### Prometheus

- **URL**: http://localhost:9091
- **Query**: Use PromQL para consultar métricas diretamente

---

## 📚 Documentação

- **`docs/openapi.yaml`**: Especificação OpenAPI
- **`docs/OBSERVABILIDADE.md`**: Guia completo de observabilidade
- **`docs/ESCALABILIDADE.md`**: **Como a arquitetura suporta grandes cargas e escalabilidade horizontal**
- **`docs/TESTE-SAQUE-AGENDADO.md`**: **Guia completo para testar saques agendados e validar o cron job**
- **`docs/TROUBLESHOOTING-METRICAS.md`**: **Diagnóstico de problemas com métricas (CLI vs Grafana)**
- **`docs/FERRAMENTAS-STRESS-TESTING.md`**: **Análise de ferramentas de stress testing (k6, Artillery, etc.)**
- **`docs_ia/`**: Documentação técnica completa

---

## 📄 Licença

MIT
