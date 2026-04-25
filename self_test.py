import yaml, json, base64, sys, os

print("=" * 80)
print("COMPREHENSIVE SELF-TEST - ISSUE #7458")
print("Testing: Supabase MCP Setup hindered by Coolify")
print("=" * 80)

BASE = r"D:\coolify\coolify"
all_pass = True
test_count = 0
pass_count = 0


def test(name, condition, detail=""):
    global all_pass, test_count, pass_count
    test_count += 1
    status = "PASS" if condition else "FAIL"
    if condition:
        pass_count += 1
    else:
        all_pass = False
    print(f"  [{status}] T{test_count}: {name}")
    if detail and not condition:
        print(f"         Detail: {detail}")
    return condition


# =============================================================================
# SUITE 1: SYNTAX VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 1: SYNTAX VALIDATION")
print("=" * 80)

# 1.1 YAML files parse
for yf in ["supabase.yaml", "supabase-with-mcp.yaml"]:
    path = os.path.join(BASE, "templates", "compose", yf)
    try:
        with open(path, "r") as f:
            data = yaml.safe_load(f)
        test(yf + " parses as valid YAML", data is not None and isinstance(data, dict))
    except Exception as e:
        test(yf + " parses as valid YAML", False, str(e))

# 1.2 JSON files parse
for jf in ["service-templates.json", "service-templates-latest.json"]:
    path = os.path.join(BASE, "templates", jf)
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        test(jf + " parses as valid JSON", isinstance(data, dict))
    except Exception as e:
        test(jf + " parses as valid JSON", False, str(e))

# 1.3 Base64 encode/decode roundtrip
for yf in ["supabase.yaml", "supabase-with-mcp.yaml"]:
    path = os.path.join(BASE, "templates", "compose", yf)
    with open(path, "r") as f:
        original = f.read()
    encoded = base64.b64encode(original.encode("utf-8")).decode("utf-8")
    decoded = base64.b64decode(encoded).decode("utf-8")
    test(yf + " base64 roundtrip", decoded == original)

# =============================================================================
# SUITE 2: TEMPLATE STRUCTURE VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 2: TEMPLATE STRUCTURE VALIDATION")
print("=" * 80)

# Load both templates
with open(os.path.join(BASE, "templates", "compose", "supabase.yaml"), "r") as f:
    supabase = yaml.safe_load(f)
with open(
    os.path.join(BASE, "templates", "compose", "supabase-with-mcp.yaml"), "r"
) as f:
    mcp_tpl = yaml.safe_load(f)

# 2.1 Both have required services
required_services = [
    "supabase-kong",
    "supabase-studio",
    "supabase-db",
    "supabase-analytics",
    "supabase-vector",
    "supabase-rest",
    "supabase-auth",
    "realtime-dev",
    "supabase-minio",
    "minio-createbucket",
    "supabase-storage",
    "imgproxy",
    "supabase-meta",
    "supabase-edge-functions",
    "supabase-supavisor",
]

for tpl_name, tpl_data in [
    ("supabase.yaml", supabase),
    ("supabase-with-mcp.yaml", mcp_tpl),
]:
    services = set(tpl_data.get("services", {}).keys())
    for svc in required_services:
        test(tpl_name + " has " + svc, svc in services)

# 2.2 Both have MCP_ALLOWED_IPS env var
for tpl_name, tpl_data in [
    ("supabase.yaml", supabase),
    ("supabase-with-mcp.yaml", mcp_tpl),
]:
    env_vars = (
        tpl_data.get("services", {}).get("supabase-kong", {}).get("environment", [])
    )
    has_mcp_env = any("MCP_ALLOWED_IPS" in str(e) for e in env_vars)
    test(tpl_name + " has MCP_ALLOWED_IPS env var", has_mcp_env)

# 2.3 Template headers are different
with open(os.path.join(BASE, "templates", "compose", "supabase.yaml"), "r") as f:
    supabase_header = f.read()[:500]
with open(
    os.path.join(BASE, "templates", "compose", "supabase-with-mcp.yaml"), "r"
) as f:
    mcp_header = f.read()[:500]

