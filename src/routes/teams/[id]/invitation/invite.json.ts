import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

async function createInvitation({ email, uid, teamId, teamName, permission }) {
    return await db.prisma.teamInvitation.create({ data: { email, uid, teamId, teamName, permission } })
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { userId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const email = request.body.get('email')
    const permission = request.body.get('permission')
    const teamId = request.body.get('teamId')
    const teamName = request.body.get('teamName')

    try {
        const userFound = await db.prisma.user.findUnique({ where: { email }, rejectOnNotFound: false })
        if (!userFound) {
            return {
                status: 404,
                body: {
                    message: `No user found with '${email}' email address.`
                }
            };
        }
        const uid = userFound.id
        // Invitation to yourself?!
        if (uid === userId) {
            return {
                status: 400,
                body: {
                    message: `Invitation to yourself? Whaaaaat?`
                }
            };
        }
        const alreadyInTeam = await db.prisma.team.findFirst({ where: { id: teamId, users: { some: { id: uid } } }, rejectOnNotFound: false })
        if (alreadyInTeam) {
            return {
                status: 400,
                body: {
                    message: `Already in the team.`
                }
            };
        }
        const invitationFound = await db.prisma.teamInvitation.findFirst({ where: { uid, teamId }, rejectOnNotFound: false })
        if (invitationFound) {
            if (dayjs().toDate() < dayjs(invitationFound.createdAt).add(1, 'day').toDate()) {
                return {
                    status: 400,
                    body: {
                        message: "Invitiation already pending on user confirmation."
                    }
                };
            } else {
                await db.prisma.teamInvitation.delete({ where: { id: invitationFound.id } })
                await createInvitation({ email, uid, teamId, teamName, permission })
                return {
                    status: 200,
                    body: {
                        message: "Invitiation sent."
                    }
                };
            }
        } else {
            await createInvitation({ email, uid, teamId, teamName, permission })
            return {
                status: 200,
                body: {
                    message: "Invitiation sent."
                }
            };
        }
    } catch (err) {
        console.log(err)
        return err
    }

}