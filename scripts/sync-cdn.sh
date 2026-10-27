#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
CHANNEL="${1:-}"
VERSION="${2:-}"
CDN_REPOSITORY=coollabsio/coollabs-cdn
CDN_URL=https://cdn.coollabs.io
FILES="versions.json releases.json docker-compose.yml docker-compose.prod.yml .env.production install.sh upgrade.sh upgrade-postgres.sh service-templates-latest.json"
WORK_DIR="$(mktemp -d)"

BOLD=$'\033[1m' BLUE=$'\033[34m' GREEN=$'\033[32m' RED=$'\033[31m' RESET=$'\033[0m'

info() { printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

cd "$(dirname "$0")/.."
trap 'rm -rf "$WORK_DIR"' EXIT

main() {
	[[ "$OPTION" =~ ^(--stable [0-9]+\.[0-9]+\.[0-9]+|--preview [0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?|--purge)$ ]] \
		|| die "'$OPTION' is not an option, expected --stable or --preview with the version of the channel, or --purge"
	if [ "$OPTION" = --purge ]; then
		purge_cache
		return
	fi
	select_channel
	write_versions
	fetch_releases
	clone_cdn
	write_release
	commit_channel
	publish_channel
}

select_channel() {
	channel="${CHANNEL#--}"
	channel_path=json/coolify channel_url="$CDN_URL/coolify" channel_files="$FILES patch.sh"
	if [ "$channel" != stable ]; then
		channel_path+="/$channel" channel_url+="/$channel" channel_files="$FILES"
	fi
	if [[ "$channel:$VERSION" == preview:*-alpha.* ]]; then
		info "the preview channel takes no alphas, Coolify $VERSION is not synced"
		exit 0
	fi
	info "syncing $channel_url to Coolify $BOLD$VERSION$RESET"
}

write_versions() {
	jq --indent 4 --arg version "$VERSION" '{coolify: ({v4: {$version}} + del(.traefik)), traefik}' upstream.json > "$WORK_DIR/versions.json"
	ok "versions.json names Coolify $VERSION and the helper, Sentinel and Traefik versions of upstream.json"
}

fetch_releases() {
	gh api "repos/coollabsio/coolify/releases?per_page=30" | jq . > "$WORK_DIR/releases.json"
	ok "releases.json lists the $(jq length "$WORK_DIR/releases.json") newest GitHub releases"
}

clone_cdn() {
	gh repo clone "$CDN_REPOSITORY" "$WORK_DIR/cdn" -- --quiet --depth 1
	ok "cloned $CDN_REPOSITORY"
}

write_release() {
	local release="$WORK_DIR/cdn/json/coolify/release.json"
	[ -f "$release" ] || echo '{}' > "$release"
	jq --tab --arg channel "$channel" --arg version "$VERSION" '.[$channel].coolify = $version | {stable, preview} | with_entries(select(.value))' \
		"$release" > "$WORK_DIR/release.json"
	cp "$WORK_DIR/release.json" "$release"
	ok "release.json sets the $channel version $BOLD$VERSION$RESET"
}

commit_channel() {
	local name
	mkdir -p "$WORK_DIR/cdn/$channel_path"
	for name in $channel_files; do
		channel_file "$name" > "$WORK_DIR/cdn/$channel_path/$name"
	done
	cdn add "$channel_path" json/coolify/release.json
	if cdn diff --cached --quiet; then
		ok "$channel_path on $CDN_REPOSITORY is up to date"
		exit 0
	fi
	title="chore: sync the $channel channel to Coolify $VERSION"
	changes="$(cdn diff --cached --name-only | sed 's/^/- /')"
	cdn commit --quiet --message "$title"
	ok "committed $(wc -l <<< "$changes" | tr -d ' ') changed file(s) of $channel_path"
}

publish_channel() {
	local branch pull_request
	if [ "$channel" != stable ]; then
		cdn push --quiet origin HEAD:main
		ok "pushed the $channel channel to main of $CDN_REPOSITORY"
		return
	fi
	branch="sync/stable-$(date -u +%Y%m%d%H%M%S)"
	cdn push --quiet origin "HEAD:refs/heads/$branch"
	pull_request="$(gh pr create --repo "$CDN_REPOSITORY" --base main --head "$branch" --title "$title" \
		--body "$(printf 'Syncs the stable channel to Coolify %s from coollabsio/coolify@%s.\n\n## Changes\n\n%s\n' "$VERSION" "$(git rev-parse --short HEAD)" "$changes")")"
	ok "opened $pull_request"
	info "Cloudflare keeps the old files for up to 10 minutes, scripts/sync-cdn.sh --purge clears them once it is merged and deployed"
}

purge_cache() {
	local token zone channel_url name urls
	token="${CLOUDFLARE_API_TOKEN:-$(env_value CLOUDFLARE_API_TOKEN)}"
	zone="${CLOUDFLARE_ZONE_ID:-$(env_value CLOUDFLARE_ZONE_ID)}"
	[ -n "$token" ] && [ -n "$zone" ] || die "CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID are set neither in the environment nor in .env, expected a token that can purge the cache of the zone"
	for channel_url in "$CDN_URL/coolify" "$CDN_URL/coolify/preview"; do
		urls="$(for name in $FILES patch.sh release.json; do echo "$channel_url/$name"; done)"
		curl --proto '=https' --tlsv1.2 -sSf --retry 3 -H "Authorization: Bearer $token" -H "Content-Type: application/json" \
			--data "$(jq -Rnc '{files: [inputs]}' <<< "$urls")" "https://api.cloudflare.com/client/v4/zones/$zone/purge_cache" \
			| jq -e .success > /dev/null || die "Cloudflare did not purge $channel_url"
	done
	ok "purged the stable and preview channels from the Cloudflare cache"
}

channel_file() {
	case "$1" in
		versions.json | releases.json) cat "$WORK_DIR/$1" ;;
		service-templates-latest.json) cat "templates/$1" ;;
		*.sh) sed "s|^CDN=\"$CDN_URL/coolify\"$|CDN=\"$channel_url\"|" "scripts/$1" ;;
		*) cat "$1" ;;
	esac
}

cdn() {
	git -C "$WORK_DIR/cdn" "$@"
}

env_value() {
	[ ! -f .env ] || sed -n "s/^$1=//p" .env | tail -n 1 | tr -d "\"'"
}

main
