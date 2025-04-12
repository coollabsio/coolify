__filename__ = "crawlers.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
import time
from utils import data_dir
from utils import save_json
from utils import user_agent_domain
from utils import remove_eol
from blocking import get_mil_domains_list
from blocking import get_gov_domains_list
from blocking import get_bsky_domains_list
from blocking import get_nostr_domains_list
from blocking import update_blocked_cache
from blocking import is_blocked_domain

default_user_agent_blocks = [
    'fedilist', 'ncsc scan', 'fedifetcher'
]


def update_known_crawlers(ua_str: str,
                          base_dir: str, known_crawlers: {},
                          last_known_crawler: int) -> int:
    """Updates a dictionary of known crawlers accessing nodeinfo
    or the masto API
    """
    if not ua_str:
        return None

    curr_time = int(time.time())
    if known_crawlers.get(ua_str):
        known_crawlers[ua_str]['hits'] += 1
        known_crawlers[ua_str]['lastseen'] = curr_time
    else:
        known_crawlers[ua_str] = {
            "lastseen": curr_time,
            "hits": 1
        }

    if curr_time - last_known_crawler >= 30:
        # remove any old observations
        remove_crawlers: list[str] = []
        for uagent, item in known_crawlers.items():
            if curr_time - item['lastseen'] >= 60 * 60 * 24 * 30:
                remove_crawlers.append(uagent)
        for uagent in remove_crawlers:
            del known_crawlers[uagent]
        # save the list of crawlers
        dir_str = data_dir(base_dir)
        save_json(known_crawlers, dir_str + '/knownCrawlers.json')
    return curr_time


def load_known_web_bots(base_dir: str) -> []:
    """Returns a list of known web bots
    """
    known_bots_filename = data_dir(base_dir) + '/knownBots.txt'
    if not os.path.isfile(known_bots_filename):
        return []
    crawlers_str = None
    try:
        with open(known_bots_filename, 'r', encoding='utf-8') as fp_crawlers:
            crawlers_str = fp_crawlers.read()
    except OSError:
        print('EX: unable to load web bots from ' +
              known_bots_filename)
    if not crawlers_str:
        return []
    known_bots: list[str] = []
    crawlers_list = crawlers_str.split('\n')
    for crawler in crawlers_list:
        if not crawler:
            continue
        crawler = remove_eol(crawler).strip()
        if not crawler:
            continue
        if crawler not in known_bots:
            known_bots.append(crawler)
    return known_bots


def _save_known_web_bots(base_dir: str, known_bots: []) -> bool:
    """Saves a list of known web bots
    """
    known_bots_filename = data_dir(base_dir) + '/knownBots.txt'
    known_bots_str = ''
    for crawler in known_bots:
        known_bots_str += crawler.strip() + '\n'
    try:
        with open(known_bots_filename, 'w+', encoding='utf-8') as fp_crawlers:
            fp_crawlers.write(known_bots_str)
    except OSError:
        print("EX: unable to save known web bots to " +
              known_bots_filename)
        return False
    return True


