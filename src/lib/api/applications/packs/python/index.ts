import { docker, streamEvents } from '$lib/api/docker';
import { promises as fs } from 'fs';
//  `HEALTHCHECK --timeout=10s --start-period=10s --interval=5s CMD curl -I -s -f http://localhost:${configuration.publish.port}${configuration.publish.path} || exit 1`,
const publishPython = (configuration) => {
	return [
		'FROM python:3-alpine',
		'WORKDIR /usr/src/app',
		'RUN pip install gunicorn',
		'COPY requirements.txt ./',
		'RUN pip install --no-cache-dir -r requirements.txt',
		'COPY . .',
		`EXPOSE ${configuration.publish.port}`,
		`CMD gunicorn -w=4 ${configuration.build.command.python.module}:${configuration.build.command.python.instance}`
	].join('\n');
};

export default async function (configuration) {
	await fs.writeFile(
		`${configuration.general.workdir}/Dockerfile`,
		publishPython(configuration)
	);
	const stream = await docker.engine.buildImage(
		{ src: ['.'], context: configuration.general.workdir },
		{ t: `${configuration.build.container.name}:${configuration.build.container.tag}` }
	);
	await streamEvents(stream, configuration);
}
