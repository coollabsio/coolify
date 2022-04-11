import { asyncExecShell, getEngine } from '$lib/common';
import { decrypt, encrypt } from '$lib/crypto';
import cuid from 'cuid';
import { generatePassword } from '.';
import { prisma } from './common';

export async function listServices(teamId) {
	if (teamId === '0') {
		return await prisma.service.findMany({ include: { teams: true } });
	} else {
		return await prisma.service.findMany({
			where: { teams: { some: { id: teamId } } },
			include: { teams: true }
		});
	}
}

export async function newService({ name, teamId }) {
	return await prisma.service.create({ data: { name, teams: { connect: { id: teamId } } } });
}

export async function getService({ id, teamId }) {
	let body = {};
	const include = {
		destinationDocker: true,
		plausibleAnalytics: true,
		minio: true,
		vscodeserver: true,
		wordpress: true,
		ghost: true,
		serviceSecret: true,
		meiliSearch: true
	};
	if (teamId === '0') {
		body = await prisma.service.findFirst({
			where: { id },
			include
		});
	} else {
		body = await prisma.service.findFirst({
			where: { id, teams: { some: { id: teamId } } },
			include
		});
	}

	if (body.plausibleAnalytics?.postgresqlPassword)
		body.plausibleAnalytics.postgresqlPassword = decrypt(
			body.plausibleAnalytics.postgresqlPassword
		);
	if (body.plausibleAnalytics?.password)
		body.plausibleAnalytics.password = decrypt(body.plausibleAnalytics.password);
	if (body.plausibleAnalytics?.secretKeyBase)
		body.plausibleAnalytics.secretKeyBase = decrypt(body.plausibleAnalytics.secretKeyBase);

	if (body.minio?.rootUserPassword)
		body.minio.rootUserPassword = decrypt(body.minio.rootUserPassword);

	if (body.vscodeserver?.password) body.vscodeserver.password = decrypt(body.vscodeserver.password);

	if (body.wordpress?.mysqlPassword)
		body.wordpress.mysqlPassword = decrypt(body.wordpress.mysqlPassword);
	if (body.wordpress?.mysqlRootUserPassword)
		body.wordpress.mysqlRootUserPassword = decrypt(body.wordpress.mysqlRootUserPassword);

	if (body.ghost?.mariadbPassword) body.ghost.mariadbPassword = decrypt(body.ghost.mariadbPassword);
	if (body.ghost?.mariadbRootUserPassword)
		body.ghost.mariadbRootUserPassword = decrypt(body.ghost.mariadbRootUserPassword);
	if (body.ghost?.defaultPassword) body.ghost.defaultPassword = decrypt(body.ghost.defaultPassword);

	if (body.meiliSearch?.masterKey) body.meiliSearch.masterKey = decrypt(body.meiliSearch.masterKey);

	if (body?.serviceSecret.length > 0) {
		body.serviceSecret = body.serviceSecret.map((s) => {
			s.value = decrypt(s.value);
			return s;
		});
	}
	if (body.wordpress?.ftpPassword) {
		body.wordpress.ftpPassword = decrypt(body.wordpress.ftpPassword);
	}
	const settings = await prisma.setting.findFirst();

	return { ...body, settings };
}

export async function configureServiceType({ id, type }) {
	if (type === 'plausibleanalytics') {
		const password = encrypt(generatePassword());
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'plausibleanalytics';
		const secretKeyBase = encrypt(generatePassword(64));

		await prisma.service.update({
			where: { id },
			data: {
				type,
				plausibleAnalytics: {
					create: {
						postgresqlDatabase,
						postgresqlUser,
						postgresqlPassword,
						password,
						secretKeyBase
					}
				}
			}
		});
	} else if (type === 'nocodb') {
		await prisma.service.update({
			where: { id },
			data: { type }
		});
	} else if (type === 'minio') {
		const rootUser = cuid();
		const rootUserPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: { type, minio: { create: { rootUser, rootUserPassword } } }
		});
	} else if (type === 'vscodeserver') {
		const password = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: { type, vscodeserver: { create: { password } } }
		});
	} else if (type === 'wordpress') {
		const mysqlUser = cuid();
		const mysqlPassword = encrypt(generatePassword());
		const mysqlRootUser = cuid();
		const mysqlRootUserPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: {
				type,
				wordpress: { create: { mysqlPassword, mysqlRootUserPassword, mysqlRootUser, mysqlUser } }
			}
		});
	} else if (type === 'vaultwarden') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'languagetool') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'n8n') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'uptimekuma') {
		await prisma.service.update({
			where: { id },
			data: {
				type
			}
		});
	} else if (type === 'ghost') {
		const defaultEmail = `${cuid()}@example.com`;
		const defaultPassword = encrypt(generatePassword());
		const mariadbUser = cuid();
		const mariadbPassword = encrypt(generatePassword());
		const mariadbRootUser = cuid();
		const mariadbRootUserPassword = encrypt(generatePassword());

		await prisma.service.update({
			where: { id },
			data: {
				type,
				ghost: {
					create: {
						defaultEmail,
						defaultPassword,
						mariadbUser,
						mariadbPassword,
						mariadbRootUser,
						mariadbRootUserPassword
					}
				}
			}
		});
	} else if (type === 'meilisearch') {
		const masterKey = encrypt(generatePassword(32));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				meiliSearch: { create: { masterKey } }
			}
		});
	}
}
export async function setServiceVersion({ id, version }) {
	return await prisma.service.update({
		where: { id },
		data: { version }
	});
}

export async function setServiceSettings({ id, dualCerts }) {
	return await prisma.service.update({
		where: { id },
		data: { dualCerts }
	});
}

export async function updatePlausibleAnalyticsService({ id, fqdn, email, username, name }) {
	await prisma.plausibleAnalytics.update({ where: { serviceId: id }, data: { email, username } });
	await prisma.service.update({ where: { id }, data: { name, fqdn } });
}
export async function updateService({ id, fqdn, name }) {
	return await prisma.service.update({ where: { id }, data: { fqdn, name } });
}
export async function updateWordpress({ id, fqdn, name, mysqlDatabase, extraConfig }) {
	return await prisma.service.update({
		where: { id },
		data: { fqdn, name, wordpress: { update: { mysqlDatabase, extraConfig } } }
	});
}
export async function updateMinioService({ id, publicPort }) {
	return await prisma.minio.update({ where: { serviceId: id }, data: { publicPort } });
}
export async function updateGhostService({ id, fqdn, name, mariadbDatabase }) {
	return await prisma.service.update({
		where: { id },
		data: { fqdn, name, ghost: { update: { mariadbDatabase } } }
	});
}

export async function removeService({ id }) {
	await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
	await prisma.ghost.deleteMany({ where: { serviceId: id } });
	await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
	await prisma.minio.deleteMany({ where: { serviceId: id } });
	await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
	await prisma.wordpress.deleteMany({ where: { serviceId: id } });
	await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });

	await prisma.service.delete({ where: { id } });
}
