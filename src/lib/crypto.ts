import crypto from 'crypto'
const algorithm = 'aes-256-ctr';

export const base64Encode = (text: string) => {
    return Buffer.from(text).toString('base64')
}
export const base64Decode = (text: string) => {
    return Buffer.from(text, 'base64').toString('ascii')
}
export const encrypt = (text: string) => {
    if (text) {
        const iv = crypto.randomBytes(16);
        const cipher = crypto.createCipheriv(algorithm, process.env['SECRET_KEY'], iv);
        const encrypted = Buffer.concat([cipher.update(text), cipher.final()]);
        return JSON.stringify({
            iv: iv.toString('hex'),
            content: encrypted.toString('hex')
        })
    }

};

export const decrypt = (hashString: string) => {
    if (hashString) {
        const hash: Hash = JSON.parse(hashString)
        const decipher = crypto.createDecipheriv(algorithm, process.env['SECRET_KEY'], Buffer.from(hash.iv, 'hex'));
        const decrpyted = Buffer.concat([decipher.update(Buffer.from(hash.content, 'hex')), decipher.final()]);
        return decrpyted.toString();
    }
};

