<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstanceSettings extends Model
{
    public static function get()
    {
        return InstanceSettings::findOrFail(0);
    }
}
