#!/usr/bin/env bash
set -euo pipefail

OPTION="$*"
CHANNEL="${1:-}"
VERSION="${2:-}"
CDN_REPOSITORY=coollabsio/coollabs-cdn
CDN_URL=https://cdn.coollabs.io
FILES=releases.json,docker-compose.yml,docker-compose.prod.yml,.env.production,install.sh,upgrade.sh,upgrade-postgres.sh,patch.sh,service-templates-latest.json
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
	[[ "$OPTION" =~ ^(--stable [0-9]+\.[0-9]+\.[0-9]+|--preview [0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?|--nightly [0-9]+\.[0-9]+\.[0-9]+-(alpha|beta|rc)\.[0-9]+\.[0-9]{8}\.[0-9]+|--purge)$ ]] \
		|| die "'$OPTION' is not an option, expected --stable, --preview or --nightly with the version of the channel, or --purge"
	if [ "$OPTION" = --purge ]; then
		purge_cache
		return
	fi
	select_channel
	fetch_releases
	clone_cdn
	write_versions
	commit_channel
	publish_channel
}

select_channel() {
	channel="${CHANNEL#--}"
	channel_path=json/coolify channel_url="$CDN_URL/coolify"
	if [ "$channel" != stable ]; then
		channel_path+="/$channel" channel_url+="/$channel"
	fi
	if [[ "$channel:$VERSION" == preview:*-alpha.* ]]; then
		info "the preview channel takes no alphas, Coolify $VERSION reaches the nightly channel only"
		exit 0
	fi
	info "syncing $channel_url to Coolify $BOLD$VERSION$RESET"
}

fetch_releases() {
	gh api "repos/coollabsio/coolify/releases?per_page=30" | jq . > "$WORK_DIR/releases.json"
	ok "releases.json lists the $(jq length "$WORK_DIR/releases.json") newest GitHub releases"
}

clone_cdn() {
	gh repo clone "$CDN_REPOSITORY" "$WORK_DIR/cdn" -- --quiet --depth 1
	ok "cloned $CDN_REPOSITORY"
}

write_versions() {
	local versions="$WORK_DIR/cdn/json/coolify/versions.json"
	[ -f "$versions" ] || echo '{}' > "$versions"
	jq --indent 4 --arg channel "$channel" --arg version "$VERSION" --slurpfile upstream upstream.json '
		.[$channel] = ({coolify: {$version}} + $upstream[0])
		| if $channel == "stable" then .coolify = ({v4: {$version}} + ($upstream[0] | del(.traefik))) | .traefik = $upstream[0].traefik else . end
		| {stable, preview, nightly, coolify, traefik} | with_entries(select(.value))
	' "$versions" > "$WORK_DIR/versions.json"
	cp "$WORK_DIR/versions.json" "$versions"
	ok "versions.json sets the $channel version $BOLD$VERSION$RESET with the helper, Sentinel and Traefik versions of upstream.json"
	[ "$channel" != stable ] || ok "versions.json sets the coolify and traefik keys of older instances to the same versions"
}

commit_channel() {
	local name count
	mkdir -p "$WORK_DIR/cdn/$channel_path"
	for name in ${FILES//,/ }; do
		channel_file "$name" > "$WORK_DIR/cdn/$channel_path/$name"
	done
	if [ "$channel" != stable ]; then
		cdn rm --quiet --ignore-unmatch "$channel_path/versions.json"
	fi
	cdn add "$channel_path" json/coolify/versions.json
	if cdn diff --cached --quiet; then
		ok "$channel_path on $CDN_REPOSITORY is up to date"
		exit 0
	fi
	title="chore: sync the $channel channel to Coolify $VERSION"
	count="$(cdn diff --cached --name-only | wc -l | tr -d ' ')"
	cdn commit --quiet --message "$title"
	ok "committed $count changed file(s) of $channel_path"
}

publish_channel() {
	local previous from branch pull_request
	if [ "$channel" != stable ]; then
		cdn push --quiet origin HEAD:main
		ok "pushed the $channel channel to main of $CDN_REPOSITORY"
		return
	fi
	previous="$(cdn show HEAD~1:json/coolify/versions.json 2> /dev/null | jq -r '.stable.coolify.version // .coolify.v4.version // empty' || true)"
	[ "$previous" = "$VERSION" ] || from="${previous:+from Coolify $previous to }"
	branch="sync/stable-$(date -u +%Y%m%d%H%M%S)"
	cdn push --quiet origin "HEAD:refs/heads/$branch"
	pull_request="$(gh pr create --repo "$CDN_REPOSITORY" --base main --head "$branch" --title "$title" \
		--body "Syncs the stable channel ${from:-to Coolify }$VERSION, see the [release notes](https://github.com/coollabsio/coolify/releases/tag/v$VERSION).")"
	ok "opened $pull_request"
	info "Cloudflare keeps the old files for up to 10 minutes, scripts/sync-cdn.sh --purge clears them once this pull request is merged and deployed"
}

purge_cache() {
	local token zone
	token="${CLOUDFLARE_API_TOKEN:-$(env_value CLOUDFLARE_API_TOKEN)}"
	zone="${CLOUDFLARE_ZONE_ID:-$(env_value CLOUDFLARE_ZONE_ID)}"
	[ -n "$token" ] && [ -n "$zone" ] || die "CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID are set neither in the environment nor in .env, expected a token that can purge the cache of the zone"
	clone_cdn
	cdn ls-files json/coolify | sed "s|^json/|$CDN_URL/|" | xargs -n 30 | while read -r urls; do
		curl --proto '=https' --tlsv1.2 -sSf --retry 3 -H "Authorization: Bearer $token" -H "Content-Type: application/json" \
			--data "$(tr ' ' '\n' <<< "$urls" | jq -Rnc '{files: [inputs]}')" "https://api.cloudflare.com/client/v4/zones/$zone/purge_cache" \
			| jq -e .success > /dev/null || die "Cloudflare did not purge the files of $CDN_URL/coolify"
	done
	ok "purged every file of $CDN_URL/coolify from the Cloudflare cache"
}

channel_file() {
	case "$1" in
		releases.json) cat "$WORK_DIR/$1" ;;
		service-templates-latest.json) cat "templates/$1" ;;
		*.sh) sed -e "s|^CDN=\"$CDN_URL/coolify\"$|CDN=\"$channel_url\"|" -e "s|^CHANNEL=\"stable\"$|CHANNEL=\"$channel\"|" "scripts/$1" ;;
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
