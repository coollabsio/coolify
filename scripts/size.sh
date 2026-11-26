#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
IMAGE="${1:-}"
BASELINE="${2:-}"
PLATFORM="${PLATFORM:-}"
IMAGE_NAME="${IMAGE_NAME:-}"
OUTPUT_FILE="${OUTPUT_FILE:-}"
WORK_DIR="$(mktemp -d)"

BOLD=$'\033[1m' GREEN=$'\033[32m' YELLOW=$'\033[33m' RED=$'\033[31m' RESET=$'\033[0m'

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s⚠%s %s\n' "$YELLOW" "$RESET" "$*"; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

trap 'rm -rf "$WORK_DIR"' EXIT

main() {
	[[ "$OPTION" =~ ^[^-][^[:space:]]*( [^-][^[:space:]]*)?$ ]] || die "'$OPTION' is not an option, expected an image and an optional baseline"
	[ -z "$OUTPUT_FILE" ] || [ -n "$BASELINE" ] || die "OUTPUT_FILE is set without a baseline, expected an image and a baseline to compare"
	measure_image image "$IMAGE"
	[ -z "$BASELINE" ] || measure_image baseline "$BASELINE"
	[ -z "$BASELINE" ] || compare_with_baseline
	[ -z "$OUTPUT_FILE" ] || write_report
}

measure_image() {
	heading "Sizes of $2"
	mkdir "$WORK_DIR/$1"
	if [ -d "$2" ]; then
		read_layouts "$1" "$2"
	else
		read_repository "$1" "$2"
	fi
	measure_manifests "$1" "$WORK_DIR/$1/index.json"
	[ -s "$WORK_DIR/$1.tsv" ] || die "$2 has no image for ${PLATFORM:-a platform}"
	sort -o "$WORK_DIR/$1.tsv" "$WORK_DIR/$1.tsv"
}

read_layouts() {
	layout="$2"
	find "$2" -name index.json -exec jq -s '{manifests: map(.manifests[])}' {} + > "$WORK_DIR/$1/index.json"
	[ -s "$WORK_DIR/$1/index.json" ] || die "$2 holds no OCI layout, expected an index.json in it"
}

