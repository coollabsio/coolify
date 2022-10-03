import { FastifyPluginAsync } from 'fastify';
import { acceptInvitation, changePassword, deleteTeam, getTeam, inviteToTeam, listAccounts, listTeams, newTeam, removeUser, removeUserFromTeam, revokeInvitation, saveTeam, setPermission } from './handlers';

import type { OnlyId } from '../../../../types';
import type { BodyId, DeleteUserFromTeam, InviteToTeam, SaveTeam, SetPermission } from './types';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        return await request.jwtVerify()
    })

    fastify.get('/', async (request) => await listAccounts(request));
    fastify.post('/new', async (request, reply) => await newTeam(request, reply));
    fastify.get('/teams', async (request) => await listTeams(request));

    fastify.get<OnlyId>('/team/:id', async (request, reply) => await getTeam(request, reply));
    fastify.post<SaveTeam>('/team/:id', async (request, reply) => await saveTeam(request, reply));
    fastify.delete<OnlyId>('/team/:id', async (request, reply) => await deleteTeam(request, reply));
    fastify.post<DeleteUserFromTeam>('/team/:id/user/remove', async (request, reply) => await removeUserFromTeam(request, reply));

    fastify.post<InviteToTeam>('/team/:id/invitation/invite', async (request, reply) => await inviteToTeam(request, reply))
    fastify.post<BodyId>('/team/:id/invitation/accept', async (request) => await acceptInvitation(request));
    fastify.post<BodyId>('/team/:id/invitation/revoke', async (request) => await revokeInvitation(request));

    fastify.post<SetPermission>('/team/:id/permission', async (request, reply) => await setPermission(request, reply));

    fastify.delete<BodyId>('/user/remove', async (request, reply) => await removeUser(request, reply));
    fastify.post<BodyId>('/user/password', async (request, reply) => await changePassword(request, reply));

};

export default root;
