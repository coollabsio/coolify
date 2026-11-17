---
type: added
---
Image cleanup of cluster servers, with a daily run, a disk threshold, and a `Clean up now` button on the server page

- The cleanup keeps the deployed revision and the 2 revisions before it of each application, the last 3 builds of each application on a builder server, and the infrastructure images
- The journal of a cluster server keeps at most 500 MB
- A rollback to a kept revision starts the image on the server, or copies it from a server that keeps it
