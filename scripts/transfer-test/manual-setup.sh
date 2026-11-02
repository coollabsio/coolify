#!/usr/bin/env bash
# Prepare A and B for a manual transfer test: reset, then add the fake extra records of extras-e2e.sh to A
# (tokens, DNS, secret manager link, tunnel token, GitHub App + runner config, resource links, canvas positions,
# team variable, maintenance mode, proxy error page, project icon, a git app with a system-wide GitHub App, and an
# app of the same project on the other VM). Prints what to open and what to enter.
#
# Usage: scripts/transfer-test/manual-setup.sh
source "$(dirname "$0")/lib.sh"

"$TT_DIR/reset.sh" || die 'reset.sh failed'
echo '== add the fake extra records to A'
php_file "$A" "$TT_DIR/seed-extras.php"

cat <<EOF

Ready for a manual transfer test.

  A (source): $A_URL
  B (target): $B_URL
  Login on both: test@example.com / password

  Servers on A:
    $(vm_field ubuntu-non-root uuid)  $(vm_field ubuntu-non-root ip)  project "Transfer Demo": whoami, nginx-dockerfile, postgres, redis, uptime-kuma
    $(vm_field debian-root uuid)  $(vm_field debian-root ip)  project "Second Server": whoami-2, mariadb, git-shop, and all the fake extra records

  Transfer on A: open a server -> Transfer
    Target instance URL: http://localhost:$B_PORT
    Target API token:    $(target_token)
  Transfer back on A (archive -> Transfer):
    Instance that manages the server now: http://localhost:$B_PORT   (same token)
    URL of this instance:                 http://localhost:$A_PORT
  Token of A (for a transfer from B to A): $(source_token)

Reset again: scripts/transfer-test/manual-setup.sh
EOF
