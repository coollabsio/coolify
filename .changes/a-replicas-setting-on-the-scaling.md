---
type: added
---
A `Replicas` setting on the `Scaling` page of an application, to run up to 20 containers on one server with Docker Compose replicas

- The proxy shares the traffic between the replicas, and a deployment waits until every replica is healthy
- Replicas need one server, no port mappings, no consistent container name, and no custom IP address. A preview runs one container
- A Docker Compose application can set `deploy.replicas` or `scale` for a service, which Coolify rejected before
- The applications API has a `replicas` field
