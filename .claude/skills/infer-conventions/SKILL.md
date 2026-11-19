---
name: infer-conventions
description: "Use this skill to analyze how a Laravel application is actually written and record its conventions as shared rules. Trigger when the user wants to detect, infer, document, or standardize project conventions or coding style, set up or grow `.ai/rules`, resolve mixed or conflicting patterns (e.g. \"are we using Form Requests or inline validation?\"), or onboard agents and teammates to \"how we do things here\". Covers: a systematic sweep of ~49 Laravel convention dimensions (validation, models, architecture, testing, frontend, database, console), open-ended house-pattern discovery, conflict reporting, and recording rules scoped to the right paths via the Boost `record-rule` MCP tool. Only run this skill when the user explicitly asks for it; never start a sweep as part of another task. Do not use for one-off code review, enforcing formatting a linter already handles, or editing `.ai/rules` files by hand."
disable-model-invocation: true
license: MIT
metadata:
  author: laravel
---

# Infer Conventions

Learn how this application writes Laravel, then record what you learn as durable, path-scoped rules other agents will read. You are documenting reality, not improving it.

## Ground Rules (read before you start)

- Consistency first. The codebase's majority style is the convention. Never judge it, never propose a "better" pattern, never record what the code should do. If the app validates inline everywhere, that is the rule, even if Form Requests would be nicer.
- Skip what an active tool produces, keep what a tool would fight. Inspect the project's Pint and Rector configuration first; a Rector transformation is tooling-owned only when its package and relevant rule or set are installed and enabled. Active tools may rewrite code toward one canonical form: `$casts` to `casts()`, `$fillable` to attributes, magic accessors to the `Attribute` class, pipe-string rules to arrays, `$signature` to `#[Signature]`, named migrations to anonymous, and many more. When the app already sits at an active tool's target form, the tool owns it, so record nothing. But when the app deliberately holds a form an active tool would refactor away, such as legacy `getXxxAttribute()` accessors the `Attribute` class would replace, no tool can reproduce that choice and an agent defaults the other way. That against-the-grain hold is exactly what to record.
- Record decisions, not defaults. A consistent pattern earns a rule only when it reflects a choice: the app took one valid option where the framework or common practice offered others, or the pattern would surprise a competent agent. Framework defaults steer nothing, so skip them: anonymous migrations, `$signature` commands, `ShouldQueue` jobs, `casts()` on Laravel 11+, named routes, Rule objects in `app/Rules`, and `Mail::fake()` or `Bus::fake()` to isolate framework services. A real fork is not enough on its own. Weigh the side the app took, and record only the side an agent would not reach for by itself: inline closures everywhere, legacy accessors, a bespoke query layer. Watch for the false fork too. "No Mockery" next to facade fakes is not a choice against Mockery, because they double different things. The test for every candidate: without this rule, would the next agent plausibly write it differently? Only "yes" earns a rule.
- Architecture choices are the gold. Record presence and deliberate absence. The structural pattern the app commits to is the highest-signal convention and the one no tool can decide: Action classes and how they are invoked (`handle` / `execute` / `__invoke`), service objects, dedicated query objects exposing `builder()`, DTOs (spatie/laravel-data vs readonly classes), Form Request validation vs inline, an events and listeners spine vs direct calls, and domain or module folders. Also record a consistent non-pattern, such as "query Eloquent directly in controllers, no repository layer", so the next agent matches the app's altitude instead of over-engineering.
- Never duplicate `.ai/rules`. Read `.ai/rules/index.md` and the area files before the sweep. A dimension already covered there is marked done and skipped.
- Evidence or silence. A convention needs at least 3 consistent examples and no meaningful rival to become a candidate. Every Step 1 verdict applies this bar.
- The recorded rule states the convention, nothing else. One or two imperative lines: this project does X, so do X here. Keep detection evidence out. No counts, ratios, current usage, file lists, or example paths, because that is proof for the confirm step, not part of the rule. One short syntax fragment at most, and point to `search-docs` for API details.

## Process

Each step ends on a checkable completion criterion. Do not advance until it holds.

Fan out when you can. The sweep is embarrassingly parallel. If your environment can spawn subagents (a Task, dispatch, or equivalent tool), do Step 0 yourself, then hand each checklist group (A to J) and the architecture map to its own subagent. Each subagent runs the greps, reads a few representative files, and returns structured verdicts (dimension, verdict, evidence, proposed glob / title / note). You aggregate, dedupe, then run Steps 3 to 5. It is far faster on a real app. No subagents available? Run the steps in sequence, with the same bar and the same output.

### Step 0: Orient

Read `composer.json` (installed packages tell you which checklist groups apply), the `pint.json` / PHPStan / Rector config, `.ai/rules/index.md` if present, and most important, map the `app/` tree. List every directory under `app/` (and any `Modules/`, `src/`, `packages/`, or domain root). Every folder beyond Laravel's default skeleton (`Http`, `Models`, `Providers`, `Console`, `Exceptions`) is a structural pattern the app committed to and a high-value rule waiting to be written: `Actions`, `Services`, `Data` or DTOs, `Queries`, `Repositories`, `ViewModels`, `Pipelines`, `Support`, `Enums`, `Contracts`, `Observers`, or `Domain` and module roots. Note each one. You will confirm how it is used in Step 2.

This app ships a frontend stack, so the frontend checklist group applies. Sweep it.

