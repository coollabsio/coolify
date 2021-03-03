const Dockerode = require("dockerode");
const { queueAndBuild } = require("../../../libs/applications");
const dockerEngine = new Dockerode({
    socketPath: process.env.DOCKER_ENGINE,
});
module.exports = async function (fastify) {
    const getLogSchema = {
        querystring: {
            type: "object",
            properties: {
                repo: { type: "string" },
                org: { type: "string" },
            },
            required: ["repo", "org"],
        },
    };
    fastify.get("/", async (request, reply) => {
        const { org,repo } = request.query;
        const services = await dockerEngine.listServices()
        const previews = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application' && JSON.parse(r.Spec.Labels.config).publish.previewDomain && JSON.parse(r.Spec.Labels.config).repository.name === `${org}/${repo}` && (JSON.parse(r.Spec.Labels.config).publish.previewDomain!== JSON.parse(r.Spec.Labels.config).publish.domain))

        try {
            return previews
        } catch (e) {
            throw new Error('No logs found');
        }
    });
    fastify.post("/toprod", async (request, reply) => {
        let {config } = request.body;
        config = JSON.parse(config)
        config.publish.previewDomain = "analytics"
        config.previewDeploy = false
        console.log(config)
        // await queueAndBuild(config)

        // const services = await dockerEngine.listServices()
        // const previews = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application' && r.Spec.Labels.previewDomain && r.Spec.Labels.org === org && r.Spec.Labels.repo === repo && (r.Spec.Labels.previewDomain!== r.Spec.Labels.domain))
        reply.code(201).send({ message: "Deployment queued." });
        // try {
        //     return previews
        // } catch (e) {
        //     throw new Error('No logs found');
        // }
    });
};
