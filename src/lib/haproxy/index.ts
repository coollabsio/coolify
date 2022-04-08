import { dev } from '$app/env';
import { asyncExecShell, getEngine } from '$lib/common';
import got from 'got';
import * as db from '$lib/database';

const url = dev ? 'http://localhost:5555' : 'http://coolify-haproxy:5555';

export const defaultProxyImage = `coolify-haproxy-alpine:latest`;
export const defaultProxyImageTcp = `coolify-haproxy-tcp-alpine:latest`;
export const defaultProxyImageHttp = `coolify-haproxy-http-alpine:latest`;

export async function haproxyInstance() {
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

export async function completeTransaction(transactionId) {
	const haproxy = await haproxyInstance();
	return await haproxy.put(`v2/services/haproxy/transactions/${transactionId}`);
}
export async function deleteProxy({ id }) {
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

export async function reloadHaproxy(engine) {
	const host = getEngine(engine);
	return await asyncExecShell(`DOCKER_HOST=${host} docker exec coolify-haproxy kill -HUP 1`);
}
export async function checkHAProxy(haproxy?: any) {
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

export async function stopTcpHttpProxy(destinationDocker, publicPort) {
	const { engine } = destinationDocker;
	const host = getEngine(engine);
	const containerName = `haproxy-for-${publicPort}`;
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
export async function startTcpProxy(destinationDocker, id, publicPort, privatePort, volume = null) {
	const { network, engine } = destinationDocker;
	const host = getEngine(engine);

	const containerName = `haproxy-for-${publicPort}`;
	const found = await checkContainer(engine, containerName);
	const foundDB = await checkContainer(engine, id);

	try {
		if (foundDB && !found) {
			const { stdout: Config } = await asyncExecShell(
				`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker run --restart always -e PORT=${publicPort} -e APP=${id} -e PRIVATE_PORT=${privatePort} --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' --network ${network} -p ${publicPort}:${publicPort} --name ${containerName} ${
					volume ? `-v ${volume}` : ''
				} -d coollabsio/${defaultProxyImageTcp}`
			);
		}
	} catch (error) {
		return error;
	}
}
export async function startHttpProxy(destinationDocker, id, publicPort, privatePort) {
	const { network, engine } = destinationDocker;
	const host = getEngine(engine);

	const containerName = `haproxy-for-${publicPort}`;
	const found = await checkContainer(engine, containerName);
	const foundDB = await checkContainer(engine, id);

	try {
		if (foundDB && !found) {
			const { stdout: Config } = await asyncExecShell(
				`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			return await asyncExecShell(
				`DOCKER_HOST=${host} docker run --restart always -e PORT=${publicPort} -e APP=${id} -e PRIVATE_PORT=${privatePort} --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' --network ${network} -p ${publicPort}:${publicPort} --name ${containerName} -d coollabsio/${defaultProxyImageHttp}`
			);
		}
	} catch (error) {
		return error;
	}
}
export async function startCoolifyProxy(engine) {
	const host = getEngine(engine);
	const found = await checkContainer(engine, 'coolify-haproxy');
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
	}
	await configureNetworkCoolifyProxy(engine);
}
export async function checkContainer(engine, container) {
	const host = getEngine(engine);
	let containerFound = false;

	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker inspect --format '{{json .State}}' ${container}`
		);
		const parsedStdout = JSON.parse(stdout);
		const status = parsedStdout.Status;
		const isRunning = status === 'running' ? true : false;
		if (status === 'exited' || status === 'created') {
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

export async function stopCoolifyProxy(engine) {
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

export async function configureNetworkCoolifyProxy(engine) {
	const host = getEngine(engine);
	const destinations = await db.prisma.destinationDocker.findMany({ where: { engine } });
	destinations.forEach(async (destination) => {
		try {
			await asyncExecShell(
				`DOCKER_HOST="${host}" docker network connect ${destination.network} coolify-haproxy`
			);
		} catch (err) {
			// TODO: handle error
		}
	});
}
