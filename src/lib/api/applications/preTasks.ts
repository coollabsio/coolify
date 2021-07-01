import Configuration from "$models/Configuration";
import Deployment from "$models/Deployment";

export default async function (configuration) {
    // Check if deployment is already queued
    const alreadyQueued = await Deployment.find({
        path: configuration.publish.path,
        domain: configuration.publish.domain,
        progress: { $in: ['queued', 'inprogress'] }
    });
    if (alreadyQueued.length > 0) {
        return {
            status: 200,
            body: {
                success: false,
                message: 'Deployment already queued.'
            }
        };
    }
    const { id, organization, name, branch } = configuration.repository;
    const { domain, path } = configuration.publish;
    const { deployId, nickname } = configuration.general;
    // Save new deployment 
    await new Deployment({
        repoId: id,
        branch,
        deployId,
        domain,
        organization,
        name,
        nickname
    }).save();

    await Configuration.findOneAndUpdate(
        {
            'publish.domain': domain,
            'publish.path': path,
            'general.pullRequest': { $in: [null, 0] }
        },
        { ...configuration },
        { upsert: true, new: true }
    );
    return
}