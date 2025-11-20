<?php

declare(strict_types=1);

/**
 * Bootstrap para Serverless/Lambda
 * 
 * Este arquivo é usado quando a aplicação roda em ambiente serverless
 * (AWS Lambda, Google Cloud Functions, etc.)
 */

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/helper.php';

\Hyperf\Di\ClassLoader::init();

return require BASE_PATH . '/config/container.php';

