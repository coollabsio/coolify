import { post } from "$lib/api";

export async function saveForm(id: string, application: any, baseDatabaseBranch?: any, dockerComposeConfiguration?: any) {
    return await post(`/applications/${id}`, {
        ...application,
        baseDatabaseBranch,
        dockerComposeConfiguration: JSON.stringify(dockerComposeConfiguration)
    });
}
