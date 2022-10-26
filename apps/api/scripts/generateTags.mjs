import fs from 'fs/promises';
import yaml from 'js-yaml';
import got from 'got';
import semverSort from 'semver-sort';

const repositories = [];
const templates = await fs.readFile('../devTemplates.yaml', 'utf8');
const devTemplates = yaml.load(templates);
for (const template of devTemplates) {
    let image = template.services['$$id'].image.replaceAll(':$$core_version', '');
    const name = template.name
    if (!image.includes('/')) {
        image = `library/${image}`;
    }
    repositories.push({ image, name: name.toLowerCase().replaceAll(' ', '') });
}
const services = []

const numberOfTags = 30;
// const semverRegex = new RegExp(/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/g)
const semverRegex = new RegExp(/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/g)
for (const repository of repositories) {
    console.log('Querying', repository.name, 'at', repository.image);
    if (repository.image.includes('ghcr.io')) {
        const { execaCommand } = await import('execa');
        const { stdout } = await execaCommand(`docker run --rm quay.io/skopeo/stable list-tags docker://${repository.image}`);
        if (stdout) {
            const json = JSON.parse(stdout);
            const semverTags = json.Tags.filter((tag) => semverRegex.test(tag))
            let tags = semverTags.length > 10 ? semverTags.sort().reverse().slice(0, numberOfTags) : json.Tags.sort().reverse().slice(0, numberOfTags)
            if (!tags.includes('latest')) {
                tags.push('latest')
            }
            try {
                tags = semverSort.desc(tags)
            } catch (error) { }
            services.push({ name: repository.name, image: repository.image, tags })
        }
    } else {
        const { token } = await got.get(`https://auth.docker.io/token?service=registry.docker.io&scope=repository:${repository.image}:pull`).json()
        let data = await got.get(`https://registry-1.docker.io/v2/${repository.image}/tags/list`, {
            headers: {
                Authorization: `Bearer ${token}`
            }
        }).json()
        const semverTags = data.tags.filter((tag) => semverRegex.test(tag))
        let tags = semverTags.length > 10 ? semverTags.sort().reverse().slice(0, numberOfTags) : data.tags.sort().reverse().slice(0, numberOfTags)
        if (!tags.includes('latest')) {
            tags.push('latest')
        }
        try {
            tags = semverSort.desc(tags)
        } catch (error) { }

        console.log({
            name: repository.name,
            image: repository.image,
            tags
        })
        services.push({
            name: repository.name,
            image: repository.image,
            tags
        })
    }
}
await fs.writeFile('./tags.json', JSON.stringify(services));