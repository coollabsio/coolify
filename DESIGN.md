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
> - Validate Blade with `./scripts/dev exec php artisan view:cache`, then clear
>   it with `./scripts/dev exec php artisan view:clear`.
> - Build frontend assets with `npm run build`.
> - Use existing components before adding another styling abstraction.

---

## 1. Visual direction

The interface is compact and product-focused:

- near-neutral layered surfaces instead of large bordered boxes;
- 13–14px UI typography and 32px controls;
- crisp hairline rings plus a restrained card lift (single 1px ring +
  `0 1px 2px rgb(0 0 0 / 0.05)`) so cards and tables separate from the canvas,
  never heavy borders or a strong floating shadow;
- full-width data tables for dense collections;
- outline Reicon glyphs through `<x-reicon>`;
- the Coolify purple brand accent in light mode;
- the readable Coolify yellow accent in dark mode;
- solid active-item fills (neutral black/white opacity), not accent gradients;
  active state is a flat selected surface with no accent rail;
- sentence-case labels and headings;
- never use the em dash (`—`) in UI copy. Prefer a period, colon, comma, or
  ASCII hyphen (`-`) for empty cells and separators.

Avoid oversized titles, generic dashboard cards, strong shadows, thick
dividers, native browser selects, and isolated colored buttons that do not
match the current action styles.

Standard `.button` controls use a compact 2px bottom depth. Hover raises the
button face by 1px and increases the visible depth to 3px. Pressing moves the
face down 2px into the edge and removes the depth until release, keeping the
overall bottom position stable. Disabled controls stay flat,
and focus-visible controls retain the accent ring alongside the depth.
Movement and depth-shadow changes transition over 80ms; color transitions keep
the shared 120ms duration.
Standard button labels use `capitalize`, giving each word an initial capital.
Highlighted buttons use `--color-coollabs-300` for their bottom edge; the
custom theme derives it from its bright color mixed with black, so custom
colors keep a matching edge. Dark mode matches the depth edge of neutral
buttons to their regular border color (`rgb(255 255 255 / 0.08)`).

---

## 2. Development and cascade notes

PHP runs in this branch's Coolify container (`./scripts/dev exec …`). The main
checkout serves the app at `http://localhost:8000` with Vite on `5173`;
worktrees use the port block printed by `./scripts/dev urls`.

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
- anchored cards use `scroll-margin-top: 7rem` to clear the fixed topbar and
  the page's top padding;
- modal shells reuse the layer-card classes but keep content-width sizing on
  desktop;
- Alpine code inside quoted Blade attributes must not introduce conflicting
  quote characters.

---

## 3. Tokens and color behavior

The surface ladder is defined in `resources/css/app.css`.

| Token | Light | Dark | Use |
|---|---|---|---|
| `--coollabs-canvas` | 97% off-white | 14.48% neutral | page canvas (kept below card fills so cards lift) |
| `--coollabs-elevated` | 98% neutral | 20.02% neutral | shells and card headers |
| `--coollabs-base` | white | 22.64% neutral | nested card bodies |
| `--coollabs-recessed` | 96% neutral | 25.2% neutral | inputs and listboxes |
| `--coollabs-fill` | 92.2% neutral | 29.31% neutral | dividers and passive fills |
| `--coollabs-line` | translucent dark | 32% neutral | control borders |
| `--coollabs-hairline` | 85.5% neutral | 32% neutral | shell rings (crisp enough to read as a card edge, ~1.5:1) |
| `--coollabs-subtle` | 50% neutral | 70.8% neutral | labels and muted titles (light darkened for WCAG AA 4.5:1) |

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

### Shell layering

The app shell is three distinct surface layers, not one flat color. Chrome
lifts, content is the base, cards lift off the content:

- **Content canvas** is the base layer: `bg-app` in dark (deepest,
  `--color-app` `oklch(14.48% 0 0)`, sRGB 10), `bg-neutral-50` in light. The
  `<main>` content area and page body use it.
- **Sidebar and topbar chrome** use `bg-panel` in dark (`--color-panel`
  `oklch(19.13% 0 0)`, sRGB 20, a clear step lighter than the content canvas)
  and `bg-white` in light, so the chrome reads as a separate panel from the content.

Dark surface tokens are exact oklch equivalents of chosen sRGB steps. oklch
lightness compresses toward pure black below ~15%, so do not pick dark values by
round oklch percentages. The dark ladder is `--color-app` 10,
`--coollabs-elevated` 22, `--coollabs-base` 28, `--coollabs-recessed` 34 (sRGB),
which reads as distinct surfaces.

