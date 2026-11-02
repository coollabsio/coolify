# Security Policy

## Supported Versions

Currently supported, maintained and updated versions:

| Version | Supported          | Support Status |
| ------- | ------------------ | -------------- |
| 4.x     | :white_check_mark: | Active Development & Security Updates |
| < 4.0   | :x:                | End of Life (no security updates) |

## Security Updates

We take security seriously. Security updates are released as soon as possible after a vulnerability is discovered and verified.

## Reporting a Vulnerability

If you discover a security vulnerability, please follow these steps:

1. **DO NOT** disclose the vulnerability publicly.
2. Send a detailed report to: `security@coollabs.io`.
3. Include in your report:
   - A description of the vulnerability
   - Steps to reproduce the issue
   - Potential impact

## Scope and Threat Model

Please read this section before you send a report. The severity of a report depends on the security boundary that it crosses.

### Roles and what they can already do

- **Team member**: read-only access to the resources of the team. A member must not see secrets or change anything.
- **Team admin / owner**: full control of the team's servers. An admin can open a terminal on these servers, deploy any Docker Compose file (including privileged containers and bind mounts of the host file system), run scheduled commands, and read all team secrets. An admin already has root access to every server of the team.
- **API tokens**: a token has the permissions of its abilities. A token with `write` or `root` ability is equal to a team admin.
- **Instance admin** (admin or owner of the root team): already controls the Coolify instance and the server it runs on.
- **Repository and Compose content**: Coolify runs what the configured repository, Dockerfile, or Compose file tells it to run. People who can change this content are trusted for the deployments that use it.

### In scope

- A user who is not logged in can read data, change data, or run code.
- A team member gets write access, runs commands, or reads secrets of their own team.
- A user of one team reads or changes resources of another team.
- A team user runs code inside the Coolify container or on the Coolify host, without being an instance admin.
- An API token does more than its abilities allow.
- A public webhook or callback can be used without a valid secret or signature.

### Low severity (still welcome)

Please still report these. They are often good findings, and we fix them. Because the attacker already has the same power through normal features, we rate them as low severity:

- Command injection, path traversal, file read or write, or container escape on a server that the attacker's own team manages, when the attack needs the admin or owner role (or a `write` token).
- Problems that need a malicious repository, Dockerfile, or Compose file that a team admin chose to deploy.
- Server-side requests that a team admin can already make from the team's own servers.

If you find a way to reach one of these with less privilege (for example, as a member, from another team, or without login), it is in scope with a higher severity.

### Out of scope

- Attacks that need the instance admin role.
- Reports based only on automated scanner output, without a working attack path.

### Severity

Rate the report with the real privilege that the attack needs. If an attack needs the admin role, use `PR:H`. Use `S:C` only when the impact reaches another team, the Coolify instance, or a server that the attacker does not manage.
