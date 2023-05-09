<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GithubEventsApplications extends Model
{
    protected $fillable = [
        'delivery_guid',
        'application_id',
    ];
}
