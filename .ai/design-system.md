# Coolify Design System

> **Purpose**: AI/LLM-consumable reference for replicating Coolify's visual design in new applications. Contains design tokens, component styles, and interactive states — with both Tailwind CSS classes and plain CSS equivalents.

---

## 1. Design Tokens

### 1.1 Colors

#### Brand / Accent

| Token | Hex | Usage |
|---|---|---|
| `coollabs` | `#6b16ed` | Primary accent (light mode) |
| `coollabs-50` | `#f5f0ff` | Highlighted button bg (light) |
| `coollabs-100` | `#7317ff` | Highlighted button hover (dark) |
| `coollabs-200` | `#5a12c7` | Highlighted button text (light) |
| `coollabs-300` | `#4a0fa3` | Deepest brand shade |
| `warning` / `warning-400` | `#fcd452` | Primary accent (dark mode) |

#### Warning Scale (used for dark-mode accent + callouts)

| Token | Hex |
|---|---|
| `warning-50` | `#fefce8` |
| `warning-100` | `#fef9c3` |
| `warning-200` | `#fef08a` |
| `warning-300` | `#fde047` |
| `warning-400` | `#fcd452` |
| `warning-500` | `#facc15` |
| `warning-600` | `#ca8a04` |
| `warning-700` | `#a16207` |
| `warning-800` | `#854d0e` |
| `warning-900` | `#713f12` |

#### Neutral Grays (dark mode backgrounds)

| Token | Hex | Usage |
|---|---|---|
| `base` | `#101010` | Page background (dark) |
| `coolgray-100` | `#181818` | Component background (dark) |
| `coolgray-200` | `#202020` | Elevated surface / borders (dark) |
| `coolgray-300` | `#242424` | Input border shadow / hover (dark) |
| `coolgray-400` | `#282828` | Tooltip background (dark) |
| `coolgray-500` | `#323232` | Subtle hover overlays (dark) |

#### Semantic

| Token | Hex | Usage |
|---|---|---|
| `success` | `#22C55E` | Running status, success alerts |
| `error` | `#dc2626` | Stopped status, danger actions, error alerts |

#### Light Mode Defaults

| Element | Color |
|---|---|
| Page background | `gray-50` (`#f9fafb`) |
| Component background | `white` (`#ffffff`) |
| Borders | `neutral-200` (`#e5e5e5`) |
| Primary text | `black` (`#000000`) |
| Muted text | `neutral-500` (`#737373`) |
| Placeholder text | `neutral-300` (`#d4d4d4`) |

### 1.2 Typography

**Font family**: Inter, sans-serif (weights 100–900, woff2, `font-display: swap`)

#### Heading Hierarchy

> **CRITICAL**: All headings and titles (h1–h4, card titles, modal titles) MUST be `white` (`#fff`) in dark mode. The default body text color is `neutral-400` (`#a3a3a3`) — headings must override this to white or they will be nearly invisible on dark backgrounds.

| Element | Tailwind | Plain CSS (light) | Plain CSS (dark) |
|---|---|---|---|
| `h1` | `text-3xl font-bold dark:text-white` | `font-size: 1.875rem; font-weight: 700; color: #000;` | `color: #fff;` |
| `h2` | `text-xl font-bold dark:text-white` | `font-size: 1.25rem; font-weight: 700; color: #000;` | `color: #fff;` |
| `h3` | `text-lg font-bold dark:text-white` | `font-size: 1.125rem; font-weight: 700; color: #000;` | `color: #fff;` |
| `h4` | `text-base font-bold dark:text-white` | `font-size: 1rem; font-weight: 700; color: #000;` | `color: #fff;` |

#### Body Text

| Context | Tailwind | Plain CSS |
|---|---|---|
| Body default | `text-sm antialiased` | `font-size: 0.875rem; line-height: 1.25rem; -webkit-font-smoothing: antialiased;` |
| Labels | `text-sm font-medium` | `font-size: 0.875rem; font-weight: 500;` |
| Badge/status text | `text-xs font-bold` | `font-size: 0.75rem; line-height: 1rem; font-weight: 700;` |
| Box description | `text-xs font-bold text-neutral-500` | `font-size: 0.75rem; font-weight: 700; color: #737373;` |

### 1.3 Spacing Patterns

| Context | Value | CSS |
|---|---|---|
| Component internal padding | `p-2` | `padding: 0.5rem;` |
| Callout padding | `p-4` | `padding: 1rem;` |
| Input vertical padding | `py-1.5` | `padding-top: 0.375rem; padding-bottom: 0.375rem;` |
| Button height | `h-8` | `height: 2rem;` |
| Button horizontal padding | `px-2` | `padding-left: 0.5rem; padding-right: 0.5rem;` |
| Button gap | `gap-2` | `gap: 0.5rem;` |
| Menu item padding | `px-2 py-1` | `padding: 0.25rem 0.5rem;` |
| Menu item gap | `gap-3` | `gap: 0.75rem;` |
| Section margin | `mb-12` | `margin-bottom: 3rem;` |
| Card min-height | `min-h-[4rem]` | `min-height: 4rem;` |

### 1.4 Border Radius

| Context | Tailwind | Plain CSS |
|---|---|---|
| Default (inputs, buttons, cards, modals) | `rounded-sm` | `border-radius: 0.125rem;` |
| Callouts | `rounded-lg` | `border-radius: 0.5rem;` |
| Badges | `rounded-full` | `border-radius: 9999px;` |
| Cards (coolbox variant) | `rounded` | `border-radius: 0.25rem;` |

### 1.5 Shadows

