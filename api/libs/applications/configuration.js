const { uniqueNamesGenerator, adjectives, colors, animals } = require('unique-names-generator');
const cuid = require('cuid')
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

module.exports = { setDefaultConfiguration }