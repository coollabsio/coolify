<?php

namespace App\Models;

use App\Models\ScheduledTask as ModelsScheduledTask;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScheduledTask extends BaseModel
{
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'command' => 'string',
        'frequency' => 'string',
        'container' => 'string',
    ];

    // protected static function booted()
    // {
    //     static::created(function ($scheduled_task) {
    //         error_log("*** IN CREATED");
    //         if ($scheduled_task->application_id) {
    //             $found = ModelsScheduledTask::where('id', $scheduled_task->id)->where('application_id', $scheduled_task->application_id)->first();
    //             $application = Application::find($scheduled_task->application_id);
    //             if (!$found) {
    //                 ModelsScheduledTask::create([
    //                     'name' => $scheduled_task->name,
    //                     'command' => $scheduled_task->command,
    //                     'frequency' => $scheduled_task->frequency,
    //                     'container' => $scheduled_task->container,
    //                     'application_id' => $scheduled_task->application_id,
    //                 ]);
    //             }
    //         }
    //     });
    // }
    public function service()
    {
        return $this->belongsTo(Service::class);
    }
    // protected function value(): Attribute
    // {
    //     return Attribute::make(
    //         get: fn (?string $value = null) => $this->get_scheduled_tasks($value),
    //         set: fn (?string $value = null) => $this->set_scheduled_tasks($value),
    //     );
    // }

    private function get_scheduled_tasks(?string $scheduled_task = null): string|null
    {
        error_log("** in get_scheduled_tasks");
        // // $team_id = currentTeam()->id;
        // if (!$scheduled_task) {
        //     return null;
        // }
        // $scheduled_task = trim(decrypt($scheduled_task));
        // if (Str::startsWith($scheduled_task, '{{') && Str::endsWith($scheduled_task, '}}') && Str::contains($scheduled_task, 'global.')) {
        //     $variable = Str::after($scheduled_task, 'global.');
        //     $variable = Str::before($variable, '}}');
        //     $variable = Str::of($variable)->trim()->value;
        //     // $scheduled_task = GlobalScheduledTask::where('name', $scheduled_task)->where('team_id', $team_id)->first()?->value;
        //     ray('global env variable');
        //     return $scheduled_task;
        // }
        // return $scheduled_task;
    }

    private function set_scheduled_tasks(?string $scheduled_task = null): string|null
    {
        error_log("** in set_scheduled_tasks");
        // if (is_null($scheduled_task) && $scheduled_task == '') {
        //     return null;
        // }
        // $scheduled_task = trim($scheduled_task);
        // return encrypt($scheduled_task);
    }

    // protected function key(): Attribute
    // {
    //     error_log("** in key()");

    //     // return Attribute::make(
    //     //     set: fn (string $value) => Str::of($value)->trim(),
    //     // );
    // }
}