Temperature: every panel is **pure neutral gray** (r=g=b), one consistent
temperature across the sidebar, tables, cards, inputs, dividers, borders, and
text, in both modes. Do not give one surface a cool (blue) or warm cast while
the others stay neutral. The light page canvas uses `bg-neutral-50` (not
`bg-gray-50`, which is faintly cool) so it matches the neutral cards and chrome.
The only intentional color is the purple/yellow brand accent.
- **Cards, tables, and collection tiles** lift off the content canvas with
  `dark:bg-white/[0.05]` plus the crisp `--coollabs-hairline` ring; in light
  they are `bg-white` with the ring and the restrained card lift.

Do not paint the content area with the same `bg-panel` as the sidebar, and do
not drop card fills below `dark:bg-white/[0.05]`; both make surfaces read as one
color. Row-hover states keep the lighter `dark:hover:bg-white/[0.025]`.

---

## 4. Page shells and navigation

### Global shell

- Main sidebar groups are compact, use outline Reicons, and keep a 32px row
  height.
- Active sidebar rows are rounded pills (`rounded-md`) with a solid neutral
  selected fill (`bg-black/5` light, `bg-white/6` dark) and no accent rail.
  Hover rows use the same radius. Do not use accent-tinted gradients on nav
  rows; yellow washes look muddy on dark UI.
- Nested items sit behind a thin 1px guide line (`.nav-children`) and use the
  same selected pill, not a thick box border.
- The update badge sits on the version row and uses a tiny fully rounded
  primary-action pill.

### Resource navigation

Application, service, database, and server pages have no second navigation
bar; content starts directly below the 48px global topbar. Every route in a
resource family (settings pages, backups, logs, terminal, metrics, danger zone)
is an entry in its grouped settings sidebar. Do not add a tab row, a large
in-flow resource heading, or legacy `.navbar-main` tabs to one resource type.

Keep route-derived active state in Blade/Livewire. Do not rely only on Alpine
state because it can disappear after polling or a Livewire morph.

The global topbar owns the current resource identity, its compact status
badges, configuration warnings (`#configuration-warning-hud-slot`), and the
resource actions (`#resource-action-hud-slot`). If a resource is missing from
`x-top-breadcrumb`, extend the global topbar instead of repeating its name or
status summary in the page. Below `xl` the resource heading repeats the name,
status, and links in-flow because the desktop HUD is hidden there.

Desktop resource actions dock in `#resource-action-hud-slot` (visible from
`xl`) as one `<x-split-action>`. The main button is the primary action for the
current state (Deploy, Restart, Start, Restart Proxy); the caret opens a menu
with the secondary actions: Deploy (without cache) and Restart on applications,
Pull latest and restart / Force Restart / Force Deploy / Force Cleanup
Containers on services, Refresh Proxy Status on servers. Stop is the last menu
item in the error color. Stop, restart, and removal items open the existing
confirmation modals. Do not add a separate Advanced dropdown or collapse actions
into an overflow menu. Place Links (`x-applications.links`, `x-services.links`)
or the server Traefik Dashboard link immediately before the split action; Links
stay a separate dropdown because the URL list is unbounded. Below `xl` the same
split action renders full width under the in-flow resource name. A resource
that cannot deploy yet shows a single Actions dropdown that explains why.

The only fixed tab strip left is `x-dashboard.navbar`, and it renders only when
a page has at least two real sibling routes or header actions. Never repeat
main-sidebar destinations such as Dashboard, Projects, Terminal, Servers,
Sources, Destinations, or Storage as a tab row. A single collection page does
not need a tab just to fill the bar; keep its primary action in the page header.

A tab must be active on the page that renders it. A bar whose only tab
points at a different route reads as broken navigation, so project and
environment pages (`project.show`, `project.edit`, `project.environment.edit`,
`project.clone-me`) carry a plain page header with a 24px title and a 13px
muted summary instead of a bar. The environment identity and the way back to
its resources already live in `x-top-breadcrumb`; do not restate them in a
sub-header.

