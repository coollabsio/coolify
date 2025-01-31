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
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->timestamp('valid_until');
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers');
            $table->unique(['server_id', 'resource_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
