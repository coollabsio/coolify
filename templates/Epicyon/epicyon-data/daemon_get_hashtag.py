__filename__ = "daemon_get_hashtag.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
import urllib.parse
from session import establish_session
from httpcodes import http_400
from httpcodes import http_404
from httpcodes import write2
from httpheaders import login_headers
from httpheaders import redirect_headers
from httpheaders import set_headers
from blocking import is_blocked_hashtag
from utils import convert_domains
from utils import get_nickname_from_actor
from fitnessFunctions import fitness_performance
from webapp_utils import html_hashtag_blocked
from webapp_search import html_hashtag_search
from webapp_search import hashtag_search_rss
from webapp_search import hashtag_search_json
from webapp_hashtagswarm import get_hashtag_categories_feed


def hashtag_search_rss2(self, calling_domain: str,
                        path: str, cookie: str,
                        base_dir: str, http_prefix: str,
                        domain: str, domain_full: str, port: int,
                        onion_domain: str, i2p_domain: str,
                        getreq_start_time,
                        system_language: str,
                        fitness: {}, debug: bool) -> None:
    """Return an RSS 2 feed for a hashtag
    """
    hashtag = path.split('/tags/rss2/')[1]
    if is_blocked_hashtag(base_dir, hashtag):
        http_400(self)
        return
    nickname = None
    if '/users/' in path:
        actor = \
            http_prefix + '://' + domain_full + path
        nickname = \
            get_nickname_from_actor(actor)
    hashtag_str = \
        hashtag_search_rss(nickname,
                           domain, port,
                           base_dir, hashtag,
                           http_prefix,
                           system_language)
    if hashtag_str:
        msg = hashtag_str.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/xml', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
    else:
        origin_path_str = path.split('/tags/rss2/')[0]
        origin_path_str_absolute = \
            http_prefix + '://' + domain_full + origin_path_str
        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str_absolute = \
                'http://' + onion_domain + origin_path_str
        elif (calling_domain.endswith('.i2p') and onion_domain):
            origin_path_str_absolute = \
                'http://' + i2p_domain + origin_path_str
        redirect_headers(self, origin_path_str_absolute + '/search',
                         cookie, calling_domain, 303)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_hashtag_search_rss2',
                        debug)


def hashtag_search_json2(self, calling_domain: str,
                         referer_domain: str,
                         path: str, cookie: str,
                         base_dir: str, http_prefix: str,
                         domain: str, domain_full: str, port: int,
                         onion_domain: str, i2p_domain: str,
                         getreq_start_time,
                         max_posts_in_feed: int,
                         fitness: {}, debug: bool) -> None:
    """Return a json collection for a hashtag
    """
    page_number = 1
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if page_number_str.isdigit():
            page_number = int(page_number_str)
        path = path.split('?page=')[0]
    hashtag = path.split('/tags/')[1]
    if is_blocked_hashtag(base_dir, hashtag):
        http_400(self)
        return
    nickname = None
    if '/users/' in path:
        actor = \
            http_prefix + '://' + domain_full + path
        nickname = \
            get_nickname_from_actor(actor)
    hashtag_json = \
        hashtag_search_json(nickname,
                            domain, port,
                            base_dir, hashtag,
                            page_number, max_posts_in_feed,
                            http_prefix)
    if hashtag_json:
        msg_str = json.dumps(hashtag_json)
        msg_str = convert_domains(calling_domain, referer_domain,
                                  msg_str, http_prefix, domain,
                                  onion_domain, i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'application/json', msglen,
                    None, calling_domain, True)
        write2(self, msg)
    else:
        origin_path_str = path.split('/tags/')[0]
        origin_path_str_absolute = \
            http_prefix + '://' + domain_full + origin_path_str
        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str_absolute = \
                'http://' + onion_domain + origin_path_str
        elif (calling_domain.endswith('.i2p') and onion_domain):
            origin_path_str_absolute = \
                'http://' + i2p_domain + origin_path_str
        redirect_headers(self, origin_path_str_absolute,
                         cookie, calling_domain, 303)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_hashtag_search_json',
                        debug)


