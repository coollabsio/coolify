import { promises as fs } from 'fs';
import { generateSecrets } from '../common';
import { buildImage } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const {
		workdir,
		port,
		baseDirectory,
		secrets,
		pullmergeRequestId,
		pythonWSGI,
		pythonModule,
		pythonVariable,
		buildId
	} = data;
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
		});
	}
	if (pythonWSGI?.toLowerCase() === 'gunicorn') {
		Dockerfile.push(`RUN pip install gunicorn`);
	} else if (pythonWSGI?.toLowerCase() === 'uvicorn') {
		Dockerfile.push(`RUN pip install uvicorn`);
	} else if (pythonWSGI?.toLowerCase() === 'uwsgi') {
		Dockerfile.push(`RUN apk add --no-cache uwsgi-python3`);
		// Dockerfile.push(`RUN pip install --no-cache-dir uwsgi`)
	}

	try {
		await fs.stat(`${workdir}${baseDirectory || ''}/requirements.txt`);
		Dockerfile.push(`COPY .${baseDirectory || ''}/requirements.txt ./`);
		Dockerfile.push(`RUN pip install --no-cache-dir -r .${baseDirectory || ''}/requirements.txt`);
	} catch (e) {
		//
	}
	Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	Dockerfile.push(`EXPOSE ${port}`);
	if (pythonWSGI?.toLowerCase() === 'gunicorn') {
		Dockerfile.push(`CMD gunicorn -w=4 -b=0.0.0.0:8000 ${pythonModule}:${pythonVariable}`);
	} else if (pythonWSGI?.toLowerCase() === 'uvicorn') {
		Dockerfile.push(`CMD uvicorn ${pythonModule}:${pythonVariable} --port ${port} --host 0.0.0.0`);
	} else if (pythonWSGI?.toLowerCase() === 'uwsgi') {
		Dockerfile.push(
			`CMD uwsgi --master -p 4 --http-socket 0.0.0.0:8000 --uid uwsgi --plugins python3 --protocol uwsgi --wsgi ${pythonModule}:${pythonVariable}`
		);
	} else {
		Dockerfile.push(`CMD python ${pythonModule}`);
	}

	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const { baseImage, baseBuildImage } = data;
		await createDockerfile(data, baseImage);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
