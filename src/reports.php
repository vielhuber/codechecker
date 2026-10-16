<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;

final readonly class reports
{
    private const FORMAT_VERSION = 1;
    private string $directory;

    /** Keep persistent diagnostics outside project sources and temporary analyzer caches. */
    public function __construct(private string $root)
    {
        $base = $_ENV['CODECHECKER_REPORT_ROOT'] ?? (getenv('CODECHECKER_REPORT_ROOT') ?: '');
        if ($base === '') {
            $state = getenv('XDG_STATE_HOME') ?: '';
            if ($state === '') {
                $home = getenv('HOME') ?: '';
                if ($home === '') {
                    throw exception::outputStorageFailed();
                }
                $state = $home . '/.local/state';
            }
            $base = $state . '/codechecker';
        }
        if (!str_starts_with($base, '/') || realpath($base) === '/') {
            throw exception::invalidRoot($base);
        }
        $base = rtrim(realpath($base) ?: $base, '/');
        $this->directory = rtrim($base, '/') . '/' . hash('sha256', $root);
    }

    /**
     * Preserve full outputs while comparing only equivalent, successfully completed checks.
     *
     * @return array<string, mixed>
     */
    public function save(report $report): array
    {
        if (
            is_link($this->directory) ||
            (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory))
        ) {
            throw exception::outputStorageFailed();
        }
        if ((fileperms($this->directory) & 0o077) !== 0) {
            throw exception::outputStorageFailed();
        }
        if (is_link($this->directory . '/.lock')) {
            throw exception::outputStorageFailed();
        }
        $lock = fopen($this->directory . '/.lock', 'c');
        if ($lock === false || !chmod($this->directory . '/.lock', 0o600) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw exception::outputStorageFailed();
        }
        try {
            $data = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $sources = [];
            $sourceLines = [];
            $configurations = [];
            foreach ($report->files as $file) {
                $sources[$file] = hash_file('sha256', $file);
                if ($sources[$file] === false) {
                    throw exception::outputStorageFailed();
                }
                $folder = dirname($file);
                while ($folder === $this->root || str_starts_with($folder, $this->root . '/')) {
                    foreach (
                        glob(
                            $folder .
                                '/{composer.*,package*.json,npm-shrinkwrap.json,pnpm-lock.yaml,yarn.lock,bun.lock,eslint.config.*,.eslintrc*,biome.json*,.prettierrc*,prettier.config.*,mago.toml,rector.php*,.php-cs-fixer*.php,*phpcs*.xml*,phpstan.*,psalm.xml*,tsconfig.json,jsconfig.json}',
                            GLOB_BRACE
                        ) ?:
                        []
                        as $configuration
                    ) {
                        if (is_file($configuration)) {
                            $configurations[$configuration] = hash_file('sha256', $configuration);
                        }
                    }
                    if ($folder === $this->root) {
                        break;
                    }
                    $folder = dirname($folder);
                }
            }
            foreach (glob(__DIR__ . '/../config/*') ?: [] as $configuration) {
                if (is_file($configuration)) {
                    $configurations[$configuration] = hash_file('sha256', $configuration);
                }
            }
            foreach (['package.json', 'package-lock.json', 'tools/composer.lock'] as $configuration) {
                $configuration = __DIR__ . '/../' . $configuration;
                if (is_file($configuration)) {
                    $configurations[$configuration] = hash_file('sha256', $configuration);
                }
            }
            ksort($configurations);
            $versions = [];
            $comparable = $report->complete;
            $findings = [];
            $occurrences = [];
            $structured = [];
            foreach ($data['results'] as &$result) {
                if ($result['outputTruncated']) {
                    foreach (['output' => 'outputFile', 'errorOutput' => 'errorOutputFile'] as $stream => $path) {
                        $content = file_get_contents($result[$path]);
                        if ($content === false) {
                            throw exception::outputStorageFailed();
                        }
                        $result[$stream] = $content;
                        $result[$path] = null;
                    }
                    $result['outputTruncated'] = false;
                }
                $binary = $result['command'][0] ?? null;
                if (
                    $binary !== null &&
                    tool::from($result['tool'])->language() === 'javascript' &&
                    !array_key_exists('node', $versions)
                ) {
                    $nodeVersion = new Process(['node', '--version'], timeout: 5);
                    try {
                        $versions['node'] = $nodeVersion->run() === 0 ? trim($nodeVersion->getOutput()) : null;
                    } catch (ProcessTimedOutException | ProcessException $exception) {
                        $versions['node'] = null;
                    }
                    $comparable = $comparable && $versions['node'] !== null;
                }
                if ($binary !== null && !array_key_exists($binary, $versions)) {
                    $version = new Process(
                        [$binary, '--version'],
                        env: ['NO_COLOR' => '1', 'FORCE_COLOR' => false],
                        timeout: 5
                    );
                    try {
                        $versions[$binary] =
                            $version->run() === 0 ? trim($version->getOutput() . $version->getErrorOutput()) : null;
                    } catch (ProcessTimedOutException | ProcessException $exception) {
                        $versions[$binary] = null;
                    }
                }
                $result['version'] = $binary !== null ? $versions[$binary] : null;
                $result['normalized'] = true;
                if ($result['status'] !== 'not_applicable' && $result['version'] === null) {
                    $comparable = false;
                }
                if ($result['status'] !== 'findings') {
                    continue;
                }
                $decoded = json_decode($result['output'], true);
                $diagnostics = [];
                if ($result['tool'] === 'eslint' && is_array($decoded) && array_is_list($decoded)) {
                    foreach ($decoded as $file) {
                        foreach ($file['messages'] ?? [] as $message) {
                            $diagnostics[] = ['file' => $file['filePath']] + $message;
                        }
                    }
                }
                if (in_array($result['tool'], ['phpcs', 'phpstan'], true) && is_array($decoded)) {
                    foreach ($decoded['files'] ?? [] as $file => $details) {
                        foreach ($details['messages'] ?? [] as $message) {
                            $diagnostics[] = ['file' => $file] + $message;
                        }
                    }
                }
                if (
                    in_array($result['tool'], ['mago-lint', 'mago-analyze', 'biome', 'oxlint'], true) &&
                    is_array($decoded)
                ) {
                    $diagnostics = $decoded['issues'] ?? ($decoded['diagnostics'] ?? []);
                }
                if ($result['tool'] === 'psalm' && is_array($decoded) && array_is_list($decoded)) {
                    $diagnostics = $decoded;
                }
                $structured[$result['tool']] = ($structured[$result['tool']] ?? true) && $diagnostics !== [];
                $result['normalized'] = $diagnostics !== [];
                if ($diagnostics === []) {
                    $diagnostics = [
                        [
                            'message' => 'Native findings; see the complete report.',
                            'code' => 'native-output',
                            'digest' => hash('sha256', $result['output'] . $result['errorOutput'])
                        ]
                    ];
                }
                foreach ($diagnostics as $diagnostic) {
                    $annotation = $diagnostic['annotations'][0]['span'] ?? [];
                    $file =
                        $diagnostic['file'] ??
                        ($diagnostic['file_path'] ??
                            ($diagnostic['filename'] ??
                                ($diagnostic['location']['path'] ?? ($annotation['file_id']['path'] ?? ''))));
                    $line =
                        $diagnostic['line'] ??
                        ($diagnostic['line_from'] ??
                            ($diagnostic['location']['start']['line'] ??
                                ($diagnostic['labels'][0]['span']['line'] ?? ($annotation['start']['line'] ?? 0))));
                    $rule =
                        $diagnostic['ruleId'] ??
                        ($diagnostic['source'] ??
                            ($diagnostic['identifier'] ??
                                ($diagnostic['code'] ??
                                    ($diagnostic['category'] ?? ($diagnostic['type'] ?? $result['tool'])))));
                    $message = $diagnostic['message'] ?? '';
                    $severity =
                        $result['tool'] === 'phpcs'
                            ? $diagnostic['type'] ?? 'unknown'
                            : $diagnostic['level'] ??
                                ($diagnostic['severity'] ?? ($result['tool'] === 'phpstan' ? 'error' : 'unknown'));
                    $severity = is_int($severity)
                        ? match ($severity) {
                            1 => 'warning',
                            2 => 'error',
                            default => 'unknown'
                        }
                        : strtolower($severity);
                    if (!is_string($message)) {
                        $message = json_encode($message, JSON_THROW_ON_ERROR);
                    }
                    $source = '';
                    if (is_string($file) && $file !== '') {
                        $resolved = realpath(str_starts_with($file, '/') ? $file : $this->root . '/' . $file);
                        if (
                            $resolved !== false &&
                            str_starts_with($resolved, $this->root . '/') &&
                            is_file($resolved)
                        ) {
                            $file = substr($resolved, strlen($this->root) + 1);
                            if ($line > 0) {
                                $sourceLines[$resolved] ??= file($resolved, FILE_IGNORE_NEW_LINES) ?: [];
                                $source = trim($sourceLines[$resolved][$line - 1] ?? '');
                            }
                        }
                    }
                    $precise = $source !== '';
                    $fingerprint = hash(
                        'sha256',
                        json_encode(
                            [
                                $result['tool'],
                                $rule,
                                $file,
                                $message,
                                $severity,
                                $precise ? $source : $sources,
                                $diagnostic['digest'] ?? null
                            ],
                            JSON_THROW_ON_ERROR
                        )
                    );
                    $occurrence = $occurrences[$fingerprint] ?? 0;
                    $occurrences[$fingerprint] = $occurrence + 1;
                    $findings[] = [
                        'id' => hash('sha256', $fingerprint . ':' . $occurrence),
                        'tool' => $result['tool'],
                        'rule' => $rule,
                        'file' => $file,
                        'line' => $line,
                        'message' => $message,
                        'severity' => $severity,
                        'precise' => $precise
                    ];
                }
            }
            unset($result);
            $toolNames = array_values(array_unique(array_column($data['results'], 'tool')));
            sort($toolNames);
            ksort($versions);
            $context = hash(
                'sha256',
                json_encode(
                    [
                        $this->root,
                        $report->files,
                        $toolNames,
                        $versions,
                        $configurations,
                        PHP_VERSION,
                        self::FORMAT_VERSION
                    ],
                    JSON_THROW_ON_ERROR
                )
            );
            $latest = $this->directory . '/latest-' . $context . '.json';
            $previous = null;
            if ($comparable && is_file($latest)) {
                $previous = $this->read(
                    json_decode((string) file_get_contents($latest), true, flags: JSON_THROW_ON_ERROR)
                );
            }
            $previousFindings = array_column($previous['findings'] ?? [], null, 'id');
            foreach ($findings as &$finding) {
                $finding['state'] = 'unknown';
                if (
                    $previous !== null &&
                    isset($previousFindings[$finding['id']]) &&
                    ($finding['precise'] || $sources === $previous['sources'])
                ) {
                    $finding['state'] = 'existing';
                }
                if ($previous !== null && !isset($previousFindings[$finding['id']]) && $finding['precise']) {
                    $finding['state'] = 'new';
                }
                unset($previousFindings[$finding['id']]);
            }
            unset($finding);
            $resolved = [];
            foreach ($previousFindings as $finding) {
                $statuses = array_column(
                    array_filter(
                        $data['results'],
                        static fn(array $result): bool => $result['tool'] === $finding['tool']
                    ),
                    'status'
                );
                if (
                    $comparable &&
                    (($statuses !== [] &&
                        array_all($statuses, static fn(string $status): bool => $status === 'passed')) ||
                        ($finding['precise'] && ($structured[$finding['tool']] ?? false)))
                ) {
                    $finding['state'] = 'resolved';
                    $resolved[] = $finding;
                }
            }
            $reportId = bin2hex(random_bytes(16));
            $states = array_count_values(array_column($findings, 'state'));
            $visible = array_values(
                array_filter($findings, static fn(array $finding): bool => $finding['state'] !== 'existing')
            );
            $summary = [
                'reportId' => $reportId,
                'root' => $this->root,
                'files' => $report->files,
                'complete' => $report->complete,
                'passed' => $report->passed,
                'results' => array_map(function (array $result): array {
                    $summary = array_intersect_key(
                        $result,
                        array_flip(['tool', 'status', 'scope', 'seconds', 'version', 'normalized'])
                    );
                    $summary['version'] = $result['version'] !== null ? explode("\n", $result['version'])[0] : null;
                    if (in_array($result['status'], ['error', 'timeout', 'unavailable'], true)) {
                        $summary['errorOutput'] = $this->preview($result['errorOutput'], 1000);
                        $summary['detailsTruncated'] = strlen($result['errorOutput']) > strlen($summary['errorOutput']);
                    }
                    return $summary;
                }, $data['results']),
                'comparison' => [
                    'previousReportId' => $previous['reportId'] ?? null,
                    'new' => $states['new'] ?? 0,
                    'existing' => $states['existing'] ?? 0,
                    'unknown' => $states['unknown'] ?? 0,
                    'resolved' => count($resolved)
                ],
                'findings' => array_map(
                    fn(array $finding): array => [...$finding, 'message' => $this->preview($finding['message'], 500)],
                    array_slice($visible, 0, 20)
                ),
                'omittedFindings' => max(0, count($visible) - 20)
            ];
            $data += [
                'reportId' => $reportId,
                'createdAt' => gmdate(DATE_ATOM),
                'formatVersion' => self::FORMAT_VERSION,
                'runtime' => ['php' => PHP_VERSION, 'node' => $versions['node'] ?? null],
                'sources' => $sources,
                'configurations' => $configurations,
                'findings' => $findings,
                'resolvedFindings' => $resolved,
                'summary' => $summary
            ];
            $this->write(
                $this->directory . '/' . $reportId . '.json',
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            if ($comparable) {
                $this->write($latest, json_encode($reportId, JSON_THROW_ON_ERROR));
            }
            return $summary;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Reject path traversal and reports from different project boundaries.
     *
     * @return array<string, mixed>
     */
    public function read(string $reportId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $reportId) !== 1) {
            throw exception::reportNotFound();
        }
        $file = $this->directory . '/' . $reportId . '.json';
        if (is_link($this->directory) || is_link($file) || !is_file($file)) {
            throw exception::reportNotFound();
        }
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['root'] ?? null) !== $this->root || ($data['reportId'] ?? null) !== $reportId) {
            throw exception::reportNotFound();
        }
        return $data;
    }

    /** Keep transport previews bounded without splitting a UTF-8 character. */
    private function preview(string $text, int $limit): string
    {
        if (preg_match('/^.{0,' . $limit . '}/us', $text, $matches) !== 1) {
            throw exception::outputStorageFailed();
        }
        return $matches[0];
    }

    /** Publish a complete report or history pointer atomically with private permissions. */
    private function write(string $file, string $content): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw exception::outputStorageFailed();
        }
        try {
            if (!chmod($temporary, 0o600) || fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                throw exception::outputStorageFailed();
            }
            if (!rename($temporary, $file)) {
                throw exception::outputStorageFailed();
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
