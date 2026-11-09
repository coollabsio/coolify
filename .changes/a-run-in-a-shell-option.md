---
type: added
commit: 31c3fcf50d
---
A `Run in a shell` option on scheduled tasks, so containers without `sh`, such as distroless or scratch images, can run them ([#8244](https://github.com/coollabsio/coolify/issues/8244))

- Turn it off to split the command into arguments and run it directly
- The API and the MCP tools set it too
