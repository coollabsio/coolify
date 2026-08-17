# Nightwatch Configuration Reference

## Configuration Summary by Event Type

| Event Type            | Sampling                                           | Filtering                                                                    | Redaction                 |
| --------------------- | -------------------------------------------------- | ---------------------------------------------------------------------------- | ------------------------- |
| **Requests**          | `NIGHTWATCH_REQUEST_SAMPLE_RATE`, Route middleware | Not applicable                                                               | Headers, payload, URL, IP |
| **Commands**          | `NIGHTWATCH_COMMAND_SAMPLE_RATE`, Event listener   | Not applicable                                                               | Command arguments         |
| **Queries**           | Parent context                                     | `rejectQueries()`, `NIGHTWATCH_IGNORE_QUERIES`                               | SQL statement             |
| **Cache**             | Parent context                                     | `rejectCacheKeys()`, `rejectCacheEvents()`, `NIGHTWATCH_IGNORE_CACHE_EVENTS` | Cache key                 |
| **Jobs**              | Parent context, Queue::before                      | `rejectQueuedJobs()`                                                         | Not applicable            |
| **Mail**              | Parent context                                     | `rejectMail()`, `NIGHTWATCH_IGNORE_MAIL`                                     | Subject                   |
| **Notifications**     | Parent context                                     | `rejectNotifications()`, `NIGHTWATCH_IGNORE_NOTIFICATIONS`                   | Not applicable            |
| **Outgoing Requests** | Parent context                                     | `rejectOutgoingRequests()`, `NIGHTWATCH_IGNORE_OUTGOING_REQUESTS`            | URL                       |
| **Exceptions**        | `NIGHTWATCH_EXCEPTION_SAMPLE_RATE`                 | Not applicable                                                               | Exception message         |

---

## Production Recommendations

### High-Traffic Applications

```bash

# Conservative sampling

NIGHTWATCH_REQUEST_SAMPLE_RATE=0.01          # 1% of requests

NIGHTWATCH_COMMAND_SAMPLE_RATE=0.1           # 10% of commands

NIGHTWATCH_EXCEPTION_SAMPLE_RATE=1.0         # Always capture exceptions

# Filter noisy events

NIGHTWATCH_IGNORE_CACHE_EVENTS=true
NIGHTWATCH_IGNORE_QUERIES=true               # Or filter specific queries programmatically

```

### Privacy-Conscious Applications

```bash

# Disable sensitive data collection

NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD=false
NIGHTWATCH_REDACT_HEADERS=Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN

# Or use redaction in AppServiceProvider

```

### Balanced Configuration (Recommended Start)

```bash

# Sample rates

NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1
NIGHTWATCH_COMMAND_SAMPLE_RATE=1.0
NIGHTWATCH_EXCEPTION_SAMPLE_RATE=1.0

# Filter obvious noise programmatically

# Redact PII as needed

```

---

## Verification Checklist

After configuration:

- [ ] Sampling rates appropriate for traffic volume
- [ ] Noisy events filtered (cache, certain queries)
- [ ] Sensitive data redacted (PII, tokens, credentials)
- [ ] Exceptions always captured for debugging
- [ ] Test in development with `NIGHTWATCH_REQUEST_SAMPLE_RATE=1.0`
- [ ] Monitor event quota usage in Nightwatch dashboard

---

## Common Patterns

### Filter Health Checks + Reduce Sampling

```php
Route::get('/health', fn() => ['status' => 'ok'])
    ->middleware(Sample::never());
```

### Exclude Internal/Vendor Queries

```php
Nightwatch::rejectQueries(fn($q) =>
    str_contains($q->sql, 'telescope') ||
    str_contains($q->sql, 'pulse')
);
```

### Protect User Data in Cache Keys

```php
Nightwatch::redactCacheEvents(fn($e) =>
    $e->key = preg_replace('/user:\d+/', 'user:***', $e->key)
);
```
