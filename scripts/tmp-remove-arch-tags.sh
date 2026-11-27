#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
LIMIT="${2:-}"
IMAGE=coollabsio/coolify
VARIANTS=""
ARCH_TAG_PATTERN='-(amd64|aarch64|arm64)$'
# The rules from here to MIN_AGE_DAYS are the same ones as in clean-manifests.sh
KEEP_TAG_PATTERN='^(latest|preview|main|nightly|v?[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?)$'
KEEP_TAG_EXCEPTIONS=v3,4.0.0-beta.420.1,4.0.0-beta.420.2,4.0.0-beta.420.3,4.0.0-beta.420.4,4.0.0-beta.420.5,4.0.0-beta.420.6,4.0.0-beta.420.7,4.0.0-beta.420.8,4.0.0-beta.420.9,4.0.0-beta.420.10
# Temporary: the Docker Hub API still lists this deleted tag, its images without a push date. Remove it once the API no longer lists the tag
SKIP_DOCKERHUB_TAG=sha-710f236
KEEP_NIGHTLY_DAYS=14
MIN_AGE_DAYS=1
DOCKERHUB_USERNAME="${DOCKERHUB_USERNAME:-}"
DOCKERHUB_TOKEN="${DOCKERHUB_TOKEN:-}"
GHCR_TOKEN=""
MANIFEST_TYPES='application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'
EMPTY_BLOB=sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a
LIST_FILE=scripts/arch-tags-to-remove.tsv
REMOVED_FILE=scripts/removed-arch-tags.tsv
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
	[[ "$OPTION" =~ ^(--remove [1-9][0-9]*)?$ ]] || die "'$OPTION' is not an option, expected --remove with a count or none"
	[ -n "$DOCKERHUB_USERNAME" ] && [ -n "$DOCKERHUB_TOKEN" ] || die "DOCKERHUB_USERNAME and DOCKERHUB_TOKEN must be set to read docker.io/$IMAGE"
	GHCR_TOKEN="$(gh auth token)" || die "gh is not signed in with a token that has read:packages, write:packages and delete:packages: gh auth refresh -s read:packages,write:packages,delete:packages"
	heading "Reading ghcr.io/$IMAGE and docker.io/$IMAGE"
	read_ghcr_versions
	read_dockerhub_tags
	select_kept_tags
	read_manifests
	select_kept_manifests
	heading "Selecting the arch tags"
	write_list
	remove_arch_tags
	verify_removed_tags
	verify_kept_manifests
}

read_ghcr_versions() {
	local page versions
	for ((page = 1; ; page++)); do
		versions="$(list_ghcr_versions "state=active&page=$page")"
		[ -n "$versions" ] || break
		printf '%s\n' "$versions"
	done > "$WORK_DIR/ghcr-active.tsv"
	cut -f 1 "$WORK_DIR/ghcr-active.tsv" > "$WORK_DIR/on-ghcr.txt"
	awk -F '\t' -v OFS='\t' '$3 != "" { n = split($3, tags, ","); for (i = 1; i <= n; i++) print $1, tags[i], $4 }' "$WORK_DIR/ghcr-active.tsv" > "$WORK_DIR/ghcr.io.tsv"
	check_tags ghcr.io
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
		page="$(jq --arg tag "$SKIP_DOCKERHUB_TAG" 'del(.results[] | select(.name == $tag))' <<< "$page")"
		jq -r '.results[] | [.name, (.digest // ""), (.media_type // ""), (.tag_last_pushed // .last_updated // ""), (.full_size // 0)] | @tsv' <<< "$page" >> "$WORK_DIR/hub-tags.tsv"
		jq -r '.results[] | .name as $tag | .images[]? | select(.digest) | [$tag, .digest, ([.os, .architecture] | join("/")), (.last_pushed // ""), (.size // 0)] | @tsv' <<< "$page" >> "$WORK_DIR/hub-images.tsv"
		url="$(jq -r '.next // empty' <<< "$page")"
	done
	awk -F '\t' '$2 == "" || $3 == "" { print $1 }' "$WORK_DIR/hub-tags.tsv" > "$WORK_DIR/old-tags.txt"
	request_manifests docker.io "$WORK_DIR/old-tags.txt" -f --head -o /dev/null -w '{}\t%header{docker-content-digest}\t%header{content-type}\n' > "$WORK_DIR/old-tags.tsv" \
		|| die "could not read the digests of the old tags of docker.io/$IMAGE, the Docker Hub API lists them without one"
	awk -F '\t' -v OFS='\t' -v images="$WORK_DIR/dockerhub-images.tsv" '
		FILENAME == ARGV[1] { old_digest[$1] = $2; old_type[$1] = $3; next }
		FILENAME == ARGV[2] { if ($2 == "") $2 = old_digest[$1]; if ($3 == "") $3 = old_type[$1]; root[$1] = $2; print $2, $3, $1, $4; next }
		$2 != root[$1] && !((root[$1], $2) in pair) { pair[root[$1], $2]; print root[$1], $2 > images }' \
		"$WORK_DIR/old-tags.tsv" "$WORK_DIR/hub-tags.tsv" "$WORK_DIR/hub-images.tsv" > "$WORK_DIR/dockerhub.tsv"
	awk -F '\t' -v OFS='\t' '{ print $1, $3, $4 }' "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/docker.io.tsv"
	check_tags docker.io
}