#### Input / Select Box-Shadow System

Coolify uses **inset box-shadows instead of borders** for inputs and selects. This enables a unique "dirty indicator" — a colored left-edge bar.

```css
/* Default state */
box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #e5e5e5;

/* Default state (dark) */
box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #242424;

/* Focus state (light) — purple left bar */
box-shadow: inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5;

/* Focus state (dark) — yellow left bar */
box-shadow: inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424;

/* Dirty (modified) state — same as focus */
box-shadow: inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5;  /* light */
box-shadow: inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424;  /* dark */

/* Disabled / Readonly */
box-shadow: none;
```

#### Input-Sticky Variant (thinner border)

```css
/* Uses 1px border instead of 2px */
box-shadow: inset 4px 0 0 transparent, inset 0 0 0 1px #e5e5e5;
```

### 1.6 Focus Ring System

All interactive elements (buttons, links, checkboxes) share this focus pattern:

**Tailwind:**
```
focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base
```

**Plain CSS:**
```css
:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px #101010, 0 0 0 4px #6b16ed; /* light */
}

/* dark mode */
.dark :focus-visible {
  box-shadow: 0 0 0 2px #101010, 0 0 0 4px #fcd452;
}
```

> **Note**: Inputs use the inset box-shadow system (section 1.5) instead of the ring system.

---

## 2. Dark Mode Strategy

- **Toggle method**: Class-based — `.dark` class on `<html>` element
- **CSS variant**: `@custom-variant dark (&:where(.dark, .dark *));`
- **Default border override**: All elements default to `border-color: var(--color-coolgray-200)` (`#202020`) instead of `currentcolor`

### Accent Color Swap

| Context | Light | Dark |
|---|---|---|
| Primary accent | `coollabs` (`#6b16ed`) | `warning` (`#fcd452`) |
| Focus ring | `ring-coollabs` | `ring-warning` |
| Input focus bar | `#6b16ed` (purple) | `#fcd452` (yellow) |
| Active nav text | `text-black` | `text-warning` |
| Helper/highlight text | `text-coollabs` | `text-warning` |
| Loading spinner | `text-coollabs` | `text-warning` |
| Scrollbar thumb | `coollabs-100` | `coollabs-100` |

### Background Hierarchy (dark)

```
#101010 (base)          — page background
  └─ #181818 (coolgray-100) — cards, inputs, components
       └─ #202020 (coolgray-200) — elevated surfaces, borders, nav active
            └─ #242424 (coolgray-300) — input borders (via box-shadow), button borders
                 └─ #282828 (coolgray-400) — tooltips, hover states
                      └─ #323232 (coolgray-500) — subtle overlays
```

### Background Hierarchy (light)

```
#f9fafb (gray-50)       — page background
  └─ #ffffff (white)      — cards, inputs, components
       └─ #e5e5e5 (neutral-200) — borders
            └─ #f5f5f5 (neutral-100) — hover backgrounds
                 └─ #d4d4d4 (neutral-300) — deeper hover, nav active
```

---

## 3. Component Catalog

### 3.1 Button

#### Default

**Tailwind:**
```
flex gap-2 justify-center items-center px-2 h-8 text-sm text-black normal-case rounded-sm
border-2 outline-0 cursor-pointer font-medium bg-white border-neutral-200 hover:bg-neutral-100
dark:bg-coolgray-100 dark:text-white dark:hover:text-white dark:hover:bg-coolgray-200
dark:border-coolgray-300 hover:text-black disabled:cursor-not-allowed min-w-fit
dark:disabled:text-neutral-600 disabled:border-transparent disabled:hover:bg-transparent
disabled:bg-transparent disabled:text-neutral-300
focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs
dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base
```

**Plain CSS:**
```css
.button {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  align-items: center;
  padding: 0 0.5rem;
  height: 2rem;
  font-size: 0.875rem;
  font-weight: 500;
  text-transform: none;
  color: #000;
  background: #fff;
  border: 2px solid #e5e5e5;
  border-radius: 0.125rem;
  outline: 0;
  cursor: pointer;
  min-width: fit-content;
}
.button:hover { background: #f5f5f5; }

/* Dark */
.dark .button {
  background: #181818;
  color: #fff;
  border-color: #242424;
}
.dark .button:hover {
  background: #202020;
  color: #fff;
}

/* Disabled */
.button:disabled {
  cursor: not-allowed;
  border-color: transparent;
  background: transparent;
  color: #d4d4d4;
}
.dark .button:disabled { color: #525252; }
```

#### Highlighted (Primary Action)

**Tailwind** (via `isHighlighted` attribute):
```
text-coollabs-200 dark:text-white bg-coollabs-50 dark:bg-coollabs/20
border-coollabs dark:border-coollabs-100 hover:bg-coollabs hover:text-white
dark:hover:bg-coollabs-100 dark:hover:text-white
```

**Plain CSS:**
```css
.button-highlighted {
  color: #5a12c7;
  background: #f5f0ff;
  border-color: #6b16ed;
}
.button-highlighted:hover {
  background: #6b16ed;
  color: #fff;
}
.dark .button-highlighted {
  color: #fff;
  background: rgba(107, 22, 237, 0.2);
  border-color: #7317ff;
}
.dark .button-highlighted:hover {
  background: #7317ff;
  color: #fff;
}
```

#### Error / Danger

**Tailwind** (via `isError` attribute):
```
text-red-800 dark:text-red-300 bg-red-50 dark:bg-red-900/30
border-red-300 dark:border-red-800 hover:bg-red-300 hover:text-white
dark:hover:bg-red-800 dark:hover:text-white
```

