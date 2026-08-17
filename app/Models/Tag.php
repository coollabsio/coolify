<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Tag model',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string'),
    ]
)]
class Tag extends BaseModel
{
    use HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'team_id',
    ];

    protected function customizeName($value)
    {
        return strtolower($value);
    }

    public static function ownedByCurrentTeam()
    {
        return Tag::whereTeamId(currentTeam()->id)->orderBy('name');
    }

    public function deleteIfOrphaned(): void
    {
        if (DB::table('taggables')->where('tag_id', $this->id)->doesntExist()) {
            $this->delete();
        }
    }

    public function applications()
    {
        return $this->morphedByMany(Application::class, 'taggable');
    }

    public function services()
    {
        return $this->morphedByMany(Service::class, 'taggable');
    }
}
