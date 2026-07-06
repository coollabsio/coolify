<?php

use App\Events\V5ClusterUpdated;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('keeps long v5 application metadata inside the canvas card', function () {
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));
    $caddyIngressCardSource = file_get_contents(resource_path('js/v5/components/canvas/caddy-ingress-card.tsx'));

    expect($caddyIngressCardSource)
        ->toContain('overflow-hidden')
        ->and($applicationCardSource)->toContain('grid grid-cols-[auto_minmax(0,1fr)]')
        ->and($applicationCardSource)->toContain('truncate text-right font-medium')
        ->and($applicationCardSource)->toContain('truncate text-right font-mono');
});

it('does not render v5 application status messages on dashboard cards', function () {
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));

    expect($applicationCardSource)
        ->not->toContain('{application.statusMessage && (')
        ->not->toContain('{application.statusMessage}</p>');
});

it('lets users dismiss v5 dashboard notices', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $noticeSource = file_get_contents(resource_path('js/v5/components/canvas/canvas-notice.tsx'));

    expect($noticeSource)
        ->toContain('aria-label="Dismiss notice"')
        ->toContain('onClick={onDismiss}')
        ->toContain('<span className="sr-only">Dismiss notice</span>')
        ->toContain('bg-card')
        ->not->toContain('bg-destructive/10')
        ->not->toContain('bg-emerald-500/10')
        ->not->toContain('bg-blue-500/10');

    expect($dashboardSource)->toContain('onDismiss={dismissNotice}');
});

it('uses the shared dialog and button components for the application ingress modal', function () {
    $ingressDialogSource = file_get_contents(resource_path('js/v5/components/canvas/ingress-dialog.tsx'));

    expect($ingressDialogSource)
        ->toContain('<Dialog')
        ->toContain('open')
        ->toContain('<DialogContent')
        ->toContain('showCloseButton')
        ->toContain('<Button type="submit" variant="coolify"')
        ->not->toContain('>Cancel</button>')
        ->not->toContain('>Close</button>');
});

it('shows a loading state while toggling application ingress', function () {
    $ingressHookSource = file_get_contents(resource_path('js/v5/lib/use-application-ingress.ts'));
    $ingressButtonSource = file_get_contents(resource_path('js/v5/components/canvas/application-ingress-button.tsx'));
    $ingressDialogSource = file_get_contents(resource_path('js/v5/components/canvas/ingress-dialog.tsx'));

    expect($ingressHookSource)
        ->toContain('const savingIngressApplications = usePendingIds<string>()')
        ->toContain('savingIngressApplications.start(application.id)')
        ->toContain('savingIngressApplications.finish(application.id)');

    expect($ingressButtonSource)->toContain("isSaving ? 'Saving...'");

    expect($ingressDialogSource)->toContain("isSaving ? 'Saving...' : 'Enable ingress'");
});

it('shows a dashboard refresh button next to the center button', function () {
    $toolbarSource = file_get_contents(resource_path('js/v5/components/canvas/canvas-toolbar.tsx'));
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($toolbarSource)
        ->toContain('onClick={onCenter}')
        ->toContain('onClick={onRefresh}')
        ->toContain("isRefreshing ? 'Refreshing…' : 'Refresh state'");

    expect($dashboardSource)
        ->toContain('centerOnCanvasNodes(applications, ingresses)')
        ->toContain('void refreshApplications()');
});

it('subscribes the v5 dashboard canvas to automatic resource status updates', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $channelHookSource = file_get_contents(resource_path('js/v5/lib/use-canvas-channel.ts'));
    $teamChannelSource = file_get_contents(resource_path('js/v5/lib/use-team-channel.ts'));

    $mergeHookSource = file_get_contents(resource_path('js/v5/lib/use-canvas-resource-merge.ts'));

    expect($dashboardSource)
        ->toContain('currentTeam = null')
        ->toContain('useCanvasResourceMerge({')
        ->toContain('teamId: currentTeam?.id ?? null')
        ->not->toContain("fetch('/v5/canvas-state'");

    expect($mergeHookSource)
        ->toContain('setApplications((currentApplications) =>')
        ->toContain('setIngresses((currentIngresses) =>')
        ->toContain('useCanvasResourceChannel(teamId, handleCanvasResourceEvent)');

    expect($channelHookSource)
        ->toContain("useTeamChannel(teamId, '.v5.canvas.resource.updated'");

    expect($teamChannelSource)
        ->toContain('channel.listen(eventName')
        ->toContain('Waiting for window.Echo before subscribing to ${eventName} updates');
});

it('allows zooming the v5 dashboard canvas with buttons and pinch gestures', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $viewportHookSource = file_get_contents(resource_path('js/v5/lib/use-canvas-viewport.ts'));
    $toolbarSource = file_get_contents(resource_path('js/v5/components/canvas/canvas-toolbar.tsx'));

    expect($viewportHookSource)
        ->toContain('zoom: number;')
        ->toContain('MIN_CANVAS_ZOOM')
        ->toContain('MAX_CANVAS_ZOOM')
        ->toContain('PINCH_CANVAS_ZOOM_STEP')
        ->toContain('zoomCanvas(')
        ->toContain('zoomCanvas(event.deltaY < 0 ? 1 : -1, PINCH_CANVAS_ZOOM_STEP')
        ->toContain('event.ctrlKey');

    expect($toolbarSource)
        ->toContain('aria-label="Zoom out"')
        ->toContain('aria-label="Zoom in"')
        ->toContain('Math.round(zoom * 100)');

    expect($dashboardSource)
        ->toContain('onWheel={handleCanvasWheel}')
        ->toContain('scale(${viewport.zoom})');
});

