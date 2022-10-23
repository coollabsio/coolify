import { FastifyPluginAsync } from 'fastify';
import { errorHandler, listSettings, version } from '../../../../lib/common';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.addHook('onRequest', async (request) => {
        try {
            return await request.jwtVerify();
        } catch(error){
            return {};
        }
    })

    fastify.get('/', async (request) => {
        const teamId = request.user?.teamId;
        const settings = await listSettings()
        try {
            return {
                ipv4: teamId ? settings.ipv4 : 'nope',
                ipv6: teamId ? settings.ipv6 : 'nope',
                version,
                whiteLabeled: process.env.COOLIFY_WHITE_LABELED === 'true',
                whiteLabeledIcon: process.env.COOLIFY_WHITE_LABELED_ICON,
                isRegistrationEnabled: settings.isRegistrationEnabled,
            }
        } catch ({ status, message }) {
            return errorHandler({ status, message })
        }
    });

};

export default root;
