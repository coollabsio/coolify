# Lessons

## Alpine x-transition + tw-animate-css exit animations flash at the end
- Symptom: a modal/overlay fades out, then flashes fully visible for 1-2 frames before it disappears.
- Cause: `animate-out` keyframes default to `animation-fill-mode: none`. The element snaps back to its natural state when the keyframe ends. Alpine hides the element (display: none) only after its own timer (read from `transition-duration`), which starts ~2 rAF later than the animation. The gap shows the element at full opacity.
- Rule: every `x-transition:leave` that uses tw-animate-css `animate-out` MUST also include `fill-mode-forwards`.
- Rule: when a user reports UI flicker, check ALL layers of the animation stack (state reset timing, spinner flash, keyframe fill mode, focus restore) before you report the fix as complete. My first fix covered state reset and spinner only; the fill-mode snap was the visible one.

## Displayed defaults must not become stored overrides
- When an edit form shows an inherited or computed default, trace an unchanged save and a related-field edit through persistence.
- Preserve the inherited state when the displayed value still equals the computed default; store an override only when the user selects a different value.
