#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
LIMIT="${2:-}"
IMAGE=coollabsio/coolify
KEEP_TAG_PATTERN='^(latest|preview|main|nightly|v?[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?)$'
KEEP_TAG_EXCEPTIONS=v3,4.0.0-beta.420.1,4.0.0-beta.420.2,4.0.0-beta.420.3,4.0.0-beta.420.4,4.0.0-beta.420.5,4.0.0-beta.420.6,4.0.0-beta.420.7,4.0.0-beta.420.8,4.0.0-beta.420.9,4.0.0-beta.420.10
KEEP_NIGHTLY_DAYS=14
MIN_AGE_DAYS=1
DOCKERHUB_USERNAME="${DOCKERHUB_USERNAME:-}"
DOCKERHUB_TOKEN="${DOCKERHUB_TOKEN:-}"
GHCR_TOKEN=""
MANIFEST_TYPES='application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'
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
	[ -n "$DOCKERHUB_USERNAME" ] && [ -n "$DOCKERHUB_TOKEN" ] || die "DOCKERHUB_USERNAME and DOCKERHUB_TOKEN must be set to read docker.io/$IMAGE"
	GHCR_TOKEN="$(gh auth token)" || die "gh is not signed in with a token that has read:packages and delete:packages: gh auth refresh -s read:packages,delete:packages"
	heading "Reading ghcr.io/$IMAGE and docker.io/$IMAGE"
	read_ghcr_versions
	read_dockerhub_tags
	read_dockerhub_manifests_deleted_on_ghcr
	read_dockerhub_untagged_manifests
	select_kept_tags
	read_manifests
	select_kept_manifests
	heading "Selecting what to delete"
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
	ok "ghcr.io/$IMAGE has $(count < "$WORK_DIR/ghcr-active.tsv") versions, $(awk -F '\t' '$3 == ""' "$WORK_DIR/ghcr-active.tsv" | count) of them untagged"
}

read_dockerhub_tags() {
	local hub url page
	hub="$(hub_token)" || die "could not sign in to the Docker Hub API as $DOCKERHUB_USERNAME"
	url="https://hub.docker.com/v2/namespaces/${IMAGE%%/*}/repositories/${IMAGE#*/}/tags?page_size=100"
	: > "$WORK_DIR/hub-tags.tsv"
	: > "$WORK_DIR/hub-images.tsv"
	: > "$WORK_DIR/dockerhub-images.tsv"
	while [ -n "$url" ]; do
		page="$(curl -sSf --retry 3 -H "Authorization: Bearer $hub" "$url")" || die "could not list the tags of docker.io/$IMAGE from the Docker Hub API"
		jq -r '.results[] | [.name, (.digest // ""), (.media_type // ""), (.tag_last_pushed // .last_updated // ""), (.full_size // 0)] | @tsv' <<< "$page" >> "$WORK_DIR/hub-tags.tsv"
		jq -r '.results[] | .name as $tag | .images[]? | select(.digest) | [$tag, .digest, ([.os, .architecture] | join("/")), (.last_pushed // ""), (.size // 0)] | @tsv' <<< "$page" >> "$WORK_DIR/hub-images.tsv"
		url="$(jq -r '.next // empty' <<< "$page")"
	done
	awk -F '\t' '$2 == "" || $3 == "" { print $1 }' "$WORK_DIR/hub-tags.tsv" > "$WORK_DIR/old-tags.txt"
	request_manifests docker.io "$WORK_DIR/old-tags.txt" -f --head -o /dev/null -w '{}\t%header{docker-content-digest}\t%header{content-type}\n' > "$WORK_DIR/old-tags.tsv" \
		|| die "could not read the digests of the old tags of docker.io/$IMAGE, the Docker Hub API lists them without one"
	awk -F '\t' -v OFS='\t' -v images="$WORK_DIR/dockerhub-images.tsv" '
		FILENAME == ARGV[1] { old_digest[$1] = $2; old_type[$1] = $3; next }
		FILENAME == ARGV[2] { if ($2 == "") $2 = old_digest[$1]; if ($3 == "") $3 = old_type[$1]; root[$1] = $2; tags[$2] = ($2 in tags) ? tags[$2] "," $1 : $1; type[$2] = $3; if ($4 > pushed[$2]) pushed[$2] = $4; next }
		$2 == root[$1] { platform[$2] = $3; size[$2] = $5; next }
		!((root[$1], $2) in pair) { pair[root[$1], $2]; print root[$1], $2 > images }
		!($2 in tags) && !seen[$2]++ { print $2, "", "", $4, $5, $3 }
		END { for (d in tags) print d, type[d], tags[d], pushed[d], (type[d] ~ /index|list/ ? 0 : size[d] + 0), platform[d] }' \
		"$WORK_DIR/old-tags.tsv" "$WORK_DIR/hub-tags.tsv" "$WORK_DIR/hub-images.tsv" > "$WORK_DIR/dockerhub-manifests.tsv"
	awk -F '\t' -v OFS='\t' '$3 != "" { n = split($3, tags, ","); for (i = 1; i <= n; i++) print $1, $2, tags[i] }' "$WORK_DIR/dockerhub-manifests.tsv" > "$WORK_DIR/dockerhub.tsv"
	grep -q $'\tlatest$' "$WORK_DIR/dockerhub.tsv" || die "docker.io/$IMAGE lists no latest tag, expected the Docker Hub API to return every tag"
	awk -F '\t' '$1 !~ /^sha256:/ || $4 == "" { exit 1 }' "$WORK_DIR/dockerhub-manifests.tsv" || die "docker.io/$IMAGE lists manifests without a digest or push date, expected both from the Docker Hub API"
	ok "docker.io/$IMAGE has $(count < "$WORK_DIR/dockerhub.tsv") tags on $(awk -F '\t' '$3 != ""' "$WORK_DIR/dockerhub-manifests.tsv" | count) manifests, which reference $(awk -F '\t' '$3 == ""' "$WORK_DIR/dockerhub-manifests.tsv" | count) more"
}

