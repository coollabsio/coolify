import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import { publicPages } from '$lib/consts';
import mongoose from 'mongoose';
import { verifyUserId } from '$lib/api/common';
import { initializeSession } from 'svelte-kit-cookie-session';
import { cleanupStuckedDeploymentsInDB } from '$lib/api/applications/cleanup';
import { docker } from '$lib/api/docker';
import Configuration from '$models/Configuration';

process.on('SIGINT', function () {
	mongoose.connection.close(function () {
		console.log('Mongoose default connection disconnected through app termination');
		process.exit(0);
	});
});

async function connectMongoDB() {
	// TODO: Save configurations on start?
	const { MONGODB_USER, MONGODB_PASSWORD, MONGODB_HOST, MONGODB_PORT, MONGODB_DB } = process.env;
	try {
		if (process.env.NODE_ENV === 'production') {
			await mongoose.connect(
				`mongodb://${MONGODB_USER}:${MONGODB_PASSWORD}@${MONGODB_HOST}:${MONGODB_PORT}/${MONGODB_DB}?authSource=${MONGODB_DB}&readPreference=primary&ssl=false`,
				{ useNewUrlParser: true, useUnifiedTopology: true, useFindAndModify: false }
			);
		} else {
			await mongoose.connect(
				'mongodb://supercooldbuser:developmentPassword4db@localhost:27017/coolify?&readPreference=primary&ssl=false',
				{ useNewUrlParser: true, useUnifiedTopology: true, useFindAndModify: false }
			);
		}
		console.log('Connected to mongodb.');

	} catch (error) {
		console.log(error);
	}

}

(async () => {
	console.log(mongoose.connection.readyState)
	if (mongoose.connection.readyState !== 1) await connectMongoDB();
	try {
		await mongoose.connection.db.dropCollection('logs-servers');
	} catch (error) {
		//
	}
	try {
		await cleanupStuckedDeploymentsInDB();
	} catch (error) {
		console.log(error)
	}
	try {
		const dockerServices = await docker.engine.listServices();
		let applications: any = dockerServices.filter(
			(r) =>
				r.Spec.Labels.managedBy === 'coolify' &&
				r.Spec.Labels.type === 'application' &&
				r.Spec.Labels.configuration
		);
		applications = applications.map((r) => {
			if (JSON.parse(r.Spec.Labels.configuration)) {
				return {
					configuration: JSON.parse(r.Spec.Labels.configuration),
					UpdatedAt: r.UpdatedAt
				};
			}
			return {};
		});
		applications = [
			...new Map(
				applications.map((item) => [
					item.configuration.publish.domain + item.configuration.publish.path,
					item
				])
			).values()
		];
		for (const application of applications) {
			await Configuration.findOneAndUpdate({
				'repository.name': application.configuration.repository.name,
				'repository.organization': application.configuration.repository.organization,
				'repository.branch': application.configuration.repository.branch,
			}, {
				...application.configuration
			}, { upsert: true })
		}
	} catch (error) {
		console.log(error)
	}
})()


export async function handle({ request, render }) {
	const { SECRETS_ENCRYPTION_KEY } = process.env;
	const session = initializeSession(request.headers, {
		secret: SECRETS_ENCRYPTION_KEY,
		cookie: { path: '/' }
	});
	request.locals.session = session;
	if (session?.data?.coolToken) {
		try {
			await verifyUserId(session.data.coolToken);
			request.locals.session = session;
		} catch (error) {
			request.locals.session.destroy = true;
		}
	}
	const response = await render(request);
	if (!session['set-cookie']) {
		if (!session?.data?.coolToken && !publicPages.includes(request.path)) {
			return {
				status: 301,
				headers: {
					location: '/'
				}
			};
		}
		return response;
	}
	return {
		...response,
		headers: {
			...response.headers,
			...session
		}
	};
}
export function getSession(request) {
	const { data } = request.locals.session;
	return {
		isLoggedIn: data && Object.keys(data).length !== 0 ? true : false,
		expires: data.expires,
		coolToken: data.coolToken,
		ghToken: data.ghToken
	};
}