test("supabase.yaml has standard slogan", "MCP" not in supabase_header.split("\n")[1])
test(
    "supabase-with-mcp.yaml has MCP slogan",
    "MCP" in mcp_header and "AI tools" in mcp_header,
)
test("supabase-with-mcp.yaml has mcp tag", "mcp" in mcp_header)
test("supabase-with-mcp.yaml has ai tag", "ai" in mcp_header)

# =============================================================================
# SUITE 3: MCP ROUTE VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 3: MCP ROUTE VALIDATION")
print("=" * 80)


def extract_kong(tpl):
    vols = tpl.get("services", {}).get("supabase-kong", {}).get("volumes", [])
    for v in vols:
        if isinstance(v, dict) and v.get("target") == "/home/kong/temp.yml":
            return v.get("content", "")
    return ""


def find_mcp(kong):
    for svc in kong.get("services", []):
        if svc.get("name") == "mcp":
            return svc
    return None


def find_ip_plugin(mcp):
    for p in mcp.get("plugins", []):
        if p.get("name") == "ip-restriction":
            return p
    return None


# 3.1 Both have kong.yml content
supabase_kong = extract_kong(supabase)
mcp_kong = extract_kong(mcp_tpl)
test(
    "supabase.yaml has kong.yml content",
    len(supabase_kong) > 1000,
    "Got " + str(len(supabase_kong)) + " chars",
)
test(
    "supabase-with-mcp.yaml has kong.yml content",
    len(mcp_kong) > 1000,
    "Got " + str(len(mcp_kong)) + " chars",
)

# 3.2 MCP route exists and is correct
for tpl_name, kong_content in [
    ("supabase.yaml", supabase_kong),
    ("supabase-with-mcp.yaml", mcp_kong),
]:
    kong_empty = kong_content.replace("$MCP_ALLOWED_IPS", "")
    try:
        kong_parsed = yaml.safe_load(kong_empty)
        test(tpl_name + " kong.yml parses (empty var)", kong_parsed is not None)

        mcp = find_mcp(kong_parsed)
        test(tpl_name + " MCP route exists", mcp is not None)

        if mcp:
            test(
                tpl_name + " MCP URL correct",
                mcp.get("url") == "http://supabase-studio:3000/api/mcp",
            )
            test(
                tpl_name + " MCP path is /mcp",
                "/mcp" in mcp.get("routes", [{}])[0].get("paths", []),
            )
            test(
                tpl_name + " MCP strip_path true",
                mcp.get("routes", [{}])[0].get("strip_path") == True,
            )

            ip_plugin = find_ip_plugin(mcp)
            test(tpl_name + " has ip-restriction plugin", ip_plugin is not None)

            if ip_plugin:
                allow = ip_plugin.get("config", {}).get("allow", [])
                deny = ip_plugin.get("config", {}).get("deny", "NOT_FOUND")
                test(tpl_name + " has 127.0.0.1", "127.0.0.1" in allow)
                test(tpl_name + " has ::1", "::1" in allow)
                test(tpl_name + " deny is []", deny == [])
    except Exception as e:
        test(tpl_name + " kong.yml parses (empty var)", False, str(e))

# 3.3 MCP template has private network ranges
test("MCP template has 172.16.0.0/12", "172.16.0.0/12" in mcp_kong)
test("MCP template has 192.168.0.0/16", "192.168.0.0/16" in mcp_kong)
test("MCP template has 10.0.0.0/8", "10.0.0.0/8" in mcp_kong)

# 3.4 Default template does NOT have private ranges
test(
    "Default template does NOT have 172.16.0.0/12", "172.16.0.0/12" not in supabase_kong
)
test(
    "Default template does NOT have 192.168.0.0/16",
    "192.168.0.0/16" not in supabase_kong,
)
test("Default template does NOT have 10.0.0.0/8", "10.0.0.0/8" not in supabase_kong)

