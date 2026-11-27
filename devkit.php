<?php

declare(strict_types=1);

// KaririCode Devkit (kcode) installs the toolchain into .kcode/ and runs it
// with the configs committed at the root (ADR-008). Versions are exact, so
// every run uses the toolchain the gates were last verified with (§32).
// psr/event-dispatcher and psr/simple-cache are not tools: the tests and the
// analysers need them for the two optional classes in Integration/, which
// users enable by installing a PSR-14 dispatcher or a PSR-16 cache.
return [
    'root_configs' => true,
    'tools' => [
        'phpunit' => '11.5.56',
        'phpstan' => '2.3.7',
        'psalm' => '6.19.1',
        'php-cs-fixer' => '3.95.27',
        'infection/infection' => '0.35.5',
        'tomasvotruba/cognitive-complexity' => '1.2.0',
        'psr/event-dispatcher' => '1.0.0',
        'psr/simple-cache' => '3.0.0',
    ],
];
