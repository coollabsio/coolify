#!/usr/bin/env bash
# Full coverage: add every kind of resource and setting to debian-root on A (seed-extras.php, seed-full.php, and the
# resources below), deploy them, transfer the server to B through the Transfer page, then compare:
#   - the database records of the server on A and on B (snapshot.sh + snapshot-diff.php),
#   - what runs on the VM after A deployed and after B deployed everything again (runtime-snapshot.sh),
# and run a database backup, a volume backup, and a scheduled task from B.
# Run reset.sh before and after. The files are in <state>/full/.
source "$(dirname "$0")/lib.sh"
TA="$(source_token)"
TB="$(target_token)"
PROFILE=debian-root
S="$(vm_field "$PROFILE" uuid)"
ip="$(vm_field "$PROFILE" ip)"
OUT="$STATE_DIR/full"
mkdir -p "$OUT"
P2="$(jq -r '.projects[1]' "$STATE_DIR/resources.json")"

# call <port> <token> <METHOD> <path> [json]: the response body; stops the test on an HTTP error.
call() {
  local out code
  out="$(curl -sS -m 60 -X "$3" "http://127.0.0.1:$1/api/v1$4" -H "Authorization: Bearer $2" -H 'Content-Type: application/json' -H 'Accept: application/json' ${5:+-d "$5"} -w $'\n%{http_code}')"
  code="${out##*$'\n'}"
  out="${out%$'\n'*}"
  [[ "$code" -lt 300 ]] || die "$3 $4 ($code): $out"
  echo "$out"
}
on() { jq -nc --arg p "$P2" --arg s "$S" --arg d "${DEST:-}" '{project_uuid:$p,server_uuid:$s,destination_uuid:$d,environment_name:"production",instant_deploy:false}' | jq -c ". + $1"; }
uuid_of() { tinker "$1" "echo App\\Models\\$2::where('name','$3')->value('uuid');" | tail -1; }
wait_deployments() {
  for _ in $(seq 1 60); do
    sleep 5
    [[ "$(tinker "$1" 'echo App\Models\ApplicationDeploymentQueue::whereIn("status", ["queued", "in_progress"])->count();' | tail -1)" == 0 ]] && return 0
  done
  return 1
}

echo '=== 1. resources and settings on A'
tinker "$A" "App\\Models\\StandaloneDocker::firstOrCreate(['network' => 'second-net', 'server_id' => App\\Models\\Server::where('uuid','$S')->value('id')], ['name' => 'second-net']);" >/dev/null
NET2_DEST="$(tinker "$A" "echo App\\Models\\StandaloneDocker::where('network','second-net')->value('uuid');" | tail -1)"
DEST="$(tinker "$A" "echo App\\Models\\StandaloneDocker::where('server_id', App\\Models\\Server::where('uuid','$S')->value('id'))->where('network','coolify')->value('uuid');" | tail -1)"
COMPOSE="$(printf 'services:\n  web:\n    image: traefik/whoami\n    environment:\n      - SERVICE_URL_WEB_80\n  cache:\n    image: redis:7-alpine\n    volumes:\n      - cache-data:/data\n      - ./conf:/conf\n' | base64 -w0)"
call "$A_PORT" "$TA" POST /services "$(on "{name:\"svc-legacy\",docker_compose_raw:\"$COMPOSE\"}")" >/dev/null
call "$A_PORT" "$TA" POST /databases/postgresql "$(on '{name:"pg-ssl",postgres_user:"full",postgres_password:"full-pg-password",postgres_db:"postgres"}')" >/dev/null
for type in mysql mongodb keydb dragonfly clickhouse redis sqlite; do
  call "$A_PORT" "$TA" POST "/databases/$type" "$(on "{name:\"idle-$type\"}")" >/dev/null
done
call "$A_PORT" "$TA" POST /applications/dockerimage "$(on "{name:\"whoami-net2\",destination_uuid:\"$NET2_DEST\",docker_registry_image_name:\"traefik/whoami\",docker_registry_image_tag:\"v1.10\",ports_exposes:\"80\"}")" >/dev/null
php_file "$A" "$TT_DIR/seed-full.php"

