import { executeCommand } from './common';

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
export async function checkContainer({ dockerId, container, remove = false }: { dockerId: string, container: string, remove?: boolean }): Promise<{ found: boolean, status?: { isExited: boolean, isRunning: boolean, isRestarting: boolean } }> {
	let containerFound = false;
	try {
		const { stdout } = await executeCommand({
			dockerId,
			command:
				`docker inspect --format '{{json .State}}' ${container}`
		});
		containerFound = true
		const parsedStdout = JSON.parse(stdout);
		const status = parsedStdout.Status;
		const isRunning = status === 'running';
		const isRestarting = status === 'restarting'
		const isExited = status === 'exited'
		if (status === 'created') {
			await executeCommand({
				dockerId,
				command:
					`docker rm ${container}`
			});
		}
		if (remove && status === 'exited') {
			await executeCommand({
				dockerId,
				command:
					`docker rm ${container}`
			});
		}

		return {
			found: containerFound,
			status: {
				isRunning,
				isRestarting,
				isExited
			}
		};
	} catch (err) {
		// Container not found
	}
	return {
		found: false
	};

}

export async function isContainerExited(dockerId: string, containerName: string): Promise<boolean> {
	let isExited = false;
	try {
		const { stdout } = await executeCommand({ dockerId, command: `docker inspect -f '{{.State.Status}}' ${containerName}` })
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
		const { stdout } = await executeCommand({ dockerId, command: `docker inspect --format '{{json .State}}' ${id}` })
		if (JSON.parse(stdout).Running) {
			await executeCommand({ dockerId, command: `docker stop -t 0 ${id}` })
			await executeCommand({ dockerId, command: `docker rm ${id}` })
		}
		if (JSON.parse(stdout).Status === 'exited') {
			await executeCommand({ dockerId, command: `docker rm ${id}` })
		}
	} catch (error) {
		throw error;
	}
}
