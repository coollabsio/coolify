#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
BRANCH="${BRANCH:-}"
VERSION_FILE=release.json
ARTIFACT=coolify
UPSTREAM_FILE=upstream.json
UPSTREAM=

BOLD=$'\033[1m' BLUE=$'\033[34m' GREEN=$'\033[32m' YELLOW=$'\033[33m' RED=$'\033[31m' RESET=$'\033[0m'

info() { [ -n "$OPTION" ] || printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*" >&2; }
ok() { [ -n "$OPTION" ] || printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*" >&2; }
warn() { [ -n "$OPTION" ] || printf '  %s⚠%s %s\n' "$YELLOW" "$RESET" "$*" >&2; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

cd "$(dirname "$0")/.."

main() {
	[[ "$OPTION" =~ ^(--build|--release|--tags)?$ ]] || die "'$OPTION' is not an option, expected --build, --release, --tags or none"
	read_version
	fetch_origin
	print_tags
	check_branch
	check_version
	select_releases
	check_unreleased
	print_upcoming_versions
	print_releases
}

read_version() {
	branch="${BRANCH:-$(git branch --show-current)}"
	channel=stable
	[[ ! "$branch" =~ ^(next|release/v[0-9]+\.[0-9]+)$ ]] || channel=preview
	version="$(artifact_version "$channel")"
	[ -n "$version" ] || die "$VERSION_FILE has no $channel version of $ARTIFACT"
	if [ "$OPTION" = --build ]; then
		echo "$version"
		exit 0
	fi
}

fetch_origin() {
	local current behind
	[ "$(git rev-parse --is-shallow-repository)" = false ] || die "the clone is shallow, run git fetch --unshallow first"
	git fetch --quiet --prune --tags origin || die "could not fetch origin"
	current="$(git branch --show-current)"
	if [ -n "$current" ] && behind="$(git rev-list --count "HEAD..origin/$current" 2> /dev/null)"; then
		[ "$behind" = 0 ] || die "$current is $behind commit(s) behind origin/$current, pull first"
	fi
	tags="$(release_tags | sort -V)"
}

print_tags() {
	local released
	[ "$OPTION" = --tags ] || return 0
	released="$(release_tags --points-at HEAD)"
	moved_tags "$(grep -vxF -f <(echo "$released") <<< "$tags" || true)" "$tags" \
		| jq -Rnc '[inputs | split(" ") | {(.[0]): .[1]}] | add // {}'
	exit 0
}

check_branch() {
	[[ "$branch" =~ ^(main|next|v([0-9]+(\.[0-9]+)?)\.x|release/v([0-9]+\.[0-9]+))$ ]] || die "${branch:-a detached HEAD} releases nothing"
	line="${BASH_REMATCH[2]}${BASH_REMATCH[4]}"
	case "$branch" in
		main) ok "main releases stable versions" ;;
		next) ok "next releases prereleases" ;;
		release/*) ok "$branch releases prereleases of $line.0" ;;
		*) ok "$branch releases stable $line.x versions" ;;
	esac
}

check_version() {
	local from
	if [ "$channel" = stable ]; then
		[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "$VERSION_FILE sets '$version', expected major.minor.patch"
		[ -z "$line" ] || [[ "$version" == "$line".* ]] || die "$VERSION_FILE sets $version, expected a $line.x version"
	else
		[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$ ]] \
			|| die "$VERSION_FILE sets '$version', expected major.minor.patch with an optional -alpha.N, -beta.N or -rc.N"
		[ -z "$line" ] || [ "${version%%-*}" = "$line.0" ] || die "$VERSION_FILE sets $version, expected a prerelease of $line.0"
	fi
	last="$(release_tags --merged HEAD | tag_versions | awk -v channel="$channel" 'channel == "preview" || !/-/' | newest)"
	earlier="$(with_version "$version" <<< "$tags" | tail -n 1)"
	publish=false
	if newer "$last" "$version"; then
		info "$VERSION_FILE sets $version, $branch released $last already"
	elif [ "$version" = 0.0.0 ]; then
		info "$VERSION_FILE sets 0.0.0, $branch releases nothing before scripts/release.sh sets the first version"
	elif [ "$channel" = preview ] && [[ ! "$version" =~ -[a-z]+\.[1-9] ]]; then
		info "$VERSION_FILE sets $version, $branch publishes numbered prereleases only"
	elif [ "$channel" = preview ] && [ -n "$earlier" ]; then
		ok "$VERSION_FILE sets $BOLD$version$RESET"
		info "$earlier is published, $branch publishes a prerelease once"
	else
		from="${last:+$last → }"
		[ "$version" != "$last" ] || from=
		ok "$VERSION_FILE sets $from$BOLD$version$RESET"
		publish=true
	fi
	if [ "$publish" = true ] && taken "${version%%-*}"; then
		die "$VERSION_FILE sets $version, ${version%%-*} is taken by $holder"
	fi
	if [ "$publish" = true ] && in_progress "${version%%-*}"; then
		warn "${version%%-*} is in progress, held by $holder"
	fi
}

select_releases() {
	local name sqlite tag previous older moves listed added=""
	releases=
	[ "$publish" = true ] || return 0
	while read -r name sqlite; do
		tag="${sqlite:+$sqlite-}v$version"
		if grep -qxF "$tag" <<< "$tags"; then
			info "$tag is published"
			continue
		fi
		previous=
		while read -r older; do
			! newer "$sqlite" "$older" || previous="$older"
		done < <(with_version "$version" <<< "$tags" | sed -n 's/-v.*//p')
		added+="$tag"$'\n'
		releases+="$(jq -nc --arg name "$name" --arg version "$sqlite" --arg image "$version" --arg tag "$tag" --arg previous "$previous" \
			'{$name, $version, $image, $tag, $previous}')"$'\n'
	done < <(upstream_lines)
	moves="$(moved_tags "$tags" "$tags"$'\n'"$added")"
	for tag in $added; do
		listed="$(moved_to "$tag")"
		ok "$BOLD$tag$RESET is new, a push publishes it${listed:+ and moves $listed}"
	done
	awk 'NF { print $2 }' <<< "$moves" | sort -u | while read -r tag; do
		grep -qxF "$tag" <<< "$added" || info "a push moves $(moved_to "$tag") to $tag"
	done
}

check_unreleased() {
	local count
	[ -n "$releases" ] && [ -n "$earlier" ] || return 0
	count="$(git rev-list --no-merges "$earlier..HEAD" -- src | grep -cvxF -f <(git rev-list "$earlier..HEAD" -- "$UPSTREAM_FILE") || true)"
	[ "$count" = 0 ] || die "$count commit(s) since $earlier change src/ and would ship as $version, release them first with scripts/release.sh"
}

print_upcoming_versions() {
	local base stable upcoming
	[ -z "$releases" ] || return 0
	if [ "$channel" = stable ]; then
		base="$version"
		! newer "$last" "$base" || base="$last"
		case "$branch" in
			main) upcoming="$(next_version "$base" patch), $(next_version "$base" minor) or $(next_version "$base" major)" ;;
			v*.*.x) upcoming="$(next_version "$base" patch)" ;;
			*) upcoming="$(next_version "$base" patch) or $(next_version "$base" minor)" ;;
		esac
		[ "$base" != 0.0.0 ] || upcoming="$(next_version "$base" minor) or $(next_version "$base" major)"
	elif [ -n "$line" ]; then
		upcoming="a prerelease of $line.0"
	else
		stable="$(release_tags --merged HEAD | tag_versions | awk '!/-/' | newest)"
		stable="${stable:-$(artifact_version stable)}"
		upcoming="a prerelease of $(next_version "$stable" minor) or $(next_version "$stable" major)"
	fi
	info "the upcoming version on $branch is $upcoming, scripts/release.sh sets it"
}

