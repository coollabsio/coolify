# Coolify UI redesign

This branch restyles Coolify without changing its Livewire + Blade + Alpine +
Tailwind v4 architecture. The visual system now covers the global shell,
project and environment pages, application navigation, settings surfaces,
tables, modals, toasts, terminals, and metrics.

Use this file as the source of truth when updating another page. The older
Graphite-only notes are no longer accurate.

> **Maintainer rules**
>
> - Keep the work frontend-focused unless existing data must be exposed to the
>   view.
> - Preserve routes, Livewire bindings, permissions, confirmations, and working
>   interactions while changing layout and presentation.
> - Do not write or run tests for this redesign branch.
> - Validate Blade with `docker exec coolify php artisan view:cache`, then clear
>   it with `docker exec coolify php artisan view:clear`.
> - Build frontend assets in the Vite container with
>   `docker exec coolify-vite npm run build`.
> - Use existing components before adding another styling abstraction.

---

## 1. Visual direction

The interface is compact and product-focused:

- near-neutral layered surfaces instead of large bordered boxes;
- 13–14px UI typography and 32px controls;
- hairline rings instead of heavy borders;
- full-width data tables for dense collections;
- filled Reicon glyphs through `<x-reicon>`;
- the Coolify purple brand accent in light mode;
- the readable Coolify yellow accent in dark mode;
- subtle active-item gradients that fade completely into the surrounding
  background at the far edge;
- sentence-case labels and headings.

Avoid oversized titles, generic dashboard cards, strong shadows, thick
dividers, native browser selects, and isolated colored buttons that do not
match the current action styles.

---

## 2. Development and cascade notes

PHP runs in the `coolify` container. The development app is normally available
at `http://localhost:8000`, with Vite on port `5173`.

`resources/css/app.css` still contains unlayered global element rules for
headings, labels, and tables. Tailwind utilities are layered, so the
unlayered rules can win unexpectedly.

The settings and dense-surface CSS therefore lives as plain unlayered CSS near
the end of `resources/css/app.css`, beginning at:

```css
/* Coollabs layer-card settings surfaces */
```

Important consequences:

- scope restyled forms with `.application-settings-form` or
  `.application-settings-workspace`;
- add shared surface overrides to the unlayered block instead of stacking
  `!important` utilities;
- listbox panels require ancestors with `overflow: visible`;
- anchored cards use `scroll-margin-top: 7rem` to clear both fixed navigation
  layers;
- modal shells reuse the layer-card classes but keep content-width sizing on
  desktop;
- Alpine code inside quoted Blade attributes must not introduce conflicting
  quote characters.

---

## 3. Tokens and color behavior

The surface ladder is defined in `resources/css/app.css`.

| Token | Light | Dark | Use |
|---|---|---|---|
| `--coollabs-canvas` | near white | 10% neutral | page canvas |
| `--coollabs-elevated` | 98% neutral | 15% neutral | shells and card headers |
| `--coollabs-base` | white | 17% neutral | nested card bodies |
| `--coollabs-recessed` | 96% neutral | 20% neutral | inputs and listboxes |
| `--coollabs-fill` | 92.2% neutral | 26.9% neutral | dividers and passive fills |
| `--coollabs-line` | translucent dark | 32% neutral | control borders |
| `--coollabs-hairline` | 93.5% neutral | 26.9% neutral | shell rings |
| `--coollabs-subtle` | 55.6% neutral | 70.8% neutral | labels and muted titles |

Accent behavior is intentionally theme-aware:

- **Light mode:** Coolify purple (`coollabs`) for active controls, focus,
  primary actions, and navigation accents.
- **Dark mode:** Coolify yellow (`warning`) for the same states because the
  original purple did not provide sufficient text and ring contrast.

Do not hard-code blue focus rings or leave yellow accent utilities active in
light mode. Primary action patterns should normally follow:

```html
bg-coollabs/10 text-coollabs ring-coollabs/25
dark:bg-warning/15 dark:text-warning dark:ring-warning/25
```

The filled top-level action/tab treatment uses the same palette at a restrained
opacity rather than a fully saturated fill.