it('renders draggable connector dots on v5 application canvas cards', function () {
    $geometrySource = file_get_contents(resource_path('js/v5/lib/canvas-geometry.ts'));
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));
    $connectionLinesSource = file_get_contents(resource_path('js/v5/components/canvas/connection-lines.tsx'));
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($geometrySource)->toContain("export type ConnectorSide = 'top' | 'right' | 'bottom' | 'left';");

    expect($applicationCardSource)
        ->toContain('application-connector')
        ->toContain('data-connector-side={side}')
        ->toContain('onConnectorPointerDown(event, application.id, side)');

    expect($connectionLinesSource)->toContain('<svg className="pointer-events-none absolute inset-0 overflow-visible">');

    expect($dashboardSource)
        ->toContain('onConnectorPointerDown={startConnectionDrag}')
        ->toContain('draftConnection');
});

it('keeps v5 canvas connections selectable unique and shortest-path only', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $connectionsHookSource = file_get_contents(resource_path('js/v5/lib/use-canvas-connections.ts'));
    $geometrySource = file_get_contents(resource_path('js/v5/lib/canvas-geometry.ts'));
    $connectionLinesSource = file_get_contents(resource_path('js/v5/components/canvas/connection-lines.tsx'));
    $portsEditorSource = file_get_contents(resource_path('js/v5/components/canvas/connection-ports-editor.tsx'));
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));

    expect($dashboardSource)
        ->toContain('resourceConnections: initialResourceConnections = []')
        ->toContain('selectedConnectionId')
        ->toContain('clearCanvasSelection')
        ->toContain('event.target !== event.currentTarget')
        ->toContain('connectionExists')
        ->toContain("closest<HTMLElement>('[data-application-card]')")
        ->toContain('persistNewConnection(pointerState.from.applicationId, targetApplicationId)');

    expect($connectionsHookSource)
        ->toContain('type CanvasConnection = V5ResourceConnection;')
        ->toContain('useState<CanvasConnection[]>(initialConnections)')
        ->toContain("!['Backspace', 'Delete'].includes(event.key)")
        ->toContain('rollback: () =>')
        ->toContain('persistConnectionPorts(updatedConnection, connection)')
        ->toContain('/v5/resource-connections')
        ->toContain('ports_by_direction: portsByDirection')
        ->toContain('connectionDirectionKey(')
        ->toContain('pruneConnectionPortsByDirection(')
        ->toContain('Number.isInteger(portNumber)')
        ->toContain('setConnectionPortInput');

    expect($geometrySource)->toContain('shortestConnectionPoints');

    expect($connectionLinesSource)
        ->toContain('onClick={(event) => onSelect(event, connection.id)}')
        ->toContain("isSelected ? 'stroke-destructive' : 'stroke-warning'")
        ->toContain('aria-label="Select connection"')
        ->toContain('stroke="transparent"')
        ->toContain('strokeWidth={12}')
        ->toContain('id="dashboard-connection-arrow"')
        ->toContain('markerEnd={isSelected ? \'url(#dashboard-connection-arrow)\' : undefined}')
        ->toContain('markerWidth="16"')
        ->toContain('markerHeight="16"')
        ->toContain('strokeDasharray="6 6"');

    expect($portsEditorSource)
        ->toContain('activeConnectionPorts(connection)')
        ->toContain('onAddPort(connection.id)')
        ->toContain('Allowed ports')
        ->toContain('onUpdateDirection(')
        ->toContain('applicationDirectionLabel(')
        ->toContain('application.id.slice(0, 8)')
        ->toContain('connection.applicationIds[0]')
        ->toContain('connection.applicationIds[1]')
        ->toContain('left: (points.from.x + points.to.x) / 2')
        ->toContain('top: (points.from.y + points.to.y) / 2')
        ->toContain('onDelete(connection.id)')
        ->toContain('Delete connection');

    expect($applicationCardSource)
        ->toContain('data-application-card="application-card"')
        ->toContain('group/application')
        ->toContain('opacity-0')
        ->toContain('group-hover/application:opacity-100');
});

it('shows v5 application connector dots after selecting a canvas card', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));

    expect($dashboardSource)
        ->toContain('selectedApplicationId')
        ->toContain('setSelectedApplicationId(application.id)')
        ->toContain('selectedApplicationId === application.id');

    expect($applicationCardSource)->toContain('opacity-100');
});

it('keeps side connector geometry aligned with the rendered v5 application card height', function () {
    $geometrySource = file_get_contents(resource_path('js/v5/lib/canvas-geometry.ts'));
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));

    expect($geometrySource)->toContain('const APPLICATION_CARD_HEIGHT = 160;');

    expect($applicationCardSource)->toContain('h-40 w-80');
});

it('shows a loading state on v5 application delete buttons', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));

    foreach ([
        'const deletingApplications = usePendingIds<string>()',
        'deletingApplications.start(application.id)',
        'deletingApplications.finish(application.id)',
        'isDeleting={deletingApplications.pendingIds.has(application.id)}',
    ] as $expectedSource) {
        $this->assertTrue(str_contains($dashboardSource, $expectedSource), "Missing source: {$expectedSource}");
    }

    foreach ([
        'disabled={isDeleting}',
        "{isDeleting ? 'Deleting…' : 'Delete'}",
    ] as $expectedSource) {
        $this->assertTrue(str_contains($applicationCardSource, $expectedSource), "Missing source: {$expectedSource}");
    }
});

it('uses a larger mobile touch target for v5 application connector dots', function () {
    $applicationCardSource = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));

    expect($applicationCardSource)
        ->toContain('size-8')
        ->toContain('md:size-3')
        ->toContain('group/connector')
        ->toContain('group-hover/connector:scale-125')
        ->toContain('<span className="size-3 rounded-full border-2 border-card bg-warning shadow ring-2 ring-background transition group-hover/connector:scale-125 group-hover/connector:bg-warning/90" />');
});

