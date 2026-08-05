from pathlib import Path
from dataclasses import dataclass
import json
from collections import Counter, defaultdict


LANG = "tcn"
DIR = Path("website/www.he4bliurehiufdfbl-6ae5rrg.site/images/assets/data")

stage_options = {
    "ja": [
        {"name": "レーデンベルグ城", "value": "000"},
        {"name": "誘惑の森", "value": "001"},
        {"name": "掃き溜めの街道", "value": "002"},
        {"name": "氷結の湖畔", "value": "003"},
        {"name": "人魂墓地", "value": "004"},
        {"name": "最果ての村", "value": "005"},
        {"name": "嵐の荒野", "value": "006"},
        {"name": "フォーンハイル遺跡", "value": "007"},
        {"name": "魔都ローゼンブルグ", "value": "008"},
        {"name": "狂気山脈", "value": "009"},
    ],
    "tcn": [
        {"name": "雷德貝魯格城", "value": "000"},
        {"name": "誘惑森林", "value": "001"},
        {"name": "垃圾之街", "value": "002"},
        {"name": "冰封湖畔", "value": "003"},
        {"name": "人魂墓地", "value": "004"},
        {"name": "盡頭之村", "value": "005"},
        {"name": "風暴荒野", "value": "006"},
        {"name": "峰亥盧遺跡", "value": "007"},
        {"name": "魔都羅占布爾克", "value": "008"},
        {"name": "瘋狂山脈", "value": "009"},
    ],
}
name_map = {
    "swd": "劍",
    "gun": "槍",
    "mov": "移",
    "shi": "防",
    "spe": "特",
}


@dataclass(frozen=True)
class Card:
    mov: int = 0
    swd: int = 0
    gun: int = 0
    shi: int = 0
    spe: int = 0

    @classmethod
    def from_dict(cls, data: dict):
        data = {k[:3]: v for k, v in data.items() if v}
        return cls(**data)

    def __str__(self) -> str:
        items = list(self.__dict__.items())
        res = "".join(f"{name_map[k]}{v}" for k, v in items if v)
        if self.kind == 1:
            return res * 2
        return res

    @property
    def kind(self):
        return len([v for v in self.__dict__.values() if v])

    def __add__(self, other):
        factor = 3 - other.kind
        return Card(
            swd=self.swd + other.swd * factor,
            gun=self.gun + other.gun * factor,
            shi=self.shi + other.shi * factor,
            spe=self.spe + other.spe * factor,
            mov=self.mov + other.mov * factor,
        )

    @property
    def priority(self):
        priorities = [self.mov, self.swd, self.gun, self.shi, self.spe]
        if self.mov > 0:
            p_row = 0
        elif self.shi > 0:
            p_row = 1
        elif self.swd > 0 and self.gun > 0:
            p_row = 2
        elif self.swd > 0:
            p_row = 3
        elif self.gun > 0:
            p_row = 4
        else:
            p_row = 5

        return p_row, *[p or float("inf") for p in priorities]


def print_ac(file):
    for stage in stage_options[LANG]:
        cards = []
        stage_name = stage["name"]
        stage_value = stage["value"]
        ac = json.loads((DIR / f"ac_asset_{stage_value}.json").read_text())
        total = defaultdict(int)
        card_nums = len(ac["frames"]) / 5
        cards = [Card.from_dict(card_dict) for card_dict in ac["frames"]]
        total = sum(cards, Card())
        counter = Counter(cards)
        ordered_counter = list(counter.items())
        ordered_counter.sort(key=lambda c: c[0].priority)

        stats = ", ".join(
            f"{name}{getattr(total, id) / card_nums: 2.2}"
            for id, name in name_map.items()
        )
        print(f"{stage_name}:  (平均: { stats})", file=file)
        for card, count in ordered_counter:
            print(f"  {str(card)} × {count}", file=file)


if __name__ == "__main__":
    out_dir = Path("data")
    out_dir.mkdir(parents=True, exist_ok=True)

    with (out_dir / "ac.txt").open("w", encoding="utf-8") as f:
        print_ac(f)