# Lessons

## Alpine x-transition + tw-animate-css exit animations flash at the end
- Symptom: a modal/overlay fades out, then flashes fully visible for 1-2 frames before it disappears.
- Cause: `animate-out` keyframes default to `animation-fill-mode: none`. The element snaps back to its natural state when the keyframe ends. Alpine hides the element (display: none) only after its own timer (read from `transition-duration`), which starts ~2 rAF later than the animation. The gap shows the element at full opacity.
- Rule: every `x-transition:leave` that uses tw-animate-css `animate-out` MUST also include `fill-mode-forwards`.
- Rule: when a user reports UI flicker, check ALL layers of the animation stack (state reset timing, spinner flash, keyframe fill mode, focus restore) before you report the fix as complete. My first fix covered state reset and spinner only; the fill-mode snap was the visible one.

## Secret-manager integration: fetch at deploy time, do not sync into the DB
- Context: 2026-08-23, third-party-secret-manager-integration branch.
- Correction: I proposed to sync remote secrets (Doppler/Vault/Infisical) into the `environment_variables` table. The user rejected this. The purpose of the integration is that Coolify does NOT store the env values. Coolify must fetch them at deployment time.
- Rule: for this feature, secrets from external managers must only exist in memory during a deployment and in the generated `.env` on the target server. Never persist them in Coolify's database.
- Rule: when a feature's stated purpose is "external system is the source of truth", do not recommend a local copy for convenience. Design for the pull model first, then list its trade-offs.
