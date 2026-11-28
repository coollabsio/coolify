---
type: fixed
---
Kept forwarding a published port of a cluster application to a server whose container crashed or stopped, or that went offline

- The other servers of the cluster now forward the port to a server that runs the application
- Connections and UDP flows that reuse a source port also go to the new server