**Plain CSS:**
```css
.button-error {
  color: #991b1b;
  background: #fef2f2;
  border-color: #fca5a5;
}
.button-error:hover {
  background: #fca5a5;
  color: #fff;
}
.dark .button-error {
  color: #fca5a5;
  background: rgba(127, 29, 29, 0.3);
  border-color: #991b1b;
}
.dark .button-error:hover {
  background: #991b1b;
  color: #fff;
}
```

#### Loading Indicator

Buttons automatically show a spinner (SVG with `animate-spin`) next to their content during async operations. The spinner uses the accent color (`text-coollabs` / `text-warning`).

---

### 3.2 Input

**Tailwind:**
```
block py-1.5 w-full text-sm text-black rounded-sm border-0
dark:bg-coolgray-100 dark:text-white
disabled:bg-neutral-200 disabled:text-neutral-500 dark:disabled:bg-coolgray-100/40
dark:read-only:text-neutral-500 dark:read-only:bg-coolgray-100/40
placeholder:text-neutral-300 dark:placeholder:text-neutral-700
read-only:text-neutral-500 read-only:bg-neutral-200
focus-visible:outline-none
```

**Plain CSS:**
```css
.input {
  display: block;
  padding: 0.375rem 0.5rem;
  width: 100%;
  font-size: 0.875rem;
  color: #000;
  background: #fff;
  border: 0;
  border-radius: 0.125rem;
  box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #e5e5e5;
}
.input:focus-visible {
  outline: none;
  box-shadow: inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5;
}
.input::placeholder { color: #d4d4d4; }
.input:disabled { background: #e5e5e5; color: #737373; box-shadow: none; }
.input:read-only { color: #737373; background: #e5e5e5; box-shadow: none; }
.input[type="password"] { padding-right: 2.4rem; }

/* Dark */
.dark .input {
  background: #181818;
  color: #fff;
  box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #242424;
}
.dark .input:focus-visible {
  box-shadow: inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424;
}
.dark .input::placeholder { color: #404040; }
.dark .input:disabled { background: rgba(24, 24, 24, 0.4); box-shadow: none; }
.dark .input:read-only { color: #737373; background: rgba(24, 24, 24, 0.4); box-shadow: none; }
```

#### Dirty (Modified) State

When an input value has been changed but not saved, a 4px colored left bar appears via box-shadow — same colors as focus state. This provides a visual indicator that the field has unsaved changes.

---

### 3.3 Select

Same base styles as Input, plus a custom dropdown arrow SVG:

**Tailwind:**
```
w-full block py-1.5 text-sm text-black rounded-sm border-0
dark:bg-coolgray-100 dark:text-white
disabled:bg-neutral-200 disabled:text-neutral-500 dark:disabled:bg-coolgray-100/40
focus-visible:outline-none
```

**Additional plain CSS for the dropdown arrow:**
```css
.select {
  /* ...same as .input base... */
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='%23000000'%3e%3cpath stroke-linecap='round' stroke-linejoin='round' d='M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9'/%3e%3c/svg%3e");
  background-position: right 0.5rem center;
  background-repeat: no-repeat;
  background-size: 1rem 1rem;
  padding-right: 2.5rem;
  appearance: none;
}

/* Dark mode: white stroke arrow */
.dark .select {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='%23ffffff'%3e%3cpath stroke-linecap='round' stroke-linejoin='round' d='M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9'/%3e%3c/svg%3e");
}
```

---

### 3.4 Checkbox

**Tailwind:**
```
dark:border-neutral-700 text-coolgray-400 dark:bg-coolgray-100 rounded-sm cursor-pointer
dark:disabled:bg-base dark:disabled:cursor-not-allowed
focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs
dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base
```

**Container:**
```
flex flex-row items-center gap-4 pr-2 py-1 form-control min-w-fit
dark:hover:bg-coolgray-100 cursor-pointer
```

**Plain CSS:**
```css
.checkbox {
  border-color: #404040;
  color: #282828;
  background: #181818;
  border-radius: 0.125rem;
  cursor: pointer;
}
.checkbox:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px #101010, 0 0 0 4px #fcd452;
}

.checkbox-container {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 1rem;
  padding: 0.25rem 0.5rem 0.25rem 0;
  min-width: fit-content;
  cursor: pointer;
}
.dark .checkbox-container:hover { background: #181818; }
```

---

### 3.5 Textarea

Uses `font-mono` for monospace text. Supports tab key insertion (2 spaces).

**Important**: Large/multiline textareas should NOT use the inset box-shadow left-border system from `.input`. Use a simple border instead:

**Tailwind:**
```
block w-full text-sm text-black rounded-sm border border-neutral-200
dark:bg-coolgray-100 dark:text-white dark:border-coolgray-300
font-mono focus-visible:outline-none focus-visible:ring-2
focus-visible:ring-coollabs dark:focus-visible:ring-warning
focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base
```

**Plain CSS:**
```css
.textarea {
  display: block;
  width: 100%;
  font-size: 0.875rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  color: #000;
  background: #fff;
  border: 1px solid #e5e5e5;
  border-radius: 0.125rem;
}
.textarea:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px #fff, 0 0 0 4px #6b16ed;
}
.dark .textarea {
  background: #181818;
  color: #fff;
  border-color: #242424;
}
.dark .textarea:focus-visible {
  box-shadow: 0 0 0 2px #101010, 0 0 0 4px #fcd452;
}
```

> **Note**: The 4px inset left-border (dirty/focus indicator) is only for single-line inputs and selects, not textareas.

