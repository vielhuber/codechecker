<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

final readonly class workspace
{
    public const PHP_EXTENSIONS = ['php', 'phtml', 'inc'];
    public const JAVASCRIPT_EXTENSIONS = ['js', 'mjs', 'cjs', 'jsx', 'ts', 'mts', 'cts', 'tsx'];
    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'vendor',
        'node_modules',
        'dist',
        'build',
        '_dist',
        '_build',
        'coverage',
        '.cache'
    ];

    public string $root;

    /** Resolve the boundary once so symlinks cannot expand it during discovery. */
    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            throw exception::invalidRoot($root);
        }
        $this->root = $resolved;
    }

    /** Resolve both explicit inputs and discovered paths against the same boundary. */
    public function resolve(string $path): string
    {
        $resolved = realpath(str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $this->root . '/' . $path);
        if (
            $resolved === false ||
            ($resolved !== $this->root && !str_starts_with($resolved, rtrim($this->root, '/') . '/'))
        ) {
            throw exception::invalidPath($path);
        }
        return $resolved;
    }

    /**
     * Deduplicate overlapping inputs without following directory symlinks.
     *
     * @return list<string>
     */
    public function files(string ...$paths): array
    {
        $files = [];
        foreach ($paths ?: ['.'] as $path) {
            $resolved = $this->resolve($path);
            if (is_file($resolved)) {
                if (
                    !is_readable($resolved) ||
                    !in_array(
                        strtolower(pathinfo($resolved, PATHINFO_EXTENSION)),
                        [...self::PHP_EXTENSIONS, ...self::JAVASCRIPT_EXTENSIONS],
                        true
                    )
                ) {
                    throw exception::unsupportedFile($path);
                }
                $files[$resolved] = $resolved;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
                    static fn(mixed $file): bool => $file instanceof \SplFileInfo &&
                        !$file->isLink() &&
                        (!$file->isDir() || !in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true))
                )
            );
            foreach ($iterator as $file) {
                if (
                    !($file instanceof \SplFileInfo) ||
                    !$file->isFile() ||
                    !in_array(
                        strtolower($file->getExtension()),
                        [...self::PHP_EXTENSIONS, ...self::JAVASCRIPT_EXTENSIONS],
                        true
                    )
                ) {
                    continue;
                }
                $source = $this->resolve($file->getPathname());
                if (!is_readable($source)) {
                    throw exception::unsupportedFile($source);
                }
                $files[$source] = $source;
            }
        }
        $files = array_values($files);
        sort($files);
        return $files;
    }

    /** Prefer project rules while allowing central defaults for unconfigured projects. */
    public function configuration(string ...$names): ?string
    {
        foreach ($names as $name) {
            if (is_file($this->root . '/' . $name)) {
                return $this->resolve($name);
            }
        }
        return null;
    }
}
