---
type: fixed
commit: b7a2c75739
---
Deployments that failed with `cURL error 28` on `https://api.github.com/zen`, because Coolify compared its clock with GitHub before each token request ([#9785](https://github.com/coollabsio/coolify/issues/9785))

- Coolify compares the clocks only when GitHub rejects a request, from the `Date` header of that response
