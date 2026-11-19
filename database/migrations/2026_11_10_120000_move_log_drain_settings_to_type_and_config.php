<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the flag and the columns of each log drain with one `logdrain_type` and one
 * encrypted `logdrain_config`, which keeps the values of each type under its key.
 */
return new class extends Migration
{
    /**
     * The order is the order in which StartLogDrain picked a drain when more than one flag was on.
     */
    private const FLAGS = [
        'newrelic' => 'is_logdrain_newrelic_enabled',
        'axiom' => 'is_logdrain_axiom_enabled',
        'custom' => 'is_logdrain_custom_enabled',
        'cloudwatch' => 'is_logdrain_cloudwatch_enabled',
    ];

    /**
     * @var array<string, array<string, string>> type => config key => old column
     */
    private const COLUMNS = [
        'newrelic' => ['license_key' => 'logdrain_newrelic_license_key', 'base_uri' => 'logdrain_newrelic_base_uri'],
        'axiom' => ['api_key' => 'logdrain_axiom_api_key', 'dataset_name' => 'logdrain_axiom_dataset_name', 'domain' => 'logdrain_axiom_domain'],
        'custom' => ['config' => 'logdrain_custom_config', 'parser_config' => 'logdrain_custom_config_parser'],
        'cloudwatch' => ['region' => 'logdrain_cloudwatch_region', 'group' => 'logdrain_cloudwatch_group', 'stream_prefix' => 'logdrain_cloudwatch_stream_prefix'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('server_settings', 'logdrain_type')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->string('logdrain_type')->nullable();
                $table->text('logdrain_config')->nullable();
            });
        }

        $oldColumns = array_values(array_filter(
            [...array_values(self::FLAGS), ...array_merge(...array_map('array_values', array_values(self::COLUMNS)))],
            fn (string $column) => Schema::hasColumn('server_settings', $column),
        ));
        if ($oldColumns === []) {
            return;
        }

        DB::table('server_settings')->select(['id', ...$oldColumns])->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $type = null;
                foreach (self::FLAGS as $flagType => $flag) {
                    if (($row->{$flag} ?? false) == true) {
                        $type = $flagType;
                        break;
                    }
                }

                $config = [];
                foreach (self::COLUMNS as $configType => $columns) {
                    foreach ($columns as $key => $column) {
                        $value = $row->{$column} ?? null;
                        if ($column === 'logdrain_custom_config') {
                            $value = $this->decrypt($value);
                        }
                        if (filled($value)) {
                            $config[$configType][$key] = $value;
                        }
                    }
                }

                DB::table('server_settings')->where('id', $row->id)->update([
                    'logdrain_type' => $type,
                    'logdrain_config' => $config === [] ? null : Crypt::encryptString(json_encode($config)),
                ]);
            }
        });

        Schema::table('server_settings', function (Blueprint $table) use ($oldColumns) {
            $table->dropColumn($oldColumns);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_logdrain_newrelic_enabled')->default(false);
            $table->string('logdrain_newrelic_license_key')->nullable();
            $table->string('logdrain_newrelic_base_uri')->nullable();
            $table->boolean('is_logdrain_axiom_enabled')->default(false);
            $table->string('logdrain_axiom_dataset_name')->nullable();
            $table->string('logdrain_axiom_api_key')->nullable();
            $table->string('logdrain_axiom_domain')->nullable();
            $table->boolean('is_logdrain_custom_enabled')->default(false);
            $table->text('logdrain_custom_config')->nullable();
            $table->text('logdrain_custom_config_parser')->nullable();
            $table->boolean('is_logdrain_cloudwatch_enabled')->default(false);
            $table->string('logdrain_cloudwatch_region')->nullable();
            $table->string('logdrain_cloudwatch_group')->nullable();
            $table->string('logdrain_cloudwatch_stream_prefix')->nullable();
        });

        DB::table('server_settings')->select(['id', 'logdrain_type', 'logdrain_config'])->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $config = json_decode((string) $this->decrypt($row->logdrain_config), true) ?: [];
                $updates = [];
                foreach (self::FLAGS as $type => $flag) {
                    $updates[$flag] = $row->logdrain_type === $type;
                }
                foreach (self::COLUMNS as $type => $columns) {
                    foreach ($columns as $key => $column) {
                        $value = $config[$type][$key] ?? null;
                        if ($column === 'logdrain_custom_config' && filled($value)) {
                            $value = Crypt::encryptString($value);
                        }
                        $updates[$column] = $value;
                    }
                }
                DB::table('server_settings')->where('id', $row->id)->update($updates);
            }
        });

        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['logdrain_type', 'logdrain_config']);
        });
    }

    private function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
};
