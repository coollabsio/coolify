# Archived V5 Package Changes

The previous V5 implementation used a React, Inertia, shadcn, and TypeScript
frontend. Those dependencies were removed from the active application when the
implementation was archived. They remain relevant only when reviewing the
source under `docs/v5/`.

## Removed Composer Package

| Package | Previous constraint |
| --- | --- |
| `inertiajs/inertia-laravel` | `^3.3` |

The package was removed from both `composer.json` and the installed packages in
`composer.lock`.

## Removed npm Runtime Packages

| Package | Previous constraint |
| --- | --- |
| `@base-ui/react` | `^1.5.0` |
| `@fontsource-variable/geist` | `^5.2.9` |
| `@inertiajs/react` | `^3.3.0` |
| `@inertiajs/vite` | `^3.3.0` |
| `@phosphor-icons/react` | `^2.1.10` |
| `class-variance-authority` | `^0.7.1` |
| `clsx` | `^2.1.1` |
| `react` | `^19.2.7` |
| `react-dom` | `^19.2.7` |
| `tailwind-merge` | `^3.6.0` |

## Removed npm Development Packages

| Package | Previous constraint |
| --- | --- |
| `@testing-library/react` | `^16.3.2` |
| `@types/react` | `^19.2.17` |
| `@types/react-dom` | `^19.2.3` |
| `@vitejs/plugin-react` | `^6.0.5` |
| `jsdom` | `^30.0.1` |
| `shadcn` | `^4.11.0` |
| `typescript` | `^6.0.3` |
| `vitest` | `^4.1.10` |

The corresponding direct dependency entries were also removed from
`package-lock.json` and `bun.lock`.

## Removed Frontend Configuration

- React and Inertia plugins, aliases, and the V5 entry point in `vite.config.js`
- `components.json`
- `tsconfig.json`
- `vitest.config.ts`
- The `typecheck`, `test`, and `test:watch` npm scripts used by the V5 frontend

Archived copies of the removed configuration files are available under
`docs/v5/archive/` using their original repository paths.

## Retained for V4

`tw-animate-css` was introduced during the earlier V5 work but is also imported
by the active V4 stylesheet at `resources/css/app.css`. It remains an active npm
dependency and must not be removed as part of the V5 archive cleanup.

The remaining active frontend dependencies were audited against V4 imports.
React, Inertia, shadcn, and their supporting packages are not used by active V4
source files.
