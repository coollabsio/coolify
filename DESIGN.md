# Coolify UI design system

This document defines Coolify's UI design system for its Livewire + Blade +
Alpine + Tailwind v4 frontend. The visual system covers the global shell,
project and environment pages, application navigation, settings surfaces,
tables, modals, toasts, terminals, and metrics.

Use this file as the source of truth for frontend design work. Update it in the
same change whenever a new shared visual pattern or component is introduced.

Onboarding validation and live server validation checkpoints share
`<x-checkpoint-item>` (idle / pending / running / success / error) inside a
compact divided list, not legacy green check SVGs or fixed-width status rows.

> **Maintainer rules**
>
> - Keep the work frontend-focused unless existing data must be exposed to the
>   view.
> - Preserve routes, Livewire bindings, permissions, confirmations, and working
>   interactions while changing layout and presentation.
> - Add or update tests when a UI change affects behavior. Follow the testing
>   requirements in `AGENTS.md`.
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
- outline Reicon glyphs through `<x-reicon>`;
- the Coolify purple brand accent in light mode;
- the readable Coolify yellow accent in dark mode;
- solid active-item fills (neutral black/white opacity), not accent gradients;
  active state is the left accent rail plus a flat selected surface;
- sentence-case labels and headings;
- never use the em dash (`—`) in UI copy. Prefer a period, colon, comma, or
  ASCII hyphen (`-`) for empty cells and separators.

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

- scope settings forms with `.application-settings-form` or
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

- Main sidebar groups are compact, use outline Reicons, and keep a 32px row
  height.
- Active sidebar rows are rounded pills (`rounded-md`) with an accent rail on
  the left plus a solid neutral selected fill (`bg-black/5` light,
  `bg-white/6` dark). Hover rows use the same radius. Do not use accent-tinted
  gradients on nav rows; yellow washes look muddy on dark UI.
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

Desktop resource lifecycle actions dock in `#resource-action-hud-slot` and
use `<x-resource-heading-overflow>`. Show primary actions (Deploy, Redeploy,
Restart, Stop) as sibling header buttons. Collapse that group into an Actions
dropdown only when the remaining top-bar width cannot fit them (breadcrumb
keeps a 200px floor). Infrequent operations live in a separate Advanced
dropdown with the grid icon: force restart / force deploy / force cleanup
on services, and Traefik dashboard / refresh proxy status on servers. Place
Advanced immediately after Links, or first in the action cluster when there
is no Links control. Application Deploy is a dropdown with Deploy and
Deploy (without cache). A running service Restart control is a dropdown with
Restart current version and Pull latest and restart. Mobile
headings keep a full-width Actions dropdown because the desktop HUD is hidden
below `xl`. Do not hide primary actions behind a menu on a wide desktop. Links
stay a separate dropdown because the URL list is unbounded.

Only add layer-2 tabs when they represent real sibling routes inside one
context. Never repeat main-sidebar destinations such as Dashboard, Projects,
Terminal, Servers, Sources, Destinations, or Storage as a second tab row. A
single collection page does not need a tab just to fill the bar; keep its
primary action in the page header instead. When tabs are useful, their left edge
uses the same compact `pl-2` alignment as application navigation rather than
the content container's wide horizontal padding.

A layer-2 tab must be active on the page that renders it. A bar whose only tab
points at a different route reads as broken navigation, so project and
environment pages (`project.show`, `project.edit`, `project.environment.edit`,
`project.clone-me`) carry a plain page header with a 24px title and a 13px
muted summary instead of a bar. The environment identity and the way back to
its resources already live in `x-top-breadcrumb`; do not restate them in a
sub-header.

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

### Route-family consistency

Treat every route family as one cohesive experience rather than styling only
its index or most visible route:

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
  updated with the page that exposes them;
- audit the whole family for native selects, legacy heading blocks, old Save
  buttons, old status chips, and `coolbox`/`navbar-main`/`sub-menu-wrapper`
  to keep the family consistent.

Do not leave a sibling route using old tabs, a large in-flow title, a browser
select, or a different modal anatomy.

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

Instance Settings constrains both `x-settings.navbar` and the workspace to the
same `max-w-[1180px]` shell.

