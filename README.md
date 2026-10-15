[![build status](https://github.com/vielhuber/codechecker/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/codechecker/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/codechecker)](https://github.com/vielhuber/codechecker/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/codechecker)](https://packagist.org/packages/vielhuber/codechecker)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/codechecker)](https://packagist.org/packages/vielhuber/codechecker)

# ⌨️codechecker⌨️

Will man PHP- und JavaScript-Projekte mit mehreren Werkzeugen prüfen, unterscheiden sich Installation, Konfiguration und Ausgabe erheblich. codechecker bündelt diese Prüfungen hinter einer PHP-Bibliothek, einer CLI und einem MCP-Server.

Man übergibt einen Projektordner, einzelne Dateien oder eine Kombination daraus. Die Werkzeuge laufen ohne Fix-Optionen; fehlende Programme, Konfigurationsfehler und Zeitüberschreitungen bleiben als eigene Ergebnisse sichtbar.

## Voraussetzungen

codechecker benötigt PHP 8.5 mit DOM, JSON und aktiviertem `proc_open` sowie Node.js 24 zum Ausführen der JavaScript-Werkzeuge. Die vorbereitete Umgebung ist für Linux und macOS gedacht.

Die Werkzeuge werden einmal in der zentralen Installation eingerichtet, nicht in jedem geprüften Projekt. Dessen normale Composer- bzw. npm-Abhängigkeiten müssen für eine belastbare Typ- und Frameworkanalyse trotzdem vorhanden sein.

## Installation

Solange das Paket noch nicht auf Packagist veröffentlicht ist, verwendet man den Checkout:

```sh
git clone https://github.com/vielhuber/codechecker.git
cd codechecker
composer install
composer install --working-dir=tools
npm install
tools/vendor/bin/mago --version
```

Der letzte Aufruf lädt das zur festgelegten Mago-Version passende Binary. Die eigentlichen Prüfläufe installieren keine Pakete und sollen nur das bereits heruntergeladene Binary verwenden.

Nach der Veröffentlichung lässt sich die PHP-Bibliothek auch über Composer einbinden:

```sh
composer require vielhuber/codechecker
```

Die Analyseprogramme werden dabei nicht als Abhängigkeiten in das Anwendungsprojekt gezogen. Für den vollständigen zentralen Werkzeugbestand bleiben die Installation unter "tools" und die npm-Installation im Paketverzeichnis erforderlich.

## Werkzeuge

| Kennung        | Werkzeug                                                             | Prüfung                                      |
| -------------- | -------------------------------------------------------------------- | -------------------------------------------- |
| `php-syntax`   | PHP                                                                  | Syntax mit der verwendeten PHP-Laufzeit      |
| `mago-format`  | [Mago](https://github.com/carthage-software/mago)                    | Formatierung                                 |
| `mago-lint`    | Mago                                                                 | Lint-Regeln                                  |
| `mago-analyze` | Mago                                                                 | Projektweite Typ- und Kontrollflussanalyse   |
| `rector`       | [Rector](https://github.com/rectorphp/rector)                        | Vorgeschlagene Refactorings als Dry-run      |
| `php-cs-fixer` | [PHP CS Fixer](https://cs.symfony.com/)                              | Coding Standards im Check-Modus              |
| `psalm`        | [Psalm](https://psalm.dev/)                                          | Projektweite statische Analyse               |
| `phpstan`      | [PHPStan](https://phpstan.org/)                                      | Projektweite statische Analyse               |
| `phpcs`        | [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) | Coding Standards                             |
| `eslint`       | [ESLint](https://eslint.org/)                                        | JavaScript-, JSX- und TypeScript-Regeln      |
| `biome`        | [Biome](https://biomejs.dev/)                                        | Linting, Formatierung und Imports            |
| `prettier`     | [Prettier](https://prettier.io/)                                     | Formatierung                                 |
| `typescript`   | [TypeScript](https://www.typescriptlang.org/)                        | Projektweite Typprüfung, auch für JavaScript |
| `oxlint`       | [Oxlint](https://oxc.rs/docs/guide/usage/linter.html)                | Zusätzliche JS-/TS-Lint-Regeln               |

Ohne Werkzeugauswahl laufen alle zur Sprache der Eingabe passenden Prüfungen. Mago stellt dabei drei getrennte Prüfungen bereit; insgesamt sind elf Analyseprogramme und der native PHP-Syntaxcheck angebunden.

Mehrere Formatter können denselben Code unterschiedlich bewerten. Die Befunde werden deshalb getrennt ausgegeben, statt die Dateien nacheinander mit widersprüchlichen Regeln umzuschreiben.

## CLI

Einen vollständigen Projektordner prüft man so:

```sh
php src/codechecker-cli.php --root=/var/www/project .
```

Ordner und Dateien lassen sich innerhalb desselben Projekts kombinieren:

```sh
php src/codechecker-cli.php --root=/var/www/project src public/app.js
```

Benötigt man nur bestimmte Werkzeuge, gibt man deren Kennungen an:

```sh
php src/codechecker-cli.php --root=/var/www/project --tools=phpstan,psalm,eslint,typescript src
php src/codechecker-cli.php --root=/var/www/project --list-tools
```

Die CLI schreibt den Bericht als JSON nach stdout. Eingabefehler erscheinen auf stderr; Optionen stehen vor den Pfaden, ein `--` beendet die Optionsauswertung.

| Exitcode | Bedeutung                                                           |
| -------- | ------------------------------------------------------------------- |
| `0`      | Alle ausgewählten, anwendbaren Prüfungen erfolgreich, keine Befunde |
| `1`      | Prüfungen abgeschlossen, mindestens ein Werkzeug meldet Befunde     |
| `2`      | Ungültige Eingabe oder unvollständige Prüfung                       |

Mit `--timeout=120` legt man das Zeitlimit je Werkzeugaufruf fest (1 bis 600 Sekunden). Es ist kein Gesamtzeitlimit; ein großes Projekt kann entsprechend länger laufen.

## PHP

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use vielhuber\codechecker\codechecker;
use vielhuber\codechecker\Tool;

$checker = new codechecker(root: '/var/www/project');
$report = $checker->check(paths: ['src', 'public/app.js']);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
exit($report->exitCode());
```

Werkzeuge lassen sich auch über das Enum auswählen:

```php
$report = $checker->check(
    paths: ['src/Invoice.php'],
    tools: [Tool::PhpStan, Tool::Psalm]
);

$available = $checker->listTools();
```

## Ergebnisse

Der Bericht enthält "root", die ausgewählten "files", die einzelnen "results" sowie "complete" und "passed". Jedes Werkzeugergebnis nennt Kennung, Status, Prüfumfang, Eingabedateien, Argumentliste, Exitcode, Laufzeit sowie die native Ausgabe auf stdout und stderr.

| Status           | Bedeutung                                    |
| ---------------- | -------------------------------------------- |
| `passed`         | Werkzeug ohne Befunde abgeschlossen          |
| `findings`       | Werkzeug meldet Code-, Typ- oder Stilbefunde |
| `unavailable`    | Kein geeignetes Programm gefunden            |
| `error`          | Ausführung oder Konfiguration fehlgeschlagen |
| `timeout`        | Zeitlimit des Aufrufs überschritten          |
| `not_applicable` | Eingabe enthält keine passende Sprache       |

"complete" bedeutet, dass alle ausgewählten anwendbaren Prüfungen ausgeführt wurden, nicht dass der Code fehlerfrei ist. Erst "passed" verlangt zusätzlich, dass kein Werkzeug Befunde meldet; nicht ausgewählte Werkzeuge sind damit ausdrücklich nicht abgedeckt.

Die Ausgaben sind je Stream und Aufruf auf ein MiB begrenzt. Wird diese Grenze überschritten, setzt codechecker "outputTruncated" und meldet den Lauf als unvollständig, statt einen gekürzten Bericht als vollständig zu behandeln.

## Projektkonfiguration

Vorhandene Programme in "vendor/bin" und "node_modules/.bin" haben Vorrang vor den zentral installierten Versionen; anschließend wird PATH berücksichtigt. Damit bleiben bereits etablierte Projektversionen nutzbar.

Vorhandene Konfigurationen im Projektstamm werden berücksichtigt, beispielsweise "mago.toml", "rector.php", ".php-cs-fixer.dist.php", "psalm.xml", "phpstan.neon", "phpcs.xml", "eslint.config.mjs", "biome.json" und "tsconfig.json". Ohne eigene Regeln gelten die jeweiligen Werkzeugstandards bzw. die mitgelieferten Profile: PSR-12 für PHP CS Fixer und PHP_CodeSniffer, PHPStan-Level 6, Psalm-Level 3 und empfohlene ESLint-/TypeScript-Regeln.

Die TypeScript-Prüfung aktiviert "allowJs", "checkJs" und "noEmit" auch bei vorhandener Konfiguration. So wird JavaScript tatsächlich mitgeprüft, ohne JavaScript-Ausgabedateien zu erzeugen; bestehende Typoptionen werden ansonsten übernommen.

Formatierung und Linting beziehen sich auf die ausgewählten Dateien. Mago-Analyse, PHPStan, Psalm und TypeScript arbeiten projektweit, damit Änderungen an Schnittstellen auch bei unveränderten Aufrufern sichtbar werden; bestehende Psalm-Projektdateien behalten ihren dort definierten Analyseumfang.

## Dateiumfang und Grenzen

Unterstützt werden ".php", ".phtml", ".inc", ".js", ".mjs", ".cjs", ".jsx", ".ts", ".mts", ".cts" und ".tsx". Bei Ordnern werden ".git", "vendor", "node_modules", "dist", "build", "_dist", "_build", "coverage" und ".cache" nicht durchsucht; symbolische Links werden bei der Verzeichnissuche nicht verfolgt.

Explizit übergebene Dateien dürfen auch in diesen Ordnern liegen, müssen aber nach Auflösung innerhalb des gewählten Projektstamms bleiben. Werkzeugkonfigurationen können zusätzliche Ausschlüsse enthalten; für Monorepos führt man die Teilprojekte mit ihren jeweiligen Projektstämmen getrennt aus.

Kein Werkzeugbestand findet sämtliche Fehler. Fachliche Anforderungen, tatsächliche Benutzerabläufe, Framework-Magie und eingebetteter Code in Vue-/Svelte-/HTML-Dateien benötigen zusätzliche passende Prüfungen; erfolgreiche statische Analyse ersetzt weder Tests noch ein fachliches Review.

## MCP-Server

Der MCP-Server verwendet [simplemcp](https://github.com/vielhuber/simplemcp) und stellt dieselben Prüfungen wie Bibliothek und CLI bereit:

- `list_tools`: verfügbare Prüfungen und aufgelöste Programme auflisten.
- `check_code`: Ordner bzw. Dateien prüfen und den Bericht zurückgeben.

Für eine lokale stdio-Verbindung wird der Projektstamm beim Start festgelegt:

```json
{
    "mcpServers": {
        "codechecker": {
            "command": "php",
            "args": ["/opt/codechecker/src/mcp-server.php"],
            "env": {
                "CODECHECKER_ROOT": "/var/www/project"
            }
        }
    }
}
```

Ein Aufruf kann beispielsweise so aussehen:

```json
{
    "paths": ["src", "public/app.js"],
    "tools": ["phpstan", "psalm", "eslint", "typescript"],
    "timeout": 120
}
```

Leere "paths" prüfen den gesamten Projektordner, leere "tools" wählen alle passenden Werkzeuge. Der Aufrufer kann den freigegebenen Projektstamm nicht über ein Tool-Argument erweitern; die Gesamtfrist des MCP-Clients muss zur erwarteten Laufzeit passen.

Soll ein Server mehrere Projekte bedienen, setzt man "CODECHECKER_ROOT" auf deren gemeinsames Oberverzeichnis. Mit "project" wählt man bei `check_code` bzw. `list_tools` einen Projektordner darunter; beispielsweise prüft `{"project":"shop","paths":["src"]}` ausschließlich das Projekt "shop", ohne die Typinformationen anderer Projekte zu vermischen. Auch "project" darf die freigegebene Grenze nicht verlassen.

Für HTTP kopiert man ".env.example" nach ".env" im Paketverzeichnis und setzt "CODECHECKER_ROOT" sowie einen eigenen zufälligen "MCP_TOKEN". Der Endpoint ist "src/mcp-server.php"; Anfragen benötigen `Authorization: Bearer <MCP_TOKEN>`, HTTPS und einen Webserver, der den direkten Zugriff auf ".env" sperrt. Ohne Token werden HTTP-Anfragen abgewiesen.

Der stdio-Betrieb benötigt keinen Token. Die Einbindung in Charly oder einen anderen MCP-Client erfolgt separat; codechecker verändert dessen Konfiguration nicht automatisch.

## Sicherheit

codechecker setzt keine Fix-, Write- oder Emit-Optionen und installiert während einer Prüfung keine Werkzeuge in das Zielprojekt. Diagnoseberichte bleiben lokal; die temporären Arbeitsverzeichnisse werden nach den Aufrufen entfernt.

Projektkonfigurationen und Plugins sind jedoch ausführbarer PHP- bzw. JavaScript-Code. Der Projektstamm ist eine Grenze für übergebene Dateipfade, keine Betriebssystem-Sandbox: Nicht vertrauenswürdige Repositories gehören in eine isolierte Umgebung ohne Zugangsdaten oder schreibbare fremde Projekte.

## Eigene Projektprüfung

```sh
composer validate --strict
composer validate --strict --working-dir=tools
npm run format:check
composer check
```

Die Einzelwerkzeuge stehen in "tools/composer.json" und "package.json". Der separate Composer-Lock hält die PHP-Werkzeugversionen einschließlich ihrer Abhängigkeiten fest; die JS-Werkzeuge haben feste Versionsangaben. Updates erfolgen bewusst dort, getrennt von den Runtime-Abhängigkeiten der PHP-Bibliothek.

Die CI verwendet Prettier als kanonischen Formatter und prüft Syntax, Mago-Analyse, PHPStan, Psalm, ESLint, Biome, TypeScript und Oxlint. Mit `composer check` erhält man zusätzlich sämtliche überlappenden Stil- und Refactoring-Prüfungen; unterschiedliche Formatvorstellungen bleiben dabei als Befunde sichtbar.
