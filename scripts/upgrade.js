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
if (program.type === 'upgrade-p1') {
  shell.exec(`docker network create ${process.env.DOCKER_NETWORK} --driver overlay`, { silent: !program.debug })
  shell.exec('docker build -t coolify -f scripts/Dockerfile .')
}

if (program.type === 'upgrade-p2') {
  shell.exec('docker service rm coollabs-coolify_coolify')
  shell.exec('set -a && source .env && set +a && envsubst < scripts/coolify-template.yml | docker stack deploy -c - coollabs-coolify', { silent: !program.debug, shell: '/bin/bash' })
}