---

## 4. Page shells and navigation

### Global shell

- Main sidebar groups are compact, use filled Reicons, and keep a 32px row
  height.
- Active sidebar rows have a curved accent rail and a subtle horizontal
  gradient. The gradient must fade to the exact sidebar background at the
  right edge in both themes.
- Nested items use a thin guide line with a visible active segment, not a thick
  box border.
- The update badge sits on the version row and uses a tiny fully rounded
  primary-action pill.

### Layer-2 navigation

Application and server pages use the same fixed second navigation layer
directly below the global topbar. Do not keep a large in-flow resource heading
or legacy `.navbar-main` tabs on one resource type while using the compact
layer-2 bar on another. Active tabs are a light brand fill:

- purple tint in light mode;
- yellow tint in dark mode;
- no fully saturated tab background.

Keep route-derived active state in Blade/Livewire. Do not rely only on Alpine
state because it can disappear after polling or a Livewire morph.

The global topbar owns the current resource identity and its compact status
badges. Layer 2 owns route tabs, resource links, and contextual action buttons
only. If a resource is missing from `x-top-breadcrumb`, extend the global
topbar instead of repeating its name or status summary in layer 2. Mobile
resource navigation may repeat this context because the desktop global topbar
is hidden there.

Only add layer-2 tabs when they represent real sibling routes inside one
context. Never repeat main-sidebar destinations such as Dashboard, Projects,
Terminal, Servers, Sources, Destinations, or Storage as a second tab row. A
single collection page does not need a tab just to fill the bar; keep its
primary action in the page header instead. When tabs are useful, their left edge
uses the same compact `pl-2` alignment as application navigation rather than
the content container's wide horizontal padding.

The dashboard is a compact overview, not a metrics wall. Use two full-width
sections that follow the projects-page grid pattern: projects first, then
servers. Keep one `New` action in the page header and let its modal choose the
resource type. Place active deployments above the resource grids as a compact,
live-updating table rather than a metric card. Communicate server health with
the shared status badge.

### Top-level dashboard destinations

Every page opened directly from the main sidebar uses the same compact content
shell:

- 24px page title and a 13px muted summary;
- the primary action at the top right using the restrained brand fill;
- no legacy `coolbox`, `.navbar-main`, or oversized subtitle block;
- four-column compact cards for small browsable collections;
- a dense table instead of cards when the collection is expected to grow;
- `x-empty` anatomy for empty states;
- `x-status-badge` for state and `x-reicon` for all interface icons.

Collection cards are `min-h-28` or `min-h-32`, use a 32px icon tile, and keep
secondary metadata at 11px. They must not grow into dashboard-sized summary
cards. Sources, destinations, S3 storage, private keys, and shared-variable
scopes use this pattern.

Top-level settings families such as Team, Notifications, Keys & Tokens, and
instance Settings use a compact header followed by a small route-derived tab
strip. The active tab uses the same purple-light/yellow-dark tint as resource
tabs. Do not nest `<button>` elements inside tab links.

### Route-family completion gate

A redesign is not complete when only its index or most visible route has been
updated. Treat every route family as one deliverable:

- index, create, detail, settings, logs, metrics, backup, execution, and danger
  routes must share the same navigation hierarchy and surface language;
- main-sidebar collection routes use the global shell without duplicating those
  destinations in a layer-2 tab row;
- resource detail families use resource identity and status in the global
  topbar, route tabs and actions in layer 2, and the grouped settings sidebar
  only for the third level;
- create and edit routes stay inside the same layer-2 family instead of
  falling back to an isolated legacy page;
- reusable partials, empty states, confirmation flows, and row editors must be
  migrated with the page that exposes them;
- audit the whole family for native selects, legacy heading blocks, old Save
  buttons, old status chips, and `coolbox`/`navbar-main`/`sub-menu-wrapper`
  before marking the family complete.

Do not report a family as redesigned while a sibling route still uses the old
tabs, a large in-flow title, a browser select, or a different modal anatomy.

