module.exports = async function (fastify, opts) {
  // Private routes
  fastify.register(async function (server) {
    if (process.env.NODE_ENV === 'production') server.register(require('./plugins/authentication'))
    server.register(require('./routes/v1/upgrade'), { prefix: '/upgrade' })
    server.register(require('./routes/v1/settings'), { prefix: '/settings' })
    server.register(require('./routes/v1/dashboard'), { prefix: '/dashboard' })
    server.register(require('./routes/v1/config'), { prefix: '/config' })
    // server.register(require("./routes/v1/secret"), { prefix: "/secret" });
    // server.register(require("./routes/v1/application"), { prefix: "/application" });
    server.register(require('./routes/v1/application/remove'), { prefix: '/application/remove' })
    server.register(require('./routes/v1/application/logs'), { prefix: '/application/logs' })
    server.register(require('./routes/v1/application/check'), { prefix: '/application/check' })
    server.register(require('./routes/v1/application/deploy'), { prefix: '/application/deploy' })
    server.register(require('./routes/v1/application/deploy/logs'), { prefix: '/application/deploy/logs' })
    // server.register(require("./routes/v1/applications/previews"), { prefix: "/applications/previews" });

    // Databases or database??????
    server.register(require('./routes/v1/databases'), { prefix: '/databases' })
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
