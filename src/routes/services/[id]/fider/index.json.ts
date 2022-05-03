import { getUserDetails } from '$lib/common';
import { encrypt } from '$lib/crypto';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	let {
		name,
		fqdn,
		fider: {
			emailNoreply,
			emailMailgunApiKey,
			emailMailgunDomain,
			emailMailgunRegion,
			emailSmtpHost,
			emailSmtpPort,
			emailSmtpUser,
			emailSmtpPassword,
			emailSmtpEnableStartTls
		}
	} = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();
	if (emailNoreply) emailNoreply = emailNoreply.toLowerCase();
	if (emailSmtpHost) emailSmtpHost = emailSmtpHost.toLowerCase();
	if (emailSmtpPassword) {
		emailSmtpPassword = encrypt(emailSmtpPassword);
	}
	if (emailSmtpPort) emailSmtpPort = Number(emailSmtpPort);
	if (emailSmtpEnableStartTls) emailSmtpEnableStartTls = Boolean(emailSmtpEnableStartTls);

	try {
		await db.updateFiderService({
			id,
			fqdn,
			name,
			emailNoreply,
			emailMailgunApiKey,
			emailMailgunDomain,
			emailMailgunRegion,
			emailSmtpHost,
			emailSmtpPort,
			emailSmtpUser,
			emailSmtpPassword,
			emailSmtpEnableStartTls
		});
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
