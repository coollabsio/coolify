#!/usr/bin/env bash
# Write the portable snapshot of a server (see snapshot.php) on the source (A) or the target (B) to a file.
#
# Usage: scripts/transfer-test/snapshot.sh A|B <server uuid> <output file>
source "$(dirname "$0")/lib.sh"

[[ $# -eq 3 ]] || die 'Usage: snapshot.sh A|B <server uuid> <output file>'
C="$A"
[[ "$1" == B ]] && C="$B"
docker cp "$TT_DIR/snapshot.php" "$C:/tmp/snapshot.php" >/dev/null
docker exec -u www-data "$C" php artisan tinker --execute "\$snapshotServer = '$2'; \$snapshotOut = '/tmp/snapshot.json'; require '/tmp/snapshot.php';" 2>&1 | grep -v '^$'
docker cp "$C:/tmp/snapshot.json" "$3" >/dev/null
