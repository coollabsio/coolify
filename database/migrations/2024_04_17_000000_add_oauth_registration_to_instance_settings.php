<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
  {
        /**
       * Run the migrations.
       */
      public function up(): void
    {
              Schema::table('instance_settings', function (Blueprint $table) {
                            $table->boolean('is_oauth_registration_enabled')->default(false);
                            $table->boolean('force_oauth_only_login')->default(false);
              });

            Schema::table('users', function (Blueprint $table) {
                          $table->boolean('force_oauth_login')->default(false);
            });
    }

      /**
       * Reverse the migrations.
       */
      public function down(): void
    {
              Schema::table('instance_settings', function (Blueprint $table) {
                            $table->dropColumn(['is_oauth_registration_enabled', 'force_oauth_only_login']);
              });

            Schema::table('users', function (Blueprint $table) {
                          $table->dropColumn('force_oauth_login');
            });
    }
  };
