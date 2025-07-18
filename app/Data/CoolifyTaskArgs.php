<?php

namespace App\Data;

use App\Enums\ProcessStatus;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * The parameters to execute a CoolifyTask, organized in a DTO.
 */
class CoolifyTaskArgs extends Data
{
    public function __construct(
        public string $server_uuid,
        public string $command,
        public string $type,
        public ?string $type_uuid = null,
        public ?int $process_id = null,
        public ?Model $model = null,
        public ?string $status = null,
        public bool $ignore_errors = false,
        public $call_event_on_finish = null,
        public $call_event_data = null
    ) {
        if (is_null($status)) {
            $this->status = ProcessStatus::QUEUED->value;
        }
    }
}
