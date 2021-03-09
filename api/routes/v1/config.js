const { docker } = require('../../libs/docker')

module.exports = async function (fastify) {

  const getConfig = {
    querystring: {
      type: "object",
      properties: {
        repoId: { type: "number" },
        branch: { type: "string" },
      },
      required: ["repoId", "branch"],
    },
  };

  const saveConfig = {
    body: {
      type: "object",
      properties: {
        build: {
          type: "object",
          properties: {
            baseDir: { type: "string" },
            installCmd: { type: "string" },
            buildCmd: { type: "string" },
          },
          required: ["baseDir", "installCmd", "buildCmd"],
        },
        publish: {
          type: "object",
          properties: {
            publishDir: { type: "string" },
            domain: { type: "string" },
            pathPrefix: { type: "string" },
            port: { type: "number" },
          },
          required: ["publishDir", "domain", "pathPrefix", "port"],
        },
        previewDeploy: { type: "boolean" },
        branch: { type: "string" },
        repoId: { type: "number" },
        buildPack: { type: "string" },
        fullName: { type: "string" },
        installationId: { type: "number" },
      },
      required: ["build", "publish", "previewDeploy", "branch", "repoId", "buildPack", "fullName", "installationId"],
    },
  };

  // fastify.get("/all", async (request, reply) => {
  //   return await Config.find().select("-_id -__v");
  // });

  // fastify.get("/", { schema: getConfig }, async (request, reply) => {
  //   const { repoId, branch } = request.query;
  //   return await Config.findOne({ repoId, branch }).select("-_id -__v");
  // });

  fastify.post("/", async (request, reply) => {
    const { name, organization, branch } = request.body;
    const services = await docker.engine.listServices()
    const applications = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')

    const found = applications.find(r => {
      const configuration = r.Spec.Labels.configuration ? JSON.parse(r.Spec.Labels.configuration) : null
      if (branch) {
        if (configuration.repository.name === name && configuration.repository.organization === organization && configuration.repository.branch === branch) {
          return r
        }
      } else {
        if (configuration.repository.name === name && configuration.repository.organization === organization) {
          return r
        }
      }

    })
    if (found) {
      return JSON.parse(found.Spec.Labels.configuration);
    } else {
      reply.code(404).send({message: 'No configuration found.'})
    }

  });

  // fastify.delete("/", async (request, reply) => {
  //   const { repoId, branch } = request.body;

  //   const deploys = await Deployment.find({ repoId, branch })
  //   const found = deploys.filter(d => d.progress !== 'done' && d.progress !== 'failed')
  //   if (found.length > 0) {
  //     throw new Error('Deployment inprogress, cannot delete now.');
  //   }

  //   const config = await Config.findOneAndDelete({ repoId, branch })
  //   for (const deploy of deploys) {
  //     await ApplicationLog.findOneAndRemove({ deployId: deploy.deployId });
  //   }
  //   const secrets = await Secret.find({ repoId, branch });
  //   for (const secret of secrets) {
  //     await Secret.findByIdAndRemove(secret._id);
  //   }
  //   await execShellAsync(`docker stack rm ${config.containerName}`);
  //   return { message: 'Deleted application and related configurations.' };
  // });
};
