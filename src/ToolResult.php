<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

final readonly class ToolResult implements \JsonSerializable
{
    /**
     * Preserve native diagnostics even when a command cannot finish successfully.
     *
     * @param list<string> $files
     * @param list<string> $command
     */
    public function __construct(
        public Tool $tool,
        public Status $status,
        public string $scope,
        public array $files = [],
        public array $command = [],
        public ?int $exitCode = null,
        public string $output = '',
        public string $errorOutput = '',
        public float $seconds = 0.0,
        public bool $outputTruncated = false
    ) {}

    /**
     * Keep the transport representation explicit for CLI and MCP consumers.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'tool' => $this->tool->value,
            'status' => $this->status->value,
            'scope' => $this->scope,
            'files' => $this->files,
            'command' => $this->command,
            'exitCode' => $this->exitCode,
            'output' => $this->output,
            'errorOutput' => $this->errorOutput,
            'seconds' => $this->seconds,
            'outputTruncated' => $this->outputTruncated
        ];
    }
}
