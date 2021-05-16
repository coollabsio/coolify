import type { Request } from '@sveltejs/kit';
import mongoose from 'mongoose';
import User from '$models/User';
import Settings from '$models/Settings';
import cuid from 'cuid';
import jsonwebtoken from 'jsonwebtoken';
import { githubAPI } from '$lib/api/github';

export async function get(request: Request) {
	const code = request.query.get('code');
	const { GITHUB_APP_CLIENT_SECRET, JWT_SIGN_KEY, VITE_GITHUB_APP_CLIENTID } = process.env;
	try {
		let uid = cuid();
		const { access_token } = await (
			await fetch(
				`https://github.com/login/oauth/access_token?client_id=${VITE_GITHUB_APP_CLIENTID}&client_secret=${GITHUB_APP_CLIENT_SECRET}&code=${code}`,
				{ headers: { accept: 'application/json' } }
			)
		).json();
		const { avatar_url } = await (await githubAPI(request, '/user', access_token)).body;
		const email = (await githubAPI(request, '/user/emails', access_token)).body.filter(
			(e) => e.primary
		)[0].email;
		const settings = await Settings.findOne({ applicationName: 'coolify' });
		const registeredUsers = await User.find().countDocuments();
		const foundUser = await User.findOne({ email });
		if (foundUser) {
			await User.findOneAndUpdate({ email }, { avatar: avatar_url }, { upsert: true, new: true });
			uid = foundUser.uid;
		} else {
			if (registeredUsers === 0) {
				const newUser = new User({
					_id: new mongoose.Types.ObjectId(),
					email,
					avatar: avatar_url,
					uid
				});
				const defaultSettings = new Settings({
					_id: new mongoose.Types.ObjectId()
				});
				try {
					await newUser.save();
					await defaultSettings.save();
				} catch (error) {
					return {
						status: 500,
						error: error.message || error
					};
				}
			} else {
				if (!settings && registeredUsers > 0) {
					return {
						status: 500,
						body: {
							error: 'Registration disabled, enable it in settings.'
						}
					};
				} else {
					if (!settings.allowRegistration) {
						return {
							status: 500,
							body: {
								error: 'You are not allowed here!'
							}
						};
					} else {
						const newUser = new User({
							_id: new mongoose.Types.ObjectId(),
							email,
							avatar: avatar_url,
							uid
						});
						try {
							await newUser.save();
						} catch (error) {
							return {
								status: 500,
								body: {
									error: error.message || error
								}
							};
						}
					}
				}
			}
		}
		const coolToken = jsonwebtoken.sign({}, JWT_SIGN_KEY, {
			expiresIn: 15778800,
			algorithm: 'HS256',
			audience: 'coolLabs',
			issuer: 'coolLabs',
			jwtid: uid,
			subject: `User:${uid}`,
			notBefore: -1000
		});
		request.locals.session.data = { coolToken, ghToken: access_token };
		return {
			status: 302,
			headers: {
				location: `/success`
			}
		};
	} catch (error) {
		return { status: 500, body: { error: error.message || error } };
	}
}
