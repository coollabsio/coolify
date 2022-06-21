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
		calcom: {
			emailFrom,
			emailSmtpHost,
			emailSmtpPort,
			emailSmtpUser,
			emailSmtpPassword,
			msGraphClientId,
			msGraphClientSecret,
			zoomClientId,
			zoomClientSecret,
			googleApiCredentials,
			licenseKey
		}
	} = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();
	if (emailFrom) emailFrom = emailFrom.toLowerCase();
	if (emailSmtpHost) emailSmtpHost = emailSmtpHost.toLowerCase();
	if (emailSmtpPassword) emailSmtpPassword = encrypt(emailSmtpPassword);
	if (emailSmtpPort) emailSmtpPort = Number(emailSmtpPort);
	if (msGraphClientSecret) msGraphClientSecret = encrypt(msGraphClientSecret);
	if (zoomClientSecret) zoomClientSecret = encrypt(zoomClientSecret);
	if (googleApiCredentials) googleApiCredentials = encrypt(googleApiCredentials);
	if (licenseKey) licenseKey = encrypt(licenseKey);

	try {
		await db.updateCalcomService({
			id,
			fqdn,
			name,
			emailFrom,
			emailSmtpHost,
			emailSmtpPort,
			emailSmtpUser,
			emailSmtpPassword,
			msGraphClientId,
			msGraphClientSecret,
			zoomClientId,
			zoomClientSecret,
			googleApiCredentials,
			licenseKey
		});
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
