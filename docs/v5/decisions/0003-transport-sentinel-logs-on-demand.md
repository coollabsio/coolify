# Decision 0003: Transport Sentinel logs on demand

**Status: Accepted**

**Date: 2026-09-11**

## Context

Coolify operators need to inspect Sentinel logs from the dashboard. In v5,
Sentinel normally communicates with Coolify through an outbound Flux
connection. Sentinel runs as a systemd service, so journald also keeps its
process output and systemd lifecycle messages.

Continuously uploading every Sentinel log would add network traffic, central
storage growth, retention work, and privacy risk. Reading and parsing
`journalctl` inside Sentinel would discard the structured fields that Sentinel
already has and would require journal access permissions. Sentinel also cannot
answer a log request when it is stopped or cannot connect to Flux.

## Decision

Sentinel will make its own recent logs available on demand through Flux.

Sentinel will add a tracing output that writes redacted structured events to a
bounded in-memory ring buffer. Each event contains a timestamp, level,
component, message, and an allowlisted set of structured fields. The buffer has
both event-count and byte-size limits. When it is full, Sentinel drops the
oldest events without blocking its normal work.

The normal v5 path is:

```text
Sentinel tracing -> in-memory buffer -> Flux -> Coolify UI
```

The first protocol operation will return a bounded list of recent events. A
later protocol operation can follow new events while the UI is open. Closing
the UI stops the follow request. Coolify does not persist these events by
default.

Coolify will keep an SSH fallback that reads the system journal:

```text
journalctl --unit sentinel.service --no-pager --lines <bounded-count>
```

Coolify uses the SSH fallback when Sentinel or Flux is unavailable. This path
also shows systemd errors and failures that happen before Sentinel can start.
The fallback command uses a fixed unit name and bounded line count. It does not
accept an arbitrary command or journal path from the user.

The initial development slice can use Sentinel's authenticated HTTP API to
prove buffer reads before the Flux command exists. This HTTP route is a
temporary development transport. Flux is the normal v5 transport.

Redaction happens before an event enters the in-memory buffer. Tokens,
credentials, authorization headers, environment values, and other secrets
must not enter the buffer or the Flux response. Coolify authorizes every log
request against the selected server and team. Requests have limits for event
count, event size, stream duration, and throughput.

## Consequences

- The dashboard receives structured Sentinel logs without text parsing.
- Log access does not require continuous upload or new central log storage.
- A Sentinel restart clears the in-memory history.
- The UI can filter normal-path events by level and component.
- SSH and journald remain necessary for startup failures and loss of the Flux
  connection.
- External log export remains separate from dashboard diagnostics.

## Not decided here

This decision does not define:

- the final protocol command names or message schema;
- the exact event-count and byte-size limits;
- the final UI layout and filtering controls;
- optional export to Loki, OpenTelemetry, or another log system; or
- long-term central log retention.
