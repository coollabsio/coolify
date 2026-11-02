#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
VERSION_FILE=release.json

BOLD=$'\033[1m' BLUE=$'\033[34m' GREEN=$'\033[32m' RED=$'\033[31m' DIM=$'\033[2m' RESET=$'\033[0m'

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
info() { printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
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

cd "$(dirname "$0")/.."

main() {
	[ -z "$OPTION" ] || die "'$OPTION' is not an option, expected none"
	check_repository
	heading "Undoing $(git log -1 --format='%h %s')"
	info "resets the commit and restores $VERSION_FILE and CHANGELOG.md"
	confirm "Continue?" || die "aborted, nothing changed"
	undo_commit
}

check_repository() {
	local branch
	git rev-parse --is-inside-work-tree > /dev/null 2>&1 || die "$PWD is not a git repository"
	branch="$(git rev-parse --abbrev-ref HEAD)"
	git diff --quiet HEAD~1 HEAD -- . ":(exclude)$VERSION_FILE" ":(exclude)CHANGELOG.md" || die "the last commit changes more than $VERSION_FILE and CHANGELOG.md, expected a release commit"
	[ "$(versions_at HEAD~1)" != "$(versions_at HEAD)" ] || die "the last commit keeps the versions in $VERSION_FILE at $(versions_at HEAD), expected a release commit"
	[ -z "$(git status --porcelain -- "$VERSION_FILE" CHANGELOG.md)" ] || die "$VERSION_FILE or CHANGELOG.md has uncommitted changes, commit or stash them first"
	git fetch --quiet origin || die "could not fetch origin"
	[ "$(git rev-list --count "origin/$branch..HEAD")" -ge 1 ] || die "the release commit is on origin/$branch already, nothing to undo locally"
}

undo_commit() {
	git reset --quiet --soft HEAD~1
	git restore --staged --worktree "$VERSION_FILE" CHANGELOG.md
	ok "reset to $DIM$(git rev-parse --short HEAD)$RESET $(git log -1 --format=%s)"
	ok "$VERSION_FILE is back to $BOLD$(versions_at HEAD)$RESET, the entries are back under [Unreleased]"
}

versions_at() {
	git show "$1:$VERSION_FILE" | jq -r '[paths(scalars) as $path | "\($path | join(".")) \(getpath($path))"] | join(", ")'
}

main
