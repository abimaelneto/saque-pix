<?php

declare(strict_types=1);

namespace Tests;

use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\Client;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?Client $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $container = ApplicationContext::getContainer();
        $this->client = $container->get(Client::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

