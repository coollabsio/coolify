const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator')
const cuid = require('cuid')
const crypto = require('crypto')
const { docker } = require('../docker')
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
        order: 'start-first',
        failure_action: 'rollback'
      }
    }

    configuration.build.container.name = sha256.slice(0, 15)

    configuration.general.nickname = nickname
    configuration.general.deployId = deployId
    configuration.general.workdir = `/tmp/${deployId}`

    if (!configuration.publish.path) configuration.publish.path = '/'
    if (!configuration.publish.port) {
      if (configuration.build.pack === 'php') {
        configuration.publish.port = 80
      } else if (configuration.build.pack === 'static') {
        configuration.publish.port = 80
      } else if (configuration.build.pack === 'nodejs') {
        configuration.publish.port = 3000
      } else if (configuration.build.pack === 'rust') {
        configuration.publish.port = 3000
      }
    }

    if (!configuration.build.directory) {
      configuration.build.directory = '/'
    }
    if (!configuration.publish.directory) {
      configuration.publish.directory = '/'
    }

    if (configuration.build.pack === 'static' || configuration.build.pack === 'nodejs') {
      if (!configuration.build.command.installation) configuration.build.command.installation = 'yarn install'
    }

    configuration.build.container.baseSHA = crypto.createHash('sha256').update(JSON.stringify(baseServiceConfiguration)).digest('hex')
    configuration.baseServiceConfiguration = baseServiceConfiguration

    return configuration
  } catch (error) {
    throw { error, type: 'server' }
  }
}

async function updateServiceLabels (configuration) {
  // In case of any failure during deployment, still update the current configuration.
  const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')
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
      await execShellAsync(`docker service update --label-add configuration='${JSON.stringify(Labels)}' --label-add com.docker.stack.image='${configuration.build.container.name}:${configuration.build.container.tag}' ${ID}`)
    } catch (error) {
      console.log(error)
    }
  }
}

async function precheckDeployment ({ services, configuration }) {
  let foundService = false
  let configChanged = false
  let imageChanged = false

  let forceUpdate = false

  for (const service of services) {
    const running = JSON.parse(service.Spec.Labels.configuration)
    if (running) {
      if (running.repository.id === configuration.repository.id && running.repository.branch === configuration.repository.branch) {
        // Base service configuration changed
        if (!running.build.container.baseSHA || running.build.container.baseSHA !== configuration.build.container.baseSHA) {
          forceUpdate = true
        }
        // If the deployment is in error state, forceUpdate
        const state = await execShellAsync(`docker stack ps ${running.build.container.name} --format '{{ json . }}'`)
        const isError = state.split('\n').filter(n => n).map(s => JSON.parse(s)).filter(n => n.DesiredState !== 'Running' && n.Image.split(':')[1] === running.build.container.tag)
        if (isError.length > 0) forceUpdate = true
        foundService = true

        const runningWithoutContainer = JSON.parse(JSON.stringify(running))
        delete runningWithoutContainer.build.container

        const configurationWithoutContainer = JSON.parse(JSON.stringify(configuration))
        delete configurationWithoutContainer.build.container

        // If only the configuration changed
        if (JSON.stringify(runningWithoutContainer.build) !== JSON.stringify(configurationWithoutContainer.build) || JSON.stringify(runningWithoutContainer.publish) !== JSON.stringify(configurationWithoutContainer.publish)) configChanged = true
        // If only the image changed
        if (running.build.container.tag !== configuration.build.container.tag) imageChanged = true
        // If build pack changed, forceUpdate the service
        if (running.build.pack !== configuration.build.pack) forceUpdate = true
      }
    }
  }
  if (forceUpdate) {
    imageChanged = false
    configChanged = false
  }
  return {
    foundService,
    imageChanged,
    configChanged,
    forceUpdate
  }
}
module.exports = { setDefaultConfiguration, updateServiceLabels, precheckDeployment }
