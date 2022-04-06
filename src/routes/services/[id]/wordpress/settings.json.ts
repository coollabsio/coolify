import { dev } from '$app/env';
import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import { decrypt, encrypt } from '$lib/crypto';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, ErrorHandler, generatePassword } from '$lib/database';
import { checkContainer, startTcpProxy, stopTcpHttpProxy } from '$lib/haproxy';
import type { ComposeFile } from '$lib/types/composeFile';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import fs from 'fs/promises';
import getPort, { portNumbers } from 'get-port';
import yaml from 'js-yaml';

export const post: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const data = await db.prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const { ftpEnabled } = await event.request.json();
	const publicPort = await getPort({ port: portNumbers(minPort, maxPort) });
	let ftpUser = cuid();
	let ftpPassword = generatePassword();

	const hostkeyDir = dev ? '/tmp/hostkeys' : '/app/ssl/hostkeys';
	try {
		const data = await db.prisma.wordpress.update({
			where: { serviceId: id },
			data: { ftpEnabled },
			include: { service: { include: { destinationDocker: true } } }
		});
		const {
			service: { destinationDockerId, destinationDocker },
			ftpPublicPort: oldPublicPort,
			ftpUser: user,
			ftpPassword: savedPassword,
			ftpHostKey,
			ftpHostKeyPrivate
		} = data;
		if (user) ftpUser = user;
		if (savedPassword) ftpPassword = decrypt(savedPassword);

		const { stdout: password } = await asyncExecShell(
			`echo ${ftpPassword} | openssl passwd -1 -stdin`
		);
		if (destinationDockerId) {
			try {
				await fs.stat(hostkeyDir);
			} catch (error) {
				await asyncExecShell(`mkdir -p ${hostkeyDir}`);
			}
			if (!ftpHostKey) {
				await asyncExecShell(
					`ssh-keygen -t ed25519 -f ssh_host_ed25519_key -N "" -q -f ${hostkeyDir}/${id}.ed25519`
				);
				const { stdout: ftpHostKey } = await asyncExecShell(`cat ${hostkeyDir}/${id}.ed25519`);
				await db.prisma.wordpress.update({
					where: { serviceId: id },
					data: { ftpHostKey: encrypt(ftpHostKey) }
				});
			} else {
				await asyncExecShell(`echo "${decrypt(ftpHostKey)}" > ${hostkeyDir}/${id}.ed25519`);
			}
			if (!ftpHostKeyPrivate) {
				await asyncExecShell(`ssh-keygen -t rsa -b 4096 -N "" -f ${hostkeyDir}/${id}.rsa`);
				const { stdout: ftpHostKeyPrivate } = await asyncExecShell(`cat ${hostkeyDir}/${id}.rsa`);
				await db.prisma.wordpress.update({
					where: { serviceId: id },
					data: { ftpHostKeyPrivate: encrypt(ftpHostKeyPrivate) }
				});
			} else {
				await asyncExecShell(`echo "${decrypt(ftpHostKeyPrivate)}" > ${hostkeyDir}/${id}.rsa`);
			}
			const { network, engine } = destinationDocker;
			const host = getEngine(engine);
			if (ftpEnabled) {
				await db.prisma.wordpress.update({
					where: { serviceId: id },
					data: {
						ftpPublicPort: publicPort,
						ftpUser: user ? undefined : ftpUser,
						ftpPassword: savedPassword ? undefined : encrypt(ftpPassword)
					}
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
				const volumes = [
					`${id}-wordpress-data:/home/${ftpUser}`,
					`${
						dev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
					}/${id}.ed25519:/etc/ssh/ssh_host_ed25519_key`,
					`${
						dev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
					}/${id}.rsa:/etc/ssh/ssh_host_rsa_key`,
					`${
						dev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
					}/${id}.sh:/etc/sftp.d/chmod.sh`
				];

				const compose: ComposeFile = {
					version: '3.8',
					services: {
						[`${id}-ftp`]: {
							image: `atmoz/sftp:alpine`,
							command: `'${ftpUser}:${password.replace('\n', '').replace(/\$/g, '$$$')}:e:1001'`,
							extra_hosts: ['host.docker.internal:host-gateway'],
							container_name: `${id}-ftp`,
							volumes,
							networks: [network],
							depends_on: [],
							restart: 'always'
						}
					},
					networks: {
						[network]: {
							external: true
						}
					},
					volumes: {
						[`${id}-wordpress-data`]: {
							external: true,
							name: `${id}-wordpress-data`
						}
					}
				};
				await fs.writeFile(
					`${hostkeyDir}/${id}.sh`,
					`#!/bin/bash\nchmod 600 /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_rsa_key`
				);
				await asyncExecShell(`chmod +x ${hostkeyDir}/${id}.sh`);
				await fs.writeFile(`${hostkeyDir}/${id}-docker-compose.yml`, yaml.dump(compose));
				await asyncExecShell(
					`DOCKER_HOST=${host} docker compose -f ${hostkeyDir}/${id}-docker-compose.yml up -d`
				);

				await startTcpProxy(destinationDocker, `${id}-ftp`, publicPort, 22);
			} else {
				await db.prisma.wordpress.update({
					where: { serviceId: id },
					data: { ftpPublicPort: null }
				});
				try {
					await asyncExecShell(
						`DOCKER_HOST=${host} docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
					);
				} catch (error) {
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
	} finally {
		await asyncExecShell(
			`rm -f ${hostkeyDir}/${id}-docker-compose.yml ${hostkeyDir}/${id}.ed25519 ${hostkeyDir}/${id}.ed25519.pub ${hostkeyDir}/${id}.rsa ${hostkeyDir}/${id}.rsa.pub ${hostkeyDir}/${id}.sh`
		);
	}
};
