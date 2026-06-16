import { Head } from '@inertiajs/react';

export default function Home({ status, currentTeam, teams, flux, cooldHosts }) {
    return (
        <>
            <Head title="V5" />

            <main>
                <p>{status}</p>

                <h1>Coolify v5</h1>

                <p>
                    This page is served from Laravel through Inertia and React, with routes,
                    assets, and future v5 tables isolated from the current v4 Livewire app.
                </p>

                <section aria-labelledby="flux-status-heading">
                    <h2 id="flux-status-heading">Flux status</h2>

                    <p>
                        <strong>{flux.label}</strong>
                    </p>

                    <p>{flux.message}</p>

                    {flux.socket ? <p>Socket: {flux.socket}</p> : null}
                </section>

                <section aria-labelledby="coold-host-heading">
                    <h2 id="coold-host-heading">coold host</h2>

                    <ul>
                        {cooldHosts.map((host) => (
                            <li key={host.id}>
                                <strong>{host.id}</strong>
                                {host.wireguardIp ? ` (${host.wireguardIp})` : ''}:
                                {' '}
                                {host.capabilities.join(', ')}; builder{' '}
                                {host.builderEnabled
                                    ? `enabled, capacity ${host.builderCapacity}`
                                    : 'disabled'}
                            </li>
                        ))}
                    </ul>
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