select_kept_tags() {
	local cutoff
	{ cut -f 3 "$WORK_DIR/ghcr-active.tsv" | tr ',' '\n' && cut -f 3 "$WORK_DIR/dockerhub.tsv"; } | sed '/^$/d' | sort -u > "$WORK_DIR/tags.txt"
	git ls-remote --tags "https://github.com/$IMAGE.git" | sed -n 's|.*refs/tags/||p' | sed 's/\^{}$//; p; s/^v//' > "$WORK_DIR/keep.txt" \
		|| die "could not list the git tags of github.com/$IMAGE"
	gh pr list --repo "$IMAGE" --state open --limit 1000 --json number --jq '.[] | "pr-\(.number)"' > "$WORK_DIR/pull-requests.txt" \
		|| die "gh could not list the open pull requests of $IMAGE"
	awk -v variants="$VARIANTS" '{ print; n = split(variants, variant, ","); for (i = 1; i <= n; i++) print $0 "-" variant[i] }' "$WORK_DIR/pull-requests.txt" >> "$WORK_DIR/keep.txt"
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
	request_manifests ghcr.io "$WORK_DIR/on-ghcr.txt" -f -o "$WORK_DIR/manifests/{}" || die "could not read the manifests of ghcr.io/$IMAGE"
	awk -F '\t' 'FILENAME == ARGV[1] { kept[$1]; next } FILENAME == ARGV[2] { ghcr[$1]; next } ($3 in kept) && !($1 in ghcr) && $2 ~ /oci\.image\.index/ && !seen[$1]++ { print $1 }' \
		"$WORK_DIR/kept-tags.txt" "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/dockerhub-indexes.txt"
	request_manifests docker.io "$WORK_DIR/dockerhub-indexes.txt" -f -o "$WORK_DIR/manifests/{}" || die "could not read the kept indexes that only docker.io has"
	find "$WORK_DIR/manifests" -type f -exec jq -r '.manifests[]? | [(input_filename | sub(".*/"; "")), .digest] | @tsv' {} + > "$WORK_DIR/children.tsv"
	ok "read the $(count < "$WORK_DIR/on-ghcr.txt") manifests on ghcr.io and the $(count < "$WORK_DIR/dockerhub-indexes.txt") kept indexes that only docker.io has, each with a GET that counts as a pull"
}

select_kept_manifests() {
	manifests_of_kept_tags "$WORK_DIR/ghcr-active.tsv" > "$WORK_DIR/kept-ghcr.io.txt"
	manifests_of_kept_tags "$WORK_DIR/dockerhub.tsv" > "$WORK_DIR/kept-docker.io.txt"
	sort -u "$WORK_DIR/kept-ghcr.io.txt" "$WORK_DIR/kept-docker.io.txt" > "$WORK_DIR/kept.txt"
	awk 'FILENAME == ARGV[1] { ghcr[$1]; next } !($1 in ghcr) { exit 1 }' "$WORK_DIR/on-ghcr.txt" "$WORK_DIR/kept-ghcr.io.txt" \
		|| die "ghcr.io lists no version for some kept manifests, expected one for each"
	ok "clean-manifests.sh keeps $(count < "$WORK_DIR/kept-ghcr.io.txt") manifests on ghcr.io and $(count < "$WORK_DIR/kept-docker.io.txt") on docker.io"
}

