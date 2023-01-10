import { FastifyPluginAsync } from 'fastify';
import { errorHandler, isARM, listSettings, version } from '../../../../lib/common';

const root: FastifyPluginAsync = async (fastify): Promise<void> => {
	fastify.addHook('onRequest', async (request) => {
		try {
			await request.jwtVerify();
		} catch (error) {
			return;
		}
	});
	fastify.get('/', async (request) => {
		const teamId = request.user?.teamId;
		const settings = await listSettings();
		try {
			return {
				ipv4: teamId ? settings.ipv4 : null,
				ipv6: teamId ? settings.ipv6 : null,
				version,
				whiteLabeled: process.env.COOLIFY_WHITE_LABELED === 'true',
				whiteLabeledIcon: process.env.COOLIFY_WHITE_LABELED_ICON,
				isRegistrationEnabled: settings.isRegistrationEnabled,
				isARM: isARM(process.arch)
			};
		} catch ({ status, message }) {
			return errorHandler({ status, message });
		}
	});
};

export default root;
