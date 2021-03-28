const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator')
const cuid = require('cuid')
const crypto = require('crypto')

const { execShellAsync } = require('../common')

function getUniq () {
  return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 })
}

function setDefaultConfiguration (configuration) {
  try {
    const nickname = getUniq()
    const deployId = cuid()

    const shaBase = JSON.stringify({ repository: configuration.repository })
    const sha256 = crypto.createHash('sha256').update(shaBase).digest('hex')

    const baseServiceConfiguration = {
      replicas: 1,
      restart_policy: {
        condition: 'any',
        max_attempts: 3
      },
      update_config: {
        parallelism: 1,
        delay: '10s',
        order: 'start-first'
      },
      rollback_config: {
        parallelism: 1,
        delay: '10s',
        order: 'start-first'
      }
    }

    configuration.build.container.name = sha256.slice(0, 15)

    configuration.general.nickname = nickname
    configuration.general.deployId = deployId
    configuration.general.workdir = `/tmp/${deployId}`

    if (!configuration.publish.path) configuration.publish.path = '/'
    if (!configuration.publish.port) configuration.publish.port = configuration.build.pack === 'static' ? 80 : 3000

    if (configuration.build.pack === 'static') {
      if (!configuration.build.command.installation) configuration.build.command.installation = 'yarn install'
      if (!configuration.build.directory) configuration.build.directory = '/'
    }

    if (configuration.build.pack === 'nodejs') {
      if (!configuration.build.command.installation) configuration.build.command.installation = 'yarn install'
      if (!configuration.build.directory) configuration.build.directory = '/'
    }

    configuration.build.container.baseSHA = crypto.createHash('sha256').update(JSON.stringify(baseServiceConfiguration)).digest('hex')
    configuration.baseServiceConfiguration = baseServiceConfiguration

    return configuration
  } catch (error) {
    throw { error, type: 'server' }
  }
}

async function updateServiceLabels (configuration, services) {
  // In case of any failure during deployment, still update the current configuration.
  const found = services.find(s => {
    const config = JSON.parse(s.Spec.Labels.configuration)
    if (config.repository.id === configuration.repository.id && config.repository.branch === configuration.repository.branch) {
      return config
    }
    return null
  })
  if (found) {
    const { ID } = found
    try {
      const Labels = { ...JSON.parse(found.Spec.Labels.configuration), ...configuration }
      execShellAsync(`docker service update --label-add configuration='${JSON.stringify(Labels)}' --label-add com.docker.stack.image='${configuration.build.container.name}:${configuration.build.container.tag}' ${ID}`)
    } catch (error) {
      console.log(error)
    }
  }
}
module.exports = { setDefaultConfiguration, updateServiceLabels }
