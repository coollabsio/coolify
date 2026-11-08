---
type: security
commit: cdc45e5736
---
Hardened SMTP and terminal connections

- SMTP hosts are validated, and Coolify connects only to the checked address
- The error message explains why an SMTP host is refused
