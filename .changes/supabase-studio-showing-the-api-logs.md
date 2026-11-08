---
type: fixed
commit: 8f7d1be7a9
---
Supabase Studio showing the API logs of another stack when two Supabase services run on one server ([#10324](https://github.com/coollabsio/coolify/issues/10324))

- The Vector container of the template reads only the logs of its own stack
- An existing service keeps its `vector.yml`, to fix it add `include_labels: ['com.docker.compose.project=${COOLIFY_RESOURCE_UUID}']` under `sources.docker_host` in `/data/coolify/services/<service-uuid>/volumes/logs/vector.yml` and restart the service
