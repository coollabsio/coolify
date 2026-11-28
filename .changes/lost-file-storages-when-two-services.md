---
type: fixed
---
Lost file storages when two services of a Compose application mount different host files at the same container path

- Each host file has its own storage, with its own content and read-only state. A storage keeps its content when its host path changes in the Compose file
