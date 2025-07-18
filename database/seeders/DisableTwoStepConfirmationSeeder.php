<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DisableTwoStepConfirmationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('instance_settings')->updateOrInsert(
            [],
            ['disable_two_step_confirmation' => true]
        );
    }
}
