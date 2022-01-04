export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay));
export const dateOptions: DateTimeFormatOptions = {
	year: 'numeric',
	month: 'short',
	day: '2-digit',
	hour: 'numeric',
	minute: 'numeric',
	second: 'numeric',
	hour12: false
};

export async function getGithubToken({apiUrl, application, githubToken}): Promise<void> {
	const response = await fetch(
		`${apiUrl}/app/installations/${application.gitSource.githubApp.installationId}/access_tokens`,
		{
			method: 'POST',
			headers: {
				Authorization: `Bearer ${githubToken}`
			}
		}
	);
	if (!response.ok) {
		throw new Error('Git Source not configured.');
	}
	const data = await response.json();
	return data.token;
}