it('detects the connection drop target from pointer coordinates for mobile drags', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardSource)
        ->toContain('connectionTargetFromPointer(event)')
        ->toContain('document.elementFromPoint(event.clientX, event.clientY)')
        ->toContain('pointer-captured mobile drags')
        ->toContain('targetApplicationId !== pointerState.from.applicationId');
});

it('renders coold diagnostics actions in the v5 server menu', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)
        ->toContain('Coold logs')
        ->toContain('Corrosion tables')
        ->toContain('Restart coold')
        ->toContain('Restarting coold...')
        ->toContain('Firewall rules')
        ->toContain('/restart-coold')
        ->toContain('/coold-logs?tail=200')
        ->toContain('/corrosion-tables?limit=200')
        ->toContain('/firewall-rules')
        ->toContain("source: 'flux' | 'ssh';")
        ->toContain('setCooldLogsSource(payload?.source ?? null)')
        ->toContain('setCorrosionTablesSource(payload?.source ?? null)')
        ->toContain('setFirewallRulesSource(payload?.source ?? null)')
        ->toContain('Source: {diagnosticsSourceLabel(cooldLogsSource)}')
        ->toContain('Source: {diagnosticsSourceLabel(corrosionTablesSource)}')
        ->toContain('Source: ${diagnosticsSourceLabel(firewallRulesSource)}')
        ->toContain('Latest journalctl entries')
        ->toContain('Corrosion table snapshots')
        ->toContain('Defined coold allow rules')
        ->toContain('overflow-y-auto overflow-x-hidden')
        ->toContain('whitespace-pre-wrap wrap-anywhere');
});

it('renders v5 server status on cluster server cards', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)
        ->toContain('server.status')
        ->toContain('server.lastStatusOutput')
        ->toContain('statusLabel(server.status)')
        ->toContain('statusBadgeClass(server.status)');
});

it('broadcasts v5 cluster updates from the queue with an explicit echo event name', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));
    $teamChannelSource = file_get_contents(resource_path('js/v5/lib/use-team-channel.ts'));
    $event = new V5ClusterUpdated(1, 1);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('v5.cluster.updated')
        ->and($clustersPage)->toContain("useTeamChannel(currentTeam?.id ?? null, '.v5.cluster.updated'")
        ->and($teamChannelSource)->toContain('Waiting for window.Echo before subscribing to ${eventName} updates');
});

it('serves a v5 realtime test page for manual websocket diagnostics', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $page = file_get_contents(resource_path('js/v5/Pages/RealtimeTest.tsx'));

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5/realtime-test')
        ->assertSuccessful()
        ->assertSee('RealtimeTest', false)
        ->assertSee((string) $team->id, false);

    expect($page)
        ->toContain('useTeamChannel(')
        ->toContain("'.v5.realtime.test',")
        ->toContain('{ onDebug: addLog, onError: addLog }')
        ->toContain('Broadcast event');

    expect(file_get_contents(resource_path('js/v5/lib/use-team-channel.ts')))
        ->toContain('Subscribing to private-');
});