read_dockerhub_manifests_deleted_on_ghcr() {
	awk -F '\t' 'FILENAME == ARGV[1] { listed[$1]; next } !($1 in listed) && !seen[$1]++ { print $1 }' "$WORK_DIR/dockerhub-manifests.tsv" "$WORK_DIR/ghcr-deleted.tsv" > "$WORK_DIR/deleted-on-ghcr.txt"
	request_manifests docker.io "$WORK_DIR/deleted-on-ghcr.txt" --head -o /dev/null -w '%{http_code}\t{}\t%header{content-type}\n' > "$WORK_DIR/deleted-on-ghcr.tsv" \
		|| die "could not check which of the versions that ghcr.io deleted last docker.io still has"
	awk '$1 != 200 && $1 != 404 { exit 1 }' "$WORK_DIR/deleted-on-ghcr.tsv" || die "docker.io answered neither 200 nor 404 for a version that ghcr.io deleted last"
	awk -F '\t' -v OFS='\t' 'FILENAME == ARGV[1] { created[$1] = $4; next } $1 == 200 { print $2, $3, "", created[$2], "", "" }' "$WORK_DIR/ghcr-deleted.tsv" "$WORK_DIR/deleted-on-ghcr.tsv" >> "$WORK_DIR/dockerhub-manifests.tsv"
	ok "docker.io still has $(awk '$1 == 200' "$WORK_DIR/deleted-on-ghcr.tsv" | count) of the $(count < "$WORK_DIR/ghcr-deleted.tsv") versions that ghcr.io deleted last and no tag references"
}

