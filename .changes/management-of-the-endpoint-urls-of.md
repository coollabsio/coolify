---
type: changed
---
Management of the endpoint URLs of Garage, MinIO and Logto on the `Domains` page instead of in their environment variables

- Each domain has an `Endpoint` that sets its internal port, and Coolify writes the first domain of each endpoint to its environment variable
