#!/usr/bin/env python3

# RAID_VICTIM_XLSX_GENERATOR_V1

import io
import json
import re
import sys
import zipfile

from collections import Counter
from datetime import datetime, timezone
from xml.sax.saxutils import escape


NS_MAIN = (
    "http://schemas.openxmlformats.org/"
    "spreadsheetml/2006/main"
)

NS_REL = (
    "http://schemas.openxmlformats.org/"
    "officeDocument/2006/relationships"
)


def clean(value):
    if value is None:
        return ""

    return re.sub(
        r"[\x00-\x08\x0b\x0c\x0e-\x1f]",
        "",
        str(value),
    )


def col_name(index):
    out = ""

    while index:
        index, rem = divmod(
            index - 1,
            26,
        )

        out = chr(65 + rem) + out

    return out


def cell_xml(
    row,
    col,
    value,
    style=0,
):
    ref = f"{col_name(col)}{row}"

    if (
        isinstance(value, (int, float))
        and not isinstance(value, bool)
    ):
        return (
            f'<c r="{ref}" s="{style}">'
            f"<v>{value}</v>"
            "</c>"
        )

    value = escape(clean(value))

    return (
        f'<c r="{ref}" s="{style}" '
        't="inlineStr">'
        "<is>"
        '<t xml:space="preserve">'
        f"{value}"
        "</t>"
        "</is>"
        "</c>"
    )


# RAID_VICTIM_XLSX_OOXML_ORDER_FIX_V1
def make_sheet(
    rows,
    widths,
    freeze_rows=0,
    auto_filter=None,
    merge_ranges=None,
):
    output = []
    max_cols = len(widths)

    for row_no, row in enumerate(
        rows,
        1,
    ):
        cells = []

        for col_no, item in enumerate(
            row,
            1,
        ):
            if isinstance(item, dict):
                value = item.get(
                    "value",
                    "",
                )

                style = int(
                    item.get(
                        "style",
                        0,
                    )
                )

            else:
                value = item
                style = 0

            cells.append(
                cell_xml(
                    row_no,
                    col_no,
                    value,
                    style,
                )
            )

            max_cols = max(
                max_cols,
                col_no,
            )

        output.append(
            f'<row r="{row_no}">'
            + "".join(cells)
            + "</row>"
        )


    cols = "".join(
        (
            f'<col min="{index}" '
            f'max="{index}" '
            f'width="{width}" '
            'customWidth="1"/>'
        )
        for index, width
        in enumerate(widths, 1)
    )


    views = (
        '<sheetViews>'
        '<sheetView workbookViewId="0">'
    )

    if freeze_rows > 0:
        views += (
            f'<pane ySplit="{freeze_rows}" '
            f'topLeftCell="A{freeze_rows + 1}" '
            'activePane="bottomLeft" '
            'state="frozen"/>'
        )

    views += (
        "</sheetView>"
        "</sheetViews>"
    )


    dimension = (
        "A1:"
        + col_name(max_cols)
        + str(max(1, len(rows)))
    )

    auto = (
        f'<autoFilter ref="{auto_filter}"/>'
        if auto_filter
        else ""
    )


    merge_ranges = merge_ranges or []

    merge_xml = ""

    if merge_ranges:
        merge_xml = (
            f'<mergeCells count="{len(merge_ranges)}">'
            + "".join(
                f'<mergeCell ref="{ref}"/>'
                for ref in merge_ranges
            )
            + '</mergeCells>'
        )


    return f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet
 xmlns="{NS_MAIN}"
 xmlns:r="{NS_REL}">
 <dimension ref="{dimension}"/>
 {views}
 <sheetFormatPr defaultRowHeight="18"/>
 <cols>{cols}</cols>
 <sheetData>{''.join(output)}</sheetData>
 {auto}
 {merge_xml}
