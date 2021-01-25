const Config = require("../../models/Config");
const Deploy = require("../../models/Deploy");
const Dockerode = require("dockerode");
module.exports = async function (fastify) {
  const dockerEngine = new Dockerode({
    socketPath: fastify.config.DOCKER_ENGINE,
  });
  fastify.get("/", async (request, reply) => {
    let configOnly = await Config.find()
    const deployment = await Deploy.find().sort({ createdAt: '-1' })
    const progress = deployment.map(r => {
      return {
        repoId: r.repoId,
        progress: r.progress
      }
    })
    const running = await (await dockerEngine.listServices()).filter(r => {
      if (r.Spec.Labels.managedBy === 'coolify') {
        const found = progress.find(p => p.repoId == r.Spec.Labels.repoId)
        if (found) {
          r.progress = found.progress
        }
        return r
      }
    })
    running.forEach(r => {
      configOnly = configOnly.filter(c => {
        if (c.publish.domain !== r.Spec.Labels.domain){
          const found = progress.find(p => p.repoId == c.repoId)
          if (found) {
            c.progress = found.progress
          }
          return c
        }
      })
    })

    return {
      running,
      configOnly
    }
  });
};