APP="$(uuid_of "$A" Application whoami-2)"
NET2="$(uuid_of "$A" Application whoami-net2)"
PG="$(uuid_of "$A" StandalonePostgresql pg-ssl)"
MD="$(uuid_of "$A" StandaloneMariadb mariadb)"
SVC="$(uuid_of "$A" Service svc-legacy)"

echo '=== 2. deploy on A'
for u in "$APP" "$NET2"; do call "$A_PORT" "$TA" POST "/deploy?uuid=$u&force=true" >/dev/null; done
call "$A_PORT" "$TA" POST "/databases/$PG/start" >/dev/null
# reset.sh does not wait for database starts, so A starts MariaDB again, as B does after the transfer.
call "$A_PORT" "$TA" POST "/databases/$MD/restart" >/dev/null
call "$A_PORT" "$TA" POST "/services/$SVC/start" >/dev/null
wait_deployments "$A" || bad 'deployments on A finished'
tinker "$A" 'foreach (App\Models\ApplicationDeploymentQueue::latest("id")->take(2)->get() as $d) echo "  deploy ".$d->application_name.": ".$d->status.PHP_EOL;'
stable_workloads "$PROFILE" >/dev/null
cache="$(ssh_vm "$PROFILE" "docker ps --format '{{.Names}}' | grep '^cache-$SVC'")"
ssh_vm "$PROFILE" "docker exec $cache sh -c 'echo kept-by-transfer > /conf/marker'"
# The fake records of seed-extras.php (secret manager, DNS) would make a deployment fail, so they come after it.
php_file "$A" "$TT_DIR/seed-extras.php"
"$TT_DIR/runtime-snapshot.sh" "$PROFILE" "$OUT/runtime-A.txt"
"$TT_DIR/snapshot.sh" A "$S" "$OUT/db-A.json"

echo "=== 3. transfer $S A -> B"
check "transfer succeeded ($(transfer_from_page "$S"))" test "$(tinker "$A" "echo data_get(App\\Models\\Server::where('uuid','$S')->first()->server_metadata, 'migration.status');" | tail -1)" = succeeded
tinker "$A" 'echo config("app.id");' | tail -1 | docker exec -i "$B" sh -c 'cat > /tmp/source-app-id'

echo '=== 4. records on B'
extras="$(php_file "$B" "$TT_DIR/verify-extras-target.php"; php_file "$A" "$TT_DIR/verify-extras-source.php")"
grep -E '^(PASS|FAIL)' <<<"$extras"
FAILS=$((FAILS + $(grep -c '^FAIL' <<<"$extras")))
"$TT_DIR/snapshot.sh" B "$S" "$OUT/db-B.json"
php "$TT_DIR/snapshot-diff.php" "$OUT/db-A.json" "$OUT/db-B.json" >"$OUT/db.diff"
grep -v '^expected' "$OUT/db.diff"
check 'every record and value of A is on B (db.diff)' grep -q '^=== 0 unexplained' "$OUT/db.diff"

