const { docker } = require('../../../libs/docker');
const { execShellAsync } = require("../../../libs/common");
const ApplicationLog = require("../../../models/Logs/Application");
const Deployment = require("../../../models/Deployment");

module.exports = async function (fastify) {
  fastify.post("/", async (request, reply) => {
    const { organization, name, branch } = request.body
    let found = false
    await (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application').map(s => {
      const running = JSON.parse(s.Spec.Labels.configuration)
      if (
        running.repository.organization === organization &&
        running.repository.name === name &&
        running.repository.branch === branch
      ) {
        found = running
      }
    })
    if (found) {

      const deploys = await Deployment.find({ organization, branch, name })

      // const found = deploys.filter(d => d.progress !== 'done' && d.progress !== 'failed')
      // if (found.length > 0) {
      //   throw new Error('Deployment inprogress, cannot delete now.');
      // }

      for (const deploy of deploys) {
        await ApplicationLog.deleteMany({ deployId: deploy.deployId });
        await Deployment.deleteMany({ deployId: deploy.deployId });
      }
      await execShellAsync(`docker stack rm ${found.general.deployId}`)
      reply.code(200).send({ organization, name, branch })

    } else {
      reply.code(404).send({ error: "Nothing to do." })
    }
  })
};