it('defines the v5 dashboard page as a shadcn styled canvas shell', function () {
    $dashboardPage = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $canvasApi = file_get_contents(resource_path('js/v5/lib/canvas-api.ts'));
    $toolbar = file_get_contents(resource_path('js/v5/components/canvas/canvas-toolbar.tsx'));
    $applicationCard = file_get_contents(resource_path('js/v5/components/canvas/application-card.tsx'));
    $caddyIngressCard = file_get_contents(resource_path('js/v5/components/canvas/caddy-ingress-card.tsx'));
    $inspectorSheet = file_get_contents(resource_path('js/v5/components/canvas/application-inspector-sheet.tsx'));
    $ingressDialog = file_get_contents(resource_path('js/v5/components/canvas/ingress-dialog.tsx'));
    $app = file_get_contents(resource_path('js/v5/app.tsx'));
    $navbarPath = resource_path('js/v5/components/app-navbar.tsx');

    expect(file_exists($navbarPath))->toBeTrue();

    $navbar = file_get_contents($navbarPath);
    $sheetPath = resource_path('js/v5/components/ui/sheet.tsx');

    expect(file_exists($sheetPath))->toBeTrue();

    expect($app)
        ->toContain('progress: {')
        ->toContain('delay: 10')
        ->toContain("color: '#fcd452'")
        ->toContain('showSpinner: false')
        ->not->toContain('TopNavigationLoadingIndicator')
        ->not->toContain('withApp:');

    expect($dashboardPage)
        ->toContain('Dashboard')
        ->toContain("import { AppNavbar } from '@/components/app-navbar';")
        ->not->toContain('function csrfToken()')
        ->toContain("import { canvasRequest } from '@/lib/canvas-api';")
        ->toContain("import { ApplicationInspectorSheet } from '@/components/canvas/application-inspector-sheet';")
        ->toContain("import { IngressDialog } from '@/components/canvas/ingress-dialog';")
        ->not->toContain("fetch('/v5/clusters'")
        ->toContain('<AppNavbar')
        ->toContain('bg-background text-foreground')
        ->toContain('h-dvh overflow-hidden bg-background text-foreground')
        ->toContain('relative h-full min-h-0 overflow-hidden pt-16')
        ->not->toContain('Add nginx')
        ->toContain('selectedNginxServerId')
        ->toContain('nginxImage')
        ->toContain('docker.io/library/nginx:alpine')
        ->toContain('server_uuid: selectedNginxServerId || null')
        ->toContain('image: nginxImage.trim() || DEFAULT_NGINX_IMAGE')
        ->toContain('openApplicationInspector')
        ->toContain('selectedInspectorApplication')
        ->toContain('application={selectedInspectorApplication}')
        ->toContain("method: 'DELETE'")
        ->toContain('removeApplication')
        ->toContain('useEffect(() => {')
        ->toContain('setApplications(settledResources.applications);')
        ->toContain('centerOnCanvasNodes(settledResources.applications, settledResources.ingresses);')
        ->toContain('persistCaddyIngressPosition')
        ->toContain('startIngressDrag')
        ->toContain('canvasRequest(`/v5/caddy-ingresses/${ingress.id}/position`')
        ->toContain("canvasRequest('/v5/applications/nginx'")
        ->toContain('nginxServers = []')
        ->toContain('canvasRequest(`/v5/applications/${application.id}/position`')
        ->not->toContain('<header')
        ->not->toContain('h-[calc(100dvh-4rem)]')
        ->not->toContain('flex h-dvh flex-col overflow-hidden bg-background text-foreground')
        ->toContain('No applications on this canvas yet.')
        ->not->toContain("import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';")
        ->not->toContain("fetch('/v5/selection'");

    expect($canvasApi)
        ->not->toContain('function csrfToken()')
        ->toContain("import { csrfToken } from '@/lib/csrf';");

    expect($toolbar)
        ->toContain('Deploy')
        ->toContain('Select nginx server')
        ->toContain('Center');

    expect($applicationCard)
        ->toContain('Delete')
        ->toContain('onDoubleClick={(event) => onOpenInspector(event, application)}');

    expect($caddyIngressCard)->toContain('Caddy ingress');

    expect($inspectorSheet)
        ->toContain("import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';")
        ->toContain("import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';")
        ->toContain('App configuration')
        ->toContain('open={application !== null}')
        ->toContain('<SheetContent side="right" className="w-full overflow-hidden bg-background sm:rounded-l-xl sm:border data-[side=right]:sm:!inset-y-4 data-[side=right]:sm:!h-auto data-[side=right]:sm:!w-[45vw] data-[side=right]:sm:!max-w-[45vw]"')
        ->toContain('showCloseButton blurOverlay={false}')
        ->toContain('<SheetHeader className="p-6 pb-4">')
        ->toContain('<div className="flex flex-1 flex-col gap-6 px-6 pb-6">')
        ->toContain('<Tabs defaultValue="overview"')
        ->toContain('<TabsList className="w-full justify-start" variant="line">')
        ->toContain('<TabsTrigger value="overview">Overview</TabsTrigger>')
        ->toContain('<TabsTrigger value="networking">Networking</TabsTrigger>')
        ->toContain('<TabsTrigger value="advanced">Advanced</TabsTrigger>')
        ->toContain('Double-click an application card to open configuration.');

    expect($ingressDialog)->toContain("import { Button } from '@/components/ui/button';");

    expect(file_get_contents($sheetPath))
        ->toContain('blurOverlay = true')
        ->toContain('<SheetOverlay blur={blurOverlay} />');

    expect($navbar)
        ->toContain("import { Link, router, usePage } from '@inertiajs/react';")
        ->toContain("import { cn } from '@/lib/utils';")
        ->toContain("import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';")
        ->toContain("import { csrfToken } from '@/lib/csrf';")
        ->not->toContain('function csrfToken()')
        ->toContain('export function AppNavbar')
        ->toContain('/coolify-logo.svg')
        ->toContain('<Link')
        ->toContain('className="fixed inset-x-0 top-0 z-40 border-b border-border bg-background"')
        ->not->toContain('className="sticky top-0 z-40 shrink-0 border-b border-border bg-background"')
        ->toContain('hover:bg-muted')
        ->toContain('text-muted-foreground')
        ->toContain('SelectGroup')
        ->toContain('variant="ghost"')
        ->toContain('position="popper"')
        ->toContain('sideOffset={4}')
        ->toContain('Select a project')
        ->toContain('Select an environment')
        ->toContain('const { url } = usePage();')
        ->toContain("const isClustersPage = url.startsWith('/v5/clusters');")
        ->toContain('href="/v5"')
        ->toContain('href="/v5/clusters"')
        ->toContain('Clusters')
        ->toContain('className="relative flex h-16 items-center gap-3 px-4 sm:px-6"')
        ->toContain('className="absolute left-1/2 flex min-w-0 -translate-x-1/2 items-center justify-center gap-1 md:static md:flex-1 md:translate-x-0 md:justify-start md:gap-2"')
        ->toContain('className="max-w-[38vw] md:max-w-[10rem]"')
        ->toContain('className="max-w-[30vw] md:max-w-[10rem]"')
        ->toContain('aria-label="Open mobile menu"')
        ->toContain('<Sheet>')
        ->toContain('<SheetTrigger')
        ->toContain('<SheetContent side="right" className="w-72 max-w-[85vw] bg-background"')
        ->toContain('<SheetHeader>')
        ->toContain('<SheetTitle>Coolify</SheetTitle>')
        ->toContain('<SheetDescription className="sr-only">')
        ->toContain('<SheetClose')
        ->toContain('Move between Coolify v5 pages.')
        ->not->toContain('<SheetTitle className="sr-only">Navigation</SheetTitle>')
        ->toContain('className="hidden rounded-md px-3 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground md:inline-flex"')
        ->toContain('className="inline-flex rounded-md p-2 text-warning transition-colors hover:bg-muted hover:text-warning md:hidden"')
        ->toContain('Dashboard')
        ->not->toContain('Home')
        ->toContain("fetch('/v5/selection'")
        ->toContain('router.reload({')
        ->toContain("'applications',")
        ->toContain("'caddyIngresses',")
        ->toContain("'resourceConnections',")
        ->toContain("'nginxServers',")
        ->toContain("'selectedProjectUuid',")
        ->toContain("'selectedEnvironmentUuid',")
        ->toContain('void persistSelection(nextProjectUuid, nextEnvironmentUuid).then((persisted) => {')
        ->toContain('void persistSelection(projectUuid, nextEnvironmentUuid).then((persisted) => {')
        ->toContain('refreshCurrentPageSelection();')
        ->toContain("'X-CSRF-TOKEN': csrfToken()")
        ->not->toContain('isMobileMenuOpen')
        ->not->toContain('setIsMobileMenuOpen')
        ->not->toContain('isProjectEnvironmentMenuOpen')
        ->not->toContain('setIsProjectEnvironmentMenuOpen')
        ->not->toContain('Open project and environment selector')
        ->not->toContain('Close project and environment selector')
        ->not->toContain('DropdownMenu')
        ->not->toContain('fixed inset-0 bg-black/80')
        ->not->toContain('fixed inset-y-0 right-0')
        ->not->toContain('<a')
        ->not->toContain("import { Button } from '@/components/ui/button';")
        ->not->toContain('<Button')
        ->not->toContain("import { Separator } from '@/components/ui/separator';")
        ->not->toContain('<Separator')
        ->not->toContain('min-h-[calc(100vh-4rem)]')
        ->not->toContain('py-10')
        ->not->toContain('bg-background/95')
        ->not->toContain('backdrop-blur')
        ->not->toContain('supports-[backdrop-filter]')
        ->not->toContain('border-coolgray')
        ->not->toContain('className="w-[12rem]"')
        ->not->toContain('<h1>Coolify v5</h1>')
        ->not->toContain('<h2 id="clusters-heading">Clusters</h2>');

    expect(file_exists(resource_path('js/v5/components/top-navigation-loading-indicator.tsx')))->toBeFalse();
});

