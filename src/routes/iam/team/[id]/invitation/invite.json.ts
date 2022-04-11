import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

async function createInvitation({ email, uid, teamId, teamName, permission }) {
	return await db.prisma.teamInvitation.create({
		data: { email, uid, teamId, teamName, permission }
	});
}

export const post: RequestHandler = async (event) => {
	const { userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { email, permission, teamId, teamName } = await event.request.json();

	try {
		const userFound = await db.prisma.user.findUnique({ where: { email } });
		if (!userFound) {
			throw {
				error: `No user found with '${email}' email address.`
			};
		}
		const uid = userFound.id;
		// Invitation to yourself?!
		if (uid === userId) {
			throw {
				error: `Invitation to yourself? Whaaaaat?`
			};
		}
		const alreadyInTeam = await db.prisma.team.findFirst({
			where: { id: teamId, users: { some: { id: uid } } }
		});
		if (alreadyInTeam) {
			throw {
				error: `Already in the team.`
			};
		}
		const invitationFound = await db.prisma.teamInvitation.findFirst({ where: { uid, teamId } });
		if (invitationFound) {
			if (dayjs().toDate() < dayjs(invitationFound.createdAt).add(1, 'day').toDate()) {
				throw {
					error: 'Invitiation already pending on user confirmation.'
				};
			} else {
				await db.prisma.teamInvitation.delete({ where: { id: invitationFound.id } });
				await createInvitation({ email, uid, teamId, teamName, permission });
				return {
					status: 200,
					body: {
						message: 'Invitiation sent.'
					}
				};
			}
		} else {
			await createInvitation({ email, uid, teamId, teamName, permission });
			return {
				status: 200,
				body: {
					message: 'Invitiation sent.'
				}
			};
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
