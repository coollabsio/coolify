---
type: fixed
commit: 780d67ccbb
---
Deleted volume coming back after a redeploy when the image declares `VOLUME` at its path ([#6875](https://github.com/coollabsio/coolify/issues/6875))

- Coolify removes containers that still mount a deleted volume before it starts the new ones
- The stale volume delete dialog warns when a container still uses the volume
