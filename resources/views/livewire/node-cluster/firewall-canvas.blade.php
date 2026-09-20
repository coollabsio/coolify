<div
    wire:ignore
    x-data="firewallCanvas({
        nodes: @js($firewallCanvasNodes),
        rules: @js($firewallCanvasRules),
        storageKey: @js('coolify-firewall-canvas-'.$cluster->uuid),
    })"
    class="relative overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-black/20"
>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 bg-white px-3 py-2 dark:border-white/[0.08] dark:bg-white/[0.04]">
        <div>
            <p class="text-sm font-medium">Traffic map</p>
            <p class="text-xs text-neutral-500 dark:text-fg-dim">Drag a yellow handle to an application, then add an allowed protocol and port.</p>
        </div>
        <div class="flex items-center gap-1">
            <button type="button" class="button" aria-label="Zoom out" x-on:click="zoomBy(-0.1)">−</button>
            <span class="min-w-12 text-center text-xs text-neutral-500" x-text="`${Math.round(zoom * 100)}%`"></span>
            <button type="button" class="button" aria-label="Zoom in" x-on:click="zoomBy(0.1)">+</button>
            <button type="button" class="button" x-on:click="resetView">Reset</button>
        </div>
    </div>

    <div x-ref="viewport" class="relative h-[34rem] overflow-auto overscroll-contain" x-on:scroll="updateViewportScroll" x-on:pointermove="moveDrag" x-on:pointerup="finishDrag">
        <div
            class="absolute left-0 top-0 origin-top-left"
            x-bind:style="`width:${canvasWidth}px;height:${canvasHeight}px;transform:translate(${pan.x}px,${pan.y}px) scale(${zoom})`"
        >
            <template x-for="connection in connections" x-bind:key="connection.id">
                <svg x-show="isVisibleConnection(connection)" class="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden="true">
                    <g>
                        <line
                            class="pointer-events-auto cursor-pointer stroke-transparent"
                            stroke-width="18"
                            x-bind:x1="connectionPoints(connection).x1"
                            x-bind:y1="connectionPoints(connection).y1"
                            x-bind:x2="connectionPoints(connection).x2"
                            x-bind:y2="connectionPoints(connection).y2"
                            x-on:click="selectConnection(connection.id)"
                        />
                        <line
                            x-bind:class="selectedConnectionId === connection.id ? 'stroke-yellow-300' : 'stroke-yellow-400'"
                            stroke-width="2.5"
                            stroke-dasharray="8 6"
                            x-bind:marker-start="hasReverseConnection(connection) ? 'url(#firewall-canvas-arrow)' : null"
                            marker-end="url(#firewall-canvas-arrow)"
                            x-bind:x1="connectionPoints(connection).x1"
                            x-bind:y1="connectionPoints(connection).y1"
                            x-bind:x2="connectionPoints(connection).x2"
                            x-bind:y2="connectionPoints(connection).y2"
                        />
                    </g>
                </svg>
            </template>

            <svg class="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden="true">
                <defs>
                    <marker id="firewall-canvas-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto-start-reverse">
                        <path d="M0,0 L8,4 L0,8 z" class="fill-yellow-400" />
                    </marker>
                </defs>
                <line
                    x-show="draft"
                    class="stroke-yellow-400"
                    stroke-width="2"
                    stroke-dasharray="6 6"
                    x-bind:x1="draft?.sourceX ?? 0"
                    x-bind:y1="draft?.sourceY ?? 0"
                    x-bind:x2="draft?.x ?? 0"
                    x-bind:y2="draft?.y ?? 0"
                />
            </svg>

            <template x-for="node in nodes" x-bind:key="node.id">
                <article
                    data-firewall-node
                    x-bind:data-firewall-node="node.id"
                    class="group absolute flex h-[104px] w-56 touch-none cursor-move select-none flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm dark:border-white/[0.1] dark:bg-neutral-900"
                    x-bind:style="`transform:translate3d(${position(node.id).x}px,${position(node.id).y}px,0)`"
                    x-on:pointerdown="startDrag($event, node.id)"
                >
                    <div class="flex min-w-0 items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold" x-text="node.name"></p>
                            <p class="truncate text-[11px] text-neutral-500 dark:text-fg-dim" x-text="node.subtitle"></p>
                        </div>
                        <span class="table-badge shrink-0" x-text="node.type === 'node' ? 'Node' : 'App'"></span>
                    </div>
                    <div class="mt-auto flex items-center justify-between gap-2">
                        <span class="text-[11px] text-neutral-500 dark:text-fg-dim" x-text="node.status"></span>
                        @can('update', $cluster)
                            <button
                                x-show="canStartConnection(node.id)"
                                type="button"
                                data-connector
                                class="flex size-7 cursor-crosshair items-center justify-center rounded-full border-2 border-amber-500 bg-white text-amber-600 shadow-sm transition hover:scale-110 dark:bg-neutral-900"
                                x-bind:aria-label="`Connect from ${node.name}`"
                                x-on:pointerdown="startConnection($event)"
                            >
                                <x-reicon name="plus" class="size-3" />
                            </button>
                        @endcan
                    </div>
                </article>
            </template>
        </div>

        <div
            x-ref="editor"
            x-cloak
            x-show="selectedConnection"
            x-on:click.outside="closeEditor"
            x-bind:style="editorStyle()"
            class="absolute z-20 max-h-[calc(100%-1.5rem)] w-[min(24rem,calc(100%-1.5rem))] overflow-y-auto rounded-xl border border-neutral-200 bg-white p-4 shadow-xl dark:border-white/[0.1] dark:bg-neutral-900"
        >
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold">Allowed traffic</p>
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">No connection means that traffic is denied.</p>
                </div>
                <button type="button" class="button" aria-label="Close connection editor" x-on:click="closeEditor">×</button>
            </div>

            <div class="mb-3 flex flex-col gap-3">
                <template x-for="connection in relatedConnections" x-bind:key="connection.id">
                    <div class="rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                        <p class="mb-2 text-xs font-medium" x-text="connectionLabel(connection)"></p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="rule in connection.rules" x-bind:key="rule.uuid">
                                <button
                                    type="button"
                                    class="table-badge cursor-pointer"
                                    x-bind:disabled="saving"
                                    x-on:click="removeRule(rule.uuid)"
                                    x-text="`${rule.protocol.toUpperCase()}${rule.protocol === 'icmp' ? '' : ` / ${rule.port}`} ×`"
                                ></button>
                            </template>
                            <span x-show="connection.rules.length === 0" class="text-xs text-neutral-500 dark:text-fg-dim">No allowed traffic in this direction.</span>
                        </div>
                    </div>
                </template>
            </div>

            @can('update', $cluster)
                <div class="grid grid-cols-[1fr_1fr_auto] items-end gap-2">
                    <label class="text-xs text-neutral-500 dark:text-fg-dim">Protocol
                        <select x-model="protocol" class="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-white/[0.1] dark:bg-neutral-950">
                            <option value="tcp">TCP</option>
                            <option value="udp">UDP</option>
                            <option value="icmp">ICMP</option>
                        </select>
                    </label>
                    <label class="text-xs text-neutral-500 dark:text-fg-dim">Port
                        <input x-model.number="port" x-bind:disabled="protocol === 'icmp'" type="number" min="1" max="65535" class="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-white/[0.1] dark:bg-neutral-950" />
                    </label>
                    <button type="button" class="button button-highlighted" x-bind:disabled="saving" x-on:click="addRule" x-bind:title="`Add to ${connectionLabel(selectedConnection)}`">Allow</button>
                </div>
            @endcan
        </div>

        <div x-show="nodes.length === 0" class="absolute inset-0 flex items-center justify-center">
            <x-empty size="sm" title="No applications" description="Deploy an application to add it to the traffic map." icon-name="layers" />
        </div>
        <div x-show="nodes.length > 0 && !hasWorkloadTargets" class="pointer-events-none absolute inset-0 flex items-center justify-center">
            <div class="rounded-xl border border-neutral-200 bg-white/95 px-5 py-4 text-center shadow-lg dark:border-white/[0.1] dark:bg-neutral-900/95">
                <p class="text-sm font-medium">No applications in this cluster</p>
                <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">Deploy an application before creating traffic rules.</p>
            </div>
        </div>
    </div>
</div>
