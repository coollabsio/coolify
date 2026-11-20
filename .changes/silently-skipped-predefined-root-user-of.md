---
type: fixed
---
Silently skipped predefined root user of the installer when its `ROOT_USER_*` values were not valid ([#5624](https://github.com/coollabsio/coolify/issues/5624))

- The installer shows why it could not create the root user, and a new run of the installer replaces the old `ROOT_USER_*` values. `ROOT_USER_EMAIL` no longer needs a mail DNS record, and `root:change-email` and `root:reset-password` tell you when there is no root user
- While `ROOT_USER_EMAIL` and `ROOT_USER_PASSWORD` are set, Coolify creates the root account only from these values, and the registration page tells you to run the installer again when they are not valid
