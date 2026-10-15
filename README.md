[![build status](https://github.com/vielhuber/codechecker/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/codechecker/actions)
[![github tag](https://img.shields.io/github/v/tag/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/tags)
[![license](https://img.shields.io/github/license/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/blob/main/LICENSE.md)
[![last commit](https://img.shields.io/github/last-commit/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/commits)
[![php version support](https://img.shields.io/packagist/php-v/vielhuber/codechecker)](https://packagist.org/packages/vielhuber/codechecker)
[![packagist downloads](https://img.shields.io/packagist/dt/vielhuber/codechecker)](https://packagist.org/packages/vielhuber/codechecker)

# ⌨️codechecker⌨️

codechecker runs read-only PHP, JavaScript and TypeScript checks through a PHP library, a CLI or an MCP server. pass a project directory, individual files or both; each tool keeps its own diagnostics.

## installation

requires PHP 8.5 with `proc_open` enabled. Node.js 24 or newer is needed for the JavaScript tools, not for PHP-only checks.

for a central installation with all tools, use a checkout:

```sh
git clone https://github.com/vielhuber/codechecker.git
cd codechecker
composer install
composer install --working-dir=tools
tools/vendor/bin/mago --version
npm install --no-audit --no-fund
```

install once centrally, not in every project. composer installs the PHP dependencies; npm downloads the separate JavaScript tools and their dependencies. PHP starts their executables directly during checks, without invoking npm. skip the npm step for PHP-only use. the mago command downloads its native binary once; checks never install missing tools.

the PHP library is available on [packagist](https://packagist.org/packages/vielhuber/codechecker): `composer require vielhuber/codechecker`. no npm release is needed. the central tool installations above are still separate. target projects need their normal dependencies and analysis plugins for meaningful framework and type checks.

## tools

| language                | checks                                                                                                          |
| ----------------------- | --------------------------------------------------------------------------------------------------------------- |
| PHP                     | `php-syntax`, `mago-format`, `mago-lint`, `mago-analyze`, `rector`, `php-cs-fixer`, `psalm`, `phpstan`, `phpcs` |
| JavaScript / TypeScript | `eslint`, `biome`, `prettier`, `typescript`, `oxlint`                                                           |

these 14 checks cover syntax, formatting, linting, refactoring suggestions and static analysis. they use default or project-configured rules, not every available rule or plugin. they do not include every available analyzer. dependency audits (`composer audit`, `npm audit`) and dedicated security scanning such as semgrep are not bundled; npm audit sends dependency information to the configured registry.

project executables take precedence over central tools, then PATH. existing project configurations are used where supported; otherwise the bundled defaults apply (including PSR-12, PHPStan level 6 and Psalm level 3). typescript maintains the project's type resolution, file selection and rules by using `tsconfig.json` or `jsconfig.json` directly. only output and incremental cache locations are overridden. different formatters can disagree, so style findings remain separate.

## cli

```sh
php src/codechecker-cli.php --root=/var/www/project .
php src/codechecker-cli.php --root=/var/www/project --tools=phpstan,psalm,eslint,typescript src public/app.js
php src/codechecker-cli.php --root=/var/www/project --list-tools
```

options precede paths; `--` ends option parsing. `--timeout=120` sets the limit per tool invocation (1–600 seconds). output is JSON: `0` means passed, `1` means findings, `2` means invalid input or an incomplete check.

## php

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use vielhuber\codechecker\codechecker;

$checker = new codechecker(root: '/var/www/project');
$report = $checker->check(paths: ['src', 'public/app.js'], tools: ['phpstan', 'eslint']);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
exit($report->exitCode());
```

omit `tools` to run every applicable check. reports contain `files`, native tool `results`, `complete` and `passed`. unavailable tools, execution errors, timeouts or failed output capture make a report incomplete; findings alone do not.

each output stream contains a one MiB preview. if `outputTruncated` is true, `outputFile` and `errorOutputFile` point to complete raw logs. these private files are on the checking host; MCP clients need authorized filesystem access there to read or filter them. preview truncation alone does not make a check incomplete. logs older than 24 hours are removed on the next check of that project; small outputs need no retained files.

## mcp

```json
{
    "mcpServers": {
        "codechecker": {
            "command": "php",
            "args": ["/opt/codechecker/src/mcp-server.php"],
            "env": { "CODECHECKER_ROOT": "/var/www" }
        }
    }
}
```

- `list_tools(project: "shop")` lists checks and resolved executables.
- `check_code(project: "shop", paths: ["src"], tools: ["phpstan"])` checks the selected project.

`project` defaults to `"."`; empty `paths` selects the whole project, empty `tools` selects all checks. paths and projects cannot escape `CODECHECKER_ROOT`.

stdio needs no token. for HTTP, copy `.env.example` to `.env`, set `CODECHECKER_ROOT` and a random `MCP_TOKEN`, and expose `src/mcp-server.php` over HTTPS. require `Authorization: Bearer <MCP_TOKEN>` and block web access to `.env`; requests without a valid token are rejected.