# The manifests endpoint is undocumented, Docker Hub uses it for image management, so it can change without notice. It only adds the untagged manifests that no tag references
read_dockerhub_untagged_manifests() {
	local hub base url page key
	hub="$(hub_token)" || die "could not sign in to the Docker Hub API as $DOCKERHUB_USERNAME"
	base="https://hub.docker.com/v2/namespaces/${IMAGE%%/*}/repositories/${IMAGE#*/}/manifests?page_size=100"
	url="$base"
	: > "$WORK_DIR/listing.tsv"
	while [ -n "$url" ]; do
		page="$(curl -sSf --retry 3 -H "Authorization: Bearer $hub" "$url")" \
			&& jq -r '.manifests[] | [.manifest_digest, .media_type, (.tags // [] | join(",")), .last_pushed, (if (.media_type // "" | test("index|list")) then .size else .total_size end), ([.os, .arch] | map(select(. != null and . != "")) | join("/"))] | @tsv' <<< "$page" >> "$WORK_DIR/listing.tsv" \
			|| { warn "the undocumented manifests listing of the Docker Hub API failed, the untagged manifests that no tag references wait for a later run"; return; }
		key="$(jq -r 'select(.manifests | length > 0) | .last_evaluated_key // empty' <<< "$page")"
		url="${key:+$base&last_evaluated_key=$(jq -rn --arg key "$key" '$key | @uri')}"
	done
	awk -F '\t' -v OFS='\t' 'FILENAME == ARGV[1] { known[$1]; next } !($1 in known) && $3 == "" { print $1, $2, "", $4, $5, $6 }' "$WORK_DIR/dockerhub-manifests.tsv" "$WORK_DIR/listing.tsv" > "$WORK_DIR/untagged.tsv"
	cat "$WORK_DIR/untagged.tsv" >> "$WORK_DIR/dockerhub-manifests.tsv"
	ok "the undocumented manifests listing adds $(count < "$WORK_DIR/untagged.tsv") untagged manifests that no tag references"
}

select_kept_tags() {
	local cutoff
	{ cut -f 3 "$WORK_DIR/ghcr-active.tsv" | tr ',' '\n' && cut -f 3 "$WORK_DIR/dockerhub.tsv"; } | sed '/^$/d' | sort -u > "$WORK_DIR/tags.txt"
	git ls-remote --tags "https://github.com/$IMAGE.git" | sed -n 's|.*refs/tags/||p' | sed 's/\^{}$//; p; s/^v//' > "$WORK_DIR/keep.txt" \
		|| die "could not list the git tags of github.com/$IMAGE"
	gh pr list --repo "$IMAGE" --state open --limit 1000 --json number --jq '.[] | "pr-\(.number)"' >> "$WORK_DIR/keep.txt" \
		|| die "gh could not list the open pull requests of $IMAGE"
	tr ',' '\n' <<< "$KEEP_TAG_EXCEPTIONS" >> "$WORK_DIR/keep.txt"
	cutoff="$(jq -nr --argjson days "$KEEP_NIGHTLY_DAYS" 'now - $days * 86400 | strftime("%Y%m%d")')"
	{
		grep -E "$KEEP_TAG_PATTERN" "$WORK_DIR/tags.txt" || true
		grep -xF -f "$WORK_DIR/keep.txt" "$WORK_DIR/tags.txt" || true
		grep -E -e '-(alpha|beta|rc)\.[0-9]+\.20[0-9]{6}\.[0-9]+$' "$WORK_DIR/tags.txt" | awk -F . -v cutoff="$cutoff" '$(NF - 1) >= cutoff' || true
	} | sort -u > "$WORK_DIR/kept-tags.txt"
	grep -qx latest "$WORK_DIR/kept-tags.txt" || die "found no latest tag, expected it among the tags to keep"
	ok "keeping $(count < "$WORK_DIR/kept-tags.txt") tags: the ones KEEP_TAG_PATTERN matches or KEEP_TAG_EXCEPTIONS lists, the nightlies of the last $KEEP_NIGHTLY_DAYS days, the git tags of github.com/$IMAGE and the tags of open pull requests"
}

