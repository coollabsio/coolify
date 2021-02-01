const schema = {
    type: "object",
    required: [
        "DOMAIN",
        "EMAIL",
        "VITE_GITHUB_APP_CLIENTID",
        "VITE_GITHUB_OAUTH_CLIENTID",
        "GITHUB_OAUTH_SECRET",
        "GITHUB_APP_CLIENT_SECRET",
        "GITHUB_APP_PRIVATE_KEY",
        "JWT_SIGN_KEY",
        "SECRETS_ENCRYPTION_KEY"
    ],
    properties: {
        DOMAIN: {
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
        GITHUB_APP_CLIENT_SECRET: {
            type: "string",
        },
        GITHUB_APP_PRIVATE_KEY: {
            type: "string",
        },
        JWT_SIGN_KEY: {
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