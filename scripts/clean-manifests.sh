#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
LIMIT="${2:-}"
IMAGE=coollabsio/coolify
KEEP_TAG_PATTERN='^(latest|preview|main|nightly|v?[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?)$'
KEEP_TAG_EXCEPTIONS='v3 4.0.0-beta.420.1 4.0.0-beta.420.2 4.0.0-beta.420.3 4.0.0-beta.420.4 4.0.0-beta.420.5 4.0.0-beta.420.6 4.0.0-beta.420.7 4.0.0-beta.420.8 4.0.0-beta.420.9 4.0.0-beta.420.10'
KEEP_NIGHTLY_DAYS=14
MIN_AGE_DAYS=1
DOCKERHUB_USERNAME="${DOCKERHUB_USERNAME:-}"
DOCKERHUB_TOKEN="${DOCKERHUB_TOKEN:-}"
TO_DELETE_FILE=scripts/manifests-to-delete.tsv
DELETED_FILE=scripts/deleted-manifests.tsv
WORK_DIR="$(mktemp -d)"

BOLD=$'\033[1m' BLUE=$'\033[34m' GREEN=$'\033[32m' YELLOW=$'\033[33m' RED=$'\033[31m' RESET=$'\033[0m'

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
info() { printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s⚠%s %s\n' "$YELLOW" "$RESET" "$*"; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

cd "$(dirname "$0")/.."
trap 'rm -rf "$WORK_DIR"' EXIT

main() {
	[[ "$OPTION" =~ ^(--delete [1-9][0-9]*)?$ ]] || die "'$OPTION' is not an option, expected --delete with a count or none"
	heading "Reading ghcr.io/$IMAGE and docker.io/$IMAGE"
	read_ghcr_versions
	read_dockerhub_tags
	select_kept_tags
	read_manifests
	select_kept_manifests
	heading "Selecting what to delete"
	find_dockerhub_only_manifests
	write_list
	delete_manifests
	verify_kept_manifests
}

read_ghcr_versions() {
	local page window=$((${LIMIT:-0} * 2))
	list_ghcr_versions "state=active" --paginate > "$WORK_DIR/ghcr-active.tsv"
	for ((page = 1; page <= (window + 99) / 100; page++)); do
		list_ghcr_versions "state=deleted&page=$page"
	done | awk -v window="$window" 'NR <= window' > "$WORK_DIR/ghcr-deleted.tsv"
	cut -f 1 "$WORK_DIR/ghcr-active.tsv" > "$WORK_DIR/on-ghcr.txt"
	ok "ghcr.io/$IMAGE has $(count < "$WORK_DIR/ghcr-active.tsv") versions, $(awk -F '\t' '$3 == ""' "$WORK_DIR/ghcr-active.tsv" | count) of them untagged, and $(count < "$WORK_DIR/ghcr-deleted.tsv") of the versions it deleted last are checked on docker.io, twice as many as the run deletes"
}

read_dockerhub_tags() {
	local token page last=""
	token="$(registry_token docker.io pull)"
	while :; do
		page="$(curl -sSf --retry 3 -H "Authorization: Bearer $token" "https://registry-1.docker.io/v2/$IMAGE/tags/list?n=1000${last:+&last=$last}" | jq -r '.tags // [] | .[]')" \
			|| die "could not list the tags of docker.io/$IMAGE"
		[ -n "$page" ] || break
		echo "$page" >> "$WORK_DIR/dockerhub-tags.txt"
		last="$(tail -n 1 <<< "$page")"
	done
	request_manifests docker.io "$WORK_DIR/dockerhub-tags.txt" -f --head -o /dev/null -w '%header{docker-content-digest}\t%header{content-type}\t{}\n' \
		> "$WORK_DIR/dockerhub.tsv" || die "could not read the digests of the tags of docker.io/$IMAGE"
	ok "docker.io/$IMAGE has $(count < "$WORK_DIR/dockerhub.tsv") tags on $(cut -f 1 "$WORK_DIR/dockerhub.tsv" | sort -u | count) manifests"
}

select_kept_tags() {
	local cutoff
	{ cut -f 3 "$WORK_DIR/ghcr-active.tsv" | tr ',' '\n' && cut -f 3 "$WORK_DIR/dockerhub.tsv"; } | sed '/^$/d' | sort -u > "$WORK_DIR/tags.txt"
	git ls-remote --tags "https://github.com/$IMAGE.git" | sed -n 's|.*refs/tags/||p' | sed 's/\^{}$//; p; s/^v//' > "$WORK_DIR/keep.txt" \
		|| die "could not list the git tags of github.com/$IMAGE"
	gh pr list --repo "$IMAGE" --state open --limit 1000 --json number --jq '.[] | "pr-\(.number)"' >> "$WORK_DIR/keep.txt" \
		|| die "gh could not list the open pull requests of $IMAGE"
	tr ' ' '\n' <<< "$KEEP_TAG_EXCEPTIONS" >> "$WORK_DIR/keep.txt"
	cutoff="$(jq -nr --argjson days "$KEEP_NIGHTLY_DAYS" 'now - $days * 86400 | strftime("%Y%m%d")')"
	{
		grep -E "$KEEP_TAG_PATTERN" "$WORK_DIR/tags.txt" || true
		grep -xF -f "$WORK_DIR/keep.txt" "$WORK_DIR/tags.txt" || true
		grep -E -e '-(alpha|beta|rc)\.[0-9]+\.20[0-9]{6}\.[0-9]+$' "$WORK_DIR/tags.txt" | awk -F . -v cutoff="$cutoff" '$(NF - 1) >= cutoff' || true
	} | sort -u > "$WORK_DIR/kept-tags.txt"
	grep -qx latest "$WORK_DIR/kept-tags.txt" || die "found no latest tag, expected it among the tags to keep"
	ok "keeping $(count < "$WORK_DIR/kept-tags.txt") tags: every tag KEEP_TAG_PATTERN matches or KEEP_TAG_EXCEPTIONS lists, the nightlies of the last $KEEP_NIGHTLY_DAYS days, every git tag of github.com/$IMAGE and the tags of open pull requests"
}

read_manifests() {
	local digest tag
	mkdir "$WORK_DIR/manifests"
	request_manifests ghcr.io "$WORK_DIR/on-ghcr.txt" -f -o "$WORK_DIR/manifests/{}" -w '{}\t%{size_download}\n' > "$WORK_DIR/bytes.tsv" \
		|| die "could not read the manifests of ghcr.io/$IMAGE"
	awk -F '\t' -v OFS='\t' 'FILENAME == ARGV[1] { kept[$1]; next } FILENAME == ARGV[2] { ghcr[$1]; next } ($3 in kept) && !($1 in ghcr) && !seen[$1]++ { print $1, $2, $3 }' \
		"$WORK_DIR/kept-tags.txt" "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/dockerhub-roots.tsv"
	awk -F '\t' '$2 ~ /oci\.image\.index/ { print $1 }' "$WORK_DIR/dockerhub-roots.tsv" > "$WORK_DIR/dockerhub-indexes.txt"
	request_manifests docker.io "$WORK_DIR/dockerhub-indexes.txt" -f -o "$WORK_DIR/manifests/{}" || die "could not read the indexes kept tags point at on docker.io"
	while IFS=$'\t' read -r digest tag; do
		curl -sSf --retry 3 "https://hub.docker.com/v2/namespaces/${IMAGE%%/*}/repositories/${IMAGE#*/}/tags/$tag" | jq -r --arg root "$digest" '.images[] | [$root, .digest] | @tsv' \
			|| die "could not read the manifests $tag points at on docker.io"
	done < <(awk -F '\t' -v OFS='\t' '$2 !~ /oci\.image\.index/ { print $1, $3 }' "$WORK_DIR/dockerhub-roots.tsv") > "$WORK_DIR/children.tsv"
	manifest_fields '.manifests[]? | [manifest, .digest, (.platform | [.os, .architecture, .variant] | map(values) | join("/")), .annotations["vnd.docker.reference.digest"]]' \
		>> "$WORK_DIR/children.tsv"
	manifest_fields '(.config // empty), .layers[]? | [manifest, .digest, .size]' > "$WORK_DIR/blobs.tsv"
	manifest_fields '[manifest, if .manifests then "index" elif any(.layers[]?; .mediaType == "application/vnd.in-toto+json") then "attestation" else "image" end]' \
		> "$WORK_DIR/kinds.tsv"
	ok "read the $(count < "$WORK_DIR/bytes.tsv") manifests of ghcr.io/$IMAGE and the $(count < "$WORK_DIR/dockerhub-roots.tsv") that kept tags point at on docker.io alone"
}

select_kept_manifests() {
	local kinds
	kept_manifests "$WORK_DIR/ghcr-active.tsv" > "$WORK_DIR/kept-ghcr.io.txt"
	kept_manifests "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/kept-docker.io.txt"
	sort -u "$WORK_DIR/kept-ghcr.io.txt" "$WORK_DIR/kept-docker.io.txt" > "$WORK_DIR/kept.txt"
	awk 'FILENAME == ARGV[1] { ghcr[$1]; next } !($1 in ghcr) { exit 1 }' "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/kept-ghcr.io.txt" \
		|| die "manifests the kept tags point at are missing from the versions gh listed on ghcr.io, expected every one of them"
	kinds="$(awk -F '\t' 'FILENAME == ARGV[1] { kept[$1]; next } ($1 in kept) { count[$2]++ }
		END { printf "%d indexes, %d images and %d attestations", count["index"], count["image"], count["attestation"] }' "$WORK_DIR/kept-ghcr.io.txt" "$WORK_DIR/kinds.tsv")"
	ok "keeping $(count < "$WORK_DIR/kept-ghcr.io.txt") manifests on ghcr.io, $kinds, and $(count < "$WORK_DIR/kept-docker.io.txt") on docker.io: the kept tags and every manifest they point at"
}

find_dockerhub_only_manifests() {
	awk -F '\t' 'FILENAME == ARGV[1] || FILENAME == ARGV[2] { skip[$1]; next } !($1 in skip) && !seen[$1]++ { print $1 }' \
		"$WORK_DIR/kept.txt" "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/dockerhub.tsv" "$WORK_DIR/ghcr-deleted.tsv" > "$WORK_DIR/candidates.txt"
	request_manifests docker.io "$WORK_DIR/candidates.txt" --head -o /dev/null -w '%{http_code}\t{}\t%header{content-type}\n' \
		> "$WORK_DIR/dockerhub-only.tsv" || die "could not check which manifests docker.io has"
	awk '$1 != 200 && $1 != 404 { exit 1 }' "$WORK_DIR/dockerhub-only.tsv" \
		|| die "could not check docker.io for the manifests ghcr.io does not have, expected 200 or 404"
	ok "docker.io still has $(awk '$1 == 200' "$WORK_DIR/dockerhub-only.tsv" | count) of the $(count < "$WORK_DIR/candidates.txt") unused manifests ghcr.io does not have"
}

write_list() {
	local cutoff
	cutoff="$(jq -nr --argjson days "$MIN_AGE_DAYS" 'now - $days * 86400 | strftime("%Y-%m-%dT%H:%M:%SZ")')"
	ok "keeping the $(awk -F '\t' -v cutoff="$cutoff" '$4 >= cutoff && !seen[$1]++' "$WORK_DIR/ghcr-active.tsv" | count) versions ghcr.io got since $cutoff, a build may still push the index that needs them"
	awk -F '\t' -v OFS='\t' -v cutoff="$cutoff" 'function platform_of(digest) { return (reference[digest] in platform) ? platform[reference[digest]] : platform[digest] }
		FILENAME == ARGV[1] { kept[$1]; next }
		FILENAME == ARGV[2] { kind[$1] = $2; next }
		FILENAME == ARGV[3] { size[$1] = $2; next }
		FILENAME == ARGV[4] { size[$1] += $3; next }
		FILENAME == ARGV[5] { platform[$2] = $3; reference[$2] = $4; next }
		FILENAME == ARGV[6] { tags[$1] = tags[$1] (tags[$1] == "" ? "" : ",") $3; next }
		FILENAME == ARGV[7] { version[$1] = $2; created[$1] = $4; next }
		FILENAME == ARGV[8] { if ($1 == 200) print "no", "yes", ($3 ~ /index|list/ ? "index" : "image"), platform_of($2), tags[$2], created[$2], "", version[$2], $2; next }
		!($1 in kept) && $4 < cutoff && !seen[$1]++ { print "yes", "undefined", kind[$1], platform_of($1), $3, $4, size[$1], $2, $1 }' \
		"$WORK_DIR/kept.txt" "$WORK_DIR/kinds.tsv" "$WORK_DIR/bytes.tsv" "$WORK_DIR/blobs.tsv" "$WORK_DIR/children.tsv" "$WORK_DIR/dockerhub.tsv" \
		"$WORK_DIR/ghcr-deleted.tsv" "$WORK_DIR/dockerhub-only.tsv" "$WORK_DIR/ghcr-active.tsv" | sort -t $'\t' -k3,3r -k1,1 -k6,6 > "$WORK_DIR/rows.tsv"
	write_table "$WORK_DIR/rows.tsv" "$TO_DELETE_FILE"
	ok "$(awk -F '\t' 'END { print ($9 + 0 ? $9 " to delete, " $3 ", they free " $7 : "nothing to delete") }' "$TO_DELETE_FILE")"
	ok "$(awk -F '\t' '$1 == "no"' "$WORK_DIR/rows.tsv" | count) of them on docker.io alone, ghcr.io deleted them already or never had them, their size stays empty as reading it counts as a pull"
	info "$TO_DELETE_FILE lists every manifest to delete with its type, platform, tags, date and size, indexes first"
}

delete_manifests() {
	local on_ghcr type tags version digest rest name deleted ghcr docker_hub status
	if [ -z "$LIMIT" ]; then
		info "nothing is deleted, a run with --delete and a count deletes that many from the top of the list"
		return
	fi
	[ -n "$DOCKERHUB_USERNAME" ] && [ -n "$DOCKERHUB_TOKEN" ] || die "DOCKERHUB_USERNAME and DOCKERHUB_TOKEN must be set to delete manifests on docker.io"
	heading "Deleting"
	write_table /dev/null "$DELETED_FILE"
	while IFS='|' read -r on_ghcr type tags version digest rest; do
		[[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || die "the list names '$digest', expected a sha256 digest"
		name="$digest${tags:+ ($tags)}"
		deleted="" ghcr=no docker_hub=no
		if [ "$on_ghcr" = yes ]; then
			delete_ghcr_version "$version" "$type $name" || continue
			deleted=ghcr.io ghcr=yes
		fi
		status="$(delete_dockerhub_manifest "$digest")"
		if [[ "$status" == 2?? ]]; then
			deleted="${deleted:+$deleted and }docker.io" docker_hub=yes
		fi
		if [ -n "$deleted" ]; then
			ok "deleted the $type $BOLD$name$RESET from $deleted"
			printf '%s\t%s\t%s\n' "$ghcr" "$docker_hub" "$rest" >> "$WORK_DIR/deleted.tsv"
			write_table "$WORK_DIR/deleted.tsv" "$DELETED_FILE"
		fi
		case "$status" in
			2?? | 404) ;;
			403 | 5??) warn "docker.io keeps the $type $name for now, it answered $status, the next run tries again" ;;
			*) die "could not delete the $type $name from docker.io, it answered $status" ;;
		esac
	done < <(head -n "$LIMIT" "$WORK_DIR/rows.tsv" | awk -F '\t' -v OFS='|' '{ rest = $3; for (i = 4; i <= NF; i++) rest = rest "\t" $i; print $1, $3, $5, $8, $9, rest }')
	ok "$(awk -F '\t' 'END { print ($9 + 0 ? "deleted " $9 ", " $3 ", they free " $7 : "deleted nothing") }' "$DELETED_FILE")"
	info "$DELETED_FILE lists what this run deleted and where, ghcr.io restores it for 30 days, docker.io deletes for good"
}

