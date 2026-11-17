---
type: fixed
---
Showed a service database as `Exited` while its container ran, when an older version had saved it as an application ([#5990](https://github.com/coollabsio/coolify/issues/5990))

- The parser uses one part for each Compose service. When a service has both an application and a database part with the same name, the database part is used, and the application part shows `Not in Compose file` so you can delete it. Redeploy the service once to show the correct status
