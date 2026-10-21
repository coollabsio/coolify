#!/usr/bin/env bash
# Add fake records around debian-root on A (tokens, DNS, secret manager link, tunnel token, GitHub App + runner config,
# resource links, canvas positions, team variable, maintenance mode, proxy error page, project icon, and an app of the
# same project on the other VM), transfer debian-root to B through the Transfer page, and check them on B and A.
# Run reset.sh before and after.
source "$(dirname "$0")/lib.sh"
S="$(vm_field debian-root uuid)"

echo '=== seed fake records on A'
php_file "$A" "$TT_DIR/seed-extras.php"
echo "=== transfer $S A -> B"
echo "status: $(transfer_from_page "$S")"

# The target compares the DNS owner comment, which contains the instance id, with the one of the source.
tinker "$A" 'echo config("app.id");' | tail -1 | docker exec -i "$B" sh -c 'cat > /tmp/source-app-id'

echo '=== target B'
b="$(php_file "$B" "$TT_DIR/verify-extras-target.php")"
echo "$b"
echo '=== source A'
a="$(php_file "$A" "$TT_DIR/verify-extras-source.php")"
echo "$a"
FAILS="$(printf '%s\n%s\n' "$a" "$b" | grep -c '^FAIL')"
echo "=== $FAILS failure(s)"
exit "$FAILS"
