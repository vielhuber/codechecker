<?php
declare(strict_types=1);

foreach (
    [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php', __DIR__ . '/../../../../autoload.php']
    as $autoloadPath
) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        return;
    }
}

fwrite(STDERR, 'codechecker: run composer install before starting the application.' . PHP_EOL);
exit(2);
