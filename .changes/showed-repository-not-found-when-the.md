---
type: fixed
---
Showed `Repository not found` when the GitHub API rate limit of a GitHub App was exceeded, during the creation of an application from a private repository through the API ([#6296](https://github.com/coollabsio/coolify/issues/6296))

- The API returns 429 with the time when you can try again
- The repository list of a new private GitHub App resource and the MCP branch list also show the time when you can try again
