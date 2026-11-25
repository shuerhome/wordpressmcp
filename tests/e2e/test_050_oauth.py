"""0.5.0 end-to-end: OAuth flow as a real MCP client would do it, tiers, rate limits, audit."""
import base64, hashlib, http.cookiejar, json, re, secrets, subprocess, sys, urllib.error, urllib.parse, urllib.request

S = sys.argv[1]
FIX = json.load(open(f"{S}/fixtures.json"))
P, O = FIX["product"], FIX["order"]
VERSION = FIX["version"]
BASE = "http://localhost:8899"
MCP = BASE + "/wp-json/mcp/v1/rpc"
LEGACY = open(f"{S}/token.txt").read().strip()
fails = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


jar = http.cookiejar.CookieJar()
browser = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), NoRedirect)
plain = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect)


def req(opener, method, url, data=None, headers=None, form=False):
    headers = dict(headers or {})
    body = None
    if data is not None:
        if form:
            body = urllib.parse.urlencode(data).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            body = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
    r = urllib.request.Request(url, body, headers, method=method)
    try:
        resp = opener.open(r)
        return resp.status, dict(resp.headers), resp.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode()


def check(name, cond, detail=""):
    global fails
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {str(detail)[:400]}"))
    if not cond:
        fails += 1


def rpc(token, method, params=None):
    st, h, b = req(plain, "POST", MCP, {"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}, {"Authorization": "Bearer " + token})
    return st, h, (json.loads(b) if b.startswith("{") else b)


def call(token, tool, args):
    st, h, d = rpc(token, "tools/call", {"name": tool, "arguments": args})
    r = d["result"]
    if r.get("isError"):
        return {"__error": r["content"][0]["text"]}
    return r["structuredContent"]


def php(code):
    out = subprocess.run(["php", "-r", f"$_SERVER['HTTP_HOST']='localhost:8899';$_SERVER['REQUEST_URI']='/';require '{S}/site/wp-load.php';{code}"], capture_output=True, text=True)
    return out.stdout


def pkce():
    v = secrets.token_urlsafe(48)
    c = base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b"=").decode()
    return v, c


# ---------------- migration & legacy token
st, h, d = rpc(LEGACY, "tools/list")
check("legacy token still works after migration", st == 200 and len(d["result"]["tools"]) == 17, (st, d))
st, h, d = rpc(LEGACY, "initialize", {"protocolVersion": "2025-06-18"})
check("server version", d["result"]["serverInfo"]["version"] == VERSION)
check("instructions mention full tier", "full administration" in d["result"]["instructions"])

# ---------------- discovery
st, h, b = req(plain, "POST", MCP, {"jsonrpc": "2.0", "id": 1, "method": "initialize"})
wa = h.get("WWW-Authenticate", "")
check("401 without token", st == 401, st)
m = re.search(r'resource_metadata="([^"]+)"', wa)
check("WWW-Authenticate points to resource metadata", bool(m), wa)
st, h, b = req(plain, "GET", m.group(1))
prm = json.loads(b)
check("protected resource metadata", st == 200 and prm["resource"] == MCP and prm["authorization_servers"] == [BASE], b)
st, h, b = req(plain, "GET", BASE + "/.well-known/oauth-protected-resource/wp-json/mcp/v1/rpc")
check("path-suffixed resource metadata", st == 200 and json.loads(b)["resource"] == MCP, (st, b[:200]))
st, h, b = req(plain, "GET", BASE + "/.well-known/oauth-authorization-server")
asm = json.loads(b)
check("authorization server metadata", st == 200 and asm["issuer"] == BASE and "S256" in asm["code_challenge_methods_supported"] and asm.get("registration_endpoint"), b)
check("CORS on metadata", h.get("Access-Control-Allow-Origin") == "*", h)
st, h, b = req(plain, "GET", BASE + "/.well-known/openid-configuration")
check("openid-configuration alias", st == 200 and json.loads(b)["issuer"] == BASE)

# ---------------- dynamic client registration
REDIRECT = "http://127.0.0.1:33418/callback"
st, h, b = req(plain, "POST", asm["registration_endpoint"], {"client_name": "Test Client", "redirect_uris": [REDIRECT], "token_endpoint_auth_method": "none", "grant_types": ["authorization_code", "refresh_token"]})
reg = json.loads(b)
check("DCR public client", st == 201 and reg["client_id"].startswith("mcpc_") and "client_secret" not in reg, b)
CID = reg["client_id"]
st, h, b = req(plain, "POST", asm["registration_endpoint"], {"redirect_uris": ["http://evil.example/cb"]})
check("DCR rejects non-loopback http", st == 400 and "invalid_redirect_uri" in b, b)
st, h, b = req(plain, "POST", asm["registration_endpoint"], {"redirect_uris": ["javascript:alert(1)"]})
check("DCR rejects javascript:", st == 400, b)
st, h, b = req(plain, "POST", asm["registration_endpoint"], {"client_name": "Secret Client", "redirect_uris": ["https://app.example/cb"]})
sreg = json.loads(b)
check("DCR confidential client gets secret", st == 201 and sreg.get("client_secret", "").startswith("mcps_"), b)


def authorize(client_id, redirect, tier, scope="", login=True):
    v, c = pkce()
    state = secrets.token_hex(8)
    q = {"response_type": "code", "client_id": client_id, "redirect_uri": redirect, "code_challenge": c, "code_challenge_method": "S256", "state": state, "resource": MCP}
    if scope:
        q["scope"] = scope
    url = asm["authorization_endpoint"] + "?" + urllib.parse.urlencode(q)
    st, h, b = req(browser, "GET", url)
    if st != 200:
        return None, (st, h.get("Location"), b[:300]), v, state
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', b).group(1)
    form = {**q, "_wpnonce": nonce, "decision": "allow", "tier": tier, "conn_name": f"Test {tier}"}
    st, h, b = req(browser, "POST", asm["authorization_endpoint"], form, form=True)
    return h.get("Location"), b, v, state


# not logged in -> redirect to wp-login
v, c = pkce()
st, h, b = req(browser, "GET", asm["authorization_endpoint"] + "?" + urllib.parse.urlencode({"response_type": "code", "client_id": CID, "redirect_uri": REDIRECT, "code_challenge": c, "code_challenge_method": "S256", "state": "x"}))
check("authorize redirects to login when logged out", st == 302 and "wp-login.php" in h.get("Location", ""), (st, h.get("Location")))
st, h, b = req(browser, "GET", asm["authorization_endpoint"] + "?" + urllib.parse.urlencode({"response_type": "code", "client_id": CID, "redirect_uri": "http://127.0.0.1:1/other", "code_challenge": c, "code_challenge_method": "S256"}))
check("authorize rejects unregistered redirect (no redirect)", st == 400 and "Location" not in h, (st, h.get("Location")))

# log in
req(browser, "GET", BASE + "/wp-login.php")
st, h, b = req(browser, "POST", BASE + "/wp-login.php", {"log": "admin", "pwd": "pass", "testcookie": "1", "redirect_to": BASE + "/wp-admin/"}, form=True)
check("wp-login", st == 302 and any(ck.name.startswith("wordpress_logged_in") for ck in jar), (st, b[:200]))

# consent page
q = {"response_type": "code", "client_id": CID, "redirect_uri": REDIRECT, "code_challenge": c, "code_challenge_method": "S256", "state": "s1", "scope": "mcp:read"}
st, h, b = req(browser, "GET", asm["authorization_endpoint"] + "?" + urllib.parse.urlencode(q))
check("consent page shows client & tiers", st == 200 and "Test Client" in b and 'value="full"' in b and re.search(r'value="read"\s+checked', b), re.findall(r'<input type="radio"[^>]*>', b))
check("consent page anti-framing", h.get("X-Frame-Options") == "DENY", h)
# deny
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', b).group(1)
st, h, b = req(browser, "POST", asm["authorization_endpoint"], {**q, "_wpnonce": nonce, "decision": "deny", "tier": "read"}, form=True)
check("deny returns access_denied to client", st == 302 and "error=access_denied" in h.get("Location", "") and "state=s1" in h["Location"], h.get("Location"))

# allow ops
loc, b, verifier, state = authorize(CID, REDIRECT, "ops")
check("allow redirects back with code+state", loc and loc.startswith(REDIRECT) and "code=" in loc and f"state={state}" in loc, (loc, b))
code = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["code"][0]

st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": REDIRECT, "client_id": CID, "code_verifier": verifier, "resource": MCP}, form=True)
tok = json.loads(b)
check("code exchange", st == 200 and tok["token_type"] == "Bearer" and tok["scope"] == "mcp:ops" and tok["refresh_token"], b)
check("token response no-store", h.get("Cache-Control") == "no-store", h)
st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": REDIRECT, "client_id": CID, "code_verifier": verifier}, form=True)
check("code replay rejected", st == 400 and "invalid_grant" in b, b)

loc, b, verifier2, _ = authorize(CID, REDIRECT, "ops")
code2 = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["code"][0]
st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "authorization_code", "code": code2, "client_id": CID, "code_verifier": "x" * 50}, form=True)
check("wrong PKCE verifier rejected", st == 400 and "invalid_grant" in b, b)

