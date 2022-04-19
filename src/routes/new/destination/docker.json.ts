import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import type { CreateDockerDestination } from '$lib/types/destinations';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const dockerDestinationProps = {
		...((await event.request.json()) as Omit<CreateDockerDestination, 'teamId'>),
		teamId
	};

	try {
		const id = dockerDestinationProps.remoteEngine
			? await db.newRemoteDestination(dockerDestinationProps)
			: await db.newLocalDestination(dockerDestinationProps);
		return { status: 200, body: { id } };
	} catch (error) {
		return ErrorHandler(error);
	}
};
