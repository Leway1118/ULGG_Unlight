"""M2 loopback-only synthetic HTTP + real Chromium mobile/desktop E2E.

Never connects to production database, Steam, Watcher, or the internet.
Starts local PHP CLI built-in servers ONLY on 127.0.0.1; always terminates.
"""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import shutil
from pathlib import Path
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT  # repository root from tests/checkpoint2026/
AS_OF = "2026-11-24 10:00:00"


def check(condition, label):
    if not condition:
        raise AssertionError("STOP_" + label)


def choose_port():
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def launch_server(php_binary, stage_enabled):
    port = choose_port()
    env = dict(os.environ)
    env.pop("ULGG_CHECKPOINT2026_M2_STAGE", None)
    if stage_enabled:
        env["ULGG_CHECKPOINT2026_M2_STAGE"] = "synthetic-only"
    env["PHP_CLI_SERVER_WORKERS"] = "1"
    proc = subprocess.Popen(
        [php_binary, "-S", "127.0.0.1:" + str(port), "-t", str(SRC)],
        cwd=SRC,
        env=env,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
    )
    url = "http://127.0.0.1:" + str(port)
    started = time.monotonic()
    while time.monotonic() - started < 6:
        if proc.poll() is not None:
            raise RuntimeError("BLOCKED_LOOPBACK_SERVER_START")
        try:
            urllib.request.urlopen(url + "/pages/checkpoint2026/index.php", timeout=0.35).close()
            return proc, url
        except urllib.error.HTTPError:
            return proc, url
        except (urllib.error.URLError, OSError, TimeoutError):
            time.sleep(0.08)
    proc.terminate()
    raise RuntimeError("BLOCKED_LOOPBACK_SERVER_TIMEOUT")


def request(opener, url, method="GET", body=None):
    req = urllib.request.Request(
        url, method=method,
        data=(json.dumps(body).encode("utf-8") if body is not None else None),
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
    )
    try:
        with opener.open(req, timeout=3) as resp:
            return resp.status, dict(resp.headers.items()), resp.read().decode("utf-8")
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers.items()), e.read().decode("utf-8")


def decode(body):
    return json.loads(body)


