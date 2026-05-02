---
version: alpha
name: Coolify
description: Self-hosted PaaS. Dark-first utilitarian UI. Purple (light) / yellow (dark) accent swap. Sharp 2px radii. Inset box-shadow inputs with 4px dirty-bar indicator.
colors:
  # Brand
  coollabs: "#6b16ed"
  coollabs-50: "#f5f0ff"
  coollabs-100: "#7317ff"
  coollabs-200: "#5a12c7"
  coollabs-300: "#4a0fa3"
  # Dark-mode accent (warning scale)
  warning: "#fcd452"
  warning-50: "#fefce8"
  warning-100: "#fef9c3"
  warning-200: "#fef08a"
  warning-300: "#fde047"
  warning-400: "#fcd452"
  warning-500: "#facc15"
  warning-600: "#ca8a04"
  warning-700: "#a16207"
  warning-800: "#854d0e"
  warning-900: "#713f12"
  # Dark surfaces
  base: "#101010"
  coolgray-100: "#181818"
  coolgray-200: "#202020"
  coolgray-300: "#242424"
  coolgray-400: "#282828"
  coolgray-500: "#323232"
  # Light surfaces (Tailwind neutrals)
  surface: "#ffffff"
  background: "#f9fafb"
  border: "#e5e5e5"
  text: "#000000"
  text-muted: "#737373"
  text-placeholder: "#d4d4d4"
  # Semantic
  success: "#22C55E"
  error: "#dc2626"
  primary: "{colors.coollabs}"
typography:
  h1:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 1.875rem
    fontWeight: 700
    lineHeight: 1.2
  h2:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 1.25rem
    fontWeight: 700
  h3:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 1.125rem
    fontWeight: 700
  h4:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 1rem
    fontWeight: 700
  body-md:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 0.875rem
    fontWeight: 400
    lineHeight: 1.25rem
  label-md:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 0.875rem
    fontWeight: 500
  label-sm:
    fontFamily: "'Geist Sans', Inter, sans-serif"
    fontSize: 0.75rem
    fontWeight: 700
    lineHeight: 1rem
  mono:
    fontFamily: "'Geist Mono', SFMono-Regular, Consolas, 'Liberation Mono', Menlo, monospace"
    fontSize: 0.875rem
    fontWeight: 400
rounded:
  sm: 0.125rem   # default — inputs, buttons, cards, modals
  md: 0.25rem    # coolbox
  lg: 0.5rem     # callouts
  full: 9999px   # badges, pills
spacing:
  xs: 0.25rem
  sm: 0.5rem
  md: 1rem
  lg: 1.5rem
  xl: 2rem
  section: 3rem
  sidebar-width: 14rem
  button-height: 2rem
  card-min-height: 4rem
  input-py: 0.375rem
