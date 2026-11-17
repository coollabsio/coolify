---
type: added
---
REST API for cluster applications, cluster databases, cluster stacks, clusters, and Nodes

- The endpoints are in the API reference under `Cluster Applications`, `Cluster Databases`, `Cluster Stacks`, and `Clusters`. A token of a member works only on the resources of the access grants of the member
- The volumes, the volume backups, and the scheduled tasks of cluster applications, cluster databases, and the services of cluster stacks have their own endpoints. A restore stops the workload, replaces the data, and starts it again
- A team admin can create and delete clusters, change the cluster settings, add and remove servers, manage the firewall rules, reconcile and repair the network, and upgrade Sentinel of a cluster, and delete a Node
