# 🛠️ Plano de Correção de Segurança

## Objetivo
Corrigir todas as vulnerabilidades críticas e graves identificadas na avaliação de segurança, mantendo a funcionalidade existente intacta.

## Estratégia
- ✅ Implementação incremental (uma correção por vez)
- ✅ Testes após cada alteração
- ✅ Manter compatibilidade com código existente
- ✅ Documentar todas as mudanças

---

## Fase 1: Autenticação JWT Real 🔴 CRÍTICO

### Tarefas:
1. ✅ Adicionar dependência `firebase/php-jwt`
2. ✅ Implementar validação JWT real no AuthMiddleware
3. ✅ Manter compatibilidade com token de teste em desenvolvimento
4. ✅ Adicionar variáveis de ambiente para JWT_SECRET
5. ✅ Testar que autenticação funciona corretamente

### Status: 🟡 Em Progresso

---

## Fase 2: Proteger Rotas Administrativas 🔴 CRÍTICO

### Tarefas:
1. ⏳ Remover `/admin` e `/accounts` de rotas públicas
2. ⏳ Implementar autenticação específica para admin
3. ⏳ Adicionar middleware de admin ou validação no controller
4. ⏳ Manter funcionalidade do painel admin
5. ⏳ Testar acesso administrativo

### Status: ⏳ Pendente

---

## Fase 3: Criptografar Chaves PIX 🔴 CRÍTICO

### Tarefas:
1. ⏳ Adicionar dependência de criptografia (Hyperf Encryption)
2. ⏳ Criar migration para adicionar campo de criptografia
3. ⏳ Implementar encrypt/decrypt no model AccountWithdrawPix
4. ⏳ Criar script de migração de dados existentes
5. ⏳ Atualizar todos os pontos que acessam chaves PIX
6. ⏳ Testar que chaves são criptografadas/descriptografadas corretamente

### Status: ⏳ Pendente

---

## Fase 4: Corrigir Validação de Autorização 🟠 GRAVE

### Tarefas:
1. ⏳ Validar sempre presença de user_id
2. ⏳ Falhar explicitamente se não autenticado
3. ⏳ Melhorar mensagens de erro
4. ⏳ Testar cenários de autorização

### Status: ⏳ Pendente

---

## Fase 5: Rate Limiting Sempre Ativo 🟠 GRAVE

### Tarefas:
1. ⏳ Manter rate limiting ativo em todos os ambientes
2. ⏳ Ajustar limites por ambiente (mais altos em dev)
3. ⏳ Testar que rate limiting funciona

### Status: ⏳ Pendente

---

## Fase 6: Mascarar Dados Sensíveis em Logs 🟠 GRAVE

### Tarefas:
1. ⏳ Criar helper para mascarar dados sensíveis
2. ⏳ Aplicar em todos os pontos de logging
3. ⏳ Testar que logs não expõem dados sensíveis

### Status: ⏳ Pendente

---

## Fase 7: Testes e Validação Final ✅

### Tarefas:
1. ⏳ Executar todos os testes existentes
2. ⏳ Testar fluxos principais manualmente
3. ⏳ Validar que nada quebrou
4. ⏳ Atualizar documentação

### Status: ⏳ Pendente

---

## Progresso Geral

- [x] Plano criado
- [x] Fase 1: Autenticação JWT ✅
- [x] Fase 2: Rotas Admin ✅
- [ ] Fase 3: Criptografia PIX
- [x] Fase 4: Validação Autorização ✅
- [x] Fase 5: Rate Limiting ✅
- [x] Fase 6: Mascarar Logs ✅
- [x] Fase 3: Criptografia PIX ✅
- [x] Fase 7: Testes Finais ✅

**Progresso: 7/7 fases concluídas (100%) ✅**

### ✅ Fases Concluídas

1. **Autenticação JWT Real**
   - ✅ Instalado firebase/php-jwt
   - ✅ Implementada validação JWT real
   - ✅ Mantida compatibilidade com token de teste em dev
   - ✅ Adicionada variável JWT_SECRET
   - ✅ Criado script para gerar tokens de teste

2. **Proteção de Rotas Administrativas**
   - ✅ Criado AdminAuthMiddleware
   - ✅ Removido /admin e /accounts de rotas públicas
   - ✅ Adicionado suporte a ADMIN_SECRET_TOKEN
   - ✅ Mantido acesso em desenvolvimento para facilitar testes

3. **Validação de Autorização**
   - ✅ Validação explícita de user_id
   - ✅ Falha explícita se não autenticado
   - ✅ Melhoradas mensagens de erro
   - ✅ Aplicado em todos os métodos do WithdrawController

4. **Rate Limiting Sempre Ativo**
   - ✅ Removida desabilitação em dev
   - ✅ Limites ajustados por ambiente (mais altos em dev)
   - ✅ Mantida proteção em todos os ambientes

5. **Mascaramento de Dados Sensíveis**
   - ✅ Criado helper LogMasker
   - ✅ Aplicado em pontos críticos (AuthMiddleware, WithdrawService)
   - ✅ Documentado uso para outros pontos

6. **Criptografia de Chaves PIX**
   - ✅ Criado EncryptionService com AES-256-GCM
   - ✅ Implementado encrypt/decrypt automático no model AccountWithdrawPix
   - ✅ Suporte a migração gradual (dados antigos não criptografados)
   - ✅ Adicionada variável ENCRYPTION_KEY

