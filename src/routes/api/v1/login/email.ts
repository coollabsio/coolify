import mongoose from 'mongoose';
import Settings from '$models/Settings';
import User from '$models/User';
import bcrypt from 'bcrypt';
import cuid from 'cuid';
import jsonwebtoken from 'jsonwebtoken';
import type { Request } from '@sveltejs/kit';

const saltRounds = 15;

export async function post(request: Request) {
	const { email, password } = request.body;
	const { JWT_SIGN_KEY } = process.env;
	const settings = await Settings.findOne({ applicationName: 'coolify' });
	const registeredUsers = await User.find().countDocuments();
	const foundUser = await User.findOne({ email });
	try {
		let uid = cuid();
		if (foundUser) {
			if (foundUser.type === 'github') {
				return {
					status: 500,
					body: {
						error: 'Wrong password or email address.'
					}
				};
			}
			uid = foundUser.uid;
			if (!(await bcrypt.compare(password, foundUser.password))) {
				return {
					status: 500,
					body: {
						error: 'Wrong password or email address.'
					}
				};
			}
		} else {
			if (registeredUsers === 0) {
				const newUser = new User({
					_id: new mongoose.Types.ObjectId(),
					email,
					uid,
					type: 'email',
					password: await bcrypt.hash(password, saltRounds)
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
				if (!settings?.allowRegistration) {
					return {
						status: 500,
						body: {
							error: 'Registration disabled, enable it in settings.'
						}
					};
				} else {
					const newUser = new User({
						_id: new mongoose.Types.ObjectId(),
						email,
						uid,
						type: 'email',
						password: await bcrypt.hash(password, saltRounds)
					});
					try {
						await newUser.save();
					} catch (error) {
						return {
							status: 500,
							error: error.message || error
						};
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
		request.locals.session.data = { coolToken, ghToken: null };
		return {
			status: 200,
			body: {
				message: 'Successfully logged in.'
			}
		};
	} catch (error) {
		return { status: 500, body: { error: error.message || error } };
	}
}
