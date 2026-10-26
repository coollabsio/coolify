# Coolify UI design system

This file has two parts:

- **Part 1, Portable design language:** the look and feel of the Coolify UI,
  with no dependency on Coolify code. It has the principles, a copy-paste token
  sheet in plain CSS, and the anatomy of each component with exact values. Use
  it to give another app the same feel. It works with any stack (plain CSS,
  Tailwind, React, Vue, Blade).
- **Part 2, Coolify implementation:** how Coolify applies the language with its
  Livewire + Blade + Alpine + Tailwind v4 frontend: the components, routes, page
  shells, and the rules for this repository.

When you change a shared value in Coolify, update Part 1 and Part 2 in the same
change.

---

# Part 1. Portable design language

## P1. The feel

- **Compact and calm.** 13–14px UI text, 32px controls, small radii, short
  labels. The data is the hero, not the chrome.
- **Layers, not boxes.** Depth comes from a few surface steps (canvas, card,
  well, control), 1px borders, and very soft shadows. Never use thick borders or
  heavy floating shadows.
- **Card and well.** Content sits in an inset panel (the well) inside a soft
  outer box with a small title row. In light mode the well is lighter than the
  outer box. In dark mode a top-level card is a near-canvas outer box with a
  lighter `#121212` well.
- **Tactile buttons.** Buttons and navigation icon tiles have a 2px bottom
  edge. They lift 1px on hover and press down on click.
- **Dark gray, not black.** Dark mode page canvas and the top bar are neutral
  sRGB 10,10,10 (`#0a0a0a`). Card wells sit one step up (`#121212`). Light mode is
  pure neutral gray.
- **One accent.** Coolify purple in both themes (`#6b16ed`, hover `#7317ff`,
  pressed `#5a12c7`, depth `#4a0fa3`, light text and icons `#a391ff`). Yellow
  is only for real warnings. In the default dark theme the sidebar, including
  the logo cell, is purple-black (`#120e1a`) with a faint brand wash. Users
  who want a neutral sidebar can pick a custom theme. The dark command palette
  has a light purple tint. The page canvas stays neutral.
- **Quiet text.** Sentence case. A clear text ladder: primary, dim, faint.
  No em dashes in UI copy.

## P2. Token sheet

Copy this sheet into the app's global CSS. Put the dark values on the selector
the app uses for dark mode (here `.dark` on `<html>`). Use the tokens in every
component; do not hard-code a gray in a component, because then it does not
follow the theme.

```css
:root {
    /* Type */
    --ui-font-sans: 'Geist Sans', Inter, system-ui, sans-serif;
    --ui-font-mono: 'Geist Mono', ui-monospace, SFMono-Regular, Menlo, monospace;

    /* Surfaces (light: pure neutral) */
    --ui-canvas: oklch(98.5% 0 0);          /* page background and sidebar */
    --ui-panel: #ffffff;                    /* top header bar */
    --ui-card-bg: rgb(0 0 0 / 0.75%);       /* card outer box */
    --ui-card-border: rgb(0 0 0 / 9%);
    --ui-well-bg: #ffffff;                  /* card body (inset well) */
    --ui-well-border: rgb(0 0 0 / 8%);
    --ui-well-shadow: 0 1px 2px rgb(0 0 0 / 6%);
    --ui-control-bg: oklch(96% 0 0);        /* inputs, select triggers */
    --ui-control-border: oklch(14.5% 0 0 / 0.1);
    --ui-menu-bg: oklch(96% 0 0);           /* dropdown, listbox, popover (opaque) */
    --ui-menu-border: oklch(14.5% 0 0 / 0.1);
    --ui-hover: oklch(92.2% 0 0);           /* menu option and row hover */
    --ui-divider: oklch(92.2% 0 0);
    --ui-chrome-border: rgb(0 0 0 / 6%);    /* sidebar and header edges */

    /* Text */
    --ui-fg: oklch(14.5% 0 0);
    --ui-fg-dim: oklch(40% 0 0);            /* card titles */
    --ui-fg-faint: oklch(50% 0 0);          /* labels, descriptions, meta */

    /* Accent and status */
    --ui-accent: oklch(49.65% 0.2709 289.33);     /* purple */
    --ui-accent-top: oklch(52.34% 0.287 289.16);  /* primary button gradient */
    --ui-accent-bottom: oklch(43.76% 0.2369 289.82);
    --ui-accent-edge: oklch(38.04% 0.2031 290.47); /* primary button depth edge */
    --ui-accent-fg: #ffffff;
    --ui-success: oklch(48% 0.135 149.58);
    --ui-warning: oklch(50% 0.105 80);
    --ui-error: oklch(52% 0.2 27.33);

    /* Depth */
    --ui-card-shadow: 0 1px 2px rgb(0 0 0 / 5%);
    --ui-menu-shadow: 0 4px 12px rgb(0 0 0 / 0.12), 0 2px 4px rgb(0 0 0 / 0.08);
    --ui-modal-shadow: 0 24px 64px rgb(0 0 0 / 0.55), 0 4px 16px rgb(0 0 0 / 0.4);
    --ui-depth-color: rgb(0 0 0 / 0.22);    /* button and tile bottom edge */

    /* Motion */
    --ui-ease-out: cubic-bezier(0.23, 1, 0.32, 1);
    --ui-ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
}

.dark {
    /* Surfaces (dark: pure neutral dark gray) */
    --ui-canvas: #0a0a0a;              /* page and top bar */
    --ui-panel: #121212;               /* cards and table headers, not the top bar */
    --ui-card-bg: rgb(255 255 255 / 6%);
    --ui-card-border: rgb(255 255 255 / 8%);
    --ui-well-bg: rgb(0 0 0 / 34%);
    --ui-well-border: rgb(255 255 255 / 7%);
    --ui-well-shadow: 0 1px 2px rgb(0 0 0 / 25%);
    --ui-control-bg: rgb(255 255 255 / 3%);
    --ui-control-border: rgb(255 255 255 / 8%);
    --ui-menu-bg: oklch(25.2% 0 0);
    --ui-menu-border: oklch(36% 0 0);
    --ui-hover: oklch(29.31% 0 0);
    --ui-divider: rgb(255 255 255 / 7%);
    --ui-chrome-border: rgb(255 255 255 / 4%);

    /* Text */
    --ui-fg: oklch(96.12% 0 0);
    --ui-fg-dim: oklch(76.99% 0 0);
    --ui-fg-faint: oklch(65% 0 0);

    /* Accent stays purple. Yellow is the warning color, not the accent. */
    --ui-accent: #6b16ed;
    --ui-accent-top: #7317ff;
    --ui-accent-bottom: #5a12c7;
    --ui-accent-edge: #4a0fa3;
    --ui-accent-fg: #ffffff;
    --ui-accent-light: #a391ff;
    --ui-sidebar: #120e1a;
    --ui-card: #121212;
    --ui-success: oklch(72.27% 0.192 149.58);
    --ui-warning: oklch(88.13% 0.1507 91.7);
    --ui-error: oklch(66% 0.2 27.33);

    --ui-depth-color: rgb(255 255 255 / 0.08);
}
```

