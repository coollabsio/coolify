import { generateSSLCerts } from '$lib/letsencrypt';

export default async function () {
	try {
		return await generateSSLCerts();
	} catch (error) {
		console.log(error);
		throw error;
	}
}
