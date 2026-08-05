from __future__ import annotations

import json
from pathlib import Path
from urllib.parse import urlparse

import requests


def validate_loopback_origin(value: str) -> str:
    parsed = urlparse(value)
    if (
        parsed.scheme != "http"
        or parsed.hostname not in {"127.0.0.1", "::1", "localhost"}
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path not in {"", "/"}
        or parsed.query
        or parsed.fragment
    ):
        raise ValueError("Tracker API origin must be loopback HTTP only")
    return value.rstrip("/")


def read_token(path: Path) -> str:
    token = path.read_text(encoding="utf-8").strip()
    if not token:
        raise ValueError(f"producer credential is empty: {path}")
    return token


def send_json(
    *,
    method: str,
    url: str,
    token: str,
    payload: dict,
) -> dict:
    response = requests.request(
        method,
        url,
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
        },
        json=payload,
        timeout=3.0,
    )
    try:
        body = response.json()
    except ValueError as error:
        raise RuntimeError(
            f"Tracker API returned non-JSON HTTP {response.status_code}"
        ) from error
    if not response.ok:
        error = body.get("error", {}) if isinstance(body, dict) else {}
        raise RuntimeError(
            f"{error.get('code', 'HTTP_ERROR')}: "
            f"{error.get('message', response.reason)}"
        )
    return body


def print_result(body: dict) -> None:
    print(json.dumps(body, ensure_ascii=False, indent=2))
