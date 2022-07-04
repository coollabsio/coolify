export async function handle({ event, resolve }) {
    const response = await resolve(event, { ssr: false });
    return response;
}