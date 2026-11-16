---
type: added
---
Image cleanup of cluster servers, with a daily run, a disk threshold, and a `Clean up now` button on the server page

- The cleanup keeps the deployed revision and the 2 revisions before it of each application, and the infrastructure images
- The journal of a cluster server keeps at most 500 MB
- A rollback to a kept revision starts the image on the server without the cluster registry
