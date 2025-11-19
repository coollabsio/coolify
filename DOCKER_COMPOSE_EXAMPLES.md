# Docker Compose Examples for Testing Health Status Aggregation

These example docker-compose files demonstrate different container health status scenarios to test the "unknown" health state aggregation fix.

## Prerequisites

```bash
# Make sure Docker is running
docker --version
```

## Test Cases

### 1. **Healthy** - All containers with passing health checks

**File:** `docker-compose.healthy.yml`

```bash
docker-compose -f docker-compose.healthy.yml up -d
docker-compose -f docker-compose.healthy.yml ps
docker inspect $(docker-compose -f docker-compose.healthy.yml ps -q web) | grep -A 5 '"Health"'
```

**Expected Status:** `running (healthy)`
- Container has healthcheck that successfully connects to nginx on port 80

**Cleanup:**
```bash
docker-compose -f docker-compose.healthy.yml down
```

---

### 2. **Unknown** - Container without health check

**File:** `docker-compose.unknown.yml`

```bash
docker-compose -f docker-compose.unknown.yml up -d
docker-compose -f docker-compose.unknown.yml ps
docker inspect $(docker-compose -f docker-compose.unknown.yml ps -q web) | grep -A 5 '"Health"'
```

**Expected Status:** `running (unknown)`
- Container has NO healthcheck defined
- `State.Health` key is missing from Docker inspect output

**Cleanup:**
```bash
docker-compose -f docker-compose.unknown.yml down
```

---

### 3. **Unhealthy** - Container with failing health check

**File:** `docker-compose.unhealthy.yml`

```bash
docker-compose -f docker-compose.unhealthy.yml up -d
# Wait 30 seconds for health check to fail
sleep 30
docker-compose -f docker-compose.unhealthy.yml ps
docker inspect $(docker-compose -f docker-compose.unhealthy.yml ps -q web) | grep -A 5 '"Health"'
```

