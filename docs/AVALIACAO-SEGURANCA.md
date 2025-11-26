# 🔒 Avaliação de Segurança - Saque PIX API

**Data:** 2024  
**Avaliador:** Especialista em Cibersegurança  
**Tipo:** Análise de Segurança para Case de Dev Senior - Banco Digital

---

## 📋 Sumário Executivo

Esta avaliação identifica **vulnerabilidades críticas e graves** que comprometem a segurança da aplicação de saque PIX. Embora a solução tenha implementado várias boas práticas (rate limiting, detecção de fraude, locks distribuídos), existem **brechas críticas** que tornam o sistema vulnerável a ataques em produção.

### Nível de Risco Geral: 🔴 **ALTO**

---

## 🚨 VULNERABILIDADES CRÍTICAS

### 1. **Autenticação JWT Não Implementada** ⚠️ **CRÍTICO**

**Localização:** `app/Middleware/AuthMiddleware.php:59-79`

**Problema:**
- A validação de token JWT está **completamente desabilitada** em produção
- Aceita apenas token hardcoded `'test-token'` em ambiente local
- Em produção, **qualquer requisição com header Authorization é rejeitada**, mas o código tem um TODO indicando que não está implementado

**Impacto:**
- ❌ **Sem autenticação real** - sistema não valida identidade dos usuários
- ❌ Qualquer pessoa pode criar saques se descobrir como contornar o middleware
- ❌ Violação de princípios de segurança bancária

**Código Problemático:**
```php
private function validateToken(string $token): ?array
{
    // Em produção, implementar validação JWT real
    // Por enquanto, aceita token de teste para desenvolvimento
    if ($token === 'test-token' && env('APP_ENV') === 'local') {
        return [
            'user_id' => 'test-user',
            'account_id' => null,
        ];
    }
    
    // TODO: Implementar validação JWT real
    return null; // ❌ SEMPRE RETORNA NULL EM PRODUÇÃO
}
```

**Recomendação:**
```php
// Implementar com firebase/php-jwt ou similar
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

private function validateToken(string $token): ?array
{
    try {
        $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        return [
            'user_id' => $decoded->sub ?? $decoded->user_id,
            'account_id' => $decoded->account_id ?? null,
        ];
    } catch (\Exception $e) {
        $this->logger->warning('JWT validation failed', ['error' => $e->getMessage()]);
        return null;
    }
}
```

---

### 2. **Rotas Administrativas Sem Autenticação** ⚠️ **CRÍTICO**

**Localização:** `app/Middleware/AuthMiddleware.php:24`, `app/Controller/AdminController.php`

**Problema:**
- Rotas `/admin` e `/accounts` são **públicas** (linha 24 do AuthMiddleware)
- Painel administrativo permite:
  - Criar contas com saldo arbitrário
  - Visualizar todos os saques com chaves PIX expostas
  - Processar saques agendados manualmente
  - Modificar dados de saques agendados

**Impacto:**
- ❌ **Acesso não autorizado** a dados sensíveis (chaves PIX, saldos, histórico)
- ❌ **Manipulação de saldos** - criar contas com valores altos
- ❌ **Exposição de dados** de todos os usuários
- ❌ Violação de LGPD/GDPR

**Código Problemático:**
```php
$publicPaths = ['/health', '/metrics', '/metrics/json', '/admin', '/accounts'];
// ❌ /admin e /accounts são públicos!
```

**Recomendação:**
```php
$publicPaths = ['/health', '/metrics', '/metrics/json']; // Remover /admin e /accounts

// Adicionar autenticação específica para admin
if (str_starts_with($path, '/admin')) {
    $adminToken = $request->getHeaderLine('X-Admin-Token');
    if ($adminToken !== env('ADMIN_SECRET_TOKEN')) {
        return $this->unauthorizedResponse('Admin access denied');
    }
}
```

---

### 3. **Chaves PIX Armazenadas em Texto Plano** ⚠️ **CRÍTICO**

**Localização:** `app/Model/AccountWithdrawPix.php`, `database/migrations/`

**Problema:**
- Chaves PIX (emails) são armazenadas **sem criptografia** no banco de dados
- Expostas em endpoints administrativos e logs
- Violação de boas práticas de segurança para dados sensíveis

**Impacto:**
- ❌ **Exposição de dados pessoais** (LGPD/GDPR)
- ❌ Se banco for comprometido, todas as chaves PIX ficam expostas
- ❌ Possibilidade de reutilização fraudulenta das chaves

**Recomendação:**
```php
// Usar criptografia AES-256
use Hyperf\Encryption\Encrypter;

protected function setKeyAttribute($value)
{
    $this->attributes['key'] = app(Encrypter::class)->encrypt($value);
}

protected function getKeyAttribute($value)
{
    return app(Encrypter::class)->decrypt($value);
}
```

---

### 4. **Validação de Autorização Inconsistente** ⚠️ **GRAVE**

**Localização:** `app/Controller/WithdrawController.php:33-48`

