# Lessons

## Alpine x-transition + tw-animate-css exit animations flash at the end
- Symptom: a modal/overlay fades out, then flashes fully visible for 1-2 frames before it disappears.
- Cause: `animate-out` keyframes default to `animation-fill-mode: none`. The element snaps back to its natural state when the keyframe ends. Alpine hides the element (display: none) only after its own timer (read from `transition-duration`), which starts ~2 rAF later than the animation. The gap shows the element at full opacity.
- Rule: every `x-transition:leave` that uses tw-animate-css `animate-out` MUST also include `fill-mode-forwards`.
- Rule: when a user reports UI flicker, check ALL layers of the animation stack (state reset timing, spinner flash, keyframe fill mode, focus restore) before you report the fix as complete. My first fix covered state reset and spinner only; the fill-mode snap was the visible one.

## 2026-09-09 shell and browser test pitfalls (coolify)
- Never put `pkill -f <pattern>` in the same Bash command as a literal that matches `<pattern>`; the shell kills itself (exit 143/144, empty output). Run the kill in its own command and bracket one character: `pkill -f "playwright run-serve[r]"`.
- Do not pipe `php artisan test tests/v4/Browser/...` output into `sed | grep` with a `timeout`; the Playwright child keeps stdout open and the pipeline never ends, which looks like a hung test. Redirect to a log file, then read the file.
- Browser tests need `config()->set('app.maintenance.store', 'array')` before `visit()` on hosts without phpredis; otherwise every page is a 500.
