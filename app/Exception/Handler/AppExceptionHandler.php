<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($throwable instanceof ValidationException) {
            $body = json_encode([
                'error' => 'Validation failed',
                'messages' => $throwable->validator->errors()->all(),
            ], JSON_UNESCAPED_UNICODE);

            return $response
                ->withStatus(422)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new SwooleStream($body));
        }

        $this->stopPropagation();
        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}

