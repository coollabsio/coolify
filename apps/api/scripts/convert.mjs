// Convert caprover format to coolify format

import fs from 'fs/promises';
import yaml from 'js-yaml';
const templateYml = await fs.readFile('./caprover.yml', 'utf8')
const template = yaml.load(templateYml)

const newTemplate = {
    "templateVersion": "1.0.0",
    "defaultVersion": "latest",
    "name": "",
    "description": "",
    "services": {

    },
    "variables": []
}
const version = template.caproverOneClickApp.variables.find(v => v.id === '$$cap_APP_VERSION').defaultValue || 'latest'

newTemplate.name = template.caproverOneClickApp.displayName
newTemplate.documentation = template.caproverOneClickApp.documentation
newTemplate.description = template.caproverOneClickApp.description
newTemplate.defaultVersion = version

const varSet = new Set()

const caproverVariables = template.caproverOneClickApp.variables
for (const service of Object.keys(template.services)) {
    const serviceTemplate = template.services[service]
    const newServiceName = service.replaceAll('cap_appname', 'id')
    const newService = {
        image: '',
        command: '',
        environment: [],
        volumes: []
    }
    const FROM = serviceTemplate.caproverExtra?.dockerfileLines?.find((line) => line.startsWith('FROM'))
    if (serviceTemplate.image) {
        newService.image = serviceTemplate.image.replaceAll('cap_APP_VERSION', 'core_version')
    } else if (FROM) {
        newService.image = FROM.split(' ')[1].replaceAll('cap_APP_VERSION', 'core_version')
    }

    const CMD = serviceTemplate.caproverExtra?.dockerfileLines?.find((line) => line.startsWith('CMD'))
    if (serviceTemplate.command) {
        newService.command = serviceTemplate.command
    } else if (CMD) {
        newService.command = CMD.replace('CMD ', '').replaceAll('"', '').replaceAll('[', '').replaceAll(']', '').replaceAll(',', ' ').replace(/\s+/g, ' ')
    } else {
        delete newService.command
    }
    const ENTRYPOINT = serviceTemplate.caproverExtra?.dockerfileLines?.find((line) => line.startsWith('ENTRYPOINT'))

    if (serviceTemplate.entrypoint) {
        newService.command = serviceTemplate.entrypoint

    } else if (ENTRYPOINT) {
        newService.entrypoint = ENTRYPOINT.replace('ENTRYPOINT ', '').replaceAll('"', '').replaceAll('[', '').replaceAll(']', '').replaceAll(',', ' ').replace(/\s+/g, ' ')
    } else {
        delete newService.entrypoint
    }

    if (serviceTemplate.environment && Object.keys(serviceTemplate.environment).length > 0) {
        for (const env of Object.keys(serviceTemplate.environment)) {
            const foundCaproverVariable = caproverVariables.find((item) => item.id === serviceTemplate.environment[env])

            let value = null;
            let defaultValue = foundCaproverVariable?.defaultValue ? foundCaproverVariable?.defaultValue.toString()?.replace('$$cap_gen_random_hex', '$$$generate_hex') : ''

            if (serviceTemplate.environment[env].startsWith('srv-captain--$$cap_appname')) {
                value = `$$config_${env}`.toLowerCase()
                defaultValue = serviceTemplate.environment[env].replaceAll('srv-captain--$$cap_appname', '$$$id').replace('$$cap', '').replaceAll('captain-overlay-network', `$$$config_${env}`).toLowerCase()
            } else {
                value = '$$config_' + serviceTemplate.environment[env].replaceAll('srv-captain--$$cap_appname', '$$$id').replace('$$cap', '').replaceAll('captain-overlay-network', `$$$config_${env}`).toLowerCase()
            }
            newService.environment.push(`${env}=${value}`)
            const foundVariable = varSet.has(env)
            if (!foundVariable) {
                newTemplate.variables.push({
                    "id": value,
                    "name": env,
                    "label": foundCaproverVariable?.label || '',
                    "defaultValue": defaultValue,
                    "description": foundCaproverVariable?.description || '',
                })
            }
            varSet.add(env)
        }
    }

    if (serviceTemplate.volumes && serviceTemplate.volumes.length > 0) {
        for (const volume of serviceTemplate.volumes) {
            const [source, target] = volume.split(':')
            if (source === '/var/run/docker.sock' || source === '/tmp') {
                continue;
            }
            newService.volumes.push(`${source.replaceAll('$$cap_appname-', '$$$id-')}:${target}`)
        }
    }

    newTemplate.services[newServiceName] = newService
    const services = { ...newTemplate.services }
    newTemplate.services = {}
    for (const key of Object.keys(services).sort()) {
        newTemplate.services[key] = services[key]
    }
}
await fs.writeFile('./caprover_new.yml', yaml.dump([{ ...newTemplate }]))
await fs.writeFile('./caprover_new.json', JSON.stringify([{ ...newTemplate }], null, 2))