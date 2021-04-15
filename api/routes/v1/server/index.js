const Server = require('../../../models/Logs/Server')
module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    try {
      const serverLogs = await Server.find().select('-_id -__v')
      // TODO: Should do better
      return {
        serverLogs
      }
    } catch (error) {
      throw { error, type: 'server' }
    }
  })
}
