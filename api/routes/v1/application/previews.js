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
        const previews = services.filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application' && JSON.parse(r.Spec.Labels.configuration).publish.previewDomain && JSON.parse(r.Spec.Labels.configuration).repository.name === `${org}/${repo}` && (JSON.parse(r.Spec.Labels.configuration).publish.previewDomain!== JSON.parse(r.Spec.Labels.configuration).publish.domain))

        try {
            return previews
        } catch (e) {
            throw new Error('No logs found');
        }
    });
    fastify.post("/toprod", async (request, reply) => {
        let {config } = request.body;
        configuration = JSON.parse(configuration)
        configuration.publish.previewDomain = "analytics"
        configuration.previewDeploy = false
        console.log(configuration)
        // await queueAndBuild(configuration)

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
