#!/usr/bin/env bash
# Put the kit back to its start: A manages its localhost VM and the transfer VMs with deployed resources, B is empty.
# WIPES BOTH DEV DATABASES (migrate:fresh --seed) and the test workloads on the VMs. VM disks are kept. About 4 minutes.
#
# Usage: scripts/transfer-test/reset.sh
source "$(dirname "$0")/lib.sh"

require_dev_instance "$A"
require_dev_instance "$B"
mkdir -p "$STATE_DIR"

echo '== 1. remove test workloads from the VMs (the proxy stays)'
if fetch_vm_key; then
  for profile in "${TRANSFER_PROFILES[@]}"; do
    ssh_vm "$profile" '
      for c in $(docker ps -aq); do n=$(docker inspect -f "{{.Name}}" $c); case "$n" in /coolify-proxy*) ;; *) docker rm -f $c >/dev/null;; esac; done
      docker volume ls -q | grep -v "^coolify" | xargs -r docker volume rm >/dev/null 2>&1 || true
      rm -rf /data/coolify/applications/* /data/coolify/databases/* /data/coolify/services/* /data/coolify/sentinel/* /data/coolify/transfer
      echo "  $(hostname): $(docker ps --format "{{.Names}}" | tr "\n" " ")"' || echo "  $profile: not reachable, skipped"
  done
else
  echo '  no VM key yet (first run), skipped'
fi

echo '== 2. fresh databases, Redis, SSH folders'
for C in "$A" "$B"; do
  docker exec "$C-redis-1" redis-cli FLUSHALL >/dev/null
  docker exec "$C" sh -c 'rm -rf /var/www/html/storage/app/ssh/keys/* /var/www/html/storage/app/ssh/mux/*; mkdir -p /var/www/html/storage/app/ssh/keys /var/www/html/storage/app/ssh/mux; chown -R www-data:www-data /var/www/html/storage/app/ssh'
  docker exec -u www-data "$C" php artisan migrate:fresh --seed --force >/dev/null || die "migrate:fresh failed in $C"
  echo "  $C: database seeded"
done

echo '== 3. seed the VM servers of A'
host="${DEV_BIND_ADDRESS:-$(grep -E '^DEV_BIND_ADDRESS=' .env 2>/dev/null | tail -1 | cut -d= -f2-)}"
[[ -z "$host" || "$host" == 0.0.0.0 ]] && host=127.0.0.1
APP_ENV=local APP_CONFIG_CACHE="$ROOT/bootstrap/cache/dev-qemu-$$.php" LOG_CHANNEL=stderr \
  APP_KEY="$(env_value "$SOURCE" APP_KEY)" DB_HOST="$host" DB_PORT="$(env_value "$SOURCE" FORWARD_DB_PORT)" \
  REDIS_HOST="$host" REDIS_PORT="$(env_value "$SOURCE" FORWARD_REDIS_PORT)" \
  DEVELOPMENT_QEMU_INSTANCE="$SOURCE" DEVELOPMENT_QEMU_SLOT="$A_SLOT" \
  DEVELOPMENT_QEMU_DOCKER_NETWORK="$A" DEVELOPMENT_QEMU_COOLIFY_CONTAINER="$A" \
  "${PRIVILEGED[@]}" sh -c "php artisan dev:qemu $LOCALHOST_PROFILE --as-localhost && php artisan dev:qemu ${TRANSFER_PROFILES[*]}" 2>&1 | grep -E 'Started|rror'
docker exec "$A" chown -R www-data:www-data /var/www/html/storage/app/ssh
fetch_vm_key || die 'The VM key (private key 1) is missing on A after seeding.'

echo '== 4. helper image on the VMs'
helper="$(tinker "$A" 'echo config("constants.coolify.helper_image").":".config("constants.coolify.helper_version");' | tail -1)"
for profile in "${TRANSFER_PROFILES[@]}"; do
  # An unreleased helper version cannot be pulled; tag the newest local helper image with that version instead.
  ssh_vm "$profile" "docker image inspect $helper >/dev/null 2>&1 || docker pull -q $helper >/dev/null 2>&1 || {
      latest=\$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -E '(^|/)coollabsio/coolify-helper:' | sort -V | tail -1)
      [ -n \"\$latest\" ] && docker tag \"\$latest\" $helper && echo \"  \$(hostname): tagged \$latest as $helper (not published yet)\"
    }"
done

echo '== 5. proxies, API tokens, new code in Horizon'
tinker "$A" 'foreach (App\Models\Server::where("id", "!=", 0)->get() as $s) { $s->proxy->set("type", "TRAEFIK"); $s->proxy->set("status", "exited"); $s->save(); App\Actions\Proxy\StartProxy::run($s->fresh(), async: false, force: true); echo "  proxy ok: ".$s->name.PHP_EOL; }'
for side in source target; do
  C="$A"
  [[ "$side" == target ]] && C="$B"
  # The dev .env runs Cloud mode, where the import check refuses private addresses unless the
  # instance allows them. The VMs of A are on a private network.
  tinker "$C" '$s = App\Models\InstanceSettings::get(); $s->is_api_enabled = true; $s->webhook_allowed_internal_hosts = ["10.221.'"$A_SLOT"'.0/24"]; $s->save(); session(["currentTeam" => App\Models\Team::find(0)]); echo App\Models\User::find(0)->createToken("transfer-test", ["root"])->plainTextToken;' | tail -1 >"$STATE_DIR/$side.token"
  chmod 600 "$STATE_DIR/$side.token"
  docker exec -u www-data "$C" php artisan horizon:terminate >/dev/null
done

echo '== 6. create and deploy resources on A'
sleep 10
"$TT_DIR/seed.sh" >/dev/null || die 'seed.sh failed'
echo '  waiting for deployments...'
for _ in $(seq 1 30); do
  sleep 10
  [[ "$(tinker "$A" 'echo App\Models\ApplicationDeploymentQueue::whereIn("status", ["queued", "in_progress"])->count();' | tail -1)" == 0 ]] && break
done
tinker "$A" 'foreach (App\Models\ApplicationDeploymentQueue::get() as $d) echo "  deploy ".$d->application_name.": ".$d->status.PHP_EOL;'
for profile in "${TRANSFER_PROFILES[@]}"; do
  ssh_vm "$profile" 'echo "  $(hostname): $(docker ps --format "{{.Names}}" | grep -v "^coolify-" | wc -l) workload containers"'
done
echo "Done. A: $A_URL  B: $B_URL  (login test@example.com / password)"
