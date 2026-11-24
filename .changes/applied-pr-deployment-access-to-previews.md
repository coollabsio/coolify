---
type: fixed
---
Applied `PR deployment access` to previews deployed by hand while `Preview Deployments` were disabled ([#12644](https://github.com/coollabsio/coolify/issues/12644))

- Public PR deployments apply only while preview deployments are enabled
- A preview deployment that a webhook queued does not start when preview deployments are disabled before it starts
