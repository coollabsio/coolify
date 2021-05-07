import { docker, streamEvents } from '$lib/docker';
import { promises as fs } from 'fs';

export default async function (configuration) {
	const path = `${configuration.general.workdir}/${
		configuration.build.directory ? configuration.build.directory : ''
	}`;
	if (fs.stat(`${path}/Dockerfile`)) {
		const stream = await docker.engine.buildImage(
			{ src: ['.'], context: path },
			{ t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
		);
		await streamEvents(stream, configuration);
	} else {
		throw new Error('No custom dockerfile found.');
	}
}
