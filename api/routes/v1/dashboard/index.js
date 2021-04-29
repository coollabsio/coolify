const { docker } = require('../../../libs/docker')
const Deployment = require('../../../models/Deployment')
const ServerLog = require('../../../models/Logs/Server')
const { saveServerLog } = require('../../../libs/logging')

module.exports = async function (fastify) {
  fastify.get('/', async (request, reply) => {
    try {
      const latestDeployments = await Deployment.aggregate([
        {
          $sort: { createdAt: -1 }
        },
        {
          $group:
          {
            _id: {
              repoId: '$repoId',
              branch: '$branch'
            },
            createdAt: { $last: '$createdAt' },
            progress: { $first: '$progress' }
          }
        }
      ])
      const serverLogs = await ServerLog.find()
      const dockerServices = await docker.engine.listServices()
      let applications = dockerServices.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application' && r.Spec.Labels.configuration)
      let databases = dockerServices.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'database' && r.Spec.Labels.configuration)
      let services = dockerServices.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'service' && r.Spec.Labels.configuration)
      applications = applications.map(r => {
        if (JSON.parse(r.Spec.Labels.configuration)) {
          return {
            configuration: JSON.parse(r.Spec.Labels.configuration),
            UpdatedAt: r.UpdatedAt
          }
        }
        return {}
      })
      databases = databases.map(r => {
        if (JSON.parse(r.Spec.Labels.configuration)) {
          return {
            configuration: JSON.parse(r.Spec.Labels.configuration)
          }
        }
        return {}
      })
      services = services.map(r => {
        if (JSON.parse(r.Spec.Labels.configuration)) {
          return {
            serviceName: r.Spec.Labels.serviceName,
            configuration: JSON.parse(r.Spec.Labels.configuration)
          }
        }
        return {}
      })
      applications = [...new Map(applications.map(item => [item.configuration.publish.domain + item.configuration.publish.path, item])).values()]
      return {
        serverLogs,
        applications: {
          deployed: applications
        },
        databases: {
          deployed: databases
        },
        services: {
          deployed: services
        }
      }
    } catch (error) {
      if (error.code === 'ENOENT' && error.errno === -2) {
        throw new Error(`Docker service unavailable at ${error.address}.`)
      } else {
        await saveServerLog(error)
        throw new Error(error)
      }
    }
  })
}
