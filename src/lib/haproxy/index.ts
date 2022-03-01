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

// export async function removeProxyConfiguration(fqdn) {
// 	const domain = getDomain(fqdn);
// 	const haproxy = await haproxyInstance();
// 	const backendFound = await haproxy
// 		.get(`v2/services/haproxy/configuration/backends/${domain}`)
// 		.json();
// 	if (backendFound) {
// 		const transactionId = await getNextTransactionId();
// 		await haproxy
// 			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
// 				searchParams: {
// 					transaction_id: transactionId
// 				}
// 			})
// 			.json();
// 		await completeTransaction(transactionId);
// 	}
// 	await forceSSLOffApplication(domain);
// 	await removeWwwRedirection(fqdn);
// }
// export async function forceSSLOffApplication(domain) {
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);

// 	let transactionId;

// 	try {
// 		const rules: any = await haproxy
// 			.get(`v2/services/haproxy/configuration/http_request_rules`, {
// 				searchParams: {
// 					parent_name: 'http',
// 					parent_type: 'frontend'
// 				}
// 			})
// 			.json();
// 		if (rules.data.length > 0) {
// 			const rule = rules.data.find((rule) =>
// 				rule.cond_test.includes(`{ hdr(host) -i ${domain} } !{ ssl_fc }`)
// 			);
// 			if (rule) {
// 				transactionId = await getNextTransactionId();
// 				await haproxy
// 					.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
// 						searchParams: {
// 							transaction_id: transactionId,
// 							parent_name: 'http',
// 							parent_type: 'frontend'
// 						}
// 					})
// 					.json();
// 			}
// 		}
// 	} catch (error) {
// 		console.log(error);
// 	} finally {
// 		if (transactionId) await completeTransaction(transactionId);
// 	}
// }
// export async function forceSSLOnApplication(domain) {
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);
// 	let transactionId;
// 	try {
// 		const rules: any = await haproxy
// 			.get(`v2/services/haproxy/configuration/http_request_rules`, {
// 				searchParams: {
// 					parent_name: 'http',
// 					parent_type: 'frontend'
// 				}
// 			})
// 			.json();
// 		let nextRule = 0;
// 		if (rules.data.length > 0) {
// 			const rule = rules.data.find((rule) =>
// 				rule.cond_test.includes(`{ hdr(host) -i ${domain} } !{ ssl_fc }`)
// 			);
// 			if (rule) return;
// 			nextRule = rules.data[rules.data.length - 1].index + 1;
// 		}
// 		transactionId = await getNextTransactionId();

// 		await haproxy
// 			.post(`v2/services/haproxy/configuration/http_request_rules`, {
// 				searchParams: {
// 					transaction_id: transactionId,
// 					parent_name: 'http',
// 					parent_type: 'frontend'
// 				},
// 				json: {
// 					index: nextRule,
// 					cond: 'if',
// 					cond_test: `{ hdr(host) -i ${domain} } !{ ssl_fc }`,
// 					type: 'redirect',
// 					redir_type: 'scheme',
// 					redir_value: 'https',
// 					redir_code: dev ? 302 : 301
// 				}
// 			})
// 			.json();
// 	} catch (error) {
// 		console.log(error);
// 		throw error;
// 	} finally {
// 		if (transactionId) await completeTransaction(transactionId);
// 	}
// }

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
		console.log(error.response.body);
	} finally {
		if (transactionId) await completeTransaction(transactionId);
	}
}

export async function reloadHaproxy(engine) {
	const host = getEngine(engine);
	return await asyncExecShell(`DOCKER_HOST=${host} docker exec coolify-haproxy kill -HUP 1`);
}
// export async function checkProxyConfigurations() {
// 	const timeout = 10;
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);
// 	try {
// 		const stats: any = await haproxy.get(`v2/services/haproxy/stats/native`).json();
// 		let transactionId = null;
// 		for (const stat of stats[0].stats) {
// 			if (stat.stats.status !== 'no check' && stat.type === 'server') {
// 				if (!transactionId) await getNextTransactionId();
// 				const { backend_name: backendName } = stat;
// 				await haproxy
// 					.delete(`v2/services/haproxy/configuration/backends/${backendName}`, {
// 						searchParams: {
// 							transaction_id: transactionId
// 						}
// 					})
// 					.json();
// 			}
// 		}
// 		if (transactionId) await completeTransaction(transactionId);
// 	} catch (error) {
// 		console.log(error.response.body);
// 	}
// }

// export async function configureCoolifyProxyOff(fqdn) {
// 	const domain = getDomain(fqdn);
// 	const isHttps = fqdn.startsWith('https://');
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);

