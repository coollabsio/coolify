---
type: fixed
---
Ignored the order of the `Environment Variables` page in the generated `.env` file of a deployment, so a variable that referenced a variable listed above it could resolve to an empty value ([#12523](https://github.com/coollabsio/coolify/issues/12523))

- Without `Alphabetical` order, the deployment writes the variables in the order of the page, which you can change in the `Developer view`
