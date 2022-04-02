import type { RequestHandler } from '@sveltejs/kit';
import * as db from '$lib/database';
import { ErrorHandler, hashPassword } from '$lib/database';
import { t } from '$lib/translations';

export const post: RequestHandler = async (event) => {
	const { secretKey, user } = await event.request.json();
	if (secretKey !== process.env.COOLIFY_SECRET_KEY) {
		return {
			status: 500,
			body: {
				error: t.get('reset.invalid_secret_key')
			}
		};
	}
	try {
		const hashedPassword = await hashPassword(user.newPassword);
		await db.prisma.user.update({
			where: { email: user.email },
			data: { password: hashedPassword }
		});
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