it('tracks v5 server actions with reusable per-id loading state', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));
    $pendingIdsHook = file_get_contents(resource_path('js/v5/lib/use-pending-ids.ts'));

    expect($clustersPage)
        ->toContain("import { usePendingIds } from '@/lib/use-pending-ids';")
        ->toContain('const checkingServers = usePendingIds<string>()')
        ->toContain('const isCheckingServer = checkingServers.has(server.id)')
        ->toContain('checkingServers.start(server.id)')
        ->toContain('checkingServers.finish(server.id)')
        ->not->toContain('const [checkingServerId, setCheckingServerId] = useState<string | null>(null)')
        ->not->toContain('setCheckingServerId(server.id)')
        ->not->toContain('setCheckingServerId(null)');

    expect($pendingIdsHook)
        ->toContain('export function usePendingIds')
        ->toContain('const [pendingIds, setPendingIds] = useState<Set<T>>(() => new Set())')
        ->toContain('start')
        ->toContain('finish')
        ->toContain('has')
        ->toContain('hasAny');
});

it('defines the v5 cluster management page and create cluster form', function () {
    $clustersPagePath = resource_path('js/v5/Pages/Clusters.tsx');
    $clustersPage = file_get_contents($clustersPagePath);
    $buttonComponent = file_get_contents(resource_path('js/v5/components/ui/button.tsx'));
    $dialogComponent = file_get_contents(resource_path('js/v5/components/ui/dialog.tsx'));
    $types = file_get_contents(resource_path('js/v5/types.ts'));

    expect(file_exists($clustersPagePath))->toBeTrue();

    preg_match('/<Button\s+type="button"\s+variant="coolify"\s+aria-label="Add server to cluster"/m', $clustersPage, $addServerButtonMatches);

    expect($addServerButtonMatches)->not->toBeEmpty();

    expect($clustersPage)
        ->toContain("import { Button } from '@/components/ui/button';")
        ->toContain("} from '@/components/ui/dialog';")
        ->toContain("} from '@/components/ui/dropdown-menu';")
        ->toContain("import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';")
        ->toContain("import { apiRequest } from '@/lib/api';")
        ->toContain("apiRequest('/v5/clusters'")
        ->toContain('aria-label="Select a cluster"')
        ->toContain('Cluster state')
        ->not->toContain('Last run')
        ->not->toContain('selectedCluster.lastCliStatus')
        ->not->toContain('selectedCluster.lastCliSummary')
        ->toContain('Generated mesh values are saved after bootstrap or extend runs.')
        ->not->toContain('CLI state')
        ->not->toContain('CLI-generated mesh values')
        ->toContain('Select a cluster')
        ->toContain('setSelectedClusterId(value)')
        ->toContain('aria-label="Create cluster"')
        ->toContain('setIsCreateDialogOpen(true)')
        ->toContain('Add cluster')
        ->toContain('variant="coolify"')
        ->not->toContain('border-warning bg-warning/10 text-foreground')
        ->not->toContain('border-primary bg-primary/10 text-foreground')
        ->toContain('<Dialog')
        ->toContain('<DialogTitle>Create cluster</DialogTitle>')
        ->toContain('<DialogDescription>')
        ->toContain('<DialogFooter>')
        ->not->toContain('<DialogClose')
        ->toContain('Cancel')
        ->toContain('<DialogContent className="max-w-md" showCloseButton={false}>')
        ->toContain('<DialogTitle>Confirm deletion</DialogTitle>')
        ->toContain('variant="delete"')
        ->toContain('aria-label="Add server to cluster"')
        ->toContain('setIsAddServerDialogOpen(true)')
        ->toContain('Add server')
        ->toContain('className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"')
        ->toContain('<DialogTitle>Add server</DialogTitle>')
        ->toContain('DialogContent className="max-w-3xl"')
        ->toContain('DialogContent className="max-w-2xl"')
        ->not->toContain('DialogContent className="max-h-[90dvh] max-w-3xl overflow-y-auto"')
        ->not->toContain('DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto"')
        ->toContain('apiRequest(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/bootstrap`')
        ->toContain('apiRequest(`/v5/clusters/${selectedCluster.id}`')
        ->toContain('hasBootstrapInProgress')
        ->toContain('Bootstrap')
        ->toContain('lastBootstrapOutput')
        ->toContain('Unable to queue bootstrap for this server.')
        ->toContain('border-destructive/30')
        ->toContain('apiRequest(`/v5/clusters/${selectedCluster.id}/servers`')
        ->toContain('apiRequest(`/v5/clusters/${selectedCluster.id}/servers/${editingServer.id}`')
        ->toContain('apiRequest(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/check`')
        ->toContain('apiRequest(`/v5/clusters/${cluster.id}/servers/${server.id}`')
        ->toContain("method: 'PATCH'")
        ->toContain("method: 'DELETE'")
        ->toContain('<DropdownMenu>')
        ->toContain('<DropdownMenuTrigger')
        ->toContain("import { DotsThreeIcon } from '@phosphor-icons/react';")
        ->toContain('variant="ghost"')
        ->toContain('size="icon-sm"')
        ->toContain('aria-label="Server actions"')
        ->toContain('<DotsThreeIcon data-icon="inline-start" weight="bold" />')
        ->not->toContain('>Server actions</DropdownMenuTrigger>')
        ->toContain('<DropdownMenuGroup>')
        ->toContain('<DropdownMenuSeparator />')
        ->toContain('Check connection')
        ->not->toContain('Check SSH')
        ->toContain('Not initialized')
        ->toContain('role="group"')
        ->toContain('aria-label="Server initialization"')
        ->toContain('rounded-l-md border border-r-0 border-destructive/30 bg-destructive/10')
        ->toContain('variant="coolify"')
        ->toContain('className="rounded-r-md"')
        ->toContain('onClick={() => void bootstrapServer(server)}')
        ->not->toContain('<DropdownMenuItem
                                                                                    disabled={isBootstrappingServer}')
        ->not->toContain('Bootstrap: {serverStatusLabel(server.status)}')
        ->not->toContain('Last bootstrap')
        ->not->toContain('{formatDate(server.lastBootstrappedAt)}')
        ->toContain("import { CanvasNotice } from '@/components/canvas/canvas-notice';")
        ->toContain('const [serverConnectionNotice, setServerConnectionNotice] = useState<ServerConnectionNotice | null>(null)')
        ->toContain('setServerConnectionNotice({')
        ->toContain('<CanvasNotice')
        ->toContain('variant={serverConnectionNotice.variant}')
        ->toContain('onDismiss={() => setServerConnectionNotice(null)}')
        ->not->toContain('aria-label="Dismiss server connection notification"')
        ->not->toContain('Latest SSH check: {latestSshCheck.status}')
        ->toContain('const notInitializedServers = selectedCluster?.servers.filter((server) => server.lastBootstrappedAt === null) ?? []')
        ->toContain('const initializedServers = selectedCluster?.servers.filter((server) => server.lastBootstrappedAt !== null) ?? []')
        ->toContain('Not initialized servers')
        ->toContain('{notInitializedServers.map(renderServerCard)}')
        ->toContain('{initializedServers.map(renderServerCard)}')
        ->toContain('{!isServerInitialized ? (')
        ->toContain('const [isBootstrapLogsDialogOpen, setIsBootstrapLogsDialogOpen] = useState(false)')
        ->toContain('const [bootstrapLogsServerId, setBootstrapLogsServerId] = useState<string | null>(null)')
        ->toContain('openBootstrapLogs(server)')
        ->toContain('View install logs')
        ->toContain('<DialogTitle>Install logs</DialogTitle>')
        ->toContain('const parsedBootstrapLogs = parseBootstrapOutput(bootstrapLogsServer?.lastBootstrapOutput ?? null)')
        ->toContain('parsedBootstrapLogs.summary.length > 0')
        ->toContain('parsedBootstrapLogs.visibleOutput')
        ->toContain('visibleOutput: hideRawBootstrapPlan(remainingOutput)')
        ->toContain("line.trim() === 'Plan:'")
        ->toContain('Action results')
        ->toContain('max-h-[70dvh]')
        ->toContain('{canShowBootstrapLogs ? (')
        ->not->toContain('Show install logs')
        ->not->toContain('{!isServerInitialized && isBootstrapLogVisible ? (')
        ->not->toContain('setVisibleBootstrapLogs')
        ->toContain('Delete server')
        ->toContain('This removes it from this cluster inventory so you can add it again later.')
        ->not->toContain('<Button\n                                                                    type="button"\n                                                                    variant="outline"\n                                                                    size="sm"\n                                                                    disabled={isCheckingServer}')
        ->toContain('<DialogTitle>Edit server</DialogTitle>')
        ->toContain('Edit server')
        ->toContain('Save server')
        ->toContain('editServerBuilderCapacity')
        ->toContain('editServerBuilderCpuQuota')
        ->toContain('Networking and bootstrap settings stay locked after creation.')
        ->toContain('Bootstrap SSH user')
        ->toContain('Bootstrap SSH port')
        ->not->toContain('{server.sshUser}@{server.host}:{server.sshPort}')
        ->toContain('showAdvancedServerConfiguration')
        ->toContain('Node address override')
        ->toContain('Defaults to server IP')
        ->not->toContain('CLI node address')
        ->toContain('wireguardListenPortOverride')
        ->toContain('wireguardEndpointOverride')
        ->toContain('privateKeys')
        ->toContain('selectedPrivateKeyId')
        ->toContain('Private key')
        ->toContain('Select a private key')
        ->toContain('appearance-none')
        ->toContain('backgroundImage: `url("data:image/svg+xml')
        ->not->toContain('No private key')
        ->toContain('Create cluster')
        ->toContain('Cluster details')
        ->toContain('Servers in this cluster')
        ->toContain('selectedCluster')
        ->toContain('Server IP')
        ->toContain('{server.host}')
        ->not->toContain('<dt className="text-muted-foreground">CLI node</dt>')
        ->not->toContain('CLI node')
        ->not->toContain('{server.nodeAddress ?? server.host}')
        ->toContain('builderCapacity')
        ->toContain('{server.builderEnabled ? (')
        ->toContain('<dt className="text-muted-foreground">Builder CPU quota</dt>')
        ->toContain('server.builderCpuQuota')
        ->toContain(') : null}')
        ->toContain('privateKeyName')
        ->toContain('lastBootstrappedAt')
        ->toContain('This removes it from this cluster inventory so you can add it again later.')
        ->toContain('deleteCluster')
        ->toContain('apiRequest(`/v5/clusters/${cluster.id}`')
        ->toContain("method: 'DELETE'")
        ->toContain('Delete cluster')
        ->toContain('selectedCluster.serversCount === 0')
        ->not->toContain("selectedCluster.serversCount === 1 ? 'server' : 'servers'")
        ->toContain('Only empty clusters can be deleted.')
        ->toContain('min-h-dvh overflow-y-auto bg-background text-foreground lg:h-dvh lg:overflow-hidden')
        ->toContain('flex min-h-dvh overflow-visible px-4 pt-16 lg:h-full lg:min-h-0 lg:overflow-hidden lg:px-6')
        ->toContain('flex w-full flex-col gap-4 py-4 lg:min-h-0 lg:py-6')
        ->toContain('rounded-lg border border-border bg-card p-4')
        ->toContain('flex items-start justify-between gap-3')
        ->toContain('min-w-0 flex-1')
        ->toContain('flex shrink-0 items-center justify-end gap-2 sm:flex-wrap')
        ->toContain('aria-label="Select a cluster"')
        ->toContain('setSelectedClusterId(value)')
        ->not->toContain('flex max-h-80 flex-col rounded-lg border border-border bg-card lg:max-h-none lg:min-h-0')
        ->toContain('overflow-visible rounded-lg border border-border bg-card lg:min-h-0 lg:overflow-y-auto')
        ->not->toContain('flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end')
        ->toContain('mt-4 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2')
        ->not->toContain('lg:grid-cols-[20rem_minmax(0,1fr)]')
        ->not->toContain('lg:grid-cols-[20rem_minmax(0,1fr)_22rem]')
        ->not->toContain('New cluster')
        ->not->toContain('<aside className="rounded-lg border border-border bg-card p-5">')
        ->not->toContain('This is where the magic happens.');

    expect(substr_count($clustersPage, 'variant="coolify"'))->toBe(6)
        ->and($buttonComponent)
        ->toContain('coolify:')
        ->toContain('bg-coollabs-50')
        ->toContain('hover:bg-coollabs')
        ->toContain('delete:');

    expect($dialogComponent)
        ->toContain('@base-ui/react/dialog')
        ->toContain('z-50')
        ->toContain('max-h-[calc(100dvh-2rem)]')
        ->toContain('overflow-y-auto')
        ->toContain('top-1/2')
        ->toContain('-translate-y-1/2')
        ->not->toContain('top-4 bottom-4')
        ->not->toContain('top-1/2 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2')
        ->toContain('showCloseButton = true')
        ->toContain('aria-label="Close dialog"')
        ->toContain('<XIcon />')
        ->toContain('DialogTitle')
        ->toContain('DialogDescription')
        ->toContain("'mt-6 flex justify-end gap-2'");

    $v5Css = file_get_contents(resource_path('css/v5/app.css'));

    expect($v5Css)
        ->toContain('--color-warning: var(--warning);')
        ->toContain('--warning: #fcd452;')
        ->not->toContain('--ring: oklch(0.705 0.015 286.067);')
        ->not->toContain('--ring: oklch(0.552 0.016 285.938);');

    expect(substr_count($v5Css, '--ring: var(--warning);'))->toBe(2);

    expect($types)
        ->not->toContain('sshUser: string;')
        ->not->toContain('sshPort: number;')
        ->toContain('builderEnabled: boolean;')
        ->toContain('builderCapacity: number;')
        ->toContain('builderCpuQuota: string;')
        ->toContain('privateKeyName: string | null;')
        ->toContain('lastBootstrappedAt: string | null;')
        ->toContain('lastBootstrapStatus: string | null;')
        ->toContain('lastStatusOutput: string | null;');
});

it('uses the standard button size for the v5 delete cluster action', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)
        ->toContain("variant=\"delete\"\n                                                        size=\"default\"\n                                                        onClick={openDeleteClusterDialog}")
        ->not->toContain("variant=\"delete\"\n                                                        size=\"sm\"\n                                                        onClick={openDeleteClusterDialog}");
});