read_manifests() {
	mkdir "$WORK_DIR/manifests"
	request_manifests ghcr.io "$WORK_DIR/on-ghcr.txt" -f -o "$WORK_DIR/manifests/{}" -w '{}\t%{size_download}\n' > "$WORK_DIR/bytes.tsv" \
		|| die "could not read the manifests of ghcr.io/$IMAGE"
	awk -F '\t' 'FILENAME == ARGV[1] { kept[$1]; next } FILENAME == ARGV[2] { ghcr[$1]; next } ($3 in kept) && !($1 in ghcr) && $2 ~ /oci\.image\.index/ && !seen[$1]++ { print $1 }' \
		"$WORK_DIR/kept-tags.txt" "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/dockerhub-indexes.txt"
	request_manifests docker.io "$WORK_DIR/dockerhub-indexes.txt" -f -o "$WORK_DIR/manifests/{}" || die "could not read the kept indexes that only docker.io has"
	manifest_fields '.manifests[]? | [manifest, .digest, (.platform | [.os, .architecture, .variant] | map(values) | join("/")), .annotations["vnd.docker.reference.digest"]]' \
		> "$WORK_DIR/children.tsv"
	manifest_fields '(.config // empty), .layers[]? | [manifest, .digest, .size]' > "$WORK_DIR/blobs.tsv"
	manifest_fields '[manifest, if .manifests then "index" elif any(.layers[]?; .mediaType == "application/vnd.in-toto+json") then "attestation" else "image" end]' \
		> "$WORK_DIR/kinds.tsv"
	ok "read the $(count < "$WORK_DIR/bytes.tsv") manifests on ghcr.io and the $(count < "$WORK_DIR/dockerhub-indexes.txt") kept indexes that only docker.io has, each with a GET that counts as a pull"
}

select_kept_manifests() {
	local kinds
	manifests_of_kept_tags "$WORK_DIR/ghcr-active.tsv" > "$WORK_DIR/kept-ghcr.io.txt"
	manifests_of_kept_tags "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/kept-docker.io.txt"
	sort -u "$WORK_DIR/kept-ghcr.io.txt" "$WORK_DIR/kept-docker.io.txt" > "$WORK_DIR/kept.txt"
	awk 'FILENAME == ARGV[1] { ghcr[$1]; next } !($1 in ghcr) { exit 1 }' "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/kept-ghcr.io.txt" \
		|| die "ghcr.io lists no version for some kept manifests, expected one for each"
	kinds="$(awk -F '\t' 'function amount(count, one, many) { return (count + 0) " " (count == 1 ? one : many) }
		FILENAME == ARGV[1] { kept[$1]; next } ($1 in kept) { count[$2]++ }
		END { print amount(count["index"], "index", "indexes") ", " amount(count["image"], "image", "images") ", " amount(count["attestation"], "attestation", "attestations") }' "$WORK_DIR/kept-ghcr.io.txt" "$WORK_DIR/kinds.tsv")"
	ok "keeping $(count < "$WORK_DIR/kept-ghcr.io.txt") manifests on ghcr.io ($kinds) and $(count < "$WORK_DIR/kept-docker.io.txt") on docker.io"
}

