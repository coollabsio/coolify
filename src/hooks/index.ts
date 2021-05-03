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
				'mongodb://b107331eeb7d:b861ef98ec960526be@mongodb:27017/coolify?&readPreference=primary&ssl=false',
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

export async function getContext({ headers }) {
	const { coolToken, ghToken } = cookie.parse(headers.cookie || '');
	return {
		isLoggedIn: coolToken ? true : false,
		coolToken: coolToken || null,
		ghToken: ghToken || null
	};
}

export function getSession({ context }) {
	return {
		isLoggedIn: context.isLoggedIn,
		coolToken: context.coolToken
	};
}
