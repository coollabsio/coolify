<?php

use App\Models\EnvironmentVariable;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Visus\Cuid2\Cuid2;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_compose_pr_location');
            $table->dropColumn('docker_compose_pr');
            $table->dropColumn('docker_compose_pr_raw');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('lemon_subscription_id');
            $table->dropColumn('lemon_order_id');
            $table->dropColumn('lemon_product_id');
            $table->dropColumn('lemon_variant_id');
            $table->dropColumn('lemon_variant_name');
            $table->dropColumn('lemon_customer_id');
            $table->dropColumn('lemon_status');
            $table->dropColumn('lemon_renews_at');
            $table->dropColumn('lemon_update_payment_menthod_url');
            $table->dropColumn('lemon_trial_ends_at');
            $table->dropColumn('lemon_ends_at');
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->string('uuid')->nullable()->after('id');
        });

        EnvironmentVariable::all()->each(function (EnvironmentVariable $environmentVariable) {
            $environmentVariable->update([
                'uuid' => (string) new Cuid2(),
            ]);
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->string('uuid')->nullable(false)->change();
        });
        Schema::table('server_settings', function (Blueprint $table) {
            $table->integer('metrics_history_days')->default(7)->change();
        });
        Server::all()->each(function (Server $server) {
            $server->settings->update([
                'metrics_history_days' => 7,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('docker_compose_pr_location')->nullable()->default('/docker-compose.yaml')->after('docker_compose_location');
            $table->longText('docker_compose_pr')->nullable()->after('docker_compose_location');
            $table->longText('docker_compose_pr_raw')->nullable()->after('docker_compose');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('lemon_subscription_id')->nullable()->after('stripe_subscription_id');
            $table->string('lemon_order_id')->nullable()->after('lemon_subscription_id');
            $table->string('lemon_product_id')->nullable()->after('lemon_order_id');
            $table->string('lemon_variant_id')->nullable()->after('lemon_product_id');
            $table->string('lemon_variant_name')->nullable()->after('lemon_variant_id');
            $table->string('lemon_customer_id')->nullable()->after('lemon_variant_name');
            $table->string('lemon_status')->nullable()->after('lemon_customer_id');
            $table->timestamp('lemon_renews_at')->nullable()->after('lemon_status');
            $table->string('lemon_update_payment_menthod_url')->nullable()->after('lemon_renews_at');
            $table->timestamp('lemon_trial_ends_at')->nullable()->after('lemon_update_payment_menthod_url');
            $table->timestamp('lemon_ends_at')->nullable()->after('lemon_trial_ends_at');
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
        Schema::table('server_settings', function (Blueprint $table) {
            $table->integer('metrics_history_days')->default(30)->change();
        });
        Server::all()->each(function (Server $server) {
            $server->settings->update([
                'metrics_history_days' => 30,
            ]);
        });
    }
};