Rules for the tokens:

- Translucent surfaces (`--ui-card-bg`, `--ui-well-bg`, `--ui-control-bg`) take
  the color of what is below them.
- Floating surfaces (menus, popovers, tooltips, modals) must be opaque. Use
  `--ui-menu-bg`, never a translucent value, or the content behind shows
  through.
- Keep the page canvas, cards, menus, and logs pure neutral (chroma 0). The
  default dark sidebar, the logo cell above it, and the dark command palette
  are the tinted surfaces.
- **Dark lightness level.** The canvas is at 14.48% and the header panel at
  19.13%. To
  change the level, move every dark surface and border token by the same
  amount (canvas, panel, menu, hover, borders, and the Coolify surface ladder),
  so the steps between layers stay the same. If you move only some of them, a
  hover fill can become darker than its menu.
- Primary buttons, focus rings, and active navigation use purple in both
  themes. Warning yellow is not an accent.
- Do not hard-code the brand purple (`#6b16ed`, `rgb(107 22 237 / …)`,
  `rgb(163 145 255 / …)`, `#8a4dff`), the dark canvas (`#0a0a0a`), or the
  card color (`#121212`) in component CSS. Use the tokens: `--color-brand`,
  `-hover`, `-press`, `-depth`, `-light`, `-ring`, `-foreground`,
  `--color-sidebar`, `--color-app`, and `--color-card`. For a translucent tint, use
  `color-mix(in srgb, var(--color-brand) 14%, transparent)`. A custom theme
  (`html[data-theme="custom"]`) overrides only these tokens, so a hard-coded
  value stays purple when the user picks another color.
- The custom theme has four colors: Sidebar, Selected (the current page in
  the sidebar), Accent, and Background. The accent gives the brand fill and its
  hover, press, depth, and ring shades, and `--color-brand-foreground` (the
  computed `--theme-accent-foreground`, so text stays readable on a light color
  such as yellow). The surface, border, and text tokens come from one ladder
  in `app.css` that mixes the readable text color into the background. The
  sidebar uses the same ladder with the sidebar color, and it can have the
  other light/dark scheme than the page (`data-sidebar-scheme`; `dark:`
  utilities follow it). When you add a color token, give it a value in that
  ladder.
- A translucent fill on a control that sits directly on the page (not in a
  card or table) shows the floating-navigation dots through it. Mix the tint
  with the canvas instead:
  `color-mix(in srgb, var(--color-brand) 6%, var(--color-app))`. Controls in
  cards keep the translucent tint, so they blend with the card.

## P3. Scales

| Scale | Values | Use |
|---|---|---|
| Text | 11px | meta, section labels, card descriptions, header links |
| | 12px | badges, small descriptions, code snippets |
| | 13px | navigation, buttons, table cells, card titles (semibold) |
| | 14px | inputs, menu options, empty-state titles |
| | 24px | page titles (with a 13px faint summary below) |
| Weight | 500 / 600 | 500 for labels and buttons, 600 for titles |
| Height | 24px | badges |
| | 32px | buttons, inputs, select triggers, menu options |
| | 36px | navigation rows with tiles |
| | 40px / 48px | table header / table rows |
| Radius | 6px | menu options |
| | 8px | buttons, inputs, card wells, navigation tiles |
| | 10px | menus and popovers (6px options + 4px inset) |
| | 12px | card outer box |
| | full | badges, pills |
| Spacing | 6px | card outer padding |
| | 16px | well padding, page gutters on small screens |
| | 24px | gap between cards (use a flex or grid gap, not margins) |
| Motion | 80ms | button lift and press |
| | 120ms | color, border, and focus changes |
| | 150ms | menu swaps, row hover |

