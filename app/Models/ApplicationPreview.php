<?php

namespace App\Models;

class ApplicationPreview extends BaseModel
{
    protected $guarded = [];

    static function findPreviewByApplicationAndPullId(int $application_id, int $pull_request_id)
    {
        return self::where('application_id', $application_id)->where('pull_request_id', $pull_request_id)->firstOrFail();
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
