const Config = require("../../models/Config");
const Deployment = require("../../models/Deployment");
const Dockerode = require("dockerode");
module.exports = async function (fastify) {
  const dockerEngine = new Dockerode({
    socketPath: fastify.config.DOCKER_ENGINE,
  });
  fastify.get("/", async (request, reply) => {
    let configOnly = await Config.find().select('-__v -_id')
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

    let running = await (await dockerEngine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify')
    running = running.map(r => {
      configOnly = configOnly.filter(c => { 
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
      running,
      configOnly
    }
  });
};