it('shows the v5 server delete validation error in the confirmation dialog', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)
        ->toContain('const payload = (await response?.json().catch(() => null)) as { message?: string } | null;')
        ->toContain('response?.status === 422')
        ->toContain("'Delete or move applications from this server before deleting it.'")
        ->toContain("'Unable to delete this server. Please try again.'")
        ->toContain('role="alert"')
        ->toContain('border-destructive/30 bg-destructive/10')
        ->toContain('setDeleteClusterError(null);');
});

it('defines a ghost variant for compact v5 select triggers', function () {
    $select = file_get_contents(resource_path('js/v5/components/ui/select.tsx'));

    expect($select)
        ->toContain('@base-ui/react/select')
        ->not->toContain('radix-ui')
        ->toContain("variant = 'default'")
        ->toContain("variant === 'default'")
        ->toContain("variant === 'ghost'")
        ->toContain('border-transparent')
        ->toContain('h-auto')
        ->toContain('text-sm')
        ->toContain("position === 'popper' ? false : true");
});

it('provides reusable v5 form field primitives with reserved validation error space', function () {
    $fieldPath = resource_path('js/v5/components/ui/field.tsx');
    $inputPath = resource_path('js/v5/components/ui/input.tsx');
    $textareaPath = resource_path('js/v5/components/ui/textarea.tsx');
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect(file_exists($fieldPath))->toBeTrue()
        ->and(file_exists($inputPath))->toBeTrue()
        ->and(file_exists($textareaPath))->toBeTrue();

    $field = file_get_contents($fieldPath);
    $input = file_get_contents($inputPath);
    $textarea = file_get_contents($textareaPath);

    expect($field)
        ->toContain('function Field(')
        ->toContain('function FieldLabel(')
        ->toContain('function FieldError(')
        ->toContain('min-h-4')
        ->toContain('aria-live="polite"')
        ->toContain('message ? undefined : true')
        ->toContain('export { Field, FieldError, FieldLabel }');

    expect($input)
        ->toContain('function Input(')
        ->toContain('aria-invalid:border-destructive')
        ->toContain('export { Input };');

    expect($textarea)
        ->toContain('function Textarea(')
        ->toContain('aria-invalid:border-destructive')
        ->toContain('export { Textarea };');

    expect($clustersPage)
        ->toContain("import { Field, FieldError, FieldLabel } from '@/components/ui/field';")
        ->toContain("import { Input } from '@/components/ui/input';")
        ->toContain("import { Textarea } from '@/components/ui/textarea';")
        ->toContain('<FieldError message={errors.name?.[0]} />')
        ->toContain('<FieldError message={serverErrors.private_key_uuid?.[0]} />')
        ->not->toContain('{errors.name ? <span className="text-xs text-destructive">{errors.name[0]}</span> : null}')
        ->not->toContain('{serverErrors.name ? (');
});