The New Resource page keeps its filter controls in the top layer card, then
renders Applications, Databases, and Services as separate layer-card sections.
Do not leave category headings and resource grids floating as uncontained
content below the filter card.

### Settings workspace

Application and server configuration pages use the same 210px grouped,
icon-led sidebar and a full-width content column. The workspace is capped at
1180px, the sidebar becomes sticky at `xl`, and the sidebar label and first
content card start on the same visual line. Do not use the legacy
`sub-menu-wrapper`, native mobile page selects, or an in-flow row of top-level
tabs. Only show nested section anchors when a page has at least four useful
sections.

The shared workspace grid is:

```blade
<div
    class="application-settings-workspace mt-8 grid min-w-0 gap-8
        xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
    <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
        ...
    </aside>
    <div class="min-w-0 xl:mt-3">
        ...
    </div>
</div>
```

Standard content stack:

```blade
<div class="application-settings-workspace flex flex-col gap-6">
    <x-application.settings-section ... />
    <x-application.settings-section ... />
</div>
```

The current cross-page section gap is `gap-6`. Do not introduce extra top
padding on an individual page unless its toolbar is intentionally separated
from the first card.

Use a flex or grid stack with `gap-6`; do not use `space-y-*` between layer
cards. The layer-card root intentionally resets its own margin, so margin-based
spacing utilities can silently collapse.

---

## 5. Layer cards

Use `resources/views/components/application/settings-section.blade.php`.
Older manual shells may use `.application-settings-section-header` and
`.application-settings-section-body`; both must retain the same padded,
action-aligned anatomy as the component. Prefer migrating new work to the
component instead of creating another manual variant.

```blade
<x-application.settings-section
    id="public-access-section"
    title="Public access"
    helper="How this section affects the resource.">
    <x-slot:actions>
        <x-forms.button>Action</x-forms.button>
    </x-slot:actions>

    ...
</x-application.settings-section>
```

Anatomy:

- 8px shell radius;
- elevated header strip;
- no divider below the header;
- nested base-color body with its own fill ring;
- 16px body padding;
- optional `flush` mode for full-bleed tables;
- card-level actions belong in the header slot.

Header actions use an 8px top/right inset while the title keeps its 16px left
inset. Do not leave a larger empty strip between the final action and the
card's top-right corner.

Do not split one collection into a summary card followed by a table or log
card. Keep its status/action in the header, its view switcher or toolbar at the
top of a flush body, and its data in that same layer card. Repeated file
editors are the opposite case: each file gets its own titled layer card so its
content and actions remain clearly associated.

### Nested radii

Concentric boxes must follow:

```text
outer radius = inner radius + visible inset
```

Examples:

- a 6px tab or listbox option inside 4px padding uses a 10px outer well;
- an 8px button inside the unsaved pill's 8px padding uses a 16px outer pill.

Do not give visibly inset parent and child boxes the same radius. Flush or
edge-to-edge children are exempt because there is no visible inset to add.

Use an empty state when the section has no usable controls:

```blade
<x-empty size="sm" title="Nothing here" description="Explain what enables it.">
    <x-slot:icon>
        <x-reicon name="layers" class="size-8" />
    </x-slot:icon>
</x-empty>
```

---

## 6. Controls

All normal controls are 32px high with an 8px radius.

### Field grids

The grid must match the controls visible in the current state:

- two visible peer controls use two columns, not a three-column grid with an
  empty track;
- three visible peer controls may use three columns when their content stays
  readable;
- conditional fields remain in the same grid when they are part of that field
  group, so a URL or text input does not become wider than its peer column;
- collapse to one column at smaller breakpoints.

Do not pick a column count from the maximum possible state if the normal state
shows fewer controls.

### Inputs

Use `x-forms.input` and `x-forms.textarea`. Fields need visible vertical spacing
between the label and control. Password visibility uses the filled Reicon
`eye`/`eye-off` treatment from the shared input component.

### Dropdowns

Do not use native `<select>` on any redesigned route, including mobile
fallbacks. Use:

```blade
<x-forms.listbox id="property" label="Setting" :options="[
    ['value' => true, 'label' => 'Enabled'],
    ['value' => false, 'label' => 'Disabled'],
]" onChange="instantSave" />
```

