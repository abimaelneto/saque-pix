<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller]
class IndexController
{
    #[GetMapping(path: '/')]
    public function index(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        return $response->json([
            'message' => 'Saque PIX API',
            'version' => '1.0.0',
            'status' => 'running',
        ]);
    }
}

