<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture\Controller;

use KaririCode\Router\Attribute\Route;

final class PlainController
{
    #[Route('/ping', method: 'SUBSCRIBE', name: 'ping')]
    public function ping(): void {}
}
