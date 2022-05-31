import { dev } from '$app/env';
import { asyncExecShell, getEngine } from '$lib/common';
import got, { type Got, type Response } from 'got';
import * as db from '$lib/database';
import type { DestinationDocker } from '@prisma/client';
import fs from 'fs/promises';
import yaml from 'js-yaml';
const url = dev ? 'http://localhost:5555' : 'http://coolify-haproxy:5555';

export const defaultProxyImage = `coolify-haproxy-alpine:latest`;
export const defaultProxyImageTcp = `coolify-haproxy-tcp-alpine:latest`;
export const defaultProxyImageHttp = `coolify-haproxy-http-alpine:latest`;
export const defaultTraefikImage = `traefik:v2.6`;

const mainTraefikEndpoint = dev
	? 'http://host.docker.internal:3000/webhooks/traefik/main.json'
	: 'http://coolify:3000/webhooks/traefik/main.json';

const otherTraefikEndpoint = dev
	? 'http://host.docker.internal:3000/webhooks/traefik/other.json'
	: 'http://coolify:3000/webhooks/traefik/other.json';

export async function haproxyInstance(): Promise<Got> {
	const { proxyPassword } = await db.listSettings();
	return got.extend({
		prefixUrl: url,
		username: 'admin',
		password: proxyPassword
	});
}

export async function getRawConfiguration(): Promise<RawHaproxyConfiguration> {
	return await (await haproxyInstance()).get(`v2/services/haproxy/configuration/raw`).json();
}

export async function getNextTransactionVersion(): Promise<number> {
	const raw = await getRawConfiguration();
	if (raw?._version) {
		return raw._version;
	}
	return 1;
}

export async function getNextTransactionId(): Promise<string> {
	const version = await getNextTransactionVersion();
	const newTransaction: NewTransaction = await (
		await haproxyInstance()
	)
		.post('v2/services/haproxy/transactions', {
			searchParams: {
				version
			}
		})
		.json();
	return newTransaction.id;
}

export async function completeTransaction(transactionId: string): Promise<Response<string>> {
	const haproxy = await haproxyInstance();
	return await haproxy.put(`v2/services/haproxy/transactions/${transactionId}`);
}