// 	try {
// 		await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json();
// 		const transactionId = await getNextTransactionId();
// 		await haproxy
// 			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
// 				searchParams: {
// 					transaction_id: transactionId
// 				}
// 			})
// 			.json();
// 		await completeTransaction(transactionId);
// 		if (isHttps) await forceSSLOffApplication(domain);
// 		await removeWwwRedirection(fqdn);
// 	} catch (error) {
// 		throw error?.response?.body || error;
// 	}
// }
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
// export async function configureCoolifyProxyOn(fqdn) {
// 	const domain = getDomain(fqdn);
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);
// 	let serverConfigured = false;
// 	let backendAvailable: any = null;
// 	try {
// 		backendAvailable = await haproxy
// 			.get(`v2/services/haproxy/configuration/backends/${domain}`)
// 			.json();
// 		const server: any = await haproxy
// 			.get(`v2/services/haproxy/configuration/servers/coolify`, {
// 				searchParams: {
// 					backend: domain
// 				}
// 			})
// 			.json();
// 		if (backendAvailable && server) {
// 			// Very sophisticated way to check if the server is already configured in proxy
// 			if (backendAvailable.data.forwardfor.enabled === 'enabled') {
// 				if (backendAvailable.data.name === domain) {
// 					if (server.data.check === 'enabled') {
// 						if (server.data.address === dev ? 'host.docker.internal' : 'coolify') {
// 							if (server.data.port === 3000) {
// 								serverConfigured = true;
// 							}
// 						}
// 					}
// 				}
// 			}
// 		}
// 	} catch (error) {}
// 	if (serverConfigured) return;
// 	const transactionId = await getNextTransactionId();
// 	try {
// 		await haproxy.post('v2/services/haproxy/configuration/backends', {
// 			searchParams: {
// 				transaction_id: transactionId
// 			},
// 			json: {
// 				adv_check: 'httpchk',
// 				httpchk_params: {
// 					method: 'GET',
// 					uri: '/undead.json'
// 				},
// 				'init-addr': 'last,libc,none',
// 				forwardfor: { enabled: 'enabled' },
// 				name: domain
// 			}
// 		});
// 		await haproxy.post('v2/services/haproxy/configuration/servers', {
// 			searchParams: {
// 				transaction_id: transactionId,
// 				backend: domain
// 			},
// 			json: {
// 				address: dev ? 'host.docker.internal' : 'coolify',
// 				check: 'enable',
// 				fall: 10,
// 				name: 'coolify',
// 				port: 3000
// 			}
// 		});
// 	} catch (error) {
// 		console.log(error);
// 		throw error;
// 	} finally {
// 		await completeTransaction(transactionId);
// 	}
// }

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
export async function startTcpProxy(destinationDocker, id, publicPort, privatePort) {
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
				`DOCKER_HOST=${host} docker run --restart always -e PORT=${publicPort} -e APP=${id} -e PRIVATE_PORT=${privatePort} --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' --network ${network} -p ${publicPort}:${publicPort} --name ${containerName} -d coollabsio/${defaultProxyImageTcp}`
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
	const { proxyPassword, proxyUser } = await db.listSettings();
	if (!found) {
		const { stdout: Config } = await asyncExecShell(
			`DOCKER_HOST="${host}" docker network inspect bridge --format '{{json .IPAM.Config }}'`
		);
		const ip = JSON.parse(Config)[0].Gateway;
		await asyncExecShell(
			`DOCKER_HOST="${host}" docker run -e HAPROXY_USERNAME=${proxyUser} -e HAPROXY_PASSWORD=${proxyPassword} --restart always --add-host 'host.docker.internal:host-gateway' --add-host 'host.docker.internal:${ip}' -v coolify-ssl-certs:/usr/local/etc/haproxy/ssl --network coolify-infra -p "80:80" -p "443:443" -p "8404:8404" -p "5555:5555" -p "5000:5000" --name coolify-haproxy -d coollabsio/${defaultProxyImage}`
		);
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
		const isRunning = parsedStdout.Running;

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

// export async function configureSimpleServiceProxyOn({ id, domain, port }) {
// 	console.log({ service: true, id, domain, port });
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);
// 	let serverConfigured = false;
// 	let backendAvailable: any = null;

// 	try {
// 		backendAvailable = await haproxy
// 			.get(`v2/services/haproxy/configuration/backends/${domain}`)
// 			.json();
// 		const server: any = await haproxy
// 			.get(`v2/services/haproxy/configuration/servers/${id}`, {
// 				searchParams: {
// 					backend: domain
// 				}
// 			})
// 			.json();
// 		if (backendAvailable && server) {
// 			// Very sophisticated way to check if the server is already configured in proxy
// 			if (backendAvailable.data.forwardfor.enabled === 'enabled') {
// 				if (backendAvailable.data.name === domain) {
// 					if (server.data.check === 'enabled') {
// 						if (server.data.address === id) {
// 							if (server.data.port === port) {
// 								serverConfigured = true;
// 							}
// 						}
// 					}
// 				}
// 			}
// 		}
// 	} catch (error) {}
// 	if (serverConfigured) return;
// 	const transactionId = await getNextTransactionId();
// 	await haproxy.post('v2/services/haproxy/configuration/backends', {
// 		searchParams: {
// 			transaction_id: transactionId
// 		},
// 		json: {
// 			'init-addr': 'last,libc,none',
// 			forwardfor: { enabled: 'enabled' },
// 			name: domain
// 		}
// 	});
// 	await haproxy.post('v2/services/haproxy/configuration/servers', {
// 		searchParams: {
// 			transaction_id: transactionId,
// 			backend: domain
// 		},
// 		json: {
// 			address: id,
// 			check: 'enable',
// 			name: id,
// 			port: port
// 		}
// 	});
// 	await completeTransaction(transactionId);
// }

// export async function configureSimpleServiceProxyOff(fqdn) {
// 	if (!fqdn) {
// 		return;
// 	}
// 	const domain = getDomain(fqdn);
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);
// 	try {
// 		await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json();
// 		const transactionId = await getNextTransactionId();
// 		await haproxy
// 			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
// 				searchParams: {
// 					transaction_id: transactionId
// 				}
// 			})
// 			.json();
// 		await completeTransaction(transactionId);
// 	} catch (error) {}
// 	await forceSSLOffApplication(domain);
// 	await removeWwwRedirection(fqdn);
// 	return;
// }

