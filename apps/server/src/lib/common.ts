import type { Permission, Setting, Team, TeamInvitation, User } from '@prisma/client';
import { prisma } from '../prisma';
import bcrypt from 'bcryptjs';
import crypto from 'crypto';
import fs from 'fs/promises';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import type { Config } from 'unique-names-generator';
import { env } from '../env';
import { day } from './dayjs';

const customConfig: Config = {
	dictionaries: [adjectives, colors, animals],
	style: 'capital',
	separator: ' ',
	length: 3
};
const algorithm = 'aes-256-ctr';
export const isDev = env.NODE_ENV === 'development';
export const version = '3.13.0';
export const sentryDSN =
	'https://409f09bcb7af47928d3e0f46b78987f3@o1082494.ingest.sentry.io/4504236622217216';

export async function listSettings(): Promise<Setting | null> {
	return await prisma.setting.findUnique({ where: { id: '0' } });
}
export async function getCurrentUser(
	userId: string
): Promise<(User & { permission: Permission[]; teams: Team[] }) | null> {
	return await prisma.user.findUnique({
		where: { id: userId },
		include: { teams: true, permission: true }
	});
}
export async function getTeamInvitation(userId: string): Promise<TeamInvitation[]> {
	return await prisma.teamInvitation.findMany({ where: { uid: userId } });
}

export async function hashPassword(password: string): Promise<string> {
	const saltRounds = 15;
	return bcrypt.hash(password, saltRounds);
}
export async function comparePassword(password: string, hashedPassword: string): Promise<boolean> {
	return bcrypt.compare(password, hashedPassword);
}
export const uniqueName = (): string => uniqueNamesGenerator(customConfig);

export const decrypt = (hashString: string) => {
	if (hashString) {
		try {
			const hash = JSON.parse(hashString);
			const decipher = crypto.createDecipheriv(
				algorithm,
				env.COOLIFY_SECRET_KEY,
				Buffer.from(hash.iv, 'hex')
			);
			const decrpyted = Buffer.concat([
				decipher.update(Buffer.from(hash.content, 'hex')),
				decipher.final()
			]);
			return decrpyted.toString();
		} catch (error) {
			if (error instanceof Error) {
				console.log({ decryptionError: error.message });
			}
			return hashString;
		}
	}
	return false;
};

export function generateRangeArray(start, end) {
	return Array.from({ length: end - start }, (v, k) => k + start);
}
export function generateTimestamp(): string {
	return `${day().format('HH:mm:ss.SSS')}`;
}
export const encrypt = (text: string) => {
	if (text) {
		const iv = crypto.randomBytes(16);
		const cipher = crypto.createCipheriv(algorithm, env.COOLIFY_SECRET_KEY, iv);
		const encrypted = Buffer.concat([cipher.update(text.trim()), cipher.final()]);
		return JSON.stringify({
			iv: iv.toString('hex'),
			content: encrypted.toString('hex')
		});
	}
	return false;
};

export async function getTemplates() {
	const templatePath = isDev ? './templates.json' : '/app/templates.json';
	const open = await fs.open(templatePath, 'r');
	try {
		let data = await open.readFile({ encoding: 'utf-8' });
		let jsonData = JSON.parse(data);
		if (isARM(process.arch)) {
			jsonData = jsonData.filter((d) => d.arch !== 'amd64');
		}
		return jsonData;
	} catch (error) {
		return [];
	} finally {
		await open?.close();
	}
}
export function isARM(arch: string) {
	if (arch === 'arm' || arch === 'arm64' || arch === 'aarch' || arch === 'aarch64') {
		return true;
	}
	return false;
}