---

### 3.6 Box / Card

#### Standard Box

**Tailwind:**
```
relative flex lg:flex-row flex-col p-2 transition-colors cursor-pointer min-h-[4rem]
dark:bg-coolgray-100 shadow-sm bg-white border text-black dark:text-white hover:text-black
border-neutral-200 dark:border-coolgray-300 hover:bg-neutral-100
dark:hover:bg-coollabs-100 dark:hover:text-white hover:no-underline rounded-sm
```

**Plain CSS:**
```css
.box {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 0.5rem;
  min-height: 4rem;
  background: #fff;
  border: 1px solid #e5e5e5;
  border-radius: 0.125rem;
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  color: #000;
  cursor: pointer;
  transition: background-color 150ms, color 150ms;
  text-decoration: none;
}
.box:hover { background: #f5f5f5; color: #000; }

.dark .box {
  background: #181818;
  border-color: #242424;
  color: #fff;
}
.dark .box:hover {
  background: #7317ff;
  color: #fff;
}

/* IMPORTANT: child text must also turn white/black on hover,
   since description text (#737373) is invisible on purple bg */
.box:hover .box-title { color: #000; }
.box:hover .box-description { color: #000; }
.dark .box:hover .box-title { color: #fff; }
.dark .box:hover .box-description { color: #fff; }

/* Desktop: row layout */
@media (min-width: 1024px) {
  .box { flex-direction: row; }
}
```

#### Coolbox (Ring Hover)

**Tailwind:**
```
relative flex transition-all duration-150 dark:bg-coolgray-100 bg-white p-2 rounded
border border-neutral-200 dark:border-coolgray-400 hover:ring-2
dark:hover:ring-warning hover:ring-coollabs cursor-pointer min-h-[4rem]
```

**Plain CSS:**
```css
.coolbox {
  position: relative;
  display: flex;
  padding: 0.5rem;
  min-height: 4rem;
  background: #fff;
  border: 1px solid #e5e5e5;
  border-radius: 0.25rem;
  cursor: pointer;
  transition: all 150ms;
}
.coolbox:hover { box-shadow: 0 0 0 2px #6b16ed; }

.dark .coolbox {
  background: #181818;
  border-color: #282828;
}
.dark .coolbox:hover { box-shadow: 0 0 0 2px #fcd452; }
```

#### Box Text

> **IMPORTANT — Dark mode titles**: Card/box titles MUST be `#fff` (white) in dark mode, not the default body text color (`#a3a3a3` / neutral-400). A black or grey title is nearly invisible on dark backgrounds (`#181818`). This applies to all heading-level text inside cards.

```css
.box-title {
  font-weight: 700;
  color: #000;              /* light mode: black */
}
.dark .box-title {
  color: #fff;              /* dark mode: MUST be white, not grey */
}

.box-description {
  font-size: 0.75rem;
  font-weight: 700;
  color: #737373;
}
/* On hover: description must become visible against colored bg */
.box:hover .box-description { color: #000; }
.dark .box:hover .box-description { color: #fff; }
```

---

### 3.7 Badge / Status Indicator

**Tailwind:**
```
inline-block w-3 h-3 text-xs font-bold rounded-full leading-none
border border-neutral-200 dark:border-black
```

**Variants**: `badge-success` (`bg-success`), `badge-warning` (`bg-warning`), `badge-error` (`bg-error`)

**Plain CSS:**
```css
.badge {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 9999px;
  border: 1px solid #e5e5e5;
}
.dark .badge { border-color: #000; }

.badge-success { background: #22C55E; }
.badge-warning { background: #fcd452; }
.badge-error { background: #dc2626; }
```

#### Status Text Pattern

Status indicators combine a badge dot with text:

```html
<div style="display: flex; align-items: center;">
  <div class="badge badge-success"></div>
  <div style="padding-left: 0.5rem; font-size: 0.75rem; font-weight: 700; color: #22C55E;">
    Running
  </div>
</div>
```

| Status | Badge Class | Text Color |
|---|---|---|
| Running | `badge-success` | `text-success` (`#22C55E`) |
| Stopped | `badge-error` | `text-error` (`#dc2626`) |
| Degraded | `badge-warning` | `dark:text-warning` (`#fcd452`) |
| Restarting | `badge-warning` | `dark:text-warning` (`#fcd452`) |

---

### 3.8 Dropdown

**Container Tailwind:**
```
p-1 mt-1 bg-white border rounded-sm shadow-sm
dark:bg-coolgray-200 dark:border-coolgray-300 border-neutral-300
```

**Item Tailwind:**
```
flex relative gap-2 justify-start items-center py-1 pr-4 pl-2 w-full text-xs
transition-colors cursor-pointer select-none dark:text-white
hover:bg-neutral-100 dark:hover:bg-coollabs
outline-none focus-visible:bg-neutral-100 dark:focus-visible:bg-coollabs
```

**Plain CSS:**
```css
.dropdown {
  padding: 0.25rem;
  margin-top: 0.25rem;
  background: #fff;
  border: 1px solid #d4d4d4;
  border-radius: 0.125rem;
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.dark .dropdown {
  background: #202020;
  border-color: #242424;
}

.dropdown-item {
  display: flex;
  position: relative;
  gap: 0.5rem;
  justify-content: flex-start;
  align-items: center;
  padding: 0.25rem 1rem 0.25rem 0.5rem;
  width: 100%;
  font-size: 0.75rem;
  cursor: pointer;
  user-select: none;
  transition: background-color 150ms;
}
.dropdown-item:hover { background: #f5f5f5; }
.dark .dropdown-item { color: #fff; }
.dark .dropdown-item:hover { background: #6b16ed; }
```

