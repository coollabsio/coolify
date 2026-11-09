---
type: fixed
commit: d09480973e
---
Docker Compose previews sharing one project with the application and the other previews, so Docker Compose treated their containers as orphans ([#9014](https://github.com/coollabsio/coolify/issues/9014))

- Each preview has its own Compose project, and the networks that the Compose file declares are not shared
