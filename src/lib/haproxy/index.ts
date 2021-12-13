import { dev } from "$app/env";
import got from "got";
const url = dev ? 'http://localhost:5555' : 'http://coolify-haproxy:5555'

export function haproxyInstance() {
    return got.extend({
        prefixUrl: url,
        username: 'haproxy-dataplaneapi',
        password: 'adminpwd'
    });
}
export async function getNextTransactionVersion(): Promise<number> {
    const raw: RawHaproxyConfiguration = await haproxyInstance().get(`v2/services/haproxy/configuration/raw`).json()
    if (raw?._version) {
        return raw._version
    }
    return 1
}

export async function getNextTransactionId(): Promise<string> {
    const version = await getNextTransactionVersion()
    const newTransaction: NewTransaction = await haproxyInstance().post('v2/services/haproxy/transactions', {
        searchParams: {
            version
        }
    }).json()
    return newTransaction.id
}

export async function completeTransaction(transactionId) {
    return await haproxyInstance().put(`v2/services/haproxy/transactions/${transactionId}`)
}

