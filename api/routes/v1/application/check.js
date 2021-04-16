
const { setDefaultConfiguration } = require('../../../libs/applications/configuration')
const { docker } = require('../../../libs/docker')
const { saveServerLog } = require('../../../libs/logging')

module.exports = async function (fastify) {
  fastify.post('/', async (request, reply) => {
    try {
      const configuration = setDefaultConfiguration(request.body)

      const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
      let foundDomain = false

      for (const service of services) {
        const running = JSON.parse(service.Spec.Labels.configuration)
        if (running) {
          if (
            running.publish.domain === configuration.publish.domain &&
            running.repository.id !== configuration.repository.id &&
            running.publish.path === configuration.publish.path
          ) {
            foundDomain = true
          }
        }
      }
      if (fastify.config.DOMAIN === configuration.publish.domain) foundDomain = true
      if (foundDomain) {
        reply.code(500).send({ message: 'Domain already in use.' })
        return
      }
      return { message: 'OK' }
    } catch (error) {
      await saveServerLog(error)
      throw new Error(error)
    }
  })
}
