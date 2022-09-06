import dotenv from 'dotenv';
dotenv.config()
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url';
import Gettext from 'node-gettext'
import { po } from 'gettext-parser'
import got from 'got';
const __filename = fileURLToPath(import.meta.url);

const __dirname = path.dirname(__filename);

const weblateInstanceURL = process.env.WEBLATE_INSTANCE_URL;
const weblateComponentName = process.env.WEBLATE_COMPONENT_NAME
const token = process.env.WEBLATE_TOKEN;

const translationsDir = process.env.TRANSLATION_DIR;
const translationsPODir = './locales';
const locales = []
const domain = 'locale'

const translations = await got(`${weblateInstanceURL}/api/components/${weblateComponentName}/glossary/translations/?format=json`, {
    headers: {
        "Authorization": `Token ${token}`
    }
}).json()
for (const translation of translations.results) {
    const code = translation.language_code
    locales.push(code)

    const fileUrl = translation.file_url.replace('=json', '=po')
    const file = await got(fileUrl, {
        headers: {
            "Authorization": `Token ${token}`
        }
    }).text()
    fs.writeFileSync(path.join(__dirname, translationsPODir, domain + '-' + code + '.po'), file)
}


const gt = new Gettext()

locales.forEach((locale) => {
    let json = {}
    const fileName = `${domain}-${locale}.po`
    const translationsFilePath = path.join(translationsPODir, fileName)
    const translationsContent = fs.readFileSync(translationsFilePath)

    const parsedTranslations = po.parse(translationsContent)
    const a = gt.gettext(parsedTranslations)
    for (const [key, value] of Object.entries(a)) {
        if (key === 'translations') {
            for (const [key1, value1] of Object.entries(value)) {
                if (key1 !== '') {
                    for (const [key2, value2] of Object.entries(value1)) {
                        json[value2.msgctxt] = value2.msgstr[0]
                    }
                }
            }
        }
    }
    fs.writeFileSync(`${translationsDir}/${locale}.json`, JSON.stringify(json))
})