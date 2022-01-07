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
export async function forceSSLOnDatabase({ domain }) {
    if (!dev) {
        const haproxy = haproxyInstance()
        await checkHAProxy()
        const transactionId = await getNextTransactionId()

        try {
            const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
                searchParams: {
                    parent_name: 'tcp',
                    parent_type: 'frontend',
                }
            }).json()

            if (rules.data.length > 0) {
                for (const rule of rules.data) {
                    if (rule.name === domain) {
                        await haproxy.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
                            searchParams: {
                                frontend: 'tcp',
                                transaction_id: transactionId
                            }
                        }).json()
                    }
                }
            }
        } catch (error) {
            console.log(error)
        } finally {
            await completeTransaction(transactionId)
        }
    } else {
        console.log(`adding ssl for ${domain}`)
    }

}

export async function forceSSLOffDatabase({ domain }) {
    if (!dev) {
        const haproxy = haproxyInstance()
        await checkHAProxy()
        const transactionId = await getNextTransactionId()

        try {
            const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
                searchParams: {
                    parent_name: 'tcp',
                    parent_type: 'frontend',
                }
            }).json()
            if (rules.data.length > 0) {
                for (const rule of rules.data) {
                    if (rule.name === domain) {
                        await haproxy.delete(`v2/services/haproxy/configuration/http_request_rules/${rule.index}`, {
                            searchParams: {
                                frontend: 'tcp',
                                transaction_id: transactionId
                            }
                        }).json()
                    }
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
export async function configureProxyForDatabase({ domain, id, port, isPublic }) {
    const haproxy = haproxyInstance()
    await checkHAProxy()
    try {
        if (!isPublic) {
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
            try {
                const rules = await haproxy.get(`v2/services/haproxy/configuration/backend_switching_rules`, {
                    searchParams: {
                        frontend: 'tcp'
                    }
                }).json()
                if (rules.data.length > 0) {
                    for (const rule of rules.data) {
                        if (rule.name === domain) {
                            console.log({ found: true, switch: true, rule })
                            await haproxy.delete(`v2/services/haproxy/configuration/backend_switching_rules/${rule.index}`, {
                                searchParams: {
                                    frontend: 'tcp',
                                    transaction_id: transactionId
                                }
                            }).json()
                            console.log({ deleted: true, switch: true, rule })
                        }
                    }
                }
            } catch (error) {
                console.log('switching rules deletion failed')
                console.log(error.response.body)
            }
            // try {
            //     const rules = await haproxy.get(`v2/services/haproxy/configuration/acls`, {
            //         searchParams: {
            //             parent_name: 'tcp',
            //             parent_type: 'frontend',
            //         }
            //     }).json()
            //     if (rules.data.length > 0) {
            //         for (const rule of rules.data) {
            //             if (rule.acl_name === domain) {
            //                 console.log({ found: true, acls: true, rule })
            //                 await haproxy.delete(`v2/services/haproxy/configuration/acls/${rule.index}`, {
            //                     searchParams: {
            //                         parent_name: 'tcp',
            //                         parent_type: 'frontend',
            //                         transaction_id: transactionId
            //                     }
            //                 }).json()
            //                 console.log({ deleted: true, acls: true, rule })
            //             }
            //         }
            //     }
            // } catch (error) {
            //     console.log('acl deletion failed')
            //     console.log(error.response.body)
            // }
            await completeTransaction(transactionId)
        }
        else {
            const transactionId = await getNextTransactionId()
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
                    "port": port
                }
            })
            // await haproxy.post(`v2/services/haproxy/configuration/acls`, {
            //     searchParams: {
            //         transaction_id: transactionId,
            //         parent_name: 'tcp',
            //         parent_type: 'frontend',
            //     },
            //     json: {
            //         "acl_name": `${domain}`,
            //         "criterion": "req.ssl_sni",
            //         "index": 0,
            //         "value": `-i ${domain}`
            //     }
            // })
            await haproxy.post(`v2/services/haproxy/configuration/backend_switching_rules`, {
                searchParams: {
                    transaction_id: transactionId,
                    frontend: 'tcp'
                },
                json: {
                    "cond": "if",
                    "cond_test": `{ req.ssl_sni -i ${domain} }`,
                    "index": 0,
                    "name": `${domain}`,
                }
            })
            await completeTransaction(transactionId)

        }

        let sslConfigured = false
        const rules: any = await haproxy.get(`v2/services/haproxy/configuration/http_request_rules`, {
            searchParams: {
                parent_name: 'ftp',
                parent_type: 'frontend',
            }
        }).json()
        if (rules.data.length > 0) {
            const rule = rules.data.find(rule => rule.cond_test.includes(`-i ${domain}`))
            if (rule) sslConfigured = true
        }
        if (isPublic && !sslConfigured) {
            await forceSSLOnDatabase({ domain })
        } else {
            await forceSSLOffDatabase({ domain })
        }
    } catch (error) {
        console.log(error.response.body)
        // No worries, it's okay to not handle it
    }


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