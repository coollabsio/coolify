import { FastifyPluginAsync } from 'fastify';
import { acceptInvitation, changePassword, deleteTeam, getTeam, inviteToTeam, listTeams, newTeam, removeUser, revokeInvitation, saveTeam, setPermission } from './handlers';


const root: FastifyPluginAsync = async (fastify, opts): Promise<void> => {
    fastify.addHook('onRequest', async (request, reply) => {
        return await request.jwtVerify()
    })
    fastify.get('/', async (request) => await listTeams(request));
    fastify.post('/new', async (request, reply) => await newTeam(request, reply));

    fastify.get('/team/:id', async (request, reply) => await getTeam(request, reply));
    fastify.post('/team/:id', async (request, reply) => await saveTeam(request, reply));
    fastify.delete('/team/:id', async (request, reply) => await deleteTeam(request, reply));

    fastify.post('/team/:id/invitation/invite', async (request, reply) => await inviteToTeam(request, reply))
    fastify.post('/team/:id/invitation/accept', async (request) => await acceptInvitation(request));
    fastify.post('/team/:id/invitation/revoke', async (request) => await revokeInvitation(request));


    fastify.post('/team/:id/permission', async (request, reply) => await setPermission(request, reply));

    fastify.delete('/user/remove', async (request, reply) => await removeUser(request, reply));
    fastify.post('/user/password', async (request, reply) => await changePassword(request, reply));
    // fastify.delete('/user', async (request, reply) => await deleteUser(request, reply));

};

export default root;