print_releases() {
	[ "$OPTION" = --release ] || return 0
	jq -sc . <<< "$releases"
}

artifact_version() {
	jq -r --arg channel "$1" --arg artifact "$ARTIFACT" '.[$channel][$artifact] // empty' "$VERSION_FILE"
}

upstream_lines() {
	if [ -z "$UPSTREAM" ]; then
		echo latest
		return
	fi
	jq -r --arg upstream "$UPSTREAM" '.[$upstream] | to_entries[] | "\(.key) \(.value.version)"' "$UPSTREAM_FILE"
}

next_version() {
	local major minor patch
	IFS=. read -r major minor patch <<< "${1%%-*}"
	while true; do
		case "$2" in
			patch) patch=$((patch + 1)) ;;
			minor) minor=$((minor + 1)) patch=0 ;;
			major) major=$((major + 1)) minor=0 patch=0 ;;
		esac
		taken "$major.$minor.$patch" || break
	done
	echo "$major.$minor.$patch"
}

taken() {
	local tag
	for tag in $(release_tags "*v$1"); do
		holder="$tag, which $branch does not contain"
		git merge-base --is-ancestor "$tag" HEAD || return 0
	done
	[ "$channel" = preview ] && in_progress "$1"
}

in_progress() {
	local tag release="release/v${1%.0}"
	if [ "$release" != "$branch" ] && git rev-parse --quiet --verify "origin/$release" > /dev/null; then
		holder="$release"
		[ "$channel" = stable ] || return 0
		git merge-base --is-ancestor "origin/$release" HEAD || return 0
	fi
	for tag in $(release_tags "*v$1-*"); do
		holder="$tag, which $branch does not contain"
		git merge-base --is-ancestor "$tag" HEAD || return 0
	done
	return 1
}

