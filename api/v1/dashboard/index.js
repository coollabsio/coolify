const Config = require("../../models/Config");
const Deployment = require("../../models/Deployment");
const Dockerode = require("dockerode");
module.exports = async function (fastify) {
    // TODO: Add this to fastify plugin
  const dockerEngine = new Dockerode({
    socketPath: fastify.config.DOCKER_ENGINE,
  });
  fastify.get("/", async (request, reply) => {
    let onlyConfigured = await Config.find().select('-__v -_id')
    const latestDeployments = await Deployment.aggregate([
      {
        $sort:{ updatedAt: -1 }
      },
      {
        $group:
        {
          _id: '$repoId',
          createdAt: { $last: '$updatedAt' },
          progress : { $first: '$progress' },
        }
      }
    ])
    const services = await dockerEngine.listServices()

    let deployedApplications = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
    let deployedDatabases = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'database')
    deployedApplications = deployedApplications.map(r => {

      onlyConfigured = onlyConfigured.filter(c => { 
        if (c.publish.domain !== r.Spec.Labels.domain) {
          let status = latestDeployments.find(l => r.Spec.Labels.repoId == l._id)
          if (status && status.progress) c.progress = status.progress
          return c
        }
      })
      let status = latestDeployments.find(l => r.Spec.Labels.repoId == l._id)
      if (status && status.progress) r.progress = status.progress
      return r
    })
    return {
      applications: {
        deployed: deployedApplications,
        onlyConfigured
      },
      databases: {
        deployed: deployedDatabases
      }
    }
  });
};
