const Config = require("../../models/Config");
const Deployment = require("../../models/Deployment");
const Dockerode = require("dockerode");
module.exports = async function (fastify) {
  const dockerEngine = new Dockerode({
    socketPath: fastify.config.DOCKER_ENGINE,
  });
  fastify.get("/", async (request, reply) => {
    let configOnly = await Config.find()
    // const latestDeployments = await Deployment.find().sort({ createdAt: '-1' })
    // const progress = latestDeployments.map(r => {
    //   return {
    //     repoId: r.repoId,
    //     progress: r.progress
    //   }
    // })
  
    const running = await (await dockerEngine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify')
    running.forEach(r => {
      configOnly = configOnly.filter(c => c.publish.domain !== r.Spec.Labels.domain)
    })

    return {
      running,
      configOnly
    }
  });
};
