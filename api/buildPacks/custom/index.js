const fs = require('fs').promises
const { streamEvents, docker } = require('../../libs/docker')

module.exports = async function (configuration) {
  try {
    const path = `${configuration.general.workdir}/${configuration.build.directory ? configuration.build.directory : ''}`
    if (fs.stat(`${path}/Dockerfile`)) {
      const stream = await docker.engine.buildImage(
        { src: ['.'], context: path },
        { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
      )
      await streamEvents(stream, configuration)
    } else {
      throw { error: 'No custom dockerfile found.', type: 'app' }
    }
  } catch (error) {
    throw { error, type: 'server' }
  }
}
