const fs = require('fs').promises
const { buildImage } = require('../helpers')
const { streamEvents, docker } = require('../../libs/docker')
//  `HEALTHCHECK --timeout=10s --start-period=10s --interval=5s CMD curl -I -s -f http://localhost:${configuration.publish.port}${configuration.publish.path} || exit 1`,
const publishNodejsDocker = (configuration) => {
  return [
    'FROM node:lts',
    'WORKDIR /usr/src/app',
    configuration.build.command.build
      ? `COPY --from=${configuration.build.container.name}:${configuration.build.container.tag} /usr/src/app/${configuration.publish.directory} ./`
      : `COPY ${configuration.build.directory} ./`,
    configuration.build.command.installation && `RUN ${configuration.build.command.installation}`,
    `EXPOSE ${configuration.publish.port}`,
    'CMD [ "yarn", "start" ]'
  ].join('\n')
}

module.exports = async function (configuration) {
  try {
    if (configuration.build.command.build) await buildImage(configuration)
    await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, publishNodejsDocker(configuration))
    const stream = await docker.engine.buildImage(
      { src: ['.'], context: configuration.general.workdir },
      { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
    )
    await streamEvents(stream, configuration)
  } catch (error) {
    throw { error, type: 'server' }
  }
}
