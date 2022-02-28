import { configureHAProxy } from '$lib/haproxy/configuration';

export default async function () {
	try {
		return await configureHAProxy();
	} catch (error) {
		console.log(error.response.body || error);
	}
	// const haproxy = await haproxyInstance();
	// await checkHAProxy(haproxy);
	// const transactionId = await getNextTransactionId();
	// let executeTransaction = {
	// 	applications: false,
	// 	services: false
	// }
	// try {
	// 	await checkProxyConfigurations();
	// } catch (error) {
	// 	console.log(error);
	// }
	// try {
	// 	const applications = await db.prisma.application.findMany({
	// 		include: { destinationDocker: true }
	// 	});

	// 	for (const application of applications) {
	// 		const {
	// 			fqdn,
	// 			id,
	// 			port,
	// 			destinationDocker: { engine }
	// 		} = application;
	// 		const containerRunning = await checkContainer(engine, id);
	// 		executeTransaction.applications = await configureHAProxy(
	// 			haproxy,
	// 			transactionId,
	// 			fqdn,
	// 			id,
	// 			port,
	// 			containerRunning,
	// 			engine
	// 		);
	// 	}

	// 	const services = await db.prisma.service.findMany({
	// 		include: {
	// 			destinationDocker: true,
	// 			minio: true,
	// 			plausibleAnalytics: true,
	// 			vscodeserver: true,
	// 			wordpress: true
	// 		}
	// 	});

	// 	for (const service of services) {
	// 		const {
	// 			fqdn,
	// 			id,
	// 			type,
	// 			destinationDocker: { engine }
	// 		} = service;
	// 		const found = db.supportedServiceTypesAndVersions.find((a) => a.name === type);
	// 		if (found) {
	// 			console.log(found);
	// 			const port = found.ports.main;
	// 			const publicPort = service[type]?.publicPort;
	// 			const containerRunning = await checkContainer(engine, id);
	// 			executeTransaction.services = await configureHAProxy(
	// 				haproxy,
	// 				transactionId,
	// 				fqdn,
	// 				id,
	// 				port,
	// 				containerRunning,
	// 				engine
	// 			);
	// 			if (publicPort) {
	// 				const containerFound = await checkContainer(
	// 					service.destinationDocker.engine,
	// 					`haproxy-for-${publicPort}`
	// 				);
	// 				if (!containerFound) {
	// 					await startHttpProxy(service.destinationDocker, id, publicPort, 9000);
	// 				}
	// 			}
	// 		}
	// 	}
	// 	if (executeTransaction.applications || executeTransaction.services) {
	// 		await haproxy.put(`v2/services/haproxy/transactions/${transactionId}`);
	// 	}
	// 	// Check Coolify FQDN and configure proxy if needed
	// 	// const { fqdn } = await db.listSettings();
	// 	// if (fqdn) {
	// 	// 	const domain = getDomain(fqdn);
	// 	// 	await startCoolifyProxy('/var/run/docker.sock');
	// 	// 	await configureCoolifyProxyOn(fqdn);
	// 	// 	await setWwwRedirection(fqdn);
	// 	// 	const isHttps = fqdn.startsWith('https://');
	// 	// 	if (isHttps) await forceSSLOnApplication(domain);
	// 	// }
	// } catch (error) {
	// 	throw error;
	// }
}
