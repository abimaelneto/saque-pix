<?php

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;

// ============================================
// ROTAS ESTÁTICAS PRIMEIRO (ordem importa!)
// ============================================

// Health e Index (sem prefixo) - MAIS ESPECÍFICAS PRIMEIRO
Router::addRoute(['GET'], '/', 'App\Controller\IndexController@index');
Router::addRoute(['GET'], '/health', 'App\Controller\HealthController@health');
Router::addRoute(['GET'], '/metrics', 'App\Controller\HealthController@metrics');
Router::addRoute(['GET'], '/metrics/json', 'App\Controller\HealthController@metricsJson');

// Admin (prefixo /admin) - todas estáticas
Router::addRoute(['GET'], '/admin', 'App\Controller\AdminController@index');
Router::addRoute(['GET'], '/admin/api/accounts', 'App\Controller\AdminController@getAccounts');
Router::addRoute(['POST'], '/admin/api/accounts', 'App\Controller\AdminController@createAccount');
Router::addRoute(['GET'], '/admin/api/withdraws', 'App\Controller\AdminController@getWithdraws');
Router::addRoute(['GET'], '/admin/api/withdraws/pending', 'App\Controller\AdminController@getPendingScheduled');
Router::addRoute(['POST'], '/admin/api/process-scheduled', 'App\Controller\AdminController@processScheduled');
Router::addRoute(['POST'], '/admin/api/update-scheduled-for-past', 'App\Controller\AdminController@updateScheduledForPast');
Router::addRoute(['GET'], '/admin/api/metrics', 'App\Controller\AdminController@getMetrics');
Router::addRoute(['GET'], '/admin/api/stats', 'App\Controller\AdminController@getStats');

// Accounts (prefixo /accounts) - ESTÁTICAS ANTES DE VARIÁVEIS
Router::addRoute(['GET'], '/accounts', 'App\Controller\AccountController@list');
Router::addRoute(['POST'], '/accounts', 'App\Controller\AccountController@create');
// Rota variável DEPOIS das estáticas
Router::addRoute(['GET'], '/accounts/{id}', 'App\Controller\AccountController@get');

// Account Withdraw (prefixo /account) - Rota variável (sempre por último)
Router::addRoute(['POST'], '/account/{accountId}/balance/withdraw', 'App\Controller\WithdrawController@withdraw');
