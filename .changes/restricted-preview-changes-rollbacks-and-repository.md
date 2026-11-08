---
type: security
commit: c75e7078b7
---
Restricted preview changes, rollbacks and repository URL changes

- Opening a preview or changing its pull request URL, Git type or Docker tag through the API needs write access, a deploy token can only redeploy an existing preview
- A rollback goes only to a listed rollback image or a successful production deployment
- A new `git_repository` of an application is validated like in the UI
