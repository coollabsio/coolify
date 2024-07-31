<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class Tag extends BaseModel
{
    protected $guarded = [];

    public function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => strtolower($value),
            set: fn ($value) => strtolower($value)
        );
    }

    public static function ownedByCurrentTeam()
    {
        return Tag::whereTeamId(currentTeam()->id)->orderBy('name');
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