def http_cases(base, results):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    status, headers, data = request(opener, base + "/api/checkpoint2026/me.php")
    check(status == 401 and decode(data)["error"] == "authentication_required", "HTTP_ANON_PRIVATE")
    results.append("ANONYMOUS_PRIVATE_401")

    status, headers, data = request(opener, base + "/api/checkpoint2026/public.php")
    check(status == 200, "HTTP_PUBLIC_STATUS")
    public = decode(data)
    check(public["access"] == "PUBLIC", "HTTP_PUBLIC_ACCESS")
    check(public["payload"]["cup"]["verified_games"] == 2, "HTTP_PUBLIC_CUP")
    check(public["payload"]["cup"]["published_report_count"] == 1, "HTTP_PUBLIC_PUBLISHED")
    check(public["payload"]["cup"]["personal_cup_status"] == "UNKNOWN", "HTTP_PERSONAL_CUP_UNVERIFIED")
    check("no-store" in headers.get("Cache-Control", ""), "HTTP_PUBLIC_NOSTORE")
    results.append("PUBLIC_CUP_ALLOWLIST")

    status, headers, data = request(
        opener, base + "/api/checkpoint2026/_fixture_login.php",
        "POST", {"who": "A"}
    )
    check(status == 200 and decode(data)["fixture"] == "A", "HTTP_LOGIN_A")
    status, headers, data = request(opener, base + "/api/checkpoint2026/me.php")
    check(status == 200, "HTTP_PRIVATE_A")
    a = decode(data)
    check(a["payload"]["pvp"]["verified_matches"] == 3, "HTTP_PRIVATE_A_PVP")
    check([a["payload"]["pvp"][x] for x in ("wins", "losses", "draws")] == [1,1,1], "HTTP_RESULTS")
    check(a["source_coverage_status"] == "VERIFIED", "HTTP_SYNTH_COVERAGE")
    check(a["readiness"] == "STAGING_ONLY", "HTTP_NO_PROD_STATUS")
    check(a["payload"]["raid"]["status"] == "UNKNOWN", "HTTP_RAID_UNVERIFIED")
    check("private" in headers.get("Cache-Control", ""), "HTTP_PRIVATE_CACHE")
    check("noindex" in headers.get("X-Robots-Tag", ""), "HTTP_PRIVATE_ROBOTS")
    for hidden in ("76561190000000000","76561190000000001","SYNTH_A","SYNTH_OPP",
                   "steam_id", "steamID", "email", "friend_code"):
        check(hidden not in data, "HTTP_NO_PRIVATE_" + hidden[:7])
    results.append("STEAM_SESSION_OWNER_RESULT")

    for selector in ("player", "steam_id", "user_id", "region", "view_as"):
        status, _headers, data = request(
            opener, base + "/api/checkpoint2026/me.php?" + selector + "=SYNTH_B"
        )
        check(status == 403 and decode(data)["error"] == "account_selector_forbidden",
              "HTTP_IDOR_" + selector)
    results.append("IDOR_SELECTOR_REJECTED")

    status, _, data = request(opener, base + "/api/checkpoint2026/public.php?player=SYNTH_A")
    check(status == 403, "HTTP_PUBLIC_IDOR")
    results.append("PUBLIC_IDOR_BLOCKED")

    status, _, data = request(opener, base + "/api/checkpoint2026/_fixture_login.php", "POST", {"who":"B"})
    check(status == 200, "HTTP_LOGIN_B")
    status, _, data = request(opener, base + "/api/checkpoint2026/me.php")
    check(status == 200, "HTTP_PRIVATE_B")
    b = decode(data)
    check(b["payload"]["pvp"]["verified_matches"] == 1, "HTTP_TW_JP_CROSS_ACCOUNT")
    check(b["payload"]["pvp"]["wins"] == 1, "HTTP_JP_RESULT")
    check(a["payload_sha256"] != b["payload_sha256"], "HTTP_CACHE_PRIVATE_SEPARATION")
    results.append("CROSS_SERVER_SAME_NAME_SEPARATED")

    status, _, _ = request(opener, base + "/api/checkpoint2026/_fixture_login.php", "POST", {"who":"logout"})
    check(status == 200, "HTTP_LOGOUT")
    status, _, data = request(opener, base + "/api/checkpoint2026/me.php")
    check(status == 401, "HTTP_LOGOUT_DENIED")
    results.append("LOGOUT_REVOKES_FIXTURE_SESSION")

    status, headers, html = request(opener, base + "/pages/checkpoint2026/index.php")
    check(status == 200 and 'name="viewport"' in html, "HTML_VIEWPORT")
    check(html.count('class="slide"') == 4, "HTML_FOUR_STORIES")
    check('id="skip"' in html and 'aria-label=' in html, "HTML_ACCESSIBILITY")
    check("no-store" in headers.get("Cache-Control", ""), "HTML_CACHE")
    status, _, css = request(opener, base + "/pages/checkpoint2026/story.css")
    check(status == 200 and "max-width:400px" in css, "CSS_MOBILE_400")
    check("prefers-reduced-motion:reduce" in css, "CSS_REDUCED_MOTION")
    status, _, js = request(opener, base + "/pages/checkpoint2026/story.js")
    check(status == 200 and "innerHTML" not in js and "textContent" in js, "JS_NO_HTML_INJECTION")
    results.append("FOUR_STORY_ACCESSIBLE_STATIC")


