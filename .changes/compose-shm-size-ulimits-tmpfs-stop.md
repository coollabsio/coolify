---
type: added
---
Compose `shm_size`, `ulimits`, `tmpfs`, `stop_grace_period`, `tty`, `stdin_open`, and `deploy.resources` for stacks on clusters

- Each key needs a Sentinel that reports `workload.runtime_options.v1` on every server of the cluster, except `deploy.resources`, which sets the CPU and memory of the service. A value on the `Resource Limits` page has priority. `stop_grace_period` is also the stop timeout of a stop, a restart, and a remove
