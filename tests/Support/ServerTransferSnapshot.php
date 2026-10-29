<?php

namespace Tests\Support;

use App\Services\ServerTransfer\ServerTransferCoverage;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A portable copy of one server and every record that a server transfer moves, to compare the source
 * with the target. Rows are keyed by model class and an identity that is the same on both instances
 * (the uuid, or a key built from the parent). Foreign keys are replaced with the identity of the
 * referenced row, and encrypted columns are decrypted, so other IDs and APP_KEYs give the same snapshot.
 *
 * Used by tests/Unit/ServerTransfer/ServerTransferCoverageTest.php and by the live kit (scripts/transfer-test/snapshot.sh).
 */
final class ServerTransferSnapshot
{
    /** Belongs-to columns and the table they point to. */
    public const FOREIGN_KEYS = [
        'private_key_id' => 'private_keys', 'server_id' => 'servers', 'environment_id' => 'environments', 'project_id' => 'projects',
        'application_id' => 'applications', 'service_id' => 'services', 's3_storage_id' => 's3_storages',
        'integration_token_id' => 'integration_tokens', 'cloudflare_tunnel_integration_token_id' => 'integration_tokens',
        'dns_provider_zone_id' => 'dns_provider_zones', 'github_app_id' => 'github_apps', 'cloud_provider_token_id' => 'cloud_provider_tokens',
        'managed_dns_record_id' => 'managed_dns_records', 'standalone_sqlite_id' => 'standalone_sqlites', 'icon_s3_storage_id' => 's3_storages',
        'scheduled_database_backup_id' => 'scheduled_database_backups', 'scheduled_volume_backup_id' => 'scheduled_volume_backups',
    ];

    /** Polymorphic relations: `<name>_type` + `<name>_id`. */
    public const MORPHS = ['destination', 'source', 'resourceable', 'resource', 'database', 'backupable', 'taggable', 'target'];

    /** The instance sets these itself; they never match between two instances. */
    private const IGNORED_COLUMNS = ['id', 'created_at', 'updated_at', 'team_id', 'created_by'];

    private const DATABASE_TABLES = ['standalone_postgresqls', 'standalone_mysqls', 'standalone_mariadbs', 'standalone_mongodbs', 'standalone_redis',
        'standalone_keydbs', 'standalone_dragonflies', 'standalone_clickhouses', 'standalone_sqlites'];

    private const RESOURCE_TABLES = ['applications', 'services', 'service_applications', 'service_databases', ...self::DATABASE_TABLES];

    /** Records that a moved record needs. Their own children are not collected. */
    private const DEPENDENCY_TABLES = ['private_keys', 's3_storages', 'integration_tokens', 'dns_provider_zones', 'github_apps', 'gitlab_apps',
        'gitea_apps', 'cloud_provider_tokens', 'environments', 'projects', 'managed_dns_records'];

    /** Values that the live instances change while they manage the resources, not the transfer. */
    public const LIVE_RUNTIME_COLUMNS = ['last_online_at', 'status', 'docker_version_checked_at', 'compose_version_checked_at', 'started_at', 'config_hash', 'container_present'];

    /** @var array<string, array<int|string, array<string, mixed>>> table => id => row */
    private array $rows = [];

    /** @var array<string, array<int|string, string>> */
    private array $identities = [];

    /** @var array<string, class-string<Model>> */
    private array $classByTable = [];

    /**
     * @return array<string, array<string, array<string, mixed>>> model class => identity => column => value
     */
    public static function take(string $serverUuid): array
    {
        return (new self)->collect($serverUuid)->portable();
    }

