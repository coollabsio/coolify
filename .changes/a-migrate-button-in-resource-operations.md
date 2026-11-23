---
type: added
---
A `Migrate` button in `Resource Operations` that copies an application or service on an old Compose parser to the latest parser

- The copy is on the same server and in the same environment and gets the volume data of the original, which stops while Coolify copies the data and then starts again
- An application on an old Compose parser shows the migration in `Recommendations` on its `General` page, and a service shows a warning on its `General` page
- The copy keeps the data of bind-mounted files and directories, and the copy of a service keeps its domains