</worksheet>
'''


def reward_text(items):
    if not isinstance(items, list):
        return "—"

    output = []

    for item in items:
        if not isinstance(item, dict):
            continue

        name = clean(
            item.get("item_name")
        )

        qty = int(
            item.get("quantity")
            or 0
        )

        if name and qty > 0:
            output.append(
                f"{name} ×{qty}"
            )

    return (
        "、".join(output)
        if output
        else "—"
    )



def build_workbook(report):
    founder = clean(
        report.get("founder")
        or "未知玩家"
    )

    summary = (
        report.get("summary")
        or {}
    )

    confirmed = (
        report.get("confirmed")
        or []
    )

    insufficient = (
        report.get("insufficient")
        or []
    )

    reward_summary = (
        report.get("reward_summary")
        or {}
    )


    # ========================================================
    # Sheet 1 - 確認異常
    # ========================================================

    rows1 = [
        [
            {
                "value":
                    "UL.GG｜受害者協會｜確認異常紀錄",
                "style": 1,
            }
        ],
        [
            {
                "value":
                    f"玩家：{founder}",
                "style": 3,
            }
        ],
        [
            {
                "value":
                    "本表僅列 HP=0 確認擊破且"
                    "渦主未列入本站玩家紀錄之 Raid。"
                    " UL.GG 無法驗證官方帳號"
                    "實際獎勵入帳結果。",
                "style": 4,
            }
        ],
        [
            {"value": "#", "style": 2},
            {"value": "時間", "style": 2},
            {"value": "BOSS", "style": 2},
            {"value": "渦階", "style": 2},
            {"value": "星等", "style": 2},
            {"value": "渦碼", "style": 2},
            {"value": "參與人數", "style": 2},
            {
                "value": "Treasure Level",
                "style": 2,
            },
            {"value": "判定依據", "style": 2},
            {"value": "開渦配置", "style": 2},
            {"value": "參加配置", "style": 2},
            {"value": "擊破配置", "style": 2},
            {"value": "排名獎勵", "style": 2},
        ],
    ]

    for index, row in enumerate(
        confirmed,
        1,
    ):
        reward = (
            row.get("reward")
            or {}
        )

        rarity = row.get("rarity")
        tl = row.get("treasure_level")

        rows1.append([
            index,

            clean(
                row.get("time")
                or "—"
            ),

            clean(
                row.get("boss")
                or "—"
            ),

            clean(
                row.get("whirlpool_tier")
                or "—"
            ),

            (
                f"{rarity}★"
                if rarity
                else "—"
            ),

            {
                "value":
                    clean(
                        row.get("raid_id")
                        or "—"
                    ),
                "style": 5,
            },

            int(
                row.get("participant_count")
                or 0
            ),

            (
                tl
                if isinstance(tl, int)
                else "—"
            ),

            {
                "value":
                    clean(
                        row.get("evidence")
                        or "—"
                    ),
                "style": 3,
            },

            {
                "value":
                    reward_text(
                        reward.get("discovery")
                    ),
                "style": 3,
            },

            {
                "value":
                    reward_text(
                        reward.get("participation")
                    ),
                "style": 3,
            },

            {
                "value":
                    reward_text(
                        reward.get("defeat")
                    ),
                "style": 3,
            },

            {
                "value":
                    "無法精確還原",
                "style": 4,
            },
        ])


    # RAID_VICTIM_XLSX_LAYOUT_V2
    #
    # Official-support-friendly order:
    # # / Raid ID / defeated time / boss / tier / rarity /
    # participants / evidence / rewards / TL
    order = [
        0,   # #
        5,   # raid code
        1,   # time
        2,   # boss
        3,   # tier
        4,   # rarity
        6,   # participants
        8,   # evidence
        9,   # discovery
        10,  # participation
        11,  # defeat
        12,  # ranking
        7,   # treasure level
    ]

    rows1[3] = [
        rows1[3][i]
        for i in order
    ]

    rows1[4:] = [
        [
            row[i]
            for i in order
        ]
        for row in rows1[4:]
    ]

    rows1[3][1]["value"] = (
        "渦碼（Raid ID／官方回查）"
    )

    rows1[3][2]["value"] = (
        "擊破時間"
    )

    rows1[2][0]["value"] += (
        " 官方客服回查時，請以每筆「渦碼（Raid ID）」"
        "作為逐筆查詢依據。"
    )


    # ========================================================
    # Sheet 2 - 統計摘要
    # ========================================================

    boss_counts = Counter(
        clean(
            row.get("boss")
            or "未知"
        )
        for row in confirmed
    )

    rows2 = [
        [
            {
                "value":
                    "UL.GG｜受害者協會｜統計摘要",
                "style": 1,
            }
        ],
        [
            {
                "value":
                    f"玩家：{founder}",
                "style": 3,
            }
        ],
        [],
        [
            {
                "value": "統計項目",
                "style": 2,
            },
            {
                "value": "數值",
                "style": 2,
            },
        ],
        [
            "開渦總數",
            int(
                summary.get("opened")
                or 0
            ),
        ],
        [
            "有本人玩家紀錄",
            int(
                summary.get(
                    "founder_recorded"
                )
                or 0
            ),
        ],
        [
            "渦主未列名",
            int(
                summary.get(
                    "founder_missing"
                )
                or 0
            ),
        ],
        [
            "確認異常",
            int(
                summary.get(
                    "confirmed_anomaly"
                )
                or 0
            ),
        ],
        [
            "資料不足",
            int(
                summary.get(
                    "insufficient_evidence"
                )
                or 0
            ),
        ],
        [
            "確認異常率",
            (
                str(
                    summary.get(
                        "confirmed_rate_percent",
                        0,
                    )
                )
                + "%"
            ),
        ],
        [],
        [
            {
                "value":
                    "確認異常 BOSS 分布",
                "style": 2,
            },
            {
                "value": "場數",
                "style": 2,
            },
        ],
    ]

    for boss, count in (
        boss_counts.most_common()
    ):
        rows2.append([
            boss,
            count,
        ])

    rows2.extend([
        [],
        [
            {
                "value":
                    "可能受影響獎勵配置",
                "style": 2,
            },
            {
                "value": "數量",
                "style": 2,
            },
            {
                "value": "分類",
                "style": 2,
            },
        ],
    ])

    reward_labels = {
        "discovery":
            "開渦配置",

        "participation":
            "參加配置",

        "defeat":
            "擊破配置",
    }

    for group in (
        "discovery",
        "participation",
        "defeat",
    ):
        values = (
            reward_summary.get(group)
            or {}
        )

        for name, quantity in (
            values.items()
        ):
            rows2.append([
                clean(name),
                int(quantity or 0),
                reward_labels[group],
            ])

    rows2.extend([
        [
            "排名獎勵",
            "無法精確還原",
            "不納入合計",
        ],
        [],
        [
            {
                "value": "資料不足紀錄",
                "style": 2,
            },
            {
                "value": "時間",
                "style": 2,
            },
            {
                "value": "BOSS",
                "style": 2,
            },
            {
                "value": "渦碼",
                "style": 2,
            },
            {
                "value": "原因",
                "style": 2,
            },
        ],
    ])

    if insufficient:
        for row in insufficient:
            rows2.append([
                "資料不足",

                clean(
                    row.get("time")
                    or "—"
                ),

                clean(
                    row.get("boss")
                    or "—"
                ),

                clean(
                    row.get("raid_id")
                    or "—"
                ),

                {
                    "value":
                        clean(
                            row.get("evidence")
                            or "—"
                        ),
                    "style": 3,
                },
            ])

    else:
        rows2.append([
            "—",
            "—",
            "—",
            "—",
            "無",
        ])


    # ========================================================
    # Sheet 3 - 判定說明
    # ========================================================

    generated = clean(
        report.get("generated_at")
        or datetime.now(
            timezone.utc
        ).isoformat()
    )

    rows3 = [
        [
            {
                "value":
                    "UL.GG｜受害者協會｜判定說明",
                "style": 1,
            }
        ],
        [
            {
                "value":
                    f"產生時間：{generated}",
                "style": 3,
            }
        ],
        [],
        [
            {
                "value": "項目",
                "style": 2,
            },
            {
                "value": "說明",
                "style": 2,
            },
        ],
        [
            "確認異常條件",
            {
                "value":
                    "① 登入會員本人為渦主；"
                    "② UL.GG 直接觀測 HP=0；"
                    "③ 玩家紀錄未出現該渦主。",
                "style": 3,
            },
        ],
        [
            "資料不足",
            {
                "value":
                    "渦主未列入玩家紀錄，"
                    "但 UL.GG 未取得 HP=0 "
                    "權威擊破證據，"
                    "因此不列入確認異常。",
                "style": 3,
            },
        ],
        [
            "UL.GG 可驗證",
            {
                "value":
                    "Raid ID、渦主、BOSS、"
                    "渦階、星等、觀測時間、"
                    "HP=0 擊破及本站玩家名單。",
                "style": 3,
            },
        ],
        [
            "UL.GG 無法驗證",
            {
                "value":
                    "官方帳號背包或伺服器端"
                    "實際獎勵入帳結果。",
                "style": 4,
            },
        ],
        [
            "獎勵配置",
            {
                "value":
                    "缺少 Treasure Level 時，"
                    "僅在同 BOSS／星等／渦階"
                    "所有候選 TL 的"
                    "物品名稱與數量一致時，"
                    "彙總非排名獎勵。",
                "style": 3,
            },
        ],
        [
            "排名獎勵",
            {
                "value":
                    "缺少可靠 Rank/TL，"
                    "無法精確還原，"
                    "因此不納入合計。",
                "style": 4,
            },
        ],
        [
            "報表用途",
            {
                "value":
                    "供玩家整理異常 Raid "
                    "紀錄並向官方客服反映。",
                "style": 3,
            },
        ],
    ]

    sheets = [
        (
            "確認異常",
            make_sheet(
                rows1,
                [
                    6, 22, 22, 14, 12,
                    8, 11, 38, 28,
                    24, 24, 24, 15,
                ],
                merge_ranges=[
                    "A1:M1",
                    "A2:M2",
                    "A3:M3",
                ],
                freeze_rows=4,
                auto_filter=(
                    "A4:M"
                    + str(
                        max(
                            4,
                            len(rows1),
                        )
                    )
                ),
            ),
        ),

        (
            "統計摘要",
            make_sheet(
                rows2,
                [
                    28,
                    20,
                    18,
                    22,
                    42,
                ],
                merge_ranges=[
                    "A1:E1",
                    "A2:E2",
                ],
                freeze_rows=4,
            ),
        ),

        (
            "判定說明",
            make_sheet(
                rows3,
                [
                    22,
                    80,
                ],
                merge_ranges=[
                    "A1:B1",
                    "A2:B2",
                ],
                freeze_rows=4,
            ),
        ),
    ]


    styles = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="{NS_MAIN}">
 <fonts count="3">
  <font>
   <sz val="11"/>
   <name val="Aptos"/>
  </font>

  <font>
   <b/>
   <sz val="16"/>
   <color rgb="FFF1F3F8"/>
   <name val="Aptos Display"/>
  </font>

  <font>
   <b/>
   <sz val="11"/>
   <color rgb="FFF1F3F8"/>
   <name val="Aptos"/>
  </font>
 </fonts>

 <fills count="4">
  <fill>
   <patternFill patternType="none"/>
  </fill>

  <fill>
   <patternFill patternType="gray125"/>
  </fill>

  <fill>
   <patternFill patternType="solid">
    <fgColor rgb="FF202636"/>
   </patternFill>
  </fill>

  <fill>
   <patternFill patternType="solid">
    <fgColor rgb="FF151A24"/>
   </patternFill>
  </fill>
 </fills>

 <borders count="1">
  <border>
   <left/>
   <right/>
   <top/>
   <bottom/>
   <diagonal/>
  </border>
 </borders>

 <cellStyleXfs count="1">
  <xf
   numFmtId="0"
   fontId="0"
   fillId="0"
   borderId="0"
  />
 </cellStyleXfs>

 <cellXfs count="6">
  <xf
   numFmtId="0"
   fontId="0"
   fillId="0"
   borderId="0"
   xfId="0"
  />

  <xf
   numFmtId="0"
   fontId="1"
   fillId="3"
   borderId="0"
   xfId="0"
   applyFont="1"
   applyFill="1"
  />

  <xf
   numFmtId="0"
   fontId="2"
   fillId="2"
   borderId="0"
   xfId="0"
   applyFont="1"
   applyFill="1"
  />

  <xf
   numFmtId="0"
   fontId="0"
   fillId="0"
   borderId="0"
   xfId="0"
   applyAlignment="1">
   <alignment
    vertical="top"
    wrapText="1"
   />
  </xf>

  <xf
   numFmtId="0"
   fontId="0"
   fillId="0"
   borderId="0"
   xfId="0"
   applyAlignment="1">
   <alignment
    vertical="top"
    wrapText="1"
   />
  </xf>

  <xf
   numFmtId="49"
   fontId="0"
   fillId="0"
   borderId="0"
   xfId="0"
   applyNumberFormat="1"
  />
 </cellXfs>

 <cellStyles count="1">
  <cellStyle
   name="Normal"
   xfId="0"
   builtinId="0"
  />
 </cellStyles>
</styleSheet>
'''


    sheet_tags = "".join(
        (
            f'<sheet name="{escape(name)}" '
            f'sheetId="{index}" '
            f'r:id="rId{index}"/>'
        )
        for index, (name, _)
        in enumerate(
            sheets,
            1,
        )
    )


    workbook = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook
 xmlns="{NS_MAIN}"
 xmlns:r="{NS_REL}">
 <sheets>
  {sheet_tags}
 </sheets>
