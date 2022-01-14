import { dev } from "$app/env";
import { asyncExecShell, getEngine } from "$lib/common";
import { letsEncryptQueue } from "$lib/queues";
import got from "got";
import * as db from '$lib/database';

const url = dev ? 'http://localhost:5555' : 'http://coolify-haproxy:5555'

export const defaultProxyImage = `coolify-haproxy-alpine:latest`
export const defaultProxyImageDatabase = `coolify-haproxy-db-alpine:latest`

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
    const haproxy = haproxyInstance()
    return await haproxy.put(`v2/services/haproxy/transactions/${transactionId}`)
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
        console.log(`[DEBUG] Adding ssl for ${domain}`)
    }

}

// export async function configureDatabaseVisibility({ id, isPublic }) {
//     const haproxy = haproxyInstance()
//     await checkHAProxy()
//     const transactionId = await getNextTransactionId()
//     const database = await db.prisma.database.findUnique({ where: { id }, include: { destinationDocker: true } })
//     try {
//         if (isPublic) {
//             await haproxy.delete(`v2/services/haproxy/configuration/tcp_request_rules/0`, {
//                 searchParams: {
//                     transaction_id: transactionId,
//                     parent_name: id,
//                     parent_type: 'frontend',
//                 }
//             })
//         } else {
//             await haproxy.post(`v2/services/haproxy/configuration/tcp_request_rules`, {
//                 searchParams: {
//                     transaction_id: transactionId,
//                     parent_name: id,
//                     parent_type: 'frontend',
//                 },
//                 json: {
//                     cond: "if",
//                     cond_test: `{ src ${database.destinationDocker.subnet} }`,
//                     "action": "accept",
//                     "index": 0,
//                     "type": "connection"
//                 }
//             })
//         }

