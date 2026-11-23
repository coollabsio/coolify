---
type: fixed
---
Replaced the repository files and the `.env` of the application with the files of a preview deployment, with `Preserve Repository` on ([#5084](https://github.com/coollabsio/coolify/issues/5084))

- Each preview has its own folder on the server, next to the folder of the application
- A preview mounts the files from its pull request, and deleting the preview removes its folder and files
- Deleting the application also removes the folders of its previews
