import crypto from 'crypto'
const { SECRET_KEY } = process.env;
const algorithm = 'aes-256-ctr';
const secretKey = SECRET_KEY;

export const encrypt = (text: string) => {
    if (text) {
        const iv = crypto.randomBytes(16);
        const cipher = crypto.createCipheriv(algorithm, secretKey, iv);
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
        const decipher = crypto.createDecipheriv(algorithm, secretKey, Buffer.from(hash.iv, 'hex'));
        const decrpyted = Buffer.concat([decipher.update(Buffer.from(hash.content, 'hex')), decipher.final()]);
        return decrpyted.toString();
    }
};

