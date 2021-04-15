const Settings = require('../../../models/Settings')
module.exports = async function (fastify) {
  const applicationName = 'coolify'
  const postSchema = {
    body: {
      type: 'object',
      properties: {
        allowRegistration: { type: 'boolean' }
      },
      required: ['allowRegistration']
    }
  }

  fastify.get('/', async (request, reply) => {
    try {
      let settings = await Settings.findOne({ applicationName }).select('-_id -__v')
      // TODO: Should do better
      if (!settings) {
        settings = {
          applicationName,
          allowRegistration: false
        }
      }
      return {
        settings
      }
    } catch (error) {
      throw { error, type: 'server' }
    }
  })

  fastify.post('/', { schema: postSchema }, async (request, reply) => {
    try {
      const settings = await Settings.findOneAndUpdate(
        { applicationName },
        { applicationName, ...request.body },
        { upsert: true, new: true }
      ).select('-_id -__v')
      reply.code(201).send({ settings })
    } catch (error) {
      throw { error, type: 'server' }
    }
  })
}
