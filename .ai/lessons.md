# Lessons

## Prove regressions before changing code
- Reproduce the reported failure on the unchanged baseline before adding a fix.
- When a symptom matches an earlier fix, inspect that fix and prove why it no longer works before adding another workaround.
- Test old reports against the current branch because later changes can make the report obsolete.
- Use the same regression test before and after the production change so the result shows the behavior difference.

## Verify the complete user flow
- Do not use a passing unit test, a successful build, or a healthy process as proof for a reported UI failure.
- Verify the exact live flow, persisted state, relevant logs, and queue state when they affect the result.
- When the request covers more than one interface or resource type, inventory and verify each supported path.

## Preserve product scope
- Do not replace required SPA navigation with a full-page redirect to hide a lifecycle or ordering defect.
- Do not add billing restrictions, live reconciliation, or fallback behavior unless the request includes them.
- Treat implementation constraints as details. Do not expand a requested team-level control into a more complex policy model.

## Keep dynamic Livewire identities stable
- In dynamic lists, key components and actions with immutable record identities, not counts, indexes, or array positions.
- Use targeted refresh events. Do not refresh a parent and a child that the parent can remove or hide during the same operation.
- Prove lifecycle and redirect causes directly; an effects assertion alone is not sufficient.

## Keep modal structure consistent
- Identify the parent page that owns a modal trigger and move the complete requested workflow into that modal.
- Use a flat form layout when the modal already supplies its title and description.
- Put destructive actions on the footer's left and primary actions last on the right.
- Use shared section, helper, tooltip, and icon-button components instead of local variants.
- Keep validation, preview, and save controls in a fixed footer when the body is large.

## Verify layered UI behavior visually
- Inspect the real layout with all conditional elements visible, especially compound status badges.
- For animation flicker, inspect state timing, loading indicators, keyframe fill mode, and focus restoration.
- Add `fill-mode-forwards` to Alpine leave transitions that use tw-animate-css `animate-out` so the element does not flash before Alpine hides it.

## Preserve inherited values and clear API semantics
- An unchanged displayed default must remain inherited; store an override only when the user selects a different value.
- Expose named API values for special modes. Keep existing numeric sentinels only as compatibility aliases unless a breaking change is requested.

## Trace infrastructure changes end to end
- For container image changes, inspect Compose services and every relevant Dockerfile build stage.
- Pin a stable release tag instead of using a floating `latest` tag.
- A successful image pull does not prove that the complete application build no longer uses the old image.

## Make distributed schedules durable
- Use the database as the correctness source for dynamic cron occurrences shared by multiple scheduler and Horizon nodes; Redis locks are load controls, not a durable execution ledger.
- Give each schedule occurrence a unique database identity and make queue consumers claim it atomically before external work.
- Keep pending occurrences recoverable across publisher interruptions, and define an explicit bounded policy for late or offline schedules.
