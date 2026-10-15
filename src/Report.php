<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

final readonly class Report implements \JsonSerializable
{
    public bool $complete;
    public bool $passed;

    /**
     * Keep unavailable checks distinct from completed checks with findings.
     *
     * @param list<string> $files
     * @param list<ToolResult> $results
     */
    public function __construct(public string $root, public array $files, public array $results)
    {
        $this->complete =
            $files !== [] &&
            array_any($results, static fn(ToolResult $result): bool => $result->status !== Status::NotApplicable) &&
            array_all(
                $results,
                static fn(ToolResult $result): bool => in_array(
                    $result->status,
                    [Status::Passed, Status::Findings, Status::NotApplicable],
                    true
                )
            );
        $this->passed =
            $this->complete &&
            array_all(
                $results,
                static fn(ToolResult $result): bool => in_array(
                    $result->status,
                    [Status::Passed, Status::NotApplicable],
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
     * @return array<string, mixed>
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
