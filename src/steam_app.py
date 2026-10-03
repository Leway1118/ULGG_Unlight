from pathlib import Path
import os
from time import sleep

from crawler import WebCrawler


def update_data():
    from script.generate_cost import main as generate_cost
    from script.generate_quests import main as generate_quests
    from script.generate_achievements import main as generate_achievements
    from script.generate_news import gen_steam_news as generate_news
    from script.generate_qp import main as generate_qp
    from script.generate_lot import main as generate_lot
    from script.generate_queststory import main as generate_queststory
    from script.generate_cc_require import main as generate_cc_require

    data_dir = Path(
        "steam/website/www.playunlight-hwa6rdrj7bxtudeh.site/images/assets/data"
    )
    res_dir = Path("steam/data")
    quest_story_dir = Path(
        "steam/website/www.playunlight-hwa6rdrj7bxtudeh.site/images/assets/quest/story"
    )
    
    generate_cost(data_dir, res_dir)
    generate_quests(data_dir, res_dir)
    generate_achievements(data_dir, res_dir)
    generate_news(data_dir, res_dir)
    generate_qp(data_dir, res_dir)
    generate_lot(data_dir, res_dir)
    generate_queststory(data_dir, quest_story_dir, res_dir)
    generate_cc_require(data_dir, res_dir)

def get_url(url_file: Path)->str:
    url_file = Path("url.txt")
    url_file.unlink(missing_ok=True)
    os.system("open nwjs/UNLIGHT_Revive.app")
    while not url_file.exists():
        sleep(0.5)
    url = url_file.read_text().strip()
    url_file.unlink()
    return url


def main():
    url_file = Path("url.txt")
    url = get_url(url_file)
    crawler = WebCrawler(
        url=url,
        work_dir=Path("steam"),
        website_root=Path("website"),
        assets_data_path=Path("images/assets/data"),
        game_data_path=Path("assets"),
    )
    crawler.get_resource()
    crawler.get_webpack_resource()

    print("Downloading links")
    paths = crawler.get_path_from_resource()
    links = crawler.get_unparsed_links(paths)
    crawler.get_vars(links)
    links = crawler.parse_links(links)
    crawler.batch_download_links(links)

    update_data()


if __name__ == "__main__":
    main()
