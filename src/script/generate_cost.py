import json
from pathlib import Path
import csv
from collections import defaultdict
from typing import Any


def read_json(data_dir: Path, relative_path: str):
    data_path = data_dir / relative_path
    return json.loads(data_path.read_text())


slots: dict[int, str | None] = {
    0: "紅",
    1: "綠",
    2: "藍",
    3: "紫",
    4: "黃",
    5: "白",
    6: "黑",
    7: None,
}


def gen_cc(cc: dict[str, Any], cc_profile: dict[str, Any], res: Path):
    with open(res, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(["name", "level", "cost", "hp", "atk", "def", "slot"])
        for chara in cc["frames"]:
            id_ = chara.get("filename")
            if not id_:
                continue
            chara_id = chara["chara"]
            hp = chara["hp"]
            atk = chara["atk"]
            def_ = chara["def"]
            cost = chara["cost"]
            kind = "R" if "r" in id_ else "L"
            level = chara["level"]
            slot = "".join(filter(None, (slots[s] for s in chara["slot"])))
            profile = cc_profile.get(chara_id)
            if not profile:
                name_ja = name_tcn = chara_id
            else:
                name_ja = profile["name_ja"]
                name_tcn = profile["name_tcn"]
            writer.writerow(
                [
                    f"{name_ja} / {name_tcn}",
                    f"{kind}{level}",
                    cost,
                    hp,
                    atk,
                    def_,
                    slot,
                ]
            )


def gen_pure_cc(cc: dict[str, Any], cc_profile: dict[str, Any], res: Path):
    data: defaultdict[str, dict[str, str | int]] = defaultdict(dict)
    for chara in cc["frames"]:
        id_ = chara.get("filename")
        if not id_:
            continue
        chara_id = chara["chara"]
        cost = chara["cost"]
        kind = "R" if "r" in id_ else "L"
        level = chara["level"]
        profile = cc_profile.get(chara_id)
        if not profile:
            name_ja = name_tcn = chara_id
        else:
            name_ja = profile["name_ja"]
            name_tcn = profile["name_tcn"]
        data[chara_id]["name_ja"] = name_ja
        data[chara_id]["name_tcn"] = name_tcn
        data[chara_id][f"{kind}{level}"] = cost
    with open(res, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(
            [
                "chara_id",
                "name_ja",
                "name_tcn",
                "L1",
                "L2",
                "L3",
                "L4",
                "L5",
                "R1",
                "R2",
                "R3",
                "R4",
                "R5",
            ]
        )
        for id_, chara in data.items():
            writer.writerow(
                [
                    id_,
                    chara["name_ja"],
                    chara["name_tcn"],
                    chara.get("L1"),
                    chara.get("L2"),
                    chara.get("L3"),
                    chara.get("L4"),
                    chara.get("L5"),
                    chara.get("R1"),
                    chara.get("R2"),
                    chara.get("R3"),
                    chara.get("R4"),
                    chara.get("R5"),
                ]
            )


def gen_weapon(cc_profile: dict[str, Any], item: dict[str, Any], res: Path):
    with open(res, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(
            [
                "idx",
                "name_tcn",
                "name_ja",
                "cost",
                "melee_attack",
                "ranged_attack",
                "melee_defense",
                "ranged_defense",
                "info_tcn",
                "info_ja",
                "chara_tcn",
                "chara_ja",
            ]
        )
        for idx, weapon in enumerate(item["weapon"]):
            # id_ = weapon["frame"]
            # if not id_:
            #     continue
            name_ja = weapon["name_ja"]
            name_tcn = weapon["name_tcn"]
            cost = weapon["cost"]
            melee_attack = ranged_attack = melee_defense = ranged_defense = 0
            for attack in weapon["atk"]:
                if attack["distance"] in (0, 2):
                    melee_attack += attack["value"]
                if attack["distance"] in (1, 2):
                    ranged_attack += attack["value"]
            for defense in weapon["def"]:
                if defense["distance"] in (0, 2):
                    melee_defense += defense["value"]
                if defense["distance"] in (1, 2):
                    ranged_defense += defense["value"]
            info_ja = "".join(c for c in weapon["info_ja"] if c != "\n")
            info_tcn = "".join(c for c in weapon["info_tcn"] if c != "\n")
            cost = weapon["cost"]
            chara_id = weapon["chara"]
            profile = cc_profile.get(chara_id, {})
            chara_ja = profile.get("name_ja")
            chara_tcn = profile.get("name_tcn")
            writer.writerow(
                [
                    idx,
                    name_tcn,
                    name_ja,
                    cost,
                    melee_attack,
                    ranged_attack,
                    melee_defense,
                    ranged_defense,
                    info_tcn,
                    info_ja,
                    chara_tcn,
                    chara_ja,
                ]
            )


def gen_event(event: dict[str, Any], res: Path):
    with open(res, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(["idx", "name", "info_ja", "info_tcn", "cost", "color"])
        for idx, e in enumerate(event["frames"]):
            if not e:
                continue
            name_ja = e["name_ja"]
            name_tcn = e["name_tcn"]
            name = f"{name_ja} / {name_tcn}"
            info_ja = "".join(c for c in e["info_ja"] if c != "\n")
            info_tcn = "".join(c for c in e["info_tcn"] if c != "\n")
            cost = e["cost"]
            color = slots[e["type"]]
            writer.writerow(
                [
                    idx,
                    name,
                    info_ja,
                    info_tcn,
                    cost,
                    color,
                ]
            )


def main(data_dir: Path, res_dir: Path):
    cc = read_json(data_dir, "cc_asset.json")
    cc_profile = read_json(data_dir, "charaProfile.json")
    event = read_json(data_dir, "event_asset.json")
    item = read_json(data_dir, "avatar_item.json")
    gen_cc(cc, cc_profile, res_dir / "cost_cc.csv")
    gen_pure_cc(cc, cc_profile, res_dir / "cost_cc_pure.csv")
    gen_weapon(cc_profile, item, res_dir / "cost_weapon.csv")
    gen_event(event, res_dir / "cost_event.csv")