write_list() {
	local cutoff
	cutoff="$(jq -nr --argjson days "$MIN_AGE_DAYS" 'now - $days * 86400 | strftime("%Y-%m-%dT%H:%M:%SZ")')"
	ok "keeping the $(awk -F '\t' -v cutoff="$cutoff" '$4 >= cutoff && !seen[$1]++' "$WORK_DIR/ghcr-active.tsv" | count) versions on ghcr.io and the $(awk -F '\t' -v cutoff="$cutoff" '$4 >= cutoff' "$WORK_DIR/dockerhub-manifests.tsv" | count) manifests on docker.io pushed since $cutoff, a running build may still need them"
	awk -F '\t' -v OFS='\t' -v cutoff="$cutoff" 'function platform_of(digest) { return (reference[digest] in platform) ? platform[reference[digest]] : platform[digest] }
		FILENAME == ARGV[1] { kept[$1]; next }
		FILENAME == ARGV[2] { kind[$1] = $2; next }
		FILENAME == ARGV[3] { size[$1] = $2; next }
		FILENAME == ARGV[4] { size[$1] += $3; next }
		FILENAME == ARGV[5] { platform[$2] = $3; reference[$2] = $4; next }
		FILENAME == ARGV[6] {
			on_docker_hub[$1]
			if (!($1 in kept) && !($1 in kind) && $4 != "" && $4 < cutoff && !seen[$1]++)
				print "no", "yes", ($2 ~ /index|list/ ? "index" : ($6 == "unknown/unknown" ? "attestation" : "image")), ($6 == "unknown/unknown" ? "" : $6), $3, $4, $5, "", $1
			next
		}
		!($1 in kept) && $4 < cutoff && !seen[$1]++ { print "yes", (($1 in on_docker_hub) ? "yes" : "no"), kind[$1], platform_of($1), $3, $4, size[$1], $2, $1 }' \
		"$WORK_DIR/kept.txt" "$WORK_DIR/kinds.tsv" "$WORK_DIR/bytes.tsv" "$WORK_DIR/blobs.tsv" "$WORK_DIR/children.tsv" "$WORK_DIR/dockerhub-manifests.tsv" "$WORK_DIR/ghcr-active.tsv" \
		| sort -t $'\t' -k3,3r -k6,6 > "$WORK_DIR/sorted.tsv"
	awk -F '\t' 'function emit(digest, list, n, i) { printed[digest]; print line[digest]; n = split(children[digest], list, " "); for (i = 1; i <= n; i++) if ((list[i] in line) && !(list[i] in printed) && --parents[list[i]] == 0) emit(list[i]) }
		FILENAME == ARGV[1] || FILENAME == ARGV[2] { if (!(($1, $2) in pair)) { pair[$1, $2]; children[$1] = children[$1] " " $2 } next }
		{ order[++rows] = $9; line[$9] = $0; type[$9] = $3 }
		END {
			for (i = 1; i <= rows; i++) if (order[i] in children) { n = split(children[order[i]], list, " "); for (j = 1; j <= n; j++) if (list[j] in line) parents[list[j]]++ }
			for (i = 1; i <= rows; i++) if (type[order[i]] == "index" && !(order[i] in children)) emit(order[i])
			for (i = 1; i <= rows; i++) if (type[order[i]] == "image" && !(order[i] in parents) && !(order[i] in printed)) emit(order[i])
			for (i = 1; i <= rows; i++) if (!(order[i] in printed)) emit(order[i])
		}' "$WORK_DIR/children.tsv" "$WORK_DIR/dockerhub-images.tsv" "$WORK_DIR/sorted.tsv" > "$WORK_DIR/rows.tsv"
	write_table "$WORK_DIR/rows.tsv" "$TO_DELETE_FILE"
	ok "$(awk -F '\t' 'END { print ($9 + 0 ? $9 " to delete (" $3 "), they free " $7 : "nothing to delete") }' "$TO_DELETE_FILE")"
	info "$TO_DELETE_FILE lists every manifest to delete in the order it deletes them, with its type, platform, tags, date and size"
}

delete_manifests() {
	local on_ghcr on_docker_hub type tags version digest rest name deleted ghcr docker_hub status gone=0
	if [ -z "$LIMIT" ]; then
		info "a dry run deletes nothing, --delete with a count deletes that many manifests from the top of the list"
		return
	fi
	heading "Deleting"
	head -n 1 "$TO_DELETE_FILE" > "$DELETED_FILE"
	: > "$WORK_DIR/deleted.tsv"
	while IFS='|' read -r on_ghcr on_docker_hub type tags version digest rest; do
		[[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || die "the list names '$digest', expected a sha256 digest"
		name="$digest${tags:+ ($tags)}"
		deleted="" ghcr=no docker_hub=no status=""
		if [ "$on_ghcr" = yes ]; then
			delete_ghcr_version "$version" "$type $name" || continue
			deleted=ghcr.io ghcr=yes
		fi
		if [ "$on_docker_hub" = yes ]; then
			status="$(delete_dockerhub_manifest "$digest")"
			if [[ "$status" == 2?? ]]; then
				deleted="${deleted:+$deleted and }docker.io" docker_hub=yes
			elif [ "$status" = 404 ]; then
				info "docker.io had already deleted the $type $BOLD$name$RESET together with its index"
				gone=$((gone + 1))
			fi
		fi
		if [ -n "$deleted" ]; then
			ok "deleted the $type $BOLD$name$RESET from $deleted"
			printf '%s\t%s\t%s\n' "$ghcr" "$docker_hub" "$rest" | tee -a "$WORK_DIR/deleted.tsv" >> "$DELETED_FILE"
		fi
		case "$status" in
			"" | 2?? | 404) ;;
			403 | 5??) warn "docker.io keeps the $type $name for now, it answered $status, the next run tries again" ;;
			*) die "could not delete the $type $name from docker.io, it answered $status" ;;
		esac
	done < <(head -n "$LIMIT" "$WORK_DIR/rows.tsv" | awk -F '\t' -v OFS='|' '{ rest = $3; for (i = 4; i <= NF; i++) rest = rest "\t" $i; print $1, $2, $3, $5, $8, $9, rest }')
	write_table "$WORK_DIR/deleted.tsv" "$DELETED_FILE"
	ok "$(awk -F '\t' 'END { print ($9 + 0 ? "deleted " $9 " (" $3 "), they free " $7 : "deleted nothing") }' "$DELETED_FILE")"
	[ "$gone" = 0 ] || info "docker.io had already deleted $gone of the listed manifests together with their index, it deletes the children of an index that nothing else references"
	info "$DELETED_FILE lists what this run deleted and where, ghcr.io can restore them for 30 days, docker.io deletes them permanently"
}