read_repository() {
	local name="${2#docker.io/}" reference
	layout=""
	repository="${name%%[:@]*}"
	reference="${name:${#repository}+1}"
	[[ "$repository" == */* ]] || repository="library/$repository"
	read_object manifests "${reference:-latest}" > "$WORK_DIR/$1/index.json" 2> /dev/null || die "$2 is not on Docker Hub or not public"
}

measure_manifests() {
	local digest
	if jq -e 'has("manifests")' "$2" > /dev/null; then
		for digest in $(jq -r '.manifests[] | select(.platform.os != "unknown") | .digest' "$2"); do
			read_object manifests "$digest" > "$WORK_DIR/$1/$digest"
			measure_manifests "$1" "$WORK_DIR/$1/$digest"
		done
	else
		measure_platform "$1" "$2"
	fi
}

measure_platform() {
	local platform compressed unpacked=0 media_type digest blocks
	read_object blobs "$(jq -r .config.digest "$2")" > "$2.config"
	platform="$(jq -r '[.os, .architecture, .variant // empty] | join("/")' "$2.config")"
	[ -z "$PLATFORM" ] || [ "$platform" = "$PLATFORM" ] || return 0
	compressed=$(($(wc -c < "$2") + $(jq '.config.size + ([.layers[].size] | add // 0)' "$2")))
	while read -r media_type digest; do
		blocks="$(read_object blobs "$digest" | decompress "$media_type" | count_blocks)"
		unpacked=$((unpacked + blocks))
	done < <(jq -r '.layers[] | "\(.mediaType) \(.digest)"' "$2")
	printf '%s\t%s\t%s\t%s\n' "$platform" "$compressed" $((compressed + unpacked)) \
		"$(jq -r '.config.Labels["org.opencontainers.image.version"] // ""' "$2.config")" >> "$WORK_DIR/$1.tsv"
	ok "$BOLD$platform$RESET is $(format_size "$compressed") compressed and $(format_size $((compressed + unpacked))) on disk, $(format_size "$unpacked") of it unpacked"
}

decompress() {
	case "$1" in
		*+zstd) zstd -dc ;;
		*+gzip | *.tar.gzip) gzip -dc ;;
		*.tar) cat ;;
		*) die "a layer is $1, expected a tar archive, plain or compressed with gzip or zstd" ;;
	esac
}

count_blocks() {
	tar -tvf - | awk '
		BEGIN { blocks = 1 }
		/^-/ { blocks += int((($2 ~ /\// ? $3 : $5) + 4095) / 4096) }
		/^d/ { blocks++ }
		/^l/ && length(substr($0, index($0, " -> ") + 4)) >= 60 { blocks++ }
		END { print blocks * 4096 }'
}

compare_with_baseline() {
	local platform compressed on_disk base_compressed base_on_disk compressed_change on_disk_change
	heading "Changes from $BASELINE"
	while IFS=$'\t' read -r platform compressed on_disk base_compressed base_on_disk; do
		compressed_change="$(format_change "$compressed" "$base_compressed")"
		on_disk_change="$(format_change "$on_disk" "$base_on_disk")"
		if [ "$compressed" = - ]; then
			warn "$BOLD$platform$RESET is gone, $IMAGE has no image for it"
		elif [ "$base_compressed" = - ]; then
			ok "$BOLD$platform$RESET is new, $BASELINE has no image for it"
		else
			ok "$BOLD$platform$RESET changes by $compressed_change compressed and $on_disk_change on disk"
		fi
		printf '| %s | %s | %s | %s | %s | %s | %s |\n' "$platform" "$(format_size "$compressed")" "$(format_size "$base_compressed")" "$compressed_change" \
			"$(format_size "$on_disk")" "$(format_size "$base_on_disk")" "$on_disk_change" >> "$WORK_DIR/rows.md"
	done < <(join -t $'\t' -a 1 -a 2 -e - -o 0,1.2,1.3,2.2,2.3 "$WORK_DIR/image.tsv" "$WORK_DIR/baseline.tsv")
}

format_change() {
	awk -v size="$1" -v base="$2" 'BEGIN {
		if (size == "-" || base == "-") { print "-"; exit }
		delta = size - base
		if (delta == 0) { print "±0"; exit }
		if (delta <= -1000000 || delta >= 1000000) amount = sprintf("%+.2f MB", delta / 1000000)
		else if (delta <= -1000 || delta >= 1000) amount = sprintf("%+.1f kB", delta / 1000)
		else amount = sprintf("%+d B", delta)
		printf "%s (%+.1f %%)\n", amount, delta * 100 / base
	}'
}

write_report() {
	local image_name baseline_name
	image_name="$(short_name image "$IMAGE")"
	baseline_name="$(short_name baseline "$BASELINE")"
	if [ "$image_name" = "$baseline_name" ]; then
		image_name="\`$IMAGE\`"
		baseline_name="\`$BASELINE\`"
	fi
	{
		printf '## Image size\n\n'
		printf '%s compared with %s%s.\n\n' "$image_name" "$baseline_name" "$(format_version baseline "$BASELINE")"
		printf '| Platform | Compressed %s | Compressed %s | Change | On disk %s | On disk %s | Change |\n' "$image_name" "$baseline_name" "$image_name" "$baseline_name"
		printf '| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n'
		cat "$WORK_DIR/rows.md"
		printf "\nCompressed is what \`docker pull\` downloads, the CONTENT SIZE of the platform in \`docker image ls --tree\`. "
		printf "On disk adds the unpacked files in 4 KiB blocks of ext4, the DISK USAGE that Docker Desktop shows, as the containerd image store keeps both. "
		printf "Neither counts the SBOM and provenance attestations.\n"
	} > "$OUTPUT_FILE"
	ok "$OUTPUT_FILE holds the Markdown report"
}

short_name() {
	if [ "$1" = image ] && [ -n "$IMAGE_NAME" ]; then
		printf "\`%s\`" "$IMAGE_NAME"
	elif [ -d "$2" ]; then
		printf 'the build'
	else
		printf "\`%s\`" "${2##*:}"
	fi
}

format_version() {
	local version
	[ ! -d "$2" ] || return 0
	version="$(head -n 1 "$WORK_DIR/$1.tsv" | cut -f 4)"
	[ -z "$version" ] || printf " (\`%s\`)" "$version"
}

read_object() {
	local file
	if [ -z "$layout" ]; then
		fetch -H "Authorization: Bearer $(fetch "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$repository:pull" | jq -r .token)" \
			-H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json' \
			"https://registry-1.docker.io/v2/$repository/$1/$2"
	else
		file="$(find "$layout" -path "*/blobs/${2/://}" -print -quit)"
		[ -n "$file" ] || die "$layout has no blob $2"
		cat "$file"
	fi
}

fetch() {
	curl --proto '=https' --tlsv1.2 -sSf --retry 3 --retry-all-errors -L "$@"
}

format_size() {
	awk -v bytes="$1" 'BEGIN {
		if (bytes == "-") print "-"
		else if (bytes >= 1000000) printf "%.2f MB\n", bytes / 1000000
		else if (bytes >= 1000) printf "%.1f kB\n", bytes / 1000
		else printf "%d B\n", bytes
	}'
}

main
