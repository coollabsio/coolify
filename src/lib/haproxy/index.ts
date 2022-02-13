import { dev } from '$app/env';
import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import got from 'got';
import * as db from '$lib/database';
import { letsEncrypt } from '$lib/letsencrypt';

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

export async function removeProxyConfiguration({ domain }) {
	const haproxy = await haproxyInstance();
	const backendFound = await haproxy
		.get(`v2/services/haproxy/configuration/backends/${domain}`)
		.json();
	if (backendFound) {
		const transactionId = await getNextTransactionId();
		await haproxy
			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
		await completeTransaction(transactionId);
	}
	await removeWwwRedirection(domain);
}
export async function forceSSLOffApplication({ domain }) {
	if (!dev) {
		const haproxy = await haproxyInstance();
		await checkHAProxy(haproxy);
		const transactionId = await getNextTransactionId();
		try {
			const rules: any = await haproxy
				.get(`v2/services/haproxy/configuration/http_request_rules`, {
					searchParams: {
						parent_name: 'http',
						parent_type: 'frontend'
					}
				})
				.json();
			if (rules.data.length > 0) {
				const rule = rules.data.find((rule) => rule.cond_test.includes(`-i ${domain}`));
				if (rule) {
					await haproxy
						.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
							searchParams: {
								transaction_id: transactionId,
								parent_name: 'http',
								parent_type: 'frontend'
							}
						})
						.json();
				}
			}
		} catch (error) {
			console.log(error);
		} finally {
			await completeTransaction(transactionId);
		}
	} else {
		console.log(`[DEBUG] Removing ssl for ${domain}`);
	}
}
export async function forceSSLOnApplication({ domain }) {
	if (!dev) {
		const haproxy = await haproxyInstance();
		try {
			await checkHAProxy(haproxy);
		} catch (error) {
			return;
		}
		const transactionId = await getNextTransactionId();

		try {
			const rules: any = await haproxy
				.get(`v2/services/haproxy/configuration/http_request_rules`, {
					searchParams: {
						parent_name: 'http',
						parent_type: 'frontend'
					}
				})
				.json();
			let nextRule = 0;
			if (rules.data.length > 0) {
				const rule = rules.data.find((rule) =>
					rule.cond_test.includes(`{ hdr(host) -i ${domain} } !{ ssl_fc }`)
				);
				if (rule) return;
				nextRule = rules.data[rules.data.length - 1].index + 1;
			}
			await haproxy
				.post(`v2/services/haproxy/configuration/http_request_rules`, {
					searchParams: {
						transaction_id: transactionId,
						parent_name: 'http',
						parent_type: 'frontend'
					},
					json: {
						index: nextRule,
						cond: 'if',
						cond_test: `{ hdr(host) -i ${domain} } !{ ssl_fc }`,
						type: 'redirect',
						redir_type: 'scheme',
						redir_value: 'https',
						redir_code: 301
					}
				})
				.json();
		} catch (error) {
			console.log(error);
			throw error;
		} finally {
			await completeTransaction(transactionId);
		}
	} else {
		console.log(`[DEBUG] Adding ssl for ${domain}`);
	}
}

export async function deleteProxy({ id }) {
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}
	const transactionId = await getNextTransactionId();
	try {
		await haproxy.get(`v2/services/haproxy/configuration/backends/${id}`).json();
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
		await completeTransaction(transactionId);
	}
}