Nested boxes normally follow `outer radius = inner radius + inset`. The card
(12px outer, 8px well, 6px inset) is an accepted exception.

## P4. Components

Class names below are examples. Keep the values and structure.

### App shell

- Three layers: the canvas (page and sidebar, `--ui-canvas`), the top header
  bar (`--ui-panel`, 48px high), and the cards on the canvas.
- In dark mode, the outer box of a top-level card is
  `color-mix(in srgb, var(--ui-panel) 66%, black)` (about `#0c0c0c`), and its
  well is `--ui-panel` (`#121212`). Tables use the same pair: the darker mix
  for the header row and `--ui-panel` for the rows.
- In light mode the sidebar is the canvas color and has a 1px
  `--ui-chrome-border` right edge. In the default dark theme the sidebar
  column, including the logo cell, is `#120e1a` with a faint lavender right
  edge (`rgb(163 145 255 / 0.10)`). The nav has a brand-purple wash that
  starts at 9% and fades out; the logo cell has a flat 9% wash, so the two
  read as one surface. A custom theme gets the neutral sidebar rules and its
  own sidebar color.
- The top bar matches the canvas. It has no bottom border at rest. After the
  page scrolls under it, dark mode draws a neutral hairline
  (`rgb(255 255 255 / 0.06)`), a translucent bar, blur, and a soft shadow.
  The logo cell stays the solid sidebar color.
- Keep the chrome borders faint. A strong border on near-black looks like a
  line drawn on the page.

### App header (logo cell)

- The logo cell is the top of the sidebar column, in the 48px top bar. It is
  as wide as the sidebar: 224px expanded, 64px collapsed.
- Expanded: the wordmark (15px semibold, tight tracking, `--ui-fg`), then the
  version on the same baseline (10.5px medium, `--ui-fg-faint`), with 6px
  between them and 16px side padding. An update badge sits at the right end.
- Collapsed: only the 20px logo mark, centered. The cell gets a bottom border
  so that it lines up with the top bar edge.
- The update badge is a small pill: 18px high, 6px side padding, 9.5px
  semibold text, fully rounded, a 10% fill, and a 25% inset ring of the same
  color. It is purple in light mode and warning yellow in dark mode. While an
  update runs, the same pill says "Updating". It hides when the sidebar is
  collapsed.
- The mobile drawer starts with the same wordmark and version in a 48px row
  with a bottom border, and a 32px close button on the right. It has no update
  badge.

### Mobile header

- Below the desktop breakpoint (1024px) a sticky top bar replaces the desktop
  top bar and sidebar. It is about 56px high (12px vertical padding, 16px side
  padding, 24px from 640px), translucent (95% of `--ui-panel`) with a small
  blur, and has a faint bottom border.
- Left: the logo mark (18px) in a 32px tile with an 8px radius and a neutral
  fill (`rgb(255 255 255 / 6%)` in dark mode), then the team switcher. The
  team name truncates.
- Right, with 4px gaps: status indicators (configuration warnings, active
  deployments), the update badge, the user menu, and a 36px sidebar button
  (the same sidebar glyph as the desktop collapse toggle). The sidebar button
  opens the drawer from the right.

### Sidebar navigation

```css
.nav-section { padding: 4px 10px; font-size: 11px; font-weight: 500; color: var(--ui-fg-faint); }
.nav-item {
    display: flex; align-items: center; gap: 10px;
    height: 36px; padding: 0 10px 0 4px;
    font-size: 13px; font-weight: 500; color: var(--ui-fg-dim);
    background: transparent;                /* the row itself never fills */
}
.nav-item:hover, .nav-item.is-active { color: var(--ui-fg); }
.nav-tile {                                 /* 30px tile: 16px icon + 6px padding + 1px border */
    box-sizing: content-box; width: 16px; height: 16px; padding: 6px;
    border: 1px solid rgb(0 0 0 / 9%); border-radius: 8px; background: #fafafa;
    box-shadow: 0 2px 0 var(--ui-depth-color);
    transition: transform 80ms, box-shadow 80ms, border-color 120ms, background-color 120ms;
}
.dark .nav-tile {
    border-color: rgb(255 255 255 / 0.07);
    background: rgb(255 255 255 / 0.03);
    color: oklch(72% 0 0);
}
.nav-item:hover .nav-tile { transform: translateY(-1px); box-shadow: 0 3px 0 var(--ui-depth-color); }
.nav-item:active .nav-tile { transform: translateY(2px); box-shadow: none; }
.nav-item.is-active .nav-tile {
    color: #fff;
    border-color: #8a4dff;
    background: linear-gradient(180deg, var(--ui-accent-top), var(--ui-accent-bottom));
    --ui-depth-color: var(--ui-accent-edge);
    box-shadow: 0 2px 0 var(--ui-accent-edge);  /* no glow: the row clips it */
}
```

- Use outline icons (1.5px stroke) at 16px. A brand item can use a one-color
  brand mark when it is clearer than an outline icon.
- Rows with a submenu end in a 12px chevron. In dark mode the chevron is
  hidden until the row is hovered, focused, or current.
