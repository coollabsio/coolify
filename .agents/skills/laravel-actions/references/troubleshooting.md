# Troubleshooting

## Scope

Use this reference when action wiring behaves unexpectedly.

## Recap

- Provides a fast triage flow for routing, queueing, events, and command wiring.
- Lists recurring failure patterns and where to check first.
- Encourages reproducing issues with focused tests before broad debugging.
- Separates wiring diagnostics from domain logic verification.

## Fast checks

- Action class uses `AsAction`.
- Namespace and autoloading are correct.
- Entrypoint wiring (route, queue, event, command) is registered.
- Method signatures and argument types match caller expectations.

## Failure patterns

- Controller route points to wrong class.
- Queue worker/config mismatch.
- Listener mapping not loaded.
- Command signature mismatch.
- Command not registered in the console kernel.

## Debug checklist

- Reproduce with a focused failing test.
- Validate wiring layer first, then domain behavior.
- Isolate dependencies with fakes/spies where appropriate.