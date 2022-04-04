import Dockerode from 'dockerode';
import { promises as fs } from 'fs';
import { checkPnpm } from './buildPacks/common';
import { saveBuildLog } from './common';

export async function buildCacheImageWithNode(data, imageForBuild) {
	const {
		applicationId,
		tag,
		workdir,
		docker,
		buildId,
		baseDirectory,
		installCommand,
		buildCommand,
		debug,
		secrets,
		pullmergeRequestId
	} = data;
	const isPnpm = checkPnpm(installCommand, buildCommand);
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${imageForBuild}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.image=true`);
	if (secrets.length > 0) {
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					if (secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name}=${secret.value}`);
					}
				} else {
					if (!secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name}=${secret.value}`);
					}
				}
			}
		});
	}
	if (isPnpm) {
		Dockerfile.push('RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm');
		Dockerfile.push('RUN pnpm add -g pnpm');
	}
	Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	if (installCommand) {
		Dockerfile.push(`RUN ${installCommand}`);
	}
	Dockerfile.push(`RUN ${buildCommand}`);
	await fs.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join('\n'));
	await buildImage({ applicationId, tag, workdir, docker, buildId, isCache: true, debug });
}

export async function buildCacheImageWithCargo(data, imageForBuild) {
	const {
		applicationId,
		tag,
		workdir,
		docker,
		buildId,
		baseDirectory,
		installCommand,
		buildCommand,
		debug,
		secrets
	} = data;
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${imageForBuild} as planner-${applicationId}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push('RUN cargo install cargo-chef');
	Dockerfile.push('COPY . .');
	Dockerfile.push('RUN cargo chef prepare --recipe-path recipe.json');
	Dockerfile.push(`FROM ${imageForBuild}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push('RUN cargo install cargo-chef');
	Dockerfile.push(`COPY --from=planner-${applicationId} /app/recipe.json recipe.json`);
	Dockerfile.push('RUN cargo chef cook --release --recipe-path recipe.json');
	await fs.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join('\n'));
	await buildImage({ applicationId, tag, workdir, docker, buildId, isCache: true, debug });
}

export async function buildImage({
	applicationId,
	tag,
	workdir,
	docker,
	buildId,
	isCache = false,
	debug = false
}) {
	if (isCache) {
		await saveBuildLog({ line: `Building cache image started.`, buildId, applicationId });
	} else {
		await saveBuildLog({ line: `Building image started.`, buildId, applicationId });
	}
	if (!debug && isCache) {
		await saveBuildLog({
			line: `Debug turned off. To see more details, allow it in the configuration.`,
			buildId,
			applicationId
		});
	}

	const stream = await docker.engine.buildImage(
		{ src: ['.'], context: workdir },
		{
			dockerfile: isCache ? 'Dockerfile-cache' : 'Dockerfile',
			t: `${applicationId}:${tag}${isCache ? '-cache' : ''}`
		}
	);
	await streamEvents({ stream, docker, buildId, applicationId, debug });
}

export function dockerInstance({ destinationDocker }): { engine: Dockerode; network: string } {
	// new Docker({protocol:"ssh",  host: '188.34.164.25',port: 22, username:'root', sshOptions: {agentForward: true, agent:process.env.SSH_AUTH_SOCK}})
	return {
		engine: new Dockerode({
			socketPath: destinationDocker.engine
		}),
		network: destinationDocker.network
	};
}
export async function streamEvents({ stream, docker, buildId, applicationId, debug }) {
	await new Promise((resolve, reject) => {
		docker.engine.modem.followProgress(stream, onFinished, onProgress);
		function onFinished(err, res) {
			if (err) reject(err);
			resolve(res);
		}
		async function onProgress(event) {
			if (event.error) {
				reject(event.error);
			} else if (event.stream) {
				if (event.stream !== '\n') {
					if (debug)
						await saveBuildLog({
							line: `${event.stream.replace('\n', '')}`,
							buildId,
							applicationId
						});
				}
			}
		}
	});
}

export const baseServiceConfigurationDocker = {
	restart_policy: {
		condition: 'any',
		max_attempts: 6
	}
};

export const baseServiceConfigurationSwarm = {
	replicas: 1,
	restart_policy: {
		condition: 'any',
		max_attempts: 6
	},
	update_config: {
		parallelism: 1,
		delay: '10s',
		order: 'start-first'
	},
	rollback_config: {
		parallelism: 1,
		delay: '10s',
		order: 'start-first',
		failure_action: 'rollback'
	}
};
