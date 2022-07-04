import { asyncExecShell } from './common';
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

export async function checkContainer(engine: string, container: string, remove = false): Promise<boolean> {
	const host = getEngine(engine);
	let containerFound = false;

	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker inspect --format '{{json .State}}' ${container}`
		);
		const parsedStdout = JSON.parse(stdout);
		const status = parsedStdout.Status;
		const isRunning = status === 'running';
		if (status === 'created') {
			await asyncExecShell(`DOCKER_HOST="${host}" docker rm ${container}`);
		}
		if (remove && status === 'exited') {
			await asyncExecShell(`DOCKER_HOST="${host}" docker rm ${container}`);
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
	engine
}: {
	id: string;
	engine: string;
}): Promise<void> {
	const host = getEngine(engine);
	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
		);
		if (JSON.parse(stdout).Running) {
			await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${id}`);
			await asyncExecShell(`DOCKER_HOST=${host} docker rm ${id}`);
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
