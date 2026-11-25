<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The healthcheck did not check the expected response text before, so a saved text had no effect.
     * Now it is checked, and a text that the response does not contain would fail the next deployment.
     * Clearing the saved texts keeps the healthcheck of existing applications as it was.
     */
    public function up(): void
    {
        DB::table('applications')
            ->whereNotNull('health_check_response_text')
            ->update(['health_check_response_text' => null]);
    }

    public function down(): void
    {
        // The cleared texts are gone.
    }
};
