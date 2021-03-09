
const { verifyUserId, execShellAsync, cleanupTmp } = require("../../../libs/common");
const Deployment = require('../../../models/Deployment');
const { queueAndBuild } = require("../../../libs/applications");
const { setDefaultConfiguration } = require("../../../libs/applications/configuration");
const { docker } = require('../../../libs/docker');
const cloneRepository = require("../../../libs/applications/github/cloneRepository");
const { cleanup } = require('../../../libs/applications/cleanup')

module.exports = async function (fastify) {
    // const postSchema = {
    //     body: {
    //         type: "object",
    //         properties: {
    //             ref: { type: "string" },
    //             repository: {
    //                 type: "object",
    //                 properties: {
    //                     id: { type: "number" },
    //                     full_name: { type: "string" },
    //                 },
    //                 required: ["id", "full_name"],
    //             },
    //             installation: {
    //                 type: "object",
    //                 properties: {
    //                     id: { type: "number" },
    //                 },
    //                 required: ["id"],
    //             },
    //         },
    //         required: ["ref", "repository", "installation"],
    //     },
    // };
    fastify.post("/", async (request, reply) => {
        if (!await verifyUserId(request.headers.authorization)) {
            reply.code(500).send({ error: "Invalid request" });
            return
        }
   
        const configuration = setDefaultConfiguration(request.body)

        const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')

        await cloneRepository(configuration)

        let foundService = false
        let foundDomain = false;
        let configChanged = false;
        let imageChanged = false;

        for (const service of services) {
            const running = JSON.parse(service.Spec.Labels.configuration)
            if (running) {
                if (running.repository.id === configuration.repository.id && running.repository.branch === configuration.repository.branch) {
                    foundService = true
                    if (
                        running.publish.domain === configuration.publish.domain &&
                        running.repository.id !== configuration.repository.id &&
                        running.repository.branch !== configuration.repository.branch
                    ) {
                        foundDomain = true
                    }

                    let runningWithoutContainer = JSON.parse(JSON.stringify(running))
                    delete runningWithoutContainer.build.container
  
                    let configurationWithoutContainer = JSON.parse(JSON.stringify(configuration))
                    delete configurationWithoutContainer.build.container
                    console.log(running.build.container.tag, configuration.build.container.tag)
                    if (JSON.stringify(runningWithoutContainer.build) !== JSON.stringify(configurationWithoutContainer.build) || JSON.stringify(runningWithoutContainer.publish) !== JSON.stringify(configurationWithoutContainer.publish)) configChanged = true
                    if (running.build.container.tag !== configuration.build.container.tag) imageChanged = true
                }

            }
        }
        console.log({ foundService, imageChanged, configChanged })
        if (foundDomain) {
            cleanupTmp(configuration.general.workdir)
            reply.code(409).send({ message: "Domain already used." })
            return
        }
        if (foundService && !imageChanged && !configChanged) {
            cleanupTmp(configuration.general.workdir)

            reply.code(400).send({ message: "Nothing changed." })
            return
        }

        const alreadyQueued = await Deployment.find({
            repoId: configuration.repository.id,
            branch: configuration.repository.branch,
            organization: configuration.repository.organization,
            name: configuration.repository.name,
            domain: configuration.publish.domain,
            progress: { $in: ['queued', 'inprogress'] }
        })

        if (alreadyQueued.length > 0) {
            reply.code(200).send({ message: "Already in the queue." });
            return
        }


        queueAndBuild(configuration, services, configChanged, imageChanged)

        reply.code(201).send({ message: "Deployment queued.", nickname: configuration.general.nickname });
    });
};
