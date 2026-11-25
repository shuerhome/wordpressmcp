"""Smoke test: every tool's basic read action over MCP, plus the OpenAPI interface."""
import json, subprocess, sys, urllib.error, urllib.request

S = sys.argv[1]
TOKEN = open(f"{S}/token.txt").read().strip()
BASE = "http://localhost:8899/wp-json/mcp/v1"
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
fails = 0


def http(method, url, body=None, token=TOKEN):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    data = json.dumps(body).encode() if body is not None else None
    try:
        r = opener.open(urllib.request.Request(url, data, headers, method=method))
        return r.status, dict(r.headers), json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), json.loads(e.read() or b"{}")


def check(name, cond, detail=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {str(detail)[:300]}"))
    if not cond:
        fails += 1


READS = [("wp_site", "capabilities"), ("wp_media", "list"), ("wp_taxonomy", "list_taxonomies"), ("wp_users", "list"),
         ("wp_settings", "get"), ("wp_plugins", "list"), ("wp_system", "cron_list"), ("wp_rest", "namespaces"),
         ("wc_products", "list"), ("wc_orders", "list"), ("wc_reports", "sales"), ("wc_inventory", "low_stock"),
         ("wc_customers", "list"), ("wc_coupons", "list"), ("wc_settings", "get"), ("wc_shipping", "list_zones"),
         ("wc_webhooks", "list")]

# ---- MCP: every tool
for name, action in READS:
    st, h, d = http("POST", BASE + "/rpc", {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": name, "arguments": {"action": action}}})
    check(f"mcp {name} {action}", st == 200 and not d["result"].get("isError"), d)

# ---- tool descriptions are bilingual
st, h, d = http("POST", BASE + "/rpc", {"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
tools = d["result"]["tools"]
check("all tool descriptions start with English", all(t["description"][:1].isascii() and t["description"][:1].isalpha() for t in tools), [t["description"][:30] for t in tools])

# ---- OpenAPI document
st, h, doc = http("GET", BASE + "/openapi.json", token=None)
ops = {p["post"]["operationId"]: p["post"] for p in doc.get("paths", {}).values()}
check("openapi document", st == 200 and doc.get("openapi") == "3.0.3" and len(ops) == 17, (st, list(ops)))
check("openapi descriptions within 300 chars", all(len(o["description"]) <= 300 for o in ops.values()), [len(o["description"]) for o in ops.values()])
check("openapi security schemes", {"bearerAuth", "oauth2"} <= set(doc["components"]["securitySchemes"]), doc["components"]["securitySchemes"])
check("openapi marks write tools consequential", ops["wc_reports"]["x-openai-isConsequential"] is False and ops["wc_orders"]["x-openai-isConsequential"] is True)

# ---- OpenAPI tool calls
st, h, d = http("POST", BASE + "/tools/wc_products", {"action": "list", "per_page": 1})
check("openapi call ok", st == 200 and d["ok"] is True and "items" in d["result"], (st, d))
st, h, d = http("POST", BASE + "/tools/wc_products", {"arguments": {"action": "list"}})
check("openapi call with wrapped arguments", st == 200 and d["ok"] is True, (st, d))
st, h, d = http("POST", BASE + "/tools/wc_products", {"action": "list"}, token=None)
check("openapi call without token -> 401 + discovery header", st == 401 and "resource_metadata" in h.get("WWW-Authenticate", ""), (st, h))
st, h, d = http("POST", BASE + "/tools/nope", {"action": "list"})
check("openapi unknown tool -> 404", st == 404 and d["ok"] is False, (st, d))
st, h, d = http("POST", BASE + "/tools/wc_products", {"action": "fly"})
check("openapi bad action -> 400", st == 400 and d["ok"] is False, (st, d))

read_token = subprocess.run(
    ["php", "-r", f"$_SERVER['HTTP_HOST']='localhost:8899';$_SERVER['REQUEST_URI']='/';require '{S}/site/wp-load.php';"
     "$r=\\WPMCP\\Mcp\\Connections::create_static('Smoke reader','read',1,1);echo $r['token'];"],
    capture_output=True, text=True).stdout.strip()
st, h, d = http("POST", BASE + "/tools/wc_coupons", {"action": "create", "code": "X", "amount": "1"}, token=read_token)
check("openapi enforces tier -> 403", st == 403 and "只读" in d.get("error", ""), (st, d))
st, h, rdoc = http("GET", BASE + "/openapi.json", token=read_token)
check("openapi doc for read token still lists read tools", st == 200 and "wc_reports" in {p["post"]["operationId"] for p in rdoc["paths"].values()})

print("\nFAILURES:", fails)
sys.exit(1 if fails else 0)
