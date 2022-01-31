import proxy from '$lib/queues/proxy';

export const get = async () => {
	await proxy();
	return {
		status: 200,
		body: {
			message: 'Nope'
		}
	};
};
