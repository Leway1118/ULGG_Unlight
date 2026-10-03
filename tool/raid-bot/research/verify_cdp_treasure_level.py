from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path
from typing import Any


TOOL_ROOT = Path(__file__).resolve().parents[2]
if str(TOOL_ROOT) not in sys.path:
    sys.path.insert(0, str(TOOL_ROOT))

from core.runtime.cdp.ul_sniffer import ULSniffer


DEFAULT_CDP_PORT = int(os.getenv("CDP_PORT", "59222"))
DEFAULT_OUTPUT_PATH = (
    Path(__file__).resolve().parents[1]
    / "data"
    / "cdp_treasure_level_verification.json"
)


READ_TREASURE_LEVEL_JS = r"""
(() => {
  const raid = globalThis.game?.scene?.keys?.Raid;

  if (!raid) {
    return JSON.stringify({
      ok: false,
      error: "Raid scene missing"
    });
  }

  const rows = Array.isArray(raid.raid_data)
    ? raid.raid_data
    : [];
  const playerName = raid.player?.name ?? null;

  return JSON.stringify({
    ok: true,
    captured_at: new Date().toISOString(),
    raids: rows.map((row) => {
      const points = Array.isArray(row?.points)
        ? row.points
        : [];

      return {
        profound_id: row?.profound_id ?? null,
        pass: row?.pass ?? null,
        founder: row?.profound_founder ?? null,
        monster_code: row?.profound_mons ?? null,
        treasure_level: row?.treasure_level ?? null,
        already_joined: Boolean(
          playerName
          && points.some((point) => point?.name === playerName)
        )
      };
    })
  });
})()
"""


def build_verification_report(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise ValueError("CDP payload must be an object")
    if not payload.get("ok"):
        raise ValueError(str(payload.get("error") or "CDP read failed"))

    raw_raids = payload.get("raids")
    if not isinstance(raw_raids, list):
        raise ValueError("CDP payload raids must be a list")

    raids: list[dict[str, Any]] = []
    for item in raw_raids:
        if not isinstance(item, dict):
            continue
        raids.append(
            {
                "profound_id": item.get("profound_id"),
                "pass": item.get("pass"),
                "founder": item.get("founder"),
                "monster_code": item.get("monster_code"),
                "treasure_level": item.get("treasure_level"),
                "already_joined": bool(item.get("already_joined")),
            }
        )

    stats = {
        "total_raids": len(raids),
        "raids_with_tl": 0,
        "joined_with_tl": 0,
        "joined_without_tl": 0,
        "unjoined_with_tl": 0,
        "unjoined_without_tl": 0,
    }

    for raid in raids:
        has_tl = raid["treasure_level"] is not None
        joined = raid["already_joined"]

        if has_tl:
            stats["raids_with_tl"] += 1
        if joined and has_tl:
            stats["joined_with_tl"] += 1
        elif joined:
            stats["joined_without_tl"] += 1
        elif has_tl:
            stats["unjoined_with_tl"] += 1
        else:
            stats["unjoined_without_tl"] += 1

    return {
        "source": "cdp_runtime_raid_data",
        "read_only": True,
        "captured_at": payload.get("captured_at"),
        "stats": stats,
        "unjoined_with_tl_cases": [
            raid
            for raid in raids
            if not raid["already_joined"]
            and raid["treasure_level"] is not None
        ],
        "raids": raids,
        "warning": "Local research output; do not commit or share raw raid data.",
    }


def write_report(path: Path, report: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_name(f"{path.name}.tmp")
    temporary_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    temporary_path.replace(path)


def _decode_evaluation(value: Any) -> dict[str, Any]:
    if isinstance(value, str):
        decoded = json.loads(value)
    else:
        decoded = value
    if not isinstance(decoded, dict):
        raise ValueError("Runtime.evaluate returned a non-object payload")
    return decoded


def run_probe(port: int, output_path: Path) -> None:
    async def after_connect(sniffer: ULSniffer) -> None:
        try:
            value, error = await sniffer.evaluate(
                READ_TREASURE_LEVEL_JS,
                await_promise=False,
            )
            if error:
                raise RuntimeError(error)

            report = build_verification_report(
                _decode_evaluation(value)
            )
            write_report(output_path, report)

            print(json.dumps(report, ensure_ascii=False, indent=2))
            print(f"\nSaved: {output_path.resolve()}")
        finally:
            if sniffer.ws is not None:
                await sniffer.ws.close()

    ULSniffer(port=port, verbose=False).start(
        after_connect=after_connect
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Read Raid.raid_data through local CDP and compare "
            "treasure_level with participation state."
        )
    )
    parser.add_argument("--port", type=int, default=DEFAULT_CDP_PORT)
    parser.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_OUTPUT_PATH,
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    run_probe(args.port, args.output)


if __name__ == "__main__":
    main()