verify_kept_manifests() {
	local registry missing
	[ -n "$LIMIT" ] || return 0
	for registry in ghcr.io docker.io; do
		missing="$(request_manifests "$registry" "$WORK_DIR/kept-$registry.txt" --head -o /dev/null -w '%{http_code}\t{}\n' | awk '$1 != 200 { print $2 }')" \
			|| die "could not check the kept manifests on $registry"
		[ -z "$missing" ] || die "$(count <<< "$missing") manifests the kept tags point at are gone from $registry, expected all of them: $(head -n 3 <<< "$missing" | paste -sd ' ' -)"
	done
	ok "every kept tag and every manifest it points at is still on ghcr.io and docker.io"
}

list_ghcr_versions() {
	local query="$1"
	shift
	gh api "$@" "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions?per_page=100&$query" \
		--jq '.[] | [.name, (.id | tostring), (.metadata.container.tags | join(",")), .created_at] | @tsv' 2> /dev/null \
		|| die "gh could not list the versions of ghcr.io/$IMAGE with $query, it needs read:packages and delete:packages: gh auth refresh -s read:packages,delete:packages"
}

request_manifests() {
	local registry="$1" list="$2" chunk token
	shift 2
	[ -s "$list" ] || return 0
	split -l 1000 "$list" "$WORK_DIR/chunk-"
	for chunk in "$WORK_DIR"/chunk-*; do
		token="$(registry_token "$registry" pull)"
		xargs -P 16 -I {} curl -sS --retry 3 -H "Authorization: Bearer $token" \
			-H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json' \
			"$@" "https://${registry/docker.io/registry-1.docker.io}/v2/$IMAGE/manifests/{}" < "$chunk" || return 1
		rm "$chunk"
	done
}

