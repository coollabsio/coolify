
const { verifyUserId, cleanupTmp, execShellAsync } = require('../../../../libs/common')
const Deployment = require('../../../../models/Deployment')
const { queueAndBuild } = require('../../../../libs/applications')
const { setDefaultConfiguration } = require('../../../../libs/applications/configuration')
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
      const configuration = setDefaultConfiguration(request.body)

      const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')

      await cloneRepository(configuration)

      let foundService = false
      let foundDomain = false
      let configChanged = false
      let imageChanged = false

      let forceUpdate = false

      for (const service of services) {
        const running = JSON.parse(service.Spec.Labels.configuration)
        if (running) {
          if (
            running.publish.domain === configuration.publish.domain &&
            running.repository.id !== configuration.repository.id
          ) {
            foundDomain = true
          }
          if (running.repository.id === configuration.repository.id && running.repository.branch === configuration.repository.branch) {
            // Base service configuration changed
            if (!running.build.container.baseSHA || running.build.container.baseSHA !== configuration.build.container.baseSHA) {
              configChanged = true
            }
            const state = await execShellAsync(`docker stack ps ${running.build.container.name} --format '{{ json . }}'`)
            const isError = state.split('\n').filter(n => n).map(s => JSON.parse(s)).filter(n => n.DesiredState !== 'Running')
            if (isError.length > 0) forceUpdate = true

            foundService = true
            const runningWithoutContainer = JSON.parse(JSON.stringify(running))
            delete runningWithoutContainer.build.container

            const configurationWithoutContainer = JSON.parse(JSON.stringify(configuration))
            delete configurationWithoutContainer.build.container

            // If only the configuration changed
            if (JSON.stringify(runningWithoutContainer.build) !== JSON.stringify(configurationWithoutContainer.build) || JSON.stringify(runningWithoutContainer.publish) !== JSON.stringify(configurationWithoutContainer.publish)) configChanged = true
            // If only the image changed
            if (running.build.container.tag !== configuration.build.container.tag) imageChanged = true
            // If build pack changed, forceUpdate the service
            if (running.build.pack !== configuration.build.pack) forceUpdate = true
          }
        }
      }
      if (foundDomain) {
        cleanupTmp(configuration.general.workdir)
        reply.code(500).send({ message: 'Domain already in use.' })
        return
      }
      if (forceUpdate) {
        imageChanged = false
        configChanged = false
      } else {
        if (foundService && !imageChanged && !configChanged) {
          cleanupTmp(configuration.general.workdir)
          reply.code(500).send({ message: 'Nothing changed, no need to redeploy.' })
          return
        }
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

      queueAndBuild(configuration, configChanged, imageChanged)

      reply.code(201).send({ message: 'Deployment queued.', nickname: configuration.general.nickname, name: configuration.build.container.name })
    } catch (error) {
      throw { error, type: 'server' }
    }
  })
}
