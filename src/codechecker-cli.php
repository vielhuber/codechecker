<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit(2);
}

require_once __DIR__ . '/autoload.php';

use vielhuber\codechecker\CodecheckerException;
use vielhuber\codechecker\Report;
use vielhuber\codechecker\Tool;
use vielhuber\codechecker\codechecker;

try {
    $root = null;
    $tools = null;
    $paths = [];
    $timeout = 120;
    $listTools = false;
    $options = true;
    $arguments = $_SERVER['argv'] ?? [];
    $matches = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if ($options && $argument === '--') {
            $options = false;
            continue;
        }
        if ($options && $argument === '--help') {
            fwrite(
                STDOUT,
                "Usage: codechecker-cli.php [--root=PATH] [--tools=phpstan,eslint] [--timeout=120] [--list-tools] [--] [PATH ...]\nExit codes: 0 passed, 1 findings, 2 incomplete or invalid input.\n"
            );
            exit(0);
        }
        if ($options && $argument === '--list-tools') {
            $listTools = true;
            continue;
        }
        if ($options && str_starts_with($argument, '--root=')) {
            $root = substr($argument, 7);
            continue;
        }
        if ($options && str_starts_with($argument, '--tools=')) {
            $tools = array_map(
                static fn(string $tool): Tool => Tool::tryFrom($tool) ?? throw CodecheckerException::invalidOptions(),
                explode(',', substr($argument, 8))
            );
            continue;
        }
        if ($options && preg_match('/^--timeout=([1-9][0-9]*)$/', $argument, $matches) === 1) {
            $timeout = (int) $matches[1];
            continue;
        }
        if ($options && str_starts_with($argument, '-')) {
            throw CodecheckerException::invalidOptions();
        }
        $paths[] = $argument;
    }
    $checker = new codechecker(root: $root, timeout: $timeout);
    $report = $listTools ? $checker->listTools() : $checker->check(paths: $paths, tools: $tools);
    fwrite(
        STDOUT,
        json_encode(
            $report,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL
    );
    exit($report instanceof Report ? $report->exitCode() : 0);
} catch (CodecheckerException | \UnexpectedValueException | \JsonException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}
