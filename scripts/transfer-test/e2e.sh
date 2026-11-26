#!/usr/bin/env bash
# Transfer both VM servers A -> B through the API and check source, target, server, and a redeploy from B.
# Run reset.sh before and after.
source "$(dirname "$0")/lib.sh"
TA="$(source_token)"
TB="$(target_token)"

for profile in "${TRANSFER_PROFILES[@]}"; do
  S="$(vm_field "$profile" uuid)"
  ip="$(vm_field "$profile" ip)"
  echo "=== $S"
  before="$(stable_workloads "$profile")"
  apps="$(tinker "$A" "echo App\\Models\\Server::where('uuid','$S')->first()->applications()->pluck('uuid')->implode(' ');" | tail -1)"

  echo '-- start the transfer on A'
  r="$(api POST "$A_PORT" "/servers/$S/migrate" "$TA" "$(jq -nc --arg u "http://localhost:$B_PORT" --arg t "$TB" '{target_url:$u,target_token:$t}')")"
  if [[ "$(jq -r .migration.status <<<"$r" 2>/dev/null)" != running ]]; then
    bad "transfer request accepted: $r"
    continue
  fi
  ok 'transfer request accepted'
  for _ in $(seq 1 40); do sleep 3; st="$(api GET "$A_PORT" "/servers/$S/transfer" "$TA" | jq -r .migration.status)"; [[ "$st" != running ]] && break; done
  check "transfer finished ($st)" test "$st" = succeeded

  echo '-- source A'
  a="$(api GET "$A_PORT" "/servers/$S/transfer" "$TA")"
  export_id="$(jq -r .transfer.export_id <<<"$a")"
  check 'A transfer.status = transferred' test "$(jq -r .transfer.status <<<"$a")" = transferred
  check 'A force_disabled + Sentinel off' test "$(tinker "$A" "\$s=App\\Models\\Server::where('uuid','$S')->first()->settings; echo (int)\$s->force_disabled.(int)\$s->is_sentinel_enabled;" | tail -1)" = 10
  check 'A shows the take-back modal' test "$(tinker "$A" "$login_php echo (int) str_contains(Livewire\\Livewire::test(App\\Livewire\\Server\\Show::class, ['server_uuid'=>'$S'])->html(), 'Take back a transferred server?');" | tail -1)" = 1
  r="$(api POST "$A_PORT" "/servers/$S/migrate" "$TA" "$(jq -nc --arg u "http://localhost:$B_PORT" --arg t "$TB" '{target_url:$u,target_token:$t}')")"
  check 'second transfer refused by the prechecks' grep -q 'already transferred' <<<"$r"
  r="$(api DELETE "$A_PORT" "/servers/$S?delete_from_provider=true" "$TA")"
  check 'A refuses to delete the cloud server of the archive' grep -q 'managed by another Coolify instance' <<<"$r"

  echo '-- target B'
  b="$(api GET "$B_PORT" "/servers/$S/transfer" "$TB")"
  check 'B transfer.status = claimed' test "$(jq -r .transfer.status <<<"$b")" = claimed
  check 'same export id on A and B' test "$(jq -r .transfer.export_id <<<"$b")" = "$export_id"
  for _ in $(seq 1 20); do
    sleep 3
    meta="$(tinker "$B" "\$s=App\\Models\\Server::where('uuid','$S')->first(); echo json_encode(['os'=>data_get(\$s->server_metadata,'os'),'reach'=>\$s->settings->is_reachable,'usable'=>\$s->settings->is_usable,'logs'=>\$s->validation_logs,'transfer'=>data_get(\$s->server_metadata,'transfer.status')]);" | tail -1)"
    [[ "$(jq -r .os <<<"$meta")" != null ]] && break
  done
  check "automatic validation filled the metadata ($(jq -r .os <<<"$meta"))" test "$(jq -r .os <<<"$meta")" != null
  check 'B reachable + usable, no validation error' test "$(jq -c '[.reach,.usable,.logs]' <<<"$meta")" = '[true,true,null]'
  check 'transfer state kept after validation' test "$(jq -r .transfer <<<"$meta")" = claimed
  check 'B started the finish list' test "$(tinker "$B" "echo count((array) data_get(App\\Models\\Server::where('uuid','$S')->first()->server_metadata,'transfer.finish.items')) > 0 ? 1 : 0;" | tail -1)" = 1
  check 'A links to the server on B' test "$(jq -r .migration.target_server_url <<<"$a")" = "$B_URL/server/$S"
  check 'B has all applications' test "$(tinker "$B" "echo App\\Models\\Server::where('uuid','$S')->first()->applications()->pluck('uuid')->implode(' ');" | tail -1)" = "$apps"
  check 'B validates the server over SSH' test "$(tinker "$B" "\$r=App\\Models\\Server::where('uuid','$S')->first()->validateConnection(); echo (int)(bool)\$r['uptime'];" | tail -1)" = 1

  echo '-- server'
  sleep 5
  after="$(workloads "$profile")"
  check 'proxy + workloads not recreated' test "$before" = "$after"
  sentinel_env="$(ssh_vm "$profile" "docker inspect coolify-sentinel --format '{{range .Config.Env}}{{println .}}{{end}}'")"
  check 'Sentinel sends to B' grep -qx "PUSH_ENDPOINT=$B_URL" <<<"$sentinel_env"
  for app in $apps; do check "app $app answers over HTTP" bash -c "curl -sf -m5 -H 'Host: $app.$ip.sslip.io' http://$ip/ >/dev/null"; done

  echo '-- redeploy one app from B'
  app="${apps%% *}"
  api POST "$B_PORT" "/deploy?uuid=$app&force=false" "$TB" >/dev/null
  for _ in $(seq 1 40); do sleep 5; ds="$(tinker "$B" "echo App\\Models\\ApplicationDeploymentQueue::where('application_id', App\\Models\\Application::where('uuid','$app')->value('id'))->latest('id')->value('status');" | tail -1)"; [[ "$ds" == finished || "$ds" == failed ]] && break; done
  check "redeploy from B ($ds)" test "$ds" = finished
  check 'app answers after the redeploy' bash -c "for i in {1..10}; do curl -sf -m5 -H 'Host: $app.$ip.sslip.io' http://$ip/ >/dev/null && exit 0; sleep 3; done; exit 1"

  [[ "$profile" == ubuntu-non-root ]] || continue
  echo '-- operations from B over the non-root SSH user'
  uuid_on_b() { tinker "$B" "echo App\\Models\\$1::where('name','$2')->value('uuid');" | tail -1; }
  pg="$(uuid_on_b StandalonePostgresql postgres)"
  rd="$(uuid_on_b StandaloneRedis redis)"
  sv="$(uuid_on_b Service uptime-kuma)"
  nginx="$(uuid_on_b Application nginx-dockerfile)"
  for u in "$pg" "$rd"; do api POST "$B_PORT" "/databases/$u/restart" "$TB" >/dev/null; done
  api POST "$B_PORT" "/services/$sv/restart" "$TB" >/dev/null
  sleep 20
  stable_workloads "$profile" >/dev/null
  running_after="$(workloads "$profile")"
  for u in "$pg" "$rd" "$sv"; do check "restarted from B: $u runs" grep -q "$u" <<<"$running_after"; done
  tinker "$B" "App\\Jobs\\DatabaseBackupJob::dispatchSync(App\\Models\\StandalonePostgresql::where('uuid','$pg')->first()->scheduledBackups()->firstOrFail());" >/dev/null
  r="$(tinker "$B" "echo App\\Models\\StandalonePostgresql::where('uuid','$pg')->first()->scheduledBackups()->first()->executions()->latest('id')->value('status');" | tail -1)"
  check "database backup from B ($r)" test "$r" = success
  task="$(api POST "$B_PORT" "/applications/$nginx/scheduled-tasks" "$TB" '{"name":"from-b","command":"echo from-b","frequency":"0 0 * * *"}' | jq -r .uuid)"
  api POST "$B_PORT" "/applications/$nginx/scheduled-tasks/$task/execute" "$TB" >/dev/null
  for _ in $(seq 1 20); do
    sleep 3
    r="$(api GET "$B_PORT" "/applications/$nginx/scheduled-tasks/$task/executions" "$TB" | jq -r '.[0].status // empty')"
    [[ "$r" == success || "$r" == failed ]] && break
  done
  check "scheduled task from B ($r)" test "$r" = success
done

echo "=== $FAILS failure(s)"
exit "$FAILS"
