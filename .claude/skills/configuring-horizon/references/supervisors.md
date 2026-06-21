# Supervisor & Balancing Configuration

## Where to Find It

Search with `search-docs` before writing any supervisor config, as option names and defaults change between Horizon versions:
- `"horizon supervisor configuration"` for the full options list
- `"horizon balancing strategies"` for auto, simple, and false modes
- `"horizon autoscaling workers"` for autoScalingStrategy details
- `"horizon environment configuration"` for the defaults and environments merge

## What to Watch For

### The `environments` array merges into `defaults` rather than replacing it

The `defaults` array defines the complete base supervisor config. The `environments` array patches it per environment, overriding only the keys listed. There is no need to repeat every key in each environment block. A common pattern is to define `connection`, `queue`, `balance`, `autoScalingStrategy`, `tries`, and `timeout` in `defaults`, then override only `maxProcesses`, `balanceMaxShift`, and `balanceCooldown` in `production`.

### Use separate named supervisors to enforce queue priority

Horizon does not enforce queue order when using `balance: auto` on a single supervisor. The `queue` array order is ignored for load balancing. To process `notifications` before `default`, use two separately named supervisors: one for the high-priority queue with a higher `maxProcesses`, and one for the low-priority queue with a lower cap. The docs include an explicit note about this.

### Use `balance: false` to keep a fixed number of workers on a dedicated queue

Auto-balancing suits variable load, but if a queue should always have exactly N workers such as a video-processing queue limited to 2, set `balance: false` and `maxProcesses: 2`. Auto-balancing would scale it up during bursts, which may be undesirable.

### Set `balanceCooldown` to prevent rapid worker scaling under bursty load

When using `balance: auto`, the supervisor can scale up and down rapidly under bursty load. Set `balanceCooldown` to the number of seconds between scaling decisions, typically 3 to 5, to smooth this out. `balanceMaxShift` limits how many processes are added or removed per cycle.