- Group rows under section labels (11px in light mode; 10.5px uppercase muted
  gray in dark mode), and separate groups with a 1px divider.
- Do not put a glow (a blurred `box-shadow`) on a tile. The current row clips
  it, so it shows as a dim square behind the tile.
- In the collapsed sidebar, Search is a tile like the other items: no row fill
  and no frame around it. Only the expanded sidebar shows Search as an input.
- A menu that replaces another menu in place starts with a back row: a
  centered label and a 12px left chevron (`‹ Dashboard`).
- The main menu has no row above its items. Dashboard is the first item, right
  under Search, on every page.

### Card (outer box, title row, well)

```css
.card {
    display: flex; flex-direction: column;
    border: 1px solid var(--ui-card-border); border-radius: 12px; padding: 6px;
    background: var(--ui-card-bg); box-shadow: var(--ui-card-shadow);
    overflow: visible;                      /* menus and tooltips must escape */
}
.dark .card { background: color-mix(in srgb, var(--ui-panel) 66%, black); box-shadow: none; }  /* top-level card */
.dark .card > .card-well { background: var(--ui-panel); box-shadow: none; }  /* top-level well */
.card-header {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: 16px; padding: 4px 8px 10px;
}
.card-title { font-size: 13px; font-weight: 600; line-height: 20px; color: var(--ui-fg-dim); }
.card-description { margin-top: 2px; font-size: 11px; line-height: 16px; color: var(--ui-fg-faint); }
.card-header a { font-size: 11px; font-weight: 500; color: var(--ui-fg-faint); }
.card-header a:hover { color: var(--ui-fg); }
.card-well {
    position: relative; padding: 16px;
    border: 1px solid var(--ui-well-border); border-radius: 8px;
    background: var(--ui-well-bg); box-shadow: var(--ui-well-shadow);
}
.card-well.is-flush { padding: 0; }        /* tables and lists that touch the edge */
```

- Card actions (buttons, badges, a filter) go in the title row on the right.
- Keep one collection in one card: its toolbar at the top of a flush well, its
  table below, and its pagination in the same well.

### Inputs and select triggers

```css
.input {
    display: block; width: 100%; height: 32px; padding: 0 12px;
    border: 1px solid var(--ui-control-border); border-radius: 8px;
    background: var(--ui-control-bg); color: var(--ui-fg); font-size: 14px;
    transition: border-color 120ms var(--ui-ease-out), box-shadow 120ms var(--ui-ease-out);
}
.input::placeholder { color: var(--ui-fg-faint); }
.input:focus-visible { outline: none; border-color: var(--ui-accent); }
.label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--ui-fg-dim); }
```

- Use a custom listbox, not the native `<select>`.
- Show a text input and a select trigger with the same height, fill, border,
  and radius.

### Buttons

```css
.button {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    height: 32px; padding: 0 10px; border-radius: 6px;
    border: 1px solid rgb(0 0 0 / 9%); background: #ffffff; color: var(--ui-fg);
    font-size: 13px; font-weight: 500; white-space: nowrap;
    box-shadow: 0 2px 0 var(--ui-depth-color);
    transition: color 120ms, background-color 120ms, border-color 120ms,
                box-shadow 80ms, transform 80ms;
    transition-timing-function: var(--ui-ease-out);
}
.dark .button:not(.button-primary) { border-color: rgb(255 255 255 / 8%); background: rgb(255 255 255 / 6%); }
.button:hover { transform: translateY(-1px); box-shadow: 0 3px 0 var(--ui-depth-color); }
.button:active { transform: translateY(2px); box-shadow: none; }
.button:focus-visible { outline: none; box-shadow: 0 2px 0 var(--ui-depth-color), 0 0 0 1px var(--ui-accent); }
.button:disabled { box-shadow: none; transform: none; opacity: 0.5; cursor: not-allowed; }
.button-primary {
    --ui-depth-color: var(--ui-accent-edge);
    border-color: var(--ui-accent-bottom); color: var(--ui-accent-fg);
    background: linear-gradient(to bottom, var(--ui-accent-top), var(--ui-accent-bottom));
}
```

- Use one primary button per area. Other actions are neutral buttons.
- Destructive actions use the error color for text and focus ring.
- With Appearance → Style → Flat, all buttons lose the raised edge.

### Menus, listboxes, and popovers

```css
.menu {
    min-width: max(100%, 13rem); max-height: 16rem; overflow-y: auto;
    display: flex; flex-direction: column; gap: 1px; padding: 4px;
    border: 1px solid var(--ui-menu-border); border-radius: 10px;
    background: var(--ui-menu-bg); box-shadow: var(--ui-menu-shadow);
}
.menu-option {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    min-height: 32px; padding: 6px 8px; border-radius: 6px;
    font-size: 14px; color: var(--ui-fg); white-space: nowrap; cursor: pointer;
}
.menu-option:hover { background: var(--ui-hover); }
```

- Mark the selected option with a check mark on the right, not with a fill.
- Keep trigger labels static (`Filter`, `Sort`); show the selection in the menu.
- Render menus above everything; never clip them at a card or modal edge.

### Tables

- Header row: 40px, 13px medium, `--ui-fg-faint`, and a 1px `--ui-divider`
  bottom border. Fill: `rgb(0 0 0 / 2%)` in light mode,
  `color-mix(in srgb, var(--ui-panel) 66%, black)` in dark mode (the same as
  the card outer box).
