require('dotenv').config()
const { program } = require('commander')
const shell = require('shelljs')
const user = shell.exec('whoami', { silent: true }).stdout.replace('\n', '')

program.version('0.0.1')
program
  .option('-d, --debug', 'Debug outputs.')
  .option('-c, --check', 'Only checks configuration.')
  .option('-t, --type <type>', 'Deploy type.')

program.parse(process.argv)

if (user !== 'root') {
  console.error(`Please run as root! Current user: ${user}`)
  process.exit(1)
}
if (program.type === 'update') {
  shell.exec('docker service rm coollabs-coolify_coolify')
  shell.exec('set -a && source .env && set +a && envsubst < install/coolify-template.yml | docker stack deploy -c - coollabs-coolify', { silent: !program.debug, shell: '/bin/bash' })
}