manifest_fields() {
	find "$WORK_DIR/manifests" -type f -exec jq -r 'def manifest: input_filename | sub(".*/"; ""); '"$1"' | @tsv' {} +
}

kept_manifests() {
	awk -F '\t' 'function keep(digest, list, n, i) { if (digest in kept) return; kept[digest]; print digest; n = split(children[digest], list, " "); for (i = 1; i <= n; i++) keep(list[i]) }
		FILENAME == ARGV[1] { children[$1] = children[$1] " " $2; next }
		FILENAME == ARGV[2] { tag[$1]; next }
		{ n = split($3, tags, ","); for (i = 1; i <= n; i++) if (tags[i] in tag) keep($1) }' "$WORK_DIR/children.tsv" "$WORK_DIR/kept-tags.txt" "$1"
}

write_table() {
	awk -F '\t' -v OFS='\t' 'function human(bytes, unit) { for (unit = 1; bytes >= 1000 && unit < 5; unit++) bytes /= 1000; return sprintf(unit == 1 ? "%d %s" : "%.1f %s", bytes, units[unit]) }
		function amount(count, one, many) { return (count + 0) " " (count == 1 ? one : many) }
		BEGIN { split("B kB MB GB TB", units, " "); print "ghcr", "docker_hub", "type", "platform", "tags", "created", "size", "ghcr_id", "digest" }
		FILENAME == ARGV[1] { removed[$9]; types[$3]++; manifests++; if ($7 != "") $7 = human($7); print; next }
		FILENAME == ARGV[2] { before += $2; if (!($1 in removed)) after += $2; next }
		{ blob[$2] = $3; if (!($1 in removed)) used[$2] }
		END {
			for (digest in blob) { before += blob[digest]; if (digest in used) after += blob[digest] }
			print "total", "", amount(types["index"], "index", "indexes") ", " amount(types["image"], "image", "images") ", " amount(types["attestation"], "attestation", "attestations"),
				"", "", "", human(before - after) " on ghcr.io (" human(before) " → " human(after) ")", "", amount(manifests, "manifest", "manifests")
		}' "$1" "$WORK_DIR/bytes.tsv" "$WORK_DIR/blobs.tsv" > "$2"
}