export async function reloadHaproxy(engine) {
	const host = getEngine(engine);
	return await asyncExecShell(`DOCKER_HOST=${host} docker exec coolify-haproxy kill -HUP 1`);
}
export async function configureProxyForApplication({ domain, imageId, applicationId, port }) {
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}

	let serverConfigured = false;
	let backendAvailable: any = null;

	try {
		backendAvailable = await haproxy
			.get(`v2/services/haproxy/configuration/backends/${domain}`)
			.json();
		const server: any = await haproxy
			.get(`v2/services/haproxy/configuration/servers/${imageId}`, {
				searchParams: {
					backend: domain
				}
			})
			.json();

		if (backendAvailable && server) {
			// Very sophisticated way to check if the server is already configured in proxy
			if (backendAvailable.data.forwardfor.enabled === 'enabled') {
				if (backendAvailable.data.name === domain) {
					if (server.data.check === 'enabled') {
						if (server.data.address === applicationId) {
							if (server.data.port === port) {
								serverConfigured = true;
							}
						}
					}
				}
			}
		}
	} catch (error) {
		//console.log('error getting backend or server', error?.response?.body);
		//
	}

	if (serverConfigured) return;
	const transactionId = await getNextTransactionId();
	if (backendAvailable) {
		await haproxy
			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
	}
	try {
		await haproxy.post('v2/services/haproxy/configuration/backends', {
			searchParams: {
				transaction_id: transactionId
			},
			json: {
				'init-addr': 'last,libc,none',
				forwardfor: { enabled: 'enabled' },
				name: domain
			}
		});

		await haproxy.post('v2/services/haproxy/configuration/servers', {
			searchParams: {
				transaction_id: transactionId,
				backend: domain
			},
			json: {
				address: imageId,
				check: 'enabled',
				name: imageId,
				port: port
			}
		});
	} catch (error) {
		throw error?.response?.body || error;
	} finally {
		await completeTransaction(transactionId);
	}
}

export async function configureCoolifyProxyOff(fqdn) {
	const domain = getDomain(fqdn);
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}

	try {
		const transactionId = await getNextTransactionId();
		await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json();
		await haproxy
			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
		await completeTransaction(transactionId);
		if (!dev) {
			await forceSSLOffApplication({ domain });
		}
		await setWwwRedirection(fqdn);
	} catch (error) {
		throw error?.response?.body || error;
	}
}
export async function checkHAProxy(haproxy) {
	if (!haproxy) haproxy = await haproxyInstance();
	try {
		await haproxy.get('v2/info');
	} catch (error) {
		throw 'HAProxy is not running, but it should be!';
	}
}
export async function configureCoolifyProxyOn(fqdn) {
	const domain = getDomain(fqdn);
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}
	let serverConfigured = false;
	let backendAvailable: any = null;
	try {
		backendAvailable = await haproxy
			.get(`v2/services/haproxy/configuration/backends/${domain}`)
			.json();
		const server: any = await haproxy
			.get(`v2/services/haproxy/configuration/servers/coolify`, {
				searchParams: {
					backend: domain
				}
			})
			.json();
		if (backendAvailable && server) {
			// Very sophisticated way to check if the server is already configured in proxy
			if (backendAvailable.data.forwardfor.enabled === 'enabled') {
				if (backendAvailable.data.name === domain) {
					if (server.data.check === 'enabled') {
						if (server.data.address === dev ? 'host.docker.internal' : 'coolify') {
							if (server.data.port === 3000) {
								serverConfigured = true;
							}
						}
					}
				}
			}
		}
	} catch (error) {}
	if (serverConfigured) return;
	const transactionId = await getNextTransactionId();
	try {
		await haproxy.post('v2/services/haproxy/configuration/backends', {
			searchParams: {
				transaction_id: transactionId
			},
			json: {
				adv_check: 'httpchk',
				httpchk_params: {
					method: 'GET',
					uri: '/undead.json'
				},
				'init-addr': 'last,libc,none',
				forwardfor: { enabled: 'enabled' },
				name: domain
			}
		});
		await haproxy.post('v2/services/haproxy/configuration/servers', {
			searchParams: {
				transaction_id: transactionId,
				backend: domain
			},
			json: {
				address: dev ? 'host.docker.internal' : 'coolify',
				check: 'enabled',
				fall: 10,
				name: 'coolify',
				port: 3000
			}
		});
	} catch (error) {
		console.log(error);
		throw error;
	} finally {
		await completeTransaction(transactionId);
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

export async function configureSimpleServiceProxyOn({ id, domain, port }) {
	const haproxy = await haproxyInstance();
	await checkHAProxy(haproxy);
	try {
		await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json();
		const transactionId = await getNextTransactionId();
		await haproxy
			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
		await completeTransaction(transactionId);
	} catch (error) {}
	try {
		const transactionId = await getNextTransactionId();
		await haproxy.post('v2/services/haproxy/configuration/backends', {
			searchParams: {
				transaction_id: transactionId
			},
			json: {
				'init-addr': 'last,libc,none',
				forwardfor: { enabled: 'enabled' },
				name: domain
			}
		});
		await haproxy.post('v2/services/haproxy/configuration/servers', {
			searchParams: {
				transaction_id: transactionId,
				backend: domain
			},
			json: {
				address: id,
				check: 'enabled',
				name: id,
				port: port
			}
		});
		console.log({
			address: id,
			check: 'enabled',
			name: id,
			port: port
		});
		await completeTransaction(transactionId);
	} catch (error) {
		console.log(error);
	}
}

export async function configureSimpleServiceProxyOff({ domain }) {
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}

	try {
		await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json();
		const transactionId = await getNextTransactionId();
		await haproxy
			.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
				searchParams: {
					transaction_id: transactionId
				}
			})
			.json();
		await completeTransaction(transactionId);
	} catch (error) {}
	await removeWwwRedirection(domain);
	return;
}

