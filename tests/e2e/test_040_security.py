"""0.4.0 end-to-end checks against the local test site."""
import base64, json, subprocess, sys, urllib.request

S = sys.argv[1]
FIX = json.load(open(f"{S}/fixtures.json"))
P, O = FIX["product"], FIX["order"]
VERSION = FIX["version"]
TOKEN = open(f"{S}/token.txt").read().strip()
URL = "http://localhost:8899/index.php?rest_route=/mcp/v1/rpc"
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
fails = 0


def rpc(method, params=None):
    body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}).encode()
    req = urllib.request.Request(URL, body, {"Authorization": "Bearer " + TOKEN, "Content-Type": "application/json"})
    return json.load(opener.open(req))


def call(tool, args):
    r = rpc("tools/call", {"name": tool, "arguments": args})["result"]
    if r.get("isError"):
        return {"__error": r["content"][0]["text"]}
    return r["structuredContent"]


def check(name, cond, detail=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {detail}"))
    if not cond:
        fails += 1


def confirmed(tool, args):
    """Run a call through the confirm flow; return (first, final)."""
    first = call(tool, args)
    tok = first.get("confirm_token")
    final = call(tool, {**args, "confirm_token": tok}) if tok else None
    return first, final


def php(code):
    subprocess.run(["php", "-r", f"$_SERVER['HTTP_HOST']='localhost:8899';$_SERVER['REQUEST_URI']='/';require '{S}/site/wp-load.php';{code}"], check=True)


# --- protocol / annotations
tools = {t["name"]: t for t in rpc("tools/list")["result"]["tools"]}
check("17 tools", len(tools) == 17, list(tools))
check("annotations present on all", all("annotations" in t and "title" in t for t in tools.values()))
check("wc_reports readOnly", tools["wc_reports"]["annotations"]["readOnlyHint"] is True)
check("wp_rest destructive", tools["wp_rest"]["annotations"].get("destructiveHint") is True)
check("wp_media openWorld", tools["wp_media"]["annotations"]["openWorldHint"] is True)
check("serverInfo version", rpc("initialize", {"protocolVersion": "2025-06-18"})["result"]["serverInfo"]["version"] == VERSION)

# --- upgrade cleanup
out = call("wp_system", {"action": "options_get", "option": "wp_mcp_design_backups"})
check("old design backups removed", out.get("value") is None, out)

# --- sensitive routes need confirmation
for label, args in [
    ("refund", {"method": "POST", "path": f"/wc/v3/orders/{O}/refunds", "body": {"amount": "1"}}),
    ("product batch", {"method": "POST", "path": "/wc/v3/products/batch", "body": {"delete": [10]}}),
    ("core batch", {"method": "POST", "path": "/batch/v1", "body": {"requests": []}}),
    ("create user", {"method": "POST", "path": "/wp/v2/users", "body": {"username": "x", "email": "x@example.com", "password": "p", "roles": ["administrator"]}}),
    ("plugin activate", {"method": "POST", "path": "/wp/v2/plugins/hello", "body": {"status": "active"}}),
    ("site settings", {"method": "POST", "path": "/wp/v2/settings", "body": {"title": "x"}}),
    ("gateway", {"method": "PUT", "path": "/wc/v3/payment_gateways/bacs", "body": {"enabled": True}}),
    ("webhook", {"method": "POST", "path": "/wc/v3/webhooks", "body": {"topic": "order.created", "delivery_url": "https://example.com/h"}}),
]:
    r = call("wp_rest", {"action": "call", **args})
    check(f"confirm required: {label}", r.get("confirmation_required") is True, r)

r = call("wp_rest", {"action": "call", "method": "PUT", "path": f"/wc/v3/products/{P}", "body": {"short_description": "ok"}, "query": {"_fields": "id"}})
check("plain product update not gated", r.get("status") == 200, r)

r = call("wp_rest", {"action": "call", "path": f"/wc/v3/products/{P}", "query": {"_method": "DELETE", "_fields": "id"}})
still = call("wp_rest", {"action": "call", "path": f"/wc/v3/products/{P}", "query": {"_fields": "id"}})
check("_method override ignored", still.get("status") == 200 and still["data"]["id"] == P, (r, still))

# refund actually runs after confirmation
first, final = confirmed("wp_rest", {"action": "call", "method": "POST", "path": f"/wc/v3/orders/{O}/refunds", "body": {"amount": "1.00", "api_refund": False}, "query": {"_fields": "id,amount"}})
check("refund executes after confirm", final and final.get("status") == 201, final)

# --- secrets
first, final = confirmed("wc_webhooks", {"action": "create", "topic": "order.created", "delivery_url": "https://example.com/hook", "name": "t"})
check("wc_webhooks create needs confirm", first.get("confirmation_required") is True, first)
wid = final["webhook"]["id"] if "webhook" in final else final.get("id")
php("$w=wc_get_webhook(%d);file_put_contents('%s/sec.txt',$w->get_secret());" % (wid, S))
real = open(f"{S}/sec.txt").read()
wh = call("wp_rest", {"action": "call", "path": "/wc/v3/webhooks/%d" % wid})
check("webhook secret not exposed via wp_rest", real and real not in json.dumps(wh), wh)
g = call("wc_webhooks", {"action": "get", "id": wid})
check("webhook secret hidden via wc_webhooks", g.get("secret") == "[hidden]", g)

o = call("wp_system", {"action": "options_get", "option": "woocommerce_fakepay_settings"})
check("gateway secret hidden in options_get", o["value"]["secret_key"] == "[hidden]" and o["value"]["publishable_key"] == "pk_1", o)
o = call("wp_system", {"action": "options_get", "option": "fakepay_api_key"})
check("scalar secret option hidden", o["value"] == "[hidden]", o)

# writing back the masked value keeps the real secret
v = dict(o2 := call("wp_system", {"action": "options_get", "option": "woocommerce_fakepay_settings"})["value"])
v["title"] = "Fake 2"
first, final = confirmed("wp_system", {"action": "options_set", "option": "woocommerce_fakepay_settings", "value": json.dumps(v)})
php("$o=get_option('woocommerce_fakepay_settings');file_put_contents('" + S + "/opt.json',json_encode($o));")
stored = json.load(open(f"{S}/opt.json"))
check("options_set with [hidden] preserves real secret", stored["secret_key"] == "sk_live_REAL" and stored["title"] == "Fake 2", stored)

gw = call("wp_rest", {"action": "call", "path": "/wc/v3/payment_gateways"})
leaked = "sk_live_REAL" in json.dumps(gw)
check("no raw secret anywhere in gateway list", not leaked)

# --- protected options
for opt in ["siteurl", "active_plugins", "wp_mcp_bound_user", "wp_user_roles", "default_role"]:
    r = call("wp_system", {"action": "options_set", "option": opt, "value": "x"})
    check(f"protected option {opt}", "__error" in r and "受保护" in r["__error"], r)

# --- dedicated tools now gated
r = call("wp_users", {"action": "set_role", "id": 1, "role_to": "editor"})
check("wp_users set_role needs confirm", r.get("confirmation_required") is True, r)
r = call("wp_plugins", {"action": "deactivate", "plugin": "woocommerce/woocommerce.php"})
check("wp_plugins deactivate needs confirm", r.get("confirmation_required") is True, r)

# --- media base64
png = base64.b64encode(bytes.fromhex(
    "89504e470d0a1a0a0000000d4948445200000001000000010806000000"
    "1f15c4890000000d49444154789c6360000002000100e221bc330000000049454e44ae426082")).decode()
r = call("wp_media", {"action": "upload", "data": "data:image/png;base64," + png, "filename": "dot.png", "alt": "dot"})
check("base64 png upload", r.get("uploaded") is True, r)
mid = r.get("media", {}).get("id")
r = call("wp_rest", {"action": "call", "method": "PUT", "path": f"/wc/v3/products/{P}", "body": {"images": [{"id": mid}]}, "query": {"_fields": "images"}})
check("uploaded image set as product image", r.get("status") == 200 and r["data"]["images"][0]["id"] == mid, r)
r = call("wp_media", {"action": "upload", "data": base64.b64encode(b"<?php echo 1;").decode(), "filename": "evil.php"})
check("php upload rejected", "__error" in r, r)
r = call("wp_media", {"action": "upload", "data": "!!!notbase64", "filename": "a.png"})
check("bad base64 rejected", "__error" in r, r)

# --- namespace allowlist
php("update_option('wp_mcp_rest_namespaces','wc/v3');")
r = call("wp_rest", {"action": "call", "path": "/wp/v2/users"})
check("allowlist blocks wp/v2", "__error" in r and "命名空间" in r["__error"], r)
r = call("wp_rest", {"action": "routes", "search": "users"})
check("allowlist filters routes", all(x["route"].startswith("/wc/v3") for x in r["routes"]), r)
ns = call("wp_rest", {"action": "namespaces"})["namespaces"]
check("allowlist filters namespaces", ns == ["wc/v3"], ns)
r = call("wp_rest", {"action": "call", "path": "/wc/v3/products", "query": {"_fields": "id"}})
check("allowlist permits wc/v3", r.get("status") == 200, r)
php("update_option('wp_mcp_rest_namespaces','');")

print("\nFAILURES:", fails)
sys.exit(1 if fails else 0)