it('uses the requested shadcn preset configuration for v5', function () {
    $components = json_decode(file_get_contents(base_path('components.json')), true);
    $css = file_get_contents(resource_path('css/v5/app.css'));

    expect($components['style'])
        ->toBe('base-lyra')
        ->and($components['tsx'])->toBeTrue()
        ->and($components['iconLibrary'])->toBe('phosphor')
        ->and($components['tailwind']['css'])->toBe('resources/css/v5/app.css')
        ->and($components['tailwind']['baseColor'])->toBe('zinc')
        ->and($css)->toContain('@import "@fontsource-variable/geist";')
        ->and($css)->toContain('--foreground: oklch(0.141 0.005 285.823);')
        ->and($css)->toContain('--background: #101010;')
        ->and($css)->toContain('button:not(:disabled)');
});

it('sizes the v5 app root with the dynamic mobile viewport', function () {
    $css = file_get_contents(resource_path('css/v5/app.css'));

    expect($css)
        ->toContain('min-height: 100dvh;')
        ->not->toContain('min-height: 100vh;');
});

it('does not include coolify version controls on the v5 dashboard page', function () {
    $dashboardPage = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));

    expect($dashboardPage)
        ->not->toContain('Check coolify version')
        ->not->toContain('/v5/coolify/version')
        ->not->toContain('Installed version:');
});

