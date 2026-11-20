<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New default image for each standalone database table, with the previous default.
     *
     * Existing databases keep their image. Only new databases use the new default.
     *
     * @var array<string, array{new: string, old: string}>
     */
    private array $images = [
        'standalone_postgresqls' => ['new' => 'postgres:18-alpine', 'old' => 'postgres:16-alpine'],
        'standalone_redis' => ['new' => 'redis:8', 'old' => 'redis:7.2'],
        'standalone_mongodbs' => ['new' => 'mongo:9', 'old' => 'mongo:7'],
        'standalone_mysqls' => ['new' => 'mysql:9.7', 'old' => 'mysql:8'],
        'standalone_mariadbs' => ['new' => 'mariadb:12.3', 'old' => 'mariadb:11'],
        'standalone_clickhouses' => ['new' => 'clickhouse/clickhouse-server:26.8', 'old' => 'clickhouse/clickhouse-server:25.11'],
    ];

    public function up(): void
    {
        foreach ($this->images as $table => $image) {
            Schema::table($table, function (Blueprint $blueprint) use ($image) {
                $blueprint->string('image')->default($image['new'])->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->images as $table => $image) {
            Schema::table($table, function (Blueprint $blueprint) use ($image) {
                $blueprint->string('image')->default($image['old'])->change();
            });
        }
    }
};
