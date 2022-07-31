import { FastifyPluginAsync } from 'fastify';
import { errorHandler, listSettings, version } from '../../../../lib/common';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
    fastify.get('/', async () => {
        const settings = await listSettings()
        try {
            return {
                ipv4: settings.ipv4,
                ipv6: settings.ipv6,
                version,
                whiteLabeled: process.env.COOLIFY_WHITE_LABELED === 'true',
                whiteLabeledIcon: process.env.COOLIFY_WHITE_LABELED_ICON,
            }
        } catch ({ status, message }) {
            return errorHandler({ status, message })
        }
    });

};

export default root;