export async function deleteProxy({ id }: { id: string }): Promise<void> {
	const haproxy = await haproxyInstance();
	await checkHAProxy(haproxy);

	let transactionId;
	try {
		await haproxy.get(`v2/services/haproxy/configuration/backends/${id}`).json();
		transactionId = await getNextTransactionId();
		await haproxy
			.delete(`v2/services/haproxy/configuration/backends/${id}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
		await haproxy.get(`v2/services/haproxy/configuration/frontends/${id}`).json();
		await haproxy
			.delete(`v2/services/haproxy/configuration/frontends/${id}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
	} catch (error) {
		console.log(error.response?.body || error);
	} finally {
		if (transactionId) await completeTransaction(transactionId);
	}
}

export async function reloadHaproxy(engine: string): Promise<{ stdout: string; stderr: string }> {
	const host = getEngine(engine);
	return await asyncExecShell(`DOCKER_HOST=${host} docker exec coolify-haproxy kill -HUP 1`);
}

export async function checkHAProxy(haproxy?: Got): Promise<void> {
	if (!haproxy) haproxy = await haproxyInstance();
	try {
		await haproxy.get('v2/info');
	} catch (error) {
		throw {
			message:
				'Coolify Proxy is not running, but it should be!<br><br>Start it in the "Destinations" menu.'
		};
	}
}

export async function stopTcpHttpProxy(
	id: string,
	destinationDocker: DestinationDocker,
	publicPort: number,
	forceName: string = null
): Promise<{ stdout: string; stderr: string } | Error> {
	const { engine } = destinationDocker;
	const host = getEngine(engine);
	const settings = await db.listSettings();
	let containerName = `${id}-${publicPort}`;
	if (!settings.isTraefikUsed) {
		containerName = `haproxy-for-${publicPort}`;
	}
	if (forceName) containerName = forceName;
	const found = await checkContainer(engine, containerName);

	try {
		if (found) {
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker stop -t 0 ${containerName} && docker rm ${containerName}`
			);
		}
	} catch (error) {
		return error;
	}
}
export async function startTraefikTCPProxy(
	destinationDocker: DestinationDocker,
	id: string,
	publicPort: number,
	privatePort: number,
	type?: string
): Promise<{ stdout: string; stderr: string } | Error> {
	const { network, engine } = destinationDocker;
	const host = getEngine(engine);
	const containerName = `${id}-${publicPort}`;
	const found = await checkContainer(engine, containerName, true);
	let dependentId = id;
	if (type === 'wordpressftp') dependentId = `${id}-ftp`;
	const foundDependentContainer = await checkContainer(engine, dependentId, true);
	try {
		if (foundDependentContainer && !found) {
			const { stdout: Config } = await asyncExecShell(
				`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			const tcpProxy = {
				version: '3.5',
				services: {
					[`${id}-${publicPort}`]: {
						container_name: containerName,
						image: 'traefik:v2.6',
						command: [
							`--entrypoints.tcp.address=:${publicPort}`,
							`--providers.http.endpoint=${otherTraefikEndpoint}?id=${id}&privatePort=${privatePort}&publicPort=${publicPort}&type=tcp`,
							'--providers.http.pollTimeout=2s',
							'--log.level=error'
						],
						ports: [`${publicPort}:${publicPort}`],
						extra_hosts: ['host.docker.internal:host-gateway', `host.docker.internal:${ip}`],
						volumes: ['/var/run/docker.sock:/var/run/docker.sock'],
						networks: ['coolify-infra', network]
					}
				},
				networks: {
					[network]: {
						external: false,
						name: network
					},
					'coolify-infra': {
						external: false,
						name: 'coolify-infra'
					}
				}
			};
			await fs.writeFile(`/tmp/docker-compose-${id}.yaml`, yaml.dump(tcpProxy));
			await asyncExecShell(
				`DOCKER_HOST=${host} docker compose -f /tmp/docker-compose-${id}.yaml up -d`
			);
			await fs.rm(`/tmp/docker-compose-${id}.yaml`);
		}
		if (!foundDependentContainer && found) {
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker stop -t 0 ${containerName} && docker rm ${containerName}`
			);
		}
	} catch (error) {
		console.log(error);
		return error;
	}
}
export async function startTcpProxy(
	destinationDocker: DestinationDocker,
	id: string,
	publicPort: number,
	privatePort: number
): Promise<{ stdout: string; stderr: string } | Error> {
	const { network, engine } = destinationDocker;
	const host = getEngine(engine);

	const containerName = `haproxy-for-${publicPort}`;
	const found = await checkContainer(engine, containerName, true);
	const foundDependentContainer = await checkContainer(engine, id, true);
	try {
		if (foundDependentContainer && !found) {
			const { stdout: Config } = await asyncExecShell(
				`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker run --restart always -e PORT=${publicPort} -e APP=${id} -e PRIVATE_PORT=${privatePort} --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' --network ${network} -p ${publicPort}:${publicPort} --name ${containerName} -d coollabsio/${defaultProxyImageTcp}`
			);
		}
		if (!foundDependentContainer && found) {
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker stop -t 0 ${containerName} && docker rm ${containerName}`
			);
		}
	} catch (error) {
		return error;
	}
}

export async function startTraefikHTTPProxy(
	destinationDocker: DestinationDocker,
	id: string,
	publicPort: number,
	privatePort: number
): Promise<{ stdout: string; stderr: string } | Error> {
	const { network, engine } = destinationDocker;
	const host = getEngine(engine);

	const containerName = `${id}-${publicPort}`;
	const found = await checkContainer(engine, containerName, true);
	const foundDependentContainer = await checkContainer(engine, id, true);

	try {
		if (foundDependentContainer && !found) {
			const { stdout: Config } = await asyncExecShell(
				`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			const tcpProxy = {
				version: '3.5',
				services: {
					[`${id}-${publicPort}`]: {
						container_name: containerName,
						image: 'traefik:v2.6',
						command: [
							`--entrypoints.http.address=:${publicPort}`,
							`--providers.http.endpoint=${otherTraefikEndpoint}?id=${id}&privatePort=${privatePort}&publicPort=${publicPort}&type=http`,
							'--providers.http.pollTimeout=2s',
							'--certificatesresolvers.letsencrypt.acme.httpchallenge=true',
							'--certificatesresolvers.letsencrypt.acme.storage=/etc/traefik/acme/acme.json',
							'--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http',
							'--log.level=error'
						],
						ports: [`${publicPort}:${publicPort}`],
						extra_hosts: ['host.docker.internal:host-gateway', `host.docker.internal:${ip}`],
						networks: ['coolify-infra', network],
						volumes: ['coolify-traefik-letsencrypt:/etc/traefik/acme']
					}
				},
				networks: {
					[network]: {
						external: false,
						name: network
					},
					'coolify-infra': {
						external: false,
						name: 'coolify-infra'
					}
				},
				volumes: {
					'coolify-traefik-letsencrypt': {}
				}
			};
			await fs.writeFile(`/tmp/docker-compose-${id}.yaml`, yaml.dump(tcpProxy));
			await asyncExecShell(
				`DOCKER_HOST=${host} docker compose -f /tmp/docker-compose-${id}.yaml up -d`
			);
			await fs.rm(`/tmp/docker-compose-${id}.yaml`);
		}
		if (!foundDependentContainer && found) {
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker stop -t 0 ${containerName} && docker rm ${containerName}`
			);
		}
	} catch (error) {
		return error;
	}
}
export async function startHttpProxy(
	destinationDocker: DestinationDocker,
	id: string,
	publicPort: number,
	privatePort: number
): Promise<{ stdout: string; stderr: string } | Error> {
	const { network, engine } = destinationDocker;
	const host = getEngine(engine);

	const containerName = `haproxy-for-${publicPort}`;
	const found = await checkContainer(engine, containerName, true);
	const foundDependentContainer = await checkContainer(engine, id, true);

	try {
		if (foundDependentContainer && !found) {
			const { stdout: Config } = await asyncExecShell(
				`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker run --restart always -e PORT=${publicPort} -e APP=${id} -e PRIVATE_PORT=${privatePort} --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' --network ${network} -p ${publicPort}:${publicPort} --name ${containerName} -d coollabsio/${defaultProxyImageHttp}`
			);
		}
		if (!foundDependentContainer && found) {
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker stop -t 0 ${containerName} && docker rm ${containerName}`
			);
		}
	} catch (error) {
		return error;
	}
}

export async function startCoolifyProxy(engine: string): Promise<void> {
	const host = getEngine(engine);
	const found = await checkContainer(engine, 'coolify-haproxy', true);
	const { proxyPassword, proxyUser, id } = await db.listSettings();
	if (!found) {
		const { stdout: Config } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
		);
		const ip = JSON.parse(Config)[0].Gateway;
		await asyncExecShell(
			`DOCKER_HOST="${host}" docker run -e HAPROXY_USERNAME=${proxyUser} -e HAPROXY_PASSWORD=${proxyPassword} --restart always --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' -v coolify-ssl-certs:/usr/local/etc/haproxy/ssl --network coolify-infra -p "80:80" -p "443:443" -p "8404:8404" -p "5555:5555" -p "5000:5000" --name coolify-haproxy -d coollabsio/${defaultProxyImage}`
		);
		await db.prisma.setting.update({ where: { id }, data: { proxyHash: null } });
		await db.setDestinationSettings({ engine, isCoolifyProxyUsed: true });
	}
	await configureNetworkCoolifyProxy(engine);
}

