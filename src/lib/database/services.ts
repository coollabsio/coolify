import { decrypt, encrypt } from '$lib/crypto';
import type { Minio, Prisma, Service } from '@prisma/client';
import cuid from 'cuid';
import { generatePassword } from '.';
import { prisma } from './common';

const include: Prisma.ServiceInclude = {
	destinationDocker: true,
	persistentStorage: true,
	serviceSecret: true,
	minio: true,
	plausibleAnalytics: true,
	vscodeserver: true,
	wordpress: true,
	ghost: true,
	meiliSearch: true,
	umami: true,
	hasura: true,
	fider: true
};
export async function listServicesWithIncludes() {
	return await prisma.service.findMany({
		include,
		orderBy: { createdAt: 'desc' }
	});
}
export async function listServices(teamId: string): Promise<Service[]> {
	if (teamId === '0') {
		return await prisma.service.findMany({ include: { teams: true } });
	} else {
		return await prisma.service.findMany({
			where: { teams: { some: { id: teamId } } },
			include: { teams: true }
		});
	}
}

export async function newService({
	name,
	teamId
}: {
	name: string;
	teamId: string;
}): Promise<Service> {
	return await prisma.service.create({ data: { name, teams: { connect: { id: teamId } } } });
}

export async function getService({ id, teamId }: { id: string; teamId: string }): Promise<Service> {
	let body;
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

	if (body?.serviceSecret.length > 0) {
		body.serviceSecret = body.serviceSecret.map((s) => {
			s.value = decrypt(s.value);
			return s;
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

	if (body.wordpress?.ftpPassword) body.wordpress.ftpPassword = decrypt(body.wordpress.ftpPassword);

	if (body.umami?.postgresqlPassword)
		body.umami.postgresqlPassword = decrypt(body.umami.postgresqlPassword);
	if (body.umami?.umamiAdminPassword)
		body.umami.umamiAdminPassword = decrypt(body.umami.umamiAdminPassword);
	if (body.umami?.hashSalt) body.umami.hashSalt = decrypt(body.umami.hashSalt);

	if (body.hasura?.postgresqlPassword)
		body.hasura.postgresqlPassword = decrypt(body.hasura.postgresqlPassword);
	if (body.hasura?.graphQLAdminPassword)
		body.hasura.graphQLAdminPassword = decrypt(body.hasura.graphQLAdminPassword);

	if (body.fider?.postgresqlPassword)
		body.fider.postgresqlPassword = decrypt(body.fider.postgresqlPassword);
	if (body.fider?.jwtSecret) body.fider.jwtSecret = decrypt(body.fider.jwtSecret);
	if (body.fider?.emailSmtpPassword)
		body.fider.emailSmtpPassword = decrypt(body.fider.emailSmtpPassword);

	const settings = await prisma.setting.findFirst();

	return { ...body, settings };
}

export async function configureServiceType({
	id,
	type
}: {
	id: string;
	type: string;
}): Promise<void> {
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
	} else if (type === 'umami') {
		const umamiAdminPassword = encrypt(generatePassword());
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'umami';
		const hashSalt = encrypt(generatePassword(64));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				umami: {
					create: {
						umamiAdminPassword,
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						hashSalt
					}
				}
			}
		});
	} else if (type === 'hasura') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'hasura';
		const graphQLAdminPassword = encrypt(generatePassword());
		await prisma.service.update({
			where: { id },
			data: {
				type,
				hasura: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						graphQLAdminPassword
					}
				}
			}
		});
	} else if (type === 'fider') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'fider';
		const jwtSecret = encrypt(generatePassword(64, true));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				fider: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						jwtSecret
					}
				}
			}
		});
	}
}

export async function setServiceVersion({
	id,
	version
}: {
	id: string;
	version: string;
}): Promise<Service> {
	return await prisma.service.update({
		where: { id },
		data: { version }
	});
}

export async function setServiceSettings({
	id,
	dualCerts
}: {
	id: string;
	dualCerts: boolean;
}): Promise<Service> {
	return await prisma.service.update({
		where: { id },
		data: { dualCerts }
	});
}

