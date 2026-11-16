---
type: fixed
---
Empty variable in a service of a stack on a cluster when another service of the stack gives its default, for templates such as `Authentik`

- A default such as `${VAR:-true}` in one service is the value of `${VAR}` in every service of the stack, as on a Docker server