---

### 3.9 Sidebar / Navigation

#### Sidebar Container + Page Layout

The navbar is a **fixed left sidebar** (14rem / 224px wide on desktop), with main content offset to the right.

**Tailwind (sidebar wrapper — desktop):**
```
hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-56 lg:flex-col min-w-0
```

**Tailwind (sidebar inner — scrollable):**
```
flex flex-col overflow-y-auto grow gap-y-5 scrollbar min-w-0
```

**Tailwind (nav element):**
```
flex flex-col flex-1 px-2 bg-white border-r dark:border-coolgray-200 border-neutral-300 dark:bg-base
```

**Tailwind (main content area):**
```
lg:pl-56
```

**Tailwind (main content padding):**
```
p-4 sm:px-6 lg:px-8 lg:py-6
```

**Tailwind (mobile top bar — shown on small screens, hidden on lg+):**
```
sticky top-0 z-40 flex items-center justify-between px-4 py-4 gap-x-6 sm:px-6 lg:hidden
bg-white/95 dark:bg-base/95 backdrop-blur-sm border-b border-neutral-300/50 dark:border-coolgray-200/50
```

**Tailwind (mobile hamburger icon):**
```
-m-2.5 p-2.5 dark:text-warning
```

**Plain CSS:**
```css
/* Sidebar — desktop only */
.sidebar {
  display: none;
}
@media (min-width: 1024px) {
  .sidebar {
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 50;
    width: 14rem;       /* 224px */
    min-width: 0;
  }
}

.sidebar-inner {
  display: flex;
  flex-direction: column;
  flex-grow: 1;
  overflow-y: auto;
  gap: 1.25rem;
  min-width: 0;
}

/* Nav element */
.sidebar-nav {
  display: flex;
  flex-direction: column;
  flex: 1;
  padding: 0 0.5rem;
  background: #fff;
  border-right: 1px solid #d4d4d4;
}
.dark .sidebar-nav {
  background: #101010;
  border-right-color: #202020;
}

/* Main content offset */
@media (min-width: 1024px) {
  .main-content { padding-left: 14rem; }
}

.main-content-inner {
  padding: 1rem;
}
@media (min-width: 640px) {
  .main-content-inner { padding: 1rem 1.5rem; }
}
@media (min-width: 1024px) {
  .main-content-inner { padding: 1.5rem 2rem; }
}

/* Mobile top bar — visible below lg breakpoint */
.mobile-topbar {
  position: sticky;
  top: 0;
  z-index: 40;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem;
  gap: 1.5rem;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(212, 212, 212, 0.5);
}
.dark .mobile-topbar {
  background: rgba(16, 16, 16, 0.95);
  border-bottom-color: rgba(32, 32, 32, 0.5);
}
@media (min-width: 1024px) {
  .mobile-topbar { display: none; }
}

/* Mobile sidebar overlay (shown when hamburger is tapped) */
.sidebar-mobile {
  position: relative;
  display: flex;
  flex: 1;
  width: 100%;
  max-width: 14rem;
  min-width: 0;
}
.sidebar-mobile-scroll {
  display: flex;
  flex-direction: column;
  padding-bottom: 0.5rem;
  overflow-y: auto;
  min-width: 14rem;
  gap: 1.25rem;
  min-width: 0;
}
.dark .sidebar-mobile-scroll { background: #181818; }
```

#### Sidebar Header (Logo + Search)

**Tailwind:**
```
flex lg:pt-6 pt-4 pb-4 pl-2
```

**Logo:**
```
text-2xl font-bold tracking-wide dark:text-white hover:opacity-80 transition-opacity
```

**Search button:**
```
flex items-center gap-1.5 px-2.5 py-1.5
bg-neutral-100 dark:bg-coolgray-100
border border-neutral-300 dark:border-coolgray-200
rounded-md hover:bg-neutral-200 dark:hover:bg-coolgray-200 transition-colors
```

**Search kbd hint:**
```
px-1 py-0.5 text-xs font-semibold
text-neutral-500 dark:text-neutral-400
bg-neutral-200 dark:bg-coolgray-200 rounded
```

**Plain CSS:**
```css
.sidebar-header {
  display: flex;
  padding: 1rem 0 1rem 0.5rem;
}
@media (min-width: 1024px) {
  .sidebar-header { padding-top: 1.5rem; }
}

.sidebar-logo {
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.025em;
  color: #000;
  text-decoration: none;
}
.dark .sidebar-logo { color: #fff; }
.sidebar-logo:hover { opacity: 0.8; }

.sidebar-search-btn {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.625rem;
  background: #f5f5f5;
  border: 1px solid #d4d4d4;
  border-radius: 0.375rem;
  cursor: pointer;
  transition: background-color 150ms;
}
.sidebar-search-btn:hover { background: #e5e5e5; }
.dark .sidebar-search-btn {
  background: #181818;
  border-color: #202020;
}
.dark .sidebar-search-btn:hover { background: #202020; }

.sidebar-search-kbd {
  padding: 0.125rem 0.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #737373;
  background: #e5e5e5;
  border-radius: 0.25rem;
}
.dark .sidebar-search-kbd {
  color: #a3a3a3;
  background: #202020;
}
```

#### Menu Item List

**Tailwind (list container):**
```
flex flex-col flex-1 gap-y-7
```

**Tailwind (inner list):**
```
flex flex-col h-full space-y-1.5
```