moved_tags() {
	moving_tags <<< "$2" | grep -vxF -f <(moving_tags <<< "$1") || true
}

moving_tags() {
	local tags releases latest preview base image sqlite prefix seen=" "
	tags="$(cat)"
	releases="$(sed -nE 's/^((.*)-)?v([^-]*)$/\3 \2/p' <<< "$tags" | sort -k1,1rV -k2,2rV)"
	latest="$(awk 'NR == 1 && NF { print ($2 == "" ? "" : $2 "-") "v" $1 }' <<< "$releases")"
	preview="$latest"
	while read -r base; do
		if newer "$base" "${latest##*v}"; then
			preview="$(tag_versions <<< "$tags" | grep "^$base-" | newest)"
			preview="$(with_version "$preview" <<< "$tags" | sort -V | tail -n 1)"
			break
		fi
	done < <(tag_versions <<< "$tags" | sed -n 's/-.*//p' | sort -uV)
	[ -z "$latest" ] || echo "latest $latest"
	[ -z "$preview" ] || echo "preview $preview"
	while read -r image sqlite; do
		prefix="$sqlite"
		while [ -n "$prefix" ] && [[ "$seen" != *" $prefix "* ]]; do
			echo "$prefix $sqlite-v$image"
			seen+="$prefix "
			[[ "$prefix" == *.* ]] || break
			prefix="${prefix%.*}"
		done
	done <<< "$releases"
}

release_tags() {
	git tag --list "$@" | grep -E "^${UPSTREAM:+[^-]+-}v[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$" || true
}

tag_versions() {
	sed 's/.*v//'
}

with_version() {
	awk -v version="$1" '{ tagged = $0; sub(/.*v/, "", tagged) } tagged == version'
}

moved_to() {
	awk -v tag="$1" '$2 == tag { print $1 }' <<< "$moves" | paste -sd , - | sed 's/,/, /g; s/\(.*\), /\1 and /'
}

newer() {
	[ "$1" != "$2" ] && [ "$(printf '%s\n' "$1" "$2" | newest)" = "$1" ]
}

newest() {
	sed 's/-/~/' | sort -V | sed 's/~/-/' | tail -n 1
}

main