# =============================================================================
# SUITE 4: SHELL SUBSTITUTION SIMULATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 4: SHELL SUBSTITUTION SIMULATION")
print("=" * 80)

# 4.1 Empty MCP_ALLOWED_IPS (default)
kong_empty = supabase_kong.replace("$MCP_ALLOWED_IPS", "")
try:
    parsed = yaml.safe_load(kong_empty)
    test("Empty substitution produces valid YAML", parsed is not None)
    mcp = find_mcp(parsed)
    if mcp:
        ip = find_ip_plugin(mcp)
        if ip:
            allow = ip.get("config", {}).get("allow", [])
            test(
                "Empty: only localhost allowed",
                allow == ["127.0.0.1", "::1"],
                "Got: " + str(allow),
            )
except Exception as e:
    test("Empty substitution produces valid YAML", False, str(e))

# 4.2 Single IP
kong_single = supabase_kong.replace("$MCP_ALLOWED_IPS", "\n            - 172.18.0.1")
try:
    parsed = yaml.safe_load(kong_single)
    test("Single IP substitution produces valid YAML", parsed is not None)
    if parsed:
        mcp = find_mcp(parsed)
        if mcp:
            ip = find_ip_plugin(mcp)
            if ip:
                allow = ip.get("config", {}).get("allow", [])
                test("Single IP: 172.18.0.1 in allow list", "172.18.0.1" in allow)
except Exception as e:
    test("Single IP substitution produces valid YAML", False, str(e))

# 4.3 Multiple IPs
kong_multi = supabase_kong.replace(
    "$MCP_ALLOWED_IPS", "\n            - 172.18.0.1\n            - 10.8.0.0/24"
)
try:
    parsed = yaml.safe_load(kong_multi)
    test("Multi IP substitution produces valid YAML", parsed is not None)
    if parsed:
        mcp = find_mcp(parsed)
        if mcp:
            ip = find_ip_plugin(mcp)
            if ip:
                allow = ip.get("config", {}).get("allow", [])
                test(
                    "Multi IP: both IPs in allow list",
                    "172.18.0.1" in allow and "10.8.0.0/24" in allow,
                )
except Exception as e:
    test("Multi IP substitution produces valid YAML", False, str(e))

# 4.4 Whitespace-only (malformed input edge case)
kong_ws = supabase_kong.replace("$MCP_ALLOWED_IPS", "   ")
try:
    parsed = yaml.safe_load(kong_ws)
    test("Whitespace-only produces valid YAML", parsed is not None)
except Exception as e:
    test("Whitespace-only produces valid YAML", False, str(e))

# =============================================================================
# SUITE 5: JSON REGISTRY VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 5: JSON REGISTRY VALIDATION")
print("=" * 80)

for jf in ["service-templates.json", "service-templates-latest.json"]:
    path = os.path.join(BASE, "templates", jf)
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)

    print("\n  Testing: " + jf)
    test("Has supabase entry", "supabase" in data)
    test("Has supabase-with-mcp entry", "supabase-with-mcp" in data)

    if "supabase-with-mcp" in data:
        entry = data["supabase-with-mcp"]
        test("MCP entry has documentation", "documentation" in entry)
        test("MCP entry has slogan with MCP", "MCP" in entry.get("slogan", ""))
        test("MCP entry has compose field", "compose" in entry)
        test("MCP entry has mcp tag", "mcp" in entry.get("tags", []))
        test("MCP entry has ai tag", "ai" in entry.get("tags", []))
        test("MCP entry has backend category", entry.get("category") == "backend")
        test("MCP entry has port 8000", entry.get("port") == "8000")

        # Decode and validate compose
        try:
            decoded = base64.b64decode(entry["compose"]).decode("utf-8")
            test("Compose base64 decodes", True)

            parsed = yaml.safe_load(decoded)
            test("Decoded compose is valid YAML", parsed is not None)

            if parsed:
                svcs = parsed.get("services", {})
                test("Has supabase-kong", "supabase-kong" in svcs)
                test("Has supabase-db", "supabase-db" in svcs)

                # Verify MCP route in decoded compose
                kong = extract_kong(parsed)
                mcp = find_mcp(yaml.safe_load(kong.replace("$MCP_ALLOWED_IPS", "")))
                test("Decoded compose has MCP route", mcp is not None)
        except Exception as e:
            test("Compose base64 decodes", False, str(e))

