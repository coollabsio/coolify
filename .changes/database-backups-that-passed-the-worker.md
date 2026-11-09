---
type: fixed
commit: 5c71067481
---
Database backups that passed the worker timeout, stayed running, and left the upload container and the dump file on the server

- Each step gets only the time that is left of the backup timeout, so a run that takes too long fails and cleans up
- An SSH retry does not start a second dump
- The backup log explains exit code 124, and the timeout help says that it covers the complete run
- The daily helper container cleanup removes the upload containers of executions that are not running