write_list() {
	local registry cutoff
	cutoff="$(jq -nr --argjson days "$MIN_AGE_DAYS" 'now - $days * 86400 | strftime("%Y-%m-%dT%H:%M:%SZ")')"
	for registry in ghcr.io docker.io; do
		awk -F '\t' -v OFS='\t' -v registry="$registry" -v arch="$ARCH_TAG_PATTERN" -v cutoff="$cutoff" 'FILENAME == ARGV[1] { kept_tag[$1]; next } FILENAME == ARGV[2] { kept[$1]; next }
			$2 ~ arch && !($2 in kept_tag) { print registry, $2, $1, (!($1 in kept) ? "unused" : ($3 >= cutoff ? "recent" : "remove")) }' "$WORK_DIR/kept-tags.txt" "$WORK_DIR/kept.txt" "$WORK_DIR/$registry.tsv"
	done | sort -u -t $'\t' -k2,2 -k1,1 > "$WORK_DIR/arch-tags.tsv"
	{
		printf 'registry\ttag\tdigest\n'
		awk -F '\t' -v OFS='\t' '$4 == "remove" { print $1, $2, $3 }' "$WORK_DIR/arch-tags.tsv"
	} > "$LIST_FILE"
	ok "$(arch_tags remove) arch tags to remove ($(arch_tags remove ghcr.io) on ghcr.io, $(arch_tags remove docker.io) on docker.io), clean-manifests.sh keeps their image"
	ok "keeping the $(arch_tags recent) arch tags pushed since $cutoff ($(arch_tags recent ghcr.io) on ghcr.io, $(arch_tags recent docker.io) on docker.io), a running build may still need them"
	[ "$(arch_tags unused)" = 0 ] || ok "not listing $(arch_tags unused) arch tags ($(arch_tags unused ghcr.io) on ghcr.io, $(arch_tags unused docker.io) on docker.io), no kept tag uses their image, so clean-manifests.sh deletes it and the tags go with it"
	info "$LIST_FILE lists every arch tag to remove in the order it removes them"
}

remove_arch_tags() {
	local placeholder registry tag digest found status moved failure
	if [ -z "$LIMIT" ]; then
		info "a dry run changes nothing, --remove with a count removes that many from the top of the list"
		return
	fi
	heading "Removing"
	head -n 1 "$LIST_FILE" > "$REMOVED_FILE"
	awk -v limit="$LIMIT" 'NR > 1 && NR <= limit + 1' "$LIST_FILE" > "$WORK_DIR/remove.tsv"
	placeholder="$(write_placeholder)"
	[[ "$placeholder" =~ ^sha256:[0-9a-f]{64}$ ]] || die "the digest of the placeholder is '$placeholder', expected a sha256 digest"
	for registry in ghcr.io docker.io; do
		awk -F '\t' -v registry="$registry" '$1 == registry' "$WORK_DIR/remove.tsv" > "$WORK_DIR/remove-$registry.tsv"
		[ -s "$WORK_DIR/remove-$registry.tsv" ] || continue
		upload_empty_blob "$registry" || die "could not upload the empty blob of the placeholder to $registry/$IMAGE, the token needs to be able to write packages"
		moved=0 failure=""
		while IFS=$'\t' read -r _ tag digest; do
			[[ "$tag" =~ ^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$ ]] && [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || {
				failure="the list names the tag '$tag' with '$digest' on $registry, expected a tag name and a sha256 digest"
				break
			}
			found="$(head_tag "$registry" "$tag")"
			case "$found" in
				"200 $digest") ;;
				404*)
					info "$registry had already deleted $BOLD$tag$RESET"
					continue
					;;
				200*)
					warn "$registry keeps $tag for now, it points at another manifest since it was listed, the next run lists it again"
					continue
					;;
				*)
					failure="could not read $tag on $registry, it answered ${found%% *}"
					break
					;;
			esac
			status="$(move_tag "$registry" "$tag")"
			[[ "$status" == 2?? ]] || {
				failure="could not move $tag on $registry to the placeholder, it answered $status"
				break
			}
			moved=$((moved + 1))
			printf '%s\t%s\t%s\n' "$registry" "$tag" "$digest" >> "$REMOVED_FILE"
			ok "moved $BOLD$tag$RESET on $registry off its image to the placeholder"
		done < "$WORK_DIR/remove-$registry.tsv"
		if [ "$moved" -gt 0 ]; then
			delete_placeholder "$registry" "$placeholder"
			ok "deleted the placeholder $placeholder from $registry with the $moved tags it held"
		fi
		[ -z "$failure" ] || die "$failure"
	done
	ok "removed $(tail -n +2 "$REMOVED_FILE" | count) arch tags, every image they pointed at stays"
	info "$REMOVED_FILE lists the removed tags with the digest each pointed at, to tag it again if needed"
}