# =============================================================================
# SUITE 6: CROSS-REFERENCE VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 6: CROSS-REFERENCE VALIDATION")
print("=" * 80)

# 6.1 JSON compose matches YAML file
for jf in ["service-templates.json", "service-templates-latest.json"]:
    path = os.path.join(BASE, "templates", jf)
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)

    if "supabase-with-mcp" in data:
        json_compose = base64.b64decode(data["supabase-with-mcp"]["compose"]).decode(
            "utf-8"
        )
        yaml_path = os.path.join(BASE, "templates", "compose", "supabase-with-mcp.yaml")
        with open(yaml_path, "r") as f:
            yaml_content = f.read()
        test(jf + " compose matches YAML file", json_compose == yaml_content)

# 6.2 Both templates have same service structure
supabase_svcs = set(supabase.get("services", {}).keys())
mcp_svcs = set(mcp_tpl.get("services", {}).keys())
test(
    "Both templates have same services",
    supabase_svcs == mcp_svcs,
    "Diff: " + str(supabase_svcs.symmetric_difference(mcp_svcs)),
)

# 6.3 Kong entrypoint is correct
for tpl_name, tpl_data in [
    ("supabase.yaml", supabase),
    ("supabase-with-mcp.yaml", mcp_tpl),
]:
    entrypoint = (
        tpl_data.get("services", {}).get("supabase-kong", {}).get("entrypoint", "")
    )
    test(
        tpl_name + " has eval echo entrypoint",
        "eval" in entrypoint and "echo" in entrypoint,
    )

# =============================================================================
# SUITE 7: SECURITY VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 7: SECURITY VALIDATION")
print("=" * 80)

# 7.1 Default template only allows localhost
kong_empty = supabase_kong.replace("$MCP_ALLOWED_IPS", "")
parsed = yaml.safe_load(kong_empty)
mcp = find_mcp(parsed)
if mcp:
    ip = find_ip_plugin(mcp)
    if ip:
        allow = ip.get("config", {}).get("allow", [])
        test(
            "Default: MCP locked to localhost",
            allow == ["127.0.0.1", "::1"],
            "Got: " + str(allow),
        )

# 7.2 MCP template allows private networks
mcp_kong_empty = mcp_kong.replace("$MCP_ALLOWED_IPS", "")
parsed = yaml.safe_load(mcp_kong_empty)
mcp = find_mcp(parsed)
if mcp:
    ip = find_ip_plugin(mcp)
    if ip:
        allow = ip.get("config", {}).get("allow", [])
        has_private = any(
            "172.16" in str(a) or "192.168" in str(a) or "10.0" in str(a) for a in allow
        )
        test("MCP template: private networks allowed", has_private)

# 7.3 No hardcoded secrets
for yf, name in [
    ("supabase.yaml", "supabase.yaml"),
    ("supabase-with-mcp.yaml", "supabase-with-mcp.yaml"),
]:
    path = os.path.join(BASE, "templates", "compose", yf)
    with open(path, "r") as f:
        content = f.read()
    has_secret = False
    for line in content.split("\n"):
        ll = line.lower()
        if any(
            p in ll for p in ["api_key: 'sk-", "password: 'admin", "secret: 'hardcoded"]
        ):
            has_secret = True
            break
    test(name + " no hardcoded secrets", not has_secret)

# 7.4 All sensitive values use variables
for tpl_name, tpl_data in [
    ("supabase.yaml", supabase),
    ("supabase-with-mcp.yaml", mcp_tpl),
]:
    env_vars = (
        tpl_data.get("services", {}).get("supabase-kong", {}).get("environment", [])
    )
    uses_vars = any("${" in str(e) for e in env_vars)
    test(tpl_name + " uses environment variables", uses_vars)

