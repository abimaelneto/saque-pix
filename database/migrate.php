<?php

declare(strict_types=1);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/helper.php';

use Hyperf\Database\Migrations\Migrator;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Context\ApplicationContext;

$container = require BASE_PATH . '/config/container.php';

$resolver = ApplicationContext::getContainer()->get(ConnectionResolverInterface::class);
$migrator = new Migrator($resolver);

$migrator->run([__DIR__ . '/migrations']);

echo "Migrations executed successfully!\n";

