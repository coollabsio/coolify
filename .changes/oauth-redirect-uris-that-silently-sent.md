---
type: fixed
commit: 3dc89cfe05
---
OAuth redirect URIs that silently sent the user back to the login page

- A redirect URI must point at the callback route of the provider, any other path gets a validation error