components:
  button:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    height: "{spacing.button-height}"
    padding: 0 0.5rem
  button-dark:
    backgroundColor: "{colors.coolgray-100}"
    textColor: "#ffffff"
  button-hover:
    backgroundColor: "#f5f5f5"
  button-hover-dark:
    backgroundColor: "{colors.coolgray-200}"
  button-highlighted:
    backgroundColor: "{colors.coollabs-50}"
    textColor: "{colors.coollabs-200}"
  button-highlighted-hover:
    backgroundColor: "{colors.coollabs}"
    textColor: "#ffffff"
  button-error:
    backgroundColor: "#fef2f2"
    textColor: "#991b1b"
  button-error-hover:
    backgroundColor: "#fca5a5"
    textColor: "#ffffff"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: 0.375rem 0.5rem
  input-dark:
    backgroundColor: "{colors.coolgray-100}"
    textColor: "#ffffff"
  textarea:
    typography: "{typography.mono}"
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.sm}"
  box:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: "{spacing.sm}"
    height: "{spacing.card-min-height}"
  box-dark:
    backgroundColor: "{colors.coolgray-100}"
    textColor: "#ffffff"
  box-hover:
    backgroundColor: "#f5f5f5"
  box-hover-dark:
    backgroundColor: "{colors.coollabs-100}"
    textColor: "#ffffff"
  coolbox:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "{spacing.sm}"
    height: "{spacing.card-min-height}"
  badge-success:
    backgroundColor: "{colors.success}"
    size: 0.75rem
    rounded: "{rounded.full}"
  badge-warning:
    backgroundColor: "{colors.warning}"
    size: 0.75rem
    rounded: "{rounded.full}"
  badge-error:
    backgroundColor: "{colors.error}"
    size: 0.75rem
    rounded: "{rounded.full}"
  deprecated-badge:
    backgroundColor: "rgba(252, 212, 82, 0.15)"
    textColor: "{colors.warning}"
    rounded: "{rounded.full}"
    padding: 0.125rem 0.5rem
  callout-warning:
    backgroundColor: "{colors.warning-50}"
    textColor: "{colors.warning-800}"
    rounded: "{rounded.lg}"
    padding: "{spacing.md}"
  callout-danger:
    backgroundColor: "#fef2f2"
    textColor: "#991b1b"
    rounded: "{rounded.lg}"
    padding: "{spacing.md}"
  callout-info:
    backgroundColor: "#eff6ff"
    textColor: "#1e40af"
    rounded: "{rounded.lg}"
    padding: "{spacing.md}"
  callout-success:
    backgroundColor: "#f0fdf4"
    textColor: "#166534"
    rounded: "{rounded.lg}"
    padding: "{spacing.md}"
  dropdown:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.sm}"
    padding: "{spacing.xs}"
  dropdown-dark:
    backgroundColor: "{colors.coolgray-200}"
  dropdown-item-hover:
    backgroundColor: "#f5f5f5"
  dropdown-item-hover-dark:
    backgroundColor: "{colors.coollabs}"
    textColor: "#ffffff"
  menu-item-active:
    backgroundColor: "#e5e5e5"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
  menu-item-active-dark:
    backgroundColor: "{colors.coolgray-200}"
    textColor: "{colors.warning}"
  tag:
    backgroundColor: "#f5f5f5"
    textColor: "{colors.text-muted}"
    padding: 0.25rem 0.5rem
  kbd:
    rounded: "{rounded.sm}"
    padding: 0 0.5rem
  toast:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.sm}"
    padding: "{spacing.md}"
    width: 20rem
  modal-input:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.sm}"
  modal-input-dark:
    backgroundColor: "{colors.base}"
  modal-confirmation:
    backgroundColor: "#f5f5f5"
    rounded: "{rounded.sm}"
  modal-confirmation-dark:
    backgroundColor: "{colors.base}"
---

# Coolify Design System

## Overview

Coolify is a self-hosted PaaS (Heroku/Netlify/Vercel alternative) built with Laravel 12, Livewire 3, and Tailwind CSS v4. UI is **dark-first, dense, utilitarian** — operators want information density over whitespace.

Brand personality: precise, engineered, no-nonsense. No flourish. No gradients outside a single branded upsell. Flat surfaces differentiated by tonal depth, not shadow.

Two signature traits define the system:

1. **Purple/Yellow accent swap.** Light mode uses `coollabs` purple `#6b16ed`. Dark mode swaps to `warning` yellow `#fcd452` for focus rings, active nav items, helper icons, loading spinners, highlighted text, helper links. Never use purple as an accent in dark mode.
2. **Inset box-shadow inputs with a 4px "dirty bar".** Inputs and selects have no border — they use `box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px <border>`. When the field is focused or has unsaved changes (`wire:dirty`), the left 4px becomes the accent color — a live visual indicator of modified state. This is the single most distinctive UI detail in Coolify.

Sharp geometry everywhere: 2px corner radius by default (`rounded-sm`). 8px only on callouts. Shadows used sparingly — one `shadow-sm` on boxes, one drop-shadow on toasts. The rest is flat tonal layers.

## Colors

Source of truth: `resources/css/app.css` `@theme` block (Tailwind v4).

### Palettes

