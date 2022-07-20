import { asyncExecShell, executeDockerCmd } from './common';
import Dockerode from 'dockerode';
export function getEngine(engine: string): string {
	return engine === '/var/run/docker.sock' ? 'unix:///var/run/docker.sock' : engine;
}
export function dockerInstance({ destinationDocker }): { engine: Dockerode; network: string } {
	return {
		engine: new Dockerode({
			socketPath: destinationDocker.engine
		}),
		network: destinationDocker.network
	};
}

export async function checkContainer({ dockerId, container, remove = false }: { dockerId: string, container: string, remove?: boolean }): Promise<boolean> {
	let containerFound = false;
	try {
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

export async function isContainerExited(engine: string, containerName: string): Promise<boolean> {
	let isExited = false;
	const host = getEngine(engine);
	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker inspect -f '{{.State.Status}}' ${containerName}`
		);
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
		const { stdout } =await executeDockerCmd({ dockerId, command: `docker inspect --format '{{json .State}}' ${id}`})
	
		if (JSON.parse(stdout).Running) {
			await executeDockerCmd({ dockerId, command: `docker stop -t 0 ${id}`})
			await executeDockerCmd({ dockerId, command: `docker rm ${id}`})
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
