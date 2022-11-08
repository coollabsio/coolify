### Gitpod

#### Option 1 - Prefered:

Follow the same steps as [container based development flow](./Container.md)

#### Option 2 - Manual setup:

1. Create a workspace from this repository, 
1. run `pnpm install && pnpm db:push && pnpm db:seed` 
1. and then `pnpm dev`. 

All the required dependencies and packages has been configured for you already.

---

> Some packages, just `pack` are not installed in this way. 
  You cannot test all the features. 
  Please use the [container based development flow](./Container.md).