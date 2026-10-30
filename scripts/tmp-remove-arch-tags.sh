#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
LIMIT="${2:-}"
IMAGE=coollabsio/coolify
ARCH_TAG_PATTERN='-(amd64|aarch64|arm64)$'
GHCR_TOKEN="${GHCR_TOKEN:-}"
DOCKERHUB_USERNAME="${DOCKERHUB_USERNAME:-}"
DOCKERHUB_TOKEN="${DOCKERHUB_TOKEN:-}"
EMPTY_BLOB=sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a
LIST_FILE=scripts/arch-tags-to-remove.tsv
REMOVED_FILE=scripts/removed-arch-tags.tsv
WORK_DIR="$(mktemp -d)"

BOLD=$'\033[1m' BLUE=$'\033[34m' GREEN=$'\033[32m' RED=$'\033[31m' RESET=$'\033[0m'

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
info() { printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

cd "$(dirname "$0")/.."
trap 'rm -rf "$WORK_DIR"' EXIT

main() {
	[[ "$OPTION" =~ ^(--remove [1-9][0-9]*)?$ ]] || die "'$OPTION' is not an option, expected --remove with a count or none"
	[ -n "$DOCKERHUB_USERNAME" ] && [ -n "$DOCKERHUB_TOKEN" ] || die "DOCKERHUB_USERNAME and DOCKERHUB_TOKEN must be set to read docker.io/$IMAGE from the Docker Hub API"
	heading "Reading ghcr.io/$IMAGE and docker.io/$IMAGE"
	read_registry_tags
	read_indexes
	heading "Selecting the arch tags"
	write_list
	remove_arch_tags
	verify_tags
}

read_registry_tags() {
	local registry
	for registry in ghcr.io docker.io; do
		read_tags "$registry" > "$WORK_DIR/$registry.tsv" || die "could not read the tags of $registry/$IMAGE"
		ok "$registry/$IMAGE has $(count < "$WORK_DIR/$registry.tsv") tags, $(awk -F '\t' -v arch="$ARCH_TAG_PATTERN" '$3 ~ arch' "$WORK_DIR/$registry.tsv" | count) of them arch tags"
	done
}

read_indexes() {
	local number page hub
	mkdir "$WORK_DIR/manifests"
	awk -F '\t' -v arch="$ARCH_TAG_PATTERN" '$3 !~ arch && $2 ~ /index|list/ && !seen[$1]++ { print $1 }' "$WORK_DIR/ghcr.io.tsv" > "$WORK_DIR/ghcr-indexes.txt"
	request_manifests ghcr.io "$WORK_DIR/ghcr-indexes.txt" -f -o "$WORK_DIR/manifests/{}" || die "could not read the indexes of ghcr.io/$IMAGE"
	find "$WORK_DIR/manifests" -type f -exec jq -r '.manifests[] | [(input_filename | sub(".*/"; "")), .digest] | @tsv' {} + > "$WORK_DIR/ghcr.io-children.tsv"
	hub="$(hub_token)" || die "could not sign in to the Docker Hub API as $DOCKERHUB_USERNAME"
	for ((number = 1; ; number++)); do
		page="$(curl -sSf --retry 3 -H "Authorization: Bearer $hub" "https://hub.docker.com/v2/namespaces/${IMAGE%%/*}/repositories/${IMAGE#*/}/tags?page_size=100&page=$number")" \
			|| die "could not list the tags of docker.io/$IMAGE on the Docker Hub API"
		jq -r --arg arch "$ARCH_TAG_PATTERN" '.results[] | select((.name | test($arch) | not) and .digest != null) | .digest as $index | .images[] | select(.digest != null) | [$index, .digest] | @tsv' <<< "$page"
		jq -e '.next' <<< "$page" > /dev/null || break
	done | sort -u > "$WORK_DIR/docker.io-children.tsv"
	ok "read the $(count < "$WORK_DIR/ghcr-indexes.txt") multi-platform manifests on ghcr.io and the docker.io tags from the Docker Hub API"
}

write_list() {
	local registry
	for registry in ghcr.io docker.io; do
		awk -F '\t' -v OFS='\t' -v registry="$registry" -v arch="$ARCH_TAG_PATTERN" '
			FILENAME == ARGV[1] { if ($3 ~ arch) digest[$3] = $1; else tags[$1] = tags[$1] (tags[$1] == "" ? "" : ",") $3; next }
			$1 in tags { listed[$2] = listed[$2] (listed[$2] == "" ? "" : ",") tags[$1] }
			END { for (tag in digest) print registry, tag, digest[tag], ((digest[tag] in tags) ? tags[digest[tag]] : listed[digest[tag]]) }' \
			"$WORK_DIR/$registry.tsv" "$WORK_DIR/$registry-children.tsv"
	done | sort -t $'\t' -k2,2 -k1,1 > "$WORK_DIR/arch-tags.tsv"
	{
		printf 'registry\ttag\tdigest\tkept by\n'
		awk -F '\t' '$4 != ""' "$WORK_DIR/arch-tags.tsv"
	} > "$LIST_FILE"
	ok "$(awk -F '\t' '$4 != ""' "$WORK_DIR/arch-tags.tsv" | count) arch tags to remove ($(awk -F '\t' '$1 == "ghcr.io" && $4 != ""' "$WORK_DIR/arch-tags.tsv" | count) on ghcr.io, $(awk -F '\t' '$1 == "docker.io" && $4 != ""' "$WORK_DIR/arch-tags.tsv" | count) on docker.io), another tag still reaches their image"
	ok "$(awk -F '\t' '$4 == ""' "$WORK_DIR/arch-tags.tsv" | count) arch tags stay as no other tag reaches their image, clean-manifests.sh deletes those images"
	info "$LIST_FILE lists every arch tag to remove with the tags that still reach its image"
}

remove_arch_tags() {
	local placeholder registry tag
	if [ -z "$LIMIT" ]; then
		info "a dry run changes nothing, --remove with a count removes that many from the top of the list"
		return
	fi
	[ -n "$GHCR_TOKEN" ] || die "GHCR_TOKEN must be set to a token that can write packages to move tags on ghcr.io"
	gh api "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions?per_page=1" > /dev/null 2>&1 \
		|| die "gh could not list the versions of ghcr.io/$IMAGE, its token needs read:packages and delete:packages to delete the placeholder"
	heading "Removing"
	head -n 1 "$LIST_FILE" > "$REMOVED_FILE"
	tail -n +2 "$LIST_FILE" | head -n "$LIMIT" > "$WORK_DIR/remove.tsv"
	placeholder="$(write_placeholder)"
	for registry in ghcr.io docker.io; do
		awk -F '\t' -v registry="$registry" '$1 == registry { print $2 }' "$WORK_DIR/remove.tsv" > "$WORK_DIR/remove-$registry.txt"
		[ -s "$WORK_DIR/remove-$registry.txt" ] || continue
		upload_empty_blob "$registry" || die "could not upload the empty blob of the placeholder to $registry/$IMAGE"
		while read -r tag; do
			curl -sSf --retry 3 -X PUT -o /dev/null -H "Authorization: Bearer $(registry_token "$registry" pull,push)" -H 'Content-Type: application/vnd.oci.image.manifest.v1+json' \
				--data-binary "@$WORK_DIR/placeholder.json" "https://$(registry_host "$registry")/v2/$IMAGE/manifests/$tag" || die "could not move $tag on $registry to the placeholder"
			ok "moved $BOLD$tag$RESET on $registry off its image to the placeholder"
		done < "$WORK_DIR/remove-$registry.txt"
		delete_placeholder "$registry" "$placeholder"
		ok "deleted the placeholder $placeholder from $registry with the $(count < "$WORK_DIR/remove-$registry.txt") tags it held"
		awk -F '\t' -v registry="$registry" '$1 == registry' "$WORK_DIR/remove.tsv" >> "$REMOVED_FILE"
	done
	ok "removed $(tail -n +2 "$REMOVED_FILE" | count) arch tags, every image they pointed at stays"
	info "$REMOVED_FILE lists the removed tags with the digest each pointed at, to tag it again if needed"
}

verify_tags() {
	local registry changed missing
	[ -n "$LIMIT" ] || return 0
	for registry in ghcr.io docker.io; do
		read_tags "$registry" > "$WORK_DIR/$registry-after.tsv" || die "could not read the tags of $registry/$IMAGE again"
		changed="$(awk -F '\t' -v registry="$registry" 'FILENAME == ARGV[1] { if ($1 == registry) removed[$2]; next }
			FILENAME == ARGV[2] { after[$3] = $1; next }
			{ if (($3 in removed) ? ($3 in after) : !(($3 in after) && after[$3] == $1)) print $3 }' "$WORK_DIR/remove.tsv" "$WORK_DIR/$registry-after.tsv" "$WORK_DIR/$registry.tsv")"
		[ -z "$changed" ] || die "$(count <<< "$changed") tags on $registry changed, expected the removed ones gone and every other one on its digest: $(head -n 3 <<< "$changed" | paste -sd ' ' -)"
		awk -F '\t' -v registry="$registry" '$1 == registry { print $3 }' "$WORK_DIR/remove.tsv" | sort -u > "$WORK_DIR/images-$registry.txt"
		missing="$(request_manifests "$registry" "$WORK_DIR/images-$registry.txt" --head -o /dev/null -w '%{http_code}\t{}\n' | awk '$1 != 200 { print $2 }')" \
			|| die "could not check the images of the removed tags on $registry"
		[ -z "$missing" ] || die "$(count <<< "$missing") images of the removed tags are gone from $registry: $(head -n 3 <<< "$missing" | paste -sd ' ' -)"
	done
	ok "the removed tags are gone, every other tag and every image is unchanged on ghcr.io and docker.io"
}

read_tags() {
	local registry="$1" token page last=""
	token="$(registry_token "$registry" pull)"
	: > "$WORK_DIR/tags.txt"
	while :; do
		page="$(curl -sSf --retry 3 -H "Authorization: Bearer $token" "https://$(registry_host "$registry")/v2/$IMAGE/tags/list?n=1000${last:+&last=$last}" | jq -r '.tags // [] | .[]')" || return 1
		[ -n "$page" ] || break
		echo "$page" >> "$WORK_DIR/tags.txt"
		last="$(tail -n 1 <<< "$page")"
	done
	request_manifests "$registry" "$WORK_DIR/tags.txt" -f --head -o /dev/null -w '%header{docker-content-digest}\t%header{content-type}\t{}\n'
}

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
	curl -sSf --head -o /dev/null -H "Authorization: Bearer $token" "https://$host/v2/$IMAGE/blobs/$EMPTY_BLOB" 2> /dev/null && return 0
	location="$(curl -sSf -X POST -o /dev/null -w '%header{location}' -H "Authorization: Bearer $token" "https://$host/v2/$IMAGE/blobs/uploads/")" || return 1
	[[ "$location" == http* ]] || location="https://$host$location"
	case "$location" in
		*\?*) location="$location&digest=$EMPTY_BLOB" ;;
		*) location="$location?digest=$EMPTY_BLOB" ;;
	esac
	curl -sSf -X PUT -o /dev/null -H "Authorization: Bearer $token" -H 'Content-Type: application/octet-stream' --data-binary '{}' "$location"
}