OPS = tok["access_token"]

# loopback port flexibility (RFC 8252)
loc, b, v3, _ = authorize(CID, "http://127.0.0.1:50999/callback", "read")
check("loopback redirect with different port accepted", loc and loc.startswith("http://127.0.0.1:50999/callback?code="), (loc, b[:200]))

# ---------------- ops tier enforcement
st, h, d = rpc(OPS, "initialize", {"protocolVersion": "2025-06-18"})
check("ops instructions", "operations" in d["result"]["instructions"])
r = call(OPS, "wc_products", {"action": "update", "id": P, "name": "Test Bag (ops)"})
check("ops: product update allowed", r.get("updated") is True or "product" in r, r)
r = call(OPS, "wc_orders", {"action": "refund", "id": O, "amount": 1})
check("ops: refund via wc_orders denied", "__error" in r and "运营" in r["__error"], r)
r = call(OPS, "wp_rest", {"action": "call", "method": "POST", "path": f"/wc/v3/orders/{O}/refunds", "body": {"amount": "1"}})
check("ops: refund via wp_rest denied", "__error" in r and "退款" in r["__error"], r)
r = call(OPS, "wp_rest", {"action": "call", "method": "PUT", "path": f"/wc/v3/products/{P}", "body": {"short_description": "ops edit"}, "query": {"_fields": "id"}})
check("ops: wp_rest product write allowed", r.get("status") == 200, r)
r = call(OPS, "wp_rest", {"action": "call", "method": "PUT", "path": "/wc/v3/payment_gateways/bacs", "body": {"enabled": True}})
check("ops: wp_rest gateway write denied", "__error" in r, r)
for tool, args in [("wp_plugins", {"action": "activate", "plugin": "hello.php"}), ("wp_system", {"action": "options_get", "option": "blogname"}), ("wc_settings", {"action": "set", "options": {"woocommerce_currency": "EUR"}}), ("wp_users", {"action": "create", "username": "x", "email": "x@example.com"})]:
    r = call(OPS, tool, args)
    check(f"ops: {tool} {args['action']} denied", "__error" in r and "运营" in r["__error"], r)
