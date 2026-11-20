---
type: fixed
---
Left `docker logs` reads running on the server after a dropped SSH connection, which kept Docker at high CPU

- Each log read on a server now stops after 30 seconds
