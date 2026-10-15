<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

/**
 * Expose entry points discovered by simplemcp through attributes.
 *
 * @api
 */
final class McpTools
{
    /**
     * Discover supported checks without running processes or installing packages.
     *
     * @return list<array{name: string, language: string, scope: string, executable: ?string}>
     */
    #[
        McpTool(
            name: 'list_tools',
            description: 'List the supported PHP and JavaScript checks and their resolved executables. Does not install anything.'
        )
    ]
    public function listTools(
        #[Schema(type: 'string', description: 'Project directory inside CODECHECKER_ROOT.')] string $project = '.'
    ): array {
        return $this->checker($project)->listTools();
    }

    /**
     * Inspect only paths inside the server-configured project root.
     *
     * @param list<string> $paths
     * @param list<string> $tools
     */
    #[
        McpTool(
            name: 'check_code',
            description: 'Read-only checks for PHP, JS, JSX and TypeScript files or folders inside CODECHECKER_ROOT. Empty paths checks the whole project. Empty tools selects all checks. Type analysis remains project-wide; missing tools, failures and timeouts make the report incomplete. No fixes or installations.'
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
            Schema(
                type: 'string',
                description: 'Project directory inside CODECHECKER_ROOT; paths are relative to this project.'
            )
        ]
        string $project = '.'
    ): Report {
        $selection =
            $tools === []
                ? null
                : array_map(
                    static fn(string $tool): Tool => Tool::tryFrom($tool) ??
                        throw CodecheckerException::invalidOptions(),
                    $tools
                );
        return $this->checker(project: $project, timeout: $timeout)->check(paths: $paths, tools: $selection);
    }

    /** Keep per-project type analysis inside the server's permitted workspace. */
    private function checker(string $project, int $timeout = 120): codechecker
    {
        $workspace = new Workspace($_ENV['CODECHECKER_ROOT'] ?? (getenv('CODECHECKER_ROOT') ?: (getcwd() ?: '.')));
        return new codechecker(root: $workspace->resolve($project), timeout: $timeout);
    }
}
