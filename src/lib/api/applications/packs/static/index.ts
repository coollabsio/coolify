import { docker, streamEvents } from '$lib/docker'
import { promises as fs } from 'fs'
import { buildImage } from '../helpers'

//    'HEALTHCHECK --timeout=10s --start-period=10s --interval=5s CMD curl -I -s -f http://localhost/ || exit 1',
const publishStaticDocker = (configuration) => {
  return [
    'FROM nginx:stable-alpine',
    'COPY nginx.conf /etc/nginx/nginx.conf',
    'WORKDIR /usr/share/nginx/html',
    configuration.build.command.build
      ? `COPY --from=${configuration.build.container.name}:${configuration.build.container.tag}-cache /usr/src/app/${configuration.publish.directory} ./`
      : `COPY ./${configuration.build.directory} ./`,
    'EXPOSE 80',
    'CMD ["nginx", "-g", "daemon off;"]'
  ].join('\n')
}

export default async function (configuration) {
  if (configuration.build.command.build) await buildImage(configuration, true)
  await fs.writeFile(`${configuration.general.workdir}/Dockerfile`, publishStaticDocker(configuration))
  const stream = await docker.engine.buildImage(
    { src: ['.'], context: configuration.general.workdir },
    { t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
  )
  await streamEvents(stream, configuration)
}
