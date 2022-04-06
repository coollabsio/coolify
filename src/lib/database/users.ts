import cuid from 'cuid';
import bcrypt from 'bcrypt';
import { prisma } from './common';
import { asyncExecShell, uniqueName } from '$lib/common';
import * as db from '$lib/database';
import { startCoolifyProxy } from '$lib/haproxy';
import type { User } from '@prisma/client';

export async function hashPassword(password: string): Promise<string> {
	const saltRounds = 15;
	return bcrypt.hash(password, saltRounds);
}

export async function login({
	email,
	password,
	isLogin
}: {
	email: string;
	password: string;
	isLogin: boolean;
}): Promise<{
	status: number;
	headers: { 'Set-Cookie': string };
	body: { userId: string; teamId: string; permission: string; isAdmin: boolean };
}> {
	const users = await prisma.user.count();
	const userFound = await prisma.user.findUnique({
		where: { email },
		include: { teams: true, permission: true },
		rejectOnNotFound: false
	});
	if (!userFound && isLogin) {
		throw {
			error: 'Wrong password or email address.'
		};
	}
	// Registration disabled if database is not seeded properly
	const { isRegistrationEnabled, id } = await db.listSettings();

	let uid = cuid();
	let permission = 'read';
	let isAdmin = false;
	// Disable registration if we are registering the first user.
	if (users === 0) {
		await prisma.setting.update({ where: { id }, data: { isRegistrationEnabled: false } });
		// Create default network & start Coolify Proxy
		asyncExecShell(`docker network create --attachable coolify`)
			.then(() => {
				console.log('Network created');
			})
			.catch(() => {
				console.log('Network already exists.');
			});

		startCoolifyProxy('/var/run/docker.sock')
			.then(() => {
				console.log('Coolify Proxy started.');
			})
			.catch((err) => {
				console.log(err);
			});
		uid = '0';
	}

	if (userFound) {
		if (userFound.type === 'email') {
			const passwordMatch = bcrypt.compare(password, userFound.password);
			if (!passwordMatch) {
				throw {
					error: 'Wrong password or email address.'
				};
			}
			uid = userFound.id;
			// permission = userFound.permission;
			isAdmin = true;
		}
	} else {
		// If registration disabled, return 403
		if (!isRegistrationEnabled) {
			throw {
				error: 'Registration disabled by administrator.'
			};
		}

		const hashedPassword = await hashPassword(password);
		if (users === 0) {
			permission = 'owner';
			isAdmin = true;
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
		status: 200,
		headers: {
			'Set-Cookie': `teamId=${uid}; HttpOnly; Path=/; Max-Age=15778800;`
		},
		body: {
			userId: uid,
			teamId: uid,
			permission,
			isAdmin
		}
	};
}

export async function getUser({ userId }: { userId: string }): Promise<User> {
	return await prisma.user.findUnique({ where: { id: userId } });
}
