---
type: fixed
---
Crash loop of the queue workers with high CPU when a queued deployment was deleted ([#12657](https://github.com/coollabsio/coolify/issues/12657))

- The workers now skip the deleted deployment
