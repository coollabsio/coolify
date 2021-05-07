import { publicPages, deleteCookies, verifyUserId } from '$lib/api/common';
import * as cookie from 'cookie';
import mongoose from 'mongoose';

process.on('SIGINT', function () {
	mongoose.connection.close(function () {
		console.log('Mongoose default connection disconnected through app termination');
		process.exit(0);
	});
});

let db
async function connectMongoDB() {
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
		db = true;
		console.log('Connected to mongodb.');
	} catch (error) {
		console.log(error);
	}
}
if (!db) connectMongoDB();

export async function handle({ request, render }) {
	const { coolToken, ghToken } = cookie.parse(request.headers.cookie || '');
	if (coolToken) {
		try {
			await verifyUserId(coolToken)
			request.locals.isLoggedIn = true
			request.locals.ghToken = ghToken
			request.locals.coolToken = coolToken
		} catch (error) {
			request.locals.isLoggedIn = false
			request.locals.ghToken = null
			request.locals.coolToken = null
			return {
				status: 200,
				headers: {
					'set-cookie': [
						...deleteCookies
					]
				}
			};
		}
	} else {
		const cookies = cookie.parse(request.headers.cookie || '');
		if (Object.keys(cookies).length === 0 && !publicPages.includes(request.path)) {
			return {
				status: 301,
				headers: {
					location: '/'
				}
			};
		} else {
			const response = await render(request);
			if (!publicPages.includes(request.path)) {
				return {
					...response,
					headers: {
						...response.headers,
						'set-cookie': [...deleteCookies]
					}
				};
			} else {
				return {
					...response
				}
			}
		}

	}
	const response = await render(request);
	return {
		...response
	}
}
export function getSession(request) {
	const { coolToken, ghToken } = request.locals
	return {
		isLoggedIn: coolToken ? true : false,
		coolToken: coolToken,
		ghToken: ghToken
	};
}
