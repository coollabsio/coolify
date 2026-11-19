---
type: fixed
---
Removed leading and trailing spaces from environment variable values ([#4734](https://github.com/coollabsio/coolify/issues/4734))

- Spaces and tabs are kept, and the `Value` field shows a warning when the value starts or ends with a space. Only the line breaks around a single-line value are removed. In the `Developer view`, put a value in quotes to keep its spaces
