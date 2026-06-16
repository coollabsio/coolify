import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function Home({ currentTeam, teams, flux, clusters, cooldServers, privateKeys }) {
    const firstPrivateKey = privateKeys[0]?.uuid || '';
    const [bootstrapResult, setBootstrapResult] = useState(null);
    const [bootstrapping, setBootstrapping] = useState(false);
    const [bootstrapForm, setBootstrapForm] = useState({
        host: '',
        ssh_user: 'root',
        ssh_port: '22',
        private_key_uuid: firstPrivateKey,
        wg_listen_port: '',
        wg_endpoint: '',
        enable_builder: true,
        builder_capacity: '2',
    });

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function updateBootstrapForm(field, value) {
        setBootstrapForm((current) => ({
            ...current,
            [field]: value,
        }));
    }

    async function bootstrapCoolifyMesh(event) {
        event.preventDefault();
        setBootstrapping(true);
        setBootstrapResult(null);

        const payload = {
            host: bootstrapForm.host,
            ssh_user: bootstrapForm.ssh_user,
            ssh_port: Number(bootstrapForm.ssh_port),
            private_key_uuid: bootstrapForm.private_key_uuid,
            enable_builder: bootstrapForm.enable_builder,
            builder_capacity: bootstrapForm.builder_capacity === '' ? null : Number(bootstrapForm.builder_capacity),
            wg_listen_port: bootstrapForm.wg_listen_port === '' ? null : Number(bootstrapForm.wg_listen_port),
            wg_endpoint: bootstrapForm.wg_endpoint || null,
        };

        try {
            const response = await fetch('/v5/coolify/bootstrap', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();

            setBootstrapResult(result);
        } catch (error) {
            setBootstrapResult({
                successful: false,
                label: 'Bootstrap failed',
                message: 'Could not start the coolify bootstrap command.',
                output: null,
                errorOutput: null,
                exitCode: null,
            });
        } finally {
            setBootstrapping(false);
        }
    }

    return (
        <>
            <Head title="V5" />

            <main>
                <h1>Coolify v5</h1>

                <section aria-label="Flux status">
                    <p>
                        <strong>Flux:</strong> {flux.label} — {flux.socket ? flux.socket : flux.message}
                    </p>
                </section>

                <section aria-labelledby="clusters-heading">
                    <h2 id="clusters-heading">Clusters</h2>

                    {clusters.length === 0 ? (
                        <p>No clusters have been added yet.</p>
                    ) : (
                        <ul>
                            {clusters.map((cluster) => (
                                <li key={cluster.id}>
                                    <strong>{cluster.name}</strong> — {cluster.serversCount}{' '}
                                    {cluster.serversCount === 1 ? 'server' : 'servers'}
                                    {cluster.description ? <p>{cluster.description}</p> : null}
                                    {cluster.servers.length > 0 ? (
                                        <ul>
                                            {cluster.servers.map((server) => (
                                                <li key={server.id}>
                                                    {server.name} ({server.status}) — {server.host};{' '}
                                                    {server.capabilities.join(', ')}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="coold-server-heading">
                    <h2 id="coold-server-heading">coold servers</h2>

                    {cooldServers.length === 0 ? (
                        <p>No coold serverss have been added yet.</p>
                    ) : (
                        <ul>
                            {cooldServers.map((host) => (
                                <li key={host.id}>
                                    <strong>{host.host}</strong> ({host.status}) — SSH {host.sshUser}@{host.host}:{host.sshPort};{' '}
                                    {host.capabilities.join(', ')}; builder{' '}
                                    {host.builderEnabled
                                        ? `enabled, capacity ${host.builderCapacity}`
                                        : 'disabled'}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="coolify-heading">
                    <h2 id="coolify-heading">coolify</h2>

                    <form onSubmit={bootstrapCoolifyMesh}>
                        <h3>Bootstrap server</h3>

                        <label>
                            Host/IP
                            <input
                                type="text"
                                value={bootstrapForm.host}
                                onChange={(event) => updateBootstrapForm('host', event.target.value)}
                                placeholder="203.0.113.10"
                                required
                            />
                        </label>

                        <label>
                            SSH user
                            <input
                                type="text"
                                value={bootstrapForm.ssh_user}
                                onChange={(event) => updateBootstrapForm('ssh_user', event.target.value)}
                                required
                            />
                        </label>

                        <label>
                            SSH port
                            <input
                                type="number"
                                min="1"
                                max="65535"
                                value={bootstrapForm.ssh_port}
                                onChange={(event) => updateBootstrapForm('ssh_port', event.target.value)}
                                required
                            />
                        </label>

                        <label>
                            Private key
                            <select
                                value={bootstrapForm.private_key_uuid}
                                onChange={(event) => updateBootstrapForm('private_key_uuid', event.target.value)}
                                required
                            >
                                <option value="" disabled>Select a private key</option>
                                {privateKeys.map((privateKey) => (
                                    <option key={privateKey.uuid} value={privateKey.uuid}>
                                        {privateKey.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <details>
                            <summary>Advanced mesh options</summary>

                            <label>
                                WireGuard listen port override
                                <input
                                    type="number"
                                    min="1"
                                    max="65535"
                                    value={bootstrapForm.wg_listen_port}
                                    onChange={(event) => updateBootstrapForm('wg_listen_port', event.target.value)}
                                />
                            </label>

                            <label>
                                WireGuard endpoint override
                                <input
                                    type="text"
                                    value={bootstrapForm.wg_endpoint}
                                    onChange={(event) => updateBootstrapForm('wg_endpoint', event.target.value)}
                                    placeholder="host.example:51821"
                                />
                            </label>

                            <label>
                                <input
                                    type="checkbox"
                                    checked={bootstrapForm.enable_builder}
                                    onChange={(event) => updateBootstrapForm('enable_builder', event.target.checked)}
                                />
                                Enable builder
                            </label>

                            <label>
                                Builder capacity
                                <input
                                    type="number"
                                    min="0"
                                    max="100"
                                    value={bootstrapForm.builder_capacity}
                                    onChange={(event) => updateBootstrapForm('builder_capacity', event.target.value)}
                                />
                            </label>
                        </details>

                        <button type="submit" disabled={bootstrapping || privateKeys.length === 0}>
                            {bootstrapping ? 'Bootstrapping server...' : 'Bootstrap server'}
                        </button>

                        {privateKeys.length === 0 ? (
                            <p>Add a private key before bootstrapping a server.</p>
                        ) : null}
                    </form>

                    {bootstrapResult ? (
                        <div>
                            <p>
                                <strong>{bootstrapResult.label}</strong>
                            </p>
                            <p>{bootstrapResult.message}</p>
                            {bootstrapResult.exitCode !== null ? (
                                <p>Exit code: {bootstrapResult.exitCode}</p>
                            ) : null}
                            {bootstrapResult.output ? <pre>{bootstrapResult.output}</pre> : null}
                            {bootstrapResult.errorOutput ? <pre>{bootstrapResult.errorOutput}</pre> : null}
                        </div>
                    ) : null}
                </section>

                <h2>Current team</h2>

                {currentTeam ? (
                    <dl>
                        <dt>Name</dt>
                        <dd>{currentTeam.name}</dd>

                        <dt>Description</dt>
                        <dd>{currentTeam.description || 'No description'}</dd>

                        <dt>Your role</dt>
                        <dd>{currentTeam.role}</dd>
                    </dl>
                ) : (
                    <p>No team selected.</p>
                )}

                <h2>Your teams</h2>

                <ul>
                    {teams.map((team) => (
                        <li key={team.id}>
                            {team.name} ({team.role})
                        </li>
                    ))}
                </ul>
            </main>
        </>
    );
}