**Page titles (global):** family H1s (`x-dashboard.navbar` with
`titleOnDesktop="false"`, the default) hide at **lg+**, the same breakpoint as
the desktop shell (main sidebar + fixed layer-2 tabs). Below `lg` the mobile
topbar is used and the page title stays visible. Collection indexes (Servers,
Projects, …) always keep their H1; stack title above actions on narrow widths
so they never overlap. Resource in-flow names only render below `md` (when the
fixed resource tab bar is hidden). Fixed layer-2 spacers must be `lg:h-12` to
match the bar height. Do not put the H1 beside the settings sidebar.

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
action-aligned anatomy as the component. Use the component for new work and
replace a manual shell when modifying it instead of creating another variant.

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
between the label and control. Password visibility uses the outline Reicon
`eye`/`eye-off` treatment from the shared input component.

### Dropdowns

Do not use native `<select>` on application routes, including mobile fallbacks.
Use:

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
checkbox on application pages.

The popup panel uses a 10px radius around 6px options with a 4px inset. Keep
the option content left-aligned and size the panel to its content or trigger;
do not create an unnecessarily wide menu.

Toolbar filter and sort buttons keep static labels (`Filter`, `Sort`). The
selected option is indicated inside the menu, not repeated on the trigger.

#### Livewire dropdown state synchronization

Instant-save listboxes must not flash back to an older value while Livewire is
saving or morphing the DOM. Treat the Alpine selection as the current visual
state until its request finishes:

- await the Livewire change handler and prevent overlapping selections while
  it is running;
- when a client-managed listbox can be rerendered by an unrelated or stale
  Livewire response, use the listbox's `preserveValue` option so the morph does
  not replace its newer Alpine value;
- scope `preserveValue` to controls whose value is owned by that interaction;
  do not use it when external server events must replace the displayed value;
- after saving through a related model, refresh the parent component's loaded
  relationship before rendering the response. A database write alone does not
  update an already-loaded Eloquent collection;
- use stable `wire:key` values for rows containing listboxes. Do not include the
  selected value in the key, because recreating the Alpine component causes a
  visible reset;
- remember that a portalled options panel is teleported outside its visual
  wrapper. Guard selection in the Alpine handler itself rather than relying
  only on `pointer-events` or a disabled wrapper.

The failure mode to avoid is: selection B is shown optimistically, selection A
is chosen next, the response for B morphs the listbox back to B, then the later
response finally shows A. The control should remain on the newest accepted
selection throughout the save sequence.

#### Multi-select filter dropdowns

Toolbar filters that can combine criteria use one multi-select listbox rather
than separate dropdowns or a single selected value. Follow the deployment
history filter in
`resources/views/livewire/project/application/deployment/index.blade.php`:

- set `aria-multiselectable="true"` on the listbox;
- group related options under compact uppercase labels;
- keep the dropdown open while options are toggled;
- use the shared 16px custom checkbox treatment: purple checked fill in light
  mode, yellow checked fill in dark mode, and a high-contrast check mark;
- show the number of active selections in a small count pill on the static
  `Filter` trigger;
- combine selections within one group with OR logic and combine different
  groups with AND logic;
- constrain only the options area with `max-h-80 overflow-y-auto`;
- place a persistent `Reset filters` action in a separate footer below the
  scrollable options, divided by a top border;
- disable the reset action when no filter is active, and close the dropdown
  after resetting.

Do not represent the empty state as a selectable `All` option. The footer reset
action is the single way to return the multi-select to its unfiltered state.

### Standard table controls

Dense tables use the shared `x-table.*` components so search, filters, sorting,
and backend loading states remain visually and behaviorally consistent:

- `<x-table.toolbar>` owns the responsive search-left/actions-right layout;
- `<x-table.search>` owns the search icon, optional loading indicator, clear
  action, sizing, and input anatomy;
- `<x-table.filter>` owns the static Filter trigger, active-count pill,
  multi-select panel, scrollable options area, and Reset filters footer;
- `<x-table.sort>` owns the static Sort trigger and single-select panel;
- `<x-table.loading>` overlays only the changing table data for backend search,
  filter, sort, and pagination requests.

Tables continue to own their filter options, sort choices, headers, rows,
queries, permissions, and empty states. Backend-filtered or paginated tables
must use `x-table.loading`; frontend-only Alpine tables reuse the same toolbar
and control anatomy but do not show an artificial loading state.

### Buttons

- neutral actions use the shared `.button`;
- primary actions use the theme-aware purple/yellow tint;
- destructive actions use the existing error treatment;
- use outline Reicons where a matching glyph exists;
- avoid raw browser-default buttons and old dark-mode purple fills.