def browser_cases(base, results, evidence_dir):
    from playwright.sync_api import sync_playwright, expect

    edge = Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe")
    chrome = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
    launch_kwargs = {"headless": True, "timeout": 10000}
    if edge.is_file():
        launch_kwargs["executable_path"] = str(edge)
    elif chrome.is_file():
        launch_kwargs["executable_path"] = str(chrome)
    elif sys.platform.startswith("linux"):
        # GitHub's Ubuntu image already ships native Chromium/Chrome.
        # Browser-install downloads are not needed for synthetic E2E.
        for name in ("chromium", "chromium-browser", "google-chrome", "google-chrome-stable"):
            system_browser = shutil.which(name)
            if system_browser and Path(system_browser).is_file():
                launch_kwargs["executable_path"] = system_browser
                break
        if "executable_path" not in launch_kwargs:
            raise RuntimeError("BLOCKED_LINUX_SYSTEM_CHROMIUM_MISSING")

    with sync_playwright() as pw:
        browser = pw.chromium.launch(**launch_kwargs)
        try:
            context = browser.new_context(
                viewport={"width": 360, "height": 740},
                reduced_motion="reduce",
            )
            page = context.new_page()
            errors = []
            blocked_external_urls = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.on("request",lambda req: blocked_external_urls.append(req.url)
                    if not req.url.startswith(base) else None)
            response=context.request.post(
                base + "/api/checkpoint2026/_fixture_login.php",
                data=json.dumps({"who":"A"}),
                headers={"Content-Type":"application/json"},
            )
            check(response.status == 200, "BROWSER_FIXTURE_LOGIN")
            page.goto(base + "/pages/checkpoint2026/index.php", wait_until="domcontentloaded", timeout=12000)
            expect(page.locator("#matches")).to_have_text("3", timeout=7000)
            expect(page.locator("#cup-games")).to_have_text("2", timeout=7000)
            page.screenshot(path=str(evidence_dir / "M2_Story_360_20261124.png"), full_page=True)
            check(page.locator(".slide").count()==4, "BROWSER_SLIDE_COUNT")
            check(page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"),
                  "BROWSER_360_NO_HORIZONTAL_OVERFLOW")
            check(page.locator("#slide-0").is_visible(), "BROWSER_FIRST_VISIBLE")
            check(not page.locator("#slide-1").is_visible(), "BROWSER_SECOND_HIDDEN")
            page.locator("#next").click()
            check(page.locator("#slide-1").is_visible(), "BROWSER_NEXT_BUTTON")
            page.keyboard.press("ArrowRight")
            check(page.locator("#slide-2").is_visible(), "BROWSER_ARROW_KEY")
            page.locator("#skip").click()
            check(all(page.locator("#slide-"+str(i)).is_visible() for i in range(4)),
                  "BROWSER_SKIP_SUMMARY")
            page.screenshot(path=str(evidence_dir / "M2_Story_360_Summary_20261124.png"), full_page=True)
            check(page.evaluate("getComputedStyle(document.getElementById('slide-0')).animationName === 'none'"),
                  "BROWSER_REDUCED_MOTION")
            check(not errors, "BROWSER_CONSOLE_ERRORS")
            check(not blocked_external_urls, "BROWSER_EXTERNAL_REQUEST")
            results.append("CHROMIUM_360_STORY_NAV_KEYBOARD_SKIP_REDUCED_MOTION")
            context.close()
            wide=browser.new_context(viewport={"width":1280,"height":850})
            page=wide.new_page()
            page.goto(base + "/pages/checkpoint2026/index.php",wait_until="domcontentloaded",timeout=12000)
            check(page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"),
                  "BROWSER_DESKTOP_NO_OVERFLOW")
            check(page.locator(".slide").count()==4,"BROWSER_DESKTOP_FOUR_STORIES")
            page.screenshot(path=str(evidence_dir / "M2_Story_1280_20261124.png"), full_page=True)
            results.append("CHROMIUM_DESKTOP_RESPONSIVE")
            wide.close()
        finally:
            browser.close()


def main(report_path, php):
    report = {
        "stage": "local_windows_loopback_synthetic",
        "no_remote_services": True,
        "browser": "real Chromium if installed",
        "status": "BLOCKED",
        "cases": [],
        "errors": [],
        "server_stopped": False,
    }
    live = None
    disabled = None
    try:
        disabled, disabled_url = launch_server(php, False)
        for name in ("/api/checkpoint2026/public.php",
                     "/api/checkpoint2026/me.php",
                     "/pages/checkpoint2026/index.php"):
            code, _, payload = request(urllib.request.build_opener(), disabled_url+name)
            check(code==503, "DISABLED_STAGING_GATE_"+name.replace("/","_"))
        report["cases"].append("STAGING_DISABLED_FAIL_CLOSED")
        disabled.terminate()
        disabled.wait(timeout=4)
        disabled=None

        live, url = launch_server(php, True)
        http_cases(url, report["cases"])
        browser_cases(url, report["cases"], report_path.parent)
        report["screenshots"] = [
            str(report_path.parent / name) for name in (
                "M2_Story_360_20261124.png",
                "M2_Story_360_Summary_20261124.png",
                "M2_Story_1280_20261124.png",
            )
        ]
        check(all(Path(path).is_file() for path in report["screenshots"]),
              "BROWSER_SCREENSHOT_MISSING")
        report["status"] = "PASS"
        print("M2_LOOPBACK_HTTP_BROWSER_PASS cases="+str(len(report["cases"])),flush=True)
    except Exception as e:
        report["errors"].append(type(e).__name__+": "+str(e)[:450])
        print("M2_LOOPBACK_E2E_BLOCKED "+repr(e),flush=True)
        raise
    finally:
        for process in (live, disabled):
            if process is not None:
                process.terminate()
                try:
                    process.wait(timeout=4)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=4)
        report["server_stopped"] = all(
            x is None or x.poll() is not None for x in (live,disabled)
        )
        if not report["server_stopped"]:
            report["status"] = "BLOCKED"
        report_path.parent.mkdir(parents=True,exist_ok=True)
        report_path.write_text(
            json.dumps(report,indent=2,ensure_ascii=False)+"\n", encoding="utf-8"
        )
        print("M2_E2E_EVIDENCE "+str(report_path),flush=True)


if __name__ == "__main__":
    ap=argparse.ArgumentParser()
    ap.add_argument("--report",required=True,type=Path)
    ap.add_argument("--php",required=True,type=Path)
    args=ap.parse_args()
    check(args.report.is_absolute(),"ABSOLUTE_REPORT_REQUIRED")
    check(args.php.is_file(),"PHP_CLI_REQUIRED")
    main(args.report,args.php)
