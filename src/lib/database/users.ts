import cuid from 'cuid';
import bcrypt from 'bcrypt';

import { prisma } from './common';
import { asyncExecShell, uniqueName } from '$lib/common';

import * as db from '$lib/database';
import { startCoolifyProxy } from '$lib/haproxy';

export async function login({ email, password }) {
	const saltRounds = 15;
	const users = await prisma.user.count();
	const userFound = await prisma.user.findUnique({
		where: { email },
		include: { teams: true },
		rejectOnNotFound: false
	});
	// Registration disabled if database is not seeded properly
	const { isRegistrationEnabled, id } = await db.listSettings();

	let uid = cuid();
	// Disable registration if we are registering the first user.
	if (users === 0) {
		await prisma.setting.update({ where: { id }, data: { isRegistrationEnabled: false } });
		// Create default network & start Coolify Proxy
		asyncExecShell(`docker network create --attachable coolify`)
			.then(() => {
				console.log('Network created');
			})
			.catch(() => {
				console.log('Network already exists');
			});

		startCoolifyProxy('/var/run/docker.sock')
			.then(() => {
				console.log('Coolify Proxy Started');
			})
			.catch((err) => {
				console.log(err);
			});
		uid = '0';
	}

	if (userFound) {
		if (userFound.type === 'email') {
			const passwordMatch = await bcrypt.compare(password, userFound.password);
			if (!passwordMatch) {
				throw {
					error: 'Wrong password or email address.'
				};
			}
			uid = userFound.id;
		}
	} else {
		// If registration disabled, return 403
		if (!isRegistrationEnabled) {
			throw {
				error: 'Registration disabled by administrator.'
			};
		}

		const hashedPassword = await bcrypt.hash(password, saltRounds);
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

	// const token = jsonwebtoken.sign({}, secretKey, {
	//     expiresIn: 15778800,
	//     algorithm: 'HS256',
	//     audience: 'coolify',
	//     issuer: 'coolify',
	//     jwtid: uid,
	//     subject: `User:${uid}`,
	//     notBefore: -1000
	// });

	return {
		status: 200,
		headers: {
			'Set-Cookie': `teamId=${uid}; HttpOnly; Path=/; Max-Age=15778800;`
		},
		body: {
			uid,
			teamId: uid
		}
	};
}

export async function getUser({ userId }) {
	return await prisma.user.findUnique({ where: { id: userId } });
}