Boolean checkboxes should normally become descriptive two-option listboxes.
Use `.live` behavior only when the selection needs an immediate server
rerender.

Keep checkboxes for compact permission matrices and multi-select lists. Those
controls must use the shared `x-forms.checkbox` anatomy: an 18px rounded custom
box, purple checked fill in light mode, yellow checked fill in dark mode, and a
high-contrast check mark. Never expose the browser or Tailwind Forms default
checkbox on a redesigned page.

The popup panel uses a 10px radius around 6px options with a 4px inset. Keep
the option content left-aligned and size the panel to its content or trigger;
do not create an unnecessarily wide menu.

Toolbar filter and sort buttons keep static labels (`Filter`, `Sort`). The
selected option is indicated inside the menu, not repeated on the trigger.

### Buttons

- neutral actions use the shared `.button`;
- primary actions use the theme-aware purple/yellow tint;
- destructive actions use the existing error treatment;
- use filled Reicons where a matching glyph exists;
- avoid raw browser-default buttons and old dark-mode purple fills.

### Unsaved changes

`resources/views/components/unsaved-bar.blade.php` is a compact floating
bottom-center pill. It contains:

- “You have changes that haven't been saved yet.”
- a subtle Reset action;
- a theme-aware Save changes button matching the tab accent.

Do not restore the old full-width footer.

Deferred fields in one Livewire component use one floating unsaved bar and one
submit action. Do not add a separate “Save configuration” button to every
card. Selectors that are safe to persist independently should use the existing
instant-save pattern.

---

## 7. Dense tables

Collections with many rows should use the Cloudflare-inspired table pattern:

- toolbar above the table;
- search on the left;
- filters, sort, view toggles, and Add on the right;
- 40px header row and roughly 48px data rows;
- subtle row hover;
- plain text or the shared status badge rather than large colored chips;
- compact action at the far right;
- no separate layer card for each item.

Do not add a summary card above a table when it only repeats the row count,
current page, or refresh interval. Keep counts and pagination in the footer.
Background polling stays silent unless its state is actionable; do not add a
“Live updates” badge just to explain that a table refreshes. Filters only
render meaningful values; use the shared listbox instead of a number input or
browser-native control.

The footer is always inside the table shell:

- `Showing X–Y of Z` on the left;
- first, previous, current page, next, and last controls on the right.

Use `x-status-badge` for resource and execution state. It is a small neutral
pill with a semantic dot, not a full colored rectangle.

Relevant classes:

- `.data-table`
- `.data-table-header`
- `.data-table-row`
- `.table-badge`

Create a page-specific grid class when columns differ. Add responsive rules
that hide secondary columns before allowing horizontal overflow.

---

## 8. Modals, confirmations, and toasts

### Modals

`x-modal-input` and confirmation dialogs reuse the layer-card shell:

- compact elevated header;
- nested base-color body;
- content-width desktop sizing;
- shared 32px controls;
- no redundant description below a self-explanatory title;
- custom listboxes instead of native browser selects;
- right-aligned footer actions below a divider;
- compact action buttons, never a submit button stretched by a column layout.

Edit modals should use the same field layout and option set as their matching
create modal.

### Toasts

`resources/views/components/toast.blade.php` provides the global
`window.toast(message, options)` API and Livewire event handling.

Current toast behavior:

- compact layered card, maximum width 26rem;
- Reicon status tile for success, info, warning, danger, or default;
- title plus optional description;
- dismiss and copy-details actions;
- up to four stacked notifications;
- four-second dismissal, paused while hovered;
- support for all six screen positions and sanitized custom HTML.

Do not bring back the old oversized dark rectangle.

---

## 9. Terminals, logs, and metrics

### Terminals

Application and server browser terminals use the same browser-oriented console
shell, theme picker, compact header controls, and filled `browser-terminal`
Reicon. Hide a container switcher when only one container exists.

### Logs

Runtime and deployment logs should feel like a clean terminal surface:

- keep a single log stream inside one layer card instead of adding an
  introductory card above it;
