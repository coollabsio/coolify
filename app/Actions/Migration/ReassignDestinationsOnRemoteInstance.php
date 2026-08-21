<?php

namespace App\Actions\Migration;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ReassignDestinationsOnRemoteInstance
{
    use AsAction;

    /**
     * Update destination FKs on the restored Coolify DB via psql (peer auth inside coolify-db).
     * Do not use artisan tinker — that needs Laravel's DB_PASSWORD, which can disagree with Postgres after restore.
     */
    public function handle(Server $target): void
    {
        instant_remote_process(RestoreInstanceOnHost::syncDatabasePasswordCommands(), $target);

        $output = instant_remote_process(self::reassignCommands(), $target, timeout: 300);

        if (! str_contains((string) $output, 'ok')) {
            throw new RuntimeException('Failed to reassign destinations on target: '.$output);
        }
    }

    /**
     * @return list<string>
     */
    public static function reassignCommands(): array
    {
        $remoteSql = '/tmp/coolify-reassign-destinations.sql';

        return [
            'printf %s '.escapeshellarg(self::reassignSql()).' | tee '.escapeshellarg($remoteSql).' >/dev/null',
            'docker cp '.escapeshellarg($remoteSql).' coolify-db:/tmp/coolify-reassign-destinations.sql',
            'docker exec coolify-db sh -c '.escapeshellarg(
                'psql -U "$POSTGRES_USER" -d "${POSTGRES_DB:-coolify}" -v ON_ERROR_STOP=1 -f /tmp/coolify-reassign-destinations.sql'
            ),
            'docker exec coolify-db rm -f /tmp/coolify-reassign-destinations.sql || true',
            'rm -f '.escapeshellarg($remoteSql).' || true',
        ];
    }

    public static function reassignSql(): string
    {
        return <<<'SQL'
DO $reassign$
DECLARE
  dest_id bigint;
  dest_type text := 'App\Models\StandaloneDocker';
BEGIN
  SELECT id INTO dest_id FROM standalone_dockers WHERE server_id = 0 ORDER BY id ASC LIMIT 1;
  IF dest_id IS NULL THEN
    RAISE EXCEPTION 'localhost destination missing';
  END IF;

  UPDATE applications SET destination_id = dest_id, destination_type = dest_type;
  UPDATE services SET destination_id = dest_id, destination_type = dest_type, server_id = 0;
  UPDATE standalone_postgresqls SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_mysqls SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_mariadbs SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_mongodbs SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_redis SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_keydbs SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_dragonflies SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
  UPDATE standalone_clickhouses SET destination_id = dest_id, destination_type = dest_type WHERE id <> 0;
END
$reassign$;

SELECT 'ok';
SQL;
    }
}