- Dark mode rows use the card well color (`--ui-panel`, `#121212`), so a
  table looks like a card: header on top, content below.
- Data rows: about 48px, 10px 16px padding, a 1px divider, and a faint hover
  fill. Put the row action at the far right.
- Toolbar on top (search left; filter, sort, and add right), pagination in the
  footer. Hide the footer when there is only one page.
- Use a table, not cards, for a collection that can grow.
- Grid view tiles of a collection are cards without a well. In dark mode they
  are `--ui-panel`, the same as the header bar.

### View switcher

- A segmented control (`.view-toggle`) on the right of a collection toolbar.
- Table and grid buttons are icon-only (30px square, 14px icon). The name is in
  `title` and `aria-label`.
- Show only the view that is not current. In table view the control shows the
  grid icon; in grid view, the list icon. Do not show a selected pill for the
  current view.
- Text links stay labelled: the `Canvas` link with its "Try it" badge, and the
  `List view` link that is the only way back from a canvas.

### Status badge

```css
.badge {
    display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 8px;
    border: 1px solid rgb(0 0 0 / 9%); border-radius: 999px; background: rgb(0 0 0 / 4%);
    font-size: 12px; font-weight: 500; line-height: 1; color: var(--ui-fg-dim);
}
.dark .badge { border-color: rgb(255 255 255 / 12%); background: rgb(255 255 255 / 7%); color: var(--ui-fg); }
.badge-dot { width: 6px; height: 6px; border-radius: 999px; background: currentColor; }
```

- The pill stays neutral. Only the 6px dot has the status color (success,
  warning, error, neutral). Use a pulsing dot for a state in progress.

### Empty state

- On its own: the card outer box and well, with centered text, at least 160px
  high (128px small, 384px large).
- Inside a card: only the centered text, because the card is the frame.
- Optional 44px icon tile, a 14px medium title (`--ui-fg`), a 13px faint
  description (max width 28rem), and an optional action. No dashed border.

### Modals and toasts

- Modals use the card anatomy (outer box, title row, well), size to their
  content on desktop, and put the actions on the right below a divider. Use
  `--ui-modal-shadow`. In dark mode the edge is only the 1px card border, with
  no extra hairline ring outside it.
- Toasts are compact cards (max width 26rem) with a status icon tile, a title,
  an optional description, and a dismiss button. They close after 4 seconds
  and pause on hover.
- The toast edge blends into the toast background. In dark mode it is
  `rgb(255 255 255 / 0.05)`, not the control border.
- The status icon tile is 3D, like a navigation tile: a 1px border and a 2px
  bottom edge in the status color (`currentColor` at 22% and 30%).

### Command palette

- In dark mode the palette has a light brand tint: the shell is
  `color-mix(in oklab, #6b16ed 10%, <elevated>)` with a
  `rgb(163 145 255 / 0.22)` ring, and the results body is 6% purple with a
  `rgb(163 145 255 / 0.12)` ring. The search icon is `#a391ff`. The
  placeholder is muted lavender.
- On the selected (focused) row in dark mode, the parts on a purple fill are
  white: the quick-command and type badges, the icon tile glyph (for example
  the plus of a create row), and the chevron. Purple text on a purple fill is
  not readable.

### Logs and code

- A monospace viewport on an opaque surface a little darker than the card
  (dark: `oklch(15.91% 0 0)`), with one compact toolbar on top.
- Code snippets: 12px mono, 8px radius, 1px border, the control fill.

## P5. Traps

- **Translucent fills stack.** A control fill of `rgb(255 255 255 / 3%)` looks
  right on the dark well but is invisible on a menu. If you change a control
  token for one area, set the opaque value again on the floating panels in it.
- **Global overrides of utility classes.** If the app has a global rule that
  replaces a border or fill utility (for example, to make borders crisper),
  that rule also hits new elements. Check the computed value, not the class.
- **Dark default tokens.** Define every text token for both themes. A token
  that has only a dark value makes light-mode text too pale.
- **`overflow: hidden` on a card** clips menus and tooltips. Keep cards
  visible; clip only an inner scroll area.
- **Near-black hues.** oklch lightness below about 15% compresses toward black,
  and a hue can render bluer than expected. Check the rendered sRGB value.

## P6. Adopting the language in another app

1. Copy the P2 token sheet and load the fonts (Geist Sans, Geist Mono).
2. Set the canvas on the page and the sidebar, and the panel on the header bar.
3. Build the card, input, button, menu, badge, and empty-state components from
   P4 before the pages. Use only the tokens in them.
4. Put every page section in a card. Stack cards with a 24px gap.
5. Use a table for collections, and the status badge for states.
6. Check in light and dark mode, with the sidebar expanded and collapsed, and
   with a menu open inside a card. Measure the computed colors of the canvas,
   card, well, input, and menu; they must match the token sheet.

---

# Part 2. Coolify implementation