- **Primary / Coollabs (`#6b16ed`)** — brand purple. Light-mode accent. Used for focus rings, active states, highlighted buttons, spinners, scrollbar thumb. Scale: `coollabs-50 #f5f0ff` (backgrounds), `coollabs #6b16ed` (base), `coollabs-100 #7317ff` (dark-mode button hover), `coollabs-200 #5a12c7` (light-mode text), `coollabs-300 #4a0fa3` (deepest).
- **Warning (`#fcd452`)** — dark-mode accent + callout palette. Full yellow scale `warning-50` through `warning-900`. Swaps in for coollabs under `.dark`.
- **Coolgray (dark surface ladder)** — five shades building dark-mode depth: `base #101010` (page) → `coolgray-100 #181818` (components) → `-200 #202020` (elevated / active nav) → `-300 #242424` (input borders, button borders) → `-400 #282828` (tooltips) → `-500 #323232` (subtle overlays).
- **Semantic** — `success #22C55E` for running/healthy, `error #dc2626` for stopped/danger.
- **Light surfaces** — `gray-50 #f9fafb` (page), `white` (components), `neutral-200 #e5e5e5` (borders), `neutral-500 #737373` (muted text), `neutral-300 #d4d4d4` (placeholders).

### Dark-mode heading rule (critical)

Body default text in dark mode is `neutral-400 #a3a3a3`. Headings and card titles MUST explicitly force `text-white` — otherwise they render near-invisible on `coolgray-100 #181818`. This is enforced globally: `h1–h4` all have `dark:text-white` in `app.css`.

### Default border override

Tailwind v4 defaults `border-color` to `currentcolor`. Coolify overrides it in `@layer base`:

```css
*, ::after, ::before, ::backdrop, ::file-selector-button {
  border-color: var(--color-coolgray-200, currentcolor);
}
```

So any `border` utility without an explicit color gets `coolgray-200 #202020` in dark mode.

## Typography

Fonts loaded in `resources/css/fonts.css` (all `woff2`, `font-display: swap`):

- **Geist Sans** — primary UI font. Variable weight `100 900`. Inter as fallback (static weights 100–900).
- **Geist Mono** — monospace for code, logs, textareas. Variable weight `100 900`.

Applied via `@theme`:

```css
--font-sans: 'Geist Sans', Inter, sans-serif;
--font-mono: 'Geist Mono', 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
--font-logs: 'Geist Mono', 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
```

### Heading hierarchy (Tailwind utilities)

| Element | Utility |
|---|---|
| `h1` | `text-3xl font-bold dark:text-white` |
| `h2` | `text-xl font-bold dark:text-white` |
| `h3` | `text-lg font-bold dark:text-white` |
| `h4` | `text-base font-bold dark:text-white` |

### Body

| Context | Utility |
|---|---|
| Body default | `text-sm font-sans antialiased` |
| Label | `text-sm font-medium` |
| Badge / status text | `text-xs font-bold` |
| Box description | `text-xs font-bold text-neutral-500` |
| Caption / kbd | `text-xs` |

## Layout

Fixed left sidebar layout on desktop. Mobile collapses to a sticky top bar with hamburger menu overlay.

### Structure

- **Sidebar** — fixed, `w-56` (14rem / 224px), `hidden lg:flex`. Inner `flex flex-col overflow-y-auto gap-y-5 scrollbar`. Nav `bg-white dark:bg-base border-r`.
- **Main content** — `lg:pl-56` offset. Inner padding `p-4 sm:px-6 lg:px-8 lg:py-6`.
- **Mobile top bar** — `sticky top-0 z-40 lg:hidden` with `bg-white/95 dark:bg-base/95 backdrop-blur-sm`.

### Spacing scale

| Token | Value | Use |
|---|---|---|
| `p-2` | 0.5rem | Component internal padding |
| `p-4` | 1rem | Callout padding |
| `py-1.5` | 0.375rem | Input vertical padding |
| `h-8` | 2rem | Button height |
| `px-2` | 0.5rem | Button horizontal padding |
| `gap-2` | 0.5rem | Button gap |
| `px-2 py-1` | 0.25rem / 0.5rem | Menu item padding |
| `gap-3` | 0.75rem | Menu item gap |
| `mb-12` | 3rem | Section margin |
| `min-h-[4rem]` | 4rem | Card min-height |

