---
type: fixed
---
Lost lines of a multiline shared variable in the `.env` file when a variable saved before exact escaping referenced it ([#4595](https://github.com/coollabsio/coolify/issues/4595))

- Such a variable now gets a quoted line that Docker Compose reads in full
- The lines after the first no longer set other variables, for example a value `first` and `OTHER=x` on two lines. Add such variables as separate variables before you upgrade