- one compact toolbar;
- a recessed monospace log viewport;
- search and line-count controls aligned with icon actions;
- clear live/follow state;
- fullscreen support without changing the control language;
- custom listbox-style menus instead of browser dropdowns.

### Metrics

Metrics pages use separate layer cards for range selection, CPU, and memory.
Charts follow the application metrics implementation:

- 240px area chart;
- smooth 2px stroke and restrained gradient fill;
- dashed neutral grid;
- no ApexCharts toolbar;
- tooltip positioned at the hovered point;
- UTC on both axes and tooltip;
- 20% headroom above observed values;
- downsample long time ranges before rendering.

Only add a metric if Sentinel exposes historical data for it. Current Sentinel
history endpoints store CPU and memory. Root filesystem usage is included in
the periodic push payload for threshold notifications, but it is not stored as
a historical Sentinel metric and has no history endpoint, so it cannot power a
disk-usage graph yet.

---

## 10. Current reference surfaces

Use these as implementation references:

| Surface | Reference |
|---|---|
| Dashboard overview | `resources/views/livewire/dashboard.blade.php` |
| Top-level collection cards | `resources/views/livewire/project/index.blade.php`, `resources/views/source/all.blade.php` |
| Top-level family tabs | `resources/views/components/team/navbar.blade.php`, `resources/views/components/notification/navbar.blade.php` |
| General settings and form anatomy | `resources/views/livewire/project/application/general.blade.php` |
| Advanced settings | `resources/views/livewire/project/application/advanced.blade.php` |
| Fixed layer-2 resource navigation | `resources/views/livewire/project/application/heading.blade.php`, `resources/views/livewire/server/navbar.blade.php` |
| Grouped settings sidebar | `resources/views/livewire/project/application/configuration.blade.php`, `resources/views/components/server/sidebar.blade.php` |
| Dense environment table and footer | `resources/views/livewire/project/shared/environment-variable/all.blade.php` |
| Application metrics charts | `resources/views/livewire/project/shared/metrics.blade.php` |
| Browser terminal workspace | `resources/views/livewire/terminal/index.blade.php` |
| Layer card | `resources/views/components/application/settings-section.blade.php` |
| Custom dropdown | `resources/views/components/forms/listbox.blade.php` |
| Empty state | `resources/views/components/empty.blade.php` |
| Status pill | `resources/views/components/status-badge.blade.php` |
| Floating save pill | `resources/views/components/unsaved-bar.blade.php` |
| Global toast | `resources/views/components/toast.blade.php` |
| Filled icons | `resources/views/components/reicon.blade.php` |
| Shared styling | `resources/css/app.css`, `resources/css/utilities.css` |

Already restyled application configuration surfaces include General, Advanced,
Environment Variables, Persistent Storage, Servers, Scheduled Tasks, Webhooks,
Preview Deployments, Healthcheck, Rollback, Resource Limits, Resource
Operations, Metrics, Tags, and Danger Zone.

---

## 11. Restyling checklist

1. Inventory every route and reusable partial in the family before editing.
2. Read the current Blade and Livewire class before changing presentation.
3. Preserve every existing action, authorization check, loading state, and
   confirmation.
4. Add the correct dual navigation and scoped workspace/form class.
5. Convert meaningful groups to layer cards and use `gap-6`.
6. Make the responsive column count match the controls visible in every state.
7. Replace native selects and checkbox-style configuration with listboxes.
8. Use one save model per component: instant-save or one floating dirty bar.
9. Check nested radii using `outer = inner + inset`.
10. Keep modal descriptions purposeful and footer actions compact/right-aligned.
11. Use tables for dense collections and cards for forms or summaries.
12. Use `x-status-badge`, `x-empty`, and `x-reicon`.
13. Confirm light and dark accent behavior.
14. Check fixed-nav anchor offsets and responsive stacking.
15. Sweep every sibling route for legacy controls and shells.
16. Run `git diff --check`.
17. Compile Blade views in the `coolify` container.
18. Build assets in `coolify-vite`.
19. Hard-refresh and inspect the family routes in both themes.