**Problema:**
- A verificação de autorização depende de `$userAccountId` estar presente no request
- Se `account_id` não vier do token JWT (que não está implementado), a validação **falha silenciosamente**
- Permite acesso a qualquer conta se o middleware não funcionar corretamente

**Código Problemático:**
```php
$userAccountId = $request->getAttribute('account_id');
$userId = $request->getAttribute('user_id');

if ($userAccountId && $userAccountId !== $accountId) {
    // ❌ Se $userAccountId for null, esta verificação é ignorada!
    return $response->json([...])->withStatus(403);
}
```

**Recomendação:**
```php
// Validar que usuário está autenticado
if (!$userId) {
    return $response->json([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Authentication required',
    ])->withStatus(401);
}

// Validar que usuário tem acesso à conta
$userAccountId = $request->getAttribute('account_id');
if (!$userAccountId || $userAccountId !== $accountId) {
    $this->auditService->logUnauthorizedAccess(...);
    return $response->json([...])->withStatus(403);
}
```

---

## ⚠️ VULNERABILIDADES GRAVES

### 5. **Rate Limiting Desabilitado em Desenvolvimento** ⚠️ **GRAVE**

**Localização:** `app/Middleware/RateLimitMiddleware.php:35-37`

**Problema:**
- Rate limiting é **completamente desabilitado** em ambientes `local` e `testing`
- Permite ataques de força bruta e DDoS em desenvolvimento
- Pode ser esquecido ao fazer deploy

**Recomendação:**
```php
// Manter rate limiting sempre ativo, mas com limites mais altos em dev
if (env('APP_ENV') === 'testing' || env('APP_ENV') === 'local') {
    $limit = $limit * 100; // Aumentar limite, mas não desabilitar
}
```

---

### 6. **Exposição de Informações Sensíveis em Logs** ⚠️ **GRAVE**

**Localização:** Vários arquivos de Service

**Problema:**
- Logs podem conter:
  - Chaves PIX completas
  - Saldos de contas
  - Tokens de idempotência
  - Dados de auditoria sensíveis

**Recomendação:**
```php
// Mascarar dados sensíveis nos logs
private function maskSensitiveData(array $data): array
{
    $sensitive = ['pix_key', 'key', 'account_id', 'amount'];
    foreach ($sensitive as $field) {
        if (isset($data[$field])) {
            $data[$field] = substr($data[$field], 0, 4) . '***';
        }
    }
    return $data;
}
```

---

### 7. **Falta de Validação de CSRF para Operações Críticas** ⚠️ **MÉDIO**

**Problema:**
- API REST não implementa proteção CSRF
- Embora menos crítico para APIs REST puras, ainda é uma boa prática

**Recomendação:**
- Implementar tokens CSRF para operações de escrita
- Ou usar SameSite cookies se houver interface web

---

### 8. **SQL Injection - Uso de Raw Queries** ⚠️ **MÉDIO**

**Localização:** `app/Repository/AccountRepository.php:40-50`

**Problema:**
- Uso de `Db::update()` com raw SQL
- Embora use prepared statements (parâmetros `?`), há risco se mal implementado

**Código Atual (Relativamente Seguro):**
```php
$result = Db::update("
    UPDATE account 
    SET balance = balance - ?,
        updated_at = NOW()
    WHERE id = ? 
    AND balance >= ?
", [$amount, $accountId, $amount]);
```

**Recomendação:**
- Manter uso de prepared statements (já está correto)
- Adicionar validação de tipos antes da query
- Considerar usar Query Builder do Eloquent para maior segurança

---

### 9. **Falta de Validação de Input em AdminController** ⚠️ **GRAVE**

**Localização:** `app/Controller/AdminController.php:166-193`

**Problema:**
- Método `updateScheduledForPast()` executa SQL direto sem validação
- Permite manipulação de dados de saques agendados

**Código Problemático:**
```php
public function updateScheduledForPast(ResponseInterface $response): PsrResponseInterface
{
    // ❌ SQL direto sem validação
    $updated = Db::statement("
        UPDATE account_withdraw 
        SET scheduled_for = DATE_SUB(NOW(), INTERVAL 1 HOUR)
        WHERE scheduled = TRUE AND done = FALSE
    ");
}
```

**Recomendação:**
- Remover este endpoint ou protegê-lo com autenticação forte
- Adicionar validação e logging de todas as operações administrativas

---

### 10. **Falta de Criptografia em Trânsito (HTTPS)** ⚠️ **CRÍTICO**

**Problema:**
- Não há configuração explícita de HTTPS
- Dados sensíveis trafegam em texto plano se não houver proxy reverso com SSL

**Recomendação:**
- Configurar HTTPS no nginx/Apache
- Forçar redirecionamento HTTP → HTTPS
- Implementar HSTS (já parcialmente implementado no SecurityHeadersMiddleware)

---

## ✅ PONTOS POSITIVOS

A solução implementa várias boas práticas:

1. ✅ **Proteção contra Race Conditions:**
   - Locks distribuídos (Redis)
   - Locks pessimistas (SELECT FOR UPDATE)
   - Operações atômicas SQL