</workbook>
'''


    relations = []

    for index in range(
        1,
        len(sheets) + 1,
    ):
        relations.append(
            (
                f'<Relationship '
                f'Id="rId{index}" '
                'Type="http://schemas.openxmlformats.org/'
                'officeDocument/2006/relationships/worksheet" '
                f'Target="worksheets/sheet{index}.xml"/>'
            )
        )


    relations.append(
        (
            f'<Relationship '
            f'Id="rId{len(sheets) + 1}" '
            'Type="http://schemas.openxmlformats.org/'
            'officeDocument/2006/relationships/styles" '
            'Target="styles.xml"/>'
        )
    )


    workbook_rels = (
        '<?xml version="1.0" '
        'encoding="UTF-8" standalone="yes"?>'
        '<Relationships '
        'xmlns="http://schemas.openxmlformats.org/'
        'package/2006/relationships">'
        + "".join(relations)
        + "</Relationships>"
    )


    overrides = [
        (
            '<Override '
            'PartName="/xl/workbook.xml" '
            'ContentType="application/vnd.openxmlformats-'
            'officedocument.spreadsheetml.sheet.main+xml"/>'
        ),

        (
            '<Override '
            'PartName="/xl/styles.xml" '
            'ContentType="application/vnd.openxmlformats-'
            'officedocument.spreadsheetml.styles+xml"/>'
        ),
    ]


    for index in range(
        1,
        len(sheets) + 1,
    ):
        overrides.append(
            (
                '<Override '
                f'PartName="/xl/worksheets/sheet{index}.xml" '
                'ContentType="application/vnd.openxmlformats-'
                'officedocument.spreadsheetml.worksheet+xml"/>'
            )
        )


    content_types = (
        '<?xml version="1.0" '
        'encoding="UTF-8" standalone="yes"?>'
        '<Types '
        'xmlns="http://schemas.openxmlformats.org/'
        'package/2006/content-types">'
        '<Default Extension="rels" '
        'ContentType="application/vnd.openxmlformats-package.'
        'relationships+xml"/>'
        '<Default Extension="xml" '
        'ContentType="application/xml"/>'
        + "".join(overrides)
        + "</Types>"
    )


    root_rels = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships
 xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
 <Relationship
  Id="rId1"
  Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
  Target="xl/workbook.xml"
 />
</Relationships>
'''


    output = io.BytesIO()

    with zipfile.ZipFile(
        output,
        "w",
        zipfile.ZIP_DEFLATED,
    ) as archive:

        archive.writestr(
            "[Content_Types].xml",
            content_types,
        )

        archive.writestr(
            "_rels/.rels",
            root_rels,
        )

        archive.writestr(
            "xl/workbook.xml",
            workbook,
        )

        archive.writestr(
            "xl/_rels/workbook.xml.rels",
            workbook_rels,
        )

        archive.writestr(
            "xl/styles.xml",
            styles,
        )


        for index, (
            _,
            xml,
        ) in enumerate(
            sheets,
            1,
        ):
            archive.writestr(
                (
                    "xl/worksheets/"
                    f"sheet{index}.xml"
                ),
                xml,
            )


    return output.getvalue()


def main():
    try:
        report = json.load(
            sys.stdin
        )

        if (
            not isinstance(
                report,
                dict,
            )
            or report.get("ok")
            is not True
        ):
            raise ValueError(
                "invalid victim report JSON"
            )

        sys.stdout.buffer.write(
            build_workbook(report)
        )

    except Exception as error:
        print(
            (
                "raid_victim_xlsx: "
                f"{type(error).__name__}: "
                f"{error}"
            ),
            file=sys.stderr,
        )

        raise SystemExit(1)


if __name__ == "__main__":
    main()
