import { Head } from '@inertiajs/react';

import { AppNavbar } from '@/components/app-navbar';
import type { V5HomeProps } from '@/types';

export default function Home({
    flux,
    clusters = [],
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}: V5HomeProps) {
    return (
        <>
            <Head title="Magic" />

            <div className="h-dvh overflow-hidden bg-background text-foreground">
                <AppNavbar
                    flux={flux}
                    clusters={clusters}
                    projects={projects}
                    selectedProjectUuid={selectedProjectUuid}
                    selectedEnvironmentUuid={selectedEnvironmentUuid}
                />

                <main className="flex h-full min-h-0 items-center justify-center overflow-hidden px-6 pt-16">
                    <section className="flex w-full max-w-5xl flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-card px-8 py-24 text-center">
                        <p className="text-sm font-medium text-foreground">Magic</p>
                        <p className="max-w-md text-sm text-muted-foreground">This is where the magic happens.</p>
                    </section>
                </main>
            </div>
        </>
    );
}
