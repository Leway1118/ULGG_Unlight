import requests
from bs4 import BeautifulSoup
import re
import json
import time


BASE = "https://w.atwiki.jp/unlight-fbtw/pages/"


headers = {
    "User-Agent": "Mozilla/5.0"
}


def get_soup(url):

    try:

        r = requests.get(
            url,
            headers=headers,
            timeout=3
        )

        if r.status_code != 200:
            return None

        r.encoding = "utf-8"

        return BeautifulSoup(
            r.text,
            "html.parser"
        )

    except requests.RequestException:

        return None


def parse_character(url):

    soup = get_soup(url)

    result = {
        "url": url,
        "name": "",
        "birthday": ""
    }

    if soup is None:
        return result

    # -----------------
    # 抓第一張基本資料表
    # -----------------

    for table in soup.find_all("table"):

        rows = table.find_all("tr")

        for row in rows:

            cols = [
                c.get_text(
                    " ",
                    strip=True
                )
                for c in row.find_all("td")
            ]


            if len(cols) >= 2:

                key = cols[0]

                value = cols[1]


                if key == "中文名字":
                    result["name"] = value


                if key == "生日":
                    result["birthday"] = value



    return result



data = []


# atwiki角色頁範圍
# 42開始測

for page_id in range(42, 300):


    url = (
        BASE
        +
        str(page_id)
        +
        ".html"
    )


    try:

        char = parse_character(url)


        if char["name"]:


            print(
                page_id,
                char["name"],
                char["birthday"]
            )


            data.append(char)


        time.sleep(0.3)



    except Exception as e:

        print(
            "ERROR",
            page_id,
            e
        )



with open(
    "unlight_birthday.json",
    "w",
    encoding="utf-8"
) as f:


    json.dump(
        data,
        f,
        ensure_ascii=False,
        indent=2
    )


print(
    "完成",
    len(data),
    "角色"
)