def blocked_user_agent(calling_domain: str, agent_str: str,
                       news_instance: bool, debug: bool,
                       user_agents_blocked: [],
                       blocked_cache_last_updated,
                       base_dir: str,
                       blocked_cache: [],
                       block_federated: [],
                       blocked_cache_update_secs: int,
                       crawlers_allowed: [],
                       known_bots: [], path: str,
                       block_military: {},
                       block_government: {},
                       block_bluesky: {},
                       block_nostr: {}):
    """Should a GET or POST be blocked based upon its user agent?
    """
    if not agent_str:
        return True, blocked_cache_last_updated, False

    agent_str_lower = agent_str.lower()
    for ua_block in default_user_agent_blocks:
        if ua_block in agent_str_lower:
            print('BLOCK: Blocked User agent 1: ' + ua_block)
            return True, blocked_cache_last_updated, False

    agent_domain = None

    if agent_str:
        contains_bot_string = False
        llm = False

        # is this an LLM crawler?
        # https://github.com/ai-robots-txt/ai.robots.txt/blob/main/robots.txt
        llm_bot_strings = (
            'gptbot', '-ai/', ' ai/', '-ai ', ' ai ', 'chatgpt',
            'anthropic', 'mlbot', 'claude-web', 'claudebot', 'ccbot',
            'facebookbot', 'google-extended', 'piplbot', 'oai-search',
            'applebot', 'meta-external', 'diffbot', 'perplexitybot',
            'omgili', 'imagesiftbot', 'bytespider', 'amazonbot', 'youbot',
            'petalbot', 'ai2bot', 'allenai', 'firecrawl', 'friendlycrawler',
            'googleother', 'icc-crawler', 'scrapy', 'timpibot',
            'velenpublic', 'webzio-extended', 'cohere-ai',
            'cohere-train', 'crawlspace', 'facebookexternal',
            'img2dataset', 'isscyberriskcrawler', 'sidetrade', 'kangaroo.ai',
            'kangaroo bot', 'iaskspider', 'duckassistbot', 'pangubot',
            'semrush'
        )
        for bot_str in llm_bot_strings:
            if bot_str in agent_str_lower:
                if '://bot' not in agent_str_lower and \
                   '://robot' not in agent_str_lower and \
                   '://spider' not in agent_str_lower and \
                   'pixelfedbot/' not in agent_str_lower:
                    contains_bot_string = True
                    llm = True
                    break

        # is this a web crawler? If so then block it by default
        # unless this is a news instance or if it is in the allowed list
        bot_strings = (
            'bot/', 'bot-', '/bot', '_bot', 'bot_', 'bot;', ' bot ',
            '/robot', 'spider/', 'spider.ht', '/spider.', '-spider',
            'externalhit/', 'google',
            'facebook', 'slurp', 'crawler', 'crawling', ' crawl ',
            'gigablast', 'archive.org', 'httrack',
            'spider-', ' spider ', 'findlink', 'ips-agent',
            'woriobot', 'webbot', 'webcrawl',
            'voilabot', 'rank/', 'ezooms', 'heritrix', 'indeedbot',
            'woobot', 'infobot', 'viewbot', 'swimgbot', 'eright',
            'apercite', 'bot (', 'summify', 'linkfind',
            'linkanalyze', 'analyzer', 'wotbox', 'ichiro',
            'drupact', 'searchengine', 'coccoc',
            'explorer/', 'explorer;', 'crystalsemantics',
            'scraper/', ' scraper ', ' scrape ', 'scraping')
        for bot_str in bot_strings:
            if bot_str in agent_str_lower:
                if '://bot' not in agent_str_lower and \
                   '://robot' not in agent_str_lower and \
                   '://spider' not in agent_str_lower and \
                   'pixelfedbot/' not in agent_str_lower:
                    contains_bot_string = True
                    break
        if contains_bot_string:
            if agent_str_lower not in known_bots:
                known_bots.append(agent_str_lower)
                known_bots.sort()
                _save_known_web_bots(base_dir, known_bots)
            # if this is a news instance then we want it
            # to be indexed by search engines
            if news_instance:
                return False, blocked_cache_last_updated, llm
            # is this crawler allowed?
            for crawler in crawlers_allowed:
                if crawler.lower() in agent_str_lower:
                    return False, blocked_cache_last_updated, llm
            print('BLOCK: Blocked Crawler: ' + agent_str)
            return True, blocked_cache_last_updated, llm
        # get domain name from User-Agent
        agent_domain = user_agent_domain(agent_str, debug)
    else:
        # no User-Agent header is present
        return True, blocked_cache_last_updated, False

    # is the User-Agent type blocked? eg. "Mastodon"
    if user_agents_blocked:
        blocked_ua = False
        for agent_name in user_agents_blocked:
            if agent_name in agent_str:
                blocked_ua = True
                break
        if blocked_ua:
            return True, blocked_cache_last_updated, False

    if not agent_domain:
        return False, blocked_cache_last_updated, False

    # is the User-Agent domain blocked
    blocked_ua = False
    if not agent_domain.startswith(calling_domain):
        blocked_cache_last_updated = \
            update_blocked_cache(base_dir, blocked_cache,
                                 blocked_cache_last_updated,
                                 blocked_cache_update_secs)

        blocked_ua = \
            is_blocked_domain(base_dir, agent_domain,
                              blocked_cache, block_federated)
        if blocked_ua:
            print('BLOCK: Blocked User agent 2: ' + agent_domain)

    block_dicts = {
        "military": block_military,
        "government": block_government,
        "bluesky": block_bluesky,
        "nostr": block_nostr
    }
    for block_type, block_dict in block_dicts.items():
        if blocked_ua or not block_dict:
            continue
        if '/users/' not in path:
            continue
        # which accounts is this?
        nickname = path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        # does this account block?
        if not block_dict.get(nickname):
            continue
        if block_type == "military":
            blk_domains = get_mil_domains_list()
        elif block_type == "government":
            blk_domains = get_gov_domains_list()
        elif block_type == "nostr":
            blk_domains = get_nostr_domains_list()
        else:
            blk_domains = get_bsky_domains_list()
        for domain_str in blk_domains:
            if '.' not in domain_str:
                tld = domain_str
                if agent_domain.endswith('.' + tld):
                    blocked_ua = True
                    print('BLOCK: Blocked ' + block_type +
                          ' tld user agent: ' + agent_domain)
                    break
            elif agent_domain.endswith(domain_str):
                blocked_ua = True
                print('BLOCK: Blocked ' + block_type +
                      ' user agent: ' + agent_domain)
                break

    return blocked_ua, blocked_cache_last_updated, False