r = call(OPS, "wc_orders", {"action": "get", "id": O, "reveal_pii": True})
check("ops: reveal_pii honoured", r.get("billing", {}).get("email") == "anna@example.com" or "anna@example.com" in json.dumps(r), json.dumps(r)[:300])

# ---------------- read tier (static token)
out = php("$r=\\WPMCP\\Mcp\\Connections::create_static('Reader','read',1,7);echo $r['token'];")
READ = out.strip()
st, h, d = rpc(READ, "tools/list")
tools = d["result"]["tools"]
check("read: tools all readOnlyHint", tools and all(t["annotations"]["readOnlyHint"] for t in tools), [t["annotations"] for t in tools][:3])
r = call(READ, "wc_products", {"action": "update", "id": P, "name": "nope"})
check("read: write denied", "__error" in r and "只读" in r["__error"], r)
r = call(READ, "wp_rest", {"action": "call", "method": "PUT", "path": f"/wc/v3/products/{P}", "body": {"name": "nope"}})
check("read: wp_rest write denied", "__error" in r, r)
r = call(READ, "wp_rest", {"action": "call", "path": "/wc/v3/products", "query": {"_fields": "id"}})
check("read: wp_rest GET allowed", r.get("status") == 200, r)
r = call(READ, "wc_orders", {"action": "get", "id": O, "reveal_pii": True})
check("read: reveal_pii ignored (still masked)", "anna@example.com" not in json.dumps(r), json.dumps(r)[:300])

