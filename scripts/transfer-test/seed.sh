#!/usr/bin/env bash
# Create real resources on the two transfer VMs of A through the API, then deploy and start them.
# Run on a fresh A only (reset.sh calls it); it does not clean up. Writes the uuids to <state>/resources.json.
source "$(dirname "$0")/lib.sh"
set -e

T="$(source_token)"
S1="$(vm_field ubuntu-non-root uuid)"
S2="$(vm_field debian-root uuid)"

call() { # call METHOD PATH [JSON]: fails on an HTTP error
  local out code
  out="$(curl -sS -X "$1" "http://127.0.0.1:$A_PORT/api/v1$2" -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -H 'Accept: application/json' ${3:+-d "$3"} -w $'\n%{http_code}')"
  code="${out##*$'\n'}"
  out="${out%$'\n'*}"
  if [[ "$code" -ge 300 ]]; then
    echo "FAIL $1 $2 ($code): $out" >&2
    return 1
  fi
  echo "$out"
}
uuid() { jq -r '.uuid'; }

echo '== projects'
P1=$(call POST /projects '{"name":"Transfer Demo","description":"Resources on the ubuntu non-root VM"}' | uuid)
P2=$(call POST /projects '{"name":"Second Server","description":"Resources on the debian root VM"}' | uuid)
call POST "/projects/$P1/environments" '{"name":"staging"}' >/dev/null

echo '== server 1 (ubuntu non-root): Docker image app with variables, volume, tag, scheduled task'
APP1=$(call POST /applications/dockerimage "$(jq -nc --arg p "$P1" --arg s "$S1" '{project_uuid:$p,server_uuid:$s,environment_name:"production",name:"whoami",docker_registry_image_name:"traefik/whoami",docker_registry_image_tag:"latest",ports_exposes:"80",instant_deploy:false}')" | uuid)
call POST "/applications/$APP1/envs" '{"key":"WHOAMI_NAME","value":"transfer-demo"}' >/dev/null
call POST "/applications/$APP1/envs" '{"key":"APP_SECRET","value":"very-secret-value"}' >/dev/null
call POST "/applications/$APP1/storages" '{"type":"persistent","name":"whoami-data","mount_path":"/data"}' >/dev/null || true
call POST "/applications/$APP1/tags" '{"tag_name":"transfer-demo"}' >/dev/null || true
call POST "/applications/$APP1/scheduled-tasks" '{"name":"hello","command":"echo hello","frequency":"*/30 * * * *"}' >/dev/null || true

echo '== server 1: Dockerfile app (staging)'
DF=$(printf 'FROM nginx:alpine\nRUN echo "built by Coolify A" > /usr/share/nginx/html/index.html\nEXPOSE 80\n' | base64 -w0)
APP2=$(call POST /applications/dockerfile "$(jq -nc --arg p "$P1" --arg s "$S1" --arg d "$DF" '{project_uuid:$p,server_uuid:$s,environment_name:"staging",name:"nginx-dockerfile",dockerfile:$d,ports_exposes:"80",instant_deploy:false}')" | uuid)

echo '== server 1: PostgreSQL with a backup, Redis'
PG=$(call POST /databases/postgresql "$(jq -nc --arg p "$P1" --arg s "$S1" '{project_uuid:$p,server_uuid:$s,environment_name:"production",name:"postgres",postgres_user:"demo",postgres_password:"pg-secret-password",postgres_db:"demo",instant_deploy:false}')" | uuid)
call POST "/databases/$PG/backups" '{"frequency":"0 3 * * *","enabled":true,"save_s3":false,"databases_to_backup":"demo"}' >/dev/null || true
RD=$(call POST /databases/redis "$(jq -nc --arg p "$P1" --arg s "$S1" '{project_uuid:$p,server_uuid:$s,environment_name:"production",name:"redis",instant_deploy:false}')" | uuid)

echo '== server 1: one-click service (uptime-kuma)'
SV=$(call POST /services "$(jq -nc --arg p "$P1" --arg s "$S1" '{type:"uptime-kuma",project_uuid:$p,server_uuid:$s,environment_name:"production",name:"uptime-kuma",instant_deploy:false}')" | uuid)

echo '== server 2 (debian root): whoami-2, MariaDB'
APP3=$(call POST /applications/dockerimage "$(jq -nc --arg p "$P2" --arg s "$S2" '{project_uuid:$p,server_uuid:$s,environment_name:"production",name:"whoami-2",docker_registry_image_name:"traefik/whoami",docker_registry_image_tag:"latest",ports_exposes:"80",instant_deploy:false}')" | uuid)
MD=$(call POST /databases/mariadb "$(jq -nc --arg p "$P2" --arg s "$S2" '{project_uuid:$p,server_uuid:$s,environment_name:"production",name:"mariadb",instant_deploy:false}')" | uuid)

echo '== deploy and start everything'
for u in $APP1 $APP2 $APP3; do call POST "/deploy?uuid=$u" >/dev/null && echo "deploy queued $u"; done
for u in $PG $RD $MD; do call POST "/databases/$u/start" >/dev/null && echo "start queued $u"; done
call POST "/services/$SV/start" >/dev/null && echo "start queued $SV"

jq -n --arg p1 "$P1" --arg p2 "$P2" --arg app1 "$APP1" --arg app2 "$APP2" --arg app3 "$APP3" --arg pg "$PG" --arg rd "$RD" --arg md "$MD" --arg sv "$SV" \
  '{projects:[$p1,$p2],apps:[$app1,$app2,$app3],databases:[$pg,$rd,$md],service:$sv}' | tee "$STATE_DIR/resources.json"
