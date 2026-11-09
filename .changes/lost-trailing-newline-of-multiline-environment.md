---
type: fixed
commit: 52a78cdb0f
---
Lost trailing newline of multiline environment variables, so OpenSSH rejected an SSH private key with "error in libcrypto" ([#9438](https://github.com/coollabsio/coolify/issues/9438))

- A value with more than one line is saved exactly as you enter it, in the UI and in the API
- Single-line values still lose their edge whitespace
- Save a variable again to add the newline that Coolify removed