This part defines how Coolify applies the design language with its Livewire +
Blade + Alpine + Tailwind v4 frontend. It covers the global shell, project and
environment pages, application navigation, settings surfaces, tables, modals,
toasts, terminals, and metrics. In Coolify, the tokens of Part 1 map to the
`--coollabs-*`, `--color-*`, `--card-*`, and `--well-*` variables in
`resources/css/app.css`.

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
- layer cards are a soft outer box around an inset well (see section 5),
  with 1px borders and a restrained `0 1px 2px rgb(0 0 0 / 0.05)` lift, never
  heavy borders or a strong floating shadow;
- full-width data tables for dense collections;
- outline Reicon glyphs through `<x-reicon>`;
- the Coolify purple brand accent in both themes (`#6b16ed`); yellow only for
  real warnings, such as "Update available";
- the active sidebar item is a purple gradient tile with a 2px depth edge and
  no glow. The row itself stays transparent;
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
| `--coollabs-canvas` | 97% off-white | 14.48% (= `--color-app`) | page canvas (kept below card fills so cards lift) |
| `--color-panel` | white | 19.13% | topbar, top-level card outer box, table header row |
| `--coollabs-elevated` | 98% neutral | 20.02% | shells and modal headers |
| `--coollabs-base` | white | 22.64% | nested modal bodies and legacy panels |
| `--coollabs-recessed` | 96% neutral | 25.2% | inputs and listboxes (`rgb(255 255 255 / 3%)` inside the dark card well) |
| `--coollabs-fill` | 92.2% neutral | 29.31% | dividers, passive fills, menu hover |
| `--coollabs-line` | translucent dark | 36% | control borders (`rgb(255 255 255 / 8%)` inside the dark card well) |
| `--coollabs-hairline` | 85.5% neutral | 36% | shell rings (crisp enough to read as a card edge, ~1.6:1) |

The dark level is fixed: canvas 14.48%, panel 19.13%. Also `--color-surface`
20.02%, `--color-raised` 22.64%, `--color-selected` 26.86%, and `--color-log`
15.91%. All dark surfaces are pure neutral (chroma 0). To change the level, move the full ladder
by the same amount, including the opaque reset values for floating panels in
the card well (`.dark .application-settings-section-body :is(.listbox-panel,
...)`).
| `--coollabs-subtle` | 50% neutral | 70.8% neutral | labels and muted titles (light darkened for WCAG AA 4.5:1) |

Accent behavior uses Coolify purple in both themes. Brand tokens live in
`@theme` as `--color-brand` (`#6b16ed`), `--color-brand-hover` (`#7317ff`),
`--color-brand-press` (`#5a12c7`), `--color-brand-depth` (`#4a0fa3`), and
`--color-brand-light` (`#a391ff`). Dark mode sets `--color-accent` to
`--color-brand`. `--color-warning` stays yellow and is only for real warnings.

Do not hard-code blue focus rings, and do not use `dark:text-warning` as a
stand-in for the accent. Selected chips follow:

```html
bg-coollabs/10 text-coollabs ring-coollabs/25
dark:bg-brand/15 dark:text-brand-light dark:ring-brand-light/25
```

The filled top-level action/tab treatment uses the same palette at a restrained
opacity rather than a fully saturated fill.

### Shell layering

The app shell is three distinct surface layers, not one flat color. The
content canvas is the base, the topbar breadcrumb bar lifts off it, and cards
lift off the content:

- **Content canvas** is the base layer: `bg-app` in dark (deepest,
  `--color-app` `oklch(14.48% 0 0)`, sRGB 10,10,10), `bg-neutral-50` in light. The
  `<main>` content area and page body use it.
- **Topbar chrome** uses the canvas color in dark (`#0a0a0a`) and `bg-white`
  in light. It has no bottom border until the page scrolls under it. Scrolling
  adds `is-scrolled` on `<html>` (see `layouts/app.blade.php`) and the dark bar
  becomes `rgb(10 10 10 / 0.72)` with blur and a neutral hairline. In dark
  mode, top-level card wells and table rows use `--color-card` (`#121212`),
  not the top bar.
- **Desktop sidebar and the topbar brand block** (Coolify name and version)
  use the canvas color in light mode. In the default dark theme they use
  `--color-sidebar` (`#120e1a`): a vertical brand wash on the nav, a flat 9%
  wash on the logo cell, and a lavender right edge. Inactive icon tiles,
  Search, the account button, and section labels (10.5px uppercase mono) are
  lavender. The Admin icon stays neutral. These purple rules use
  `html.dark:not([data-theme="custom"])`; a custom theme keeps neutral tiles,
  Search, and account button. Chevrons are hidden until the row is hovered,
  focused, or current. Do not use `dark:border-white/[0.06]` on these chrome
  edges; a global rule forces that utility to the solid hairline grey.

Dark surface tokens are exact oklch equivalents of chosen sRGB steps. oklch
lightness compresses toward pure black below ~15%, so check the rendered sRGB
value. The dark ladder is `--color-app` 10, `--color-panel` 20,
`--coollabs-elevated` 22, `--coollabs-base` 28,
`--coollabs-recessed` 34 (sRGB, r=g=b), which reads as distinct surfaces.

