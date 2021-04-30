const { docker } = require('../../../libs/docker')
const { execShellAsync, delay } = require('../../../libs/common')
const ApplicationLog = require('../../../models/Logs/Application')
const Deployment = require('../../../models/Deployment')
const { purgeImagesContainers } = require('../../../libs/applications/cleanup')

module.exports = async function (fastify) {
  fastify.post('/', async (request, reply) => {
    const { organization, name, branch } = request.body
    let found = false
    try {
      (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application').map(s => {
        const running = JSON.parse(s.Spec.Labels.configuration)
        if (running.repository.organization === organization &&
          running.repository.name === name &&
          running.repository.branch === branch) {
          found = running
        }
        return null
      })
      if (found) {
        const deploys = await Deployment.find({ organization, branch, name })
        for (const deploy of deploys) {
          await ApplicationLog.deleteMany({ deployId: deploy.deployId })
          await Deployment.deleteMany({ deployId: deploy.deployId })
        }
        await execShellAsync(`docker stack rm ${found.build.container.name}`)
        reply.code(200).send({ organization, name, branch })
        await delay(10000)
        await purgeImagesContainers(found, true)
      } else {
        reply.code(500).send({ message: 'Nothing to do.' })
      }
    } catch (error) {
      reply.code(500).send({ message: 'Nothing to do.' })
    }
  })
}
