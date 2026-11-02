#!/usr/bin/env bash
# Undo paths: the owner marker, transfer back from the archive, a transfer over an archive, take back with automatic
# release, and deleting an archive.
# Uses the debian-root VM. Run reset.sh before and after.
source "$(dirname "$0")/lib.sh"
TA="$(source_token)"
TB="$(target_token)"
PROFILE=debian-root
S="$(vm_field "$PROFILE" uuid)"

marker() { ssh_vm "$PROFILE" 'cat /data/coolify/transfer/owner.json 2>/dev/null' | jq -r .claim_id; }
claim_id() { tinker "$1" "echo data_get(App\\Models\\Server::where('uuid','$S')->first()?->server_metadata,'transfer.claim.claim_id');" | tail -1; }
status() { tinker "$1" "echo data_get(App\\Models\\Server::withTrashed()->where('uuid','$S')->first()?->server_metadata,'transfer.status','none');" | tail -1; }
# migrate <from port> <from token> <to port> <to token>: transfer through the API, wait, print the transfer status JSON.
migrate() {
  local r
  api POST "$1" "/servers/$S/migrate" "$2" "$(jq -nc --arg u "http://localhost:$3" --arg t "$4" '{target_url:$u,target_token:$t}')" >/dev/null
  for _ in $(seq 1 40); do sleep 3; r="$(api GET "$1" "/servers/$S/transfer" "$2")"; [[ "$(jq -r .migration.status <<<"$r")" != running ]] && break; done
  echo "$r"
}

before="$(stable_workloads "$PROFILE")"

echo '=== 1. A -> B writes the owner marker of B'
r="$(migrate "$A_PORT" "$TA" "$B_PORT" "$TB")"
check "transfer A -> B ($(jq -r .migration.status <<<"$r"))" test "$(jq -r .migration.status <<<"$r")" = succeeded
cb="$(claim_id "$B")"
check "marker = claim of B ($cb)" test "$(marker)" = "$cb"

echo '=== 2. Transfer back from the Transfer page of the archive on A'
tinker "$A" "$login_php Livewire\\Livewire::test(App\\Livewire\\Server\\Transfer::class, ['server_uuid'=>'$S'])->set('managerUrl','http://localhost:$B_PORT')->set('managerToken','$TB')->set('thisInstanceUrl','http://localhost:$A_PORT')->call('transferBack');" >/dev/null
for _ in $(seq 1 40); do sleep 3; [[ "$(status "$A")" == claimed ]] && break; done
ca="$(claim_id "$A")"
check 'A claimed (archive replaced), B transferred' test "$(status "$A")/$(status "$B")" = claimed/transferred
check 'A has one server with this uuid' test "$(tinker "$A" "echo App\\Models\\Server::withTrashed()->where('uuid','$S')->count();" | tail -1)" = 1
check "marker = claim of A ($ca)" test "$(marker)" = "$ca"
check 'workloads unchanged after the transfer back' test "$(workloads "$PROFILE")" = "$before"

echo '=== 3. A -> B again replaces the archive on B'
r="$(migrate "$A_PORT" "$TA" "$B_PORT" "$TB")"
check "transfer A -> B again ($(jq -r .migration.status <<<"$r"))" test "$(jq -r .migration.status <<<"$r")" = succeeded
check 'B claimed, A transferred' test "$(status "$B")/$(status "$A")" = claimed/transferred
check 'workloads unchanged after the second transfer' test "$(workloads "$PROFILE")" = "$before"

echo '=== 4. Take back on A, then B releases the server on its next connection check'
tinker "$A" "$login_php Livewire\\Livewire::test(App\\Livewire\\Server\\Show::class, ['server_uuid'=>'$S'])->call('enableManagement');" >/dev/null
ca="$(claim_id "$A")"
check 'A claimed again' test "$(status "$A")" = claimed
check "marker = new claim of A ($ca)" test "$(marker)" = "$ca"
check 'A started the finish list' test "$(tinker "$A" "echo count((array) data_get(App\\Models\\Server::where('uuid','$S')->first()->server_metadata,'transfer.finish.items')) > 0 ? 1 : 0;" | tail -1)" = 1
check 'B still claimed before its check' test "$(status "$B")" = claimed
tinker "$B" "(new App\\Jobs\\ServerConnectionCheckJob(App\\Models\\Server::where('uuid','$S')->first()))->handle();" >/dev/null
check 'B released the server after its check' test "$(status "$B")" = transferred
check 'B is force disabled' test "$(tinker "$B" "echo (int) App\\Models\\Server::where('uuid','$S')->first()->settings->force_disabled;" | tail -1)" = 1
tinker "$A" "(new App\\Jobs\\ServerConnectionCheckJob(App\\Models\\Server::where('uuid','$S')->first()))->handle();" >/dev/null
check 'A keeps the server after its own check' test "$(status "$A")" = claimed

echo '=== 5. Delete the archive on B (records only)'
tinker "$B" "$login_php Livewire\\Livewire::test(App\\Livewire\\Server\\Delete::class, ['server_uuid'=>'$S'])->call('delete','password');" >/dev/null
for _ in $(seq 1 20); do sleep 3; [[ "$(status "$B")" == none ]] && break; done
check 'archive is gone on B' test "$(status "$B")" = none
check 'workloads unchanged at the end' test "$(workloads "$PROFILE")" = "$before"

echo "=== $FAILS failure(s)"
exit "$FAILS"
