<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hyperf\Database\Migrations\Migrator;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Context\ApplicationContext;

$container = require __DIR__ . '/../config/container.php';

$resolver = ApplicationContext::getContainer()->get(ConnectionResolverInterface::class);
$migrator = new Migrator($resolver);

$migrator->run([__DIR__ . '/migrations']);

echo "Migrations executed successfully!\n";

