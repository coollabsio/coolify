export async function handle({ event, resolve }) {
	const response = await resolve(event, { ssr: false });
	return response;
}
export const handleError = ({ error, event }) => {
	return {
		message: 'Whoops!',
		code: error?.code ?? 'UNKNOWN'
	};
};
