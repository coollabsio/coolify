---
type: fixed
---
Mounted an empty host folder instead of the existing Docker volume after the upgrade to v4.7.7, when the variable of a short-syntax Compose volume such as `${DATA}/config:/config` has a host path value

- A volume that the resource had before stays in use while its entry exists in `Persistent Storage`, and the deployment log shows a warning. The entry shows the host path that the variable gives. To use that path, copy the data and delete the entry
