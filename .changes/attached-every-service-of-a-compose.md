---
type: fixed
---
Attached every service of a Compose stack to every top-level network, also to networks that only one service declares ([#12668](https://github.com/coollabsio/coolify/issues/12668))

- Each service joins only the networks it declares, plus the network of its stack
