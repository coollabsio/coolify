---
type: fixed
---
Ignored environment variables in the host path of a short-syntax Compose volume, which made a named volume from `${DATA}/config:/config` and rejected a variable in the middle of the path ([#4222](https://github.com/coollabsio/coolify/issues/4222))

- The host path uses the value from `Environment Variables`, or the default of `${VAR:-default}` when the variable has no value
- A host path with a variable that has no value and no default stops the parse with an error that names the variable
- A storage that a resource had before keeps its saved host path, so its data does not move, and the deployment log shows a warning
- `Use the variable path` on the storage switches it to the path of the variable from the next deployment
