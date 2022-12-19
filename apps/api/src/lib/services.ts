import { isARM, isDev } from './common';
import fs from 'fs/promises';
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
const compareSemanticVersions = (a: string, b: string) => {
	const a1 = a.split('.');
	const b1 = b.split('.');
	const len = Math.min(a1.length, b1.length);
	for (let i = 0; i < len; i++) {
		const a2 = +a1[i] || 0;
		const b2 = +b1[i] || 0;
		if (a2 !== b2) {
			return a2 > b2 ? 1 : -1;
		}
	}
	return b1.length - a1.length;
};
export async function getTags(type: string) {
	try {
		if (type) {
			const tagsPath = isDev ? './tags.json' : '/app/tags.json';
			const data = await fs.readFile(tagsPath, 'utf8');
			let tags = JSON.parse(data);
			if (tags) {
				tags = tags.find((tag: any) => tag.name.includes(type));
				tags.tags = tags.tags.sort(compareSemanticVersions).reverse();
				return tags;
			}
		}
	} catch (error) {
		return [];
	}
}
