module.exports = async function (fastify, opts) {
  // Private routes
  fastify.register(async function (server) {
    server.register(require('./plugins/authentication'))
    server.register(require('./routes/v1/upgrade'), { prefix: '/upgrade' })
    server.register(require('./routes/v1/settings'), { prefix: '/settings' })
    server.register(require('./routes/v1/dashboard'), { prefix: '/dashboard' })
    server.register(require('./routes/v1/config'), { prefix: '/config' })
    server.register(require('./routes/v1/application/remove'), { prefix: '/application/remove' })
    server.register(require('./routes/v1/application/logs'), { prefix: '/application/logs' })
    server.register(require('./routes/v1/application/check'), { prefix: '/application/check' })
    server.register(require('./routes/v1/application/deploy'), { prefix: '/application/deploy' })
    server.register(require('./routes/v1/application/deploy/logs'), { prefix: '/application/deploy/logs' })
    server.register(require('./routes/v1/databases'), { prefix: '/databases' })
    server.register(require('./routes/v1/services'), { prefix: '/services' })
    server.register(require('./routes/v1/services/deploy'), { prefix: '/services/deploy' })
    server.register(require('./routes/v1/server'), { prefix: '/server' })
  })
  // Public routes
  fastify.register(require('./routes/v1/verify'), { prefix: '/verify' })
  fastify.register(require('./routes/v1/login/github'), {
    prefix: '/login/github'
  })
  fastify.register(require('./routes/v1/webhooks/deploy'), {
    prefix: '/webhooks/deploy'
  })
  fastify.register(require('./routes/v1/undead'), {
    prefix: '/undead'
  })
}
