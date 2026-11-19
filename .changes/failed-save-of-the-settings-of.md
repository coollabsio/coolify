---
type: fixed
---
Failed save of the settings of a service part built with `build` in the compose file, because the empty `Image` field was required ([#4722](https://github.com/coollabsio/coolify/issues/4722))

- Saving such a part keeps the compose file without an `image` key
