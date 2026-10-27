<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;

final class runner
{
    private const OUTPUT_LIMIT = 1048576;
    private const OUTPUT_RETENTION = 86_400;

    /**
     * Run argument arrays without a shell and retain failures as report data.
     *
     * @param list<string> $files
     */
    public function run(tool $tool, workspace $workspace, array $files, int $timeout): result
    {
        $scope = $tool->projectWide() ? 'project' : 'files';
        if (in_array($tool, [tool::MagoFormat, tool::PhpCsFixer], strict: true)) {
            $formatter = $workspace->phpFormatter($files[0], $timeout);
            if ($formatter !== $tool) {
                return new result(
                    tool: $tool,
                    status: status::NotApplicable,
                    scope: $scope,
                    files: $files,
                    output: 'PHP formatting is checked by ' . $formatter->value . '.'
                );
            }
        }
        $binary = $this->locate($tool, $workspace);
        if ($binary === null) {
            return new result(
                tool: $tool,
                status: status::Unavailable,
                scope: $scope,
                files: $files,
                errorOutput: 'Executable not found: ' . $tool->executable()
            );
        }
        $cachePrefix = sys_get_temp_dir() . '/codechecker-' . substr(hash('sha256', $workspace->root), 0, 16) . '-';
        foreach (glob($cachePrefix . '*/stdout.log') ?: [] as $previousOutput) {
            $directory = dirname($previousOutput);
            $modified = filemtime($previousOutput);
            if (
                $modified !== false &&
                $modified < time() - self::OUTPUT_RETENTION &&
                glob($directory . '/*') === [$directory . '/stderr.log', $directory . '/stdout.log']
            ) {
                unlink($directory . '/stderr.log');
                unlink($previousOutput);
                rmdir($directory);
            }
        }
        $cache = $cachePrefix . bin2hex(random_bytes(12));
        if (!mkdir($cache, 0700)) {
            return new result(
                tool: $tool,
                status: status::Error,
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
        $status = status::Error;
        $outputStream = null;
        $errorStream = null;
        $outputFile = null;
        $errorOutputFile = null;
        $process = null;
        try {
            $outputStream = fopen($cache . '/stdout.log', 'x+b');
            $errorStream = fopen($cache . '/stderr.log', 'x+b');
            if (
                $outputStream === false ||
                $errorStream === false ||
                !chmod($cache . '/stdout.log', 0600) ||
                !chmod($cache . '/stderr.log', 0600)
            ) {
                throw exception::outputStorageFailed();
            }
            $configurationDirectory = __DIR__ . '/../config';
            $arguments = match ($tool) {
                tool::PhpSyntax => ['-l'],
                tool::MagoFormat => ['--threads=2', 'format', '--check'],
                tool::MagoLint => ['--threads=2', 'lint', '--reporting-format=json'],
                tool::MagoAnalyze => ['--threads=2', 'analyze', '--reporting-format=json'],
                tool::Rector => [
                    'process',
                    '--dry-run',
                    '--no-progress-bar',
                    '--no-diffs',
                    '--output-format=json',
                    '--config=' .
                    ($workspace->configuration('rector.php', 'rector.php.dist') ??
                        $configurationDirectory . '/rector.php')
                ],
                tool::PhpCsFixer => [
                    'check',
                    '--format=json',
                    '--using-cache=no',
                    '--allow-risky=no',
                    '--config=' .
                    ($workspace->configuration('.php-cs-fixer.php', '.php-cs-fixer.dist.php') ??
                        $configurationDirectory . '/php-cs-fixer.php')
                ],
                tool::Psalm => [
                    '--output-format=json',
                    '--no-progress',
                    '--no-cache',
                    '--threads=1',
                    '--root=' . $workspace->root
                ],
                tool::PhpStan => ['analyse', '--no-progress', '--error-format=json'],
                tool::PhpCodeSniffer => [
                    '--report=json',
                    '--extensions=php,phtml,inc',
                    '--standard=' .
                    ($workspace->configuration('.phpcs.xml', 'phpcs.xml', '.phpcs.xml.dist', 'phpcs.xml.dist') ??
                        $configurationDirectory . '/phpcs.xml')
                ],
                tool::Eslint => ['--format=json', '--max-warnings=0'],
                tool::Biome => [
                    'check',
                    '--skip=style/useConst',
                    '--skip=complexity/noStaticOnlyClass',
                    '--formatter-enabled=false',
                    '--reporter=json',
                    '--max-diagnostics=none',
                    '--error-on-warnings'
                ],
                tool::Prettier => ['--check'],
                tool::TypeScript => ['--pretty', 'false'],
                tool::Oxlint => ['--format=json', '--deny-warnings', '--threads=2']
            };
            if ($tool === tool::MagoLint) {
                $configurations = [$configurationDirectory . '/mago-lint.toml'];
                $existing = $workspace->configuration('mago.toml');
                if ($existing !== null) {
                    $configurations[] = $existing;
                }
                file_put_contents(
                    $cache . '/mago-lint.toml',
                    'extends = ' . json_encode($configurations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"
                );
                $arguments = [
                    '--workspace=' . $workspace->root,
                    '--config=' . $cache . '/mago-lint.toml',
                    ...$arguments
                ];
            }
            $targets = $files;
            if ($tool === tool::MagoAnalyze) {
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
            if ($tool === tool::PhpStan) {
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
            if ($tool === tool::Psalm) {
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
            if ($tool === tool::TypeScript) {
                $existing = $workspace->configuration('tsconfig.json', 'jsconfig.json');
                if ($existing === null) {
                    $configuration = [
                        'extends' => $configurationDirectory . '/typescript.json',
                        'compilerOptions' => [
                            'allowJs' => true,
                            'checkJs' => true,
                            'typeRoots' => [$workspace->root . '/node_modules/@types']
                        ],
                        'files' => $files,
                        'include' => []
                    ];
                    file_put_contents($cache . '/typescript.json', json_encode($configuration, JSON_THROW_ON_ERROR));
                }
                $arguments = [
                    ...$arguments,
                    '--project',
                    $existing ?? $cache . '/typescript.json',
                    '--noEmit',
                    '--incremental',
                    '--tsBuildInfoFile',
                    $cache . '/typescript.tsbuildinfo'
                ];
                $targets = [];
            }
            if (
                $tool === tool::Eslint &&
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
                $outputStream,
                $errorStream,
                $process
            ): void {
                $stream = $type === Process::ERR ? $errorStream : $outputStream;
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw exception::outputStorageFailed();
                }
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
            $output = $this->preview($output);
            $errorOutput = $this->preview($errorOutput);
            $diagnostics = null;
            if (
                in_array(
                    $tool,
                    [tool::PhpStan, tool::PhpCodeSniffer, tool::Biome, tool::MagoLint, tool::MagoAnalyze],
                    true
                )
            ) {
                $diagnostics = $this->diagnosticObject(
                    json_decode((string) file_get_contents($cache . '/stdout.log'), true)
                );
            }
            $findingCodes = match ($tool) {
                tool::PhpSyntax => [255],
                tool::Rector, tool::Psalm => [2],
                tool::PhpCsFixer => [4, 8, 12],
                tool::PhpCodeSniffer => [1, 2, 3],
                tool::TypeScript => [1, 2],
                default => [1]
            };
            $status =
                $exitCode === 0
                    ? status::Passed
                    : (in_array($exitCode, $findingCodes, true)
                        ? status::Findings
                        : status::Error);
            if (
                $tool === tool::PhpStan &&
                $exitCode !== 0 &&
                ($diagnostics === null || ($diagnostics['errors'] ?? []) !== [])
            ) {
                $status = status::Error;
            }
            if (
                in_array($tool, [tool::PhpCodeSniffer, tool::Biome], true) &&
                $exitCode !== 0 &&
                $diagnostics === null
            ) {
                $status = status::Error;
            }
            if (
                $tool === tool::TypeScript &&
                $exitCode !== 0 &&
                preg_match('/error TS(?:5[0-9]{3}|6[0-9]{3}|1800[23]):/', $output . $errorOutput) === 1
            ) {
                $status = status::Error;
            }
            if (
                in_array($tool, [tool::MagoLint, tool::MagoAnalyze], true) &&
                $exitCode === 0 &&
                $diagnostics !== null &&
                ($diagnostics['issues'] ?? []) !== []
            ) {
                $status = status::Findings;
            }
        } catch (ProcessTimedOutException $exception) {
            $status = status::Timeout;
            $errorOutput .= $exception->getMessage();
        } catch (exception $exception) {
            $status = status::Error;
            $output = '';
            $errorOutput = $exception->getMessage();
            $process?->stop();
        } catch (ProcessException | \JsonException $exception) {
            $errorOutput .= $exception->getMessage();
        } finally {
            if (is_resource($outputStream)) {
                fclose($outputStream);
            }
            if (is_resource($errorStream)) {
                fclose($errorStream);
            }
            if ($truncated && is_file($cache . '/stdout.log') && is_file($cache . '/stderr.log')) {
                $outputFile = $cache . '/stdout.log';
                $errorOutputFile = $cache . '/stderr.log';
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if (
                    $file instanceof \SplFileInfo &&
                    !in_array($file->getPathname(), [$outputFile, $errorOutputFile], true)
                ) {
                    $directory = $file->isDir() && !$file->isLink();
                    if ($directory) {
                        rmdir($file->getPathname());
                    }
                    if (!$directory) {
                        unlink($file->getPathname());
                    }
                }
            }
            if ($outputFile === null) {
                rmdir($cache);
            }
        }
        return new result(
            tool: $tool,
            status: $status,
            scope: $scope,
            files: $files,
            command: $command,
            exitCode: $exitCode,
            output: $output,
            errorOutput: $errorOutput,
            seconds: round(microtime(true) - $started, 3),
            outputTruncated: $truncated,
            outputFile: $outputFile,
            errorOutputFile: $errorOutputFile
        );
    }

    /** Keep a bounded preview on UTF-8 boundaries without altering the retained raw log. */
    private function preview(string $text): string
    {
        for ($trim = 0; $trim < 4; ++$trim) {
            $preview = $trim === 0 ? $text : substr($text, 0, -$trim);
            if (preg_match('//u', $preview) === 1) {
                return $preview;
            }
        }
        throw exception::outputStorageFailed();
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

    /** Resolve versions in the target project before centrally installed tools. */
    public function locate(tool $tool, workspace $workspace): ?string
    {
        if ($tool === tool::PhpSyntax) {
            return PHP_BINARY;
        }
        $candidates = [
            $workspace->root . '/vendor/bin/' . $tool->executable(),
            $workspace->root . '/node_modules/.bin/' . $tool->executable(),
            __DIR__ . '/../tools/vendor/bin/' . $tool->executable(),
            __DIR__ . '/../node_modules/.bin/' . $tool->executable(),
            new \Symfony\Component\Process\ExecutableFinder()->find($tool->executable())
        ];
        foreach ($candidates as $path) {
            if ($path === null || !is_file($path) || !is_executable($path)) {
                continue;
            }
            if (
                $tool->executable() === 'mago' &&
                str_contains((string) file_get_contents($path, length: 512), '<?php')
            ) {
                $binaries = glob(dirname($path) . '/../carthage-software/mago/composer/bin/*/mago-*/mago') ?: [];
                rsort($binaries, SORT_NATURAL);
                foreach ($binaries as $binary) {
                    if (is_executable($binary)) {
                        return $binary;
                    }
                }
                continue;
            }
            return $path;
        }
        return null;
    }
}
