---
type: fixed
---
Inconsistent parsing of shared variable references

- Every part of Coolify reads `{{team.KEY}}` references the same way
- Environment variable and shared variable values with a NUL character are refused when you save them, because a container cannot start with one
