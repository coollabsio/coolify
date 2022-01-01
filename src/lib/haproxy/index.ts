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
export async function getRawConfiguration(): Promise<RawHaproxyConfiguration> {
    return await haproxyInstance().get(`v2/services/haproxy/configuration/raw`).json()
}
export async function getNextTransactionVersion(): Promise<number> {
    const raw = await getRawConfiguration()
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

export async function removeProxyConfiguration({ domain }) {
    const haproxy = haproxyInstance()
    const transactionId = await getNextTransactionId()
    const backendFound = await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
    console.log(backendFound)
    if (backendFound) {
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
        await completeTransaction(transactionId)

    }
}

export async function configureProxy({ domain, applicationId, port }) {
    const haproxy = haproxyInstance()

    try {
        await haproxy.get('v2/info')
    } catch (error) {
        return
    }

    try {
        try {
            const backend = await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
            const server = await haproxy.get(`v2/services/haproxy/configuration/servers/${applicationId}`, {
                searchParams: {
                    backend: domain
                },
            }).json()
            if (backend && server) {
                // Very sophisticated way to check if the server is already configured in proxy
                if (backend.data.forwardfor.enabled === 'enabled') {
                    if (backend.data.name === domain) {
                        if (server.data.check === 'enabled') {
                            if (server.data.address === applicationId) {
                                if (server.data.port === port) {
                                    console.log('proxy already configured for this application', domain, applicationId, port)
                                    return
                                }
                            }
                        }
                    }
                }
            }
        } catch (error) {
            // No worries, it's okay to not handle it
        }
        const transactionId = await getNextTransactionId()

        try {
            await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
            await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
                searchParams: {
                    transaction_id: transactionId
                },
            }).json()
        } catch (error) {

        }
        await haproxy.post('v2/services/haproxy/configuration/backends', {
            searchParams: {
                transaction_id: transactionId
            },
            json: {
                "init-addr": "last,libc,none",
                "forwardfor": { "enabled": "enabled" },
                "name": domain
            }
        })

        await haproxy.post('v2/services/haproxy/configuration/servers', {
            searchParams: {
                transaction_id: transactionId,
                backend: domain
            },
            json: {
                "address": applicationId,
                "check": "enabled",
                "name": applicationId,
                "port": port
            }
        })
        await completeTransaction(transactionId)
        console.log('proxy configured for this application', domain, applicationId, port)
    } catch (error) {
        console.log(error)
        throw new Error(error)
    }
}
