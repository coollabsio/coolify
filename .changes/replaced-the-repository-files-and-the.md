---
type: fixed
---
Replaced the repository files and the `.env` of the application with the files of a preview deployment, with `Preserve Repository` on

- Each preview has its own folder on the server, next to the folder of the application
- A preview mounts the files from its pull request, and deleting the preview removes its folder and files
