---
type: added
---
A `Replicas` setting on the `Scaling` page of an application, to run up to 10 containers on one server

- The proxy balances the traffic between the replicas, and a deployment waits until every replica is healthy
- Replicas need one server, no port mappings, no consistent container name, and no custom IP address. A preview runs one container
- A Docker Compose application sets `deploy.replicas` or `scale` for a service in the Compose file, which Coolify rejected before
- The `replicas` field in the applications API sets it
