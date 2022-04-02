export async function del({ locals }) {
	locals.session.destroy();

	return {
		headers: {
			'set-cookie': [
				'teamId=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT',
				'gitlabToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT'
			]
		},
		body: {
			ok: true
		}
	};
}
