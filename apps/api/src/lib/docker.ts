import { executeDockerCmd } from './common';

export function formatLabelsOnDocker(data) {
	return data.trim().split('\n').map(a => JSON.parse(a)).map((container) => {
		const labels = container.Labels.split(',')
		let jsonLabels = {}
		labels.forEach(l => {
			const name = l.split('=')[0]
			const value = l.split('=')[1]
			jsonLabels = { ...jsonLabels, ...{ [name]: value } }
		})
		container.Labels = jsonLabels;
		return container
	})
}
export async function checkContainer({ dockerId, container, remove = false }: { dockerId: string, container: string, remove?: boolean }): Promise<boolean> {
	let containerFound = false;
	try {
		console.log('checking ', container)
		const { stdout } = await executeDockerCmd({
			dockerId,
			command:
				`docker inspect --format '{{json .State}}' ${container}`
		});

		const parsedStdout = JSON.parse(stdout);
		const status = parsedStdout.Status;
		const isRunning = status === 'running';
		if (status === 'created') {
			await executeDockerCmd({
				dockerId,
				command:
					`docker rm ${container}`
			});
		}
		if (remove && status === 'exited') {
			await executeDockerCmd({
				dockerId,
				command:
					`docker rm ${container}`
			});
		}
		if (isRunning) {
			containerFound = true;
		}
	} catch (err) {
		// Container not found
	}
	return containerFound;
}

export async function isContainerExited(dockerId: string, containerName: string): Promise<boolean> {
	let isExited = false;
	try {
		const { stdout } = await executeDockerCmd({ dockerId, command: `docker inspect -f '{{.State.Status}}' ${containerName}` })
		if (stdout.trim() === 'exited') {
			isExited = true;
		}
	} catch (error) {
		//
	}

	return isExited;
}

export async function removeContainer({
	id,
	dockerId
}: {
	id: string;
	dockerId: string;
}): Promise<void> {
	try {
		const { stdout } = await executeDockerCmd({ dockerId, command: `docker inspect --format '{{json .State}}' ${id}` })
		console.log(id)
		if (JSON.parse(stdout).Running) {
			await executeDockerCmd({ dockerId, command: `docker stop -t 0 ${id}` })
			await executeDockerCmd({ dockerId, command: `docker rm ${id}` })
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