// export async function removeWwwRedirection(fqdn) {
// 	const domain = getDomain(fqdn);
// 	const isHttps = fqdn.startsWith('https://');
// 	const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;

// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy();

// 	let transactionId;

// 	try {
// 		const rules: any = await haproxy
// 			.get(`v2/services/haproxy/configuration/http_request_rules`, {
// 				searchParams: {
// 					parent_name: 'http',
// 					parent_type: 'frontend'
// 				}
// 			})
// 			.json();
// 		if (rules.data.length > 0) {
// 			const rule = rules.data.find((rule) => rule.redir_value.includes(redirectValue));
// 			if (rule) {
// 				transactionId = await getNextTransactionId();
// 				await haproxy
// 					.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
// 						searchParams: {
// 							transaction_id: transactionId,
// 							parent_name: 'http',
// 							parent_type: 'frontend'
// 						}
// 					})
// 					.json();
// 			}
// 		}
// 	} catch (error) {
// 		console.log(error);
// 	} finally {
// 		if (transactionId) await completeTransaction(transactionId);
// 	}
// }
// export async function setWwwRedirection(fqdn) {
// 	const haproxy = await haproxyInstance();
// 	await checkHAProxy(haproxy);
// 	let transactionId;

// 	try {
// 		const domain = getDomain(fqdn);
// 		const isHttps = fqdn.startsWith('https://');
// 		const isWWW = fqdn.includes('www.');
// 		const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;
// 		const contTest = `{ req.hdr(host) -i ${isWWW ? domain.replace('www.', '') : `www.${domain}`} }`;
// 		const rules: any = await haproxy
// 			.get(`v2/services/haproxy/configuration/http_request_rules`, {
// 				searchParams: {
// 					parent_name: 'http',
// 					parent_type: 'frontend'
// 				}
// 			})
// 			.json();
// 		let nextRule = 0;
// 		if (rules.data.length > 0) {
// 			const rule = rules.data.find((rule) => rule.redir_value.includes(redirectValue));
// 			if (rule) return;
// 			nextRule = rules.data[rules.data.length - 1].index + 1;
// 		}

// 		transactionId = await getNextTransactionId();
// 		await haproxy
// 			.post(`v2/services/haproxy/configuration/http_request_rules`, {
// 				searchParams: {
// 					transaction_id: transactionId,
// 					parent_name: 'http',
// 					parent_type: 'frontend'
// 				},
// 				json: {
// 					index: nextRule,
// 					cond: 'if',
// 					cond_test: contTest,
// 					type: 'redirect',
// 					redir_type: 'location',
// 					redir_value: redirectValue,
// 					redir_code: dev ? 302 : 301
// 				}
// 			})
// 			.json();
// 	} catch (error) {
// 		console.log(error);
// 		throw error;
// 	} finally {
// 		if (transactionId) await completeTransaction(transactionId);
// 	}
// }