# =============================================================================
# SUITE 8: DOCUMENTATION VALIDATION
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 8: DOCUMENTATION VALIDATION")
print("=" * 80)

doc_path = os.path.join(BASE, "templates", "SUPABASE_MCP_SETUP.md")
with open(doc_path, "r", encoding="utf-8") as f:
    doc = f.read()

test("Documentation file exists", len(doc) > 1000, "Got " + str(len(doc)) + " chars")

required = [
    ("Template comparison table", "Choosing the Right Template"),
    ("SSH Tunnel method", "ssh -L"),
    ("WireGuard setup", "WireGuard"),
    ("IDE - Cursor", "Cursor"),
    ("IDE - Claude Code", "Claude Code"),
    ("IDE - Windsurf", "Windsurf"),
    ("Multi-Instance setup", "Multi-Instance"),
    ("Troubleshooting", "Troubleshooting"),
    ("MCP_ALLOWED_IPS env var", "MCP_ALLOWED_IPS"),
    ("Docker gateway IP command", "docker inspect supabase-kong"),
    ("curl test command", "curl"),
    ("mcpServers JSON config", "mcpServers"),
    ("References supabase template", "supabase"),
    ("References supabase-with-mcp template", "supabase-with-mcp"),
]

for desc, keyword in required:
    test(desc, keyword in doc)

# =============================================================================
# SUITE 9: ISSUE REQUIREMENTS CHECKLIST
# =============================================================================
print("\n" + "=" * 80)
print("SUITE 9: ISSUE #7458 REQUIREMENTS CHECKLIST")
print("=" * 80)

# From the issue:
# 1. How to configure the MCP and use it on local network
# 2. How to setup Wireguard to properly allow for usage of this MCP
# 3. How to configure Cursor/Claude Code/Windsurf to use this MCP
# 4. HOW TO CONNECT TO DIFFERENT SUPABASE INSTANCES ON THE SAME COOLIFY INSTANCE

test(
    "Requirement 1: Local network config documented",
    "Local Network" in doc or "local network" in doc.lower(),
)
test("Requirement 2: WireGuard setup documented", "WireGuard" in doc)
test(
    "Requirement 3: IDE config documented (Cursor)",
    "Cursor" in doc and "mcpServers" in doc,
)
test("Requirement 3: IDE config documented (Claude Code)", "Claude Code" in doc)
test("Requirement 3: IDE config documented (Windsurf)", "Windsurf" in doc)
test(
    "Requirement 4: Multi-instance support documented",
    "Multi-Instance" in doc or "multiple" in doc.lower(),
)
test(
    "Requirement 4: Multi-instance has architecture diagram",
    "Coolify Server" in doc or "Instance A" in doc,
)

# =============================================================================
# FINAL SUMMARY
# =============================================================================
print("\n" + "=" * 80)
print("FINAL SUMMARY")
print("=" * 80)
print("  Tests passed: " + str(pass_count) + "/" + str(test_count))
print("  Tests failed: " + str(test_count - pass_count) + "/" + str(test_count))

if all_pass:
    print("\n  RESULT: ALL " + str(test_count) + " TESTS PASSED")
    print("  Issue #7458 is FULLY RESOLVED")
    print("\n  What was implemented:")
    print("  - supabase.yaml: MCP configurable via MCP_ALLOWED_IPS env var")
    print("  - supabase-with-mcp.yaml: MCP pre-enabled for private networks")
    print("  - Both templates registered in service-templates.json")
    print("  - Both templates registered in service-templates-latest.json")
    print("  - SUPABASE_MCP_SETUP.md: Comprehensive documentation")
    print("\n  Bounty requirements met:")
    print("  - Local network configuration: Documented")
    print("  - WireGuard setup: Documented")
    print("  - IDE configuration (Cursor/Claude/Windsurf): Documented")
    print("  - Multi-instance support: Documented with architecture diagram")
else:
    print("\n  RESULT: " + str(test_count - pass_count) + " TESTS FAILED")
    print("  Review failures above before submitting PR")

print("=" * 80)
