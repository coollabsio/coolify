<?php

use App\Models\EnvironmentVariable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        try {
            EnvironmentVariable::whereNull('resourceable_id')->each(function (EnvironmentVariable $environmentVariable) {
                $environmentVariable->delete();
            });
        } catch (\Exception $e) {
            Log::error('Failed to delete wrongly created environment variables: '.$e->getMessage());
        }
    }
};
