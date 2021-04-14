const fs = require('fs').promises
const { streamEvents, docker } = require('../../libs/docker')

const publishPHPDocker = (configuration) => {
  return [
    'FROM php:apache',
    'RUN a2enmod rewrite',
    'WORKDIR /usr/src/app',
    `COPY .${configuration.build.directory} /var/www/html`,
    'HEALTHCHECK --timeout=10s --start-period=10s --interval=5s CMD curl -I -s -f http://localhost/ || exit 1',
    'EXPOSE 80',
    ' CMD ["apache2-foreground"]'
  ].join('\n')
}

module.exports = async function (configuration) {
  try {
    await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, publishPHPDocker(configuration))
    const stream = await docker.engine.buildImage(
      { src: ['.'], context: configuration.general.workdir },
      { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
    )
    await streamEvents(stream, configuration)
  } catch (error) {
    throw { error, type: 'server' }
  }
}
