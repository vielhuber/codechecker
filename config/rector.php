<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withFileExtensions(['php', 'phtml', 'inc'])
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withCache(getenv('CODECHECKER_CACHE') ?: sys_get_temp_dir() . '/codechecker-rector')
    ->withoutParallel();
