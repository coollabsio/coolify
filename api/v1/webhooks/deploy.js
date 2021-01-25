const Dockerode = require("dockerode");
const getSecrets = require("../../libs/getSecret");
const getConfig = require("../../libs/getConfig");
const cloneRepository = require("../../libs/cloneRepository");
const generateConfig = require("../../libs/generateConfig");
const copyFiles = require("../../libs/copyFiles");
const buildContainer = require("../../libs/buildContainer");
const deploy = require("../../libs/deploy");
const cuid = require("cuid");
const Deploy = require('../../models/Deploy')

module.exports = async function (fastify) {
  const engine = new Dockerode({
    socketPath: fastify.config.DOCKER_ENGINE,
  });
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
    if (request.headers["x-github-event"] !== "push") {
      reply.code(500).send({ success: false, error: "Not a push event." });
      return;
    }
    const appId = request.headers["x-github-hook-installation-target-id"];
    const random = cuid();
    const ref = request.body.ref.split("/")
    let branch = null
    if (ref[1] === "heads") {
      branch = ref.slice(2).join('/')
    } else {
      return
    }

    /*     request.body.ref.split("/").length === 3 ||
        request.body.ref.split("/")[1] === "heads"
          ? request.body.ref.split("/")[2]
          : request.body.ref.split("/")[1], */
    const config = {
      repository: {
        installationId: request.body.installation.id,
        id: request.body.repository.id,
        name: request.body.repository.full_name,
        branch,
      },
      general: {
        random,
        workdir: `/tmp/${random}`,
      },
      build: {
        container: {
          name: "",
          tag: "",
        },
      },
      publish: {
        secrets: [],
      },
    };
    const repoId = config.repository.id
    const deployId = config.general.random
    const alreadyQueued = await Deploy.find({ repoId, branch, progress: { $ne: 'done' } })
    if (alreadyQueued.length > 0) {
      reply.code(200).send({ message: "Already queued." });
      return
    }
    reply.code(201).send({ message: "Added to building queue." });
    const newDeploy = new Deploy({
      repoId, branch, deployId
    })

    await newDeploy.save()

    try {
      await getSecrets(config);
      await getConfig(config);
      await cloneRepository(
        config,
        appId,
        fastify.config.GITHUB_APP_PRIVATE_KEY
      );
      await generateConfig(config);
      await copyFiles(config.general.workdir);
      await buildContainer(config, engine);
      await deploy(config, fastify.config.DOCKER_NETWORK);
    } catch (error) {
      console.log("webhook error");
      console.log(error);
    }
  });
};
