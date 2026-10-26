"""Real local HTTP smoke test. Uses isolated data and never contacts ARCA."""
import http.cookiejar
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")
storage = tempfile.mkdtemp(prefix="facturafacil-http-")
env = {**os.environ, "FACTURAFACIL_STORAGE": storage}
password = "Smoke-Test-" + os.urandom(12).hex()
process = None
checks = 0


def check(condition, label):
    global checks
    assert condition, label
    checks += 1


try:
    subprocess.run([PHP, "bin/install.php", "admin@example.test"], cwd=ROOT,
                   env=env, input=password + "\n" + password + "\n", text=True,
                   check=True, capture_output=True)
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    base = f"http://127.0.0.1:{port}"
    process = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", "public", "public/router.php"],
                               cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    def request(path, method="GET", data=None, csrf=None, form=False):
        headers = {}
        if csrf:
            headers["X-CSRF-Token"] = csrf
        if data is not None:
            if form:
                data = urllib.parse.urlencode(data).encode()
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            else:
                data = json.dumps(data).encode()
                headers["Content-Type"] = "application/json"
        req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
        try:
            response = opener.open(req, timeout=10)
        except urllib.error.HTTPError as err:
            response = err
        raw = response.read().decode()
        parsed = json.loads(raw) if "application/json" in response.headers.get("Content-Type", "") else raw
        return response.status, parsed, response.headers

    for _ in range(50):
        try:
            status, html, _ = request("/")
            break
        except urllib.error.URLError:
            time.sleep(0.05)
    check(status == 200 and 'name="password"' in html, "login is served")
    status, _, _ = request("/api/products")
    check(status == 401, "anonymous API access denied")
    csrf = re.search(r'name="_csrf" value="([a-f0-9]+)"', html).group(1)
    status, html, headers = request("/login", "POST", {"email": "admin@example.test", "password": password, "_csrf": csrf}, form=True)
    check(status == 200 and 'id="main"' in html, "real login and session persist")
    check("script-src 'self'" in headers.get("Content-Security-Policy", ""), "CSP present")
    csrf = re.search(r'name="csrf-token" content="([a-f0-9]+)"', html).group(1)
    status, _, _ = request("/api/products", "POST", {"code": "BAD"})
    check(status == 419, "writes without CSRF denied")
    config = {"company_name": "Empresa prueba", "cuit": "20123456786", "issuer_tax_condition": 1,
              "point_of_sale": 1, "arca_environment": "homologation", "default_voucher_type": 6,
              "default_vat_rate": "21", "prices_include_vat": True}
    status, result, _ = request("/api/settings", "POST", config, csrf)
    check(status == 200 and result["settings"]["cuit"] == config["cuit"], "settings saved")
    check("private_key_path" not in result["settings"] and "private_key_passphrase" not in result["settings"], "secrets not exposed")
    products = []
    for code, rate, price in [("P21", "21", "121"), ("P105", "10.5", "110,50")]:
        status, result, _ = request("/api/products", "POST", {"code": code, "name": "Producto " + rate,
                                                            "price": price, "vat_rate": rate}, csrf)
        check(status == 201, "product created")
        products.append(result["item"])
    status, result, _ = request("/api/customers", "POST", {"name": "Cliente <script>prueba</script>", "document_type": "DNI",
                                                        "document_number": "12345678", "tax_condition": 5}, csrf)
    check(status == 201, "customer created")
    customer = result["item"]
    payload = {"customer_id": customer["id"], "issue_date": time.strftime("%Y-%m-%d"), "voucher_type": 6,
               "items": [{"product_id": p["id"], "quantity": "1"} for p in products]}
    status, result, _ = request("/api/invoices/preview", "POST", payload, csrf)
    check(status == 200 and result["invoice"]["totals"]["total_cents"] == 23150
          and result["invoice"]["totals"]["vat_cents"] == 3150, "mixed VAT preview exact")
    status, result, _ = request("/api/invoices", "POST", payload, csrf)
    check(status == 201, "draft created")
    invoice = result["invoice"]
    status, _, _ = request("/api/invoices/issue", "POST", {"ids": [invoice["id"]], "confirmed": True,
                                                       "environment": "homologation"}, csrf)
    check(status == 422, "no fiscal request without certificate configuration")
    status, result, _ = request(f'/api/invoices/{invoice["id"]}')
    check(status == 200 and result["invoice"]["status"] == "draft", "blocked emission preserves draft")
    status, _, _ = request(f'/invoices/{invoice["id"]}/print')
    check(status == 422, "unauthorized draft cannot be printed as fiscal invoice")
    status, result, _ = request("/api/invoices/bulk-drafts", "POST", {**payload, "voucher_type": "auto", "customer_ids": [customer["id"]]}, csrf)
    check(status == 201 and result["count"] == 1, "bulk drafts endpoint works")
    # A synthetic authorized fixture tests the print view only, never a fiscal submission.
    printed_id = result["invoices"][0]["id"]
    with sqlite3.connect(storage + "/facturafacil.sqlite") as fixture:
        fixture.execute("UPDATE invoices SET status='authorized',number=1,cae='12345678901234',cae_due_date='2026-12-31' WHERE id=?", (printed_id,))
    status, printed, _ = request(f"/invoices/{printed_id}/print")
    check(status == 200 and "SIN VALIDEZ FISCAL" in printed and 'id="fiscal-qr"' in printed
          and "12345678901234" in printed, "authorized test fixture prints with QR and homologation label")
    for print_format in ["a4", "80mm", "50mm"]:
        status, printed, headers = request(f"/invoices/{printed_id}/print?format={print_format}")
        check(status == 200 and f'data-print-format="{print_format}"' in printed,
              "explicit print format is applied: " + print_format)
        check(f"/assets/print-{print_format}.css" in printed and 'id="print-format"' in printed,
              "selected print stylesheet and format control are present: " + print_format)
        check("12345678901234" in printed and 'id="fiscal-qr"' in printed
              and "10,5%" in printed and "21%" in printed and "Cliente &lt;script&gt;prueba&lt;/script&gt;" in printed,
              "every format preserves CAE, QR, VAT and escaped recipient: " + print_format)
    for bad_format in ["90mm", "80mm;size:A3", '"><script>window.print()</script>']:
        encoded = urllib.parse.quote(bad_format, safe="")
        status, printed, _ = request(f"/invoices/{printed_id}/print?format={encoded}")
        check(status == 422 and '<script>window.print()</script>' not in printed,
              "unrecognized or injected print format is rejected")
    status, _, _ = request(f"/invoices/{printed_id}/print?format%5B%5D=80mm")
    check(status == 422, "array print format is rejected without a server error")
    for print_format in ["80mm", "50mm", "a4"]:
        status, result, _ = request("/api/settings", "POST", {"print_format": print_format}, csrf)
        check(status == 200 and result["settings"]["print_format"] == print_format,
              "print format preference is saved: " + print_format)
        status, printed, _ = request(f"/invoices/{printed_id}/print")
        check(status == 200 and f'data-print-format="{print_format}"' in printed,
              "printing without query uses saved preference: " + print_format)
        if print_format == "80mm":
            status, printed, _ = request(f"/invoices/{printed_id}/print?format=a4")
            check(status == 200 and 'data-print-format="a4"' in printed,
                  "explicit print format overrides the saved preference")
            status, result, _ = request("/api/settings")
            check(status == 200 and result["settings"]["print_format"] == "80mm",
                  "one-time print format does not modify saved preference")
    status, _, _ = request("/api/settings", "POST", {"print_format": "90mm"}, csrf)
    check(status == 422, "invalid saved print format is rejected")
    status, result, _ = request("/api/settings")
    check(status == 200 and result["settings"]["print_format"] == "a4",
          "invalid setting does not overwrite the saved format")
    for asset in ["print.js", "print.css", "print-a4.css", "print-80mm.css", "print-50mm.css", "qrcode.js"]:
        status, _, _ = request("/assets/" + asset)
        check(status == 200, "print asset served")
    status, _, _ = request(f'/api/invoices/{invoice["id"]}', "DELETE", csrf=csrf)
    check(status == 200, "draft deletion removes dependent events safely")
    status, _, _ = request(f'/api/products/{products[0]["id"]}', "PUT", {**products[0], "name": "Actualizado", "price": "121"}, csrf)
    check(status == 200, "product update works")
    status, _, _ = request(f'/api/customers/{customer["id"]}', "DELETE", csrf=csrf)
    check(status == 200, "customer archives without deleting history")
    status, result, _ = request("/api/customers")
    check(status == 200 and not result["items"], "archived customers not listed")
    status, _, _ = request("/private/storage/facturafacil.sqlite")
    check(status == 404, "private storage not web accessible")
    status, _, _ = request("/api/password", "POST", {"current_password": password, "new_password": password + "x"}, csrf)
    check(status == 200, "password change works")
    # Backups run on isolated data; guard against recursive destination copies.
    backup_dir = tempfile.mkdtemp(prefix="facturafacil-backup-")
    try:
        backup = subprocess.run([PHP, "bin/backup.php", backup_dir], cwd=ROOT, env=env, capture_output=True, text=True)
        check(backup.returncode == 0 and list(Path(backup_dir).glob("*/facturafacil.sqlite")), "consistent backup created")
        bad_backup = subprocess.run([PHP, "bin/backup.php", storage + "/backups"], cwd=ROOT, env=env, capture_output=True, text=True)
        check(bad_backup.returncode != 0, "recursive backup destination rejected")
    finally:
        shutil.rmtree(backup_dir)
    print(f"HTTP: {checks} verificaciones correctas; sin solicitudes fiscales.")
finally:
    if process:
        process.terminate()
        process.wait(timeout=5)
    shutil.rmtree(storage)
