<?php

namespace App\Domain\Deployment;

use Carbon\Carbon;

class DeploymentOutput
{
    private Carbon $timestamp;

    private int $order = 1;

    public function __construct(private readonly ?string $command = null, private readonly ?string $output = null,
        private readonly ?string $type = 'stdout', private readonly ?bool $hidden = false, private readonly ?int $batch = 1)
    {
        $this->timestamp = Carbon::now('UTC');
    }

    public function getCommand(): string
    {
        return $this->command ? remove_iip($this->command) : '';
    }

    public function getOutput(): string
    {
        return $this->output ? remove_iip($this->output) : '';
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
