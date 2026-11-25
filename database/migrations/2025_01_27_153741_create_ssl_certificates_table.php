<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->text('ssl_certificate');
            $table->text('ssl_private_key');
            $table->text('configuration_dir')->nullable();
            $table->text('mount_path')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('server_id');
            $table->text('common_name');
            $table->json('subject_alternative_names')->nullable();
            $table->timestamp('valid_until');
            $table->boolean('is_ca_certificate')->default(false);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
