import { Head } from '@inertiajs/react';

export default function Home({ flux, clusters }) {
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
            </main>
        </>
    );
}
