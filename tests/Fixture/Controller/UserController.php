<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture\Controller;

use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;
use KaririCode\Router\Enum\HttpMethod;

#[Controller('/api/v1/users/', middleware: ['auth'], namePrefix: 'users.', host: 'api.example.com', scheme: 'https')]
final class UserController
{
    #[Route('/constructed')]
    public function __construct() {}

    #[Route('/', name: 'index')]
    public function index(): void {}

    #[Route('/{id:int}', method: ['GET', 'head'], name: 'show', middleware: ['etag'], defaults: ['format' => 'json'], host: 'admin.example.com', scheme: 'wss')]
    public function show(): void {}

    #[Route('/', method: HttpMethod::Post, name: 'store')]
    #[Route('/bulk', method: 'POST')]
    public function store(): void {}

    #[Route]
    public function export(): void {}

    public function helper(): void {}

    #[Route('/hidden')]
    protected function hidden(): void {}
}
