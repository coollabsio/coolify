---
type: added
---
Owner UID, group GID, and mode of each secret file of a cluster application

- The `Edit environment variable` dialog shows `Owner UID`, `Group GID`, and `Mode` when `Delivery` is `File in /run/secrets`. Empty fields keep root and 0444
- A deploy with these fields set needs a server whose Sentinel supports them, else the deploy stops with the reason
