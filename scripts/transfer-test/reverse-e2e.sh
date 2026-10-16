#!/usr/bin/env bash
# The owner marker on the host, the reverse transfer B -> A, deleting the archive, and take back with automatic release.
# Uses the debian-root VM. Run reset.sh before and after.
source "$(dirname "$0")/lib.sh"
TA="$(source_token)"
TB="$(target_token)"
PROFILE=debian-root
S="$(vm_field "$PROFILE" uuid)"

marker() { ssh_vm "$PROFILE" 'cat /data/coolify/transfer/owner.json 2>/dev/null' | jq -r .claim_id; }
claim_id() { tinker "$1" "echo data_get(App\\Models\\Server::where('uuid','$S')->first()?->server_metadata,'transfer.claim.claim_id');" | tail -1; }
status() { tinker "$1" "echo data_get(App\\Models\\Server::withTrashed()->where('uuid','$S')->first()?->server_metadata,'transfer.status','none');" | tail -1; }
workloads() { ssh_vm "$PROFILE" 'docker ps --format "{{.Names}} {{.ID}}" | grep -v coolify-sentinel | sort'; }
# migrate <from port> <from token> <to port> <to token>: transfer through the API, wait, print the transfer status JSON.
migrate() {
  local r
  api POST "$1" "/servers/$S/migrate" "$2" "$(jq -nc --arg u "http://localhost:$3" --arg t "$4" '{target_url:$u,target_token:$t}')" >/dev/null
  for _ in $(seq 1 40); do sleep 3; r="$(api GET "$1" "/servers/$S/transfer" "$2")"; [[ "$(jq -r .migration.status <<<"$r")" != running ]] && break; done
  echo "$r"
}

before="$(workloads)"

echo '=== 1. A -> B writes the owner marker of B'
r="$(migrate "$A_PORT" "$TA" "$B_PORT" "$TB")"
check "transfer A -> B ($(jq -r .migration.status <<<"$r"))" test "$(jq -r .migration.status <<<"$r")" = succeeded
cb="$(claim_id "$B")"
check "marker = claim of B ($cb)" test "$(marker)" = "$cb"

echo '=== 2. B -> A is refused while A keeps the archive'
r="$(migrate "$B_PORT" "$TB" "$A_PORT" "$TA")"
msg="$(jq -r '.migration.error // .migration.message // empty' <<<"$r")"
check "reverse transfer refused: ${msg:0:90}" grep -q 'archived copy' <<<"$msg"
check 'B still manages the server' test "$(status "$B")" = claimed

echo '=== 3. Delete the archive on A (records only)'
tinker "$A" "$login_php Livewire\\Livewire::test(App\\Livewire\\Server\\Delete::class, ['server_uuid'=>'$S'])->call('delete','password');" >/dev/null
for _ in $(seq 1 20); do sleep 3; [[ "$(status "$A")" == none ]] && break; done
check 'archive is gone on A' test "$(status "$A")" = none
check 'workloads unchanged after the archive delete' test "$(workloads)" = "$before"

echo '=== 4. B -> A succeeds'
r="$(migrate "$B_PORT" "$TB" "$A_PORT" "$TA")"
check "transfer B -> A ($(jq -r .migration.status <<<"$r") $(jq -r '.migration.error // empty' <<<"$r" | cut -c1-80))" test "$(jq -r .migration.status <<<"$r")" = succeeded
ca="$(claim_id "$A")"
check 'A claimed, B transferred' test "$(status "$A")/$(status "$B")" = claimed/transferred
check "marker = claim of A ($ca)" test "$(marker)" = "$ca"
check 'workloads unchanged after the reverse transfer' test "$(workloads)" = "$before"

echo '=== 5. Take back on B, then A releases the server on its next connection check'
tinker "$B" "$login_php Livewire\\Livewire::test(App\\Livewire\\Server\\Show::class, ['server_uuid'=>'$S'])->call('enableManagement');" >/dev/null
cb="$(claim_id "$B")"
check 'B claimed again' test "$(status "$B")" = claimed
check "marker = new claim of B ($cb)" test "$(marker)" = "$cb"
check 'A still claimed before its check' test "$(status "$A")" = claimed
tinker "$A" "(new App\\Jobs\\ServerConnectionCheckJob(App\\Models\\Server::where('uuid','$S')->first()))->handle();" >/dev/null
check 'A released the server after its check' test "$(status "$A")" = transferred
check 'A records B as the owner' test "$(tinker "$A" "echo data_get(App\\Models\\Server::where('uuid','$S')->first()->server_metadata,'transfer.target_instance_url');" | tail -1)" = "$B_URL"
check 'A is force disabled' test "$(tinker "$A" "echo (int) App\\Models\\Server::where('uuid','$S')->first()->settings->force_disabled;" | tail -1)" = 1
tinker "$B" "(new App\\Jobs\\ServerConnectionCheckJob(App\\Models\\Server::where('uuid','$S')->first()))->handle();" >/dev/null
check 'B keeps the server after its own check' test "$(status "$B")" = claimed
check 'workloads unchanged at the end' test "$(workloads)" = "$before"

echo "=== $FAILS failure(s)"
exit "$FAILS"
