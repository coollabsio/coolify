require('dotenv').config();
const { program } = require('commander');
const shell = require('shelljs');
const user = shell.exec('whoami', { silent: true }).stdout.replace('\n', '');

program.version('0.0.1');
program
	.option('-d, --debug', 'Debug outputs.')
	.option('-c, --check', 'Only checks configuration.')
	.option('-t, --type <type>', 'Deploy type.');

program.parse(process.argv);

const options = program.opts();

if (user !== 'root') {
	console.error(`Please run as root! Current user: ${user}`);
	process.exit(1);
}
shell.exec(`docker network create ${process.env.DOCKER_NETWORK} --driver overlay`, {
	silent: !options.debug
});
shell.exec('docker build -t coolify -f install/Dockerfile .');
if (options.type === 'all') {
	shell.exec('docker stack rm coollabs-coolify', { silent: !options.debug });
} else if (options.type === 'coolify') {
	shell.exec('docker service rm coollabs-coolify_coolify');
} else if (options.type === 'proxy') {
	shell.exec('docker service rm coollabs-coolify_proxy');
}
if (options.type !== 'upgrade') {
	shell.exec(
		'set -a && source .env && set +a && envsubst < install/coolify-template.yml | docker stack deploy -c - coollabs-coolify',
		{ silent: !options.debug, shell: '/bin/bash' }
	);
}
