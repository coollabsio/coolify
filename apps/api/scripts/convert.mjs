import fs from 'fs/promises';
import yaml from 'js-yaml';
const templateYml = await fs.readFile('./caprover.yml', 'utf8')
const template = yaml.load(templateYml)

const newTemplate = {
    "templateVersion": "1.0.0",
    "serviceDefaultVersion": "latest",
    "name": "",
    "displayName": "",
    "description": "",
    "services": {

    },
    "variables": []
}
const version = template.caproverOneClickApp.variables.find(v => v.id === '$$cap_APP_VERSION').defaultValue || 'latest'

newTemplate.displayName = template.caproverOneClickApp.displayName
newTemplate.name = template.caproverOneClickApp.displayName.toLowerCase()
newTemplate.documentation = template.caproverOneClickApp.documentation
newTemplate.description = template.caproverOneClickApp.description
newTemplate.serviceDefaultVersion = version

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
            if (serviceTemplate.environment[env].startsWith('srv-captain--$$cap_appname')) {
                continue;
            }
            const value = '$$config_' + serviceTemplate.environment[env].replaceAll('srv-captain--$$cap_appname', '$$$id').replace('$$cap', '').replaceAll('captain-overlay-network', `$$$config_${env}`).toLowerCase()
            newService.environment.push(`${env}=${value}`)
            const foundVariable = varSet.has(env)
            if (!foundVariable) {
                const foundCaproverVariable = caproverVariables.find((item) => item.id === serviceTemplate.environment[env])
                const defaultValue = foundCaproverVariable?.defaultValue ? foundCaproverVariable?.defaultValue.toString()?.replace('$$cap_gen_random_hex', '$$$generate_hex') : ''
                if (defaultValue && defaultValue !== foundCaproverVariable?.defaultValue) {
                    console.log('changed')
                }
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
            newService.volumes.push(`${source.replaceAll('$$cap_appname-', '$$$id-')}:${target}`)
        }
    }
    newTemplate.services[newServiceName] = newService
}
await fs.writeFile('./caprover_new.yml', yaml.dump([{ ...newTemplate }]))