verify_removed_tags() {
	local registry attempt left
	[ -n "$LIMIT" ] || return 0
	for registry in ghcr.io docker.io; do
		awk -F '\t' -v registry="$registry" '$1 == registry { print $2 }' "$REMOVED_FILE" > "$WORK_DIR/removed-$registry.txt"
		for attempt in 1 2 3 4 5; do
			left="$(request_manifests "$registry" "$WORK_DIR/removed-$registry.txt" --head -o /dev/null -w '%{http_code}\t{}\n' | awk '$1 != 404 { print $2 }')" \
				|| die "could not check the removed tags on $registry"
			[ -n "$left" ] || break
			sleep $((attempt * 5))
		done
		[ -z "$left" ] || die "$(count <<< "$left") removed tags are still on $registry: $(head -n 3 <<< "$left" | paste -sd ' ' -)"
	done
	ok "the removed tags are gone from ghcr.io and docker.io"
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

check_tags() {
	awk -F '\t' '$2 == "latest" { found = 1 } END { exit !found }' "$WORK_DIR/$1.tsv" || die "$1/$IMAGE lists no latest tag, expected every tag"
	awk -F '\t' '$1 !~ /^sha256:[0-9a-f]+$/ || length($1) != 71 || $3 == "" { exit 1 }' "$WORK_DIR/$1.tsv" || die "$1/$IMAGE lists tags without a digest or push date, expected both for each"
	ok "$1/$IMAGE has $(count < "$WORK_DIR/$1.tsv") tags, $(awk -F '\t' -v arch="$ARCH_TAG_PATTERN" '$2 ~ arch' "$WORK_DIR/$1.tsv" | count) of them arch tags"
}

manifests_of_kept_tags() {
	awk -F '\t' 'function keep(digest, list, n, i) { if (digest in kept) return; kept[digest]; print digest; n = split(children[digest], list, " "); for (i = 1; i <= n; i++) keep(list[i]) }
		FILENAME == ARGV[1] || FILENAME == ARGV[2] { children[$1] = children[$1] " " $2; next }
		FILENAME == ARGV[3] { tag[$1]; next }
		{ n = split($3, tags, ","); for (i = 1; i <= n; i++) if (tags[i] in tag) keep($1) }' "$WORK_DIR/children.tsv" "$WORK_DIR/dockerhub-images.tsv" "$WORK_DIR/kept-tags.txt" "$1"
}

arch_tags() { awk -F '\t' -v state="$1" -v registry="${2:-}" '$4 == state && (registry == "" || $1 == registry)' "$WORK_DIR/arch-tags.tsv" | count; }

write_placeholder() {
	jq -cnj --arg created "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg empty "$EMPTY_BLOB" '{schemaVersion: 2, mediaType: "application/vnd.oci.image.manifest.v1+json",
		artifactType: "application/vnd.oci.empty.v1+json", config: {mediaType: "application/vnd.oci.empty.v1+json", digest: $empty, size: 2},
		layers: [{mediaType: "application/vnd.oci.empty.v1+json", digest: $empty, size: 2}],
		annotations: {"org.opencontainers.image.created": $created, "org.opencontainers.image.description": "Placeholder that takes arch tags off their images, deleted right after"}}' \
		> "$WORK_DIR/placeholder.json"
	echo "sha256:$(shasum -a 256 "$WORK_DIR/placeholder.json" | cut -d ' ' -f 1)"
}

