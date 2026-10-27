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

proj bins/cfg first, central bins/defaults next, system bins last. defaults: php-cs-fixer psr-12, phpcs psr-1 without the bootstrap side-effect ban, phpstan lvl 6, psalm lvl 3. ts keeps proj cfg. type analysis remains proj-wide, even with file paths. no dep audits or dedicated security scans.

biome skips `style/useConst` and `complexity/noStaticOnlyClass` globally; other rules and proj cfg stay unchanged.
js/ts formatting belongs to prettier; biome keeps lint and import checks, without its formatter.

mago lint defaults skip blanket `isset`/elvis/ini/boolean-flag bans, file-name policy and aggregate class/size metrics.
correctness/security rules and excessive nesting stay enabled; architecture metrics are opt-in.
proj `mago.toml` settings override these defaults; explicit rule opt-ins remain possible.

php formatting has one owner: explicit php-cs-fixer cfg, explicit mago `[formatter]`, prettier with a php parser, then central php-cs-fixer.
project prettier cfg/plugins are resolved by prettier itself, including parent cfg. node is only needed for node-based checks/formatter detection.
requesting a php format check also selects its owner. other formatters return `not_applicable` with a delegation reason, not a fabricated pass.
prettier accepts php paths when it owns php formatting; its existing `javascript` language label denotes the node-based tool.
central phpcs checks basic conventions, not competing whitespace profiles; explicit proj rulesets stay unchanged.

## cli

```sh
php src/codechecker-cli.php --root=/var/www/proj .
php src/codechecker-cli.php --root=/var/www/proj --tools=php-syntax,eslint src/demo.php public/demo.js
php src/codechecker-cli.php --root=/var/www/proj --mode=files --tools=php-syntax --summary src/demo.php
php src/codechecker-cli.php --root=/var/www/proj --report=REPORT_ID
php src/codechecker-cli.php --root=/var/www/proj --list-tools
```

json; exit: `0` ok, `1` findings, `2` incomplete/invalid. timeout: 120s/tool (1-600). opts before paths; `--` ends opts. cli defaults to project mode + full output; `--summary` returns compact output.

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

report: `files`, `results`, `complete` ≠ `passed`. errors/timeouts/missing bins → incomplete. php `check()` keeps its project-mode defaults and raw report contract; `mode: 'files'` enforces explicit files + file-scoped tools. `checkCode()` saves the report and provides a summary.

## reports

mcp/cli checks save full reports outside the proj, including large logs, tool versions, cfg hashes and source hashes. set `CODECHECKER_REPORT_ROOT` to a private persistent dir; default: `$XDG_STATE_HOME/codechecker` or `$HOME/.local/state/codechecker`. permissions: dirs `0700`, files `0600`. no automatic expiry; temporary analyzer logs expire after 24 h. public links and caller-selected storage paths are not provided.

summary: tool status, `reportId`, up to 20 new/unknown findings, counts for `new`, `existing`, `unknown`, `resolved`. comparison requires the same proj, files, tools, versions and known cfg hashes. no matching completed comparable run → unknown. existing findings still fail. native outputs without structured diagnostics are marked `normalized: false`; their counts represent output groups, not individual findings. full reports are available via `get_report` or `--report`.

## mcp

```json
{
    "mcpServers": {
        "codechecker": {
            "command": "php",
            "args": ["/opt/codechecker/src/mcp-server.php"],
            "env": {
                "CODECHECKER_ROOT": "/var/www",
                "CODECHECKER_REPORT_ROOT": "/private/codechecker-reports"
            }
        }
    }
}
```

- `list_tools(project: "shop")`: bins/checks.
- `check_code(project: "shop", paths: ["src/demo.php"], tools: ["php-syntax"])`: run + compact summary.
- `check_code(project: "shop", mode: "project", tools: ["phpstan"])`: explicitly requested proj analysis.
- `get_report(project: "shop", reportId: "REPORT_ID", tool: "phpcs")`: full report, optionally filtered by tool.

proj default: `"."`. mcp defaults to `mode: "files"`: non-empty paths/tools, files only, no project-wide tools. `mode: "project"` permits dirs, empty paths for the whole proj, empty tools for all checks. paths and report ids stay inside the configured project boundary.

mcp migration: whole-proj callers must select `mode: "project"`; raw outputs are retrieved via `get_report`. php/cli defaults stay unchanged.

stdio: no token. http: copy `.env.example` → `.env`, set `CODECHECKER_ROOT` + random `MCP_TOKEN`; serve via https with `Authorization: Bearer <MCP_TOKEN>`. deny web access to `.env`. trusted cfg/plugins only; root bound ≠ sandbox.