No grid system — flex layouts everywhere.

## Elevation & Depth

**Flat + tonal.** Hierarchy comes from background color, not shadows.

### Dark tonal ladder

```
#101010 (base)          page background
  #181818 (coolgray-100) cards, inputs, components
    #202020 (coolgray-200) elevated surfaces, borders, nav active
      #242424 (coolgray-300) input borders, button borders
        #282828 (coolgray-400) tooltips, hover states
          #323232 (coolgray-500) subtle overlays
```

### Light tonal ladder

```
#f9fafb (gray-50)       page background
  #ffffff (white)        cards, inputs, components
    #e5e5e5 (neutral-200) borders
      #f5f5f5 (neutral-100) hover backgrounds
        #d4d4d4 (neutral-300) deeper hover, nav active
```

### Shadows (used sparingly)

- Boxes: `shadow-sm` (`0 1px 2px 0 rgba(0,0,0,0.05)`)
- Toasts: `shadow-[0_5px_15px_-3px_rgb(0_0_0_/_0.08)]`
- Slide-over: `shadow-lg`
- Modal-input: `drop-shadow-sm`

### Input inset box-shadow system (distinctive)

Inputs and selects use `box-shadow` instead of `border` — this enables the 4px left dirty-bar indicator:

```css
/* default */  box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #e5e5e5;
/* default dark */  inset 4px 0 0 transparent, inset 0 0 0 2px #242424;
/* focus light */  inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5;
/* focus dark */  inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424;
/* dirty (same as focus) — set via wire:dirty.class */
/* disabled / readonly */  box-shadow: none;
```

Variant `input-sticky` uses `1px` outer shadow instead of `2px`.

### Focus ring (buttons, links, checkboxes, non-input)

`focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base`

## Shapes

- **Default** — `rounded-sm` (2px). Everything: inputs, buttons, cards, modals, toasts, dropdowns.
- **Coolbox** — `rounded` (4px). Alternate card style with ring-hover.
- **Callouts** — `rounded-lg` (8px). Only exception to the sharp rule.
- **Badges / deprecated badge / pills / avatars** — `rounded-full`.

Never mix radii within the same view.

## Components

All component classes live in `resources/css/utilities.css` as `@utility` blocks, consumed by Blade components under `resources/views/components/`.

### Forms

#### Button

Utility `.button` (`resources/css/utilities.css`):

```
flex gap-2 justify-center items-center px-2 h-8 text-sm text-black normal-case rounded-sm border-2 outline-0 cursor-pointer font-medium bg-white border-neutral-200 hover:bg-neutral-100 dark:bg-coolgray-100 dark:text-white dark:hover:text-white dark:hover:bg-coolgray-200 dark:border-coolgray-300 hover:text-black disabled:cursor-not-allowed min-w-fit dark:disabled:text-neutral-600 disabled:border-transparent disabled:hover:bg-transparent disabled:bg-transparent disabled:text-neutral-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base
```

Attribute variants (in `app.css`):

- `button[isHighlighted]` → `text-coollabs-200 dark:text-white bg-coollabs-50 dark:bg-coollabs/20 border-coollabs dark:border-coollabs-100 hover:bg-coollabs hover:text-white dark:hover:bg-coollabs-100 dark:hover:text-white`
- `button[isError]` → `text-red-800 dark:text-red-300 bg-red-50 dark:bg-red-900/30 border-red-300 dark:border-red-800 hover:bg-red-300 hover:text-white dark:hover:bg-red-800 dark:hover:text-white`

Loading: `<x-loading-on-button>` — inline `w-4 h-4 dark:text-warning animate-spin` SVG.

#### Input

Utility chain `.input-select` → `.input`:

