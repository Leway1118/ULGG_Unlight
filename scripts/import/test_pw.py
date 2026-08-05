from playwright.sync_api import sync_playwright

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page()

    # 改用你確定能連的站
    page.goto("https://www.playunlight.online", timeout=60000)

    print("TITLE:", page.title())
    browser.close()
