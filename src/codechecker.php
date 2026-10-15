<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

final readonly class codechecker
{
    private Workspace $workspace;

    /** Bind all library and MCP checks to one explicit project boundary. */
    public function __construct(?string $root = null, private int $timeout = 120)
    {
        if ($timeout < 1 || $timeout > 600) {
            throw CodecheckerException::invalidOptions();
        }
        $this->workspace = new Workspace(
            $root ?? ($_ENV['CODECHECKER_ROOT'] ?? (getenv('CODECHECKER_ROOT') ?: (getcwd() ?: '.')))
        );
    }

    /**
     * Inspect executable availability without starting analyzers or installing dependencies.
     *
     * @return list<array{name: string, language: string, scope: string, executable: ?string}>
     */
    public function listTools(): array
    {
        $locator = new ToolLocator();
        return array_map(
            fn(Tool $tool): array => [
                'name' => $tool->value,
                'language' => $tool->language(),
                'scope' => $tool->projectWide() ? 'project' : 'files',
                'executable' => $locator->find($tool, $this->workspace)
            ],
            Tool::cases()
        );
    }

    /**
     * Inspect selected sources while keeping type analysis project-wide.
     *
     * @param list<string> $paths
     * @param list<Tool>|null $tools
     */
    public function check(array $paths = [], ?array $tools = null): Report
    {
        $tools ??= Tool::cases();
        if ($tools === []) {
            throw CodecheckerException::invalidOptions();
        }
        $selected = $this->workspace->files(...$paths);
        if ($selected === []) {
            throw CodecheckerException::emptySelection();
        }
        $project = array_values(array_unique([...$this->workspace->files(), ...$selected]));
        $runner = new ToolRunner();
        $results = [];
        foreach (array_unique($tools, SORT_REGULAR) as $tool) {
            $extensions = $tool->language() === 'php' ? Workspace::PHP_EXTENSIONS : Workspace::JAVASCRIPT_EXTENSIONS;
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
                $results[] = new ToolResult(tool: $tool, status: Status::NotApplicable, scope: $scope);
                continue;
            }
            $files = array_values(
                array_filter(
                    $tool->projectWide() ? $project : $selected,
                    static fn(string $file): bool => in_array(
                        strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                        $extensions,
                        true
                    )
                )
            );
            foreach (
                $tool->projectWide() ? [$files] : array_chunk($files, $tool === Tool::PhpSyntax ? 1 : 100)
                as $batch
            ) {
                $results[] = $runner->run(
                    tool: $tool,
                    workspace: $this->workspace,
                    files: $batch,
                    timeout: $this->timeout
                );
            }
        }
        return new Report(root: $this->workspace->root, files: $selected, results: $results);
    }
}