**Plain CSS:**
```css
.menu-list {
  display: flex;
  flex-direction: column;
  flex: 1;
  gap: 1.75rem;
  list-style: none;
  padding: 0;
  margin: 0;
}

.menu-list-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 0.375rem;
  list-style: none;
  padding: 0;
  margin: 0;
}
```

#### Menu Item

**Tailwind:**
```
flex gap-3 items-center px-2 py-1 w-full text-sm
dark:hover:bg-coolgray-100 dark:hover:text-white hover:bg-neutral-300 rounded-sm truncate min-w-0
```

#### Menu Item Active

**Tailwind:**
```
text-black rounded-sm dark:bg-coolgray-200 dark:text-warning bg-neutral-200 overflow-hidden
```

#### Menu Item Icon / Label

```
/* Icon */  flex-shrink-0 w-6 h-6 dark:hover:text-white
/* Label */ min-w-0 flex-1 truncate
```

**Plain CSS:**
```css
.menu-item {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  padding: 0.25rem 0.5rem;
  width: 100%;
  font-size: 0.875rem;
  border-radius: 0.125rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.menu-item:hover { background: #d4d4d4; }
.dark .menu-item:hover { background: #181818; color: #fff; }

.menu-item-active {
  color: #000;
  background: #e5e5e5;
  border-radius: 0.125rem;
}
.dark .menu-item-active {
  background: #202020;
  color: #fcd452;
}

.menu-item-icon {
  flex-shrink: 0;
  width: 1.5rem;
  height: 1.5rem;
}

.menu-item-label {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
```

#### Sub-Menu Item

```css
.sub-menu-item {
  /* Same as menu-item but with gap: 0.5rem and icon size 1rem */
  display: flex;
  gap: 0.5rem;
  align-items: center;
  padding: 0.25rem 0.5rem;
  width: 100%;
  font-size: 0.875rem;
  border-radius: 0.125rem;
}
.sub-menu-item-icon { flex-shrink: 0; width: 1rem; height: 1rem; }
```

---

### 3.10 Callout / Alert

Four types: `warning`, `danger`, `info`, `success`.

**Structure:**
```html
<div class="callout callout-{type}">
  <div class="callout-icon"><!-- SVG --></div>
  <div class="callout-body">
    <div class="callout-title">Title</div>
    <div class="callout-text">Content</div>
  </div>
</div>
```

**Base Tailwind:**
```
relative p-4 border rounded-lg
```

**Type Colors:**

| Type | Background | Border | Title Text | Body Text |
|---|---|---|---|---|
| **warning** | `bg-warning-50 dark:bg-warning-900/30` | `border-warning-300 dark:border-warning-800` | `text-warning-800 dark:text-warning-300` | `text-warning-700 dark:text-warning-200` |
| **danger** | `bg-red-50 dark:bg-red-900/30` | `border-red-300 dark:border-red-800` | `text-red-800 dark:text-red-300` | `text-red-700 dark:text-red-200` |
| **info** | `bg-blue-50 dark:bg-blue-900/30` | `border-blue-300 dark:border-blue-800` | `text-blue-800 dark:text-blue-300` | `text-blue-700 dark:text-blue-200` |
| **success** | `bg-green-50 dark:bg-green-900/30` | `border-green-300 dark:border-green-800` | `text-green-800 dark:text-green-300` | `text-green-700 dark:text-green-200` |

**Plain CSS (warning example):**
```css
.callout {
  position: relative;
  padding: 1rem;
  border: 1px solid;
  border-radius: 0.5rem;
}

.callout-warning {
  background: #fefce8;
  border-color: #fde047;
}
.dark .callout-warning {
  background: rgba(113, 63, 18, 0.3);
  border-color: #854d0e;
}

.callout-title {
  font-size: 1rem;
  font-weight: 700;
}
.callout-warning .callout-title { color: #854d0e; }
.dark .callout-warning .callout-title { color: #fde047; }

.callout-text {
  margin-top: 0.5rem;
  font-size: 0.875rem;
}
.callout-warning .callout-text { color: #a16207; }
.dark .callout-warning .callout-text { color: #fef08a; }
```

**Icon colors per type:**
- Warning: `text-warning-600 dark:text-warning-400` (`#ca8a04` / `#fcd452`)
- Danger: `text-red-600 dark:text-red-400` (`#dc2626` / `#f87171`)
- Info: `text-blue-600 dark:text-blue-400` (`#2563eb` / `#60a5fa`)
- Success: `text-green-600 dark:text-green-400` (`#16a34a` / `#4ade80`)

---

### 3.11 Toast / Notification

**Container Tailwind:**
```
relative flex flex-col items-start
shadow-[0_5px_15px_-3px_rgb(0_0_0_/_0.08)]
w-full transition-all duration-100 ease-out
dark:bg-coolgray-100 bg-white
dark:border dark:border-coolgray-200
rounded-sm sm:max-w-xs
```

**Plain CSS:**
```css
.toast {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  width: 100%;
  max-width: 20rem;
  background: #fff;
  border-radius: 0.125rem;
  box-shadow: 0 5px 15px -3px rgba(0, 0, 0, 0.08);
  transition: all 100ms ease-out;
}
.dark .toast {
  background: #181818;
  border: 1px solid #202020;
}
```

**Icon colors per toast type:**

| Type | Color | Hex |
|---|---|---|
| Success | `text-green-500` | `#22c55e` |
| Info | `text-blue-500` | `#3b82f6` |
| Warning | `text-orange-400` | `#fb923c` |
| Danger | `text-red-500` | `#ef4444` |

