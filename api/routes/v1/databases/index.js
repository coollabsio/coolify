const yaml = require('js-yaml')
const fs = require('fs').promises
const cuid = require('cuid')
const { docker } = require('../../../libs/docker')
const { execShellAsync } = require('../../../libs/common')

const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator')
const generator = require('generate-password')

function getUniq () {
  return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 })
}
module.exports = async function (fastify) {
  fastify.get('/:deployId', async (request, reply) => {
    const { deployId } = request.params
    try {
      const database = (await docker.engine.listServices()).find(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'database' && JSON.parse(r.Spec.Labels.configuration).general.deployId === deployId)
      if (database) {
        const jsonEnvs = {}
        for (const d of database.Spec.TaskTemplate.ContainerSpec.Env) {
          const s = d.split('=')
          jsonEnvs[s[0]] = s[1]
        }
        const payload = {
          config: JSON.parse(database.Spec.Labels.configuration),
          envs: jsonEnvs
        }
        reply.code(200).send(payload)
      } else {
        throw new Error()
      }
    } catch (error) {
      throw new Error('No database found?')
    }
  })

  const postSchema = {
    body: {
      type: 'object',
      properties: {
        type: { type: 'string', enum: ['mongodb', 'postgresql', 'mysql', 'couchdb'] }
      },
      required: ['type']
    }
  }

  fastify.post('/deploy', { schema: postSchema }, async (request, reply) => {
    try {
      let { type, defaultDatabaseName } = request.body
      const passwords = generator.generateMultiple(2, {
        length: 24,
        numbers: true,
        strict: true
      })
      const usernames = generator.generateMultiple(2, {
        length: 10,
        numbers: true,
        strict: true
      })
      // TODO: Query for existing db with the same name
      const nickname = getUniq()

      if (!defaultDatabaseName) defaultDatabaseName = nickname

      reply.code(201).send({ message: 'Deploying.' })
      // TODO: Persistent volume, custom inputs
      const deployId = cuid()
      const configuration = {
        general: {
          workdir: `/tmp/${deployId}`,
          deployId,
          nickname,
          type
        },
        database: {
          usernames,
          passwords,
          defaultDatabaseName
        },
        deploy: {
          name: nickname
        }
      }
      let generateEnvs = {}
      let image = null
      let volume = null
      if (type === 'mongodb') {
        generateEnvs = {
          MONGODB_ROOT_PASSWORD: passwords[0],
          MONGODB_USERNAME: usernames[0],
          MONGODB_PASSWORD: passwords[1],
          MONGODB_DATABASE: defaultDatabaseName
        }
        image = 'bitnami/mongodb:4.4'
        volume = `${configuration.general.deployId}-${type}-data:/bitnami/mongodb`
      } else if (type === 'postgresql') {
        generateEnvs = {
          POSTGRESQL_PASSWORD: passwords[0],
          POSTGRESQL_USERNAME: usernames[0],
          POSTGRESQL_DATABASE: defaultDatabaseName
        }
        image = 'bitnami/postgresql:13.2.0'
        volume = `${configuration.general.deployId}-${type}-data:/bitnami/postgresql`
      } else if (type === 'couchdb') {
        generateEnvs = {
          COUCHDB_PASSWORD: passwords[0],
          COUCHDB_USER: usernames[0]
        }
        image = 'bitnami/couchdb:3'
        volume = `${configuration.general.deployId}-${type}-data:/bitnami/couchdb`
      } else if (type === 'mysql') {
        generateEnvs = {
          MYSQL_ROOT_PASSWORD: passwords[0],
          MYSQL_ROOT_USER: usernames[0],
          MYSQL_USER: usernames[1],
          MYSQL_PASSWORD: passwords[1],
          MYSQL_DATABASE: defaultDatabaseName
        }
        image = 'bitnami/mysql:8.0'
        volume = `${configuration.general.deployId}-${type}-data:/bitnami/mysql/data`
      }

      const stack = {
        version: '3.8',
        services: {
          [configuration.general.deployId]: {
            image,
            networks: [`${docker.network}`],
            environment: generateEnvs,
            volumes: [volume],
            deploy: {
              replicas: 1,
              update_config: {
                parallelism: 0,
                delay: '10s',
                order: 'start-first'
              },
              rollback_config: {
                parallelism: 0,
                delay: '10s',
                order: 'start-first'
              },
              labels: [
                'managedBy=coolify',
                'type=database',
                'configuration=' + JSON.stringify(configuration)
              ]
            }
          }
        },
        networks: {
          [`${docker.network}`]: {
            external: true
          }
        },
        volumes: {
          [`${configuration.general.deployId}-${type}-data`]: {
            external: true
          }
        }
      }
      await execShellAsync(`mkdir -p ${configuration.general.workdir}`)
      await fs.writeFile(`${configuration.general.workdir}/stack.yml`, yaml.dump(stack))
      await execShellAsync(
              `cat ${configuration.general.workdir}/stack.yml | docker stack deploy -c - ${configuration.general.deployId}`
      )
    } catch (error) {
      throw { error, type: 'server' }
    }
  })

  fastify.delete('/:dbName', async (request, reply) => {
    const { dbName } = request.params
    await execShellAsync(`docker stack rm ${dbName}`)
    reply.code(200).send({})
  })
}
