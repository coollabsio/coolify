import { dev } from "$app/env";
import { letsEncryptQueue } from "$lib/queues";
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

export async function reloadConfiguration(): Promise<any> {
    return await haproxyInstance().get(`v2/services/haproxy/reloads`)
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
    if (backendFound) {
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
        await completeTransaction(transactionId)

    }
}
// export async function forceSSLOnDatabase({ domain }) {
//     if (!dev) {
//         const haproxy = haproxyInstance()
//         await checkHAProxy()
//         const transactionId = await getNextTransactionId()

//         try {
//             const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
//                 searchParams: {
//                     parent_name: 'tcp',
//                     parent_type: 'frontend',
//                 }
//             }).json()

//             if (rules.data.length > 0) {
//                 for (const rule of rules.data) {
//                     if (rule.name === domain) {
//                         await haproxy.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
//                             searchParams: {
//                                 frontend: 'tcp',
//                                 transaction_id: transactionId
//                             }
//                         }).json()
//                     }
//                 }
//             }
//         } catch (error) {
//             console.log(error)
//         } finally {
//             await completeTransaction(transactionId)
//         }
//     } else {
//         console.log(`adding ssl for ${domain}`)
//     }

// }

// export async function forceSSLOffDatabase({ domain }) {
//     if (!dev) {
//         const haproxy = haproxyInstance()
//         await checkHAProxy()
//         const transactionId = await getNextTransactionId()

//         try {
//             const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
//                 searchParams: {
//                     parent_name: 'tcp',
//                     parent_type: 'frontend',
//                 }
//             }).json()
//             if (rules.data.length > 0) {
//                 for (const rule of rules.data) {
//                     if (rule.name === domain) {
//                         await haproxy.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
//                             searchParams: {
//                                 frontend: 'tcp',
//                                 transaction_id: transactionId
//                             }
//                         }).json()
//                     }
//                 }
//             }
//         } catch (error) {
//             console.log(error)
//         } finally {
//             await completeTransaction(transactionId)
//         }
//     } else {
//         console.log(`removing ssl for ${domain}`)
//     }

// }
export async function forceSSLOffApplication({ domain }) {
    if (!dev) {
        const haproxy = haproxyInstance()
        await checkHAProxy()
        const transactionId = await getNextTransactionId()
        try {
            const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
                searchParams: {
                    parent_name: 'http',
                    parent_type: 'frontend',
                }
            }).json()
            if (rules.data.length > 0) {
                const rule = rules.data.find(rule => rule.cond_test.includes(`-i ${domain}`))
                if (rule) {
                    await haproxy.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
                        searchParams: {
                            transaction_id: transactionId,
                            parent_name: 'http',
                            parent_type: 'frontend',
                        }
                    }).json()
                }
            }
        } catch (error) {
            console.log(error)
        } finally {
            await completeTransaction(transactionId)
        }
    } else {
        console.log(`removing ssl for ${domain}`)
    }

}
export async function forceSSLOnApplication({ domain }) {
    if (!dev) {
        const haproxy = haproxyInstance()
        await checkHAProxy()
        const transactionId = await getNextTransactionId()

        try {
            const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
                searchParams: {
                    parent_name: 'http',
                    parent_type: 'frontend',
                }
            }).json()
            let nextRule = 0
            if (rules.data.length > 0) {
                nextRule = rules.data[rules.data.length - 1].index + 1
            }
            await haproxy.post(`v2/services/haproxy/configuration/http_request_rules`, {
                searchParams: {
                    transaction_id: transactionId,
                    parent_name: 'http',
                    parent_type: 'frontend',
                },
                json: {
                    "index": nextRule,
                    "cond": "if",
                    "cond_test": `{ hdr(Host) -i ${domain} } !{ ssl_fc }`,
                    "type": "redirect",
                    "redir_type": "scheme",
                    "redir_value": "https",
                    "redir_code": 301
                }
            }).json()
        } catch (error) {
            console.log(error)
        } finally {
            await completeTransaction(transactionId)
        }
    } else {
        console.log(`adding ssl for ${domain}`)
    }

}