verify_kept_manifests() {
	local registry missing
	[ -n "$LIMIT" ] || return 0
	for registry in ghcr.io docker.io; do
		missing="$(request_manifests "$registry" "$WORK_DIR/kept-$registry.txt" --head -o /dev/null -w '%{http_code}\t{}\n' | awk '$1 != 200 { print $2 }')" \
			|| die "could not check the kept manifests on $registry"
		[ -z "$missing" ] || die "$(count <<< "$missing") kept manifests are gone from $registry: $(head -n 3 <<< "$missing" | paste -sd ' ' -)"
	done
	ok "every kept manifest is still on ghcr.io and docker.io"
}

list_ghcr_versions() {
	local query="$1"
	shift
	gh api "$@" "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions?per_page=100&$query" \
		--jq '.[] | [.name, (.id | tostring), (.metadata.container.tags | join(",")), .created_at] | @tsv' 2> /dev/null \
		|| die "gh could not list the versions of ghcr.io/$IMAGE, its token needs read:packages and delete:packages: gh auth refresh -s read:packages,delete:packages"
}

request_manifests() {
	local registry="$1" list="$2" chunk token
	shift 2
	[ -s "$list" ] || return 0
	split -l 1000 "$list" "$WORK_DIR/chunk-"
	for chunk in "$WORK_DIR"/chunk-*; do
		token="$(registry_token "$registry" pull)"
		xargs -P 16 -I {} curl -sS --retry 3 -H "Authorization: Bearer $token" -H "Accept: $MANIFEST_TYPES" "$@" "https://${registry/docker.io/registry-1.docker.io}/v2/$IMAGE/manifests/{}" < "$chunk" || return 1
		rm "$chunk"
	done
}

manifest_fields() {
	find "$WORK_DIR/manifests" -type f -exec jq -r 'def manifest: input_filename | sub(".*/"; ""); '"$1"' | @tsv' {} +
}

manifests_of_kept_tags() {
	awk -F '\t' 'function keep(digest, list, n, i) { if (digest in kept) return; kept[digest]; print digest; n = split(children[digest], list, " "); for (i = 1; i <= n; i++) keep(list[i]) }
		FILENAME == ARGV[1] || FILENAME == ARGV[2] { children[$1] = children[$1] " " $2; next }
		FILENAME == ARGV[3] { tag[$1]; next }
		{ n = split($3, tags, ","); for (i = 1; i <= n; i++) if (tags[i] in tag) keep($1) }' "$WORK_DIR/children.tsv" "$WORK_DIR/dockerhub-images.tsv" "$WORK_DIR/kept-tags.txt" "$1"
}