    /**
     * Differences between two snapshots: a row that is missing or extra, or a column that changed.
     * Columns that ServerTransferCoverage leaves out on purpose, and $alsoExpected columns
     * ("Class.column" or "column"), are returned as expected with the reason.
     *
     * @param  array<string, string>  $alsoExpected
     * @return array{unexplained: list<string>, expected: list<string>}
     */
    public static function diff(array $before, array $after, array $alsoExpected = []): array
    {
        $short = fn (mixed $value) => mb_strimwidth(is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES), 0, 160, '…');
        $result = ['unexplained' => [], 'expected' => []];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $class) {
            $name = class_basename($class);
            foreach (array_unique([...array_keys($before[$class] ?? []), ...array_keys($after[$class] ?? [])]) as $key) {
                $a = $before[$class][$key] ?? null;
                $b = $after[$class][$key] ?? null;
                if ($a === null || $b === null) {
                    $result['unexplained'][] = ($b === null ? 'missing ' : 'extra ')."{$name} {$key}";

                    continue;
                }
                foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $column) {
                    if (($a[$column] ?? null) === ($b[$column] ?? null)) {
                        continue;
                    }
                    $line = "{$name} {$key} .{$column}: ".$short($a[$column] ?? null).' => '.$short($b[$column] ?? null);
                    $reason = ServerTransferCoverage::NOT_EXPORTED_COLUMNS[$class][$column] ?? $alsoExpected["{$name}.{$column}"] ?? $alsoExpected[$column] ?? match (true) {
                        ($a[$column] ?? '') === ($b[$column] ?? '') => 'No value and an empty text mean the same.',
                        is_string($a[$column] ?? null) && str_starts_with($a[$column], 'system-wide ') && ($b[$column] ?? null) === null => 'A system-wide git source is instance data; the target links its own copy when it has one.',
                        default => null,
                    };
                    if ($reason === null) {
                        $result['unexplained'][] = $line;
                    } else {
                        $result['expected'][] = "{$line}  [{$reason}]";
                    }
                }
            }
        }

        return $result;
    }

    private function __construct()
    {
        foreach (array_keys(ServerTransferCoverage::TRANSFERRED) as $class) {
            $this->classByTable[(new $class)->getTable()] = $class;
        }
    }

    /** Children that belong to a parent and move with it: child table => [column or morph => parent tables]. */
    private function children(): array
    {
        $children = [
            'server_settings' => ['server_id' => ['servers']],
            'standalone_dockers' => ['server_id' => ['servers']],
            'swarm_dockers' => ['server_id' => ['servers']],
            'ssl_certificates' => ['server_id' => ['servers']],
            'github_runner_configs' => ['server_id' => ['servers']],
            'applications' => ['destination' => ['standalone_dockers', 'swarm_dockers']],
            'services' => ['destination' => ['standalone_dockers', 'swarm_dockers'], 'server_id' => ['servers']],
            'application_settings' => ['application_id' => ['applications']],
            'application_previews' => ['application_id' => ['applications']],
            'scheduled_tasks' => ['application_id' => ['applications'], 'service_id' => ['services']],
            'service_applications' => ['service_id' => ['services']],
            'service_databases' => ['service_id' => ['services']],
            'environment_variables' => ['resourceable' => self::RESOURCE_TABLES],
            'local_persistent_volumes' => ['resource' => [...self::RESOURCE_TABLES, 'application_previews']],
            'local_file_volumes' => ['resource' => self::RESOURCE_TABLES],
            'secret_manager_links' => ['resourceable' => self::RESOURCE_TABLES],
            'scheduled_database_backups' => ['database' => [...self::DATABASE_TABLES, 'service_databases']],
            'scheduled_volume_backups' => ['backupable' => [...self::RESOURCE_TABLES, 'local_persistent_volumes']],
            'managed_dns_record_references' => ['resource' => self::RESOURCE_TABLES],
            'resource_links' => ['source' => self::RESOURCE_TABLES],
            'shared_environment_variables' => ['server_id' => ['servers'], 'project_id' => ['projects'], 'environment_id' => ['environments']],
            'scheduled_database_backup_s3_storage' => ['scheduled_database_backup_id' => ['scheduled_database_backups']],
            'scheduled_volume_backup_s3_storage' => ['scheduled_volume_backup_id' => ['scheduled_volume_backups']],
        ];
        foreach (self::DATABASE_TABLES as $table) {
            $children[$table] = ['destination' => ['standalone_dockers', 'swarm_dockers']];
        }

        return $children;
    }

    private function collect(string $serverUuid): self
    {
        $server = DB::table('servers')->where('uuid', $serverUuid)->first() ?? throw new RuntimeException("No server {$serverUuid}");
        $this->add('servers', [$server]);
        do {
            $new = $this->collectChildren() + $this->collectDependencies() + $this->collectTags();
        } while ($new > 0);

        return $this;
    }

    private function collectChildren(): int
    {
        $new = 0;
        foreach ($this->children() as $childTable => $links) {
            foreach ($links as $link => $parentTables) {
                foreach ($parentTables as $parentTable) {
                    $parentIds = array_keys($this->rows[$parentTable] ?? []);
                    if ($parentIds === []) {
                        continue;
                    }
                    $query = DB::table($childTable);
                    if (str_ends_with($link, '_id')) {
                        $query->whereIn($link, $parentIds);
                        if ($childTable === 'shared_environment_variables') {
                            $query->where('type', ['server_id' => 'server', 'project_id' => 'project', 'environment_id' => 'environment'][$link]);
                        }
                    } else {
                        $types = $this->morphTypesOf($childTable, $link, $parentTable);
                        if ($types === []) {
                            continue;
                        }
                        $query->whereIn("{$link}_type", $types)->whereIn("{$link}_id", $parentIds);
                    }
                    $new += $this->add($childTable, $query->get());
                }
            }
        }

        return $new;
    }

    private function collectDependencies(): int
    {
        $new = 0;
        foreach ($this->rows as $tableRows) {
            foreach ($tableRows as $row) {
                foreach ($row as $column => $value) {
                    $target = self::FOREIGN_KEYS[$column] ?? null;
                    if ($value !== null && in_array($target, self::DEPENDENCY_TABLES, true)) {
                        $new += $this->add($target, DB::table($target)->where('id', $value)->get());
                    }
                    $morph = str_ends_with($column, '_type') ? substr($column, 0, -5) : null;
                    if ($morph !== null && in_array($morph, self::MORPHS, true) && isset($row["{$morph}_id"])) {
                        $target = $this->morphTable($value);
                        if (in_array($target, self::DEPENDENCY_TABLES, true)) {
                            $new += $this->add($target, DB::table($target)->where('id', $row["{$morph}_id"])->get());
                        }
                    }
                }
            }
        }
        $recordIds = collect($this->rows['managed_dns_record_references'] ?? [])->pluck('managed_dns_record_id')->all();

        return $new + $this->add('managed_dns_records', DB::table('managed_dns_records')->whereIn('id', $recordIds)->get());
    }

    private function collectTags(): int
    {
        $new = 0;
        foreach (self::RESOURCE_TABLES as $resourceTable) {
            $ids = array_keys($this->rows[$resourceTable] ?? []);
            $types = $this->morphTypesOf('taggables', 'taggable', $resourceTable);
            if ($ids === [] || $types === []) {
                continue;
            }
            foreach (DB::table('taggables')->whereIn('taggable_type', $types)->whereIn('taggable_id', $ids)->get() as $pivot) {
                $key = "{$pivot->taggable_type}|{$pivot->taggable_id}|{$pivot->tag_id}";
                if (! isset($this->rows['taggables'][$key])) {
                    $this->rows['taggables'][$key] = ['id' => $key, ...((array) $pivot)];
                    $new++;
                }
                $new += $this->add('tags', DB::table('tags')->where('id', $pivot->tag_id)->get());
            }
        }

        return $new;
    }

    /** @return list<string> */
    private function morphTypesOf(string $table, string $morph, string $parentTable): array
    {
        return DB::table($table)->distinct()->pluck("{$morph}_type")
            ->filter(fn (?string $type) => $this->morphTable($type) === $parentTable)->values()->all();
    }

    private function add(string $table, iterable $found): int
    {
        $new = 0;
        foreach ($found as $row) {
            $row = (array) $row;
            if (! empty($row['is_system_wide'])) {
                continue;
            }
            if (($row['deleted_at'] ?? null) === null && ! isset($this->rows[$table][$row['id']])) {
                $this->rows[$table][$row['id']] = $row;
                $new++;
            }
        }

        return $new;
    }

    private function morphTable(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $class = Relation::getMorphedModel($type) ?? $type;

        return array_search($class, $this->classByTable, true) ?: (class_exists($class) ? (new $class)->getTable() : null);
    }

    private function identity(string $table, int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (isset($this->identities[$table][$id])) {
            return $this->identities[$table][$id];
        }
        $row = $this->rows[$table][$id] ?? (array) (DB::table($table)->where('id', $id)->first() ?? []);
        if ($row === []) {
            return "{$table}#missing";
        }
        $morph = fn (string $name) => $this->morphTable($row["{$name}_type"] ?? null).':'.$this->identity((string) $this->morphTable($row["{$name}_type"] ?? null), $row["{$name}_id"] ?? null);

        return $this->identities[$table][$id] = match (true) {
            ! empty($row['uuid']) => $row['uuid'],
            $table === 'server_settings' => 'settings of '.$this->identity('servers', $row['server_id']),
            $table === 'application_settings' => 'settings of '.$this->identity('applications', $row['application_id']),
            $table === 'ssl_certificates' => $this->identity('servers', $row['server_id']).'|'.($row['is_ca_certificate'] ? 'CA' : $morph('resource')).'|'.$row['common_name'],
            $table === 'shared_environment_variables' => $row['type'].'|'.match ($row['type']) {
                'server' => $this->identity('servers', $row['server_id']),
                'project' => $this->identity('projects', $row['project_id']),
                'environment' => $this->identity('environments', $row['environment_id']),
                default => 'team',
            }.'|'.$row['key'],
            $table === 'managed_dns_record_references' => $this->identity('managed_dns_records', $row['managed_dns_record_id']).'|'.$morph('resource'),
            $table === 'taggables' => $morph('taggable').'|'.$this->identity('tags', $row['tag_id']),
            $table === 'tags' => 'tag '.$row['name'],
            $table === 'scheduled_database_backup_s3_storage' => $this->identity('scheduled_database_backups', $row['scheduled_database_backup_id']).'|'.$this->identity('s3_storages', $row['s3_storage_id']),
            $table === 'scheduled_volume_backup_s3_storage' => $this->identity('scheduled_volume_backups', $row['scheduled_volume_backup_id']).'|'.$this->identity('s3_storages', $row['s3_storage_id']),
            default => "{$table}#{$id}",
        };
    }

    private function portable(): array
    {
        $snapshot = [];
        foreach ($this->rows as $table => $tableRows) {
            $class = $this->classByTable[$table] ?? $table;
            $model = class_exists($class) ? new $class : null;
            foreach ($tableRows as $id => $row) {
                if ($table === 'resource_links' && ! isset($this->rows[$this->morphTable($row['target_type'])][$row['target_id']])) {
                    continue;
                }
                $values = [];
                foreach ($row as $column => $value) {
                    if (in_array($column, self::IGNORED_COLUMNS, true) || $table === 'taggables') {
                        continue;
                    }
                    $morph = preg_replace('/_(type|id)$/', '', $column);
                    if (in_array($morph, self::MORPHS, true) && array_key_exists("{$morph}_type", $row) && array_key_exists("{$morph}_id", $row)) {
                        if (str_ends_with($column, '_type')) {
                            $target = $this->morphTable($value);
                            $systemWide = in_array($target, ['github_apps', 'gitlab_apps', 'gitea_apps'], true)
                                && DB::table($target)->where('id', $row["{$morph}_id"])->value('is_system_wide');
                            $values[$morph] = $target === null ? null : ($systemWide ? 'system-wide ' : '').$target.':'.$this->identity($target, $row["{$morph}_id"]);
                        }

                        continue;
                    }
                    if (isset(self::FOREIGN_KEYS[$column])) {
                        $values[$column] = $value === null ? null : self::FOREIGN_KEYS[$column].':'.$this->identity(self::FOREIGN_KEYS[$column], $value);

                        continue;
                    }
                    $values[$column] = $this->normalize($this->decrypted($model, $row, $column, $value));
                }
                ksort($values);
                $key = $this->identity($table, $id);
                // Two rows with one identity (for example two variables with the same key) must not hide each other.
                for ($n = 2; isset($snapshot[$class][$key]); $n++) {
                    $key = $this->identity($table, $id)." (duplicate {$n})";
                }
                $snapshot[$class][$key] = $values;
            }
        }
        ksort($snapshot);

        return array_map(function (array $rows) {
            ksort($rows);

            return $rows;
        }, $snapshot);
    }

    private function decrypted(?Model $model, array $row, string $column, mixed $value): mixed
    {
        $cast = (string) ($model?->getCasts()[$column] ?? '');
        $looksEncrypted = is_string($value) && str_starts_with($value, 'eyJpdiI6');
        if ($value === null || ! ($looksEncrypted || str_starts_with($cast, 'encrypted'))) {
            return $value;
        }
        try {
            return str_starts_with($cast, 'encrypted') ? $model->newFromBuilder($row)->getAttribute($column) : decrypt($value, false);
        } catch (Throwable) {
            try {
                return decrypt($value);
            } catch (Throwable $e) {
                return 'UNDECRYPTABLE: '.$e->getMessage();
            }
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }
}
