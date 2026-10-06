{{--
    Read-only infrastructure map on the dashboard. Livewire refreshes `map` (plain arrays,
    no IPs) every 15 seconds while the map is in the viewport; the Alpine `clusterMap`
    component (resources/js/cluster-map.js) lays it out. Below `sm` the map is a stacked
    list without pan, zoom, or traffic lines.
--}}
@php
    $dot = fn (string $type): string => "{
        'bg-emerald-500': {$type} === 'success',
        'bg-warning': {$type} === 'warning',
        'bg-red-500': {$type} === 'error',
        'bg-neutral-400 dark:bg-neutral-500': !['success', 'warning', 'error'].includes({$type}),
    }";
    $iconTile = 'flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.04] dark:text-fg-dim';
    $pill = 'inline-flex h-5 max-w-full items-center gap-1 whitespace-nowrap rounded-full border border-neutral-200 bg-neutral-100 px-1.5 text-[11px] font-medium leading-none text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white';
@endphp
<div wire:poll.15s.visible="refreshMap" data-testid="dashboard-cluster-map" class="min-w-0">
    <div wire:ignore x-data="clusterMap" x-on:keydown.escape.window="pinnedEdgeId = null" class="min-w-0">
        {{-- Canvas: pan, zoom, and the optional traffic layer. --}}
        <div
            class="hidden overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm sm:block dark:border-white/[0.08] dark:bg-white/[0.05]">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.08]">
                <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-neutral-500 dark:text-fg-faint">
                    <span x-show="!showTraffic">Drag to pan. Hold Ctrl and scroll to zoom.</span>
                    <span x-cloak x-show="showTraffic" class="inline-flex items-center gap-1.5">
                        <svg class="h-2 w-6" aria-hidden="true">
                            <line x1="0" y1="4" x2="24" y2="4" stroke-width="2" stroke-dasharray="5 3"
                                class="stroke-coollabs dark:stroke-warning" />
                        </svg>
                        Firewall rule
                    </span>
                    <span x-cloak x-show="showTraffic" class="inline-flex items-center gap-1.5">
                        <svg class="h-2 w-6" aria-hidden="true">
                            <line x1="0" y1="4" x2="24" y2="4" stroke-width="2"
                                class="stroke-neutral-500 dark:stroke-neutral-400" />
                        </svg>
                        Public domain
                    </span>
                    <span x-cloak x-show="showTraffic && edges.length === 0">No firewall rules or public domains yet.</span>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" x-on:click="toggleTraffic" x-bind:aria-pressed="showTraffic ? 'true' : 'false'"
                        title="Show firewall rules and public domains"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium ring-1 transition-colors focus-visible:outline-none focus-visible:ring-accent"
                        x-bind:class="showTraffic
                            ? 'bg-coollabs/10 text-coollabs ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'
                            : 'text-neutral-600 ring-neutral-200 hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:ring-white/[0.08] dark:hover:bg-white/[0.06] dark:hover:text-fg'">
                        <x-reicon name="network" class="size-3.5" />
                        Traffic
                    </button>
                    <span class="mx-1 h-5 w-px bg-neutral-200 dark:bg-white/[0.08]" aria-hidden="true"></span>
                    <button type="button" class="icon-button" aria-label="Zoom out" title="Zoom out"
                        x-on:click="zoomBy(-0.1)">
                        <span class="text-[15px] leading-none" aria-hidden="true">-</span>
                    </button>
                    <span class="min-w-10 text-center text-[11px] text-neutral-500 tabular-nums dark:text-fg-faint"
                        x-text="`${Math.round(zoom * 100)}%`"></span>
                    <button type="button" class="icon-button" aria-label="Zoom in" title="Zoom in"
                        x-on:click="zoomBy(0.1)">
                        <x-reicon name="plus" class="size-3.5" />
                    </button>
                    <button type="button" class="button" x-on:click="fit()">Fit</button>
                </div>
            </div>

            <div x-ref="viewport"
                class="relative cursor-grab touch-none overflow-auto overscroll-contain bg-neutral-50 bg-local bg-size-[20px_20px] bg-[radial-gradient(circle,var(--color-neutral-300)_1px,transparent_1px)] dark:bg-black/20 dark:bg-[radial-gradient(circle,rgb(255_255_255/0.1)_1px,transparent_1px)]"
                x-bind:class="panning?.moved && 'cursor-grabbing select-none'"
                x-bind:style="`height:${viewportHeight}px`"
                x-on:scroll="updateViewportScroll"
                x-on:scroll.window.passive="refreshPopover()"
                x-on:resize.window="refreshPopover()"
                x-on:wheel="onWheel($event)"
                x-on:pointerdown="startPan($event)"
                x-on:pointermove="movePan($event)"
                x-on:pointerup="finishPan"
                x-on:pointercancel="finishPan">
                <div class="relative"
                    x-bind:style="`width:${layout.size.width * zoom}px;height:${layout.size.height * zoom}px`">
                    <div class="absolute top-0 left-0 origin-top-left"
                        x-bind:style="`width:${layout.size.width}px;height:${layout.size.height}px;transform:scale(${zoom})`">

                        {{-- Cluster frames with their server cards and application rows. --}}
                        <template x-for="cluster in data.clusters" x-bind:key="cluster.uuid">
                            <section data-map-cluster
                                class="absolute rounded-xl border border-neutral-200 bg-white/70 dark:border-white/[0.1] dark:bg-white/[0.03]"
                                x-bind:style="frameStyle(frame(`cluster:${cluster.uuid}`))">
                                <div class="flex h-[52px] min-w-0 items-center justify-between gap-2 px-4">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <span class="{{ $iconTile }}"><x-reicon name="layers" class="size-3.5" /></span>
                                        <a x-bind:href="cluster.href" {{ wireNavigate() }}
                                            class="min-w-0 truncate text-[13px] font-semibold text-black hover:underline dark:text-fg"
                                            x-text="cluster.name" x-bind:title="cluster.name"></a>
                                        <span class="{{ $pill }} shrink-0" x-bind:title="`Network: ${cluster.status}`">
                                            <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('cluster.statusType') }}"></span>
                                            <span x-text="cluster.status"></span>
                                        </span>
                                    </div>
                                    <a x-cloak x-show="showTraffic" x-bind:href="cluster.firewallHref" {{ wireNavigate() }}
                                        class="shrink-0 text-[11px] font-medium text-neutral-500 hover:text-black hover:underline dark:text-fg-faint dark:hover:text-fg">
                                        Edit rules
                                    </a>
                                </div>
                                <p x-show="cluster.servers.length === 0"
                                    class="px-4 text-[12px] text-neutral-500 dark:text-fg-dim">No servers in this cluster yet.</p>

                                <template x-if="layout.internet[cluster.uuid]">
                                    <div class="absolute flex items-center justify-center gap-1.5 rounded-xl border border-neutral-300 bg-white text-[12px] font-medium text-neutral-700 shadow-sm dark:border-white/[0.14] dark:bg-neutral-900 dark:text-fg"
                                        x-bind:style="relativeStyle(layout.internet[cluster.uuid], frame(`cluster:${cluster.uuid}`))">
                                        <x-reicon name="globe" class="size-3.5" />
                                        Internet
                                    </div>
                                </template>

                                <template x-for="server in cluster.servers" x-bind:key="server.uuid">
                                    <article data-map-server
                                        class="absolute rounded-xl border bg-white shadow-sm transition-[border-color,box-shadow] dark:bg-neutral-900"
                                        x-bind:class="isServerHighlighted(cluster.uuid, server.uuid)
                                            ? 'border-coollabs ring-2 ring-coollabs/20 dark:border-warning dark:ring-warning/20'
                                            : 'border-neutral-200 dark:border-white/[0.1]'"
                                        x-bind:style="relativeStyle(serverRect(cluster.uuid, server.uuid), frame(`cluster:${cluster.uuid}`))">
                                        <div class="flex h-[60px] min-w-0 flex-col justify-center gap-1.5 px-2.5">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <x-reicon name="servers" class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                                <a x-bind:href="server.href" {{ wireNavigate() }}
                                                    class="min-w-0 truncate text-[13px] font-semibold text-black hover:underline dark:text-fg"
                                                    x-text="server.name" x-bind:title="server.name"></a>
                                            </div>
                                            <div class="flex min-w-0 items-center gap-1.5">
                                                <span class="{{ $pill }} min-w-0" x-bind:title="`Status: ${server.status}`">
                                                    <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('server.statusType') }}"></span>
                                                    <span class="truncate" x-text="server.status"></span>
                                                </span>
                                                <span x-show="server.ingress !== 'Off'" class="{{ $pill }} shrink-0"
                                                    x-bind:title="`Ingress: ${server.ingress}`">
                                                    <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('server.ingressType') }}"></span>
                                                    <span x-text="server.ingress === 'Active' ? 'Ingress' : `Ingress ${server.ingress.toLowerCase()}`"></span>
                                                </span>
                                            </div>
                                        </div>
                                        <p x-show="server.apps.length === 0"
                                            class="absolute flex items-center text-[11px] text-neutral-500 dark:text-fg-faint"
                                            x-bind:style="`left:${map.serverPadding + 4}px;top:${map.serverHeaderHeight}px;height:${map.appHeight}px`">
                                            No applications
                                        </p>
                                        <template x-for="(app, index) in server.apps" x-bind:key="app.uuid">
                                            <a x-bind:href="app.href" {{ wireNavigate() }} data-map-app
                                                class="absolute flex min-w-0 items-center gap-2 rounded-md px-2 text-[12px] text-neutral-700 transition-colors hover:bg-neutral-100 hover:text-black hover:no-underline dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                x-bind:class="isAppHighlighted(cluster.uuid, app.uuid)
                                                    ? 'bg-coollabs/10 text-black ring-1 ring-coollabs/40 dark:bg-warning/10 dark:text-fg dark:ring-warning/40'
                                                    : 'bg-neutral-50 dark:bg-white/[0.04]'"
                                                x-bind:style="`left:${map.serverPadding}px;top:${map.serverHeaderHeight + index * (map.appHeight + map.appGap)}px;width:${map.serverWidth - map.serverPadding * 2}px;height:${map.appHeight}px`"
                                                x-bind:title="`${app.name}: ${app.status}`">
                                                <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('app.statusType') }}"></span>
                                                <span class="min-w-0 flex-1 truncate" x-text="app.name"></span>
                                                <span class="shrink-0 text-[10px] text-neutral-500 dark:text-fg-faint"
                                                    x-show="app.statusType !== 'success'" x-text="app.status"></span>
                                            </a>
                                        </template>
                                    </article>
                                </template>
                            </section>
                        </template>

                        {{-- Docker servers, compact. --}}
                        <template x-if="data.dockerServers.length > 0">
                            <section data-map-docker
                                class="absolute rounded-xl border border-dashed border-neutral-300 bg-white/50 dark:border-white/[0.12] dark:bg-white/[0.02]"
                                x-bind:style="frameStyle(frame('docker'))">
                                <div class="flex h-[52px] min-w-0 items-center justify-between gap-2 px-4">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <span class="{{ $iconTile }}"><x-reicon name="servers" class="size-3.5" /></span>
                                        <span class="truncate text-[13px] font-semibold text-black dark:text-fg">Docker servers</span>
                                    </div>
                                    <a x-show="data.dockerServersTotal > data.dockerServers.length" x-bind:href="data.serversHref"
                                        {{ wireNavigate() }}
                                        class="shrink-0 text-[11px] font-medium text-neutral-500 hover:text-black hover:underline dark:text-fg-faint dark:hover:text-fg"
                                        x-text="`+${data.dockerServersTotal - data.dockerServers.length} more`"></a>
                                </div>
                                <template x-for="server in data.dockerServers" x-bind:key="server.uuid">
                                    <a x-bind:href="server.href" {{ wireNavigate() }} data-map-docker-server
                                        class="absolute flex min-w-0 items-center gap-2 rounded-lg border border-neutral-200 bg-white px-2.5 shadow-sm transition-colors hover:border-neutral-300 hover:no-underline dark:border-white/[0.1] dark:bg-neutral-900 dark:hover:border-white/[0.18]"
                                        x-bind:style="relativeStyle(layout.dockerServers[server.uuid] ?? { x: 0, y: 0, width: 0, height: 0 }, frame('docker'))"
                                        x-bind:title="`${server.name}: ${server.status}`">
                                        <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('server.statusType') }}"></span>
                                        <span class="min-w-0 flex-1 truncate text-[12px] font-medium text-black dark:text-fg" x-text="server.name"></span>
                                        <span x-show="server.statusType !== 'success'"
                                            class="shrink-0 text-[10px] text-neutral-500 dark:text-fg-faint" x-text="server.status"></span>
                                    </a>
                                </template>
                            </section>
                        </template>

                        {{-- Traffic layer: read-only lines above the cards. --}}
                        <svg class="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden="true">
                            <defs>
                                <marker id="cluster-map-arrow-firewall" markerUnits="userSpaceOnUse" markerWidth="12" markerHeight="12" refX="11" refY="6" orient="auto-start-reverse">
                                    <path d="M0,1 L12,6 L0,11 L3,6 z" class="fill-coollabs dark:fill-warning" />
                                </marker>
                                <marker id="cluster-map-arrow-ingress" markerUnits="userSpaceOnUse" markerWidth="12" markerHeight="12" refX="11" refY="6" orient="auto-start-reverse">
                                    <path d="M0,1 L12,6 L0,11 L3,6 z" class="fill-neutral-500 dark:fill-neutral-400" />
                                </marker>
                            </defs>
                        </svg>
                        <template x-for="edge in edges" x-bind:key="edge.id">
                            <svg class="pointer-events-none absolute inset-0 size-full overflow-visible">
                                <g>
                                    <path data-map-edge fill="none" stroke="transparent" stroke-width="14"
                                        class="pointer-events-auto cursor-pointer outline-none"
                                        tabindex="0" role="button"
                                        x-bind:aria-label="`${edge.directions[0].from} to ${edge.directions[0].to}`"
                                        x-bind:d="edge.path"
                                        x-on:mouseenter="hoveredEdgeId = edge.id"
                                        x-on:mouseleave="hoveredEdgeId = null"
                                        x-on:focus="hoveredEdgeId = edge.id"
                                        x-on:blur="hoveredEdgeId = null"
                                        x-on:click.stop="pinEdge(edge.id)"
                                        x-on:keydown.enter.prevent="pinEdge(edge.id)" />
                                    <path fill="none" stroke-linecap="round"
                                        x-bind:d="edge.path"
                                        x-bind:stroke-width="isEdgeActive(edge) ? 3 : 2"
                                        x-bind:stroke-dasharray="edge.kind === 'firewall' ? '8 6' : null"
                                        x-bind:class="edge.kind === 'firewall'
                                            ? 'stroke-coollabs dark:stroke-warning'
                                            : 'stroke-neutral-500 dark:stroke-neutral-400'"
                                        x-bind:opacity="activeEdge && !isEdgeActive(edge) ? 0.35 : 0.9"
                                        x-bind:marker-start="edge.bidirectional ? `url(#cluster-map-arrow-${edge.kind})` : null"
                                        x-bind:marker-end="`url(#cluster-map-arrow-${edge.kind})`" />
                                </g>
                            </svg>
                        </template>
                        {{-- Domain labels: the middle layer of each public line, same width so lines start level. --}}
                        <template x-for="edge in edges.filter((candidate) => candidate.label)" x-bind:key="`label-${edge.id}`">
                            <span data-map-edge
                                class="absolute flex cursor-pointer items-center gap-1.5 rounded-md border bg-white px-2 text-[11px] font-medium shadow-sm transition-[opacity,border-color] dark:bg-neutral-900"
                                x-bind:class="isEdgeActive(edge)
                                    ? 'border-neutral-400 text-black dark:border-white/[0.3] dark:text-fg'
                                    : (activeEdge ? 'opacity-40 ' : '') + 'border-neutral-200 text-neutral-700 dark:border-white/[0.12] dark:text-fg-dim'"
                                x-bind:style="frameStyle(edge.label)"
                                x-bind:title="edge.domains.join(', ')"
                                x-on:mouseenter="hoveredEdgeId = edge.id"
                                x-on:mouseleave="hoveredEdgeId = null"
                                x-on:click.stop="pinEdge(edge.id)">
                                <x-reicon name="globe" class="size-3 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                <span class="min-w-0 truncate" x-text="edge.label.text"></span>
                            </span>
                        </template>
                    </div>
                </div>

                {{-- Traffic details for the hovered or clicked line. --}}
                {{-- Teleported to the body so the scroll area cannot clip it. --}}
                <template x-teleport="body">
                    <div x-cloak x-show="activeEdge" data-map-popover
                        x-bind:style="popoverStyle()"
                        class="fixed z-50 w-72 max-h-[calc(100vh-24px)] overflow-y-auto rounded-xl border border-neutral-200 bg-white p-3 text-[12px] shadow-[var(--shadow-dropdown)] dark:border-white/[0.1] dark:bg-neutral-900"
                        x-bind:class="pinnedEdgeId ? '' : 'pointer-events-none'">
                        <template x-if="activeEdge">
                            <div class="flex flex-col gap-2">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-[13px] font-semibold"
                                        x-text="activeEdge.kind === 'ingress' ? 'Public traffic' : 'Allowed traffic'"></p>
                                    <button x-show="pinnedEdgeId" type="button" class="icon-button -mt-1 -mr-1"
                                        aria-label="Close traffic details" x-on:click="pinnedEdgeId = null">
                                        <x-reicon name="x" class="size-3.5" />
                                    </button>
                                </div>
                                <template x-for="direction in activeEdge.directions" x-bind:key="`${direction.from}-${direction.to}`">
                                    <div class="rounded-lg border border-neutral-200 p-2 dark:border-white/[0.08]">
                                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5">
                                            <dt class="text-neutral-500 dark:text-fg-dim">From</dt>
                                            <dd class="truncate font-medium" x-text="direction.from" x-bind:title="direction.from"></dd>
                                            <dt class="text-neutral-500 dark:text-fg-dim">To</dt>
                                            <dd class="truncate font-medium" x-text="direction.to" x-bind:title="direction.to"></dd>
                                        </dl>
                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                            <template x-for="rule in direction.rules" x-bind:key="rule.uuid">
                                                <span class="table-badge" x-text="rule.text"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="activeEdge.kind === 'ingress'">
                                    <ul class="flex flex-col gap-0.5">
                                        <template x-for="domain in activeEdge.domains" x-bind:key="domain">
                                            <li class="truncate text-neutral-600 dark:text-fg-dim" x-text="domain" x-bind:title="domain"></li>
                                        </template>
                                    </ul>
                                </template>
                                <p x-show="!pinnedEdgeId" class="text-[11px] text-neutral-500 dark:text-fg-faint">Click the line to keep these details open.</p>
                                <a x-show="pinnedEdgeId && activeEdge.kind === 'firewall'" x-bind:href="activeEdge.firewallHref"
                                    {{ wireNavigate() }} class="button w-fit">
                                    Edit rules
                                    <x-reicon name="arrow-right" class="size-3" />
                                </a>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Small screens: the same map as a stacked list. --}}
        <div class="flex flex-col gap-3 sm:hidden">
            <template x-for="cluster in data.clusters" x-bind:key="cluster.uuid">
                <section
                    class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                    <div class="flex min-w-0 items-center gap-2.5 border-b border-neutral-200 px-3 py-2.5 dark:border-white/[0.08]">
                        <span class="{{ $iconTile }}"><x-reicon name="layers" class="size-3.5" /></span>
                        <a x-bind:href="cluster.href" {{ wireNavigate() }}
                            class="min-w-0 flex-1 truncate text-[13px] font-semibold text-black dark:text-fg"
                            x-text="cluster.name"></a>
                        <span class="{{ $pill }} shrink-0">
                            <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('cluster.statusType') }}"></span>
                            <span x-text="cluster.status"></span>
                        </span>
                    </div>
                    <p x-show="cluster.servers.length === 0" class="px-3 py-3 text-[12px] text-neutral-500 dark:text-fg-dim">
                        No servers in this cluster yet.</p>
                    <template x-for="server in cluster.servers" x-bind:key="server.uuid">
                        <div class="border-b border-neutral-200 px-3 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                            <div class="flex min-w-0 items-center gap-2">
                                <x-reicon name="servers" class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                <a x-bind:href="server.href" {{ wireNavigate() }}
                                    class="min-w-0 flex-1 truncate text-[13px] font-medium text-black dark:text-fg"
                                    x-text="server.name"></a>
                                <span x-show="server.ingress !== 'Off'" class="{{ $pill }} shrink-0">
                                    <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('server.ingressType') }}"></span>
                                    Ingress
                                </span>
                                <span class="{{ $pill }} shrink-0">
                                    <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('server.statusType') }}"></span>
                                    <span x-text="server.status"></span>
                                </span>
                            </div>
                            <div x-show="server.apps.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                                <template x-for="app in server.apps" x-bind:key="app.uuid">
                                    <a x-bind:href="app.href" {{ wireNavigate() }}
                                        class="inline-flex h-7 max-w-full min-w-0 items-center gap-1.5 rounded-md bg-neutral-100 px-2 text-[12px] text-neutral-700 hover:no-underline dark:bg-white/[0.06] dark:text-fg-dim"
                                        x-bind:title="`${app.name}: ${app.status}`">
                                        <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('app.statusType') }}"></span>
                                        <span class="truncate" x-text="app.name"></span>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </template>
                </section>
            </template>
            <template x-if="data.dockerServers.length > 0">
                <section
                    class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                    <div class="flex min-w-0 items-center gap-2.5 border-b border-neutral-200 px-3 py-2.5 dark:border-white/[0.08]">
                        <span class="{{ $iconTile }}"><x-reicon name="servers" class="size-3.5" /></span>
                        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-black dark:text-fg">Docker servers</span>
                    </div>
                    <template x-for="server in data.dockerServers" x-bind:key="server.uuid">
                        <a x-bind:href="server.href" {{ wireNavigate() }}
                            class="flex min-h-11 min-w-0 items-center gap-2 border-b border-neutral-200 px-3 text-[13px] last:border-b-0 hover:no-underline dark:border-white/[0.07]">
                            <span class="size-1.5 shrink-0 rounded-full" x-bind:class="{{ $dot('server.statusType') }}"></span>
                            <span class="min-w-0 flex-1 truncate font-medium text-black dark:text-fg" x-text="server.name"></span>
                            <span class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-faint" x-text="server.status"></span>
                        </a>
                    </template>
                </section>
            </template>
        </div>
    </div>
</div>
