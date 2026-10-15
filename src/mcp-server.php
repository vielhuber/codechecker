<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use vielhuber\simplemcp\simplemcp;

$packageDirectory = dirname(__DIR__);
new simplemcp(
    name: 'codechecker-mcp-server',
    log: $packageDirectory . '/mcp-server.log',
    discovery: __DIR__,
    auth: 'static',
    env: is_file($packageDirectory . '/.env') ? $packageDirectory . '/.env' : '/dev/null'
);
