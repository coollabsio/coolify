---
type: fixed
commit: d70801aaa1
---
Deployments that failed on one `502` or one timeout from the GitHub API ([#9785](https://github.com/coollabsio/coolify/issues/9785))

- GitHub API reads retry up to three times on a 5xx error or a failed connection
- Writes, such as pull request comments, are not retried