```
block py-1.5 w-full text-sm text-black rounded-sm border-0 dark:bg-coolgray-100 dark:text-white disabled:bg-neutral-200 disabled:text-neutral-500 dark:disabled:bg-coolgray-100/40 placeholder:text-neutral-300 dark:placeholder:text-neutral-700 read-only:text-neutral-500 read-only:bg-neutral-200 dark:read-only:text-neutral-500 dark:read-only:bg-coolgray-100/40 focus-visible:outline-none
```

Plus the inset box-shadow system (see Elevation). Password variant: `.input[type="password"]` gets `pr-[2.4rem]` for the eye icon.

**Dirty indicator.** Livewire sets the focus-colored shadow via `wire:dirty.class`:

```blade
wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]"
```

Variant `.input-sticky` — same shape, `1px` outer shadow (thinner border).

#### Select

Extends `.input-select` + custom SVG dropdown arrow:

```css
background-image: url("data:image/svg+xml,...stroke='%23000000'...");
padding-right: 2.5rem;
```

Dark mode swaps the SVG stroke to `%23ffffff`.

#### Checkbox

Input class:
```
dark:border-neutral-700 text-coolgray-400 dark:bg-coolgray-100 rounded-sm cursor-pointer dark:disabled:bg-base dark:disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base
```

Container:
```
form-control flex max-w-full flex-row items-center gap-4 py-1 pr-2 dark:hover:bg-coolgray-100 cursor-pointer
```

#### Textarea

Uses the same `input` utility + `font-mono` + dirty-bar via `wire:dirty.class` (identical to input). Optional `@keydown.tab=handleKeydown` inserts 2 spaces on Tab.

#### Copy-Button

`resources/views/components/forms/copy-button.blade.php` — readonly `.input` with an absolute-positioned copy icon right-side. Copied state shows a green check (`text-green-500`) for 1 second. Only renders in secure contexts (`window.isSecureContext`).

### Containers

#### Box

Utility `.box`:
```
relative flex lg:flex-row flex-col p-2 transition-colors cursor-pointer min-h-[4rem] dark:bg-coolgray-100 shadow-sm bg-white border text-black dark:text-white hover:text-black border-neutral-200 dark:border-coolgray-300 hover:bg-neutral-100 dark:hover:bg-coollabs-100 dark:hover:text-white hover:no-underline rounded-sm
```

**Critical child text rule.** On dark hover, background becomes purple `#7317ff` — description text `#737373` disappears. Utilities `.box-title` and `.box-description` include `dark:group-hover:text-white group-hover:text-black` to flip text contrast.

Variants: `.box-boarding`, `.box-without-bg`, `.box-without-bg-without-border`.

#### Coolbox

Utility `.coolbox`:
```
relative flex transition-all duration-150 dark:bg-coolgray-100 bg-white p-2 rounded border border-neutral-200 dark:border-coolgray-400 hover:ring-2 dark:hover:ring-warning hover:ring-coollabs cursor-pointer min-h-[4rem]
```

Distinguished by `rounded` (4px, not 2px) and **ring-hover** instead of background change.

### Status & Badges

#### Badge base

```
inline-block w-3 h-3 text-xs font-bold rounded-full leading-none border border-neutral-200 dark:border-black
```

Fill utilities: `.badge-success` (`bg-success`), `.badge-warning` (`bg-warning`), `.badge-error` (`bg-error`). Dashboard variant `.badge-dashboard` is `absolute top-1 right-1 w-2.5 h-2.5`.

#### Status indicator pattern

Badge + label side-by-side. Components in `resources/views/components/status/`:

| Component | Badge | Text color | Loading? |
|---|---|---|---|
| `status/running` | `badge-success` | `text-success` (`#22C55E`) | Swaps to `badge-warning` while checking proxy |
| `status/degraded` | `badge-warning` | `dark:text-warning` (`#fcd452`) | `<x-loading>` + `wire:loading.delay.longer` |
| `status/restarting` | `badge-warning` | `dark:text-warning` | `<x-loading>` |
| `status/stopped` | `badge-error` | `text-error` (`#dc2626`) | `<x-loading>` |

