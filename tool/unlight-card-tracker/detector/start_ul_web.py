from __future__ import annotations

import json
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from tkinter import Tk, messagebox


CDP_HOST = "127.0.0.1"
CDP_PORT = 59223

PROFILE_DIR = Path(r"C:\ULWebAutomationChrome")
PROFILE_NAME = "Profile 4"
EXTENSION_DIR = Path(
    r"D:\Leway\OneDrive - 合航企業有限公司\Leway\公司檔案"
    r"\7.Code\unlight\tool"
    r"\ulr-boot-extension"
)

GAME_URL = (
    "https://www.playunlight.online/"
    "?steamid=76561199811024894"
)

CHROME_CANDIDATES = [
    Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
    Path(r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"),
]


def show_message(title: str, text: str, *, error: bool = False) -> None:
    root = Tk()
    root.withdraw()
    root.attributes("-topmost", True)

    if error:
        messagebox.showerror(title, text, parent=root)
    else:
        messagebox.showinfo(title, text, parent=root)

    root.destroy()


def find_chrome() -> Path:
    for path in CHROME_CANDIDATES:
        if path.is_file():
            return path

    raise FileNotFoundError(
        "找不到 Google Chrome。\n\n"
        "已檢查：\n"
        + "\n".join(str(path) for path in CHROME_CANDIDATES)
    )


def get_cdp_version(timeout: float = 1.0) -> dict | None:
    url = f"http://{CDP_HOST}:{CDP_PORT}/json/version"

    try:
        with urllib.request.urlopen(url, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8"))
    except (
        urllib.error.URLError,
        TimeoutError,
        json.JSONDecodeError,
        OSError,
    ):
        return None

if not EXTENSION_DIR.is_dir():
    raise FileNotFoundError(
        f"找不到 ULR Boot 擴充功能資料夾：\n{EXTENSION_DIR}"
    )

manifest_path = EXTENSION_DIR / "manifest.json"

if not manifest_path.is_file():
    raise FileNotFoundError(
        f"找不到 manifest.json：\n{manifest_path}"
    )
    
def launch_chrome(
    chrome: Path,
    url: str = "about:blank",
) -> None:
    PROFILE_DIR.mkdir(parents=True, exist_ok=True)

    if not EXTENSION_DIR.is_dir():
        raise FileNotFoundError(
            f"找不到 ULR Boot 擴充功能資料夾：\n{EXTENSION_DIR}"
        )

    manifest_path = EXTENSION_DIR / "manifest.json"

    if not manifest_path.is_file():
        raise FileNotFoundError(
            f"找不到 manifest.json：\n{manifest_path}"
        )

    arguments = [
        str(chrome),
        f"--remote-debugging-address={CDP_HOST}",
        f"--remote-debugging-port={CDP_PORT}",
        f"--user-data-dir={PROFILE_DIR}",
        f"--profile-directory={PROFILE_NAME}",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-background-mode",
        "--new-window",
        url,
    ]

    creation_flags = 0

    if sys.platform == "win32":
        creation_flags = subprocess.DETACHED_PROCESS

    subprocess.Popen(
        arguments,
        creationflags=creation_flags,
        close_fds=True,
    )


def wait_for_cdp(seconds: float = 15.0) -> dict | None:
    deadline = time.monotonic() + seconds

    while time.monotonic() < deadline:
        result = get_cdp_version()

        if result and result.get("webSocketDebuggerUrl"):
            return result

        time.sleep(0.5)

    return None


def main() -> int:
    try:
        chrome = find_chrome()

        existing = get_cdp_version()

        if existing and existing.get("webSocketDebuggerUrl"):
            time.sleep(0.5)
            launch_chrome(chrome, GAME_URL)

            show_message(
                "UNLIGHT Web",
                "Chrome 59223 已經啟動。\n"
                "已送出開啟 UNLIGHT 網頁的指令。",
            )
            return 0

        # 先只啟動專用 Chrome，避免遊戲網址早於擴充功能載入。
        launch_chrome(chrome, "about:blank")

        version = wait_for_cdp()

        if not version:
            show_message(
                "UNLIGHT Web 啟動失敗",
                "Chrome 已送出啟動指令，但 15 秒內未偵測到 CDP 59223。\n\n"
                "請檢查：\n"
                "1. C:\\ULWebAutomationChrome 是否可用\n"
                "2. Chrome 是否被防毒軟體阻擋\n"
                "3. 59223 是否被其他程式占用",
                error=True,
            )
            return 1
        # 等待 Profile 與 ULR Boot 擴充功能完全初始化。
        time.sleep(2.0)

        # 透過已啟動的同一個專用 Chrome 開啟遊戲。
        launch_chrome(chrome, GAME_URL)
        browser = version.get("Browser", "Chrome")

        show_message(
            "UNLIGHT Web 已啟動",
            f"{browser}\n\n"
            f"CDP：http://{CDP_HOST}:{CDP_PORT}\n"
            "UNLIGHT 網頁已開啟。",
        )
        return 0

    except Exception as exc:
        show_message(
            "UNLIGHT Web 啟動失敗",
            str(exc),
            error=True,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())