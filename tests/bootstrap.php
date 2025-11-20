<?php

declare(strict_types=1);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

if (\extension_loaded('swoole') && class_exists(\Swoole\Runtime::class)) {
    \Swoole\Runtime::enableCoroutine();
}

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/helper.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

// Coroutine runtime não é necessário para testes unitários/integrados.

\Hyperf\Di\ClassLoader::init();

// Inicializar container se não estiver inicializado
if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
    $container = require BASE_PATH . '/config/container.php';
}

