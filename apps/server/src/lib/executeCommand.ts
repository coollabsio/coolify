import { prisma } from '../prisma';
import os from 'os';
import fs from 'fs/promises';
import type { ExecaChildProcess } from 'execa';
import sshConfig from 'ssh-config';

import { getFreeSSHLocalPort } from './ssh';
import { env } from '../env';
import { BuildLog, saveBuildLog } from './logging';
import { decrypt } from './common';

export async function executeCommand({
	command,
	dockerId = null,
	sshCommand = false,
	shell = false,
	stream = false,
	buildId,
	applicationId,
	debug
}: {
	command: string;
	sshCommand?: boolean;
	shell?: boolean;
	stream?: boolean;
	dockerId?: string | null;
	buildId?: string;
	applicationId?: string;
	debug?: boolean;
}): Promise<ExecaChildProcess<string>> {
	const { execa, execaCommand } = await import('execa');
	const { parse } = await import('shell-quote');
	const parsedCommand = parse(command);
	const dockerCommand = parsedCommand[0];
	const dockerArgs = parsedCommand.slice(1);

	if (dockerId && dockerCommand && dockerArgs) {
		const destinationDocker = await prisma.destinationDocker.findUnique({
			where: { id: dockerId }
		});
		if (!destinationDocker) {
			throw new Error('Destination docker not found');
		}
		let { remoteEngine, remoteIpAddress, engine } = destinationDocker;
		if (remoteEngine) {
			await createRemoteEngineConfiguration(dockerId);
			engine = `ssh://${remoteIpAddress}-remote`;
		} else {
			engine = 'unix:///var/run/docker.sock';
		}

		if (env.CODESANDBOX_HOST) {
			if (command.startsWith('docker compose')) {
				command = command.replace(/docker compose/gi, 'docker-compose');
			}
		}
		if (sshCommand) {
			if (shell) {
				return execaCommand(`ssh ${remoteIpAddress}-remote ${command}`);
			}
			//@ts-ignore
			return await execa('ssh', [`${remoteIpAddress}-remote`, dockerCommand, ...dockerArgs]);
		}
		if (stream) {
			return await new Promise(async (resolve, reject) => {
				let subprocess = null;
				if (shell) {
					//@ts-ignore
					subprocess = execaCommand(command, {
						env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
					});
				} else {
					//@ts-ignore
					subprocess = execa(dockerCommand, dockerArgs, {
						env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
					});
				}
				const logs: any[] = [];
				if (subprocess && subprocess.stdout && subprocess.stderr) {
					subprocess.stdout.on('data', async (data: string) => {
						const stdout = data.toString();
						const array = stdout.split('\n');
						for (const line of array) {
							if (line !== '\n' && line !== '') {
								const log: BuildLog = {
									line: `${line.replace('\n', '')}`,
									buildId,
									applicationId
								};
								logs.push(log);
								if (debug) {
									await saveBuildLog(log);
								}
							}
						}
					});
					subprocess.stderr.on('data', async (data: string) => {
						const stderr = data.toString();
						const array = stderr.split('\n');
						for (const line of array) {
							if (line !== '\n' && line !== '') {
								const log = {
									line: `${line.replace('\n', '')}`,
									buildId,
									applicationId
								};
								logs.push(log);
								if (debug) {
									await saveBuildLog(log);
								}
							}
						}
					});
					subprocess.on('exit', async (code: number) => {
						if (code === 0) {
							//@ts-ignore
							resolve(code);
						} else {
							if (!debug) {
								for (const log of logs) {
									await saveBuildLog(log);
								}
							}
							reject(code);
						}
					});
				}
			});
		} else {
			if (shell) {
				return await execaCommand(command, {
					//@ts-ignore
					env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
				});
			} else {
				//@ts-ignore
				return await execa(dockerCommand, dockerArgs, {
					env: { DOCKER_BUILDKIT: '1', DOCKER_HOST: engine }
				});
			}
		}
	} else {
		if (shell) {
			return execaCommand(command, { shell: true });
		}
		//@ts-ignore
		return await execa(dockerCommand, dockerArgs);
	}
}

export async function createRemoteEngineConfiguration(id: string) {
	const homedir = os.homedir();
	const sshKeyFile = `/tmp/id_rsa-${id}`;
	const localPort = await getFreeSSHLocalPort(id);
	const {
		sshKey: { privateKey },
		network,
		remoteIpAddress,
		remotePort,
		remoteUser
	} = await prisma.destinationDocker.findFirst({ where: { id }, include: { sshKey: true } });
	await fs.writeFile(sshKeyFile, decrypt(privateKey) + '\n', { encoding: 'utf8', mode: 400 });
	const config = sshConfig.parse('');
	const Host = `${remoteIpAddress}-remote`;

	try {
		await executeCommand({ command: `ssh-keygen -R ${Host}` });
		await executeCommand({ command: `ssh-keygen -R ${remoteIpAddress}` });
		await executeCommand({ command: `ssh-keygen -R localhost:${localPort}` });
	} catch (error) {}

	const found = config.find({ Host });
	const foundIp = config.find({ Host: remoteIpAddress });

	if (found) config.remove({ Host });
	if (foundIp) config.remove({ Host: remoteIpAddress });

	config.append({
		Host,
		Hostname: remoteIpAddress,
		Port: remotePort.toString(),
		User: remoteUser,
		StrictHostKeyChecking: 'no',
		IdentityFile: sshKeyFile,
		ControlMaster: 'auto',
		ControlPath: `${homedir}/.ssh/coolify-${remoteIpAddress}-%r@%h:%p`,
		ControlPersist: '10m'
	});

	try {
		await fs.stat(`${homedir}/.ssh/`);
	} catch (error) {
		await fs.mkdir(`${homedir}/.ssh/`);
	}
	return await fs.writeFile(`${homedir}/.ssh/config`, sshConfig.stringify(config));
}
