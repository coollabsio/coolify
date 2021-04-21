const { execShellAsync } = require('../../../libs/common')
const { docker } = require('../../../libs/docker')

module.exports = async function (fastify) {
  fastify.get('/:serviceName', async (request, reply) => {
    const { serviceName } = request.params
    try {
      const service = (await docker.engine.listServices()).find(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'service' && r.Spec.Labels.serviceName === serviceName)

      if (service) {
        const payload = {
          config: JSON.parse(service.Spec.Labels.configuration)
        }
        reply.code(200).send(payload)
      } else {
        throw new Error()
      }
    } catch (error) {
      throw new Error('No service found?')
    }
  })
  fastify.delete('/:serviceName', async (request, reply) => {
    const { serviceName } = request.params
    await execShellAsync(`docker stack rm ${serviceName}`)
    reply.code(200).send({})
  })
}