Layout: `<div class="flex items-center">` → badge → `<div class="pl-2 pr-1 text-xs font-bold {color}">{label}</div>` → optional `({health})` in same color.

#### Deprecated Badge

`resources/views/components/deprecated-badge.blade.php`:
```
px-2 py-0.5 text-xs font-medium leading-normal rounded-full bg-warning/15 text-warning border border-warning/30
```

#### Tag

Utility `.tag`:
```
px-2 py-1 cursor-pointer box-description dark:bg-coolgray-100 dark:hover:bg-coolgray-300 bg-neutral-100 hover:bg-neutral-200
```

### Overlays

#### Callout

Four types (`warning`, `danger`, `info`, `success`). Base: `relative p-4 border rounded-lg`.

| Type | Background | Border | Title text | Body text |
|---|---|---|---|---|
| warning | `bg-warning-50 dark:bg-warning-900/30` | `border-warning-300 dark:border-warning-800` | `text-warning-800 dark:text-warning-300` | `text-warning-700 dark:text-warning-200` |
| danger | `bg-red-50 dark:bg-red-900/30` | `border-red-300 dark:border-red-800` | `text-red-800 dark:text-red-300` | `text-red-700 dark:text-red-200` |
| info | `bg-blue-50 dark:bg-blue-900/30` | `border-blue-300 dark:border-blue-800` | `text-blue-800 dark:text-blue-300` | `text-blue-700 dark:text-blue-200` |
| success | `bg-green-50 dark:bg-green-900/30` | `border-green-300 dark:border-green-800` | `text-green-800 dark:text-green-300` | `text-green-700 dark:text-green-200` |

Icon colors (600 light / 400 dark) match type.

#### Modal (input variant)

`resources/views/components/modal.blade.php`:
```
relative w-full lg:w-auto lg:min-w-2xl lg:max-w-4xl border rounded-sm drop-shadow-sm bg-white border-neutral-200 dark:bg-base dark:border-coolgray-300 flex flex-col
```

Backdrop: `bg-black/20 backdrop-blur-xs`. Close button: `w-8 h-8 rounded-full hover:bg-neutral-100 dark:hover:bg-coolgray-300` top-right, 24px `stroke-width=1.5` X icon.

#### Modal Confirmation

`resources/views/components/modal-confirmation.blade.php` — destructive-action 2-or-3-step wizard (checkboxes → confirm text → password):
```
relative w-full border rounded-none sm:rounded-sm min-w-full lg:min-w-[36rem] max-w-full sm:max-w-[48rem] h-screen sm:h-auto max-h-screen sm:max-h-[calc(100vh-2rem)] bg-neutral-100 border-neutral-400 dark:bg-base dark:border-coolgray-300 flex flex-col
```

Uses `<x-callout type="danger">` for warning. Password step hidden for OAuth users.

#### Confirm Modal

`resources/views/components/confirm-modal.blade.php` — Livewire-bound simpler confirm dialog.

#### Popup / Popup-Small

Fixed bottom-right notification card with title / description / action button. `bg-white dark:bg-coolgray-100 border dark:border-coolgray-300 shadow-lg sm:rounded-sm`. Popup is responsive max-w-4xl, Popup-Small is `max-w-[46rem]`.

#### Slide-Over

`resources/views/components/slide-over.blade.php`:

Outer: `fixed inset-y-0 right-0 flex max-w-full pl-10`

Panel: `max-w-xl w-screen flex flex-col h-full py-6 overflow-hidden border-l shadow-lg bg-neutral-50 dark:bg-base dark:border-neutral-800 border-neutral-200`

#### Toast

`resources/views/components/toast.blade.php` — Alpine-powered stacked toast system.

