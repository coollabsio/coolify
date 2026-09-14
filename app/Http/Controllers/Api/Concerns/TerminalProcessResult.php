<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessFailedException;

final readonly class TerminalProcessResult implements ProcessResult
{
    public function __construct(
        private ProcessResult $result,
        private string $output,
        private string $errorOutput,
    ) {}

    public function command(): string
    {
        return $this->result->command();
    }

    public function successful(): bool
    {
        return $this->result->successful();
    }

    public function failed(): bool
    {
        return $this->result->failed();
    }

    public function exitCode(): ?int
    {
        return $this->result->exitCode();
    }

    public function output(): string
    {
        return $this->output;
    }

    public function seeInOutput(string $output): bool
    {
        return str_contains($this->output, $output);
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    public function seeInErrorOutput(string $output): bool
    {
        return str_contains($this->errorOutput, $output);
    }

    public function throw(?callable $callback = null): static
    {
        if ($this->successful()) {
            return $this;
        }

        $exception = new ProcessFailedException($this);
        if ($callback) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    public function throwIf(bool $condition, ?callable $callback = null): static
    {
        if ($condition) {
            return $this->throw($callback);
        }

        return $this;
    }
}
