---
type: fixed
---
Long 503 errors during a rolling update when the proxy labels of the application changed

- The old container stops at once, without the stop grace period, because the proxy does not route to the application while both containers run
