const yaml = require("js-yaml");
const fs = require('fs').promises

const { docker } = require('../../../libs/docker')
const { execShellAsync } = require("../../../libs/common");

const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator');
function getUniq() {
    return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 })
}
module.exports = async function (fastify) {
    fastify.get("/:dbName", async (request, reply) => {
        const { dbName } = request.params
        try {
            const database = (await docker.engine.listServices()).find(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'database' && JSON.parse(r.Spec.Labels.config).general.name === dbName)
            if (database) {
                const payload = {
                    config: JSON.parse(database.Spec.Labels.config),
                    envs: database.Spec.TaskTemplate.ContainerSpec.Env
                }
                reply.code(200).send(payload);
            } else {
                throw new Error()
            }
        } catch (error) {
            throw new Error('No database found?');
        }
    })

    const postSchema = {
        body: {
            type: "object",
            properties: {
                type: { type: "string", enum: ['mongodb', 'postgresql'] },
            },
            required: ["type"],
        },
    };

    fastify.post("/new", { schema: postSchema }, async (request, reply) => {
        // TODO: Query for existing db with the same name
        reply.code(201).send({ message: "Added to the queue." });
        const { type } = request.body
        // TODO: Persistent volume, custom inputs
        let name = getUniq()
        
        const config = {
            general: {
                workdir: '.',
                name,
                type
            },
            deploy: {
                name,
            }
        }

        const generateEnvs = {
            MONGODB_ROOT_PASSWORD: '1234',
            MONGODB_USERNAME: '1234',
            MONGODB_PASSWORD: '1234',
            MONGODB_DATABASE: '1234',
        }
        const stack = {
            version: "3.8",
            services: {
                [config.general.name]: {
                    image: 'bitnami/mongodb:4.4',
                    networks: [`${docker.network}`],
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
                            "config=" + JSON.stringify(config)
                        ],
                    },
                },
            },
            networks: {
                [`${docker.network}`]: {
                    external: true,
                },
            },
        };
        await fs.writeFile(`${config.general.workdir}/stack.yml`, yaml.dump(stack))
        // await execShellAsync(
        //     `echo "${yaml.dump(stack)}" | docker stack deploy -c - ${config.general.name}`
        // );
        await execShellAsync(
            `cat ${config.general.workdir}/stack.yml | docker stack deploy -c - ${config.general.name}`
        );


    });
};
