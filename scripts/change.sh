#!/usr/bin/env bash
set -euo pipefail

CHANGE_DIRECTORY=.changes
TYPES=(added changed deprecated removed fixed security maintenance)
SECTIONS=('Breaking Changes' Security Added Changed Deprecated Removed Fixed Maintenance)

BOLD=$'\033[1m' GREEN=$'\033[32m' RED=$'\033[31m' DIM=$'\033[2m' RESET=$'\033[0m'
[ -t 1 ] || BOLD='' GREEN='' RED='' DIM='' RESET=''

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
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
trap 'tput cnorm 2> /dev/null || true' EXIT

main() {
	read_arguments "$@"
	if [ -n "$notes" ]; then
		print_notes
		return
	fi
	[ -n "$kind" ] && [ -n "$summary" ] || ask_questions
	write_change
}

read_arguments() {
	kind='' summary='' commit='' breaking='' notes='' details=()
	while [ $# -gt 0 ]; do
		case "$1" in
			--notes) notes=true ;;
			--breaking) breaking=true ;;
			--type | --summary | --detail | --commit)
				[ $# -ge 2 ] || die "$1 has no value, expected one"
				case "$1" in
					--type) kind="$2" ;;
					--summary) summary="$2" ;;
					--detail) details+=("$2") ;;
					--commit) commit="$2" ;;
				esac
				shift
				;;
			*) die "'$1' is not an option, expected --type, --summary, --detail, --breaking, --commit or --notes" ;;
		esac
		shift
	done
}

ask_questions() {
	local selected detail
	[ -t 0 ] || die "--type and --summary are required without a terminal"
	if [ -z "$kind" ]; then
		heading "Select the type"
		selected="$(menu 0 "${TYPES[@]}")"
		kind="${TYPES[$selected]}"
	fi
	if [ -z "$summary" ]; then
		heading "Summary, one sentence with the heading"
		printf '  '
		read -r summary
	fi
	heading "Details, an empty line ends them"
	while printf '  - ' && read -r detail && [ -n "$detail" ]; do
		details+=("$detail")
	done
	if confirm "Breaking change?"; then
		breaking=true
	fi
	printf '\n'
}

write_change() {
	local path detail
	printf '%s\n' "${TYPES[@]}" | grep -qx -- "$kind" || die "the type is '$kind', expected ${TYPES[*]}"
	[ -n "$summary" ] || die "the summary is empty, expected one sentence"
	if [ -n "$commit" ]; then
		git rev-parse --verify --quiet "$commit^{commit}" > /dev/null || die "$commit is not a commit of this repository"
		commit="$(git rev-parse --short "$commit")"
	fi
	mkdir -p "$CHANGE_DIRECTORY"
	path="$CHANGE_DIRECTORY/$(file_name).md"
	{
		printf -- '---\ntype: %s\n' "$kind"
		[ -z "$breaking" ] || printf 'breaking: true\n'
		[ -z "$commit" ] || printf 'commit: %s\n' "$commit"
		printf -- '---\n%s\n' "$summary"
		[ "${#details[@]}" = 0 ] || printf '\n'
		for detail in ${details[@]+"${details[@]}"}; do
			printf -- '- %s\n' "$detail"
		done
	} > "$path"
	ok "$path describes the $BOLD$kind$RESET change"
}

print_notes() {
	local section entries
	compgen -G "$CHANGE_DIRECTORY/*.md" > /dev/null || die "$CHANGE_DIRECTORY has no change files"
	for section in "${SECTIONS[@]}"; do
		entries="$(awk -v section="$section" '
			FNR == 1 { fences = 0; type = ""; breaking = 0 }
			fences < 2 {
				if ($0 == "---") fences++
				else if ($1 == "type:") type = $2
				else if ($0 == "breaking: true") breaking = 1
				next
			}
			NF && tolower(section) == (breaking ? "breaking changes" : type) {
				prefix = /^- / ? "  " : "- "
				print prefix $0
			}
		' "$CHANGE_DIRECTORY"/*.md)"
		[ -z "$entries" ] || printf '### %s\n\n%s\n\n' "$section" "$entries"
	done
}

file_name() {
	local stem name number=2
	stem="$(printf '%s' "$summary" | LC_ALL=C tr -cs '[:alnum:]' '\n' | LC_ALL=C tr '[:upper:]' '[:lower:]' | awk 'NF && ++count <= 6' | paste -sd - -)"
	name="$stem"
	while [ -e "$CHANGE_DIRECTORY/$name.md" ]; do
		name="$stem-$number"
		number=$((number + 1))
	done
	echo "$name"
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

main "$@"
