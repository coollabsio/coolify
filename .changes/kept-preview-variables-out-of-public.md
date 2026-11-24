---
type: security
---
Kept preview variables out of public previews also when their value only resolves to the production value, such as `{{team.KEY}}` and `{{ team.KEY }}` ([#12644](https://github.com/coollabsio/coolify/issues/12644))

- A public preview that used such a variable does not get it after the upgrade. Give the preview variable its own value