The dashboard is a compact overview, not a metrics wall. It has no page header:
live active deployments come first as a compact table, followed by traffic
analytics when a server has it enabled, then two full-width sections (Projects,
then Servers) that follow the projects-page grid pattern. Each section uses
`x-section-heading` linking to its full index. Communicate server health with
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
instance Settings use their `*settings-layout` component: the same grouped,
icon-led settings sidebar as resources, plus a `settings-mobile-header` title
below `xl`. Do not nest `<button>` elements inside sidebar links.

### Route-family consistency

Treat every route family as one cohesive experience rather than styling only
its index or most visible route:

- index, create, detail, settings, logs, metrics, backup, execution, and danger
  routes must share the same navigation hierarchy and surface language;
- main-sidebar collection routes use the global shell without duplicating those
  destinations in a tab row;
- resource detail families use resource identity, status, and actions in the
  global topbar, and put every sibling route in the grouped settings sidebar;
- create and edit routes stay inside the same sidebar family instead of
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

Application, service, database, server, and top-level settings pages use the
same 210px grouped, icon-led sidebar and a full-width content column. From `xl`
the sidebar is a fixed full-height rail below the topbar that tracks the main
sidebar width, with its own fill, right hairline, filter input, and scroll. Below
`xl` it becomes a wrapped grid of links above the content. Do not use the legacy
`sub-menu-wrapper`, native mobile page selects, or a row of top-level tabs. Only
show nested section anchors when a page has at least four useful sections.

The shared workspace grid is:

```blade
<section class="application-settings-workspace w-full max-w-none">
    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-application.configuration-sidebar :application="$application" :current-route="$currentRoute" />
        <div class="min-w-0">
            ...
        </div>
    </div>
</section>
```

Instance Settings uses `x-settings.layout` with the same full-width workspace.

**Page titles (global):** `x-dashboard.navbar` H1s (`titleOnDesktop="false"`,
the default) hide at **lg+** only when the page renders a fixed tab strip or
actions; otherwise they stay visible. Collection indexes (Servers, Projects, …)
always keep their H1; stack title above actions on narrow widths so they never
overlap. Application, service, and database names render in-flow below `xl`
(the desktop action HUD breakpoint), server names below `lg`, and settings
families use `settings-mobile-header` below `xl`. Fixed tab-strip spacers must
be `lg:h-12` to match the bar height. Do not put the H1 beside the settings sidebar.

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

Every dropdown, menu, listbox panel, and the command palette uses the shared
`--shadow-dropdown` token (`0 4px 12px rgb(0 0 0 / 0.12), 0 2px 4px
rgb(0 0 0 / 0.08)`) for a restrained, consistent lift. Do not hand-roll a
heavier `shadow-lg` / `0 18px 50px` / `0.45`-alpha drop shadow on a menu.
Reserve the stronger `--shadow-modal` for actual modals, dialogs, and toasts.

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
instant-save pattern. When those requests share a component with a modal draft,
pass the unsaved bar a `dirty` Alpine expression comparing that draft with its
initial values, so unrelated saves do not hide pending changes. Mount modal save
bars only while the modal is open to avoid inactive keyboard shortcuts.

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

### Domain rows on mobile

Domain tables become compact summary cards below 600px. Keep the public URL on
its own line, followed by a short routing summary such as `HTTP → HTTPS · Port
80 · Noindex`. Put DNS status and the existing icon actions on the final row.
Do not squeeze desktop label/value columns into a mobile card or move settings
behind an overflow menu. Long domains wrap, and icon actions retain 40px touch
targets.

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
- normally up to four stacked notifications, without evicting persistent notices;
- four-second dismissal, paused while hovered;
- `persistent: true` disables automatic dismissal, including after hover; users close these notices with the dismiss button;
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
| Top-level settings families | `resources/views/components/team/settings-layout.blade.php`, `resources/views/components/notification/settings-layout.blade.php` |
| General settings and form anatomy | `resources/views/livewire/project/application/general.blade.php` |
| Advanced settings | `resources/views/livewire/project/application/advanced.blade.php` |
| Resource actions in the topbar | `resources/views/livewire/project/application/heading.blade.php`, `resources/views/components/split-action.blade.php`, `resources/views/livewire/server/navbar.blade.php` |
| Grouped settings sidebar | `resources/views/components/application/configuration-sidebar.blade.php`, `resources/views/components/server/sidebar.blade.php` |
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
4. Add the grouped settings sidebar and scoped workspace/form class.
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
17. Compile Blade views with `./scripts/dev exec php artisan view:cache`.
18. Build assets with `npm run build`.
19. Hard-refresh and inspect the family routes in both themes.
