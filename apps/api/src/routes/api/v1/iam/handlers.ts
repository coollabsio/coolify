import cuid from 'cuid';
import type { FastifyRequest } from 'fastify';
import { FastifyReply } from 'fastify';
import { decrypt, errorHandler, prisma, uniqueName } from '../../../../lib/common';
import { day } from '../../../../lib/dayjs';

import type { OnlyId } from '../../../../types';
import type { BodyId, DeleteUserFromTeam, InviteToTeam, SaveTeam, SetPermission } from './types';


export async function listAccounts(request: FastifyRequest) {
    try {
        const userId = request.user.userId;
        const teamId = request.user.teamId;
        const account = await prisma.user.findUnique({
            where: { id: userId },
            select: { id: true, email: true, teams: true }
        });
        let accounts = await prisma.user.findMany({ where: { teams: { some: { id: teamId } } }, select: { id: true, email: true, teams: true } });
        if (teamId === '0') {
            accounts = await prisma.user.findMany({ select: { id: true, email: true, teams: true } });
        }
        return {
            account,
            accounts
        };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function listTeams(request: FastifyRequest) {
    try {
        const userId = request.user.userId;
        const teamId = request.user.teamId;
        let allTeams = [];
        if (teamId === '0') {
            allTeams = await prisma.team.findMany({
                where: { users: { none: { id: userId } } },
                include: { permissions: true }
            });
        }
        const ownTeams = await prisma.team.findMany({
            where: { users: { some: { id: userId } } },
            include: { permissions: true }
        });
        return {
            ownTeams,
            allTeams,
        };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function removeUserFromTeam(request: FastifyRequest<DeleteUserFromTeam>, reply: FastifyReply) {
    try {
        const { uid } = request.body;
        const { id } = request.params;
        const userId = request.user.userId;
        const foundUser = await prisma.team.findMany({ where: { id, users: { some: { id: userId } } } });
        if (foundUser.length === 0) {
            return errorHandler({ status: 404, message: 'Team not found' });
        }
        await prisma.team.update({ where: { id }, data: { users: { disconnect: { id: uid } } } });
        await prisma.permission.deleteMany({ where: { teamId: id, userId: uid } })
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteTeam(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const userId = request.user.userId;
        const { id } = request.params;

        const aloneInTeams = await prisma.team.findMany({ where: { users: { every: { id: userId } }, id } });
        if (aloneInTeams.length > 0) {
            for (const team of aloneInTeams) {
                const applications = await prisma.application.findMany({
                    where: { teams: { every: { id: team.id } } }
                });
                if (applications.length > 0) {
                    for (const application of applications) {
                        await prisma.application.update({
                            where: { id: application.id },
                            data: { teams: { connect: { id: '0' } } }
                        });
                    }
                }
                const services = await prisma.service.findMany({
                    where: { teams: { every: { id: team.id } } }
                });
                if (services.length > 0) {
                    for (const service of services) {
                        await prisma.service.update({
                            where: { id: service.id },
                            data: { teams: { connect: { id: '0' } } }
                        });
                    }
                }
                const databases = await prisma.database.findMany({
                    where: { teams: { every: { id: team.id } } }
                });
                if (databases.length > 0) {
                    for (const database of databases) {
                        await prisma.database.update({
                            where: { id: database.id },
                            data: { teams: { connect: { id: '0' } } }
                        });
                    }
                }
                const sources = await prisma.gitSource.findMany({
                    where: { teams: { every: { id: team.id } } }
                });
                if (sources.length > 0) {
                    for (const source of sources) {
                        await prisma.gitSource.update({
                            where: { id: source.id },
                            data: { teams: { connect: { id: '0' } } }
                        });
                    }
                }
                const destinations = await prisma.destinationDocker.findMany({
                    where: { teams: { every: { id: team.id } } }
                });
                if (destinations.length > 0) {
                    for (const destination of destinations) {
                        await prisma.destinationDocker.update({
                            where: { id: destination.id },
                            data: { teams: { connect: { id: '0' } } }
                        });
                    }
                }
                await prisma.teamInvitation.deleteMany({ where: { teamId: team.id } });
                await prisma.permission.deleteMany({ where: { teamId: team.id } });
                // await prisma.user.delete({ where: { id } });
                await prisma.team.delete({ where: { id: team.id } });
            }
        }

        const notAloneInTeams = await prisma.team.findMany({ where: { users: { some: { id: userId } } } });
        if (notAloneInTeams.length > 0) {
            for (const team of notAloneInTeams) {
                await prisma.team.update({
                    where: { id: team.id },
                    data: { users: { disconnect: { id } } }
                });
            }
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function newTeam(request: FastifyRequest, reply: FastifyReply) {
    try {
        const userId = request.user?.userId;
        const name = uniqueName();
        const { id } = await prisma.team.create({
            data: {
                name,
                permissions: { create: { user: { connect: { id: userId } }, permission: 'owner' } },
                users: { connect: { id: userId } }
            }
        });
        return reply.code(201).send({ id })
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getTeam(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const userId = request.user.userId;
        const teamId = request.user.teamId;
        const { id } = request.params;

        const user = await prisma.user.findFirst({
            where: { id: userId, teams: teamId === '0' ? undefined : { some: { id } } },
            include: { permission: true }
        });
        if (!user) return reply.code(401).send()

        const permissions = await prisma.permission.findMany({
            where: { teamId: id },
            include: { user: { select: { id: true, email: true } } }
        });
        const team = await prisma.team.findUnique({ where: { id }, include: { permissions: true } });
        const invitations = await prisma.teamInvitation.findMany({ where: { teamId: team.id } });
        const { teams } = await prisma.user.findUnique({ where: { id: userId }, include: { teams: true } })
        return {
            currentTeam: teamId,
            team,
            teams,
            permissions,
            invitations
        };
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveTeam(request: FastifyRequest<SaveTeam>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const { name } = request.body;

        await prisma.team.update({ where: { id }, data: { name: { set: name } } });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

// export async function deleteUser(request: FastifyRequest, reply: FastifyReply) {
//     try {
//         const userId = request.user.userId;
//         const { id } = request.params;

//         const aloneInTeams = await prisma.team.findMany({ where: { users: { every: { id: userId } }, id } });
//         if (aloneInTeams.length > 0) {
//             for (const team of aloneInTeams) {
//                 const applications = await prisma.application.findMany({
//                     where: { teams: { every: { id: team.id } } }
//                 });
//                 if (applications.length > 0) {
//                     for (const application of applications) {
//                         await prisma.application.update({
//                             where: { id: application.id },
//                             data: { teams: { connect: { id: '0' } } }
//                         });
//                     }
//                 }
//                 const services = await prisma.service.findMany({
//                     where: { teams: { every: { id: team.id } } }
//                 });
//                 if (services.length > 0) {
//                     for (const service of services) {
//                         await prisma.service.update({
//                             where: { id: service.id },
//                             data: { teams: { connect: { id: '0' } } }
//                         });
//                     }
//                 }
//                 const databases = await prisma.database.findMany({
//                     where: { teams: { every: { id: team.id } } }
//                 });
//                 if (databases.length > 0) {
//                     for (const database of databases) {
//                         await prisma.database.update({
//                             where: { id: database.id },
//                             data: { teams: { connect: { id: '0' } } }
//                         });
//                     }
//                 }
//                 const sources = await prisma.gitSource.findMany({
//                     where: { teams: { every: { id: team.id } } }
//                 });
//                 if (sources.length > 0) {
//                     for (const source of sources) {
//                         await prisma.gitSource.update({
//                             where: { id: source.id },
//                             data: { teams: { connect: { id: '0' } } }
//                         });
//                     }
//                 }
//                 const destinations = await prisma.destinationDocker.findMany({
//                     where: { teams: { every: { id: team.id } } }
//                 });
//                 if (destinations.length > 0) {
//                     for (const destination of destinations) {
//                         await prisma.destinationDocker.update({
//                             where: { id: destination.id },
//                             data: { teams: { connect: { id: '0' } } }
//                         });
//                     }
//                 }
//                 await prisma.teamInvitation.deleteMany({ where: { teamId: team.id } });
//                 await prisma.permission.deleteMany({ where: { teamId: team.id } });
//                 await prisma.user.delete({ where: { id: userId } });
//                 await prisma.team.delete({ where: { id: team.id } });

//             }
//         }

//         const notAloneInTeams = await prisma.team.findMany({ where: { users: { some: { id: userId } } } });
//         if (notAloneInTeams.length > 0) {
//             for (const team of notAloneInTeams) {
//                 await prisma.team.update({
//                     where: { id: team.id },
//                     data: { users: { disconnect: { id } } }
//                 });
//             }
//         }

//         return reply.code(201).send()
//     } catch (error) {
//         console.log(error)
//         throw { status: 500, message: error }
//     }
// }

export async function inviteToTeam(request: FastifyRequest<InviteToTeam>, reply: FastifyReply) {
    try {
        const userId = request.user.userId;
        const { email, permission, teamId, teamName } = request.body;
        const userFound = await prisma.user.findUnique({ where: { email } });
        if (!userFound) {
            throw {
                message: `No user found with '${email}' email address.`
            };
        }
        const uid = userFound.id;
        if (uid === userId) {
            throw {
                message: `Invitation to yourself? Whaaaaat?`
            };
        }
        const alreadyInTeam = await prisma.team.findFirst({
            where: { id: teamId, users: { some: { id: uid } } }
        });
        if (alreadyInTeam) {
            throw {
                message: `Already in the team.`
            };
        }
        const invitationFound = await prisma.teamInvitation.findFirst({ where: { uid, teamId } });
        if (invitationFound) {
            if (day().toDate() < day(invitationFound.createdAt).add(1, 'day').toDate()) {
                throw 'Invitiation already pending on user confirmation.'
            } else {
                await prisma.teamInvitation.delete({ where: { id: invitationFound.id } });
                await prisma.teamInvitation.create({
                    data: { email, uid, teamId, teamName, permission }
                });
                return reply.code(201).send({ message: 'Invitiation sent.' })
            }
        } else {
            await prisma.teamInvitation.create({
                data: { email, uid, teamId, teamName, permission }
            });
            return reply.code(201).send({ message: 'Invitiation sent.' })

        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function acceptInvitation(request: FastifyRequest<BodyId>) {
    try {
        const userId = request.user.userId;
        const { id } = request.body;
        const invitation = await prisma.teamInvitation.findFirst({
            where: { uid: userId },
            rejectOnNotFound: true
        });
        await prisma.team.update({
            where: { id: invitation.teamId },
            data: { users: { connect: { id: userId } } }
        });
        await prisma.permission.create({
            data: {
                user: { connect: { id: userId } },
                permission: invitation.permission,
                team: { connect: { id: invitation.teamId } }
            }
        });
        await prisma.teamInvitation.delete({ where: { id } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function revokeInvitation(request: FastifyRequest<BodyId>) {
    try {
        const { id } = request.body
        await prisma.teamInvitation.delete({ where: { id } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function removeUser(request: FastifyRequest<BodyId>, reply: FastifyReply) {
    try {
        const { id } = request.body;
        const user = await prisma.user.findUnique({ where: { id }, include: { teams: true, permission: true } });
        if (user) {
            const permissions = user.permission;
            if (permissions.length > 0) {
                for (const permission of permissions) {
                    await prisma.permission.deleteMany({ where: { id: permission.id, userId: id } });
                }
            }
            const teams = user.teams;
            if (teams.length > 0) {
                for (const team of teams) {
                    const newTeam = await prisma.team.update({
                        where: { id: team.id },
                        data: { users: { disconnect: { id } } },
                        include: { applications: true, database: true, gitHubApps: true, gitLabApps: true, gitSources: true, destinationDocker: true, service: true, users: true }
                    });
                    if (newTeam.users.length === 0) {
                        if (newTeam.applications.length > 0) {
                            for (const application of newTeam.applications) {
                                await prisma.application.update({
                                    where: { id: application.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        if (newTeam.database.length > 0) {
                            for (const database of newTeam.database) {
                                await prisma.database.update({
                                    where: { id: database.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        if (newTeam.service.length > 0) {
                            for (const service of newTeam.service) {
                                await prisma.service.update({
                                    where: { id: service.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        if (newTeam.gitHubApps.length > 0) {
                            for (const gitHubApp of newTeam.gitHubApps) {
                                await prisma.githubApp.update({
                                    where: { id: gitHubApp.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        if (newTeam.gitLabApps.length > 0) {
                            for (const gitLabApp of newTeam.gitLabApps) {
                                await prisma.gitlabApp.update({
                                    where: { id: gitLabApp.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        if (newTeam.gitSources.length > 0) {
                            for (const gitSource of newTeam.gitSources) {
                                await prisma.gitSource.update({
                                    where: { id: gitSource.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        if (newTeam.destinationDocker.length > 0) {
                            for (const destinationDocker of newTeam.destinationDocker) {
                                await prisma.destinationDocker.update({
                                    where: { id: destinationDocker.id },
                                    data: { teams: { disconnect: { id: team.id }, connect: { id: '0' } } }
                                });
                            }
                        }
                        await prisma.team.delete({ where: { id: team.id } });
                    }
                }
            }
        }
        await prisma.user.delete({ where: { id } });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function setPermission(request: FastifyRequest<SetPermission>, reply: FastifyReply) {
    try {
        const { userId, newPermission, permissionId } = request.body;
        await prisma.permission.updateMany({
            where: { id: permissionId, userId },
            data: { permission: { set: newPermission } }
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function changePassword(request: FastifyRequest<BodyId>, reply: FastifyReply) {
    try {
        const { id } = request.body;
        await prisma.user.update({ where: { id }, data: { password: 'RESETME' } });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}