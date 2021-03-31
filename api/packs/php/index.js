const fs = require('fs').promises
const { streamEvents, docker } = require('../../libs/docker')

module.exports = async function (configuration) {
  let dockerFile = `# production stage
    FROM php:apache
    `
  console.log(configuration)
  if (configuration.publish.directory) {
    dockerFile += `COPY ${configuration.publish.directory} /var/www/html`
  } else {
    dockerFile += 'COPY . /var/www/html'
  }

  dockerFile += `
      EXPOSE 80
      CMD ["apache2-foreground"]`
  await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, dockerFile)

  const stream = await docker.engine.buildImage(
    { src: ['.'], context: configuration.general.workdir },
    { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
  )
  await streamEvents(stream, configuration)
}
