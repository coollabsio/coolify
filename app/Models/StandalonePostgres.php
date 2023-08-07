<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class StandalonePostgres extends BaseModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'postgres_password' => 'encrypted',
    ];
    public function type() {
        return 'postgresql';
    }
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
    public function destination()
    {
        return $this->morphTo();
    }
}