export async function configureDatabaseVisibility({ domain, isPublic }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()
    const transactionId = await getNextTransactionId()
    try {
        if (isPublic) {
            await haproxy.delete(`v2/services/haproxy/configuration/tcp_request_rules/0`, {
                searchParams: {
                    transaction_id: transactionId,
                    parent_name: domain,
                    parent_type: 'frontend',
                }
            })
        } else {
            await haproxy.post(`v2/services/haproxy/configuration/tcp_request_rules`, {
                searchParams: {
                    transaction_id: transactionId,
                    parent_name: domain,
                    parent_type: 'frontend',
                },
                json: {
                    "action": "reject",
                    "index": 0,
                    "type": "connection"
                }
            })

        }

    } catch (error) {
        console.log(error.response.body)
    } finally {
        await completeTransaction(transactionId)
    }
}
export async function deleteProxyForDatabase({ domain }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()
    const transactionId = await getNextTransactionId()
    try {
        await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
        await haproxy.get(`v2/services/haproxy/configuration/frontends/${domain}`).json()
        await haproxy.delete(`v2/services/haproxy/configuration/frontends/${domain}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
    } catch (error) {
        console.log(error.response.body)
    } finally {
        await completeTransaction(transactionId)
    }

}
export async function configureProxyForDatabase({ domain, id, port, isPublic, privatePort }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()
    const transactionId = await getNextTransactionId()
    try {
        await haproxy.post('v2/services/haproxy/configuration/backends', {
            searchParams: {
                transaction_id: transactionId
            },
            json: {
                "init-addr": "last,libc,none",
                "mode": "tcp",
                "name": domain
            }
        })

        await haproxy.post('v2/services/haproxy/configuration/servers', {
            searchParams: {
                transaction_id: transactionId,
                backend: domain
            },
            json: {
                "address": id,
                "check": "enabled",
                "name": id,
                "port": privatePort
            }
        })
        await haproxy.post('v2/services/haproxy/configuration/frontends', {
            searchParams: {
                transaction_id: transactionId,
                backend: domain
            },
            json: {
                "default_backend": domain,
                "mode": "tcp",
                "name": domain
            }
        })
        await haproxy.post('v2/services/haproxy/configuration/binds', {
            searchParams: {
                transaction_id: transactionId,
                frontend: domain
            },
            json: {
                "address": "*",
                "name": domain,
                "port": port
            }
        })

    } catch (error) {
        console.log(error.response.body)
        // No worries, it's okay to not handle it
    } finally {
        await completeTransaction(transactionId)
    }
    await configureDatabaseVisibility({ domain, isPublic })



}
export async function configureProxyForApplication({ domain, applicationId, port, forceSSL }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()

    try {
        try {
            const backend: any = await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
            const server: any = await haproxy.get(`v2/services/haproxy/configuration/servers/${applicationId}`, {
                searchParams: {
                    backend: domain
                },
            }).json()
            let serverConfigured = false
            let sslConfigured = false
            if (backend && server) {
                // Very sophisticated way to check if the server is already configured in proxy
                if (backend.data.forwardfor.enabled === 'enabled') {
                    if (backend.data.name === domain) {
                        if (server.data.check === 'enabled') {
                            if (server.data.address === applicationId) {
                                if (server.data.port === port) {
                                    // console.log('proxy already configured for this application', domain, applicationId, port)
                                    serverConfigured = true
                                }
                            }
                        }
                    }
                }
            }

            if (forceSSL) {
                const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
                    searchParams: {
                        parent_name: 'http',
                        parent_type: 'frontend',
                    }
                }).json()
                if (rules.data.length > 0) {
                    const rule = rules.data.find(rule => rule.cond_test.includes(`-i ${domain}`))
                    if (rule) sslConfigured = true
                }
            }
            if (!sslConfigured && forceSSL) await forceSSLOnApplication({ domain })
            if (serverConfigured) return

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
    } catch (error) {
        console.log(error)
        throw new Error(error)
    }
}

export async function configureCoolifyProxyOff({ domain }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()
    try {
        const transactionId = await getNextTransactionId()
        await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
        await completeTransaction(transactionId)
        if (!dev) {
            await forceSSLOffApplication({ domain })
        }
    } catch (error) {
        console.log(error)
    }
}
export async function checkHAProxy() {
    const haproxy = haproxyInstance()
    try {
        await haproxy.get('v2/info')
    } catch (error) {
        throw 'HAProxy is not running, but it should be!'
    }
}
export async function configureCoolifyProxyOn({ domain }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()

    try {
        await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
        return
    } catch (error) {

    }
    try {
        const transactionId = await getNextTransactionId()
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
                "address": dev ? "host.docker.internal" : "coolify",
                "check": "enabled",
                "name": "coolify",
                "port": 3000
            }
        })
        await completeTransaction(transactionId)
        if (!dev) {
            letsEncryptQueue.add(domain, { domain, isCoolify: true })
            await forceSSLOnApplication({ domain })
        }
    } catch (error) {
        console.log(error)
    }

}