write_table() {
	awk -F '\t' -v OFS='\t' 'function human(bytes, unit) { for (unit = 1; bytes >= 1000 && unit < 5; unit++) bytes /= 1000; return sprintf(unit == 1 ? "%d %s" : "%.1f %s", bytes, units[unit]) }
		function amount(count, one, many) { return (count + 0) " " (count == 1 ? one : many) }
		BEGIN { split("B kB MB GB TB", units, " "); print "ghcr", "docker_hub", "type", "platform", "tags", "created", "size", "ghcr_id", "digest" }
		FILENAME == ARGV[1] { removed[$9]; types[$3]++; manifests++; if ($7 != "") $7 = human($7); print; next }
		FILENAME == ARGV[2] { bytes[$1] = $2; before += $2; if (!($1 in removed)) after += $2; next }
		FILENAME == ARGV[3] { if ($1 in bytes) on_hub[$1]; size = ($1 in bytes) ? bytes[$1] : $5; hub_before += size; if (!($1 in removed)) hub_after += size; next }
		{ blob[$2] = $3; if (!($1 in removed)) used[$2]; if ($1 in on_hub) { hub_blob[$2] = $3; if (!($1 in removed)) hub_used[$2] } }
		END {
			for (digest in blob) { before += blob[digest]; if (digest in used) after += blob[digest] }
			for (digest in hub_blob) { hub_before += hub_blob[digest]; if (digest in hub_used) hub_after += hub_blob[digest] }
			print "total", "", amount(types["index"], "index", "indexes") ", " amount(types["image"], "image", "images") ", " amount(types["attestation"], "attestation", "attestations"),
				"", "", "", human(before - after) " on ghcr.io (" human(before) " → " human(after) ") and ~" human(hub_before - hub_after) " on docker.io (~" human(hub_before) " → ~" human(hub_after) ")",
				"", amount(manifests, "manifest", "manifests")
		}' "$1" "$WORK_DIR/bytes.tsv" "$WORK_DIR/dockerhub-manifests.tsv" "$WORK_DIR/blobs.tsv" > "$2"
}

delete_ghcr_version() {
	local error
	[[ "$1" =~ ^[0-9]+$ ]] || die "the version id of the $2 is '$1', expected a number from the GitHub API"
	until error="$(gh api -X DELETE "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions/$1" 2>&1 > /dev/null)"; do
		case "$error" in
			*"rate limit"*) sleep 60 ;;
			*downloads*)
				warn "ghcr.io and docker.io keep the $2, ghcr.io deletes no version with more than 5,000 downloads"
				return 1
				;;
			*) die "could not delete the $2 from ghcr.io: $error" ;;
		esac
	done
	sleep 1
}

# docker.io answers 202 to the deletion of any digest, so a HEAD tells first whether the manifest is still there
delete_dockerhub_manifest() {
	local token status
	token="$(registry_token docker.io pull,push,delete)"
	status="$(curl -sS --retry 3 -o /dev/null -w '%{http_code}' --head -H "Authorization: Bearer $token" -H "Accept: $MANIFEST_TYPES" "https://registry-1.docker.io/v2/$IMAGE/manifests/$1")"
	[ "$status" = 200 ] || { echo "$status"; return; }
	while status="$(curl -sS --retry 3 -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer $token" "https://registry-1.docker.io/v2/$IMAGE/manifests/$1")" && [ "$status" = 429 ]; do
		sleep 60
		token="$(registry_token docker.io pull,push,delete)"
	done
	echo "$status"
}

registry_token() {
	case "$1" in
		ghcr.io) basic_auth "x:$GHCR_TOKEN" | curl -sSf --retry 3 -K - "https://ghcr.io/token?scope=repository:$IMAGE:$2" ;;
		docker.io) basic_auth "$DOCKERHUB_USERNAME:$DOCKERHUB_TOKEN" | curl -sSf --retry 3 -K - "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$IMAGE:$2" ;;
	esac | jq -r .token
}

basic_auth() {
	printf 'header = "Authorization: Basic %s"\n' "$(printf '%s' "$1" | jq -Rrj @base64)"
}

hub_token() {
	jq -n --arg identifier "$DOCKERHUB_USERNAME" --arg secret "$DOCKERHUB_TOKEN" '{identifier: $identifier, secret: $secret}' \
		| curl -sSf --retry 3 -X POST -H 'Content-Type: application/json' --data-binary @- https://hub.docker.com/v2/auth/token | jq -er .access_token
}

count() { awk 'END { print NR }'; }

main
