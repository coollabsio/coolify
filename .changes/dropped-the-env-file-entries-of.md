---
type: fixed
---
Dropped the `env_file` entries of Docker Compose applications at deployment, so repository env files with default values did not reach the containers ([#2004](https://github.com/coollabsio/coolify/issues/2004))

- Coolify adds its `.env` file after the env files of each service, so Coolify variables override their values
- A relative `env_file` path is read from the directory of the Compose file
- Coolify skips an `env_file` that is not a file in the repository, such as a gitignored `.env.local`, and shows a warning in the deployment log