### Unsaved changes

`resources/views/components/unsaved-bar.blade.php` is a compact floating
bottom-center pill. It contains:

- “You have changes that haven't been saved yet.”
- a subtle Reset action;
- a theme-aware Save changes button matching the tab accent.

On small viewports the pill is inset (`inset-x-3`) and stacks: full label on
the first line, Reset / Save on the second (right-aligned). From `sm` up it
returns to the centered single-row nowrap pill.

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

Hide the entire pagination footer when there is only one page (`totalPages > 1`).
A lone “1–2 of 2” bar with disabled controls adds noise and is unnecessary.

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
- listbox and dropdown panels must render above the modal body and escape its
  scroll container. Never clip a panel at the modal boundary or make users
  scroll the modal to see its options;
- when there is not enough viewport space below the trigger, open the panel
  above it while keeping the panel visually on top of the modal;
- right-aligned footer actions below a divider;
- compact action buttons, never a submit button stretched by a column layout.

Edit modals should use the same field layout and option set as their matching
create modal.

### Command palette

The global search command palette (`livewire:global-search`) is a compact
top-anchored overlay:

- elevated shell with hairline ring and modal shadow (not a heavy floating card);
- recessed-neutral header strip with outline search glyph and 14px input;
- compact OS-aware mod+K (`⌘K` on macOS, `Ctrl+K` on Windows/Linux) / `/` / `ESC` kbd chips matching the sidebar search trigger;
- nested base-color results body with group labels in sentence case;
- dense result rows as inset 6px-radius pills (listbox anatomy), not full-bleed
  bars with global focus rings;
- hover uses neutral fill; keyboard focus uses a soft accent wash plus a 2px
  left rail — never the global `ring-2` / ring-offset treatment;
- create rows use a neutral plus tile that only picks up the accent when the
  row is focused;
- type pills and quickcommand chips stay recessed; they tint with the accent
  only on the focused row;
- neutral thin scrollbar inside the results body (not brand-colored);
- create-resource modals opened from the palette reuse the standard
  `application-settings-section` layer-card shell.

Preserve keyboard navigation (arrow keys, Enter via focused links, Escape to
clear then close), `/` and mod+K (⌘K / Ctrl+K by OS) open shortcuts, and the multi-step
server → destination → project → environment create flow.

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
shell, theme picker, compact header controls, and outline `browser-terminal`
Reicon. Hide a container switcher when only one container exists.

The themed console shell belongs to an open session. Before a target is
selected, the global Terminal page stays a normal top-level destination: a
full-width layer card titled `Start a terminal session`, its filter input in
the card header actions, and grouped `Servers` / `Containers` rows reusing the
command-palette row classes. Do not render an empty full-height console canvas
just to host the target picker, and do not offer the console theme selector
before a session owns that canvas. Rows show the target name, a muted server
column that only appears when the team has more than one server, and the shared
chevron. Group headers stick to the top of the scrolling list and carry a count.

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
| Standard table toolbar controls | `resources/views/components/table/*` |
| Application metrics charts | `resources/views/livewire/project/shared/metrics.blade.php` |
| Browser terminal workspace | `resources/views/livewire/terminal/index.blade.php` |
| Layer card | `resources/views/components/application/settings-section.blade.php` |
| Custom dropdown | `resources/views/components/forms/listbox.blade.php` |
| Empty state | `resources/views/components/empty.blade.php` |
| Status pill | `resources/views/components/status-badge.blade.php` |
| Floating save pill | `resources/views/components/unsaved-bar.blade.php` |
| Global toast | `resources/views/components/toast.blade.php` |
| Command palette / global search | `resources/views/livewire/global-search.blade.php` |
| Outline icons | `resources/views/components/reicon.blade.php` |
| Shared styling | `resources/css/app.css`, `resources/css/utilities.css` |
| HTTP error pages | `resources/views/components/error-page.blade.php`, `resources/views/errors/*` |

HTTP error pages (400, 401, 402, 403, 404, 419, 429, 500, 503) use the shared
`<x-error-page>` component on the public auth-style canvas: theme-aware status
code, compact title and muted description, neutral `.button` actions, and an
`auth-text-link`-style Contact support link. Keep copy sentence-case and avoid
oversized 200px status numbers.

---

## 11. UI implementation checklist

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
