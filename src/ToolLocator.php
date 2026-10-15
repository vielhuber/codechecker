<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

use Symfony\Component\Process\ExecutableFinder;

final readonly class ToolLocator
{
    /** Resolve versions in the target project before centrally installed tools. */
    public function find(Tool $tool, Workspace $workspace): ?string
    {
        if ($tool === Tool::PhpSyntax) {
            return PHP_BINARY;
        }
        $candidates = [
            $workspace->root . '/vendor/bin/' . $tool->executable(),
            $workspace->root . '/node_modules/.bin/' . $tool->executable(),
            __DIR__ . '/../tools/vendor/bin/' . $tool->executable(),
            __DIR__ . '/../node_modules/.bin/' . $tool->executable(),
            new ExecutableFinder()->find($tool->executable())
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
