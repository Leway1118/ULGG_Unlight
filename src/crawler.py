import subprocess
import re
import json
import shutil
import logging
from pathlib import Path
from typing import Generator
from urllib.parse import urlparse
from concurrent.futures import ThreadPoolExecutor
from collections import deque
from queue import Queue
from functools import partial
from ast import literal_eval

import requests

logging.basicConfig(level=logging.WARNING)


class WebCrawler:
    SKIP_CHECK_404 = False
    CC039_RND = range(1, 4)  # 布勞的弗拉姆
    CC041_RND = range(2)  # 音音夢長大
    LANGS = ("ja", "en", "kr", "scn", "tcn")
    MAPS = [
        str(i) for i in range(1, 4)
    ]  # https://www.he4bliurehiufdfbl-6ae5rrg.site/images/assets/quest/story/map04/map_name.png
    STORY = ["0_2", "1_7", "2_7", "3_7", "10_2", "10_5", "10_7"]
    STAGES = [
        f"{i:03}" for i in range(14)
    ]  # https://www.he4bliurehiufdfbl-6ae5rrg.site/images/assets/bg/013/bg_013.webp
    EXCLUDE_KEYWORDS = ["phaser"]

    def __init__(
        self,
        url: str,
        work_dir: Path,
        website_root: Path,
        game_data_path: Path,
        assets_data_path: Path,
    ):
        self.url = url
        self.work_dir = work_dir
        self.website_root = work_dir / website_root
        self.game_data_path = game_data_path
        self.assets_data_path = assets_data_path

        self.host = Path(urlparse(url).netloc)
        self.lang_pattern = re.compile(r"--LANGUAGE--")
        load_pattern = re.compile(
            r"\.load\.(?!scenePlugin|once|start|setPath)\w*\((?!UL_).*?(?<!'r0'),(?P<url>.*?)(?:,|\))",
            re.DOTALL,
        )
        quotes = "'`\""
        loader_patterns = [
            re.compile(
                rf"""(?P<url>{quote}[^{quote}\,n\s]*?images\/assets\/[^{quote},\n\s]*?{quote})"""
            )
            for quote in quotes
        ]
        self.patterns = [load_pattern, *loader_patterns]

        self.etags = json.loads((self.work_dir / "etags.json").read_text())
        self.unsolves_links: set[str] = (
            set(json.loads((self.work_dir / "unsolve_links.json").read_text()))
            if self.SKIP_CHECK_404
            else set()
        )
        (self.work_dir / "unsolve_link.txt").unlink(missing_ok=True)
        self.exclude_keywords = [
            "phaser",
            "src_game_Loader_ts-src_helper_web-socket-client_js.js",
        ]
        self.preload_config()

    def preload_config(self):
        self.CHARAS: list[str] = []
        self.FILENAMES: list[str] = []
        self.SKILLS: list[tuple[str, ...]] = []
        self.MC_BOSS: list[str] = []

        self.unlight_config = self.host / "unlight-config.json"
        self.unlight_assets = self.host / "assets/unlight-assets.json"
        self.batch_download_links(
            [f"https://{path}" for path in (self.unlight_config, self.unlight_assets)]
        )
        unlight_cfg_data = json.loads(
            (self.website_root / self.unlight_config).read_text()
        )
        self.hosts = unlight_cfg_data["domains"]["assets"]["urls"]
        self.domains = [
            host.removeprefix("https://www.") for host in self.hosts
        ]  # https://www.${PATH[Math.trunc(Math.random() * 2)]}/images/assets/blank.png
        self.assets_data_path = (
            f"{self.hosts[0].removeprefix('https://')}" / self.assets_data_path
        )
        self.game_data_path = self.host / self.game_data_path

        cc = self.assets_data_path / "cc_asset.json"
        mc = self.assets_data_path / "mc_asset.json"
        mc_boss = self.assets_data_path / "mc_boss.json"
        voice = self.game_data_path / "voice.json"
        cfg_paths = [cc, mc, mc_boss]
        cfg_links = [f"https://{path}" for path in cfg_paths + [voice]]
        self.batch_download_links(cfg_links)
        for path in cfg_paths:
            file_path = self.website_root / path
            data = json.loads(file_path.read_text())
            frames = [frame for frame in data["frames"] if frame.get("chara")]
            charas = [frame["chara"] for frame in frames]
            file_names = [frame["filename"] for frame in frames]
            skills = [
                tuple(filter(bool, (frame[f"base{i}"] for i in range(1, 5))))
                for frame in frames
            ]
            if path == mc_boss:
                self.MC_BOSS.extend(charas)
            self.CHARAS.extend(charas)
            self.FILENAMES.extend(file_names)
            self.SKILLS.extend(skills)
        self.VOICES = json.loads((self.website_root / voice).read_text())

    def load_textures(self) -> list[str]:
        links: list[str] = []
        data = json.loads((self.website_root / self.unlight_assets).read_text())
        for scene in data.values():
            for kind in scene.values():
                if not isinstance(kind, list):
                    continue
                for texture in kind:
                    for key, value in texture.items():
                        if "url" in key.lower():
                            urls = self.render_language(value)
                            links.extend(
                                f"{host}/{url}" for url in urls for host in self.hosts
                            )
        return links

    def render_language(self, url: str) -> list[str]:
        match = self.lang_pattern.search(url)
        if match is None:
            return [url]
        return [url.replace("--LANGUAGE--", lang) for lang in self.LANGS]

    @staticmethod
    def find_vars(
        text: str, ignore_unmatched: bool = False
    ) -> Generator[str, None, None]:
        pos = float("inf")
        nest_count = 0
        for idx, char in enumerate(text):
            match char:
                case "$":
                    if pos == float("inf"):
                        nest_count = 0
                    pos = min(pos, idx)
                case "{":
                    nest_count += 1
                case "}":
                    nest_count -= 1
                case _:
                    pass
            if idx - pos > 1 and nest_count == 0:
                yield text[pos : idx + 1]
                pos = float("inf")
        if not ignore_unmatched and pos != float("inf"):
            raise ValueError(f"Unmatched variable in text: {text}")
        return None

    def update_etag(self):
        etag_file = self.work_dir / "etags.json"
        etag_file.write_text(json.dumps(self.etags, indent=4))

    def update_unsolve_links(self):
        unsolve_links_file = self.work_dir / "unsolve_links.json"
        unsolve_links_file.write_text(json.dumps(sorted(self.unsolves_links), indent=4))

    def get_resource(self):
        subprocess.run(
            [
                "wget",
                "-mpEk",
                "-e",
                "robots=off",
                "-P",
                self.website_root,
                f"{self.url}",
            ]
        )
        subprocess.run(
            f"find {self.website_root / self.host} -type f -name '*\\?*' -exec bash -c 'mv \"$0\" \"${{0%\\?*}}\"' {{}} \\;",
            shell=True,
        )

    def cleanup_webpack(self) -> None:
        client_dir = self.website_root / self.host / "client"
        if client_dir.exists():
            shutil.rmtree(client_dir)
        client_dir.mkdir(parents=True, exist_ok=True)

    def check_format(self, p: Path) -> Path | None:
        if len(p.suffixes) < 2:
            return None
        new_name = f"{p.stem.split('.')[0]}{p.suffix}"
        return p.copy(p.with_name(new_name))

    def get_webpack_resource(self) -> None:
        client_dir = self.website_root / self.host / "client"
        entry_point = self.website_root / self.host / "index.html"
        with open(entry_point, "r") as f:
            content = f.read()
        pattern = re.compile(r'src="client/(?P<file_name>.+?\.js)"')
        webpack_paths = [
            client_dir / match.group("file_name") for match in pattern.finditer(content)
        ]
        format_paths = set(webpack_paths)
        for path in webpack_paths:
            if "main" in path.name:
                continue
            elif "runtime" in path.name:
                chunks = self.get_chunks(path)
                format_paths.update(
                    self.batch_download_links(
                        [f"https://{self.host}/client/{chunk}" for chunk in chunks]
                    )
                )
            else:
                print(f"Unknown webpack file: {path}")
        to_format_paths = {p for path in format_paths if (p := self.check_format(path))}
        subprocess.run(["prettier", "--write", *map(str, to_format_paths)])

    def get_chunks(self, js_path: Path) -> list[str]:
        # 最裡層且長度超過2的花括號
        chunk_map_pattern = re.compile(r"\{([^{};]+,){2,}[^{};]+\}", re.DOTALL)
        with open(js_path, "r") as f:
            content = f.read()
        chunk_maps = [match.group() for match in chunk_map_pattern.finditer(content)]
        if len(chunk_maps) != 2:
            raise ValueError("Cannot parse chunk map")
        chunk_maps.sort(key=len)
        nampe_map, hash_map = map(literal_eval, chunk_maps)
        res = []
        for idx, hash in hash_map.items():
            name = nampe_map.get(idx)
            file_name = f"{name}.{hash}.js" if name else f"{idx}.{hash}.js"
            res.append(file_name)
        res.sort()
        return res

    def get_path_from_resource(self) -> list[Path]:
        paths: list[Path] = []
        root = self.website_root / self.host
        for dirpath, _, files in root.walk():
            for file in files:
                file_path = dirpath / file
                if any(keyword in str(file) for keyword in self.exclude_keywords):
                    continue
                paths.append(file_path)
        return paths

    def search_link(self, content: str) -> list[str]:
        urls: list[str] = []
        for pattern in self.patterns:
            matched = pattern.findall(content)
            urls.extend(matched)
        return urls

    def get_unparsed_links(self, paths: list[Path]) -> list[str]:
        links: list[str] = []
        for path in paths:
            try:
                with open(path, "r") as f:
                    links.extend(self.search_link(f.read()))
            except UnicodeDecodeError:
                pass
        non_duplicated_link = {
            link.strip().removeprefix("url:").removesuffix("}").strip().strip("'\"`\\")
            for link in links
        }
        sorted_link = sorted(non_duplicated_link)
        unparsed_links_txt = self.work_dir / "unparsed_links.txt"
        unparsed_links_txt.write_text("".join(f"{link}\n" for link in sorted_link))
        return sorted_link

    def get_vars(self, unparsed_links: list[str]) -> list[str]:
        vars: set[str] = set()
        for link in unparsed_links:
            matches = self.find_vars(link, ignore_unmatched=True)
            for match in matches:
                vars.add(match)
        sorted_vars = sorted(vars)
        vars_txt = self.work_dir / "vars.txt"
        vars_txt.write_text("".join(f"{link}\n" for link in sorted_vars))
        return sorted_vars

    def render_link(self, link: str, matches: list[str]) -> list[str]:
        matches.sort(key=len, reverse=True)
        for m in matches:
            if "base" in m or "skill" in m:
                link = link.replace(m, "${chara_skill}")
            elif "voice" in m or "VOICE" in m:
                link = link.replace(m, "${chara_voice}")
            elif "path" in m:
                link = link.replace(m, "${path}")
            elif "PATH" in m:
                link = link.replace(m, "${domain}")
            elif "lang" in m:
                link = link.replace(m, "${lang}")
            elif "filename" in m:
                link = link.replace(m, "${filename}")
            elif "char" in m:
                link = link.replace(m, "${chara}")
            elif "map" in m:
                link = link.replace(m, "${map}")
            elif "stage" in m or "room_bgm" in m:
                link = link.replace(m, "${stage}")
            elif "cc039_rnd" in m:
                pass
            elif "random" in m:
                link = link.replace(m, "${cc041_rnd}")
            elif m == "${this.mons}":
                link = link.replace(m, "${raid_mons}")
            elif "lot_data" in m:
                link = link.replace(m, "${lot_special}")
            elif "story_id" in m:
                link = link.replace(m, "${story_id}")
            elif "url" in m:
                link = link.replace(m, "${host}")
            elif m == "${c}":
                link = link.replace(m, "${chara}")
            else:
                print(f"Unknown var: {m}, {link = }")

        if "cc039_rnd" in link:
            link = link.replace("${chara}", "cc039")
        elif link.endswith("_ent.png"):
            link = link.replace("${chara}", "cc028")  # 泡沫沒有狼
        elif link.endswith("images/assets/cc/cc012/effect/${chara_skill}_2.png"):
            link = link.replace("${chara_skill}", "cc012_sk03")  # 艾喵九魂

        res = deque([link])
        while res:
            link = res.pop()
            var = next(self.find_vars(link), None)
            if not var:
                res.appendleft(link)
                break
            match var:
                case "${path}":
                    res.extendleft([link.replace(var, path) for path in self.hosts])
                case "${domain}":
                    res.extendleft(
                        [link.replace(var, domain) for domain in self.domains]
                    )
                case "${lang}":
                    res.extendleft([link.replace(var, lang) for lang in self.LANGS])
                case "${filename}" | "${chara}" | "${chara_skill}" | "${chara_voice}":
                    if "${chara_voice}" in link:
                        for chara, chara_voices in self.VOICES.items():
                            res.extendleft(
                                link.replace("${chara}", chara).replace(
                                    "${chara_voice}", voice["data"]
                                )
                                for voice in chara_voices
                            )
                        continue
                    for filename, chara, chara_skills in zip(
                        self.FILENAMES, self.CHARAS, self.SKILLS
                    ):
                        chara_link = link.replace("${filename}", filename).replace(
                            "${chara}", chara
                        )
                        if "${chara_skill}" in link:
                            res.extendleft(
                                chara_link.replace("${chara_skill}", skill)
                                for skill in chara_skills
                            )
                        else:
                            res.appendleft(chara_link)
                case "${map}":
                    res.extendleft([link.replace(var, map) for map in self.MAPS])
                case "${stage}":
                    res.extendleft([link.replace(var, stage) for stage in self.STAGES])
                case "${cc039_rnd}":
                    res.extendleft(
                        [link.replace(var, str(num)) for num in self.CC039_RND]
                    )
                case "${cc041_rnd}":
                    res.extendleft(
                        [link.replace(var, str(num)) for num in self.CC041_RND]
                    )
                case "${story_id}":
                    res.extendleft([link.replace(var, story) for story in self.STORY])
                case "${raid_mons}":
                    res.extendleft(link.replace(var, mons) for mons in self.MC_BOSS)
                case "${host}":
                    res.extendleft(link.replace(var, host) for host in self.hosts)
                case "${lot_special}":
                    print("ignore lot_special")  # TODO: download lot_special
                case _:
                    print(f"Unknown var: {var}, {link = }")
        return list(res)

    def parse_links(self, unparesd_links: list[str]) -> list[str]:
        res: list[str] = []
        while unparesd_links:
            link = unparesd_links.pop()
            if link.startswith("texture.") or link.startswith("${texture_url"):
                continue
            try:
                match = list(self.find_vars(link))
            except ValueError:
                continue
            if match:
                parsed_links = self.render_link(link, match)
            else:
                parsed_links = [link]
            for link in parsed_links:
                links = [link]
                if not link.startswith("http"):
                    link = link.lstrip("/")
                    if link.startswith("javascripts") or link.startswith(".."):
                        links = [f"https://{self.host}/{link}"]
                    else:
                        links = [f"{host}/{link}" for host in self.hosts]
                for link in links:
                    res.extend(self.render_language(link))
        res = [
            link
            for link in set(res)
            if "/mc/cc" not in link
            and "/cc/mc" not in link
            and self.get_url_extension(link)
        ]
        textures = self.load_textures()
        res = sorted(set(res).union(textures))
        parsed_links_txt = self.work_dir / "parsed_links.txt"
        parsed_links_txt.write_text("".join(f"{link}\n" for link in res))
        return res

    @staticmethod
    def get_url_extension(url: str) -> str:
        path = urlparse(url).path
        ext = Path(path).suffix
        return ext

    def download_link(
        self, link: str, queue: Queue[tuple[str, str | None]]
    ) -> str | None:
        _, path = link.split("://", 1)
        if link in self.unsolves_links:
            return None
        try:
            with requests.get(link, stream=True, timeout=(3.05, 27)) as res:
                res.raise_for_status()
                etag = res.headers.get("ETag")
                if etag and etag == self.etags.get(path):
                    return path
                file_path = self.website_root / path
                file_path.parent.mkdir(parents=True, exist_ok=True)
                with open(file_path, "wb") as f:
                    res.raw.decode_content = True
                    shutil.copyfileobj(res.raw, f)
                queue.put((path, etag))
                logging.info(f"Downloaded {link}")
                return path
        except requests.exceptions.RequestException as e:
            self.unsolves_links.add(link)
            unsolve_link_file = self.work_dir / "unsolve_link.txt"
            with unsolve_link_file.open("a") as f:
                f.write(f"{link}\n{e}\n\n")
            return None

    def batch_download_links(self, links: list[str]) -> list[Path]:
        queue: Queue[tuple[str, str | None]] = Queue()
        download_link = partial(self.download_link, queue=queue)
        with ThreadPoolExecutor(max_workers=20) as executor:
            paths = executor.map(download_link, links)
        while not queue.empty():
            path, etag = queue.get_nowait()
            self.etags[path] = etag
        self.update_etag()
        self.update_unsolve_links()
        return [self.website_root / path for path in paths if path]
