require('dotenv').config()
const { program } = require('commander')
const { version } = require('../package.json')
const shell = require('shelljs')
const user = shell.exec('whoami', { silent: true }).stdout.replace('\n', '')
console.log(version)
program.version('0.0.1')
program
  .option('-d, --debug', 'Debug outputs.')
  .option('-c, --check', 'Only checks configuration.')
  .option('-t, --type <type>', 'Deploy type.')

program.parse(process.argv)
const options = program.opts()
if (user !== 'root') {
  console.error(`Please run as root! Current user: ${user}`)
  process.exit(1)
}

if (options.type === 'upgrade') {
  shell.exec('docker service rm coollabs-coolify_coolify')
  shell.exec('set -a && source .env && set +a && envsubst < install/coolify-template.yml | docker stack deploy -c - coollabs-coolify', { silent: !options.debug, shell: '/bin/bash' })
}