//     } catch (error) {
//         console.log(error.response.body)
//         throw error.response.body
//     } finally {
//         await completeTransaction(transactionId)
//     }
// }
export async function deleteProxyForDatabase({ id }) {
    const haproxy = haproxyInstance()
    try {
        await checkHAProxy()
    } catch (error) {
        return
    }
    const transactionId = await getNextTransactionId()
    try {
        await haproxy.get(`v2/services/haproxy/configuration/backends/${id}`).json()
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${id}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
        await haproxy.get(`v2/services/haproxy/configuration/frontends/${id}`).json()
        await haproxy.delete(`v2/services/haproxy/configuration/frontends/${id}`, {
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
// export async function configureProxyForDatabase({ id, port, isPublic, privatePort }) {
//     const haproxy = haproxyInstance()
//     try {
//         await checkHAProxy()
//     } catch (error) {
//         return
//     }

//     let alreadyConfigured = false
//     try {
//         const backend: any = await haproxy.get(`v2/services/haproxy/configuration/backends/${id}`).json()
//         const server: any = await haproxy.get(`v2/services/haproxy/configuration/servers/${id}`, {
//             searchParams: {
//                 backend: id
//             },
//         }).json()
//         if (backend.data.name === id) {
//             if (server.data.port === privatePort) {
//                 if (server.data.check === 'enabled') {
//                     if (server.data.address === id) {
//                         alreadyConfigured = true
//                     }
//                 }
//             }
//         }
//     } catch (error) {
//         console.log('error getting backend or server', error.response.body)
//     }
//     if (alreadyConfigured) return

//     const transactionId = await getNextTransactionId()
//     try {
//         await haproxy.post('v2/services/haproxy/configuration/backends', {
//             searchParams: {
//                 transaction_id: transactionId
//             },
//             json: {
//                 "init-addr": "last,libc,none",
//                 "mode": "tcp",
//                 "name": id
//             }
//         })
//         await haproxy.post('v2/services/haproxy/configuration/servers', {
//             searchParams: {
//                 transaction_id: transactionId,
//                 backend: id
//             },
//             json: {
//                 "address": id,
//                 "check": "enabled",
//                 "name": id,
//                 "port": privatePort
//             }
//         })
//         await haproxy.post('v2/services/haproxy/configuration/frontends', {
//             searchParams: {
//                 transaction_id: transactionId,
//                 backend: id
//             },
//             json: {
//                 "default_backend": id,
//                 "mode": "tcp",
//                 "name": id
//             }
//         })
//         await haproxy.post('v2/services/haproxy/configuration/binds', {
//             searchParams: {
//                 transaction_id: transactionId,
//                 frontend: id
//             },
//             json: {
//                 "address": "*",
//                 "name": id,
//                 "port": port
//             }
//         })
//     } catch (error) {
//         console.log(error.response.body)
//         throw error.response.body
//     } finally {
//         try {
//             await completeTransaction(transactionId)
//         } catch (error) {
//             console.log(error.response.body)
//         }
//     }
//     await configureDatabaseVisibility({ id, isPublic })
// }
export async function configureProxyForApplication({ domain, applicationId, port, forceSSL }) {
    const haproxy = haproxyInstance()
    let serverConfigured = false
    let sslConfigured = false

    try {
        await checkHAProxy()
    } catch (error) {
        return
    }
    try {
        const backend: any = await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
        const server: any = await haproxy.get(`v2/services/haproxy/configuration/servers/${applicationId}`, {
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
                                // console.log('proxy already configured for this application', domain, applicationId, port)
                                serverConfigured = true
                            }
                        }
                    }
                }
            }
        }
    } catch (error) {
        console.log('error getting backend or server', error.response.body)
        //
    }
    try {
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
    } catch (error) {
        console.log('error getting http_request_rules', error.response.body)
        //
    }
    if (!sslConfigured && forceSSL) await forceSSLOnApplication({ domain })
    if (serverConfigured) return

    const transactionId = await getNextTransactionId()

    // Try to delete old backend
    try {
        await haproxy.get(`v2/services/haproxy/configuration/backends/${domain}`).json()
        await haproxy.delete(`v2/services/haproxy/configuration/backends/${domain}`, {
            searchParams: {
                transaction_id: transactionId
            },
        }).json()
    } catch (error) {
        console.log('error deleting backend', error.response.body)
    }
    try {
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
                "check": "disabled",
                "name": applicationId,
                "port": port
            }
        })

    } catch (error) {
        console.log(error.response.body)
        throw error.response.body
    } finally {
        await completeTransaction(transactionId)
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

export async function stopDatabaseProxy(destinationDocker, publicPort) {
    const { engine } = destinationDocker
    const host = getEngine(engine)
    const containerName = `haproxy-for-${publicPort}`
    const found = await checkContainer(engine, containerName)
    try {
        if (found) {
            return await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${containerName} && docker rm ${containerName}`)
        }
    } catch (error) {
        return error
    }

}
export async function startDatabaseProxy(destinationDocker, id, publicPort, privatePort) {
    const { network, engine } = destinationDocker
    const host = getEngine(engine)

    const containerName = `haproxy-for-${publicPort}`
    const found = await checkContainer(engine, containerName)
    const foundDB = await checkContainer(engine, id)

    try {
        if (foundDB && !found) {
            return await asyncExecShell(`DOCKER_HOST=${host} docker run --restart always -e PORT=${publicPort} -e APP=${id} -e PRIVATE_PORT=${privatePort} --add-host 'host.docker.internal:host-gateway' --network ${network} -p ${publicPort}:${publicPort} --name ${containerName} -d coollabsio/${defaultProxyImageDatabase}`)
        }
    } catch (error) {
        return error
    }

}
export async function startCoolifyProxy(engine) {
    const host = getEngine(engine)
    const found = await checkContainer(engine, 'coolify-haproxy')
    if (!found) {
        await asyncExecShell(`DOCKER_HOST="${host}" docker run --restart always --add-host 'host.docker.internal:host-gateway' -v coolify-ssl-certs:/usr/local/etc/haproxy/ssl --network coolify-infra -p "80:80" -p "443:443" -p "8404:8404" -p "5555:5555" -p "5000:5000" --name coolify-haproxy -d coollabsio/${defaultProxyImage}`)
        return await configureNetworkCoolifyProxy(engine)
    }
}
export async function checkContainer(engine, container) {
    const host = getEngine(engine)
    let containerFound = false

    try {
        const { stdout } = await asyncExecShell(`DOCKER_HOST="${host}" docker inspect --format '{{json .State}}' ${container}`)
        
        const parsedStdout = JSON.parse(stdout)
        const status = parsedStdout.Status
        const isRunning = parsedStdout.Running

        if (status === 'exited' || status === 'created') {
            await asyncExecShell(`DOCKER_HOST="${host}" docker rm ${container}`)
        }
        if (isRunning) {
            containerFound = true
        }

    } catch (err) {
        // Container not found
    }
    return containerFound
}

export async function stopCoolifyProxy(engine) {
    const host = getEngine(engine)
    const found = await checkContainer(engine, 'coolify-haproxy')
    try {
        if (found) {
            await asyncExecShell(`DOCKER_HOST="${host}" docker stop -t 0 coolify-haproxy && docker rm coolify-haproxy`)
        }
    } catch (error) {
        return error
    }

}

export async function configureNetworkCoolifyProxy(engine) {
    const host = getEngine(engine)
    const destinations = await db.prisma.destinationDocker.findMany({ where: { engine } })
    destinations.forEach(async (destination) => {
        try {
            await asyncExecShell(`DOCKER_HOST="${host}" docker network connect ${destination.network} coolify-haproxy`)
        } catch (err) {
            // TODO: handle error
        }
    })
}