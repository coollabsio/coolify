<?php

namespace App\Domain\Deployment;

use Carbon\Carbon;

class DeploymentOutput
{
    private Carbon $timestamp;

    private int $order = 1;

    public function __construct(private readonly string $command, private readonly string $output,
        private readonly string $type, private readonly bool $hidden, private readonly int $batch)
    {
        $this->timestamp = Carbon::now('UTC');
    }

    public function getCommand(): string
    {
        return remove_iip($this->command);
    }

    public function getOutput(): string
    {
        return remove_iip($this->output);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getBatch(): int
    {
        return $this->batch;
    }

    public function getTimestamp(): Carbon
    {
        return $this->timestamp;
    }

    public function toArray(): array
    {
        return [
            'command' => $this->getCommand(),
            'output' => $this->getOutput(),
            'type' => $this->getType(),
            'hidden' => $this->isHidden(),
            'batch' => $this->getBatch(),
            'timestamp' => $this->getTimestamp(),
            'order' => $this->getOrder(),
        ];
    }

    public function setOrder(int $order): void
    {
        $this->order = $order;
    }

    public function getOrder(): int
    {
        return $this->order;
    }
}
