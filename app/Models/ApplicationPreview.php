<?php

namespace App\Models;

class ApplicationPreview extends BaseModel
{
    protected $fillable = [
        'uuid',
        'pull_request_id',
        'pull_request_html_url',
        'pull_request_issue_comment_id',
        'fqdn',
        'status',
        'application_id',
    ];

    static function findPreviewByApplicationAndPullId(int $application_id, int $pull_request_id)
    {
        return self::where('application_id', $application_id)->where('pull_request_id', $pull_request_id)->firstOrFail();
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