2. ✅ **Rate Limiting:**
   - Implementado com Redis
   - Limites diferenciados por endpoint

3. ✅ **Detecção de Fraude:**
   - Limites de saques por hora/dia
   - Detecção de padrões suspeitos

4. ✅ **Idempotência:**
   - Suporte a idempotency keys
   - Previne duplicação de transações

5. ✅ **Auditoria:**
   - Logging de operações críticas
   - Rastreamento de tentativas não autorizadas

6. ✅ **Security Headers:**
   - CSP, X-Frame-Options, HSTS
   - Headers de segurança HTTP

7. ✅ **Validação de Input:**
   - Validação de dados de entrada
   - Sanitização de inputs

---

## 📊 MATRIZ DE RISCO

| Vulnerabilidade | Severidade | Probabilidade | Impacto | Prioridade |
|----------------|------------|---------------|---------|------------|
| JWT Não Implementado | Crítica | Alta | Crítico | 🔴 P0 |
| Rotas Admin Públicas | Crítica | Alta | Crítico | 🔴 P0 |
| Chaves PIX em Texto | Crítica | Média | Alto | 🔴 P0 |
| Autorização Inconsistente | Grave | Alta | Alto | 🟠 P1 |
| Rate Limit Desabilitado | Grave | Média | Médio | 🟠 P1 |
| Exposição em Logs | Grave | Média | Alto | 🟠 P1 |
| SQL Injection (Potencial) | Média | Baixa | Médio | 🟡 P2 |
| Falta de CSRF | Média | Baixa | Baixo | 🟡 P2 |

---

## 🛠️ PLANO DE CORREÇÃO PRIORITÁRIO

### Fase 1 - Crítico (Imediato) 🔴

1. **Implementar autenticação JWT real**
   - Instalar `firebase/php-jwt`
   - Configurar secret key em variáveis de ambiente
   - Implementar validação completa

2. **Proteger rotas administrativas**
   - Remover `/admin` e `/accounts` de rotas públicas
   - Implementar autenticação específica para admin
   - Adicionar rate limiting mais restritivo

3. **Criptografar chaves PIX**
   - Implementar criptografia AES-256
   - Migrar dados existentes
   - Atualizar todos os pontos de acesso

### Fase 2 - Grave (Curto Prazo) 🟠

4. **Corrigir validação de autorização**
   - Validar sempre presença de `user_id`
   - Falhar explicitamente se não autenticado

5. **Habilitar rate limiting sempre**
   - Manter ativo em todos os ambientes
   - Ajustar limites por ambiente

6. **Mascarar dados sensíveis em logs**
   - Implementar função de mascaramento
   - Aplicar em todos os pontos de logging

### Fase 3 - Melhorias (Médio Prazo) 🟡

7. **Revisar queries SQL**
   - Migrar para Query Builder onde possível
   - Adicionar validação de tipos

8. **Implementar CSRF protection**
   - Para endpoints que precisarem
   - Considerar SameSite cookies

9. **Configurar HTTPS obrigatório**
   - Configurar certificados SSL
   - Forçar redirecionamento HTTP → HTTPS

---

## 📝 RECOMENDAÇÕES ADICIONAIS

### Segurança de Banco de Dados

1. **Credenciais em Variáveis de Ambiente:**
   - ✅ Já implementado corretamente
   - ⚠️ Garantir que `.env` não seja commitado

2. **Conexões Seguras:**
   - Implementar SSL/TLS para conexões MySQL
   - Usar usuários com privilégios mínimos

3. **Backup Seguro:**
   - Criptografar backups
   - Testar restauração regularmente

### Monitoramento e Resposta

1. **SIEM/SOC:**
   - Integrar logs com sistema de monitoramento
   - Alertas para tentativas de acesso não autorizado

2. **Penetration Testing:**
   - Realizar testes de penetração regulares
   - Bug bounty program (opcional)

3. **Incident Response:**
   - Plano de resposta a incidentes
   - Equipe de segurança 24/7

### Compliance

1. **LGPD/GDPR:**
   - Criptografar dados pessoais
   - Implementar direito ao esquecimento
   - Política de privacidade clara

2. **PCI DSS (se aplicável):**
   - Se processar cartões, seguir padrões PCI
   - Não armazenar dados de cartão

---

## 🎯 CONCLUSÃO

A solução demonstra **conhecimento técnico sólido** em várias áreas (race conditions, idempotência, arquitetura), mas possui **vulnerabilidades críticas de segurança** que a tornam **inadequada para produção** em um ambiente bancário real.

### Principais Gaps:

1. ❌ **Autenticação não funcional** - maior vulnerabilidade
2. ❌ **Rotas administrativas expostas** - risco de acesso não autorizado
3. ❌ **Dados sensíveis não criptografados** - violação de privacidade

### Recomendação Final:

**NÃO APROVAR para produção** sem corrigir as vulnerabilidades críticas (Fase 1). Após correções, realizar nova avaliação e testes de penetração.

---

**Avaliação realizada por:** Especialista em Cibersegurança  
**Data:** 2024  
**Versão:** 1.0