# ---------------- user capability caps tier
out = php("$u=wp_insert_user(['user_login'=>'mgr'.wp_rand(),'user_pass'=>'p','user_email'=>'m'.wp_rand().'@example.com','role'=>'shop_manager']);"
          "$f=\\WPMCP\\Mcp\\Connections::create_static('m','full',$u);echo is_wp_error($f)?'FULLDENIED':'FULLOK';echo ' ';"
          "$o=\\WPMCP\\Mcp\\Connections::create_static('m','ops',$u);echo $o['token'],' ',$u;")
parts = out.split()
check("shop_manager cannot get full tier", parts[0] == "FULLDENIED", out)
MGR, MGR_ID = parts[1], parts[2]
r = call(MGR, "wc_products", {"action": "list"})
check("shop_manager ops token works", "items" in r, r)
php(f"$u=new WP_User({MGR_ID});$u->set_role('subscriber');")
st, h, d = rpc(MGR, "tools/list")
check("demoted user's token rejected (403)", st == 403, (st, d))

# ---------------- refresh rotation & reuse detection
st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": CID}, form=True)
tok2 = json.loads(b)
check("refresh issues new tokens", st == 200 and tok2["access_token"] != OPS and tok2["refresh_token"] != tok["refresh_token"], b)
st, h, d = rpc(OPS, "tools/list")
check("old access token invalid after refresh", st == 401 and "invalid_token" in h.get("WWW-Authenticate", ""), (st, h.get("WWW-Authenticate")))
st, h, d = rpc(tok2["access_token"], "tools/list")
check("new access token works", st == 200)
st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": CID}, form=True)
check("reused old refresh token rejected", st == 400 and "invalid_grant" in b, b)
st, h, d = rpc(tok2["access_token"], "tools/list")
check("reuse detection revoked the connection", st == 401, st)

# ---------------- confidential client with Basic auth (must not be eaten by WP application passwords)
loc, b, v4, _ = authorize(sreg["client_id"], "https://app.example/cb", "read")
code4 = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["code"][0]
basic = base64.b64encode(f"{sreg['client_id']}:{sreg['client_secret']}".encode()).decode()
st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "authorization_code", "code": code4, "redirect_uri": "https://app.example/cb", "code_verifier": v4}, {"Authorization": "Basic " + basic}, form=True)
check("confidential client via Basic auth", st == 200 and json.loads(b)["scope"] == "mcp:read", b)
ctok = json.loads(b)
bad = base64.b64encode(f"{sreg['client_id']}:wrong".encode()).decode()
st, h, b = req(plain, "POST", asm["token_endpoint"], {"grant_type": "refresh_token", "refresh_token": ctok["refresh_token"]}, {"Authorization": "Basic " + bad}, form=True)
check("wrong client secret rejected", st == 401 and "invalid_client" in b, b)

# ---------------- revoke endpoint
st, h, b = req(plain, "POST", asm["revocation_endpoint"], {"token": ctok["access_token"], "client_id": sreg["client_id"], "client_secret": sreg["client_secret"]}, form=True)
st2, h2, d2 = rpc(ctok["access_token"], "tools/list")
check("revoke endpoint", st == 200 and st2 == 401, (st, b, st2))

