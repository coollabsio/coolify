const crypto = require('crypto');
const { verifyUserId } = require("../../../libs/common");
const Deployment = require('../../../models/Deployment')
const { queueAndBuild } = require("../../../libs/applications");
const { setDefaultConfiguration } = require("../../../libs/applications/configuration");
const { docker } = require('../../../libs/docker')

module.exports = async function (fastify) {
  // TODO: Add this to fastify plugin
  const postSchema = {
    body: {
      type: "object",
      properties: {
        ref: { type: "string" },
        repository: {
          type: "object",
          properties: {
            id: { type: "number" },
            full_name: { type: "string" },
          },
          required: ["id", "full_name"],
        },
        installation: {
          type: "object",
          properties: {
            id: { type: "number" },
          },
          required: ["id"],
        },
      },
      required: ["ref", "repository", "installation"],
    },
  };
  fastify.post("/", { schema: postSchema }, async (request, reply) => {
    const hmac = crypto.createHmac('sha256', fastify.config.GITHUP_APP_WEBHOOK_SECRET)
    const digest = Buffer.from('sha256=' + hmac.update(JSON.stringify(request.body)).digest('hex'), 'utf8')
    const checksum = Buffer.from(request.headers["x-hub-signature-256"], 'utf8')
    if (checksum.length !== digest.length || !crypto.timingSafeEqual(digest, checksum)) {
      reply.code(500).send({ error: "Invalid request" });
      return
    }

    if (request.headers["x-github-event"] !== "push") {
      reply.code(500).send({ error: "Not a push event." });
      return;
    }

    const services = await docker.engine.listServices()

    let configuration = await services.find(r => {
      if (r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application') {
        if (JSON.parse(r.Spec.Labels.configuration).repository.id === request.body.repository.id) {
          return r
        }
      }
    })

    if (!configuration) {
      reply.code(404).send({ error: "Nothing to do." })
      return
    }

    configuration = setDefaultConfiguration(JSON.parse(config.Spec.Labels.configuration))
    const { id, organization, name, branch } = configuration.repository
    const { domain } = configuration.publish
    const deployId = configuration.general.deployId

    const alreadyQueued = await Deployment.find({ repoId: id, branch, organization, name, domain, progress: { $in: ['queued', 'inprogress'] } })
    if (alreadyQueued.length > 0) {
      reply.code(200).send({ message: "Already in the queue." });
      return
    }

    queueAndBuild(configuration)
    reply.code(201).send({ message: "Deployment queued." });
  });
};
