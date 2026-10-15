[![build status](https://github.com/vielhuber/codechecker/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/codechecker/actions)
[![github tag](https://img.shields.io/github/v/tag/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/tags)
[![license](https://img.shields.io/github/license/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/blob/main/LICENSE.md)
[![last commit](https://img.shields.io/github/last-commit/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/commits)
[![php version support](https://img.shields.io/packagist/php-v/vielhuber/codechecker)](https://packagist.org/packages/vielhuber/codechecker)
[![packagist downloads](https://img.shields.io/packagist/dt/vielhuber/codechecker)](https://packagist.org/packages/vielhuber/codechecker)

# ⌨️codechecker⌨️

read-only php/js/ts checks via lib, cli & mcp. dirs, files or both.

## setup

php ≥ 8.5 (`proc_open`); node ≥ 24 for js/ts.

```sh
git clone https://github.com/vielhuber/codechecker.git
cd codechecker
composer install
composer install --working-dir=tools
tools/vendor/bin/mago --version
npm install --no-audit --no-fund
```

once centrally, not per proj. skip npm for php-only. lib: `composer require vielhuber/codechecker` via [packagist](https://packagist.org/packages/vielhuber/codechecker); tools installed separately. target deps/plugins required.

## tools

| lang  | checks                                                                                                          |
| ----- | --------------------------------------------------------------------------------------------------------------- |
| php   | `php-syntax`, `mago-format`, `mago-lint`, `mago-analyze`, `rector`, `php-cs-fixer`, `psalm`, `phpstan`, `phpcs` |
| js/ts | `eslint`, `biome`, `prettier`, `typescript`, `oxlint`                                                           |

proj bins/cfg first, central bins/defaults next, system bins last. defaults: psr-12, phpstan lvl 6, psalm lvl 3. ts keeps proj cfg. type analysis remains proj-wide, even with file paths. no dep audits or dedicated security scans.

## cli

```sh
php src/codechecker-cli.php --root=/var/www/proj .
php src/codechecker-cli.php --root=/var/www/proj --tools=php-syntax,eslint src/demo.php public/demo.js
php src/codechecker-cli.php --root=/var/www/proj --list-tools
```

json; exit: `0` ok, `1` findings, `2` incomplete/invalid. timeout: 120s/tool (1-600). opts before paths; `--` ends opts.

## php

```php
<?php
declare(strict_types=1);
require 'vendor/autoload.php';

$report = (new \vielhuber\codechecker\codechecker(root: '/var/www/proj'))->check(
    paths: ['src/demo.php', 'public/demo.js'],
    tools: ['php-syntax', 'eslint']
);

echo json_encode($report);
```

report: `files`, `results`, `complete` ≠ `passed`. errors/timeouts/missing bins → incomplete. preview: 1 mib/stream; full logs via `outputFile`/`errorOutputFile` if cut. host fs access required. logs > 24 h deleted on next proj check.

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

- `list_tools(project: "shop")`: bins/checks.
- `check_code(project: "shop", paths: ["src/demo.php"], tools: ["php-syntax"])`: run.

proj default: `"."`. empty paths: whole proj; empty tools: all checks. paths stay inside `CODECHECKER_ROOT`.

stdio: no token. http: copy `.env.example` → `.env`, set `CODECHECKER_ROOT` + random `MCP_TOKEN`; serve via https with `Authorization: Bearer <MCP_TOKEN>`. deny web access to `.env`. trusted cfg/plugins only; root bound ≠ sandbox.
