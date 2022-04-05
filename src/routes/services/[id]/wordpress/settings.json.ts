import { dev } from '$app/env';
import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import { decrypt, encrypt } from '$lib/crypto';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, ErrorHandler, generatePassword } from '$lib/database';
import { checkContainer, startTcpProxy, stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import fs from 'fs/promises';
import getPort, { portNumbers } from 'get-port';

export const post: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const data = await db.prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const { ftpEnabled } = await event.request.json();
	const publicPort = await getPort({ port: portNumbers(minPort, maxPort) });
	let ftpUser = cuid();
	const ftpPassword = generatePassword();

	const hostkeyDir = dev ? '/tmp/hostkeys' : '/app/ssl/hostkeys';
	try {
		const { stdout: password } = await asyncExecShell(
			`echo ${ftpPassword} | openssl passwd -1 -stdin`
		);
		const data = await db.prisma.wordpress.update({
			where: { serviceId: id },
			data: { ftpEnabled },
			include: { service: { include: { destinationDocker: true } } }
		});
		const {
			service: { destinationDockerId, destinationDocker },
			ftpPublicPort: oldPublicPort,
			ftpUser: user,
			ftpHostKey,
			ftpHostKeyPrivate
		} = data;
		if (user) ftpUser = user;
		try {
			await fs.stat(hostkeyDir);
		} catch (error) {
			await asyncExecShell(`mkdir -p ${hostkeyDir}`);
		}
		if (!ftpHostKey) {
			await asyncExecShell(
				`ssh-keygen -t ed25519 -f ssh_host_ed25519_key -N "" -q -f /tmp/${id} < /dev/null`
			);
			const { stdout: ftpHostKey } = await asyncExecShell(`cat ${hostkeyDir}/${id}.ed25519`);
			await db.prisma.wordpress.update({
				where: { serviceId: id },
				data: { ftpHostKey: encrypt(ftpHostKey.replace('\n', '')) }
			});
		} else {
			await asyncExecShell(`echo ${decrypt(ftpHostKey)} > ${hostkeyDir}/${id}.ed25519`);
		}
		if (!ftpHostKeyPrivate) {
			await asyncExecShell(`ssh-keygen -t rsa -b 4096 -N "" -f /tmp/${id}.rsa < /dev/null`);
			const { stdout: ftpHostKeyPrivate } = await asyncExecShell(`cat /tmp/${id}.rsa`);
			await db.prisma.wordpress.update({
				where: { serviceId: id },
				data: { ftpHostKeyPrivate: encrypt(ftpHostKeyPrivate.replace('\n', '')) }
			});
		} else {
			await asyncExecShell(`echo ${decrypt(ftpHostKeyPrivate)} > ${hostkeyDir}/${id}.rsa`);
		}
		if (destinationDockerId) {
			const { network, engine } = destinationDocker;
			const host = getEngine(engine);
			if (ftpEnabled) {
				await db.prisma.wordpress.update({
					where: { serviceId: id },
					data: { ftpPublicPort: publicPort, ftpUser, ftpPassword: encrypt(ftpPassword) }
				});

				try {
					const isRunning = await checkContainer(engine, `${id}-ftp`);
					if (isRunning) {
						await asyncExecShell(
							`DOCKER_HOST=${host} docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
						);
					}
				} catch (error) {
					console.log(error);
					//
				}

				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --restart always --add-host 'host.docker.internal:host-gateway' --network ${network} --name ${id}-ftp  -v ${id}-wordpress-data:/home/${ftpUser} -v ${hostkeyDir}/${id}.ed25519:/etc/ssh/ssh_host_ed25519_key -v ${hostkeyDir}/${id}.rsa:/etc/ssh/ssh_host_rsa_key -d atmoz/sftp '${ftpUser}:${password.replace(
						'\n',
						''
					)}:e:1001'`
				);

				await startTcpProxy(destinationDocker, `${id}-ftp`, publicPort, 22);
			} else {
				await db.prisma.wordpress.update({
					where: { serviceId: id },
					data: { ftpPublicPort: null }
				});
				try {
					const isRunning = await checkContainer(engine, `${id}-ftp`);
					if (isRunning) {
						await asyncExecShell(
							`DOCKER_HOST=${host} docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
						);
					}
				} catch (error) {
					console.log(error);
					//
				}

				await stopTcpHttpProxy(destinationDocker, oldPublicPort);
			}
		}
		if (ftpEnabled) {
			return {
				status: 201,
				body: {
					publicPort,
					ftpUser,
					ftpPassword
				}
			};
		} else {
			return {
				status: 200,
				body: {}
			};
		}
	} catch (error) {
		console.log(error);
		return ErrorHandler(error);
	}
};
