import { executeCommand } from "./executeCommand";

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