Temperature: the page canvas, cards, menus, listboxes, modals, and logs stay
**pure neutral gray** (r=g=b). Do not tint those. The exceptions are the
default dark sidebar (`--color-sidebar`, `#120e1a`, with a brand-purple wash)
and the dark command palette (a faint brand-purple wash). A custom theme derives all
of these surfaces from its background and sidebar colors (see the token rules in P2). The light page canvas uses `bg-neutral-50` (not
`bg-gray-50`, which is faintly cool) so it matches the neutral cards and chrome.
The only intentional color is the purple brand accent. Yellow is for warnings.
- **Layer cards** use the outer box and inset well from section 5.
- **Top-level pages** (Projects, Servers, and the other section roots) do not
  repeat the page title in the breadcrumb when that title is the visible `h1`.
  Nested pages keep the full trail (project, environment, resource).
- **Pagination** is hidden when there is only one page (both arrows disabled).
- **Project cards** spell out their counts: "1 environment · 35 resources".
- **The view switcher** (`x-canvas-view-toggle`,
  `x-shared-variables.view-controls`) shows only the other view, as an icon
  button: the grid icon in table view, the list icon in grid view. The labelled
  `Canvas` link and the `List view` link on canvas pages keep their text.
- **Tables and collection tiles** outside a layer card use the
  `rounded-xl border … dark:bg-white/[0.05]` shell plus the crisp
  `--coollabs-hairline` ring; in light they are `bg-white` with the ring and
  the restrained card lift. In dark mode these shells are painted like layer
  cards. A tile, a separate pagination bar, or an empty box is a card without a
  well, so it is `--color-card` (`#121212`). A table (a shell whose first child is a
  `border-b` header row) has a header row in the card outer color
  (`color-mix(in srgb, var(--color-card) 66%, black)`) and `--color-card` rows.
  These rules match the Tailwind classes in `resources/css/app.css`, because
  the table shells are copied markup without a shared class.

Do not paint the content area with the same `bg-panel` as the topbar, and do
not drop card fills below `dark:bg-white/[0.05]`; both make surfaces read as one
color. Row-hover states keep the lighter `dark:hover:bg-white/[0.025]`.

---

## 4. Page shells and navigation

### Global shell

- Main sidebar groups are compact and use outline Reicons. With Appearance →
  Navigation items → Flat, rows are 32px high.
- With the default tile items (not Appearance → Navigation items → Flat), each
  icon sits in a 30px 3D tile (16px icon, 6px padding, 1px border) in a 36px row.
- With tile items, the row has no fill: the current item shows through its
  purple accent tile and its brighter label. The dark active tile has a 2px
  depth edge and no glow, because the row (`overflow: hidden`) clips a glow
  into a square.
- In the collapsed desktop sidebar, Search is a tile like the other items. The
  row fill is removed in `@layer utilities` (the button has a layered
  `!important` fill utility that an unlayered rule cannot beat), and the dark
  tile uses the same colors as the other tiles.
- With Flat items, active rows are rounded pills (`rounded-md`) with a solid
  neutral selected fill (`bg-black/5` light, `bg-white/6` dark) and no accent
  rail. Hover rows use the same radius. Do not use accent-tinted gradients on nav
  rows; yellow washes look muddy on dark UI.
- Nested items sit behind a thin 1px guide line (`.nav-children`) and use the
  same selected pill, not a thick box border.
- The logo cell (`.app-topbar-brand` in `layouts/app.blade.php`) is `w-56`
  expanded and `w-16` collapsed. Expanded, it shows the `Coolify` wordmark
  (`text-[15px] font-semibold tracking-tight`), `<x-version>`
  (`text-[10.5px]`, `text-fg-faint` in dark), and the update badge
  (`livewire:upgrade`, instance admins on self-hosted only) at `ml-auto`.
  Collapsed, it shows only `/coolify-logo.svg` at `size-5` and adds
  `border-b`.
- The update badge sits on the version row: an 18px fully rounded pill with
  9.5px semibold text. It is `bg-coollabs/10 text-coollabs ring-coollabs/25` in
  light mode and the warning pill (`dark:bg-warning/15 dark:text-warning
  dark:ring-warning/25`) in dark mode, because an update is a real warning.
  While an upgrade runs it says "Updating". Both open the upgrade modal.
- The mobile drawer header (`data-mobile-sidebar-brand`) repeats the wordmark
  and version in an `h-12` row with a bottom border and a close button. It
  does not show the update badge.
- The mobile top bar (`MOBILE TOP BAR` in `layouts/app.blade.php`, `lg:hidden`)
  is `sticky top-0 z-40`, `px-4 py-3 sm:px-6`, `bg-white/95 dark:bg-panel/95
  backdrop-blur-sm` with `border-neutral-200/60 dark:border-white/[0.06]`.
  Left: `/coolify-logo.svg` (18px) in a `size-8 rounded-lg bg-neutral-100
  dark:bg-white/[0.06]` tile, then `livewire:switch-team`. Right (`gap-1`):
  `#configuration-warning-hud-slot-mobile`, `deployments-indicator
  variant="mobile"`, the update badge (`livewire:upgrade key="mobile-upgrade"`,
  instance admins on self-hosted only), `x-top-user-menu`, and a `size-9`
  button that toggles the drawer. Keep these in the same order as the desktop
  top bar.
- Submenu chevrons (the right chevron on rows that open a submenu and the back
  chevron) are `text-coollabs` in light mode and grey in dark mode
  (`dark:text-fg-faint` right, `dark:text-fg-dim` back).
- Brand items use a one-color brand glyph in `<x-reicon>` instead of an outline
  icon when the brand mark is clearer (for example `cloudflare` for Cloudflare
  Tunnel, drawn from `public/svgs/cloudflared.svg`).