export async function updatePlausibleAnalyticsService({
	id,
	fqdn,
	email,
	exposePort,
	username,
	name,
	scriptName
}: {
	id: string;
	fqdn: string;
	exposePort?: number;
	name: string;
	email: string;
	username: string;
	scriptName: string;
}): Promise<void> {
	await prisma.plausibleAnalytics.update({
		where: { serviceId: id },
		data: { email, username, scriptName }
	});
	await prisma.service.update({ where: { id }, data: { name, fqdn, exposePort } });
}

export async function updateService({
	id,
	fqdn,
	exposePort,
	name
}: {
	id: string;
	fqdn: string;
	exposePort?: number;
	name: string;
}): Promise<Service> {
	return await prisma.service.update({ where: { id }, data: { fqdn, name, exposePort } });
}
export async function updateMinioService({
	id,
	fqdn,
	apiFqdn,
	exposePort,
	name
}: {
	id: string;
	fqdn: string;
	apiFqdn: string;
	exposePort?: number;
	name: string;
}): Promise<Service> {
	return await prisma.service.update({
		where: { id },
		data: { fqdn, name, exposePort, minio: { update: { apiFqdn } } }
	});
}
export async function updateFiderService({
	id,
	fqdn,
	name,
	exposePort,
	emailNoreply,
	emailMailgunApiKey,
	emailMailgunDomain,
	emailMailgunRegion,
	emailSmtpHost,
	emailSmtpPort,
	emailSmtpUser,
	emailSmtpPassword,
	emailSmtpEnableStartTls
}: {
	id: string;
	fqdn: string;
	exposePort?: number;
	name: string;
	emailNoreply: string;
	emailMailgunApiKey: string;
	emailMailgunDomain: string;
	emailMailgunRegion: string;
	emailSmtpHost: string;
	emailSmtpPort: number;
	emailSmtpUser: string;
	emailSmtpPassword: string;
	emailSmtpEnableStartTls: boolean;
}): Promise<Service> {
	return await prisma.service.update({
		where: { id },
		data: {
			fqdn,
			name,
			exposePort,
			fider: {
				update: {
					emailNoreply,
					emailMailgunApiKey,
					emailMailgunDomain,
					emailMailgunRegion,
					emailSmtpHost,
					emailSmtpPort,
					emailSmtpUser,
					emailSmtpPassword,
					emailSmtpEnableStartTls
				}
			}
		}
	});
}

export async function updateWordpress({
	id,
	fqdn,
	name,
	exposePort,
	ownMysql,
	mysqlDatabase,
	extraConfig,
	mysqlHost,
	mysqlPort,
	mysqlUser,
	mysqlPassword
}: {
	id: string;
	fqdn: string;
	name: string;
	exposePort?: number;
	ownMysql: boolean;
	mysqlDatabase: string;
	extraConfig: string;
	mysqlHost?: string;
	mysqlPort?: number;
	mysqlUser?: string;
	mysqlPassword?: string;
}): Promise<Service> {
	mysqlPassword = encrypt(mysqlPassword);
	return await prisma.service.update({
		where: { id },
		data: {
			fqdn,
			name,
			exposePort,
			wordpress: {
				update: {
					mysqlDatabase,
					extraConfig,
					mysqlHost,
					mysqlUser,
					mysqlPassword,
					mysqlPort
				}
			}
		}
	});
}

export async function updateMinioServicePort({
	id,
	publicPort
}: {
	id: string;
	publicPort: number;
}): Promise<Minio> {
	return await prisma.minio.update({ where: { serviceId: id }, data: { publicPort } });
}

export async function updateGhostService({
	id,
	fqdn,
	name,
	exposePort,
	mariadbDatabase
}: {
	id: string;
	fqdn: string;
	name: string;
	exposePort?: number;
	mariadbDatabase: string;
}): Promise<Service> {
	return await prisma.service.update({
		where: { id },
		data: { fqdn, name, exposePort, ghost: { update: { mariadbDatabase } } }
	});
}

export async function removeService({ id }: { id: string }): Promise<void> {
	await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
	await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
	await prisma.fider.deleteMany({ where: { serviceId: id } });
	await prisma.ghost.deleteMany({ where: { serviceId: id } });
	await prisma.umami.deleteMany({ where: { serviceId: id } });
	await prisma.hasura.deleteMany({ where: { serviceId: id } });
	await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
	await prisma.minio.deleteMany({ where: { serviceId: id } });
	await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
	await prisma.wordpress.deleteMany({ where: { serviceId: id } });
	await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });

	await prisma.service.delete({ where: { id } });
}
