<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('application_docker_registry', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('docker_registry_id');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('docker_registry_id')->references('id')->on('docker_registries')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('application_docker_registry');
    }
};
