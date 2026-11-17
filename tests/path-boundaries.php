<?php
declare(strict_types=1);

use vielhuber\codechecker\exception;
use vielhuber\codechecker\report;
use vielhuber\codechecker\reports;
use vielhuber\codechecker\runner;
use vielhuber\codechecker\status;
use vielhuber\codechecker\tool;
use vielhuber\codechecker\workspace;

require dirname(__DIR__) . '/vendor/autoload.php';

$temporary = sys_get_temp_dir() . '/codechecker-boundaries-' . bin2hex(random_bytes(8));
$previousReportRoot = $_ENV['CODECHECKER_REPORT_ROOT'] ?? null;
mkdir($temporary, 0700);
try {
    mkdir($temporary . '/project', 0700);
    mkdir($temporary . '/project-other', 0700);
    file_put_contents($temporary . '/outside.php', '<?php echo "outside";');
    file_put_contents($temporary . '/project/safe.php', '<?php echo "safe";');
    file_put_contents($temporary . '/project-other/outside.php', '<?php echo "outside";');
    symlink($temporary . '/outside.php', $temporary . '/project/link.php');
    $workspace = new workspace($temporary . '/project');
    if ($workspace->resolve('safe.php') !== $temporary . '/project/safe.php') {
        throw new RuntimeException('A valid project file was rejected.');
    }
    foreach (['https://example.invalid/project', $temporary . '/outside.php'] as $root) {
        try {
            new workspace($root);
        } catch (exception) {
            continue;
        }
        throw new RuntimeException('An invalid workspace root was accepted.');
    }
    foreach (
        ['../outside.php', '../project-other/outside.php', 'link.php', 'https://example.invalid/file.php']
        as $path
    ) {
        try {
            $workspace->resolve($path);
        } catch (exception) {
            continue;
        }
        throw new RuntimeException('An invalid project path was accepted.');
    }

    $_ENV['CODECHECKER_REPORT_ROOT'] = $temporary . '/reports';
    $store = new reports($workspace->root);
    $saved = $store->save(new report(root: $workspace->root, files: [], results: []));
    $data = $store->read($saved['reportId']);
    $invalidId = $saved['reportId'] . "\n";
    $data['reportId'] = $invalidId;
    file_put_contents(
        $temporary . '/reports/' . hash('sha256', $workspace->root) . '/' . $invalidId . '.json',
        json_encode($data, JSON_THROW_ON_ERROR)
    );
    foreach ([$invalidId, '../' . $saved['reportId'], 'https://example.invalid/report', ''] as $id) {
        try {
            $store->read($id);
        } catch (exception) {
            continue;
        }
        throw new RuntimeException('An invalid report ID was accepted.');
    }

    $unsafe = $temporary . '/project/unsafe.php';
    file_put_contents($unsafe, '<?php file_get_contents($_GET["file"]);');
    $result = new runner()->run(tool::Psalm, $workspace, [$unsafe], timeout: 120);
    if ($result->status !== status::Findings || !str_contains($result->output, 'TaintedFile')) {
        throw new RuntimeException('Psalm did not detect an unvalidated file read.');
    }
    fwrite(STDOUT, "Path boundaries, report IDs, and active taint analysis verified.\n");
} finally {
    if ($previousReportRoot === null) {
        unset($_ENV['CODECHECKER_REPORT_ROOT']);
    }
    if ($previousReportRoot !== null) {
        $_ENV['CODECHECKER_REPORT_ROOT'] = $previousReportRoot;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if (!($file instanceof SplFileInfo)) {
            throw new RuntimeException('An invalid cleanup entry was returned.');
        }
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
            continue;
        }
        unlink($file->getPathname());
    }
    rmdir($temporary);
}
