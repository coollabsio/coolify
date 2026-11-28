---
type: added
---
A `Load balancer` section on the `Scaling` page of an application, to choose how the proxy shares the requests between the replicas

- The strategies are `Round robin`, `Least connections`, `Fastest response`, and `Client IP`
- `Sticky sessions` keep each visitor on one replica with a cookie, which can have a custom name
- The applications API has the `load_balancer_strategy`, `is_sticky_session_enabled`, `sticky_session_cookie_name`, and `sticky_session_max_age` fields
