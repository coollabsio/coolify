# Lessons

## Alpine x-transition + tw-animate-css exit animations flash at the end
- Symptom: a modal/overlay fades out, then flashes fully visible for 1-2 frames before it disappears.
- Cause: `animate-out` keyframes default to `animation-fill-mode: none`. The element snaps back to its natural state when the keyframe ends. Alpine hides the element (display: none) only after its own timer (read from `transition-duration`), which starts ~2 rAF later than the animation. The gap shows the element at full opacity.
- Rule: every `x-transition:leave` that uses tw-animate-css `animate-out` MUST also include `fill-mode-forwards`.
- Rule: when a user reports UI flicker, check ALL layers of the animation stack (state reset timing, spinner flash, keyframe fill mode, focus restore) before you report the fix as complete. My first fix covered state reset and spinner only; the fill-mode snap was the visible one.

## Displayed defaults must not become stored overrides
- When an edit form shows an inherited or computed default, trace an unchanged save and a related-field edit through persistence.
- Preserve the inherited state when the displayed value still equals the computed default; store an override only when the user selects a different value.

## Prove regressions against the unchanged baseline
- For a bug fix, run the same regression test before and after the production change. Use a stash when requested so the failure and success come from the exact same test.

## Apply shared domain UX to every supported resource type
- When a user asks for domain-management behavior, inventory every resource that can edit domains before implementation.
- Do not stop at the resource type named in the original report when the requested UX is meant to be consistent across Coolify.

## Verify manual and generated domain paths separately
- Domain regeneration and manual hostname edits must start the same post-save DNS check.
- Add explicit regression coverage for both entry paths across every active domain editor.

## Do not treat a runtime restart as behavior verification
- A healthy restarted container proves only that the process started.
- For a reported UI failure, verify the exact user flow and inspect the resulting persisted state before claiming the fix works.

## Prove the reported live flow before reporting a UI fix
- Do not use unit tests or a healthy process as proof for a reported live UI failure.
- After the user repeats the flow, inspect the exact persisted record, request logs, queue state, and deployed source before stating that it works.

## Start DNS checks only for DNS-relevant edits
- Compare the previous and saved scheme and hostname before a post-save DNS check.
- Do not restart DNS checks for indexing, redirect, path, or internal-port-only changes.

## Include automatically added domains in post-save DNS checks
- Compare the configured domain list before and after Save.
- Start checks for each newly added counterpart, even when the edited domain itself did not change.

## Use one DNS progress pattern
- All DNS check entry points must set the domain badge to the same `checking` state.
- Do not use separate loading feedback on Check all or per-domain action buttons when the badge is the progress indicator.
- Verify the rendered badge uses the spinner slot instead of the default status dot.

## Confirm whether old reports still apply before changing code
- For an old issue, first test the current branch and inspect later fixes. Do not assume that the historical reproduction still needs a new code change.

## Compare routing identity, not complete domain URLs
- Domain-conflict checks must treat `http://host` and `https://host` as the same routing identity.
- Reproduce reports with the exact stored schemes before stating that duplicate detection works.
