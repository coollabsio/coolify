<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingQueue: string
{
    case Default = 'default';
    case High = 'high';
    case StandardDeployment = 'standard-deployment';
    case ProductionDeployment = 'production-deployment';

    case WorkerDefault = 'worker-default';
    case WorkerHigh = 'worker-high';
    case WorkerStandardDeployment = 'worker-standard-deployment';
    case WorkerProductionDeployment = 'worker-production-deployment';
}
