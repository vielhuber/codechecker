<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

final class exception extends \InvalidArgumentException
{
    /** Reject missing roots before any analyzer can execute. */
    public static function invalidRoot(string $root): self
    {
        return new self('The project root must be an existing readable directory: ' . $root);
    }

    /** Keep requests inside the explicitly selected project. */
    public static function invalidPath(string $path): self
    {
        return new self('The path must exist inside the project root: ' . $path);
    }

    /** Reject explicit files rather than silently ignoring unsupported input. */
    public static function unsupportedFile(string $path): self
    {
        return new self('Unsupported or unreadable source file: ' . $path);
    }

    /** Prevent accidentally succeeding with an empty inspection. */
    public static function emptySelection(): self
    {
        return new self('No supported PHP or JavaScript/TypeScript source files were selected.');
    }

    /** Reject invalid execution settings before starting subprocesses. */
    public static function invalidOptions(): self
    {
        return new self('Timeout must be between 1 and 600 seconds and every selected tool must be supported.');
    }
}

enum status: string
{
    case Passed = 'passed';
    case Findings = 'findings';
    case Unavailable = 'unavailable';
    case Error = 'error';
    case Timeout = 'timeout';
    case NotApplicable = 'not_applicable';
}

enum tool: string
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

final readonly class result implements \JsonSerializable
{
    /**
     * Preserve native diagnostics even when a command cannot finish successfully.
     *
     * @param list<string> $files
     * @param list<string> $command
     */
    public function __construct(
        public tool $tool,
        public status $status,
        public string $scope,
        public array $files = [],
        public array $command = [],
        public ?int $exitCode = null,
        public string $output = '',
        public string $errorOutput = '',
        public float $seconds = 0.0,
        public bool $outputTruncated = false
    ) {}

    /**
     * Keep the transport representation explicit for CLI and MCP consumers.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'tool' => $this->tool->value,
            'status' => $this->status->value,
            'scope' => $this->scope,
            'files' => $this->files,
            'command' => $this->command,
            'exitCode' => $this->exitCode,
            'output' => $this->output,
            'errorOutput' => $this->errorOutput,
            'seconds' => $this->seconds,
            'outputTruncated' => $this->outputTruncated
        ];
    }
}

final readonly class report implements \JsonSerializable
{
    public bool $complete;
    public bool $passed;

    /**
     * Keep unavailable checks distinct from completed checks with findings.
     *
     * @param list<string> $files
     * @param list<result> $results
     */
    public function __construct(public string $root, public array $files, public array $results)
    {
        $this->complete =
            $files !== [] &&
            array_any($results, static fn(result $result): bool => $result->status !== status::NotApplicable) &&
            array_all(
                $results,
                static fn(result $result): bool => in_array(
                    $result->status,
                    [status::Passed, status::Findings, status::NotApplicable],
                    true
                )
            );
        $this->passed =
            $this->complete &&
            array_all(
                $results,
                static fn(result $result): bool => in_array(
                    $result->status,
                    [status::Passed, status::NotApplicable],
                    true
                )
            );
    }

    /** Distinguish findings from incomplete execution in CI. */
    public function exitCode(): int
    {
        if (!$this->complete) {
            return 2;
        }
        return $this->passed ? 0 : 1;
    }

    /**
     * Expose completion separately from the absence of diagnostics.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'root' => $this->root,
            'files' => $this->files,
            'results' => $this->results,
            'complete' => $this->complete,
            'passed' => $this->passed
        ];
    }
}

final readonly class codechecker
{
    private workspace $workspace;

    /** Bind all library and MCP checks to one explicit project boundary. */
    public function __construct(?string $root = null)
    {
        $this->workspace = new workspace(
            $root ?? ($_ENV['CODECHECKER_ROOT'] ?? (getenv('CODECHECKER_ROOT') ?: (getcwd() ?: '.')))
        );
    }

    /**
     * Inspect executable availability without starting analyzers or installing dependencies.
     *
     * @return list<array{name: string, language: string, scope: string, executable: ?string}>
     */
    #[
        McpTool(
            name: 'list_tools',
            description: 'List supported checks and their installed executables. No installation.'
        )
    ]
    public function listTools(
        #[Schema(description: 'Project directory inside CODECHECKER_ROOT.')] string $project = '.'
    ): array {
        $workspace = new workspace($this->workspace->resolve($project));
        $runner = new runner();
        return array_map(
            fn(tool $tool): array => [
                'name' => $tool->value,
                'language' => $tool->language(),
                'scope' => $tool->projectWide() ? 'project' : 'files',
                'executable' => $runner->locate($tool, $workspace)
            ],
            tool::cases()
        );
    }

    /**
     * Inspect selected sources while keeping type analysis project-wide.
     *
     * @param list<string> $paths
     * @param list<string> $tools
     */
    #[
        McpTool(
            name: 'check_code',
            description: 'Read-only PHP, JavaScript and TypeScript checks. Empty paths checks the whole project; empty tools selects all checks. Type analysis remains project-wide. No fixes or installations.'
        )
    ]
    public function check(
        #[Schema(type: 'array', items: ['type' => 'string', 'minLength' => 1])] array $paths = [],
        #[
            Schema(
                type: 'array',
                items: [
                    'type' => 'string',
                    'enum' => [
                        'php-syntax',
                        'mago-format',
                        'mago-lint',
                        'mago-analyze',
                        'rector',
                        'php-cs-fixer',
                        'psalm',
                        'phpstan',
                        'phpcs',
                        'eslint',
                        'biome',
                        'prettier',
                        'typescript',
                        'oxlint'
                    ]
                ]
            )
        ]
        array $tools = [],
        #[Schema(type: 'integer', minimum: 1, maximum: 600)] int $timeout = 120,
        #[
            Schema(description: 'Project directory inside CODECHECKER_ROOT; paths are relative to this project.')
        ]
        string $project = '.'
    ): report {
        if ($timeout < 1 || $timeout > 600) {
            throw exception::invalidOptions();
        }
        $tools =
            $tools === []
                ? tool::cases()
                : array_map(
                    static fn(string $name): tool => tool::tryFrom($name) ?? throw exception::invalidOptions(),
                    $tools
                );
        $workspace = new workspace($this->workspace->resolve($project));
        $selected = $workspace->files(...$paths);
        if ($selected === []) {
            throw exception::emptySelection();
        }
        $projectFiles = array_values(array_unique([...$workspace->files(), ...$selected]));
        $runner = new runner();
        $results = [];
        foreach (array_unique($tools, SORT_REGULAR) as $tool) {
            $extensions = $tool->language() === 'php' ? workspace::PHP_EXTENSIONS : workspace::JAVASCRIPT_EXTENSIONS;
            $trigger = array_filter(
                $selected,
                static fn(string $file): bool => in_array(
                    strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                    $extensions,
                    true
                )
            );
            $scope = $tool->projectWide() ? 'project' : 'files';
            if ($trigger === []) {
                $results[] = new result(tool: $tool, status: status::NotApplicable, scope: $scope);
                continue;
            }
            $files = array_values(
                array_filter(
                    $tool->projectWide() ? $projectFiles : $selected,
                    static fn(string $file): bool => in_array(
                        strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                        $extensions,
                        true
                    )
                )
            );
            foreach (
                $tool->projectWide() ? [$files] : array_chunk($files, $tool === tool::PhpSyntax ? 1 : 100)
                as $batch
            ) {
                $results[] = $runner->run(tool: $tool, workspace: $workspace, files: $batch, timeout: $timeout);
            }
        }
        return new report(root: $workspace->root, files: $selected, results: $results);
    }
}
