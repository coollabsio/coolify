const fs = require('fs').promises
const { streamEvents, docker } = require('../libs/docker')
const buildImageNodeDocker = (configuration) => {
  return [
    'FROM node:lts',
    'WORKDIR /usr/src/app',
    `COPY ${configuration.build.directory}/package*.json ./`,
    configuration.build.command.installation && `RUN ${configuration.build.command.installation}`,
    `COPY ./${configuration.build.directory} ./`,
    `RUN ${configuration.build.command.build}`
  ].join('\n')
}
async function buildImage (configuration) {
  await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, buildImageNodeDocker(configuration))
  const stream = await docker.engine.buildImage(
    { src: ['.'], context: configuration.general.workdir },
    { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
  )
  await streamEvents(stream, configuration)
}

module.exports = {
  buildImage
}
