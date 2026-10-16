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
        return new self(
            'Timeout must be between 1 and 600 seconds, tools must be supported and mode must be files or project.'
        );
    }

    /** Prevent reporting success when native diagnostics could not be retained. */
    public static function outputStorageFailed(): self
    {
        return new self('Could not preserve the complete analyzer output.');
    }

    /** Reject broad checks before discovering or executing project sources. */
    public static function invalidFileMode(): self
    {
        return new self(
            'File mode requires explicit source files and file-scoped tools; use project mode for whole-project analysis.'
        );
    }

    /** Keep report identifiers separate from caller-controlled filesystem paths. */
    public static function reportNotFound(): self
    {
        return new self('The report does not exist in this project.');
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
        public bool $outputTruncated = false,
        public ?string $outputFile = null,
        public ?string $errorOutputFile = null
    ) {}

    /**
     * Keep the transport representation explicit for CLI and MCP consumers.
     *
     * @return array{tool: string, status: string, scope: string, files: list<string>, command: list<string>, exitCode: ?int, output: string, errorOutput: string, seconds: float, outputTruncated: bool, outputFile: ?string, errorOutputFile: ?string}
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
            'outputTruncated' => $this->outputTruncated,
            'outputFile' => $this->outputFile,
            'errorOutputFile' => $this->errorOutputFile
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
     * @return array{root: string, files: list<string>, results: list<result>, complete: bool, passed: bool}
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
     * Return a compact, persistent report without exposing broad defaults to MCP callers.
     *
     * @param list<string> $paths
     * @param list<string> $tools
     * @return array{reportId: string, root: string, files: list<string>, complete: bool, passed: bool, results: list<array<string, mixed>>, comparison: array<string, mixed>, findings: list<array<string, mixed>>, omittedFindings: int}
     * @api
     */
    #[
        McpTool(
            name: 'check_code',
            description: 'Read-only checks with persistent full reports and compact findings. File mode requires explicit files and file-scoped tools. Only explicitly requested project mode permits directories, empty selections or project-wide analysis. No fixes or installations.'
        )
    ]
    public function checkCode(
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
        string $project = '.',
        #[Schema(enum: ['files', 'project'])] string $mode = 'files'
    ): array {
        $report = $this->check(paths: $paths, tools: $tools, timeout: $timeout, project: $project, mode: $mode);
        return new reports($report->root)->save($report);
    }

    /**
     * Preserve PHP API defaults while allowing callers to enforce a narrow execution mode.
     *
     * @param list<string> $paths
     * @param list<string> $tools
     */
    public function check(
        array $paths = [],
        array $tools = [],
        int $timeout = 120,
        string $project = '.',
        string $mode = 'project'
    ): report {
        if ($timeout < 1 || $timeout > 600) {
            throw exception::invalidOptions();
        }
        if (!in_array($mode, ['files', 'project'], true)) {
            throw exception::invalidOptions();
        }
        if ($mode === 'files' && ($paths === [] || $tools === [])) {
            throw exception::invalidFileMode();
        }
        $tools =
            $tools === []
                ? tool::cases()
                : array_map(
                    static fn(string $name): tool => tool::tryFrom($name) ?? throw exception::invalidOptions(),
                    $tools
                );
        $workspace = new workspace($this->workspace->resolve($project));
        if ($mode === 'files') {
            foreach ($paths as $path) {
                if (!is_file($workspace->resolve($path))) {
                    throw exception::invalidFileMode();
                }
            }
            foreach ($tools as $tool) {
                if ($tool->projectWide()) {
                    throw exception::invalidFileMode();
                }
            }
        }
        $selected = $workspace->files(...$paths);
        if ($selected === []) {
            throw exception::emptySelection();
        }
        $projectFiles = [];
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
            if ($tool->projectWide() && $projectFiles === []) {
                $projectFiles = array_values(array_unique([...$workspace->files(), ...$selected]));
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

    /**
     * Return complete diagnostics only from the selected project's private report store.
     *
     * @return array<string, mixed>
     */
    #[
        McpTool(
            name: 'get_report',
            description: 'Read a complete saved report in the selected project, optionally limited to one tool. Report IDs never accept filesystem paths.'
        )
    ]
    public function getReport(
        #[Schema(pattern: '^[a-f0-9]{32}$')] string $reportId,
        #[Schema(description: 'Project directory inside CODECHECKER_ROOT.')] string $project = '.',
        #[Schema(description: 'Optional tool name; empty returns all tools.')] string $tool = ''
    ): array {
        if ($tool !== '' && tool::tryFrom($tool) === null) {
            throw exception::invalidOptions();
        }
        $report = new reports($this->workspace->resolve($project))->read($reportId);
        if ($tool !== '') {
            foreach (['results', 'findings', 'resolvedFindings'] as $field) {
                /** @var list<array<string, mixed>> $entries */
                $entries = $report[$field];
                $report[$field] = array_values(
                    array_filter($entries, static fn(array $entry): bool => $entry['tool'] === $tool)
                );
            }
            unset($report['summary']);
        }
        return $report;
    }
}