Done when: you have the applicable checklist groups, the dimensions already recorded in `.ai/rules`, and a list of every non-default `app/` directory mapped to the pattern it represents.

### Step 1: Predefined sweep

Open `references/checklist.md` and work every applicable dimension using its search hints. Give each exactly one verdict:

- Pattern. Clears the bar, rival under ~20% of sites, and reflects a real choice (passes the decisions-not-defaults test). A recording candidate. Cite 2 to 3 example files.
- Conflict. Both styles present in meaningful numbers. Report the split with counts and example files. Never record a preferred winner while the code remains mixed, even in yolo, because that would describe an aspiration rather than reality. Record only if the user identifies a stable path or context boundary that explains both styles; otherwise defer until the code is reconciled.
- Default. Consistent, but a framework or common-practice default the agent already writes unprompted. Skip it as a no-op, not a convention.
- No signal. Under the bar: feature unused, or too few examples. Skip silently (one summary line at most).
- Tooling-owned or Already-recorded. Skip per the ground rules.

Done when: every applicable dimension carries exactly one of those verdicts.

### Step 2: Open-ended pass

First, close out the architecture map from Step 0. For every non-default `app/` directory you listed, confirm how the pattern is used and apply the same evidence and decisions-not-defaults tests as Step 1. Generator-standard or sparsely used directories such as `Rules`, `Observers`, `Mail`, and `Notifications` are signals to inspect, not automatic conventions. Make genuine structural patterns candidates: Action classes invoked via `handle` / `execute` / `__invoke`, Services constructor-injected, `Queries` objects exposing `builder(): Builder`, DTOs as readonly classes or spatie/laravel-data, module or domain folders as the unit of organization. Scope each qualifying pattern to its own directory glob. Also record a consistent deliberate absence, such as "no repository layer, controllers query Eloquent directly", so the next agent matches the app's altitude.

Then find what else makes this codebase itself: base or abstract classes most code extends, traits used everywhere, tenancy or authorization scoping woven through queries, naming schemes, and custom helpers. Same evidence bar, cite files. Record every genuine structural pattern, and cap the other house findings at ~5 so the pass stays high-signal.

Done when: every non-default `app/` directory from Step 0 has a verdict, and the pass has produced its cited house findings (or concluded there are none).

### Step 3: Confirm

Present every candidate in one batch. Per item: dimension, verdict, evidence (counts and files), and the exact proposed `glob` or `globs` / `title` / `note`. Conflicts are presented as questions about an existing context boundary or deferred cleanup, not as a choice of future style.

Default mode is confirm: record only what the user approves. Switch to yolo only when the invocation said so ("yolo", "don't ask", "just record them"), then record all pattern candidates without asking. Conflicts still go to the user in yolo.

Done when: every candidate is approved, rejected, or (conflicts) decided.

### Step 4: Record

Make one `record-rule` call for each glob an approved convention applies to. Choose the most specific globs that cover the cited evidence from the mapping table below; if a convention spans models and migrations, record it under both domains so agents discover it from either path. The `note` is the bare convention: strip every trace of detection (see the ground rule). If `record-rule` is unavailable (rules disabled), report the full rule text so the user can enable `BOOST_RULES_ENABLED` or add it by hand.

Record this:

> Accessors and mutators: use the legacy magic-method style (`getXxxAttribute()` / `setXxxAttribute()`), not the `Attribute` class. Match it in models.

Not this:

> Accessors/mutators use the legacy magic-method style; the `Attribute`-class style is not used anywhere (13 legacy, 0 Attribute-class), e.g. `app/Models/Post.php`. Match the legacy style in existing models.

Done when: every approved item has a successful tool response, and any failure is reported with its rule text.

### Step 5: Summarize

List recorded rules (file and title), conflicts the user deferred, notable no-signals, and remind the user to commit `.ai/rules` so their team and agents share the conventions.

## Glob mapping

Attach each rule to the most specific path that covers its evidence. Never a lazy `app/**` when a subtree fits. Match the glob to where the code actually lives, which is not the same in a default skeleton and in a modular or DDD layout. Use the Step 0 `app/` map to pick the real path.

Examples:

- Models: `app/Models/**` in a default app, or `app/Modules/Blog/Models/**` / `src/Domain/Blog/**` in a modular one.
- Controllers, routing, validation, responses: `app/Http/**`, or `app/Modules/*/Http/**` when each module owns its HTTP layer.
- Actions, Services, DTOs: `app/Actions/**`, `app/Services/**`, `app/Data/**`, or the module path the app actually uses.
- Tests: `tests/**`.
- Migrations and database: `database/migrations/**`.
- Truly app-wide (rare, e.g. auth retrieval): `app/**`.

`record-rule` takes one glob. When a convention genuinely spans two domains (e.g. UUID keys touch models and migrations), call it once per domain with the same title and note; mentioning another path in the note does not make the rule discoverable there.

## Edge cases

- Rules disabled or `record-rule` missing: detection is read-only, so Steps 0 to 3 still run, and recording falls back to the manual path in Step 4.
- Tiny or fresh app: most dimensions land on no-signal. Say so honestly ("not enough code to infer conventions yet") and record nothing.
- Huge app: each dimension is a bounded grep plus a handful of file reads. Sample representative files, do not read everything.
- Re-runs: reading `.ai/rules` in Step 0 makes re-runs incremental, so only new or undecided dimensions surface.
- Non-standard layout (modules, DDD): the open-ended pass catches the layout itself as convention #1. Adapt the globs in the mapping table to the observed paths.
