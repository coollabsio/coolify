<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class S3Storage extends BaseModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'key' => 'encrypted',
        'secret' => 'encrypted',
    ];

    static public function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);
        return S3Storage::whereTeamId(session('currentTeam')->id)->select($selectArray->all())->orderBy('name');
    }

    public function awsUrl()
    {
        return "{$this->endpoint}/{$this->bucket}";
    }

    public function testConnection()
    {
        set_s3_target($this);
        return \Storage::disk('custom-s3')->files();
    }
}