export async function removeWwwRedirection(domain) {
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}

	const rules: any = await haproxy
		.get(`v2/services/haproxy/configuration/http_request_rules`, {
			searchParams: {
				parent_name: 'http',
				parent_type: 'frontend'
			}
		})
		.json();
	if (rules.data.length > 0) {
		const rule = rules.data.find((rule) =>
			rule.redir_value.includes(`${domain}%[capture.req.uri]`)
		);
		if (rule) {
			const transactionId = await getNextTransactionId();
			await haproxy
				.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
					searchParams: {
						transaction_id: transactionId,
						parent_name: 'http',
						parent_type: 'frontend'
					}
				})
				.json();
			await completeTransaction(transactionId);
		}
	}
}
export async function setWwwRedirection(fqdn) {
	const haproxy = await haproxyInstance();
	try {
		await checkHAProxy(haproxy);
	} catch (error) {
		return;
	}
	const transactionId = await getNextTransactionId();

	try {
		const domain = getDomain(fqdn);
		const isHttps = fqdn.startsWith('https://');
		const isWWW = fqdn.includes('www.');
		const contTest = `{ req.hdr(host) -i ${isWWW ? domain.replace('www.', '') : `www.${domain}`} }`;
		const rules: any = await haproxy
			.get(`v2/services/haproxy/configuration/http_request_rules`, {
				searchParams: {
					parent_name: 'http',
					parent_type: 'frontend'
				}
			})
			.json();
		let nextRule = 0;
		if (rules.data.length > 0) {
			const rule = rules.data.find((rule) =>
				rule.redir_value.includes(`${domain}%[capture.req.uri]`)
			);
			if (rule) return;
			nextRule = rules.data[rules.data.length - 1].index + 1;
		}
		const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;
		await haproxy
			.post(`v2/services/haproxy/configuration/http_request_rules`, {
				searchParams: {
					transaction_id: transactionId,
					parent_name: 'http',
					parent_type: 'frontend'
				},
				json: {
					index: nextRule,
					cond: 'if',
					cond_test: contTest,
					type: 'redirect',
					redir_type: 'location',
					redir_value: redirectValue,
					redir_code: dev ? 302 : 301
				}
			})
			.json();
	} catch (error) {
		console.log(error);
		throw error;
	} finally {
		await completeTransaction(transactionId);
	}
}
