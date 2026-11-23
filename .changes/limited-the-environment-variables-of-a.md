---
type: security
---
Limited the environment variables of a Docker Compose application or service to the containers that you select, instead of giving every container all variables ([#7655](https://github.com/coollabsio/coolify/issues/7655))

- Each Compose service gets its own env file. Select the services in the `Containers` setting of a variable, in the developer view with `# coolify: containers=app,db`, or with `compose_services` in the API. A variable without a selection still goes to all containers