delete_ghcr_version() {
	local error
	[[ "$1" =~ ^[0-9]+$ ]] || die "the version id of the $2 is '$1', expected a number from the GitHub API"
	until error="$(gh api -X DELETE "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions/$1" 2>&1 > /dev/null)"; do
		case "$error" in
			*"rate limit"*) sleep 60 ;;
			*downloads*)
				warn "ghcr.io and docker.io keep the $2, it has more than 5,000 downloads on ghcr.io"
				return 1
				;;
			*) die "could not delete the $2 from ghcr.io: $error" ;;
		esac
	done
	sleep 1
}

delete_dockerhub_manifest() {
	local status
	while status="$(curl -sS --retry 3 -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer $(registry_token docker.io pull,push,delete)" \
		"https://registry-1.docker.io/v2/$IMAGE/manifests/$1")" && [ "$status" = 429 ]; do
		sleep 60
	done
	echo "$status"
}

registry_token() {
	case "$1" in
		ghcr.io) curl -sSf --retry 3 "https://ghcr.io/token?scope=repository:$IMAGE:$2" ;;
		docker.io) basic_auth "${DOCKERHUB_USERNAME:+$DOCKERHUB_USERNAME:$DOCKERHUB_TOKEN}" | curl -sSf --retry 3 -K - "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$IMAGE:$2" ;;
	esac | jq -r .token
}

basic_auth() {
	[ -z "$1" ] || printf 'header = "Authorization: Basic %s"\n' "$(printf '%s' "$1" | jq -Rrj @base64)"
}

count() { awk 'END { print NR }'; }

main
