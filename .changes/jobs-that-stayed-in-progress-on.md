---
type: fixed
---
Jobs that stayed in progress on GitHub after their runner container stopped ([#12591](https://github.com/coollabsio/coolify/issues/12591))

- Coolify cancels the workflow run of the job and retries the removal of the runner. New GitHub Apps get the repository permission `Actions`: write; give it to an existing App so that Coolify can cancel the run
