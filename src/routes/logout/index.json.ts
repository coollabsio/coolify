export async function del({ locals }) {
    locals.session.destroy();

    return {
        headers: {
            'set-cookie': ["selectedTeam=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT"],
        },
        body: {
            ok: true,
        },
    };
}