import { docker, streamEvents } from '$lib/api/docker';
import { promises as fs } from 'fs';

const buildImageNodeDocker = (configuration, prodBuild) => {
	return [
		'FROM node:lts',
		'WORKDIR /usr/src/app',
		`COPY ${configuration.build.directory}/package*.json ./`,
		configuration.build.command.installation && `RUN ${configuration.build.command.installation}`,
		`COPY ./${configuration.build.directory} ./`,
		`RUN ${configuration.build.command.build}`,
		prodBuild && `RUN rm -fr node_modules && ${configuration.build.command.installation} --prod`
	].join('\n');
};
export async function buildImage(configuration, cacheBuild?: boolean, prodBuild?: boolean) {
	await fs.writeFile(
		`${configuration.general.workdir}/Dockerfile`,
		buildImageNodeDocker(configuration, prodBuild)
	);
	const stream = await docker.engine.buildImage(
		{ src: ['.'], context: configuration.general.workdir },
		{
			t: `${configuration.build.container.name}:${
				cacheBuild
					? `${configuration.build.container.tag}-cache`
					: configuration.build.container.tag
			}`
		}
	);
	await streamEvents(stream, configuration);
}
