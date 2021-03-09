const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator');
const cuid = require('cuid')
const { docker } = require('../docker')
const { execShellAsync } = require('../common')
const crypto = require('crypto')

function getUniq() {
  return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 })
}

function setDefaultConfiguration(configuration) {
  try {
    const nickname = getUniq()
    const deployId = cuid()

    const shaBase = JSON.stringify({ repository: configuration.repository })
    const sha256 = crypto.createHash('sha256').update(shaBase).digest('hex');

    configuration.build.container.name = sha256.slice(0, 15)

    configuration.general.nickname = nickname
    configuration.general.deployId = deployId
    configuration.general.workdir = `/tmp/${deployId}`

    if (!configuration.publish.path) configuration.publish.path = '/'
    if (!configuration.publish.port) configuration.publish.port = configuration.build.pack === 'static' ? 80 : 3000
    
    if (configuration.build.pack === 'static') {
      if (!configuration.build.command.installation) configuration.build.command.installation = "yarn install";
      if (!configuration.build.directory) configuration.build.directory = "/";
    }

    if (configuration.build.pack === 'nodejs') {
      if (!configuration.build.command.installation) configuration.build.command.installation = "yarn install";
      if (!configuration.build.directory) configuration.build.directory = "/";
    }
    
    return configuration

  } catch (error) {
    throw { error, type: 'server' }
  }
}

async function updateServiceLabels(configuration, services) {
  // In case of any failure during deployment, still update the current configuration.
  const found = services.find(s => {
    const config = JSON.parse(s.Spec.Labels.configuration)
    if (config.repository.id === configuration.repository.id && config.repository.branch === configuration.repository.branch) {
      return config
    }
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