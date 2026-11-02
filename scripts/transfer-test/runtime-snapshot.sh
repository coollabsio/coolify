#!/usr/bin/env bash
# Write what Coolify runs on a VM of the source to a file: the workload containers (normalized docker inspect) and the
# files that Coolify writes in /data/coolify. Take one after the source deployed and one after the target deployed
# again, then compare them with `diff`. Values that change with every deployment are left out.
#
# Usage: scripts/transfer-test/runtime-snapshot.sh <profile> <output file>
source "$(dirname "$0")/lib.sh"

[[ $# -eq 2 ]] || die 'Usage: runtime-snapshot.sh <profile> <output file>'
inspect="$(ssh_vm "$1" 'docker ps -q | xargs -r docker inspect')"
# .env lines are sorted: their order has no meaning and follows the database IDs of the variables.
files="$(ssh_vm "$1" 'cd /data/coolify && find applications databases services -type f -size -64k 2>/dev/null | sort | while read -r f; do printf "=== %s\n" "$f"; case "$f" in *.env) sort "$f" ;; *) cat "$f" ;; esac; echo; done')"
jq -S '
  map(select(.Name | test("^/coolify-(proxy|sentinel)$") | not)) | map({
    name: (.Name | ltrimstr("/")),
    image: .Config.Image, cmd: .Config.Cmd, entrypoint: .Config.Entrypoint, user: .Config.User, healthcheck: .Config.Healthcheck,
    env: ((.Config.Env // []) | map(select(test("^(SOURCE_COMMIT|HOSTNAME)=") | not)) | sort),
    labels: ((.Config.Labels // {}) | with_entries(select(.key | test("^(com\\.docker\\.compose\\.(config-hash|container-number|oneoff|version|depends_on|replace|image)|org\\.opencontainers\\.)") | not))),
    host: (.HostConfig | {Memory, MemorySwap, MemorySwappiness, MemoryReservation, NanoCpus, CpusetCpus, CpuShares, RestartPolicy, PortBindings, CapAdd, ShmSize, Ulimits, LogConfig, ExtraHosts, Privileged, Init}),
    mounts: ((.Mounts // []) | map({Type, Name, Source, Destination, RW}) | sort_by(.Destination)),
    networks: (.NetworkSettings.Networks | to_entries | map({network: .key, aliases: ((.value.Aliases // []) | map(select(test("^[0-9a-f]{12}$") | not)) | sort)}) | sort_by(.network))
  }) | sort_by(.name)' <<<"$inspect" >"$2"
printf '%s\n' "$files" >>"$2"
# Values that every deployment changes: the container name suffix, the artifact folder, and the deployment date.
sed -i -E 's/-[0-9]{8}T[0-9]{6}/-<deployed>/g; s#/artifacts/[a-z0-9]+#/artifacts/<id>#g; s/^(Latest Deployment Date:).*/\1 <date>/; s/^(SOURCE_COMMIT)=.*/\1=<varies>/' "$2"
echo "runtime snapshot of $1: $(jq length <<<"$inspect") containers -> $2"
