import { getUserDetails } from '$lib/common';
import { ErrorHandler } from '$lib/database';
import { renewSSLCerts } from '$lib/letsencrypt';
import { t } from '$lib/translations';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (teamId !== '0')
		return {
			status: 401,
			body: {
				message: t.get('setting.permission_denied')
			}
		};
	if (status === 401) return { status, body };

	try {
		renewSSLCerts();
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
