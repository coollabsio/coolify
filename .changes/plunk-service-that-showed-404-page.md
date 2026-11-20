---
type: fixed
---
Plunk service that showed `404 page not found` or stopped after signup or login ([#5184](https://github.com/coollabsio/coolify/issues/5184))

- New Plunk services use Plunk v2 (`ghcr.io/useplunk/plunk`), because Plunk v1 is no longer maintained. The dashboard and the API get their own domains, and the AWS SES variables are now `AWS_SES_REGION`, `AWS_SES_ACCESS_KEY_ID`, and `AWS_SES_SECRET_ACCESS_KEY`. Existing Plunk services do not change
