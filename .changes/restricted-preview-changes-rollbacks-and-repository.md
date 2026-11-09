---
type: security
commit: c75e7078b7
---
Restricted preview changes, rollbacks and repository URL changes

- Opening a preview, changing its pull request URL, Git type or Docker tag, or choosing a commit through the API needs write access, a deploy token can only redeploy an existing preview
- A rollback goes only to a listed rollback image or a successful production deployment
- A new `git_repository` of an application is validated like in the UI
- The `docker_tag` of `/api/v1/deploy` is validated like in the preview API
