#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
VERSION_FILE=release.json
ARTIFACT=coolify
UPSTREAM=
BREAKING_CHANGE='^([a-z]+(\([^)]*\))?!|BREAKING[ -]CHANGE): '
WORK_DIR="$(mktemp -d)"

BOLD=$'\033[1m' BLUE=$'\033[34m' GREEN=$'\033[32m' YELLOW=$'\033[33m' RED=$'\033[31m' DIM=$'\033[2m' RESET=$'\033[0m'

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
info() { printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s⚠%s %s\n' "$YELLOW" "$RESET" "$*"; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}
confirm() {
	local answer
	printf '\n  %s [y/N] ' "$*"
	read -r answer
	[[ "$answer" =~ ^[Yy]$ ]]
}
hint() { printf '  %-26s%s%s%s\n' "$1" "$DIM" "$2" "$RESET"; }

cd "$(dirname "$0")/.."
trap 'rm -rf "$WORK_DIR"; tput cnorm 2> /dev/null || true' EXIT

main() {
	[ -z "$OPTION" ] || die "'$OPTION' is not an option, expected none"
	check_repository
	read_versions
	read_changes
	select_stage
	select_version
	heading "Releasing v$new_version"
	update_changelog
	update_version
	commit
	push
}

check_repository() {
	local counts behind ahead entries
	heading "Checking the repository"
	git rev-parse --is-inside-work-tree > /dev/null 2>&1 || die "$PWD is not a git repository"
	branch="$(git symbolic-ref --quiet --short HEAD)" || die "HEAD is detached, expected a branch"
	[[ "$branch" =~ ^(main|next|v[0-9]+(\.[0-9]+)?\.x|release/v([0-9]+\.[0-9]+))$ ]] \
		|| die "$branch releases nothing"
	line="${BASH_REMATCH[3]}"
	channel=stable
	[[ ! "$branch" =~ ^(next|release/) ]] || channel=preview
	[ -z "$(git status --porcelain)" ] || die "the working tree is not clean, commit or stash first"
	[ "$(git rev-parse --is-shallow-repository)" = false ] || die "the clone is shallow, run git fetch --unshallow first"
	git fetch --quiet --prune --tags origin || die "could not fetch origin"
	counts="$(git rev-list --left-right --count "origin/$branch...HEAD" 2> /dev/null)" || die "origin has no branch $branch, push it first"
	read -r behind ahead <<< "$counts"
	[ "$behind$ahead" = 00 ] || die "$branch is $ahead commit(s) ahead of and $behind behind origin/$branch, expected it in sync"
	ok "$branch is clean and in sync with origin/$branch"
	entries="$(awk '/^## \[Unreleased\]$/ { inside = 1; next } /^## / { inside = 0 } inside && /^- / { count++ } END { print count + 0 }' CHANGELOG.md)"
	[ "$entries" != 0 ] || die "CHANGELOG.md has no entries under ## [Unreleased], describe the changes first"
	ok "CHANGELOG.md lists $BOLD$entries$RESET change(s) under [Unreleased], the notes of this release"
}

read_versions() {
	local released
	version="$(artifact_version "$channel")"
	released="$(artifact_version stable)"
	[ -n "$version" ] && [ -n "$released" ] || die "$VERSION_FILE has no versions of $ARTIFACT"
	stable="$(release_tags --merged HEAD | tag_versions | awk '!/-/' | sort -V | tail -n 1)"
	stable="${stable:-$released}"
	! newer "$released" "$stable" || die "$VERSION_FILE sets the stable version $released, $branch released $stable last, publish $released first"
	ok "$VERSION_FILE sets the $channel version $BOLD$version$RESET"
}

read_changes() {
	local tag range hash subject
	tag="$(git describe --tags --abbrev=0 --match "*v$stable" 2> /dev/null)" || true
	range="${tag:+$tag..}HEAD"
	required="patch"
	if [ -n "$(git log -E --grep="$BREAKING_CHANGE" --format=%h "$range")" ]; then
		required=major
		[ "${stable%%.*}" != 0 ] || required=minor
	elif git log --format=%s "$range" | awk '/^feat(\([^)]*\))?!?: / && !/^feat\(release\): / { found = 1 } END { exit !found }'; then
		required=minor
	fi
	info "the commits since ${tag:-the first commit} need a $BOLD$required$RESET release"
	git log -E --grep="$BREAKING_CHANGE" --format='%h %s' "$range" | while read -r hash subject; do
		warn "$DIM$hash$RESET $subject is a breaking change"
	done
}

select_stage() {
	local stages=(alpha beta rc) selected=0 i
	stage=
	[ "$channel" = preview ] || return 0
	for i in "${!stages[@]}"; do
		[[ "$version" != *-"${stages[$i]}".* ]] || selected="$i"
	done
	heading "Select the stage"
	stage="${stages[$(menu "$selected" "${stages[@]}")]}"
}

select_version() {
	local bases=() base candidate options=() selected=0
	if [ -n "$line" ]; then
		bases=("$line.0")
	elif [ "$channel" = preview ]; then
		bases=("$(next_version "$stable" minor)" "$(next_version "$stable" major)")
		[ "$required" != major ] || selected=1
		if [[ "$version" == *-* ]] && newer "${version%%-*}" "$stable"; then
			if taken "${version%%-*}"; then
				info "${version%%-*} is taken by $holder"
			else
				bases=("${version%%-*}" "${bases[@]}")
				selected=0
			fi
		fi
	elif [ "$stable" = 0.0.0 ]; then
		bases=("$(next_version "$stable" minor)" "$(next_version "$stable" major)")
	else
		bases=("$(next_version "$stable" patch)")
		[[ "$branch" == v*.*.x ]] || bases+=("$(next_version "$stable" minor)")
		[ "$branch" != main ] || bases+=("$(next_version "$stable" major)")
		case "$required" in
			minor) selected=1 ;;
			major) selected=2 ;;
		esac
	fi
	for base in "${bases[@]}"; do
		candidate="$base"
		if [ -n "$stage" ]; then
			candidate="$base-$stage.1"
			[ "$base-$stage" != "${version%.*}" ] || candidate="$base-$stage.$((${version##*.} + 1))"
		fi
		if newer "$candidate" "$version" && [[ " ${options[*]:-} " != *" $candidate "* ]]; then
			options+=("$candidate")
			! in_progress "$base" || warn "$base is in progress, held by $holder"
		fi
	done
	[ -n "${options[*]:-}" ] || die "$branch is at $version, no $stage prerelease follows it"
	[ "$selected" -lt "${#options[@]}" ] || selected=$((${#options[@]} - 1))
	heading "Select the version"
	new_version="${options[$(menu "$selected" "${options[@]/#/v}")]}"
}

update_changelog() {
	local today
	if [ "$channel" = preview ]; then
		info "CHANGELOG.md keeps the entries under [Unreleased] until the stable release dates them"
		return
	fi
	today="$(date +%Y-%m-%d)"
	awk -v heading="## [v$new_version] - $today" '
		/^#/ && NR > 1 && previous != "" { print "" }
		{ print; previous = $0 }
		/^## \[Unreleased\]$/ { print ""; print heading; previous = heading }
	' CHANGELOG.md > "$WORK_DIR/CHANGELOG.md"
	cp "$WORK_DIR/CHANGELOG.md" CHANGELOG.md
	ok "CHANGELOG.md dates the unreleased entries as ${BOLD}v$new_version$RESET, $today"
}

update_version() {
	jq --tab --arg channel "$channel" --arg artifact "$ARTIFACT" --arg version "$new_version" '.[$channel][$artifact] = $version' "$VERSION_FILE" > "$WORK_DIR/release.json"
	cp "$WORK_DIR/release.json" "$VERSION_FILE"
	ok "$VERSION_FILE sets the $channel version $version → $BOLD$new_version$RESET"
}

commit() {
	git add "$VERSION_FILE" CHANGELOG.md
	git commit --quiet -S --message "feat(release): bump $ARTIFACT version to v$new_version"
	ok "signed commit $DIM$(git rev-parse --short HEAD)$RESET $(git log -1 --format=%s)"
}

push() {
	local runs
	if confirm "Push to origin/$branch?"; then
		git push --quiet origin "$branch"
		ok "pushed ${BOLD}v$new_version$RESET to origin/$branch"
		runs="$(git remote get-url origin | sed -E 's#^(ssh://)?git@github\.com[:/]#https://github.com/#; s#\.git$##')/actions/workflows/release.yaml?query=branch%3A$branch"
		info "the release workflow runs at $runs"
	else
		info "not pushed"
		heading "Next"
		hint "git push" "push the release commit later"
		hint "scripts/undo-release.sh" "undo the release commit"
	fi
}

artifact_version() {
	jq -r --arg channel "$1" --arg artifact "$ARTIFACT" '.[$channel][$artifact] // empty' "$VERSION_FILE"
}

release_tags() {
	git tag --list "$@" | grep -E "^${UPSTREAM:+[^-]+-}v[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$" || true
}

tag_versions() {
	sed 's/.*v//'
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

newer() {
	[ "$1" != "$2" ] && [ "$(printf '%s\n' "$1" "$2" | sed 's/-/~/' | sort -V | tail -n 1 | sed 's/~/-/')" = "$1" ]
}

menu() {
	local selected="$1" options key i
	shift
	options=("$@")
	tput civis >&2 2> /dev/null || true
	while true; do
		for i in "${!options[@]}"; do
			if [ "$i" = "$selected" ]; then
				printf '  %s❯ %s%s\n' "$GREEN" "${options[$i]}" "$RESET" >&2
			else
				printf '  %s  %s%s\n' "$DIM" "${options[$i]}" "$RESET" >&2
			fi
		done
		IFS= read -rsn1 key
		if [ "$key" = $'\x1b' ]; then
			IFS= read -rsn2 key
			if [ "$key" = '[A' ] && [ "$selected" -gt 0 ]; then
				selected=$((selected - 1))
			elif [ "$key" = '[B' ] && [ "$selected" -lt $((${#options[@]} - 1)) ]; then
				selected=$((selected + 1))
			fi
		elif [ -z "$key" ]; then
			break
		fi
		printf '\033[%dA\033[J' "${#options[@]}" >&2
	done
	tput cnorm >&2 2> /dev/null || true
	echo "$selected"
}

main
