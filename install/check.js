require('dotenv').config();
const fastify = require('fastify')();
const { schema } = require('../api/schema');

checkConfig()
	.then(() => {
		console.log('Config: OK');
	})
	.catch((err) => {
		console.log('Config: NOT OK');
		console.error(err);
		process.exit(1);
	});

function checkConfig() {
	return new Promise((resolve, reject) => {
		fastify
			.register(require('fastify-env'), {
				schema,
				dotenv: true
			})
			.ready((err) => {
				if (err) reject(err);
				resolve();
			});
	});
}