# ---------------- access token not accepted in URL
loc, b, v5, _ = authorize(CID, REDIRECT, "full")
code5 = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["code"][0]
FULL = json.loads(req(plain, "POST", asm["token_endpoint"], {"grant_type": "authorization_code", "code": code5, "client_id": CID, "code_verifier": v5}, form=True)[2])["access_token"]
st, h, b = req(plain, "POST", MCP + "?token=" + FULL, {"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
check("OAuth access token rejected in ?token=", st == 401, st)
st, h, b = req(plain, "POST", MCP + "?token=" + LEGACY, {"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
check("static token still accepted in ?token=", st == 200, st)

# ---------------- confirm tokens bound to connection
first = call(FULL, "wc_coupons", {"action": "create", "code": "TEMP" + secrets.token_hex(3), "amount": "5"})
cid = (first.get("coupon") or first).get("id")
r = call(FULL, "wc_coupons", {"action": "delete", "id": cid})
r2 = call(LEGACY, "wc_coupons", {"action": "delete", "id": cid, "confirm_token": r.get("confirm_token")})
check("confirm token not usable by another connection", "error" in r2, r2)

# ---------------- rate limits
php("update_option('wp_mcp_rl_write',3);")
RL = php("$r=\\WPMCP\\Mcp\\Connections::create_static('RL','full',1);echo $r['token'];").strip()
res = [call(RL, "wc_products", {"action": "update", "id": P, "name": f"rl {i}"}) for i in range(4)]
check("write rate limit (4th of 3/min rejected)", all("__error" not in x for x in res[:3]) and "__error" in res[3] and "频繁" in res[3]["__error"], res[3])
r = call(RL, "wc_products", {"action": "list"})
check("reads unaffected by write limit", "items" in r, r)
php("update_option('wp_mcp_rl_write',5000);update_option('wp_mcp_rl_dangerous',1);")
ids = []
for i in range(2):
    c_ = call(FULL, "wc_coupons", {"action": "create", "code": "DEL" + secrets.token_hex(3), "amount": "1"})
    ids.append((c_.get("coupon") or c_).get("id"))
outs = []
for i in ids:
    t = call(FULL, "wc_coupons", {"action": "delete", "id": i})
    outs.append(call(FULL, "wc_coupons", {"action": "delete", "id": i, "confirm_token": t.get("confirm_token")}))
check("daily dangerous cap", "error" not in outs[0] and "error" in outs[1] and "上限" in outs[1]["error"], outs)
php("update_option('wp_mcp_rl_dangerous',50);")

# ---------------- audit
rows = json.loads(php("echo json_encode(\\WPMCP\\Safety\\Audit::query(['limit'=>500]));"))
names = {r_["connection_name"] for r_ in rows}
check("audit records connection names", "Test ops" in names and "Reader" in names, names)
check("audit records denials", any(r_["result"] == "denied" and "运营" in r_["message"] for r_ in rows))
check("audit records wp_rest target", any(r_["tool"] == "wp_rest" and r_["target"].startswith(f"PUT /wc/v3/products/{P}") for r_ in rows))
check("audit records oauth authorize", any(r_["tool"] == "oauth" and r_["action"] == "authorize" for r_ in rows))

# ---------------- admin page renders
st, h, b = req(browser, "GET", BASE + "/wp-admin/options-general.php?page=wp-mcp")
check("settings page renders", st == 200 and "连接列表" in b and "Test ops" in b and "审计日志" in b, (st, h.get("Location"), b[-300:]))
m2 = re.search(r'href="([^"]*wp_mcp_export=1[^"]*)"', b)
st, h, b = req(browser, "GET", m2.group(1).replace("&amp;", "&").replace("&#038;", "&") if m2 else BASE)
check("audit CSV export", st == 200 and "connection_id" in b and "text/csv" in h.get("Content-Type", ""), (st, b[:200]))

print("\nFAILURES:", fails)
sys.exit(1 if fails else 0)