- Container: `fixed ... sm:max-w-xs z-9999`, positioned via `position` param (`top-right` / `top-left` / `top-center` / `bottom-right` / `bottom-left` / `bottom-center`).
- Toast shell: `relative flex flex-col items-start shadow-[0_5px_15px_-3px_rgb(0_0_0_/_0.08)] w-full dark:bg-coolgray-100 bg-white dark:border dark:border-coolgray-200 rounded-sm sm:max-w-xs`.
- Stacks up to 4 (oldest gets scale 82% then burns).
- Auto-dismiss after 4 s. Hover on container pauses dismissal and expands stack.
- HTML payload sanitized via `window.sanitizeHTML` (XSS guard).
- Per-toast copy-to-clipboard + close buttons.

Icon colors:

| Type | Class |
|---|---|
| success | `text-green-500` |
| info | `text-blue-500` |
| warning | `text-orange-400` |
| danger | `text-red-500` |
| default | `text-gray-800` |

#### Helper / Tooltip

`resources/views/components/helper.blade.php`. Icon utility `.info-helper`:
```
cursor-pointer text-coollabs dark:text-warning
```

Popup utility `.info-helper-popup`:
```
hidden absolute z-40 text-xs rounded-sm text-neutral-700 group-hover:block dark:border-coolgray-500 border-neutral-900 dark:bg-coolgray-400 bg-neutral-200 dark:text-neutral-300 max-w-sm whitespace-normal break-words
```

Shown on parent `.group:hover`. Supports rich HTML (links colored `text-coollabs dark:text-warning underline`).

### Navigation

#### Sidebar / Navbar

Component: `resources/views/components/navbar.blade.php`.

Root nav: `flex flex-col flex-1 px-2 bg-white border-r dark:border-coolgray-200 border-neutral-300 dark:bg-base`

Menu list: `flex flex-col flex-1 gap-y-7` → inner `flex flex-col h-full space-y-1.5`.

Utility `.menu-item`:
```
flex gap-3 items-center px-2 py-1 w-full text-sm dark:hover:bg-coolgray-100 dark:hover:text-white hover:bg-neutral-300 rounded-sm truncate min-w-0
```

Utility `.menu-item-active`:
```
text-black rounded-sm dark:bg-coolgray-200 dark:text-warning bg-neutral-200 overflow-hidden
```

Icon `.menu-item-icon`: `flex-shrink-0 w-6 h-6 dark:hover:text-white`. Sub-items use `gap-2` + `w-4 h-4` icons.

#### Breadcrumbs

`resources/views/components/resources/breadcrumbs.blade.php` — project → environment → resource trail. Desktop: `<ol class="hidden flex-wrap items-center gap-y-1 md:flex">`. Each link `text-xs lg:text-sm hover:text-warning`. Chevron buttons `text-warning`. Dropdowns `absolute ... bg-white dark:bg-coolgray-100 rounded-md shadow-lg border`. Active item `dark:text-warning font-semibold`.

#### External-Link

Mini icon — `inline-flex w-3 h-3 dark:text-neutral-400 text-black` with arrow-out-of-box SVG. Appended to external anchors.

#### Internal-Link

Arrow SVG — `inline-flex w-4 h-4 text-black dark:text-white`. Used in CTA links ("go to deployment" etc).

#### Banner

`resources/views/components/banner.blade.php` — dismissible top bar:
```
relative z-999 w-full py-2 mx-auto duration-100 ease-out shadow-xs bg-coolgray-100 sm:py-0 sm:h-14
```

Close button: `w-6 h-6 rounded-full hover:bg-coolgray-500 text-neutral-200`. Reveals via Alpine `x-transition` after 100ms delay.

### Feedback

#### Loading Spinner

`resources/views/components/loading.blade.php` — inline flex with optional text + spinning SVG:
```
w-4 h-4 mx-1 ml-3 text-coollabs dark:text-warning animate-spin
```

SVG has two paths at `opacity-25` (track) + `opacity-75` (arc).

Utility `.loading`: `w-4 dark:text-warning text-coollabs`.

#### Loading-On-Button

`resources/views/components/loading-on-button.blade.php` — same SVG but **no light-mode color** (`w-4 h-4 mx-1 ml-3 dark:text-warning animate-spin`), meant to inherit button text color.

#### Page-Loading

