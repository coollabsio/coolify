const schema = {
  type: 'object',
  required: [
    'DOMAIN',
    'EMAIL',
    'VITE_GITHUB_APP_CLIENTID',
    'GITHUB_APP_CLIENT_SECRET',
    'GITHUB_APP_PRIVATE_KEY',
    'GITHUP_APP_WEBHOOK_SECRET',
    'JWT_SIGN_KEY',
    'SECRETS_ENCRYPTION_KEY'
  ],
  properties: {
    DOMAIN: {
      type: 'string'
    },
    EMAIL: {
      type: 'string'
    },
    VITE_GITHUB_APP_CLIENTID: {
      type: 'string'
    },
    GITHUB_APP_CLIENT_SECRET: {
      type: 'string'
    },
    GITHUB_APP_PRIVATE_KEY: {
      type: 'string'
    },
    GITHUP_APP_WEBHOOK_SECRET: {
      type: 'string'
    },
    JWT_SIGN_KEY: {
      type: 'string'
    },
    DOCKER_ENGINE: {
      type: 'string',
      default: '/var/run/docker.sock'
    },
    DOCKER_NETWORK: {
      type: 'string',
      default: 'coollabs'
    },
    SECRETS_ENCRYPTION_KEY: {
      type: 'string'
    }
  }
}

module.exports = { schema }