delete_placeholder() {
	local id attempt status
	case "$1" in
		ghcr.io)
			for attempt in 1 2 3 4 5; do
				id="$(gh api "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions?per_page=100" --jq ".[] | select(.name == \"$2\") | .id")" \
					|| die "gh could not list the versions of ghcr.io/$IMAGE"
				[ -z "$id" ] || break
				sleep "$attempt"
			done
			[ -n "$id" ] || die "found no version for the placeholder $2 among the newest 100 on ghcr.io, it holds the moved tags until it is deleted in the package settings"
			gh api -X DELETE "/orgs/${IMAGE%%/*}/packages/container/${IMAGE#*/}/versions/$id" > /dev/null \
				|| die "could not delete the placeholder $2 (version $id) from ghcr.io, it holds the moved tags until it is deleted"
			;;
		docker.io)
			status="$(curl -sS --retry 3 -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer $(registry_token docker.io pull,push,delete)" \
				"https://registry-1.docker.io/v2/$IMAGE/manifests/$2")"
			[[ "$status" == 2?? ]] || die "could not delete the placeholder $2 from docker.io, it answered $status, it holds the moved tags until it is deleted"
			;;
	esac
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
			"$@" "https://$(registry_host "$registry")/v2/$IMAGE/manifests/{}" < "$chunk" || return 1
		rm "$chunk"
	done
}

registry_token() {
	case "$1" in
		ghcr.io) curl -sSf --retry 3 ${GHCR_TOKEN:+-u "x:$GHCR_TOKEN"} "https://ghcr.io/token?scope=repository:$IMAGE:$2" ;;
		docker.io) curl -sSf --retry 3 -u "$DOCKERHUB_USERNAME:$DOCKERHUB_TOKEN" "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$IMAGE:$2" ;;
	esac | jq -r .token
}

hub_token() {
	jq -n --arg identifier "$DOCKERHUB_USERNAME" --arg secret "$DOCKERHUB_TOKEN" '{identifier: $identifier, secret: $secret}' \
		| curl -sSf --retry 3 -X POST -H 'Content-Type: application/json' --data-binary @- https://hub.docker.com/v2/auth/token | jq -er .access_token
}

registry_host() { echo "${1/docker.io/registry-1.docker.io}"; }

count() { awk 'END { print NR }'; }

main