Full-page loader overlay (variant of `loading` component, fills viewport).

### Text

#### Highlighted text

`resources/views/components/highlighted.blade.php` / utility `.text-helper`:
```
inline-block font-bold text-coollabs dark:text-warning
```

Also used for required-field asterisks via `<x-highlighted text="*" />`.

#### Kbd

Utility `.kbd-custom`:
```
px-2 text-xs rounded-sm border border-dashed border-neutral-700 dark:text-warning
```

### Chrome

#### Scrollbar

Utility `.scrollbar` (uses `tailwind-scrollbar` plugin):
```
scrollbar-thumb-coollabs-100 scrollbar-track-neutral-200 dark:scrollbar-track-coolgray-200 scrollbar-thin
```

Applied globally to `<body>` in `app.css`.

#### Table

Styled via base element rules in `app.css` (not a reusable component):

```css
table       { @apply min-w-full divide-y dark:divide-coolgray-200 divide-neutral-300; }
thead       { @apply uppercase; }
tbody       { @apply divide-y dark:divide-coolgray-200 divide-neutral-300; }
tr          { @apply text-black dark:text-neutral-400 dark:hover:bg-coolgray-300 hover:bg-neutral-100; }
tr th       { @apply px-3 py-3.5 text-left text-black dark:text-white; }
tr th:first-child { @apply py-3.5 pr-3 pl-4 sm:pl-6; }
tr td       { @apply px-3 py-4 whitespace-nowrap; }
tr td:first-child { @apply pr-3 pl-4 font-bold sm:pl-6; }
```

#### Dropdown

`resources/views/components/dropdown.blade.php`. Container:
```
border border-neutral-300 bg-white p-1 shadow-sm dark:border-coolgray-300 dark:bg-coolgray-200
```

Utility `.dropdown-item`:
```
flex relative gap-2 justify-start items-center py-1 pr-4 pl-2 w-full text-xs transition-colors cursor-pointer select-none dark:text-white hover:bg-neutral-100 dark:hover:bg-coollabs outline-none data-disabled:pointer-events-none data-disabled:opacity-50 focus-visible:bg-neutral-100 dark:focus-visible:bg-coollabs
```

Touch variant adds `min-h-10 px-3 py-2 text-sm`.

## Do's and Don'ts

- **Do** force `dark:text-white` on h1–h4 and card titles. Default body text `#a3a3a3` is unreadable on `coolgray-100`.
- **Do** swap the accent: `coollabs` in light, `warning` in dark. For focus rings, active nav, helpers, spinners, highlighted text, scrollbar thumb, helper links.
- **Do** use the inset box-shadow system on inputs, selects, and textareas — not a border. It enables the 4px left dirty-bar.
- **Do** wire the dirty indicator via `wire:dirty.class` so Livewire flips the bar color on modified state.
- **Do** flip `.box-title` and `.box-description` to the contrast color on hover. On dark hover the card goes purple `#7317ff`; `text-neutral-500` description becomes invisible.
- **Do** maintain WCAG AA contrast (4.5:1 for normal text).
- **Do** sanitize HTML passed into toasts via `window.sanitizeHTML`.
- **Do** use `<x-loading>` for in-button spinners and as `wire:loading.delay.longer` indicators in status components.
- **Don't** use purple `coollabs` as the dark-mode accent. Always use yellow `warning` in dark.
- **Don't** mix corner radii — 2px everywhere except callouts (8px) and pills (full).
- **Don't** use shadows for elevation in dark mode. Use tonal layers from the coolgray ladder.
- **Don't** set `border` utilities without expecting `coolgray-200` in dark (default override in base layer).
- **Don't** add gradients. The one exception is the `.bg-coollabs-gradient` upsell strip.
- **Don't** use more than two font weights on a single screen (typically 400 body + 700 bold).

---

Source files:
- Theme tokens: `resources/css/app.css` (`@theme` block)
- Fonts: `resources/css/fonts.css`
- Component utilities: `resources/css/utilities.css`
- Blade components: `resources/views/components/**/*.blade.php`
