import { executeCommand } from './executeCommand';

export async function checkContainer({
	dockerId,
	container,
	remove = false
}: {
	dockerId: string;
	container: string;
	remove?: boolean;
}): Promise<{
	found: boolean;
	status?: { isExited: boolean; isRunning: boolean; isRestarting: boolean };
}> {
	let containerFound = false;
	try {
		const { stdout } = await executeCommand({
			dockerId,
			command: `docker inspect --format '{{json .State}}' ${container}`
		});
		containerFound = true;
		const parsedStdout = JSON.parse(stdout);
		const status = parsedStdout.Status;
		const isRunning = status === 'running';
		const isRestarting = status === 'restarting';
		const isExited = status === 'exited';
		if (status === 'created') {
			await executeCommand({
				dockerId,
				command: `docker rm ${container}`
			});
		}
		if (remove && status === 'exited') {
			await executeCommand({
				dockerId,
				command: `docker rm ${container}`
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

export async function removeContainer({
	id,
	dockerId
}: {
	id: string;
	dockerId: string;
}): Promise<void> {
	try {
		const { stdout } = await executeCommand({
			dockerId,
			command: `docker inspect --format '{{json .State}}' ${id}`
		});
		if (JSON.parse(stdout).Running) {
			await executeCommand({ dockerId, command: `docker stop -t 0 ${id}` });
			await executeCommand({ dockerId, command: `docker rm ${id}` });
		}
		if (JSON.parse(stdout).Status === 'exited') {
			await executeCommand({ dockerId, command: `docker rm ${id}` });
		}
	} catch (error) {
		throw error;
	}
}

export async function stopDatabaseContainer(database: any): Promise<boolean> {
	let everStarted = false;
	const {
		id,
		destinationDockerId,
		destinationDocker: { engine, id: dockerId }
	} = database;
	if (destinationDockerId) {
		try {
			const { stdout } = await executeCommand({
				dockerId,
				command: `docker inspect --format '{{json .State}}' ${id}`
			});

			if (stdout) {
				everStarted = true;
				await removeContainer({ id, dockerId });
			}
		} catch (error) {
			//
		}
	}
	return everStarted;
}
export async function stopTcpHttpProxy(
	id: string,
	destinationDocker: any,
	publicPort: number,
	forceName: string | null = null
): Promise<{ stdout: string; stderr: string } | Error | unknown> {
	const { id: dockerId } = destinationDocker;
	let container = `${id}-${publicPort}`;
	if (forceName) container = forceName;
	const { found } = await checkContainer({ dockerId, container });
	try {
		if (!found) return true;
		return await executeCommand({
			dockerId,
			command: `docker stop -t 0 ${container} && docker rm ${container}`,
			shell: true
		});
	} catch (error) {
		return error;
	}
}

export function formatLabelsOnDocker(data: any) {
	return data
		.trim()
		.split('\n')
		.map((a) => JSON.parse(a))
		.map((container) => {
			const labels = container.Labels.split(',');
			let jsonLabels = {};
			labels.forEach((l) => {
				const name = l.split('=')[0];
				const value = l.split('=')[1];
				jsonLabels = { ...jsonLabels, ...{ [name]: value } };
			});
			container.Labels = jsonLabels;
			return container;
		});
}

export function defaultComposeConfiguration(network: string): any {
	return {
		networks: [network],
		restart: 'on-failure',
		deploy: {
			restart_policy: {
				condition: 'on-failure',
				delay: '5s',
				max_attempts: 10,
				window: '120s'
			}
		}
	};
}
