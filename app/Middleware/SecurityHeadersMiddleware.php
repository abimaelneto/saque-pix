<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware de Headers de Segurança
 * 
 * Adiciona headers de segurança HTTP para proteção contra ataques comuns
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Content Security Policy
        // Permitir inline scripts e event handlers apenas para /admin (painel interno)
        $path = $request->getUri()->getPath();
        $csp = str_starts_with($path, '/admin')
            ? "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-hashes'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self';"
            : "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';";
        
        $response = $response->withHeader('Content-Security-Policy', $csp);

        // X-Content-Type-Options: Previne MIME type sniffing
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');

        // X-Frame-Options: Previne clickjacking
        $response = $response->withHeader('X-Frame-Options', 'DENY');

        // X-XSS-Protection: Proteção XSS (legado, mas ainda útil)
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');

        // Strict-Transport-Security: Força HTTPS (apenas em produção)
        if (env('APP_ENV') === 'production') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Referrer-Policy: Controla informações de referrer
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy: Controla features do navegador
        $response = $response->withHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=()'
        );

        // Remove headers que podem expor informações
        $response = $response->withoutHeader('X-Powered-By');
        $response = $response->withoutHeader('Server');

        return $response;
    }
}