def hashtag_search2(self, calling_domain: str,
                    path: str, cookie: str,
                    base_dir: str, http_prefix: str,
                    domain: str, domain_full: str, port: int,
                    onion_domain: str, i2p_domain: str,
                    getreq_start_time,
                    curr_session,
                    max_posts_in_hashtag_feed: int,
                    translate: {}, account_timezone: {},
                    bold_reading_nicknames: {},
                    fitness: {}, debug: bool,
                    recent_posts_cache: {},
                    max_recent_posts: int,
                    cached_webfingers: {},
                    person_cache: {},
                    project_version: str,
                    yt_replace_domain: str,
                    twitter_replacement_domain: str,
                    show_published_date_only: bool,
                    peertube_instances: [],
                    allow_local_network_access: bool,
                    theme_name: str,
                    system_language: str,
                    max_like_count: int,
                    signing_priv_key_pem: str,
                    cw_lists: {},
                    lists_enabled: {},
                    dogwhistles: {},
                    map_format: str,
                    access_keys: {},
                    min_images_for_accounts: [],
                    buy_sites: [],
                    auto_cw_cache: {},
                    ua_str: str,
                    mitm_servers: [],
                    instance_software: {}) -> None:
    """Return the result of a hashtag search
    """
    page_number = 1
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
    hashtag = path.split('/tags/')[1]
    if '?page=' in hashtag:
        hashtag = hashtag.split('?page=')[0]
    hashtag = urllib.parse.unquote_plus(hashtag)
    if is_blocked_hashtag(base_dir, hashtag):
        print('BLOCK: blocked hashtag #' + hashtag)
        msg = html_hashtag_blocked(base_dir,
                                   translate).encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        return
    nickname = None
    if '/users/' in path:
        nickname = path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if '?' in nickname:
            nickname = nickname.split('?')[0]
    timezone = None
    if account_timezone.get(nickname):
        timezone = account_timezone.get(nickname)
    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True
    hashtag_str = \
        html_hashtag_search(nickname, domain, port,
                            recent_posts_cache,
                            max_recent_posts,
                            translate,
                            base_dir, hashtag, page_number,
                            max_posts_in_hashtag_feed,
                            curr_session,
                            cached_webfingers,
                            person_cache,
                            http_prefix,
                            project_version,
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            theme_name,
                            system_language,
                            max_like_count,
                            signing_priv_key_pem,
                            cw_lists,
                            lists_enabled,
                            timezone, bold_reading,
                            dogwhistles,
                            map_format,
                            access_keys,
                            'search',
                            min_images_for_accounts,
                            buy_sites,
                            auto_cw_cache, ua_str,
                            mitm_servers,
                            instance_software)
    if hashtag_str:
        msg = hashtag_str.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
    else:
        origin_path_str = path.split('/tags/')[0]
        origin_path_str_absolute = \
            http_prefix + '://' + domain_full + origin_path_str
        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str_absolute = \
                'http://' + onion_domain + origin_path_str
        elif (calling_domain.endswith('.i2p') and onion_domain):
            origin_path_str_absolute = \
                'http://' + i2p_domain + origin_path_str
        redirect_headers(self, origin_path_str_absolute + '/search',
                         cookie, calling_domain, 303)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_hashtag_search', debug)


def get_hashtag_categories_feed2(self, calling_domain: str, path: str,
                                 base_dir: str, proxy_type: str,
                                 getreq_start_time,
                                 debug: bool,
                                 curr_session, fitness: {}) -> None:
    """Returns the hashtag categories feed
    """
    curr_session = \
        establish_session("get_hashtag_categories_feed",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 27)
        return

    hashtag_categories = None
    msg = \
        get_hashtag_categories_feed(base_dir, hashtag_categories)
    if msg:
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/xml', msglen,
                    None, calling_domain, True)
        write2(self, msg)
        if debug:
            print('Sent rss2 categories feed: ' +
                  path + ' ' + calling_domain)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_get_hashtag_categories_feed', debug)
        return
    if debug:
        print('Failed to get rss2 categories feed: ' +
              path + ' ' + calling_domain)
    http_404(self, 28)