**Behavior**: Stacks up to 4 toasts, auto-dismisses after 4 seconds, positioned bottom-right.

---

### 3.12 Modal

**Tailwind (dialog-based):**
```
rounded-sm modal-box max-h-[calc(100vh-5rem)] flex flex-col
```

**Modal Input variant container:**
```
relative w-full lg:w-auto lg:min-w-2xl lg:max-w-4xl
border rounded-sm drop-shadow-sm
bg-white border-neutral-200
dark:bg-base dark:border-coolgray-300
flex flex-col
```

**Modal Confirmation container:**
```
relative w-full border rounded-sm
min-w-full lg:min-w-[36rem] max-w-[48rem]
max-h-[calc(100vh-2rem)]
bg-neutral-100 border-neutral-400
dark:bg-base dark:border-coolgray-300
flex flex-col
```

**Plain CSS:**
```css
.modal-box {
  border-radius: 0.125rem;
  max-height: calc(100vh - 5rem);
  display: flex;
  flex-direction: column;
}

.modal-input {
  position: relative;
  width: 100%;
  border: 1px solid #e5e5e5;
  border-radius: 0.125rem;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, 0.05));
  background: #fff;
  display: flex;
  flex-direction: column;
}
.dark .modal-input {
  background: #101010;
  border-color: #242424;
}

/* Desktop sizing */
@media (min-width: 1024px) {
  .modal-input {
    width: auto;
    min-width: 42rem;
    max-width: 56rem;
  }
}
```

**Modal header:**
```css
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.5rem;
  flex-shrink: 0;
}
.modal-header h3 {
  font-size: 1.5rem;
  font-weight: 700;
}
```

**Close button:**
```css
.modal-close {
  width: 2rem;
  height: 2rem;
  border-radius: 9999px;
  color: #fff;
}
.modal-close:hover { background: #242424; }
```

---

### 3.13 Slide-Over Panel

**Tailwind:**
```
fixed inset-y-0 right-0 flex max-w-full pl-10
```

**Inner panel:**
```
max-w-xl w-screen
flex flex-col h-full py-6
border-l shadow-lg
bg-neutral-50 dark:bg-base
dark:border-neutral-800 border-neutral-200
```

**Plain CSS:**
```css
.slide-over {
  position: fixed;
  top: 0;
  bottom: 0;
  right: 0;
  display: flex;
  max-width: 100%;
  padding-left: 2.5rem;
}

.slide-over-panel {
  max-width: 36rem;
  width: 100vw;
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 1.5rem 0;
  border-left: 1px solid #e5e5e5;
  box-shadow: -10px 0 15px -3px rgba(0, 0, 0, 0.1);
  background: #fafafa;
}
.dark .slide-over-panel {
  background: #101010;
  border-color: #262626;
}
```

---

### 3.14 Tag

**Tailwind:**
```
px-2 py-1 cursor-pointer text-xs font-bold text-neutral-500
dark:bg-coolgray-100 dark:hover:bg-coolgray-300 bg-neutral-100 hover:bg-neutral-200
```

**Plain CSS:**
```css
.tag {
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 700;
  color: #737373;
  background: #f5f5f5;
  cursor: pointer;
}
.tag:hover { background: #e5e5e5; }
.dark .tag { background: #181818; }
.dark .tag:hover { background: #242424; }
```

---

### 3.15 Loading Spinner

**Tailwind:**
```
w-4 h-4 text-coollabs dark:text-warning animate-spin
```

**Plain CSS + SVG:**
```css
.loading-spinner {
  width: 1rem;
  height: 1rem;
  color: #6b16ed;
  animation: spin 1s linear infinite;
}
.dark .loading-spinner { color: #fcd452; }

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
```

**SVG structure:**
```html
<svg class="loading-spinner" viewBox="0 0 24 24" fill="none">
  <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"/>
  <path fill="currentColor" opacity="0.75" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
</svg>
```

---

### 3.16 Helper / Tooltip

**Tailwind (trigger icon):**
```
cursor-pointer text-coollabs dark:text-warning
```

**Tailwind (popup):**
```
hidden absolute z-40 text-xs rounded-sm text-neutral-700 group-hover:block
dark:border-coolgray-500 border-neutral-900 dark:bg-coolgray-400 bg-neutral-200
dark:text-neutral-300 max-w-sm whitespace-normal break-words
```

**Plain CSS:**
```css
.helper-icon {
  cursor: pointer;
  color: #6b16ed;
}
.dark .helper-icon { color: #fcd452; }

.helper-popup {
  display: none;
  position: absolute;
  z-index: 40;
  font-size: 0.75rem;
  border-radius: 0.125rem;
  color: #404040;
  background: #e5e5e5;
  max-width: 24rem;
  white-space: normal;
  word-break: break-word;
  padding: 1rem;
}
.dark .helper-popup {
  background: #282828;
  color: #d4d4d4;
  border: 1px solid #323232;
}

/* Show on parent hover */
.helper:hover .helper-popup { display: block; }
```

---

### 3.17 Highlighted Text

**Tailwind:**
```
inline-block font-bold text-coollabs dark:text-warning
```

**Plain CSS:**
```css
.text-highlight {
  display: inline-block;
  font-weight: 700;
  color: #6b16ed;
}
.dark .text-highlight { color: #fcd452; }
```

---

### 3.18 Scrollbar

**Tailwind:**
```
scrollbar-thumb-coollabs-100 scrollbar-track-neutral-200
dark:scrollbar-track-coolgray-200 scrollbar-thin
```

