import fs from 'fs/promises';
import yaml from 'js-yaml';
import got from 'got';

const repositories = [];
const templates = await fs.readFile('./apps/api/devTemplates.yaml', 'utf8');
const devTemplates = yaml.load(templates);
for (const template of devTemplates) {
    let image = template.services['$$id'].image.replaceAll(':$$core_version', '');
    if (!image.includes('/')) {
        image = `library/${image}`;
    }
    repositories.push({ image, name: template.type });
}
const services = []
const numberOfTags = 30;
// const semverRegex = new RegExp(/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/g)
for (const repository of repositories) {
    console.log('Querying', repository.name, 'at', repository.image);
    let semverRegex = new RegExp(/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/g)
    if (repository.name.startsWith('wordpress')) {
        semverRegex = new RegExp(/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-php(0|[1-9]\d*)$/g)
    }
    if (repository.name.startsWith('minio')) {
        semverRegex = new RegExp(/^RELEASE.*$/g)
    }
    if (repository.name.startsWith('fider')) {
        semverRegex = new RegExp(/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-([0-9]+)$/g)
    }
    if (repository.name.startsWith('searxng')) {
        semverRegex = new RegExp(/^\d{4}[\.\-](0?[1-9]|[12][0-9]|3[01])[\.\-](0?[1-9]|1[012]).*$/)
    }
    if (repository.name.startsWith('umami')) {
        semverRegex = new RegExp(/^postgresql-v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-([0-9]+)$/g)
    }
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
        services.push({
            name: repository.name,
            image: repository.image,
            tags
        })
    }
}
await fs.writeFile('./apps/api/devTags.json', JSON.stringify(services));