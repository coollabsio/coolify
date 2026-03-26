<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingQueue: string
{
    case Default = 'default';
    case High = 'high';
    case StandardDeployment = 'standardDeployment';
    case ProductionDeployment = 'productionDeployment';

    case WorkerDefault = 'workerDefault';
    case WorkerHigh = 'workerHigh';
    case WorkerStandardDeployment = 'workerStandardDeployment';
    case WorkerProductionDeployment = 'workerProductionDeployment';
}
