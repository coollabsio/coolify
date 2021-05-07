import { execShellAsync } from '$lib/common';
import type { Request } from '@sveltejs/kit';

export async function patch(request: Request) {
	const { POSTGRESQL_USERNAME, POSTGRESQL_PASSWORD, POSTGRESQL_DATABASE } = JSON.parse(
		JSON.parse(
			await execShellAsync(
				"docker service inspect plausible_plausible --format='{{json .Spec.Labels.configuration}}'"
			)
		)
	).generateEnvsPostgres;
	const containers = (await execShellAsync("docker ps -a --format='{{json .Names}}'"))
		.replace(/"/g, '')
		.trim()
		.split('\n');
	const postgresDB = containers.find((container) => container.startsWith('plausible_plausible_db'));
	await execShellAsync(
		`docker exec ${postgresDB} psql -H postgresql://${POSTGRESQL_USERNAME}:${POSTGRESQL_PASSWORD}@localhost:5432/${POSTGRESQL_DATABASE} -c "UPDATE users SET email_verified = true;"`
	);
	return {
		status: 200,
		body: { message: 'OK' }
	};
}
