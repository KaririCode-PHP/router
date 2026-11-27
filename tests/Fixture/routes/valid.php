<?php

declare(strict_types=1);

return [
    ['GET', '/health', 'HealthCheck', 'health'],
    ['GET|POST', '/search', ['SearchController', 'run'], 'search', 'middleware' => ['throttle'], 'defaults' => ['page' => '1']],
    ['group' => '/admin', 'middleware' => ['auth'], 'name_prefix' => 'admin.', 'routes' => [
        [['GET'], '/stats', 'AdminStats', 'stats'],
        ['group' => '/users', 'routes' => [
            ['DELETE', '/{id:int}', 'AdminUsers', 'users.delete'],
        ]],
    ]],
];
