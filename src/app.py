from pathlib import Path

from crawler import WebCrawler


def update_data():
    from script.generate_cost import main as generate_cost
    from script.generate_quests import main as generate_quests
    from script.generate_achievements import main as generate_achievements
    from script.generate_news import gen_dmm_news as generate_news
    from script.generate_qp import main as generate_qp
    from script.generate_lot import main as generate_lot
    from script.generate_queststory import main as generate_queststory
    from script.generate_cc_require import main as generate_cc_require

    data_dir = Path("website/www.playunlight-4zg9jewy2ws9yncu.com/images/assets/data")
    res_dir = Path("data")
    quest_story_dir = Path(
        "website/www.playunlight-czjd5aajhngs3ire.com/images/assets/quest/story"
    )

    generate_cost(data_dir, res_dir)
    generate_quests(data_dir, res_dir)
    generate_achievements(data_dir, res_dir)
    generate_news(data_dir, res_dir)
    generate_qp(data_dir, res_dir)
    generate_lot(data_dir, res_dir)
    generate_queststory(data_dir, quest_story_dir, res_dir)
    generate_cc_require(data_dir, res_dir)


def main():
    crawler = WebCrawler(
        url="https://www.playunlight-dmm.com/?owner=52390705&cdp_id=SpNMVqODKhek6FSe",
        work_dir=Path("."),
        website_root=Path("website"),
        assets_data_path=Path("images/assets/data"),
        game_data_path=Path("javascripts/assets"),
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