upload_empty_blob() {
	local host token location
	host="$(registry_host "$1")"
	token="$(registry_token "$1" pull,push)"
	curl -sSf --retry 3 --head -o /dev/null -H "Authorization: Bearer $token" "https://$host/v2/$IMAGE/blobs/$EMPTY_BLOB" 2> /dev/null && return 0
	location="$(curl -sSf -X POST -o /dev/null -w '%header{location}' -H "Authorization: Bearer $token" "https://$host/v2/$IMAGE/blobs/uploads/")" || return 1
	[[ "$location" == http* ]] || location="https://$host$location"
	[[ "$location" == "https://$host/"* ]] || die "$1 answered the blob upload with ${location%%\?*}, expected a URL on https://$host for the push token"
	case "$location" in
		*\?*) location="$location&digest=$EMPTY_BLOB" ;;
		*) location="$location?digest=$EMPTY_BLOB" ;;
	esac
	curl -sSf -X PUT -o /dev/null -H "Authorization: Bearer $token" -H 'Content-Type: application/octet-stream' --data-binary '{}' "$location"
}

head_tag() {
	curl -sS --retry 3 --head -o /dev/null -w '%{http_code} %header{docker-content-digest}' -H "Authorization: Bearer $(registry_token "$1" pull)" -H "Accept: $MANIFEST_TYPES" \
		"https://$(registry_host "$1")/v2/$IMAGE/manifests/$2"
}

move_tag() {
	local status
	while status="$(curl -sS --retry 3 -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $(registry_token "$1" pull,push)" -H 'Content-Type: application/vnd.oci.image.manifest.v1+json' \
		--data-binary "@$WORK_DIR/placeholder.json" "https://$(registry_host "$1")/v2/$IMAGE/manifests/$2")" && [ "$status" = 429 ]; do
		sleep 60
	done
	echo "$status"
}

delete_placeholder() {
	local id="" attempt error status
	case "$1" in
		ghcr.io)
			for attempt in 1 2 3 4 5; do
				id="$(list_ghcr_versions "state=active&page=1" | awk -F '\t' -v digest="$2" '$1 == digest { print $2 }')"
				[ -z "$id" ] || break
				sleep $((attempt * 5))
			done
			[[ "$id" =~ ^[0-9]+$ ]] || die "found no version for the placeholder $2 among the newest 100 on ghcr.io, it holds the moved tags until it is deleted in the package settings"
			until error="$(gh api -X DELETE "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions/$id" 2>&1 > /dev/null)"; do
				case "$error" in
					*"rate limit"*) sleep 60 ;;
					*) die "could not delete the placeholder $2 (version $id) from ghcr.io, it holds the moved tags until it is deleted: $error" ;;
				esac
			done
			;;
		docker.io)
			while status="$(curl -sS --retry 3 -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer $(registry_token docker.io pull,push,delete)" \
				"https://registry-1.docker.io/v2/$IMAGE/manifests/$2")" && [ "$status" = 429 ]; do
				sleep 60
			done
			[[ "$status" == 2?? ]] || die "could not delete the placeholder $2 from docker.io, it answered $status, it holds the moved tags until it is deleted"
			;;
	esac
}

list_ghcr_versions() {
	local attempt versions
	for attempt in 1 2 3 4 5; do
		if versions="$(gh api "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions?per_page=100&$1" \
			--jq '.[] | [.name, (.id | tostring), (.metadata.container.tags | join(",")), .created_at] | @tsv')"; then
			[ -z "$versions" ] || printf '%s\n' "$versions"
			return
		fi
		sleep $((attempt * 5))
	done
	die "gh could not list the versions of ghcr.io/$IMAGE"
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

registry_host() { echo "${1/docker.io/registry-1.docker.io}"; }

count() { awk 'END { print NR }'; }

main
