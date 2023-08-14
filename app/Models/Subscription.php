<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = [];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
    public function type()
    {
        $basic = explode(',', config('coolify.lemon_squeezy_basic_plan_ids'));
        $pro = explode(',', config('coolify.lemon_squeezy_pro_plan_ids'));
        $ultimate = explode(',', config('coolify.lemon_squeezy_ultimate_plan_ids'));

        $subscription = $this->lemon_variant_id;
        if (in_array($subscription, $basic)) {
            return 'basic';
        }
        if (in_array($subscription, $pro)) {
            return 'pro';
        }
        if (in_array($subscription, $ultimate)) {
            return 'ultimate';
        }
        return 'unknown';
    }
}
