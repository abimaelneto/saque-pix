<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Account;
use App\Model\AccountWithdraw;
use App\Repository\AccountRepository;
use App\Repository\AccountWithdrawRepository;
use App\Service\MetricsService;
use App\Service\WithdrawService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

// Rotas definidas em config/routes.php
class AdminController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private AccountWithdrawRepository $withdrawRepository,
        private WithdrawService $withdrawService,
        private MetricsService $metricsService,
        private ValidatorFactoryInterface $validatorFactory,
    ) {
    }

    public function index(ResponseInterface $response): PsrResponseInterface
    {
        try {
            $html = $this->getAdminHtml();
            return $response->html($html);
        } catch (\Throwable $e) {
            $errorHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><h1>Erro</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
            return $response->html($errorHtml)->withStatus(500);
        }
    }

    
    public function getAccounts(ResponseInterface $response): PsrResponseInterface
    {
        $accounts = Account::orderBy('created_at', 'desc')->limit(50)->get();
        
        return $response->json([
            'success' => true,
            'data' => $accounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'balance' => $account->balance,
                    'created_at' => $account->created_at?->format('Y-m-d H:i:s'),
                ];
            }),
        ]);
    }

    
    public function createAccount(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        $data = $request->all();
        
        $validator = $this->validatorFactory->make($data, [
            'name' => 'required|string|max:255',
            'balance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $response->json([
                'success' => false,
                'error' => 'Validation failed',
                'messages' => $validator->errors()->all(),
            ])->withStatus(422);
        }

        try {
            $account = new Account();
            $account->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $account->name = $data['name'];
            $account->balance = (string) $data['balance'];
            $account->save();

            return $response->json([
                'success' => true,
                'data' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'balance' => $account->balance,
                ],
            ])->withStatus(201);
        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'error' => $e->getMessage(),
            ])->withStatus(500);
        }
    }

    
    public function getWithdraws(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        // Parâmetros opcionais para filtro
        $minutes = $request->input('minutes', null); // null = sem filtro (mostra todos)
        $limit = (int) $request->input('limit', 50);
        
        $query = AccountWithdraw::with('pix');
        
        // Filtro opcional por intervalo de tempo (últimos X minutos)
        if ($minutes !== null && $minutes > 0) {
            $query->where('created_at', '>=', now()->subMinutes((int) $minutes));
        }
        
        $withdraws = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
        
        return $response->json([
            'success' => true,
            'count' => $withdraws->count(),
            'filter' => $minutes ? "Últimos {$minutes} minutos" : 'Todos',
            'data' => $withdraws->map(function ($withdraw) {
                return [
                    'id' => $withdraw->id,
                    'account_id' => $withdraw->account_id,
                    'method' => $withdraw->method,
                    'amount' => $withdraw->amount,
                    'scheduled' => $withdraw->scheduled,
                    'scheduled_for' => $withdraw->scheduled_for?->format('Y-m-d H:i:s'),
                    'done' => $withdraw->done,
                    'error' => $withdraw->error,
                    'error_reason' => $withdraw->error_reason,
                    'processed_at' => $withdraw->processed_at?->format('Y-m-d H:i:s'),
                    'created_at' => $withdraw->created_at?->format('Y-m-d H:i:s'),
                    'pix_type' => $withdraw->pix?->type,
                    'pix_key' => $withdraw->pix?->key,
                ];
            }),
        ]);
    }

    
    public function getPendingScheduled(ResponseInterface $response): PsrResponseInterface
    {
        $pending = $this->withdrawRepository->findPendingScheduled();
        
        return $response->json([
            'success' => true,
            'count' => $pending->count(),
            'data' => $pending->map(function ($withdraw) {
                return [
                    'id' => $withdraw->id,
                    'account_id' => $withdraw->account_id,
                    'amount' => $withdraw->amount,
                    'scheduled_for' => $withdraw->scheduled_for?->format('Y-m-d H:i:s'),
                    'created_at' => $withdraw->created_at?->format('Y-m-d H:i:s'),
                ];
            }),
        ]);
    }

    
    public function processScheduled(ResponseInterface $response): PsrResponseInterface
    {
        try {
            $processed = $this->withdrawService->processScheduledWithdraws();
            
            return $response->json([
                'success' => true,
                'processed' => $processed,
                'message' => "Processados {$processed} saques agendados",
            ]);
        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'error' => $e->getMessage(),
            ])->withStatus(500);
        }
    }

    
    public function updateScheduledForPast(ResponseInterface $response): PsrResponseInterface
    {
        try {
            $updated = Db::statement("
                UPDATE account_withdraw 
                SET scheduled_for = DATE_SUB(NOW(), INTERVAL 1 HOUR)
                WHERE scheduled = TRUE AND done = FALSE
            ");
            
            $count = Db::selectOne("
                SELECT COUNT(*) as count 
                FROM account_withdraw 
                WHERE scheduled = TRUE AND done = FALSE
            ");
            
            return $response->json([
                'success' => true,
                'updated' => $updated,
                'pending_count' => (int) $count->count,
                'message' => 'Saques agendados atualizados para o passado',
            ]);
        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'error' => $e->getMessage(),
            ])->withStatus(500);
        }
    }

    
    public function getMetrics(ResponseInterface $response): PsrResponseInterface
    {
        $metrics = $this->metricsService->getAllMetrics();
        
        return $response->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    
    public function getStats(ResponseInterface $response): PsrResponseInterface
    {
        $totalAccounts = Account::count();
        $totalWithdraws = AccountWithdraw::count();
        $totalScheduled = AccountWithdraw::where('scheduled', true)->count();
        $totalProcessed = AccountWithdraw::where('done', true)->count();
        $totalErrors = AccountWithdraw::where('error', true)->count();
        $totalPending = AccountWithdraw::where('scheduled', true)
            ->where('done', false)
            ->count();
        
        $totalAmount = AccountWithdraw::where('done', true)
            ->sum('amount');
        
        return $response->json([
            'success' => true,
            'data' => [
                'accounts' => $totalAccounts,
                'withdraws' => [
                    'total' => $totalWithdraws,
                    'scheduled' => $totalScheduled,
                    'processed' => $totalProcessed,
                    'errors' => $totalErrors,
                    'pending' => $totalPending,
                ],
                'total_amount' => (string) $totalAmount,
            ],
        ]);
    }

    private function getAdminHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Saque PIX</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 16px;
            color: #666;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        .tab.active {
            color: #007bff;
            border-bottom-color: #007bff;
            font-weight: 600;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        button:hover {
            background: #0056b3;
        }
        button.secondary {
            background: #6c757d;
        }
        button.secondary:hover {
            background: #545b62;
        }
        button.danger {
            background: #dc3545;
        }
        button.danger:hover {
            background: #c82333;
        }
        .btn-group {
            display: flex;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.error { background: #f8d7da; color: #721c24; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.info { background: #d1ecf1; color: #0c5460; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        .links {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .links a {
            color: #007bff;
            text-decoration: none;
            margin-right: 20px;
        }
        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>💰 Admin Panel - Saque PIX</h1>
        
        <div class="tabs">
            <button class="tab active" onclick="showTab('dashboard', this)">Dashboard</button>
            <button class="tab" onclick="showTab('accounts', this)">Contas</button>
            <button class="tab" onclick="showTab('withdraws', this)">Saques</button>
            <button class="tab" onclick="showTab('scheduled', this)">Agendados</button>
            <button class="tab" onclick="showTab('metrics', this)">Métricas</button>
        </div>

        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <div class="stats-grid" id="stats-grid">
                <div class="stat-card">
                    <div class="stat-value" id="stat-accounts">-</div>
                    <div class="stat-label">Contas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="stat-withdraws">-</div>
                    <div class="stat-label">Total de Saques</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="stat-processed">-</div>
                    <div class="stat-label">Processados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="stat-pending">-</div>
                    <div class="stat-label">Pendentes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="stat-errors">-</div>
                    <div class="stat-label">Erros</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="stat-amount">R$ 0,00</div>
                    <div class="stat-label">Valor Total</div>
                </div>
            </div>

            <div class="card">
                <h2>Links Úteis</h2>
                <div class="links">
                    <a href="http://localhost:8025" target="_blank">📧 Mailhog (Emails)</a>
                    <a href="http://localhost:3001" target="_blank">📊 Grafana</a>
                    <a href="http://localhost:9091" target="_blank">📈 Prometheus</a>
                    <a href="/health" target="_blank">❤️ Health Check</a>
                    <a href="/metrics" target="_blank">📊 Métricas (Prometheus)</a>
                    <a href="/metrics/json" target="_blank">📊 Métricas (JSON)</a>
                </div>
            </div>
        </div>

        <!-- Accounts Tab -->
        <div id="accounts" class="tab-content">
            <div class="card">
                <h2>Criar Nova Conta</h2>
                <form id="create-account-form">
                    <div class="form-group">
                        <label>Nome</label>
                        <input type="text" name="name" required placeholder="Nome da conta">
                    </div>
                    <div class="form-group">
                        <label>Saldo Inicial</label>
                        <input type="number" name="balance" step="0.01" min="0" required placeholder="1000.00">
                    </div>
                    <button type="submit">Criar Conta</button>
                </form>
            </div>

            <div class="card">
                <h2>Contas Existentes</h2>
                <button onclick="loadAccounts()" class="secondary">Atualizar</button>
                <div id="accounts-table"></div>
            </div>
        </div>

        <!-- Withdraws Tab -->
        <div id="withdraws" class="tab-content">
            <div class="card">
                <h2>Criar Saque</h2>
                <form id="create-withdraw-form">
                    <div class="form-group">
                        <label>Account ID</label>
                        <input type="text" name="accountId" required placeholder="UUID da conta">
                    </div>
                    <div class="form-group">
                        <label>Valor</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="100.00">
                    </div>
                    <div class="form-group">
                        <label>Chave PIX (Email)</label>
                        <input type="email" name="pixKey" required placeholder="usuario@email.com">
                    </div>
                    <div class="form-group">
                        <label>Agendar? (deixe vazio para imediato)</label>
                        <input type="datetime-local" name="schedule" placeholder="Data e hora">
                    </div>
                    <button type="submit">Criar Saque</button>
                </form>
            </div>

            <div class="card">
                <h2>Saques Recentes</h2>
                <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                    <label>Filtrar por:</label>
                    <select id="withdraws-filter" onchange="loadWithdraws()" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Todos os saques</option>
                        <option value="5">Últimos 5 minutos</option>
                        <option value="15">Últimos 15 minutos</option>
                        <option value="30">Últimos 30 minutos</option>
                        <option value="60">Última hora</option>
                        <option value="1440">Últimas 24 horas</option>
                    </select>
                    <button onclick="loadWithdraws()" class="secondary">Atualizar</button>
                </div>
                <div id="withdraws-table"></div>
            </div>
        </div>

        <!-- Scheduled Tab -->
        <div id="scheduled" class="tab-content">
            <div class="card">
                <h2>Processar Saques Agendados</h2>
                <p>Saques agendados pendentes: <strong id="pending-count">-</strong></p>
                <div class="btn-group" style="margin-top: 15px;">
                    <button onclick="updateScheduledToPast()" class="secondary">Atualizar para o Passado</button>
                    <button onclick="processScheduled()">Processar Agendados</button>
                </div>
                <div id="scheduled-result"></div>
            </div>

            <div class="card">
                <h2>Saques Agendados Pendentes</h2>
                <button onclick="loadPendingScheduled()" class="secondary">Atualizar</button>
                <div id="pending-table"></div>
            </div>
        </div>

        <!-- Metrics Tab -->
        <div id="metrics" class="tab-content">
            <div class="card">
                <h2>Métricas do Sistema</h2>
                <button onclick="loadMetrics()" class="secondary">Atualizar</button>
                <pre id="metrics-data" style="background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 15px;"></pre>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '/admin/api';
        const API_AUTH_HEADER = 'Bearer test-token';  // Token de teste para desenvolvimento

        function showTab(tabName, clickedElement) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            if (clickedElement) {
                clickedElement.classList.add('active');
            } else {
                // Fallback: encontrar tab pelo nome
                document.querySelectorAll('.tab').forEach(tab => {
                    if (tab.textContent.toLowerCase().includes(tabName.toLowerCase())) {
                        tab.classList.add('active');
                    }
                });
            }
            
            if (tabName === 'dashboard') loadStats();
            if (tabName === 'accounts') loadAccounts();
            if (tabName === 'withdraws') loadWithdraws();
            if (tabName === 'scheduled') {
                loadPendingScheduled();
                updatePendingCount();
            }
            if (tabName === 'metrics') loadMetrics();
        }

        async function loadStats() {
            try {
                const res = await fetch(`${API_BASE}/stats`);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('stat-accounts').textContent = data.data.accounts;
                    document.getElementById('stat-withdraws').textContent = data.data.withdraws.total;
                    document.getElementById('stat-processed').textContent = data.data.withdraws.processed;
                    document.getElementById('stat-pending').textContent = data.data.withdraws.pending;
                    document.getElementById('stat-errors').textContent = data.data.withdraws.errors;
                    document.getElementById('stat-amount').textContent = 
                        'R$ ' + parseFloat(data.data.total_amount).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                }
            } catch (e) {
                console.error('Error loading stats:', e);
                // Não mostrar erro no dashboard para não poluir a interface
                // Os valores permanecerão como "-" se houver erro
            }
        }

        async function loadAccounts() {
            try {
                const res = await fetch(`${API_BASE}/accounts`);
                const data = await res.json();
                if (data.success) {
                    const table = document.getElementById('accounts-table');
                    if (data.data.length === 0) {
                        table.innerHTML = '<p>Nenhuma conta encontrada.</p>';
                        return;
                    }
                    table.innerHTML = `
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nome</th>
                                    <th>Saldo</th>
                                    <th>Criado em</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.map(acc => `
                                    <tr>
                                        <td><code>${acc.id}</code></td>
                                        <td>${acc.name}</td>
                                        <td>R$ ${parseFloat(acc.balance).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                        <td>${acc.created_at || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                }
            } catch (e) {
                console.error('Error loading accounts:', e);
                const table = document.getElementById('accounts-table');
                if (table) {
                    table.innerHTML = '<div class="alert error">Erro ao carregar contas: ' + e.message + '</div>';
                }
            }
        }

        async function loadWithdraws() {
            try {
                const filterSelect = document.getElementById('withdraws-filter');
                const minutes = filterSelect ? filterSelect.value : '';
                const url = minutes ? `${API_BASE}/withdraws?minutes=${minutes}` : `${API_BASE}/withdraws`;
                
                const res = await fetch(url);
                const data = await res.json();
                if (data.success) {
                    const table = document.getElementById('withdraws-table');
                    if (data.data.length === 0) {
                        const filterMsg = data.filter ? ` (${data.filter})` : '';
                        table.innerHTML = `<p>Nenhum saque encontrado${filterMsg}.</p>`;
                        return;
                    }
                    table.innerHTML = `
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Conta</th>
                                    <th>Valor</th>
                                    <th>Status</th>
                                    <th>Agendado</th>
                                    <th>Processado</th>
                                    <th>PIX</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.map(w => `
                                    <tr>
                                        <td><code>${w.id.substring(0, 8)}...</code></td>
                                        <td><code>${w.account_id.substring(0, 8)}...</code></td>
                                        <td>R$ ${parseFloat(w.amount).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                        <td>
                                            ${w.done ? '<span class="badge success">Processado</span>' : ''}
                                            ${w.error ? '<span class="badge error">Erro</span>' : ''}
                                            ${!w.done && !w.error ? '<span class="badge warning">Pendente</span>' : ''}
                                        </td>
                                        <td>${w.scheduled ? (w.scheduled_for || '-') : 'Não'}</td>
                                        <td>${w.processed_at || '-'}</td>
                                        <td>${w.pix_key || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        ${data.filter ? `<p style="margin-top: 10px; color: #666; font-size: 0.9em;">Mostrando ${data.count} saque(s) - ${data.filter}</p>` : `<p style="margin-top: 10px; color: #666; font-size: 0.9em;">Mostrando ${data.count} saque(s)</p>`}
                    `;
                }
            } catch (e) {
                console.error('Error loading withdraws:', e);
                const table = document.getElementById('withdraws-table');
                if (table) {
                    table.innerHTML = '<div class="alert error">Erro ao carregar saques: ' + e.message + '</div>';
                }
            }
        }

        async function loadPendingScheduled() {
            try {
                const res = await fetch(`${API_BASE}/withdraws/pending`);
                const data = await res.json();
                if (data.success) {
                    const table = document.getElementById('pending-table');
                    if (data.data.length === 0) {
                        table.innerHTML = '<p>Nenhum saque agendado pendente.</p>';
                        return;
                    }
                    table.innerHTML = `
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Conta</th>
                                    <th>Valor</th>
                                    <th>Agendado para</th>
                                    <th>Criado em</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.map(w => `
                                    <tr>
                                        <td><code>${w.id.substring(0, 8)}...</code></td>
                                        <td><code>${w.account_id.substring(0, 8)}...</code></td>
                                        <td>R$ ${parseFloat(w.amount).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                        <td>${w.scheduled_for || '-'}</td>
                                        <td>${w.created_at || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                }
            } catch (e) {
                console.error('Error loading pending:', e);
                const table = document.getElementById('pending-table');
                if (table) {
                    table.innerHTML = '<div class="alert error">Erro ao carregar saques pendentes: ' + e.message + '</div>';
                }
            }
        }

        async function updatePendingCount() {
            try {
                const res = await fetch(`${API_BASE}/withdraws/pending`);
                const data = await res.json();
                if (data.success) {
                    const countEl = document.getElementById('pending-count');
                    if (countEl) {
                        countEl.textContent = data.count;
                    }
                }
            } catch (e) {
                console.error('Error loading pending count:', e);
                const countEl = document.getElementById('pending-count');
                if (countEl) {
                    countEl.textContent = '?';
                }
            }
        }

        async function processScheduled() {
            const resultDiv = document.getElementById('scheduled-result');
            resultDiv.innerHTML = '<div class="loading">Processando...</div>';
            
            try {
                const res = await fetch(`${API_BASE}/process-scheduled`, { method: 'POST' });
                const data = await res.json();
                if (data.success) {
                    resultDiv.innerHTML = `<div class="alert success">${data.message}</div>`;
                    loadPendingScheduled();
                    updatePendingCount();
                    loadStats();
                } else {
                    resultDiv.innerHTML = `<div class="alert error">Erro: ${data.error}</div>`;
                }
            } catch (e) {
                resultDiv.innerHTML = `<div class="alert error">Erro: ${e.message}</div>`;
            }
        }

        async function updateScheduledToPast() {
            const resultDiv = document.getElementById('scheduled-result');
            resultDiv.innerHTML = '<div class="loading">Atualizando...</div>';
            
            try {
                const res = await fetch(`${API_BASE}/update-scheduled-for-past`, { method: 'POST' });
                const data = await res.json();
                if (data.success) {
                    resultDiv.innerHTML = `<div class="alert success">${data.message} (${data.pending_count} pendentes)</div>`;
                    loadPendingScheduled();
                    updatePendingCount();
                } else {
                    resultDiv.innerHTML = `<div class="alert error">Erro: ${data.error}</div>`;
                }
            } catch (e) {
                resultDiv.innerHTML = `<div class="alert error">Erro: ${e.message}</div>`;
            }
        }

        async function loadMetrics() {
            try {
                const res = await fetch(`${API_BASE}/metrics`);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('metrics-data').textContent = 
                        JSON.stringify(data.data, null, 2);
                }
            } catch (e) {
                console.error('Error loading metrics:', e);
                const metricsDiv = document.getElementById('metrics-data');
                if (metricsDiv) {
                    metricsDiv.textContent = 'Erro ao carregar métricas: ' + e.message;
                }
            }
        }

        document.getElementById('create-account-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);
            
            // Converter balance para número
            data.balance = parseFloat(data.balance);
            
            const submitButton = e.target.querySelector('button[type="submit"]');
            const originalText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Criando...';
            
            try {
                const res = await fetch(`${API_BASE}/accounts`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                if (result.success) {
                    alert('✅ Conta criada com sucesso!\n\nID: ' + result.data.id + '\nNome: ' + result.data.name + '\nSaldo: R$ ' + parseFloat(result.data.balance).toLocaleString('pt-BR', {minimumFractionDigits: 2}));
                    e.target.reset();
                    loadAccounts();
                    loadStats();
                } else {
                    const errorMsg = result.error || result.messages?.join(', ') || 'Erro desconhecido';
                    alert('❌ Erro ao criar conta:\n\n' + errorMsg);
                }
            } catch (e) {
                alert('❌ Erro de conexão: ' + e.message);
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            }
        });

        document.getElementById('create-withdraw-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);
            
            // Validar Account ID (UUID)
            if (!data.accountId || data.accountId.trim().length < 36) {
                alert('❌ Account ID inválido. Deve ser um UUID válido.');
                return;
            }
            
            // Converter amount para número
            const amount = parseFloat(data.amount);
            if (isNaN(amount) || amount <= 0) {
                alert('❌ Valor inválido. Deve ser um número maior que zero.');
                return;
            }
            
            // Converter schedule se fornecido
            let schedule = null;
            if (data.schedule) {
                const scheduleDate = new Date(data.schedule);
                if (isNaN(scheduleDate.getTime())) {
                    alert('❌ Data de agendamento inválida.');
                    return;
                }
                schedule = scheduleDate.toISOString().slice(0, 16).replace('T', ' ');
            }
            
            const payload = {
                method: 'PIX',
                pix: {
                    type: 'email',
                    key: data.pixKey.trim()
                },
                amount: amount,
                schedule: schedule
            };
            
            const submitButton = e.target.querySelector('button[type="submit"]');
            const originalText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Criando...';
            
            try {
                const res = await fetch(`/account/${data.accountId.trim()}/balance/withdraw`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': API_AUTH_HEADER
                    },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (res.ok && result.success) {
                    const type = result.data.scheduled ? 'agendado' : 'imediato';
                    alert('✅ Saque criado com sucesso!\n\nID: ' + result.data.id + '\nTipo: ' + type + '\nValor: R$ ' + parseFloat(result.data.amount).toLocaleString('pt-BR', {minimumFractionDigits: 2}));
                    e.target.reset();
                    loadWithdraws();
                    loadStats();
                } else {
                    const errorMsg = result.error || result.message || result.errors?.join(', ') || 'Erro desconhecido';
                    alert('❌ Erro ao criar saque:\n\n' + errorMsg + (result.errors ? '\n\nDetalhes:\n' + result.errors.join('\n') : ''));
                }
            } catch (e) {
                alert('❌ Erro de conexão: ' + e.message);
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            }
        });

        // Auto-refresh stats every 5 seconds
        setInterval(() => {
            if (document.getElementById('dashboard').classList.contains('active')) {
                loadStats();
            }
        }, 5000);

        // Initial load
        loadStats();
    </script>
</body>
</html>
HTML;
    }
}

