<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit(2);
}

require_once __DIR__ . '/autoload.php';

use vielhuber\codechecker\exception;
use vielhuber\codechecker\codechecker;

try {
    $root = null;
    $tools = [];
    $paths = [];
    $timeout = 120;
    $listTools = false;
    $summary = false;
    $mode = 'project';
    $reportId = null;
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
                "Usage: codechecker-cli.php [--root=PATH] [--tools=phpstan,eslint] [--timeout=120] [--mode=files|project] [--summary] [--report=ID] [--list-tools] [--] [PATH ...]\nExit codes: 0 passed, 1 findings, 2 incomplete or invalid input.\n"
            );
            exit(0);
        }
        if ($options && $argument === '--list-tools') {
            $listTools = true;
            continue;
        }
        if ($options && $argument === '--summary') {
            $summary = true;
            continue;
        }
        if ($options && str_starts_with($argument, '--mode=')) {
            $mode = substr($argument, 7);
            continue;
        }
        if ($options && str_starts_with($argument, '--report=')) {
            $reportId = substr($argument, 9);
            continue;
        }
        if ($options && str_starts_with($argument, '--root=')) {
            $root = substr($argument, 7);
            continue;
        }
        if ($options && str_starts_with($argument, '--tools=')) {
            $tools = explode(',', substr($argument, 8));
            continue;
        }
        if ($options && preg_match('/^--timeout=([1-9][0-9]*)$/', $argument, $matches) === 1) {
            $timeout = (int) $matches[1];
            continue;
        }
        if ($options && str_starts_with($argument, '-')) {
            throw exception::invalidOptions();
        }
        $paths[] = $argument;
    }
    $checker = new codechecker(root: $root);
    $exitCode = 0;
    switch (true) {
        case $reportId !== null:
            $output = $checker->getReport($reportId);
            break;
        case $listTools:
            $output = $checker->listTools();
            break;
        default:
            $compact = $checker->checkCode(paths: $paths, tools: $tools, timeout: $timeout, mode: $mode);
            $output = $summary ? $compact : $checker->getReport($compact['reportId']);
            $exitCode = !$compact['complete'] ? 2 : ($compact['passed'] ? 0 : 1);
    }
    fwrite(
        STDOUT,
        json_encode(
            $output,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL
    );
    exit($exitCode);
} catch (exception | \UnexpectedValueException | \JsonException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}