export async function startTraefikProxy(engine: string): Promise<void> {
	const host = getEngine(engine);
	const found = await checkContainer(engine, 'coolify-proxy', true);
	const { id, proxyPassword, proxyUser } = await db.listSettings();
	if (!found) {
		const { stdout: Config } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
		);
		const ip = JSON.parse(Config)[0].Gateway;
		await asyncExecShell(
			`DOCKER_HOST="${host}" docker run --restart always \
			--add-host 'host.docker.internal:host-gateway' \
			--add-host 'host.docker.internal:${ip}' \
			-v coolify-traefik-letsencrypt:/etc/traefik/acme \
			-v /var/run/docker.sock:/var/run/docker.sock \
			--network coolify-infra \
			-p "80:80" \
			-p "443:443" \
			-p "8080:8080" \
			--name coolify-proxy \
			-d ${defaultTraefikImage} \
			--api.insecure=true \
			--entrypoints.web.address=:80 \
			--entrypoints.websecure.address=:443 \
			--providers.docker=true \
			--providers.docker.exposedbydefault=false \
			--providers.http.endpoint=${mainTraefikEndpoint} \
			--providers.http.pollTimeout=5s \
			--certificatesresolvers.letsencrypt.acme.httpchallenge=true \
			--certificatesresolvers.letsencrypt.acme.storage=/etc/traefik/acme/acme.json \
			--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web \
			--log.level=error`
		);
		await db.prisma.setting.update({ where: { id }, data: { proxyHash: null } });
		await db.setDestinationSettings({ engine, isCoolifyProxyUsed: true });
	}
	await configureNetworkTraefikProxy(engine);
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
	} catch (error) {}

	return isExited;
}
export async function checkContainer(
	engine: string,
	container: string,
	remove: boolean = false
): Promise<boolean> {
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

export async function getContainerUsage(engine: string, container: string): Promise<any> {
	const host = getEngine(engine);
	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker container stats ${container} --no-stream --no-trunc --format "{{json .}}"`
		);
		return JSON.parse(stdout);
	} catch (err) {
		return {
			MemUsage: 0,
			CPUPerc: 0,
			NetIO: 0
		};
	}
}
export async function stopCoolifyProxy(
	engine: string
): Promise<{ stdout: string; stderr: string } | Error> {
	const host = getEngine(engine);
	const found = await checkContainer(engine, 'coolify-haproxy');
	await db.setDestinationSettings({ engine, isCoolifyProxyUsed: false });
	const { id } = await db.prisma.setting.findFirst({});
	await db.prisma.setting.update({ where: { id }, data: { proxyHash: null } });
	try {
		if (found) {
			await asyncExecShell(
				`DOCKER_HOST="${host}" docker stop -t 0 coolify-haproxy && docker rm coolify-haproxy`
			);
		}
	} catch (error) {
		return error;
	}
}
export async function stopTraefikProxy(
	engine: string
): Promise<{ stdout: string; stderr: string } | Error> {
	const host = getEngine(engine);
	const found = await checkContainer(engine, 'coolify-proxy');
	await db.setDestinationSettings({ engine, isCoolifyProxyUsed: false });
	const { id } = await db.prisma.setting.findFirst({});
	await db.prisma.setting.update({ where: { id }, data: { proxyHash: null } });
	try {
		if (found) {
			await asyncExecShell(
				`DOCKER_HOST="${host}" docker stop -t 0 coolify-proxy && docker rm coolify-proxy`
			);
		}
	} catch (error) {
		return error;
	}
}

export async function configureNetworkCoolifyProxy(engine: string): Promise<void> {
	const host = getEngine(engine);
	const destinations = await db.prisma.destinationDocker.findMany({ where: { engine } });
	const { stdout: networks } = await asyncExecShell(
		`DOCKER_HOST="${host}" docker ps -a --filter name=coolify-haproxy --format '{{json .Networks}}'`
	);
	const configuredNetworks = networks.replace(/"/g, '').replace('\n', '').split(',');
	for (const destination of destinations) {
		if (!configuredNetworks.includes(destination.network)) {
			await asyncExecShell(
				`DOCKER_HOST="${host}" docker network connect ${destination.network} coolify-haproxy`
			);
		}
	}
}

export async function configureNetworkTraefikProxy(engine: string): Promise<void> {
	const host = getEngine(engine);
	const destinations = await db.prisma.destinationDocker.findMany({ where: { engine } });
	const { stdout: networks } = await asyncExecShell(
		`DOCKER_HOST="${host}" docker ps -a --filter name=coolify-proxy --format '{{json .Networks}}'`
	);
	const configuredNetworks = networks.replace(/"/g, '').replace('\n', '').split(',');
	for (const destination of destinations) {
		if (!configuredNetworks.includes(destination.network)) {
			await asyncExecShell(
				`DOCKER_HOST="${host}" docker network connect ${destination.network} coolify-proxy`
			);
		}
	}
}
