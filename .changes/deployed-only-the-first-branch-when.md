---
type: fixed
---
Deployed only the first branch when one push to Bitbucket updated several branches ([#12524](https://github.com/coollabsio/coolify/issues/12524))

- Each application on a pushed branch deploys the commit of its branch, and `[skip ci]` / `[skip cd]` skips only the branch whose commits contain it
