<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

final class CodecheckerException extends \InvalidArgumentException
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
