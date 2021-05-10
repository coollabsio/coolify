import { docker, streamEvents } from '$lib/api/docker';
import { promises as fs } from 'fs';
import { buildImage } from '../helpers';
//  `HEALTHCHECK --timeout=10s --start-period=10s --interval=5s CMD curl -I -s -f http://localhost:${configuration.publish.port}${configuration.publish.path} || exit 1`,
const publishNodejsDocker = (configuration) => {
	return [
		'FROM node:lts',
		'WORKDIR /usr/src/app',
		configuration.build.command.build
			? `COPY --from=${configuration.build.container.name}:${configuration.build.container.tag} /usr/src/app/${configuration.publish.directory} ./`
			: `
      COPY ${configuration.build.directory}/package*.json ./
      RUN ${configuration.build.command.installation}
      COPY ./${configuration.build.directory} ./`,
		`EXPOSE ${configuration.publish.port}`,
		'CMD [ "yarn", "start" ]'
	].join('\n');
};
export default async function (configuration) {
	await buildImage(configuration);
	await fs.writeFile(
		`${configuration.general.workdir}/Dockerfile`,
		publishNodejsDocker(configuration)
	);
	const stream = await docker.engine.buildImage(
		{ src: ['.'], context: configuration.general.workdir },
		{ t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
	);
	await streamEvents(stream, configuration);
}
