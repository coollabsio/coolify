---
type: fixed
---
Wrapped lines at 80 characters and a jumping cursor in the terminal after a container switch or a new session in the same page ([#6611](https://github.com/coollabsio/coolify/issues/6611))

- Each new terminal session gets the size of the browser terminal
- A line feed from the server moves the cursor down without a return to the first column, as full-screen programs such as `vim` and `htop` expect
