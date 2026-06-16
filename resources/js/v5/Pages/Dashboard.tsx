import { Head } from '@inertiajs/react';

import { AppNavbar } from '@/components/app-navbar';
import type { V5DashboardProps } from '@/types';

export default function Dashboard({
    flux,
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}: V5DashboardProps) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="h-dvh overflow-hidden bg-background text-foreground">
                <AppNavbar
                    flux={flux}
                    projects={projects}
                    selectedProjectUuid={selectedProjectUuid}
                    selectedEnvironmentUuid={selectedEnvironmentUuid}
                />

                <main className="flex h-full min-h-0 items-center justify-center overflow-hidden px-6 pt-16">
                    <section className="flex w-full max-w-5xl flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-card px-8 py-24 text-center">
                        <p className="text-sm font-medium text-foreground">Dashboard</p>
                        <p className="max-w-md text-sm text-muted-foreground">This is where the magic happens.</p>
                    </section>
                </main>
            </div>
        </>
    );
}
