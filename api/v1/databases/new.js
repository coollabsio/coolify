const yaml = require("js-yaml");
const { execShellAsync } = require("../../libs/common");

module.exports = async function (fastify) {
    const postSchema = {
        body: {
            type: "object",
            properties: {
                nane: { type: "string" },
            },
            required: ["name"],
        },
    };

    fastify.post("/",{ schema: postSchema }, async (request, reply) => {
        // TODO: Query for existing db with the same name
        reply.code(201).send({ message: "Added to the queue." });
        const { name } = request.body
        // TODO: Persistent volume!
        const network = fastify.config.DOCKER_NETWORK
        const generateEnvs = {
            MONGODB_ROOT_PASSWORD: '1234',
            MONGODB_USERNAME: '1234',
            MONGODB_PASSWORD: '1234',
            MONGODB_DATABASE: '1234',
        }
        const stack = {
            version: "3.8",
            services: {
                [name]: {
                    image: 'bitnami/mongodb:4.4',
                    networks: [`${network}`],
                    environment: generateEnvs,
                    deploy: {
                        replicas: 1,
                        update_config: {
                            parallelism: 0,
                            delay: "10s",
                            order: "start-first",
                        },
                        rollback_config: {
                            parallelism: 0,
                            delay: "10s",
                            order: "start-first",
                        },
                        labels: [
                            "managedBy=coolify",
                            "type=database",
                            `name=${name}`,
                        ],
                    },
                },
            },
            networks: {
                [`${network}`]: {
                    external: true,
                },
            },
        };
        await execShellAsync(
            `echo "${yaml.dump(stack)}" | docker stack deploy --prune -c - ${name}`
        );

    });
};
