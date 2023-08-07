<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    protected $fillable = [
        'name',
        'project_id',
    ];
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower($value),
        );
    }
    public function can_delete_environment() {
        return $this->applications()->count() == 0 && $this->postgresqls()->count() == 0;
    }
    public function databases() {
        return $this->postgresqls();
    }
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    public function applications()
    {
        return $this->hasMany(Application::class);
    }
    public function postgresqls()
    {
        return $this->hasMany(StandalonePostgres::class);
    }
    public function services()
    {
        return $this->hasMany(Service::class);
    }
}