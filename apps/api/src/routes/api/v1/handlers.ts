import { compareVersions } from 'compare-versions';
import cuid from 'cuid';
import bcrypt from 'bcryptjs';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import {
	asyncSleep,
	cleanupDockerStorage,
	errorHandler,
	isDev,
	listSettings,
	prisma,
	uniqueName,
	version,
	sentryDSN,
	executeCommand
} from '../../../lib/common';
import { scheduler } from '../../../lib/scheduler';
import type { FastifyReply, FastifyRequest } from 'fastify';
import type { Login, Update } from '.';
import type { GetCurrentUser } from './types';

export async function hashPassword(password: string): Promise<string> {
	const saltRounds = 15;
	return bcrypt.hash(password, saltRounds);
}

export async function backup(request: FastifyRequest) {
	try {
		const { backupData } = request.params;
		let std = null;
		const [id, backupType, type, zipped, storage] = backupData.split(':');
		console.log(id, backupType, type, zipped, storage);
		const database = await prisma.database.findUnique({ where: { id } });
		if (database) {
			// await executeDockerCmd({
			// 	dockerId: database.destinationDockerId,
			// 	command: `docker pull coollabsio/backup:latest`,
			// })
			std = await executeCommand({
				dockerId: database.destinationDockerId,
				command: `docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v coolify-local-backup:/app/backups -e CONTAINERS_TO_BACKUP="${backupData}" coollabsio/backup`
			});
		}
		if (std.stdout) {
			return std.stdout;
		}
		if (std.stderr) {
			return std.stderr;
		}
		return 'nope';
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function cleanupManually(request: FastifyRequest) {
	try {
		const { serverId } = request.body;
		const destination = await prisma.destinationDocker.findUnique({
			where: { id: serverId }
		});
		await cleanupDockerStorage(destination.id, true, true);
		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function refreshTags() {
	try {
		const { default: got } = await import('got');
		try {
			if (isDev) {
				let tags = await fs.readFile('./devTags.json', 'utf8');
				try {
					if (await fs.stat('./testTags.json')) {
						const testTags = await fs.readFile('./testTags.json', 'utf8');
						if (testTags.length > 0) {
							tags = JSON.parse(tags).concat(JSON.parse(testTags));
						}
					}
				} catch (error) {}
				await fs.writeFile('./tags.json', tags);
			} else {
				const tags = await got.get('https://get.coollabs.io/coolify/service-tags.json').text();
				await fs.writeFile('/app/tags.json', tags);
			}
		} catch (error) {
			console.log(error);
		}

		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function refreshTemplates() {
	try {
		const { default: got } = await import('got');
		try {
			if (isDev) {
				let templates = await fs.readFile('./devTemplates.yaml', 'utf8');
				try {
					if (await fs.stat('./testTemplate.yaml')) {
						templates = templates + (await fs.readFile('./testTemplate.yaml', 'utf8'));
					}
				} catch (error) {}
				const response = await fs.readFile('./devTemplates.yaml', 'utf8');
				await fs.writeFile('./templates.json', JSON.stringify(yaml.load(response)));
			} else {
				const response = await got
					.get('https://get.coollabs.io/coolify/service-templates.yaml')
					.text();
				await fs.writeFile('/app/templates.json', JSON.stringify(yaml.load(response)));
			}
		} catch (error) {
			console.log(error);
		}
		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function checkUpdate(request: FastifyRequest) {
	try {
		const { default: got } = await import('got');
		const isStaging =
			request.hostname === 'staging.coolify.io' || request.hostname === 'arm.coolify.io';
		const currentVersion = version;
		const { coolify } = await got
			.get('https://get.coollabs.io/versions.json', {
				searchParams: {
					appId: process.env['COOLIFY_APP_ID'] || undefined,
					version: currentVersion
				}
			})
			.json();
		const latestVersion = coolify.main.version;
		const isUpdateAvailable = compareVersions(latestVersion, currentVersion);
		if (isStaging) {
			return {
				isUpdateAvailable: true,
				latestVersion: 'next'
			};
		}
		return {
			isUpdateAvailable: isStaging ? true : isUpdateAvailable === 1,
			latestVersion
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function update(request: FastifyRequest<Update>) {
	const { latestVersion } = request.body;
	try {
		if (!isDev) {
			const { isAutoUpdateEnabled } = await prisma.setting.findFirst();
			await executeCommand({ command: `docker pull coollabsio/coolify:${latestVersion}` });
			await executeCommand({ shell: true, command: `env | grep COOLIFY > .env` });
			await executeCommand({
				command: `sed -i '/COOLIFY_AUTO_UPDATE=/cCOOLIFY_AUTO_UPDATE=${isAutoUpdateEnabled}' .env`
			});
			await executeCommand({
				shell: true,
				command: `docker run --rm -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && echo 'TAG=${latestVersion}' >> .env && docker stop -t 0 coolify coolify-fluentbit && docker rm coolify coolify-fluentbit && docker compose pull && docker compose up -d --force-recreate"`
			});
			return {};
		} else {
			await asyncSleep(2000);
			return {};
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function resetQueue(request: FastifyRequest<any>) {
	try {
		const teamId = request.user.teamId;
		if (teamId === '0') {
			await prisma.build.updateMany({
				where: { status: { in: ['queued', 'running'] } },
				data: { status: 'canceled' }
			});
			scheduler.workers.get('deployApplication').postMessage('cancel');
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function restartCoolify(request: FastifyRequest<any>) {
	try {
		const teamId = request.user.teamId;
		if (teamId === '0') {
			if (!isDev) {
				await executeCommand({ command: `docker restart coolify` });
				return {};
			} else {
				return {};
			}
		}
		throw {
			status: 500,
			message: 'You are not authorized to restart Coolify.'
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function showDashboard(request: FastifyRequest) {
	try {
		const userId = request.user.userId;
		const teamId = request.user.teamId;
		let applications = await prisma.application.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { settings: true, destinationDocker: true, teams: true }
		});
		const databases = await prisma.database.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { settings: true, destinationDocker: true, teams: true }
		});
		const services = await prisma.service.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { destinationDocker: true, teams: true }
		});
		const gitSources = await prisma.gitSource.findMany({
			where: {
				OR: [
					{ teams: { some: { id: teamId === '0' ? undefined : teamId } } },
					{ isSystemWide: true }
				]
			},
			include: { teams: true }
		});
		const destinations = await prisma.destinationDocker.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { teams: true }
		});
		const settings = await listSettings();

		let foundUnconfiguredApplication = false;
		for (const application of applications) {
			if (
				((!application.buildPack || !application.branch) && !application.simpleDockerfile) ||
				!application.destinationDockerId ||
				(!application.settings?.isBot && !application?.fqdn && application.buildPack !== 'compose')
			) {
				foundUnconfiguredApplication = true;
			}
		}
		let foundUnconfiguredService = false;
		for (const service of services) {
			if (!service.fqdn) {
				foundUnconfiguredService = true;
			}
		}
		let foundUnconfiguredDatabase = false;
		for (const database of databases) {
			if (!database.version) {
				foundUnconfiguredDatabase = true;
			}
		}
		return {
			foundUnconfiguredApplication,
			foundUnconfiguredDatabase,
			foundUnconfiguredService,
			applications,
			databases,
			services,
			gitSources,
			destinations,
			settings
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function login(request: FastifyRequest<Login>, reply: FastifyReply) {
	if (request.user) {
		return reply.redirect('/dashboard');
	} else {
		const { email, password, isLogin } = request.body || {};
		if (!email || !password) {
			throw { status: 500, message: 'Email and password are required.' };
		}
		const users = await prisma.user.count();
		const userFound = await prisma.user.findUnique({
			where: { email },
			include: { teams: true, permission: true },
			rejectOnNotFound: false
		});
		if (!userFound && isLogin) {
			throw { status: 500, message: 'User not found.' };
		}
		const { isRegistrationEnabled, id } = await prisma.setting.findFirst();
		let uid = cuid();
		let permission = 'read';
		let isAdmin = false;

		if (users === 0) {
			await prisma.setting.update({
				where: { id },
				data: { isRegistrationEnabled: false }
			});
			uid = '0';
		}
		if (userFound) {
			if (userFound.type === 'email') {
				if (userFound.password === 'RESETME') {
					const hashedPassword = await hashPassword(password);
					if (userFound.updatedAt < new Date(Date.now() - 1000 * 60 * 10)) {
						if (userFound.id === '0') {
							await prisma.user.update({
								where: { email: userFound.email },
								data: { password: 'RESETME' }
							});
						} else {
							await prisma.user.update({
								where: { email: userFound.email },
								data: { password: 'RESETTIMEOUT' }
							});
						}

						throw {
							status: 500,
							message: 'Password reset link has expired. Please request a new one.'
						};
					} else {
						await prisma.user.update({
							where: { email: userFound.email },
							data: { password: hashedPassword }
						});
						return {
							userId: userFound.id,
							teamId: userFound.id,
							permission: userFound.permission,
							isAdmin: true
						};
					}
				}

				const passwordMatch = await bcrypt.compare(password, userFound.password);
				if (!passwordMatch) {
					throw {
						status: 500,
						message: 'Wrong password or email address.'
					};
				}
				uid = userFound.id;
				isAdmin = true;
			}
		} else {
			permission = 'owner';
			isAdmin = true;
			if (!isRegistrationEnabled) {
				throw {
					status: 404,
					message: 'Registration disabled by administrator.'
				};
			}
			const hashedPassword = await hashPassword(password);
			if (users === 0) {
				await prisma.user.create({
					data: {
						id: uid,
						email,
						password: hashedPassword,
						type: 'email',
						teams: {
							create: {
								id: uid,
								name: uniqueName(),
								destinationDocker: { connect: { network: 'coolify' } }
							}
						},
						permission: { create: { teamId: uid, permission: 'owner' } }
					},
					include: { teams: true }
				});
			} else {
				await prisma.user.create({
					data: {
						id: uid,
						email,
						password: hashedPassword,
						type: 'email',
						teams: {
							create: {
								id: uid,
								name: uniqueName()
							}
						},
						permission: { create: { teamId: uid, permission: 'owner' } }
					},
					include: { teams: true }
				});
			}
		}
		return {
			userId: uid,
			teamId: uid,
			permission,
			isAdmin
		};
	}
}

export async function getCurrentUser(request: FastifyRequest<GetCurrentUser>, fastify) {
	let token = null;
	const { teamId } = request.query;
	try {
		const user = await prisma.user.findUnique({
			where: { id: request.user.userId }
		});
		if (!user) {
			throw 'User not found';
		}
	} catch (error) {
		throw { status: 401, message: error };
	}
	if (teamId) {
		try {
			const user = await prisma.user.findFirst({
				where: { id: request.user.userId, teams: { some: { id: teamId } } },
				include: { teams: true, permission: true }
			});
			if (user) {
				const permission = user.permission.find((p) => p.teamId === teamId).permission;
				const payload = {
					...request.user,
					teamId,
					permission: permission || null,
					isAdmin: permission === 'owner' || permission === 'admin'
				};
				token = fastify.jwt.sign(payload);
			}
		} catch (error) {
			// No new token -> not switching teams
		}
	}
	const pendingInvitations = await prisma.teamInvitation.findMany({
		where: { uid: request.user.userId }
	});
	return {
		settings: await prisma.setting.findUnique({ where: { id: '0' } }),
		sentryDSN,
		pendingInvitations,
		token,
		...request.user
	};
}
