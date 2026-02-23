#!/usr/bin/env python3
import asyncio
import json
import time
from pathlib import Path
from urllib.request import Request, urlopen

TARGETS = [
    "https://httpbin.org/get",
]

def fetch(url):
    req = Request(url, headers={"User-Agent": "AutonomousBot/1.0"})
    with urlopen(req, timeout=15) as r:  # nosec B310
        return r.read().decode("utf-8", errors="ignore")[:2000]

async def worker(url, out_dir):
    loop = asyncio.get_running_loop()
    content = await loop.run_in_executor(None, fetch, url)
    ts = int(time.time())
    p = Path(out_dir) / f"result_{ts}.json"
    p.write_text(json.dumps({"url": url, "preview": content}, ensure_ascii=False, indent=2), encoding="utf-8")
    return str(p)

async def main():
    out_dir = Path("output")
    out_dir.mkdir(parents=True, exist_ok=True)
    paths = await asyncio.gather(*(worker(url, out_dir) for url in TARGETS))
    print("done", paths)

if __name__ == "__main__":
    asyncio.run(main())
