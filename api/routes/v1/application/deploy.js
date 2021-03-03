
const { verifyUserId } = require("../../../libs/common");
const Deployment = require('../../../models/Deployment')
const { queueAndBuild } = require("../../../libs/applications");
const { setDefaultConfiguration } = require("../../../libs/applications/configuration");
const { docker } = require('../../../libs/docker')

module.exports = async function (fastify) {
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
    fastify.post("/", async (request, reply) => {
        if (!await verifyUserId(request.headers.authorization)) {
            reply.code(500).send({ error: "Invalid request" });
            return
        }

        const configuration = setDefaultConfiguration(request.body)

        let found = false
        await (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application').map(s => {
            const running = JSON.parse(s.Spec.Labels.config)
            if (
                running.publish.domain === configuration.publish.domain &&
                running.repository.id !== configuration.repository.id &&
                running.repository.branch !== configuration.repository.branch
            ) {
                found = true
            }
        })

        if (found) {
            reply.code(409).send({})
            return
        }
        
        const alreadyQueued = await Deployment.find({ repoId: configuration.repository.id, branch: configuration.repository.branch, progress: { $in: ['queued', 'inprogress'] } })
        if (alreadyQueued.length > 0) {
            reply.code(200).send({ message: "Already in the queue." });
            return
        }

        queueAndBuild(configuration)

        reply.code(201).send({ message: "Deployment queued." });
    });
};