echo '=== 5. deploy and restart everything from B'
# The fake Infisical token of seed-extras.php cannot give secrets to a deployment.
tinker "$B" "App\\Models\\SecretManagerLink::query()->delete();" >/dev/null
for u in "$APP" "$NET2"; do call "$B_PORT" "$TB" POST "/deploy?uuid=$u&force=true" >/dev/null; done
for u in "$PG" "$MD"; do call "$B_PORT" "$TB" POST "/databases/$u/restart" >/dev/null; done
call "$B_PORT" "$TB" POST "/services/$SVC/restart" >/dev/null
wait_deployments "$B" || bad 'deployments on B finished'
check 'deployments from B finished' test "$(tinker "$B" 'echo App\Models\ApplicationDeploymentQueue::where("status", "!=", "finished")->count();' | tail -1)" = 0
sleep 20
stable_workloads "$PROFILE" >/dev/null
"$TT_DIR/runtime-snapshot.sh" "$PROFILE" "$OUT/runtime-B.txt"
# USES_TEAM comes from seed-extras.php, which runs after the deployment on A.
without_team_var() { sed -E '/USES_TEAM/d; s/,$//' "$1"; }
diff <(without_team_var "$OUT/runtime-A.txt") <(without_team_var "$OUT/runtime-B.txt") >"$OUT/runtime.diff" && ok 'B runs the same containers and files as A' || {
  bad "B runs other containers or files than A ($(grep -c '^[<>]' "$OUT/runtime.diff") lines in runtime.diff)"
  head -60 "$OUT/runtime.diff"
}
sentinel="$(ssh_vm "$PROFILE" "docker inspect coolify-sentinel --format '{{range .Config.Env}}{{println .}}{{end}}'")"
for setting in COLLECTOR_ENABLED=true COLLECTOR_REFRESH_RATE_SECONDS=10 COLLECTOR_RETENTION_PERIOD_DAYS=14 PUSH_INTERVAL_SECONDS=30; do
  check "Sentinel of B uses the moved setting $setting" grep -qx "$setting" <<<"$sentinel"
done
cache="$(ssh_vm "$PROFILE" "docker ps --format '{{.Names}}' | grep '^cache-$SVC'")"
check 'the ./ bind mount of the parser v3 service keeps its data' test "$(ssh_vm "$PROFILE" "docker exec $cache cat /conf/marker")" = kept-by-transfer

echo '=== 6. backups and scheduled tasks from B'
tinker "$B" "App\\Jobs\\DatabaseBackupJob::dispatchSync(App\\Models\\ScheduledDatabaseBackup::where('description','full coverage backup')->firstOrFail());" >/dev/null
r="$(tinker "$B" "\$e = App\\Models\\ScheduledDatabaseBackup::where('description','full coverage backup')->first()->executions()->latest('id')->first(); echo json_encode([\$e?->status, (bool) \$e?->s3_uploaded, \$e?->message]);" | tail -1)"
check "database backup from B to S3 ($r)" test "$(jq -c '.[0:2]' <<<"$r")" = '["success",true]'
volume="$(tinker "$B" "echo App\\Models\\LocalPersistentVolume::where('name','$APP-data')->value('uuid');" | tail -1)"
call "$B_PORT" "$TB" POST "/applications/$APP/storages/$volume/backups/run" >/dev/null
for _ in $(seq 1 30); do
  sleep 5
  r="$(tinker "$B" "\$e = App\\Models\\ScheduledVolumeBackup::whereHasMorph('backupable', [App\\Models\\LocalPersistentVolume::class], fn (\$q) => \$q->where('uuid', '$volume'))->first()?->executions()->latest('id')->first(); echo json_encode([\$e?->status?->value ?? \$e?->status, \$e?->message]);" | tail -1)"
  [[ "$(jq -r '.[0]' <<<"$r")" =~ ^(success|failed)$ ]] && break
done
check "volume backup from B ($r)" test "$(jq -r '.[0]' <<<"$r")" = success
tinker "$B" "App\\Jobs\\ScheduledTaskJob::dispatchSync(App\\Models\\ScheduledTask::where('name','service-task')->firstOrFail());" >/dev/null
r="$(tinker "$B" "\$e = App\\Models\\ScheduledTask::where('name','service-task')->first()->executions()->latest('id')->first(); echo json_encode([\$e?->status, \$e?->message]);" | tail -1)"
check "scheduled task of the service from B ($r)" test "$(jq -r '.[0]' <<<"$r")" = success
check 'whoami-net2 answers over HTTP' bash -c "curl -sf -m5 -H 'Host: $NET2.$ip.sslip.io' http://$ip/ >/dev/null"

echo "=== $FAILS failure(s). Files: $OUT"
exit "$FAILS"