it('does not render flux status in the v5 navbar', function () {
    $navbar = file_get_contents(resource_path('js/v5/components/app-navbar.tsx'));

    expect($navbar)
        ->not->toContain('Flux: {flux?.label ??')
        ->not->toContain('title={flux?.socket ?? flux?.message ?? undefined}')
        ->not->toContain('{clusters.length} clusters')
        ->not->toContain('<h2 id="flux-status-heading">Flux status</h2>')
        ->not->toContain('<p>{flux.message}</p>')
        ->not->toContain('Socket: {flux.socket}');
});

it('hardens v5 dashboard fetches and websocket merges against failures', function () {
    $dashboardSource = file_get_contents(resource_path('js/v5/Pages/Dashboard.tsx'));
    $canvasApiSource = file_get_contents(resource_path('js/v5/lib/canvas-api.ts'));
    $statusBadgeSource = file_get_contents(resource_path('js/v5/components/canvas/status-badge.ts'));

    expect($canvasApiSource)->toContain('AbortSignal.timeout(30_000)');

    expect($statusBadgeSource)->toContain("'starting'");

    expect($dashboardSource)->toContain('Could not save the card position.');

    $addNginxSource = substr($dashboardSource, strpos($dashboardSource, 'addNginx'));

    expect(strpos($addNginxSource, '!response.ok'))->not->toBeFalse();
});

it('preserves all v5 resource connection firewall directions when editing ports', function () {
    $connectionsHook = file_get_contents(resource_path('js/v5/lib/use-canvas-connections.ts'));
    $updateDirectionSource = substr(
        $connectionsHook,
        strpos($connectionsHook, 'const updateConnectionDirection'),
        strpos($connectionsHook, 'const setConnectionPortDraft') - strpos($connectionsHook, 'const updateConnectionDirection'),
    );

    expect($connectionsHook)
        ->toContain('function connectionPortsPayload(connection: CanvasConnection)')
        ->toContain('body: { ports_by_direction: connectionPortsPayload(updatedConnection) }')
        ->toContain('preserveActiveDirection(nextConnection, updatedConnection)')
        ->and($updateDirectionSource)
        ->toContain('replaceConnection(updatedConnection)')
        ->not->toContain('persistConnectionPorts(updatedConnection, connection)');
});
