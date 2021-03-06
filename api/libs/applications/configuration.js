const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator');
const cuid = require('cuid')
const { docker } = require('../docker')
const { execShellAsync } = require('../common')
function getUniq() {
  return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 })
}

function setDefaultConfiguration(configuration) {
  try {
    const nickname = getUniq()
    const deployId = cuid()

    configuration.build.container.name = deployId

    configuration.general.nickname = nickname
    configuration.general.deployId = deployId
    configuration.general.workdir = `/tmp/${deployId}`

    if (!configuration.publish.path) configuration.publish.path = '/'
    if (!configuration.publish.port) configuration.publish.port = configuration.build.pack === 'static' ? 80 : 3000

    return configuration
  } catch (error) {
    throw { error, type: 'server' }
  }

}

async function saveConfiguration(configuration) {
  // In case of any failure during deployment, still update the current configuration.
  const services = (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application')

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
      execShellAsync(`docker service update --label-add configuration='${JSON.stringify(Labels)}' ${ID}`)
    } catch (error) {
      console.log(error)
    }

  }

}
module.exports = { setDefaultConfiguration, saveConfiguration }