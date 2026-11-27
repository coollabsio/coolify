---
type: maintenance
---
Replaced npm and bun with aube as the package manager, to protect the builds of Coolify against supply chain attacks

- `aube-lock.yaml` replaces `package-lock.json` and `bun.lock`
- A new version of a dependency installs 3 days after its release at the earliest
- Every install checks the dependencies for malware in the OSV advisories, and fails when the check cannot run
- The Socket security scanner checks every dependency that is added or updated
- Only `node-pty` runs its install script, an install fails on the install script of any other dependency
- An install fails on a version with weaker publishing evidence than an earlier version, such as lost provenance, and on a package without an integrity hash
- The images install the dependencies in the `peakimages/aube` image, and the terminal server runs on Node.js 26 instead of 24
