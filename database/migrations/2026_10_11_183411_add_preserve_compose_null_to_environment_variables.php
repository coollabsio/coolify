<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->boolean('preserve_compose_null')->default(false);
        });

        EnvironmentVariable::query()
            ->whereNull('value')
            ->where(function ($query) {
                $query->where('resourceable_type', Service::class)
                    ->orWhere(function ($query) {
                        $query->where('resourceable_type', Application::class)
                            ->whereIn('resourceable_id', Application::query()->where('build_pack', 'dockercompose')->select('id')->getQuery());
                    });
            })
            ->toBase()->update(['preserve_compose_null' => true]);
    }

    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('preserve_compose_null');
        });
    }
};
