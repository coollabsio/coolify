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
            $table->text('ssl_certificate')->nullable();
            $table->text('ssl_private_key')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