**Plain CSS:**
```css
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #e5e5e5; }
::-webkit-scrollbar-thumb { background: #7317ff; }
.dark ::-webkit-scrollbar-track { background: #202020; }
```

---

### 3.19 Table

**Plain CSS:**
```css
table { min-width: 100%; border-collapse: separate; }
table, tbody { border-bottom: 1px solid #d4d4d4; }
.dark table, .dark tbody { border-color: #202020; }

thead { text-transform: uppercase; }

tr { color: #000; }
tr:hover { background: #e5e5e5; }
.dark tr { color: #a3a3a3; }
.dark tr:hover { background: #000; }

th {
  padding: 0.875rem 0.75rem;
  text-align: left;
  color: #000;
}
.dark th { color: #fff; }
th:first-child { padding-left: 1.5rem; }

td { padding: 1rem 0.75rem; white-space: nowrap; }
td:first-child { padding-left: 1.5rem; font-weight: 700; }
```

---

### 3.20 Keyboard Shortcut Indicator

**Tailwind:**
```
px-2 text-xs rounded-sm border border-dashed border-neutral-700 dark:text-warning
```

**Plain CSS:**
```css
.kbd {
  padding: 0 0.5rem;
  font-size: 0.75rem;
  border-radius: 0.125rem;
  border: 1px dashed #404040;
}
.dark .kbd { color: #fcd452; }
```

---

## 4. Base Element Styles

These global styles are applied to all HTML elements:

```css
/* Page */
html, body {
  width: 100%;
  min-height: 100%;
  background: #f9fafb;
  font-family: Inter, sans-serif;
}
.dark html, .dark body {
  background: #101010;
  color: #a3a3a3;
}

body {
  min-height: 100vh;
  font-size: 0.875rem;
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
}

/* Links */
a:hover { color: #000; }
.dark a:hover { color: #fff; }

/* Labels */
.dark label { color: #a3a3a3; }

/* Sections */
section { margin-bottom: 3rem; }

/* Default border color override */
*, ::after, ::before, ::backdrop {
  border-color: #202020; /* coolgray-200 */
}

/* Select options */
.dark option {
  color: #fff;
  background: #181818;
}
```

---

## 5. Interactive State Reference

### Focus

| Element Type | Mechanism | Light | Dark |
|---|---|---|---|
| Buttons, links, checkboxes | `ring-2` offset | Purple `#6b16ed` | Yellow `#fcd452` |
| Inputs, selects, textareas | Inset box-shadow (4px left bar) | Purple `#6b16ed` | Yellow `#fcd452` |
| Dropdown items | Background change | `bg-neutral-100` | `bg-coollabs` (`#6b16ed`) |

### Hover

| Element | Light | Dark |
|---|---|---|
| Button (default) | `bg-neutral-100` | `bg-coolgray-200` |
| Button (highlighted) | `bg-coollabs` (`#6b16ed`) | `bg-coollabs-100` (`#7317ff`) |
| Button (error) | `bg-red-300` | `bg-red-800` |
| Box card | `bg-neutral-100` + all child text `#000` | `bg-coollabs-100` (`#7317ff`) + all child text `#fff` |
| Coolbox card | Ring: `ring-coollabs` | Ring: `ring-warning` |
| Menu item | `bg-neutral-300` | `bg-coolgray-100` |
| Dropdown item | `bg-neutral-100` | `bg-coollabs` |
| Table row | `bg-neutral-200` | `bg-black` |
| Link | `text-black` | `text-white` |
| Checkbox container | — | `bg-coolgray-100` |

### Disabled

```css
/* Universal disabled pattern */
:disabled {
  cursor: not-allowed;
  color: #d4d4d4;           /* neutral-300 */
  background: transparent;
  border-color: transparent;
}
.dark :disabled {
  color: #525252;            /* neutral-600 */
}

/* Input-specific */
.input:disabled {
  background: #e5e5e5;      /* neutral-200 */
  color: #737373;            /* neutral-500 */
  box-shadow: none;
}
.dark .input:disabled {
  background: rgba(24, 24, 24, 0.4);
  box-shadow: none;
}
```

### Readonly

```css
.input:read-only {
  color: #737373;
  background: #e5e5e5;
  box-shadow: none;
}
.dark .input:read-only {
  color: #737373;
  background: rgba(24, 24, 24, 0.4);
  box-shadow: none;
}
```

---

## 6. CSS Custom Properties (Theme Tokens)

For use in any CSS framework or plain CSS:

```css
:root {
  /* Font */
  --font-sans: Inter, sans-serif;

  /* Brand */
  --color-base: #101010;
  --color-coollabs: #6b16ed;
  --color-coollabs-50: #f5f0ff;
  --color-coollabs-100: #7317ff;
  --color-coollabs-200: #5a12c7;
  --color-coollabs-300: #4a0fa3;

  /* Neutral grays (dark backgrounds) */
  --color-coolgray-100: #181818;
  --color-coolgray-200: #202020;
  --color-coolgray-300: #242424;
  --color-coolgray-400: #282828;
  --color-coolgray-500: #323232;

  /* Warning / dark accent */
  --color-warning: #fcd452;
  --color-warning-50: #fefce8;
  --color-warning-100: #fef9c3;
  --color-warning-200: #fef08a;
  --color-warning-300: #fde047;
  --color-warning-400: #fcd452;
  --color-warning-500: #facc15;
  --color-warning-600: #ca8a04;
  --color-warning-700: #a16207;
  --color-warning-800: #854d0e;
  --color-warning-900: #713f12;

  /* Semantic */
  --color-success: #22C55E;
  --color-error: #dc2626;
}
```
