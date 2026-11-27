<?php

declare(strict_types=1);

require \dirname(__DIR__) . '/vendor/autoload.php';

// psr/event-dispatcher and psr/simple-cache, needed by the tests of the two
// classes in src/Integration, come with the toolchain kcode installs
// (devkit.php, ADR-008). The project's autoloader is registered first, so
// its packages win over any copy in .kcode/.
require \dirname(__DIR__) . '/.kcode/vendor/autoload.php';
