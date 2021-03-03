const { docker } = require('../../../libs/docker')
const Deployment = require("../../../models/Deployment");
const ServerLog = require("../../../models/ServerLog");

module.exports = async function (fastify) {
  fastify.get("/", async (request, reply) => {
    let underDeployment = await Deployment.find({ progress: { $in: ['queued', 'inprogress'] } })
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

    let serverLogs = await ServerLog.find()
    const services = await docker.engine.listServices()

    let applications = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
    let databases = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'database')

    applications = applications.map(r => {
      const config = r.Spec.Labels.config ? JSON.parse(r.Spec.Labels.config) : null

      let status = latestDeployments.find(l => config.repository.id === l._id.repoId && config.repository.branch === l._id.branch)
      if (status && status.progress) r.progress = status.progress
      r.Spec.Labels.config = config
      return r
    })
    databases = databases.map(r => {
      const config = r.Spec.Labels.config ? JSON.parse(r.Spec.Labels.config) : null

      r.Spec.Labels.config = config
      return r
    })
    applications = [...new Map(applications.map(item => [item.Spec.Labels.config.publish.domain, item])).values()];
    return {
      serverLogs,
      applications: {
        deployed: applications,
        underDeployment
      },
      databases: {
        deployed: databases
      }
    }
  });
};
