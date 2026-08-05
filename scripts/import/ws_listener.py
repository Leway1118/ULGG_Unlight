from playwright.sync_api import sync_playwright

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False)
    page = browser.new_page()

    def on_response(resp):
        if "application/json" in (resp.headers.get("content-type") or ""):
            if "/api/" in resp.url:
                print(resp.url)

    page.on("response", on_response)
    page.goto("https://www.playunlight.online/")
    page.wait_for_timeout(20000)