### Resource navigation

Application, service, database, and server pages have no second navigation
bar; content starts directly below the 48px global topbar. Every route in a
resource family (settings pages, backups, logs, terminal, metrics, danger zone)
is an entry in its grouped settings sidebar. Do not add a tab row, a large
in-flow resource heading, or legacy `.navbar-main` tabs to one resource type.

Keep route-derived active state in Blade/Livewire. Do not rely only on Alpine
state because it can disappear after polling or a Livewire morph.

From `xl`, the resource menu replaces the main menu in the main sidebar
(`x-main-nav-menu`, `registerMainNavContext()`, `components/navbar.blade.php`).
Its back row (`‹ Dashboard`, `‹ Servers`, `‹ Service`) shows the menu one depth
lower, without a page load. The main menu has no row that opens the resource
menu again; to get back to it, open the resource again. Dashboard is always the
first item under Search.

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
then Servers). Each section uses `x-section-heading` with a small "View all"
link to its full index (next to the switcher) and a table / grid `x-canvas-view-toggle` (no canvas
link). Table is the default; each section remembers its own choice. A page-level
List / Canvas switch at the top opens `/dashboard/canvas`: the servers canvas
with a "Deploying" / "Queued" pill on each application card that has an active
deployment, and a foldable Recent deployments panel at the bottom left (active, then
recent; a row shows its card, the status opens the deployment). Communicate server health with
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

- soft outer box: 12px radius, 1px border, 6px padding, faint fill
  (`rgb(0 0 0 / 0.75%)` light). In dark mode a top-level card is
  `color-mix(in srgb, var(--color-card) 66%, black)` and its well is
  `--color-card` (`#121212`); a nested card keeps
  `rgb(255 255 255 / 6%)` over its parent;
- small title row on the outer box (13px semibold title, 11px description and
  header links), no divider below it;
- body is an inset well: 8px radius, 1px border, white in light,
  `--color-card` in a dark top-level card, and `rgb(0 0 0 / 34%)` in a dark
  nested card;
- 16px body padding;
- optional `flush` mode for full-bleed tables;
- card-level actions belong in the header slot;
- theme colors are CSS variables on the card (`--card-bg`, `--card-border`,
  `--well-bg`, `--well-border`, `--well-shadow`, `--card-fg*`), overridden in
  `.dark .application-settings-section`.

The title row has `4px 8px 10px` padding inside the 6px outer padding, so
header actions sit 10px from the top and 14px from the right edge.

The card keeps `overflow: visible` so listbox panels and tooltips can escape
it. Do not add `overflow: hidden` to the card or the well.

Some pages put a well (`.application-settings-section-body`) directly on the
page without a card, for example the domains and environment variable tables.
The well colors are defined on the well itself as well as on the card, so a
standalone well keeps its background and border. In dark mode a standalone well
matches a top-level card: its content is `--color-card` (`#121212`), and a
table header row in it is the darker card outer color
(`color-mix(in srgb, var(--color-card) 66%, black)`). An `x-empty` inside a
standalone well shows only its centered text, as it does inside a card.

Inside the dark well, controls use a darker translucent fill and border
(`--coollabs-recessed: rgb(255 255 255 / 3%)`, `--coollabs-line:
rgb(255 255 255 / 8%)`), set on `.dark .application-settings-section-body`.
The global opaque values look too light on the dark well. Floating panels in
the well (`.listbox-panel`, `.searchable-listbox-panel`, `[role="listbox"]`,
`[role="menu"]`) set the opaque values again, so they are not see-through. A
new floating panel that uses `--coollabs-recessed` in a card needs the same
reset.

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
The layer card is an intentional exception: its 12px outer box and 8px well
with a 6px inset match the reference design.

Use an empty state when the section has no usable controls. On its own,
`x-empty` is the layer-card outer box and inset well with a centered 14px title
and a 13px muted description (minimum height 160px; `sm` 128px, `lg` 384px).
Inside a layer card, the card is the frame, so it shows only the centered text.
Do not use a dashed border for empty states.

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

- the same outer box, title row, and inset well as layer cards;
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
  only on the focused row. In dark mode the text of the focused row's chips,
  its icon tile glyph, and its chevron are white;
- in dark mode the shell, header, and results body carry the brand-purple
  tint from Part 1 (rules after the dark `.view-toggle` rules in
  `resources/css/app.css`). Light mode stays neutral;
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
- Reicon status tile for success, info, warning, danger, or default. The tile
  (`.toast-icon`) is 3D: a border and a 2px bottom edge in the status color;
- a faint edge (`.toast-card`, dark `rgb(255 255 255 / 0.05)`) that blends into
  the toast background;
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
history endpoints store CPU, memory, disk, network throughput, and load average
(Sentinel PR #47 added disk, network, and load). Bulk snapshot endpoints
(`/api/summary`, `/api/containers/current`) return the latest host and
per-container values in one call for fleet-wide views.

The fleet Metrics page (`/metrics`, top-level sidebar) aggregates these across
every team server: KPI tiles, per-metric trend cards, a threshold-colored
servers table, and a fleet-wide hottest-containers list.

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
| Fleet metrics page | `resources/views/livewire/fleet/metrics.blade.php` |
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
