<?php

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/', 'App\Controller\IndexController@index');