**Expected Status:** `running (unhealthy)`
- Container has healthcheck that tries to connect to port 9999 (which doesn't exist)
- Health check will fail after retries

**Cleanup:**
```bash
docker-compose -f docker-compose.unhealthy.yml down
```

---

### 4. **Mixed: Healthy + Unknown** → Should show "unknown"

**File:** `docker-compose.mixed-healthy-unknown.yml`

```bash
docker-compose -f docker-compose.mixed-healthy-unknown.yml up -d
docker-compose -f docker-compose.mixed-healthy-unknown.yml ps
docker inspect $(docker-compose -f docker-compose.mixed-healthy-unknown.yml ps -q web) | grep -A 5 '"Health"'
docker inspect $(docker-compose -f docker-compose.mixed-healthy-unknown.yml ps -q worker) | grep -A 5 '"Health"'
```

**Expected Aggregated Status:** `running (unknown)` ← **This is the fix!**
- `web` container: `running (healthy)` - has passing healthcheck
- `worker` container: `running (unknown)` - no healthcheck
- **Before fix:** Would show `running (healthy)` ❌
- **After fix:** Shows `running (unknown)` ✅

**Cleanup:**
```bash
docker-compose -f docker-compose.mixed-healthy-unknown.yml down
```

---

### 5. **Mixed: Unhealthy + Unknown** → Should show "unhealthy"

**File:** `docker-compose.mixed-unhealthy-unknown.yml`

```bash
docker-compose -f docker-compose.mixed-unhealthy-unknown.yml up -d
# Wait 30 seconds for health check to fail
sleep 30
docker-compose -f docker-compose.mixed-unhealthy-unknown.yml ps
docker inspect $(docker-compose -f docker-compose.mixed-unhealthy-unknown.yml ps -q web) | grep -A 5 '"Health"'
docker inspect $(docker-compose -f docker-compose.mixed-unhealthy-unknown.yml ps -q worker) | grep -A 5 '"Health"'
```

**Expected Aggregated Status:** `running (unhealthy)`
- `web` container: `running (unhealthy)` - failing healthcheck
- `worker` container: `running (unknown)` - no healthcheck
- Unhealthy takes priority over unknown

**Cleanup:**
```bash
docker-compose -f docker-compose.mixed-unhealthy-unknown.yml down
```

---

### 6. **Excluded Container** - Unhealthy container excluded from health checks

**File:** `docker-compose.excluded.yml`

```bash
docker-compose -f docker-compose.excluded.yml up -d
# Wait 30 seconds for health check to fail
sleep 30
docker-compose -f docker-compose.excluded.yml ps
docker inspect $(docker-compose -f docker-compose.excluded.yml ps -q web) | grep -A 5 '"Health"'
docker inspect $(docker-compose -f docker-compose.excluded.yml ps -q backup) | grep -A 5 '"Health"'
```

**Expected Aggregated Status:** `running (healthy)`
- `web` container: `running (healthy)` - passing healthcheck
- `backup` container: `running (unhealthy)` - but has `exclude_from_hc: true`
- Excluded containers don't affect aggregation

**Cleanup:**
```bash
docker-compose -f docker-compose.excluded.yml down
```

---

## Quick Test All Cases

```bash
# Test healthy
echo "=== Testing HEALTHY ==="
docker-compose -f docker-compose.healthy.yml up -d && sleep 15
docker inspect $(docker-compose -f docker-compose.healthy.yml ps -q web) --format='{{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}unknown{{end}})'
docker-compose -f docker-compose.healthy.yml down

# Test unknown
echo -e "\n=== Testing UNKNOWN ==="
docker-compose -f docker-compose.unknown.yml up -d && sleep 5
docker inspect $(docker-compose -f docker-compose.unknown.yml ps -q web) --format='{{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}unknown{{end}})'
docker-compose -f docker-compose.unknown.yml down

# Test unhealthy
echo -e "\n=== Testing UNHEALTHY ==="
docker-compose -f docker-compose.unhealthy.yml up -d && sleep 35
docker inspect $(docker-compose -f docker-compose.unhealthy.yml ps -q web) --format='{{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}unknown{{end}})'
docker-compose -f docker-compose.unhealthy.yml down

# Test mixed healthy + unknown
echo -e "\n=== Testing MIXED HEALTHY + UNKNOWN ==="
docker-compose -f docker-compose.mixed-healthy-unknown.yml up -d && sleep 15
echo "Web: $(docker inspect $(docker-compose -f docker-compose.mixed-healthy-unknown.yml ps -q web) --format='{{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}unknown{{end}})')"
echo "Worker: $(docker inspect $(docker-compose -f docker-compose.mixed-healthy-unknown.yml ps -q worker) --format='{{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}unknown{{end}})')"
echo "Expected Aggregated: running (unknown)"
docker-compose -f docker-compose.mixed-healthy-unknown.yml down

# Cleanup all
echo -e "\n=== Cleaning up ==="
docker-compose -f docker-compose.healthy.yml down 2>/dev/null
docker-compose -f docker-compose.unknown.yml down 2>/dev/null
docker-compose -f docker-compose.unhealthy.yml down 2>/dev/null
docker-compose -f docker-compose.mixed-healthy-unknown.yml down 2>/dev/null
docker-compose -f docker-compose.mixed-unhealthy-unknown.yml down 2>/dev/null
docker-compose -f docker-compose.excluded.yml down 2>/dev/null
```

## Understanding the Output

### Docker Inspect Health Status
```json
"Health": {
    "Status": "healthy",    // or "unhealthy", "starting"
    "FailingStreak": 0,
    "Log": [...]
}
```

If `"Health"` key is missing → Container has no healthcheck → Shows as `unknown`

### Coolify Status Format
Individual containers: `"<status> (<health>)"`
- `"running (healthy)"` - Container running with passing healthcheck
- `"running (unhealthy)"` - Container running with failing healthcheck
- `"running (unknown)"` - Container running with no healthcheck
- `"running (starting)"` - Container running, healthcheck in initial grace period

### Aggregation Priority (after fix)
1. **Unhealthy** (highest) - If ANY container is unhealthy
2. **Unknown** (medium) - If no unhealthy, but ≥1 has no healthcheck
3. **Healthy** (lowest) - Only when ALL containers explicitly healthy
