import { verifyUserId } from '$lib/api/applications/common';
import * as cookie from 'cookie';
import mongoose from 'mongoose';

let db = null;
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
	const response = await render(request);
	const { coolToken } = cookie.parse(request.headers.cookie || '');
	if (coolToken) {
		try {
			await verifyUserId(coolToken)
			console.log('user OK')
			return {
				...response,
			}
		} catch (error) {
			return {
				...response,
				headers: {
					location: '/',
					'set-cookie': [
						`coolToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`,
						`ghToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`
					]
				},
			}
		}
	}
	return {
		...response
	}

	// const response = await render(request);
	// return {
	// 	...response,
	// 	headers: {
	// 		location:'/',
	// 		'set-cookie': [
	// 			`coolToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`,
	// 			`ghToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`
	// 		]
	// 	},
	// }
}
export function getSession({ headers }) {
	const { coolToken, ghToken } = cookie.parse(headers.cookie || '');
	return {
		isLoggedIn: coolToken ? true : false,
		coolToken: coolToken || null,
		ghToken: ghToken || null
	};
}
