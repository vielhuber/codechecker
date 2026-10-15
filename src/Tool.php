<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

enum Tool: string
{
    case PhpSyntax = 'php-syntax';
    case MagoFormat = 'mago-format';
    case MagoLint = 'mago-lint';
    case MagoAnalyze = 'mago-analyze';
    case Rector = 'rector';
    case PhpCsFixer = 'php-cs-fixer';
    case Psalm = 'psalm';
    case PhpStan = 'phpstan';
    case PhpCodeSniffer = 'phpcs';
    case Eslint = 'eslint';
    case Biome = 'biome';
    case Prettier = 'prettier';
    case TypeScript = 'typescript';
    case Oxlint = 'oxlint';

    /** Distinguish file checks from analyzers that need the complete project graph. */
    public function projectWide(): bool
    {
        return in_array($this, [self::MagoAnalyze, self::Psalm, self::PhpStan, self::TypeScript], true);
    }

    /** Select the language without depending on an installed executable. */
    public function language(): string
    {
        return in_array($this, [self::Eslint, self::Biome, self::Prettier, self::TypeScript, self::Oxlint], true)
            ? 'javascript'
            : 'php';
    }

    /** Resolve shared executables used by multiple independent checks. */
    public function executable(): string
    {
        return match ($this) {
            self::PhpSyntax => 'php',
            self::MagoFormat, self::MagoLint, self::MagoAnalyze => 'mago',
            self::TypeScript => 'tsc',
            default => $this->value
        };
    }
}
