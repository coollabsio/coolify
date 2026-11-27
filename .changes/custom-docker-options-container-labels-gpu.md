---
type: added
---
Custom Docker Options, container labels, GPU, and pre- and post-deployment commands for cluster applications

- Coolify checks each Docker option on save and refuses a flag that servers of a cluster do not support. `--cap-add` and `--sysctl` take values from an allow list, and some of them need a team admin. A GPU needs `Allow GPU workloads` on the server
