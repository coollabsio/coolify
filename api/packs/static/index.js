const fs = require('fs').promises
const { buildImage } = require('../helpers')
const { streamEvents, docker } = require('../../libs/docker')

module.exports = async function (configuration) {
  if (configuration.build.command.build) await buildImage(configuration)

  let dockerFile = `# production stage
    FROM nginx:stable-alpine
    COPY nginx.conf /etc/nginx/nginx.conf
    `
  if (configuration.build.command.build) {
    dockerFile += `COPY --from=${configuration.build.container.name}:${configuration.build.container.tag} /usr/src/app/${configuration.publish.directory} /usr/share/nginx/html`
  } else {
    if (configuration.publish.directory) {
      dockerFile += `COPY .${configuration.publish.directory} /usr/share/nginx/html`
    } else {
      dockerFile += 'COPY . /usr/share/nginx/html'
    }
  }

  dockerFile += `
      EXPOSE 80
      CMD ["nginx", "-g", "daemon off;"]`
  await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, dockerFile)

  const stream = await docker.engine.buildImage(
    { src: ['.'], context: configuration.general.workdir },
    { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
  )
  await streamEvents(stream, configuration)
}
