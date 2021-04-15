const { docker } = require('../../libs/docker')

module.exports = async function (fastify) {
  fastify.post('/', async (request, reply) => {
    const { name, organization, branch } = request.body
    const services = await docker.engine.listServices()
    const applications = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')

    const found = applications.find(r => {
      const configuration = r.Spec.Labels.configuration ? JSON.parse(r.Spec.Labels.configuration) : null
      if (branch) {
        if (configuration.repository.name === name && configuration.repository.organization === organization && configuration.repository.branch === branch) {
          return r
        }
      } else {
        if (configuration.repository.name === name && configuration.repository.organization === organization) {
          return r
        }
      }
      return null
    })
    if (found) {
      return JSON.parse(found.Spec.Labels.configuration)
    } else {
      reply.code(500).send({ message: 'No configuration found.' })
    }
  })
}
