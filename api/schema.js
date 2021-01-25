const schema = {
    type: "object",
    required: [
        "DOMAIN",
        "SUBDOMAIN",
        "EMAIL",
        "VITE_GITHUB_APP_CLIENTID",
        "VITE_GITHUB_OAUTH_CLIENTID",
        "GITHUB_OAUTH_SECRET",
        "GITHUB_APP_CLIENTSECRET",
        "GITHUB_APP_PRIVATE_KEY",
        "JWT_SIGNKEY",
        "SECRETS_ENCRYPTION_KEY"
    ],
    properties: {
        DOMAIN: {
            type: "string",
        },
        SUBDOMAIN: {
            type: "string",
        },
        EMAIL: {
            type: "string",
        },
        VITE_GITHUB_APP_CLIENTID: {
            type: "string",
        },
        VITE_GITHUB_OAUTH_CLIENTID: {
            type: "string",
        },
        GITHUB_OAUTH_SECRET: {
            type: "string",
        },
        GITHUB_APP_CLIENTSECRET: {
            type: "string",
        },
        GITHUB_APP_PRIVATE_KEY: {
            type: "string",
        },
        JWT_SIGNKEY: {
            type: "string",
        },
        DOCKER_ENGINE: {
            type: "string",
            default: "/var/run/docker.sock"
        },
        DOCKER_NETWORK: {
            type: "string",
            default: "coollabs"
        },
        SECRETS_ENCRYPTION_KEY: {
            type: "string",
        },
    },
};

module.exports = { schema }