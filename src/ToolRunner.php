<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;

final class ToolRunner
{
    private const OUTPUT_LIMIT = 1048576;

    /**
     * Run argument arrays without a shell and retain failures as report data.
     *
     * @param list<string> $files
     */
    public function run(Tool $tool, Workspace $workspace, array $files, int $timeout): ToolResult
    {
        $scope = $tool->projectWide() ? 'project' : 'files';
        $binary = new ToolLocator()->find($tool, $workspace);
        if ($binary === null) {
            return new ToolResult(
                tool: $tool,
                status: Status::Unavailable,
                scope: $scope,
                files: $files,
                errorOutput: 'Executable not found: ' . $tool->executable()
            );
        }
        $cache = sys_get_temp_dir() . '/codechecker-' . bin2hex(random_bytes(12));
        if (!mkdir($cache, 0700)) {
            return new ToolResult(
                tool: $tool,
                status: Status::Error,
                scope: $scope,
                files: $files,
                errorOutput: 'Could not create a private working directory.'
            );
        }
        $command = [$binary];
        $started = microtime(true);
        $output = '';
        $errorOutput = '';
        $truncated = false;
        $exitCode = null;
        $status = Status::Error;
        try {
            $configurationDirectory = __DIR__ . '/../config';
            $arguments = match ($tool) {
                Tool::PhpSyntax => ['-l'],
                Tool::MagoFormat => ['--threads=2', 'format', '--check'],
                Tool::MagoLint => ['--threads=2', 'lint', '--reporting-format=json'],
                Tool::MagoAnalyze => ['--threads=2', 'analyze', '--reporting-format=json'],
                Tool::Rector => [
                    'process',
                    '--dry-run',
                    '--no-progress-bar',
                    '--no-diffs',
                    '--output-format=json',
                    '--config=' .
                    ($workspace->configuration('rector.php', 'rector.php.dist') ??
                        $configurationDirectory . '/rector.php')
                ],
                Tool::PhpCsFixer => [
                    'check',
                    '--format=json',
                    '--using-cache=no',
                    '--allow-risky=no',
                    '--config=' .
                    ($workspace->configuration('.php-cs-fixer.php', '.php-cs-fixer.dist.php') ??
                        $configurationDirectory . '/php-cs-fixer.php')
                ],
                Tool::Psalm => [
                    '--output-format=json',
                    '--no-progress',
                    '--no-cache',
                    '--threads=1',
                    '--root=' . $workspace->root
                ],
                Tool::PhpStan => ['analyse', '--no-progress', '--error-format=json'],
                Tool::PhpCodeSniffer => [
                    '--report=json',
                    '--extensions=php,phtml,inc',
                    '--standard=' .
                    ($workspace->configuration('phpcs.xml', '.phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml.dist') ??
                        'PSR12')
                ],
                Tool::Eslint => ['--format=json', '--max-warnings=0'],
                Tool::Biome => ['check', '--reporter=json', '--max-diagnostics=none', '--error-on-warnings'],
                Tool::Prettier => ['--check'],
                Tool::TypeScript => ['--pretty', 'false'],
                Tool::Oxlint => ['--format=json', '--deny-warnings', '--threads=2']
            };
            $targets = $files;
            if ($tool === Tool::MagoAnalyze) {
                $existing = $workspace->configuration('mago.toml');
                $configuration =
                    $existing === null
                        ? ''
                        : 'extends = ' . json_encode([$existing], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                $configuration .=
                    "[source]\nworkspace = " .
                    json_encode($workspace->root, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) .
                    "\npaths = " .
                    json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) .
                    "\n";
                if ($existing === null && is_dir($workspace->root . '/vendor')) {
                    $configuration .=
                        'includes = ' .
                        json_encode([$workspace->root . '/vendor'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) .
                        "\n";
                }
                file_put_contents($cache . '/mago.toml', $configuration);
                $arguments = ['--config=' . $cache . '/mago.toml', ...$arguments];
                $targets = [];
            }
            if ($tool === Tool::PhpStan) {
                $configuration = ['parameters' => ['paths' => $files, 'tmpDir' => $cache . '/phpstan']];
                $existing = $workspace->configuration(
                    'phpstan.neon',
                    'phpstan.neon.dist',
                    'phpstan.dist.neon',
                    'phpstan.php'
                );
                if ($existing !== null) {
                    $configuration['includes'] = [$existing];
                }
                if ($existing === null) {
                    $configuration['parameters']['level'] = 6;
                }
                file_put_contents($cache . '/phpstan.php', '<?php return ' . var_export($configuration, true) . ';');
                $arguments[] = '--configuration=' . $cache . '/phpstan.php';
                $targets = [];
            }
            if ($tool === Tool::Psalm) {
                $existing = $workspace->configuration('psalm.xml', 'psalm.xml.dist');
                if ($existing !== null) {
                    $arguments[] = '--config=' . $existing;
                }
                if ($existing === null) {
                    $attributes =
                        'errorLevel="3" resolveFromConfigFile="false" cacheDirectory="' .
                        htmlspecialchars($cache . '/psalm', ENT_QUOTES | ENT_XML1) .
                        '"';
                    if (is_file($workspace->root . '/vendor/autoload.php')) {
                        $attributes .=
                            ' autoloader="' .
                            htmlspecialchars($workspace->root . '/vendor/autoload.php', ENT_QUOTES | ENT_XML1) .
                            '"';
                    }
                    $sources = implode(
                        '',
                        array_map(
                            static fn(string $file): string => '<file name="' .
                                htmlspecialchars($file, ENT_QUOTES | ENT_XML1) .
                                '"/>',
                            $files
                        )
                    );
                    file_put_contents(
                        $cache . '/psalm.xml',
                        '<?xml version="1.0"?><psalm ' .
                            $attributes .
                            '><projectFiles>' .
                            $sources .
                            '</projectFiles></psalm>'
                    );
                    $arguments[] = '--config=' . $cache . '/psalm.xml';
                }
                $targets = [];
            }
            if ($tool === Tool::TypeScript) {
                $configuration = [
                    'extends' =>
                        $workspace->configuration('tsconfig.json', 'jsconfig.json') ??
                        $configurationDirectory . '/typescript.json',
                    'compilerOptions' => [
                        'allowJs' => true,
                        'checkJs' => true,
                        'noEmit' => true,
                        'incremental' => true,
                        'tsBuildInfoFile' => $cache . '/typescript.tsbuildinfo',
                        'typeRoots' => [$workspace->root . '/node_modules/@types']
                    ],
                    'files' => $files,
                    'include' => []
                ];
                file_put_contents($cache . '/typescript.json', json_encode($configuration, JSON_THROW_ON_ERROR));
                $arguments[] = '--project';
                $arguments[] = $cache . '/typescript.json';
                $targets = [];
            }
            if (
                $tool === Tool::Eslint &&
                $workspace->configuration(
                    'eslint.config.js',
                    'eslint.config.mjs',
                    'eslint.config.cjs',
                    'eslint.config.ts',
                    'eslint.config.mts',
                    'eslint.config.cts'
                ) === null
            ) {
                $arguments[] = '--config=' . $configurationDirectory . '/eslint.config.mjs';
            }
            $command = [$binary, ...$arguments, ...$targets];
            $process = new Process(
                $command,
                $workspace->root,
                ['CODECHECKER_CACHE' => $cache, 'NO_COLOR' => '1', 'FORCE_COLOR' => false],
                timeout: $timeout
            );
            $exitCode = $process->run(static function (string $type, string $chunk) use (
                &$output,
                &$errorOutput,
                &$truncated,
                $process
            ): void {
                $buffer = $type === Process::ERR ? $errorOutput : $output;
                $available = max(0, self::OUTPUT_LIMIT - strlen($buffer));
                $truncated = $truncated || strlen($chunk) > $available;
                $buffer .= substr($chunk, 0, $available);
                if ($type === Process::ERR) {
                    $errorOutput = $buffer;
                }
                if ($type !== Process::ERR) {
                    $output = $buffer;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            $findingCodes = match ($tool) {
                Tool::PhpSyntax => [255],
                Tool::Rector, Tool::Psalm => [2],
                Tool::PhpCsFixer => [4, 8, 12],
                Tool::PhpCodeSniffer => [1, 2, 3],
                Tool::TypeScript => [1, 2],
                default => [1]
            };
            $status =
                $exitCode === 0
                    ? Status::Passed
                    : (in_array($exitCode, $findingCodes, true)
                        ? Status::Findings
                        : Status::Error);
            if ($tool === Tool::PhpStan && $exitCode !== 0) {
                $diagnostics = $this->diagnosticObject(json_decode($output, true));
                if ($diagnostics === null || ($diagnostics['errors'] ?? []) !== []) {
                    $status = Status::Error;
                }
            }
            if (
                in_array($tool, [Tool::PhpCodeSniffer, Tool::Biome], true) &&
                $exitCode !== 0 &&
                $this->diagnosticObject(json_decode($output, true)) === null
            ) {
                $status = Status::Error;
            }
            if (
                $tool === Tool::TypeScript &&
                $exitCode !== 0 &&
                preg_match('/error TS(?:5[0-9]{3}|6[0-9]{3}|1800[23]):/', $output . $errorOutput) === 1
            ) {
                $status = Status::Error;
            }
            if (in_array($tool, [Tool::MagoLint, Tool::MagoAnalyze], true) && $exitCode === 0) {
                $diagnostics = $this->diagnosticObject(json_decode($output, true));
                if ($diagnostics !== null && ($diagnostics['issues'] ?? []) !== []) {
                    $status = Status::Findings;
                }
            }
            if ($truncated) {
                $status = Status::Error;
            }
        } catch (ProcessTimedOutException $exception) {
            $status = Status::Timeout;
            $errorOutput .= $exception->getMessage();
        } catch (ProcessException | \JsonException $exception) {
            $errorOutput .= $exception->getMessage();
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo) {
                    $directory = $file->isDir() && !$file->isLink();
                    if ($directory) {
                        rmdir($file->getPathname());
                    }
                    if (!$directory) {
                        unlink($file->getPathname());
                    }
                }
            }
            rmdir($cache);
        }
        return new ToolResult(
            tool: $tool,
            status: $status,
            scope: $scope,
            files: $files,
            command: $command,
            exitCode: $exitCode,
            output: $output,
            errorOutput: $errorOutput,
            seconds: round(microtime(true) - $started, 3),
            outputTruncated: $truncated
        );
    }

    /**
     * Validate decoded JSON before interpreting tool-specific fields.
     *
     * @return array<array-key, mixed>|null
     */
    private function diagnosticObject(mixed $decoded): ?array
    {
        return is_array($decoded) ? $decoded : null;
    }
}
