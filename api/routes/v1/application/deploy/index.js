
const { verifyUserId, cleanupTmp } = require('../../../../libs/common')
const Deployment = require('../../../../models/Deployment')
const { queueAndBuild } = require('../../../../libs/applications')
const { setDefaultConfiguration, precheckDeployment } = require('../../../../libs/applications/configuration')
const { docker } = require('../../../../libs/docker')
const cloneRepository = require('../../../../libs/applications/github/cloneRepository')

module.exports = async function (fastify) {
  // const postSchema = {
  //     body: {
  //         type: "object",
  //         properties: {
  //             ref: { type: "string" },
  //             repository: {
  //                 type: "object",
  //                 properties: {
  //                     id: { type: "number" },
  //                     full_name: { type: "string" },
  //                 },
  //                 required: ["id", "full_name"],
  //             },
  //             installation: {
  //                 type: "object",
  //                 properties: {
  //                     id: { type: "number" },
  //                 },
  //                 required: ["id"],
  //             },
  //         },
  //         required: ["ref", "repository", "installation"],
  //     },
  // };
  fastify.post('/', async (request, reply) => {
    try {
      await verifyUserId(request.headers.authorization)
    } catch (error) {
      reply.code(500).send({ error: 'Invalid request' })
      return
    }
    try {
      const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
      const configuration = setDefaultConfiguration(request.body)
      await cloneRepository(configuration)
      const { foundService, imageChanged, configChanged, forceUpdate } = await precheckDeployment({ services, configuration })

      if (foundService && !forceUpdate && !imageChanged && !configChanged) {
        cleanupTmp(configuration.general.workdir)
        reply.code(500).send({ message: 'Nothing changed, no need to redeploy.' })
        return
      }

      const alreadyQueued = await Deployment.find({
        repoId: configuration.repository.id,
        branch: configuration.repository.branch,
        organization: configuration.repository.organization,
        name: configuration.repository.name,
        domain: configuration.publish.domain,
        progress: { $in: ['queued', 'inprogress'] }
      })

      if (alreadyQueued.length > 0) {
        reply.code(200).send({ message: 'Already in the queue.' })
        return
      }

      queueAndBuild(configuration, imageChanged)

      reply.code(201).send({ message: 'Deployment queued.', nickname: configuration.general.nickname, name: configuration.build.container.name })
    } catch (error) {
      throw { error, type: 'server' }
    }
  })
}
