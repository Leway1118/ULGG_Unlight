# -*- coding: utf-8 -*-
"""One-shot live validation for the Electron CDP -> Phaser action path."""

from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path
from typing import TYPE_CHECKING


if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[5]))

if TYPE_CHECKING:
    from core.runtime.cdp.ul_sniffer import ULSniffer


CDP_PORT = 59222
ACTION_SETTLE_SECONDS = 0.25


PREFLIGHT_JS = r"""
(() => {
    const scenes = globalThis.game?.scene?.keys;
    const move = scenes?.MovePhaseA;
    const main = scenes?.MainA;
    const front = move?.front;
    const okButton = main?.ok || main?.friend_ok_btn || main?.btn_ok;

    return JSON.stringify({
        moveSceneExists: Boolean(move),
        moveSceneActive: Boolean(move?.scene?.isActive?.()),
        mainSceneExists: Boolean(main),
        frontExists: Boolean(front),
        frontInteractive: Boolean(front?.input?.enabled),
        frontCanEmit: typeof front?.emit === "function",
        okExists: Boolean(okButton),
        okInteractive: Boolean(okButton?.input?.enabled),
        okCanEmit: typeof okButton?.emit === "function"
    });
})()
"""


def build_click_js(target_expression: str, action: str) -> str:
    return f"""
(async () => {{
    const target = {target_expression};

    if (!target)
        return JSON.stringify({{ok:false, error:"object missing"}});

    if (target.input?.enabled !== true)
        return JSON.stringify({{ok:false, error:"object not interactive"}});

    if (typeof target.emit !== "function")
        return JSON.stringify({{ok:false, error:"emit unavailable"}});

    try {{
        const downHandled = target.emit("pointerdown");
        await new Promise(resolve => setTimeout(resolve, 100));
        const upHandled = target.emit("pointerup");

        if (downHandled !== true || upHandled !== true) {{
            return JSON.stringify({{
                ok:false,
                error:"pointer event was not handled",
                downHandled:Boolean(downHandled),
                upHandled:Boolean(upHandled)
            }});
        }}

        return JSON.stringify({{ok:true, action:{json.dumps(action)}}});
    }} catch (error) {{
        return JSON.stringify({{ok:false, error:String(error)}});
    }}
}})()
"""


FRONT_CLICK_JS = build_click_js(
    "globalThis.game?.scene?.keys?.MovePhaseA?.front",
    "front",
)

OK_CLICK_JS = build_click_js(
    "(() => { const main = globalThis.game?.scene?.keys?.MainA; "
    "return main?.ok || main?.friend_ok_btn || main?.btn_ok; })()",
    "ok",
)


class RuntimeValidationError(RuntimeError):
    pass


class RuntimeValidator:
    def __init__(self) -> None:
        self.connected = False
        self.passed = False
        self.status = {
            "CDP": "FAIL (connection unavailable)",
            "MovePhaseA": "FAIL (not verified)",
            "MainA": "FAIL (not verified)",
            "Front Action": "FAIL (not run)",
            "OK Action": "FAIL (not run)",
        }

    async def evaluate_json(
        self,
        sniffer: ULSniffer,
        script: str,
        *,
        await_promise: bool = False,
    ) -> dict:
        value, error = await sniffer.evaluate(
            script,
            await_promise=await_promise,
        )

        if error:
            raise RuntimeValidationError(error)
        if not value:
            raise RuntimeValidationError("empty CDP result")

        try:
            result = json.loads(value)
        except (TypeError, ValueError, json.JSONDecodeError) as exc:
            raise RuntimeValidationError(
                f"invalid CDP JSON result: {exc}"
            ) from exc

        if not isinstance(result, dict):
            raise RuntimeValidationError("CDP result is not an object")

        return result

    async def run(self, sniffer: ULSniffer) -> None:
        self.connected = True
        self.status["CDP"] = "PASS"

        try:
            preflight = await self.evaluate_json(sniffer, PREFLIGHT_JS)

            if not preflight.get("moveSceneExists"):
                raise RuntimeValidationError("MovePhaseA missing")
            if not preflight.get("moveSceneActive"):
                raise RuntimeValidationError("MovePhaseA is not active")
            self.status["MovePhaseA"] = "PASS"

            if not preflight.get("mainSceneExists"):
                raise RuntimeValidationError("MainA missing")
            self.status["MainA"] = "PASS"

            if not all(
                preflight.get(key)
                for key in (
                    "frontExists",
                    "frontInteractive",
                    "frontCanEmit",
                )
            ):
                raise RuntimeValidationError("front is not interactive")

            if not all(
                preflight.get(key)
                for key in (
                    "okExists",
                    "okInteractive",
                    "okCanEmit",
                )
            ):
                raise RuntimeValidationError("OK button is not interactive")

            front_result = await self.evaluate_json(
                sniffer,
                FRONT_CLICK_JS,
                await_promise=True,
            )
            if front_result.get("ok") is not True:
                raise RuntimeValidationError(
                    f"front click failed: {front_result}"
                )
            self.status["Front Action"] = "PASS"

            await asyncio.sleep(ACTION_SETTLE_SECONDS)

            ok_result = await self.evaluate_json(
                sniffer,
                OK_CLICK_JS,
                await_promise=True,
            )
            if ok_result.get("ok") is not True:
                raise RuntimeValidationError(
                    f"OK click failed: {ok_result}"
                )
            self.status["OK Action"] = "PASS"
            self.passed = True

        except Exception as exc:
            print(f"Validation Error: {exc}")
        finally:
            if sniffer.ws is not None:
                await sniffer.ws.close()

    def print_status(self) -> None:
        for label, result in self.status.items():
            print(f"{label}: {result}")


def main() -> int:
    validator = RuntimeValidator()

    try:
        from core.runtime.cdp.ul_sniffer import ULSniffer
    except ModuleNotFoundError as exc:
        validator.status["CDP"] = f"FAIL (dependency unavailable: {exc.name})"
        validator.print_status()
        return 1

    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )

    sniffer.start(after_connect=validator.run)
    validator.print_status()
    return 0 if validator.passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
