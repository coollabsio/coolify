__filename__ = "daemon.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon"

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer, HTTPServer
import sys
import time
import os
from socket import error as SocketError
import errno
from functools import partial
# for saving images
from metadata import metadata_custom_emoji
from person import update_memorial_flags
from person import clear_person_qrcodes
from person import create_shared_inbox
from person import create_news_inbox
from keys import get_instance_actor_key
from posts import expire_cache
from inbox import run_inbox_queue
from inbox import run_inbox_queue_watchdog
from follow import create_initial_last_seen
from threads import begin_thread
from threads import thread_with_trace
from threads import remove_dormant_threads
from cwlists import load_cw_lists
from blocking import run_federated_blocks_daemon
from blocking import load_federated_blocks_endpoints
from blocking import load_blocked_military
from blocking import load_blocked_government
from blocking import load_blocked_bluesky
from blocking import load_blocked_nostr
from blocking import update_blocked_cache
from blocking import set_broch_mode
from blocking import get_domain_blocklist
from webapp_utils import load_buy_sites
from webapp_accesskeys import load_access_keys_for_accounts
from webapp_media import load_peertube_instances
from shares import run_federated_shares_daemon
from shares import run_federated_shares_watchdog
from shares import create_shared_item_federation_token
from shares import generate_shared_item_federation_tokens
from shares import expire_shares
from categories import load_city_hashtags
from categories import update_hashtag_categories
from languages import load_default_post_languages
from utils import load_searchable_by_default
from utils import set_accounts_data_dir
from utils import data_dir
from utils import check_bad_path
from utils import acct_handle_dir
from utils import load_reverse_timeline
from utils import load_min_images_for_accounts
from utils import load_account_timezones
from utils import load_translations_from_file
from utils import load_bold_reading
from utils import load_hide_follows
from utils import load_hide_recent_posts
from utils import get_full_domain
from utils import set_config_param
from utils import get_config_param
from utils import load_json
from utils import load_mitm_servers
from utils import load_instance_software
from content import load_auto_cw_cache
from content import load_dogwhistles
from theme import scan_themes_for_scripts
from theme import is_news_theme_name
from theme import get_text_mode_banner
from theme import set_news_avatar
from schedule import run_post_schedule
from schedule import run_post_schedule_watchdog
from happening import dav_propfind_response
from happening import dav_put_response
from happening import dav_report_response
from happening import dav_delete_response
from newswire import load_hashtag_categories
from newsdaemon import run_newswire_watchdog
from newsdaemon import run_newswire_daemon
from fitnessFunctions import fitness_thread
from siteactive import load_unavailable_sites
from crawlers import load_known_web_bots
from qrcode import save_domain_qrcode
from importFollowing import run_import_following_watchdog
from relationships import update_moved_actors
from daemon_get import daemon_http_get
from daemon_post import daemon_http_post
from daemon_head import daemon_http_head
from httpcodes import http_200
from httpcodes import http_201
from httpcodes import http_207
from httpcodes import http_403
from httpcodes import http_404
from httpcodes import http_304
from httpcodes import http_400
from httpcodes import write2
from httpheaders import set_headers
from daemon_utils import load_known_epicyon_instances
from daemon_utils import has_accept
from daemon_utils import is_authorized
from poison import load_dictionary
from poison import load_2grams


class PubServer(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def handle_error(self, request, client_address):
        """HTTP server error handling
        """
        print('ERROR: http server error: ' + str(request) + ', ' +
              str(client_address))

    def do_GET(self):
        daemon_http_get(self)

    def _dav_handler(self, endpoint_type: str, debug: bool):
        calling_domain = self.server.domain_full
        if not has_accept(self, calling_domain):
            http_400(self)
            return
        accept_str = self.headers['Accept']
        if 'application/xml' not in accept_str:
            if debug:
                print(endpoint_type.upper() + ' is not of xml type')
            http_400(self)
            return
        if not self.headers.get('Content-length'):
            print(endpoint_type.upper() + ' has no content-length')
            http_400(self)
            return

        # check that the content length string is not too long
        if isinstance(self.headers['Content-length'], str):
            max_content_size = len(str(self.server.maxMessageLength))
            if len(self.headers['Content-length']) > max_content_size:
                http_400(self)
                return

        length = int(self.headers['Content-length'])
        if length > self.server.max_post_length:
            print(endpoint_type.upper() +
                  ' request size too large ' + self.path)
            http_400(self)
            return
        if not self.path.startswith('/calendars/'):
            print(endpoint_type.upper() + ' without /calendars ' + self.path)
            http_404(self, 145)
            return
        if debug:
            print(endpoint_type.upper() + ' checking authorization')
        if not is_authorized(self):
            print(endpoint_type.upper() + ' not authorized')
            http_403(self)
            return
        nickname = self.path.split('/calendars/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if not nickname:
            print(endpoint_type.upper() + ' no nickname ' + self.path)
            http_400(self)
            return
        dir_str = data_dir(self.server.base_dir)
        if not os.path.isdir(dir_str + '/' +
                             nickname + '@' + self.server.domain):
            print(endpoint_type.upper() +
                  ' for non-existent account ' + self.path)
            http_404(self, 146)
            return
        propfind_bytes = None
        try:
            propfind_bytes = self.rfile.read(length)
        except SocketError as ex:
            if ex.errno == errno.ECONNRESET:
                print('EX: ' + endpoint_type.upper() +
                      ' connection reset by peer')
            else:
                print('EX: ' + endpoint_type.upper() + ' socket error')
            http_400(self)
            return
        except ValueError as ex:
            print('EX: ' + endpoint_type.upper() +
                  ' rfile.read failed, ' + str(ex))
            http_400(self)
            return
        if not propfind_bytes:
            http_404(self, 147)
            return
        propfind_xml = propfind_bytes.decode('utf-8')
        response_str = None
        if endpoint_type == 'propfind':
            response_str = \
                dav_propfind_response(nickname, propfind_xml)
        elif endpoint_type == 'put':
            response_str = \
                dav_put_response(self.server.base_dir,
                                 nickname, self.server.domain,
                                 propfind_xml,
                                 self.server.http_prefix,
                                 self.server.system_language,
                                 self.server.recent_dav_etags)
        elif endpoint_type == 'report':
            curr_etag = None
            if self.headers.get('ETag'):
                curr_etag = self.headers['ETag']
            elif self.headers.get('Etag'):
                curr_etag = self.headers['Etag']
            response_str = \
                dav_report_response(self.server.base_dir,
                                    nickname, self.server.domain,
                                    propfind_xml,
                                    self.server.person_cache,
                                    self.server.http_prefix,
                                    curr_etag,
                                    self.server.recent_dav_etags,
                                    self.server.domain_full,
                                    self.server.system_language)
        elif endpoint_type == 'delete':
            response_str = \
                dav_delete_response(self.server.base_dir,
                                    nickname, self.server.domain,
                                    self.path,
                                    self.server.http_prefix,
                                    debug,
                                    self.server.recent_posts_cache)
        if not response_str:
            http_404(self, 148)
            return
        if response_str == 'Not modified':
            if endpoint_type == 'put':
                http_200(self)
                return
            http_304(self)
            return
        if response_str.startswith('ETag:') and endpoint_type == 'put':
            response_etag = response_str.split('ETag:', 1)[1]
            http_201(self, response_etag)
        elif response_str != 'Ok':
            message_xml = response_str.encode('utf-8')
            message_xml_len = len(message_xml)
            set_headers(self, 'application/xml; charset=utf-8',
                        message_xml_len,
                              None, calling_domain, False)
            write2(self, message_xml)
            if 'multistatus' in response_str:
                return http_207(self)
        http_200(self)

    def do_PROPFIND(self):
        if self.server.starting_daemon:
            return
        if check_bad_path(self.path):
            http_400(self)
            return

        self._dav_handler('propfind', self.server.debug)

    def do_PUT(self):
        if self.server.starting_daemon:
            return
        if check_bad_path(self.path):
            http_400(self)
            return

        self._dav_handler('put', self.server.debug)

    def do_REPORT(self):
        if self.server.starting_daemon:
            return
        if check_bad_path(self.path):
            http_400(self)
            return

        self._dav_handler('report', self.server.debug)

    def do_DELETE(self):
        if self.server.starting_daemon:
            return
        if check_bad_path(self.path):
            http_400(self)
            return

        self._dav_handler('delete', self.server.debug)

    def do_HEAD(self):
        daemon_http_head(self)

    def do_POST(self):
        daemon_http_post(self)


class PubServerUnitTest(PubServer):
    protocol_version = 'HTTP/1.0'


class EpicyonServer(ThreadingHTTPServer):
    starting_daemon = True
    hide_announces = {}
    no_of_books = 0
    max_api_blocks = 32000
    block_federated_endpoints = None
    block_federated = []
    books_cache = {}
    max_recent_books = 1000
    max_cached_readers = 24
    auto_cw_cache = {}
    sites_unavailable = None
    max_shares_on_profile = 0
    block_military = {}
    block_government = {}
    block_bluesky = {}
    block_nostr = {}
    followers_synchronization = False
    followers_sync_cache = {}
    buy_sites = None
    min_images_for_accounts = 0
    default_post_language = None
    css_cache = {}
    reverse_sequence = None
    clacks = None
    public_replies_unlisted = False
    dogwhistles = {}
    preferred_podcast_formats: list[str] = []
    bold_reading = {}
    hide_follows = {}
    hide_recent_posts = {}
    account_timezone = None
    post_to_nickname = None
    nodeinfo_is_active = False
    security_txt_is_active = False
    vcard_is_active = False
    masto_api_is_active = False
    map_format = None
    dyslexic_font = False
    content_license_url = ''
    dm_license_url = ''
    fitness = {}
    signing_priv_key_pem = None
    show_node_info_accounts = False
    show_node_info_version = False
    text_mode_banner = ''
    access_keys = {}
    rss_timeout_sec = 20
    check_actor_timeout = 2
    default_reply_interval_hrs = 9999999
    recent_dav_etags = {}
    key_shortcuts = {}
    low_bandwidth = False
    user_agents_blocked = None
    crawlers_allowed = None
    known_bots = None
    unit_test = False
    allow_local_network_access = False
    yt_replace_domain = ''
    twitter_replacement_domain = ''
    newswire = {}
    max_newswire_posts = 0
    verify_all_signatures = False
    blocklistUpdateCtr = 0
    blocklistUpdateInterval = 100
    domainBlocklist = None
    manual_follower_approval = True
    onion_domain = None
    i2p_domain = None
    media_instance = False
    blogs_instance = False
    translate = {}
    system_language = 'en'
    city = ''
    voting_time_mins = 30
    positive_voting = False
    newswire_votes_threshold = 1
    max_newswire_feed_size_kb = 1
    max_newswire_posts_per_source = 1
    show_published_date_only = False
    max_mirrored_articles = 0
    max_news_posts = 0
    maxTags = 32
    max_followers = 2000
    show_publish_as_icon = False
    full_width_tl_button_header = False
    icons_as_buttons = False
    rss_icon_at_top = True
    publish_button_at_top = False
    max_feed_item_size_kb = 100
    maxCategoriesFeedItemSizeKb = 1024
    dormant_months = 6
    max_like_count = 10
    followingItemsPerPage = 12
    registration = False
    enable_shared_inbox = True
    outboxThread = {}
    outbox_thread_index = {}
    new_post_thread = {}
    project_version = __version__
    secure_mode = True
    max_post_length = 0
    maxMediaSize = 0
    maxMessageLength = 64000
    maxPostsInBox = 32000
    maxCacheAgeDays = 30
    domain = ''
    port = 43
    domain_full = ''
    http_prefix = 'https'
    debug = False
    federation_list: list[str] = []
    shared_items_federated_domains: list[str] = []
    base_dir = ''
    instance_id = ''
    person_cache = {}
    cached_webfingers = {}
    favicons_cache = {}
    proxy_type = None
    session = None
    session_onion = None
    session_i2p = None
    last_getreq = 0
    last_postreq = 0
    getreq_busy = False
    postreq_busy = False
    received_message = False
    inbox_queue: list[dict] = []
    send_threads = None
    post_log = []
    max_queue_length = 64
    allow_deletion = True
    last_login_time = 0
    last_login_failure = 0
    login_failure_count = {}
    log_login_failures = True
    max_replies = 10
    tokens = {}
    tokens_lookup = {}
    instance_only_skills_search = True
    followers_threads = []
    blocked_cache = []
    blocked_cache_last_updated = 0
    blocked_cache_update_secs = 120
    blocked_cache_last_updated = 0
    custom_emoji = {}
    known_crawlers = {}
    last_known_crawler = 0
    lists_enabled = None
    cw_lists = {}
    theme_name = ''
    news_instance = False
    default_timeline = 'inbox'
    thrFitness = None
    recent_posts_cache = {}
    thrCache = None
    send_threads_timeout_mins = 3
    thrPostsQueue = None
    thrPostsWatchdog = None
    thrSharesExpire = None
    thrSharesExpireWatchdog = None
    max_recent_posts = 1
    iconsCache = {}
    fontsCache = {}
    shared_item_federation_tokens = None
    shared_item_federation_tokens = None
    peertube_instances = []
    max_mentions = 10
    max_emoji = 10
    max_hashtags = 10
    thrInboxQueue = None
    thrPostSchedule = None
    thrNewswireDaemon = None
    thrFederatedSharesDaemon = None
    restart_inbox_queue_in_progress = False
    restart_inbox_queue = False
    signing_priv_key_pem = ''
    thrCheckActor = {}
    thrImportFollowing = None
    thrWatchdog = None
    thrWatchdogSchedule = None
    thrNewswireWatchdog = None
    thrFederatedSharesWatchdog = None
    thrFederatedBlocksDaemon = None
    qrcode_scale = 6
    instance_description = ''
    instance_description_short = 'Epicyon'
    robots_txt = None
    last_llm_time = None
    mitm_servers = []
    watermark_width_percent = 0
    watermark_position = 0
    watermark_opacity = 0
    headers_catalog = {}
    dictionary = []
    twograms = {}
    searchable_by_default = {}
    known_epicyon_instances = []

    def handle_error(self, request, client_address):
        # surpress connection reset errors
        cls, e_ret = sys.exc_info()[:2]
        if cls is ConnectionResetError:
            if e_ret.errno != errno.ECONNRESET:
                print('ERROR: (EpicyonServer) ' + str(cls) + ", " + str(e_ret))
        elif cls is BrokenPipeError:
            pass
        else:
            print('ERROR: (EpicyonServer) ' + str(cls) + ", " + str(e_ret))
            return HTTPServer.handle_error(self, request, client_address)


def run_posts_queue(base_dir: str, send_threads: [], debug: bool,
                    timeout_mins: int) -> None:
    """Manages the threads used to send posts
    """
    while True:
        time.sleep(1)
        remove_dormant_threads(base_dir, send_threads, debug, timeout_mins)


def run_shares_expire(version_number: str, base_dir: str, httpd) -> None:
    """Expires shares as needed
    """
    while True:
        time.sleep(120)
        expire_shares(base_dir, httpd.max_shares_on_profile,
                      httpd.person_cache)


def run_posts_watchdog(project_version: str, httpd) -> None:
    """This tries to keep the posts thread running even if it dies
    """
    print('THREAD: Starting posts queue watchdog')
    posts_queue_original = httpd.thrPostsQueue.clone(run_posts_queue)
    begin_thread(httpd.thrPostsQueue, 'run_posts_watchdog')
    while True:
        time.sleep(20)
        if httpd.thrPostsQueue.is_alive():
            continue
        httpd.thrPostsQueue.kill()
        print('THREAD: restarting posts queue')
        httpd.thrPostsQueue = posts_queue_original.clone(run_posts_queue)
        begin_thread(httpd.thrPostsQueue, 'run_posts_watchdog 2')
        print('Restarting posts queue...')


def run_shares_expire_watchdog(project_version: str, httpd) -> None:
    """This tries to keep the shares expiry thread running even if it dies
    """
    print('THREAD: Starting shares expiry watchdog')
    shares_expire_original = httpd.thrSharesExpire.clone(run_shares_expire)
    begin_thread(httpd.thrSharesExpire, 'run_shares_expire_watchdog')
    while True:
        time.sleep(20)
        if httpd.thrSharesExpire.is_alive():
            continue
        httpd.thrSharesExpire.kill()
        print('THREAD: restarting shares watchdog')
        httpd.thrSharesExpire = shares_expire_original.clone(run_shares_expire)
        begin_thread(httpd.thrSharesExpire, 'run_shares_expire_watchdog 2')
        print('Restarting shares expiry...')


def load_tokens(base_dir: str, tokens_dict: {}, tokens_lookup: {}) -> None:
    """Loads shared items access tokens for each account
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if '@' in handle:
                token_filename = acct_handle_dir(base_dir, handle) + '/.token'
                if not os.path.isfile(token_filename):
                    continue
                nickname = handle.split('@')[0]
                token = None
                try:
                    with open(token_filename, 'r',
                              encoding='utf-8') as fp_tok:
                        token = fp_tok.read()
                except OSError as ex:
                    print('WARN: Unable to read token for ' +
                          nickname + ' ' + str(ex))
                if not token:
                    continue
                tokens_dict[nickname] = token
                tokens_lookup[token] = nickname
        break


def run_daemon(accounts_data_dir: str,
               no_of_books: int,
               public_replies_unlisted: int,
               max_shares_on_profile: int,
               max_hashtags: int,
               map_format: str,
               clacks: str,
               preferred_podcast_formats: [],
               check_actor_timeout: int,
               crawlers_allowed: [],
               dyslexic_font: bool,
               content_license_url: str,
               lists_enabled: str,
               default_reply_interval_hrs: int,
               low_bandwidth: bool,
               max_like_count: int,
               shared_items_federated_domains: [],
               user_agents_blocked: [],
               log_login_failures: bool,
               city: str,
               show_node_info_accounts: bool,
               show_node_info_version: bool,
               broch_mode: bool,
               verify_all_signatures: bool,
               send_threads_timeout_mins: int,
               dormant_months: int,
               max_newswire_posts: int,
               allow_local_network_access: bool,
               max_feed_item_size_kb: int,
               publish_button_at_top: bool,
               rss_icon_at_top: bool,
               icons_as_buttons: bool,
               full_width_tl_button_header: bool,
               show_publish_as_icon: bool,
               max_followers: int,
               max_news_posts: int,
               max_mirrored_articles: int,
               max_newswire_feed_size_kb: int,
               max_newswire_posts_per_source: int,
               show_published_date_only: bool,
               voting_time_mins: int,
               positive_voting: bool,
               newswire_votes_threshold: int,
               news_instance: bool,
               blogs_instance: bool,
               media_instance: bool,
               max_recent_posts: int,
               enable_shared_inbox: bool,
               registration: bool,
               language: str,
               project_version: str,
               instance_id: str,
               client_to_server: bool,
               base_dir: str,
               domain: str,
               onion_domain: str,
               i2p_domain: str,
               yt_replace_domain: str,
               twitter_replacement_domain: str,
               port: int,
               proxy_port: int,
               http_prefix: str,
               fed_list: [],
               max_mentions: int,
               max_emoji: int,
               secure_mode: bool,
               proxy_type: str,
               max_replies: int,
               domain_max_posts_per_day: int,
               account_max_posts_per_day: int,
               allow_deletion: bool,
               debug: bool,
               unit_test: bool,
               instance_only_skills_search: bool,
               send_threads: [],
               manual_follower_approval: bool,
               watermark_width_percent: int,
               watermark_position: str,
               watermark_opacity: int,
               bind_to_ip_address: str) -> None:
    if len(domain) == 0:
        domain = 'localhost'
    if '.' not in domain:
        if domain != 'localhost':
            print('Invalid domain: ' + domain)
            return

    update_moved_actors(base_dir, debug)

    if unit_test:
        server_address = (domain, proxy_port)
        pub_handler = partial(PubServerUnitTest)
    else:
        if not bind_to_ip_address:
            server_address = ('', proxy_port)
        else:
            server_address = (bind_to_ip_address, proxy_port)
        pub_handler = partial(PubServer)

    if accounts_data_dir:
        set_accounts_data_dir(base_dir, accounts_data_dir)

    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str):
        print('Creating accounts directory')
        os.mkdir(dir_str)

    httpd = None
    try:
        httpd = EpicyonServer(server_address, pub_handler)
    except SocketError as ex:
        if ex.errno == errno.ECONNREFUSED:
            print('EX: HTTP server address is already in use. ' +
                  str(server_address))
            return False

        print('EX: HTTP server failed to start. ' + str(ex))
        print('server_address: ' + str(server_address))
        return False

    if not httpd:
        print('Unable to start daemon')
        return False

    httpd.starting_daemon = True

    # the last time when an LLM scraper was replied to
    httpd.last_llm_time = None

    # servers with man-in-the-middle transport encryption
    httpd.mitm_servers = load_mitm_servers(base_dir)

    # for each domain name this stores the instance type
    # such as mastodon, epicyon, pixelfed, etc
    httpd.instance_software = load_instance_software(base_dir)

    # default "searchable by" for new posts for each account
    httpd.searchable_by_default = load_searchable_by_default(base_dir)

    # load the list of known Epicyon instances
    httpd.known_epicyon_instances = load_known_epicyon_instances(base_dir)

    # if a custom robots.txt exists then read it
    robots_txt_filename = data_dir(base_dir) + '/robots.txt'
    httpd.robots_txt = None
    if os.path.isfile(robots_txt_filename):
        new_robots_txt = ''
        try:
            with open(robots_txt_filename, 'r',
                      encoding='utf-8') as fp_robots:
                new_robots_txt = fp_robots.read()
        except OSError:
            print('EX: error reading 1 ' + robots_txt_filename)
        if new_robots_txt:
            httpd.robots_txt = new_robots_txt

    # width, position and opacity of watermark applied to attached images
    # as a percentage of the attached image width
    httpd.watermark_width_percent = watermark_width_percent
    httpd.watermark_position = watermark_position
    httpd.watermark_opacity = watermark_opacity

    # for each account whether to hide announces
    httpd.hide_announces = {}
    hide_announces_filename = data_dir(base_dir) + '/hide_announces.json'
    if os.path.isfile(hide_announces_filename):
        httpd.hide_announces = load_json(hide_announces_filename)

    # short description of the instance
    httpd.instance_description_short = \
        get_config_param(base_dir, 'instanceDescriptionShort')
    if httpd.instance_description_short is None:
        httpd.instance_description_short = 'Epicyon'

    # description of the instance
    httpd.instance_description = \
        get_config_param(base_dir, 'instanceDescription')
    if httpd.instance_description is None:
        httpd.instance_description = ''

    # number of book events which show on profile screens
    httpd.no_of_books = no_of_books

    # initialise federated blocklists
    httpd.max_api_blocks = 32000
    httpd.block_federated_endpoints = \
        load_federated_blocks_endpoints(base_dir)
    httpd.block_federated = []

    # cache storing recent book events
    httpd.books_cache = {}
    httpd.max_recent_books = 1000
    httpd.max_cached_readers = 24

    # cache for automatic content warnings
    httpd.auto_cw_cache = load_auto_cw_cache(base_dir)

    # loads a catalog of http header fields
    headers_catalog_fieldname = data_dir(base_dir) + '/headers_catalog.json'
    httpd.headers_catalog = {}
    if os.path.isfile(headers_catalog_fieldname):
        httpd.headers_catalog = load_json(headers_catalog_fieldname)

    # list of websites which are currently down
    httpd.sites_unavailable = load_unavailable_sites(base_dir)

    # maximum number of shared items attached to actors, as in
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/0837/fep-0837.md
    httpd.max_shares_on_profile = max_shares_on_profile

    # load a list of nicknames for accounts blocking military instances
    httpd.block_military = load_blocked_military(base_dir)

    # load a list of nicknames for accounts blocking government instances
    httpd.block_government = load_blocked_government(base_dir)

    # load a list of nicknames for accounts blocking bluesky bridges
    httpd.block_bluesky = load_blocked_bluesky(base_dir)

    # load a list of nicknames for accounts blocking nostr bridges
    httpd.block_nostr = load_blocked_nostr(base_dir)

    # scan the theme directory for any svg files containing scripts
    assert not scan_themes_for_scripts(base_dir)

    # lock for followers synchronization
    httpd.followers_synchronization = False

    # cache containing followers synchronization hashes and json
    httpd.followers_sync_cache = {}

    # permitted sites from which the buy button may be displayed
    httpd.buy_sites = load_buy_sites(base_dir)

    # which accounts should minimize all attached images by default
    httpd.min_images_for_accounts = load_min_images_for_accounts(base_dir)

    # default language for each account when creating a new post
    httpd.default_post_language = load_default_post_languages(base_dir)

    # caches css files
    httpd.css_cache = {}

    httpd.reverse_sequence = load_reverse_timeline(base_dir)

    httpd.clacks = get_config_param(base_dir, 'clacks')
    if not httpd.clacks:
        if clacks:
            httpd.clacks = clacks
        else:
            httpd.clacks = 'GNU Natalie Nguyen'

    httpd.public_replies_unlisted = public_replies_unlisted

    # load a list of dogwhistle words
    dogwhistles_filename = data_dir(base_dir) + '/dogwhistles.txt'
    if not os.path.isfile(dogwhistles_filename):
        dogwhistles_filename = base_dir + '/default_dogwhistles.txt'
    httpd.dogwhistles = load_dogwhistles(dogwhistles_filename)

    # list of preferred podcast formats
    # eg ['audio/opus', 'audio/mp3', 'audio/speex']
    httpd.preferred_podcast_formats = preferred_podcast_formats

    # for each account, whether bold reading is enabled
    httpd.bold_reading = load_bold_reading(base_dir)

    # whether to hide follows on profile screen for each account
    httpd.hide_follows = load_hide_follows(base_dir)

    # whether to hide recent public posts on profile screen for each account
    httpd.hide_recent_posts = load_hide_recent_posts(base_dir)

    httpd.account_timezone = load_account_timezones(base_dir)

    httpd.post_to_nickname = None

    httpd.nodeinfo_is_active = False
    httpd.security_txt_is_active = False
    httpd.vcard_is_active = False
    httpd.masto_api_is_active = False

    # use kml or gpx format for hashtag maps
    httpd.map_format = map_format.lower()

    httpd.dyslexic_font = dyslexic_font

    # license for content of the instance
    if not content_license_url:
        content_license_url = 'https://creativecommons.org/licenses/by-nc/4.0'
    httpd.content_license_url = content_license_url
    httpd.dm_license_url = ''

    # fitness metrics
    fitness_filename = data_dir(base_dir) + '/fitness.json'
    httpd.fitness = {}
    if os.path.isfile(fitness_filename):
        fitness = load_json(fitness_filename)
        if fitness is not None:
            httpd.fitness = fitness

    # initialize authorized fetch key
    httpd.signing_priv_key_pem = None

    httpd.show_node_info_accounts = show_node_info_accounts
    httpd.show_node_info_version = show_node_info_version

    # ASCII/ANSI text banner used in shell browsers, such as Lynx
    httpd.text_mode_banner = get_text_mode_banner(base_dir)

    # key shortcuts SHIFT + ALT + [key]
    httpd.access_keys = {
        'Page up': ',',
        'Page down': '.',
        'submitButton': 'y',
        'followButton': 'f',
        'moveButton': 'm',
        'blockButton': 'b',
        'infoButton': 'i',
        'snoozeButton': 's',
        'reportButton': '[',
        'viewButton': 'v',
        'unblockButton': 'u',
        'enterPetname': 'p',
        'enterNotes': 'n',
        'menuTimeline': 't',
        'menuEdit': 'e',
        'menuThemeDesigner': 'z',
        'menuProfile': 'p',
        'menuInbox': 'i',
        'menuSearch': '/',
        'menuNewPost': 'n',
        'menuNewBlog': '0',
        'menuCalendar': 'c',
        'menuDM': 'd',
        'menuReplies': 'r',
        'menuOutbox': 's',
        'menuBookmarks': 'q',
        'menuShares': 'h',
        'menuWanted': 'w',
        'menuReadingStatus': '=',
        'menuBlogs': 'b',
        'menuNewswire': '#',
        'menuLinks': 'l',
        'menuMedia': 'm',
        'menuModeration': 'o',
        'menuFollowing': 'f',
        'menuFollowers': 'g',
        'menuRoles': 'o',
        'menuSkills': 'a',
        'menuLogout': 'x',
        'menuKeys': 'k',
        'Public': 'p',
        'Reminder': 'r'
    }

    # timeout used when getting rss feeds
    httpd.rss_timeout_sec = 20

    # load dictionary used for LLM poisoning
    httpd.dictionary = load_dictionary(base_dir)
    httpd.twograms = load_2grams(base_dir)

    # timeout used when checking for actor changes when clicking an avatar
    # and entering person options screen
    if check_actor_timeout < 2:
        check_actor_timeout = 2
    httpd.check_actor_timeout = check_actor_timeout

    # how many hours after a post was published can a reply be made
    default_reply_interval_hrs = 9999999
    httpd.default_reply_interval_hrs = default_reply_interval_hrs

    # recent caldav etags for each account
    httpd.recent_dav_etags = {}

    httpd.key_shortcuts = {}
    load_access_keys_for_accounts(base_dir, httpd.key_shortcuts,
                                  httpd.access_keys)

    # wheither to use low bandwidth images
    httpd.low_bandwidth = low_bandwidth

    # list of blocked user agent types within the User-Agent header
    httpd.user_agents_blocked = user_agents_blocked

    # list of crawler bots permitted within the User-Agent header
    httpd.crawlers_allowed = crawlers_allowed

    # list of web crawlers known to the system
    httpd.known_bots = load_known_web_bots(base_dir)

    httpd.unit_test = unit_test
    httpd.allow_local_network_access = allow_local_network_access
    if unit_test:
        # unit tests are run on the local network with LAN addresses
        httpd.allow_local_network_access = True
    httpd.yt_replace_domain = yt_replace_domain
    httpd.twitter_replacement_domain = twitter_replacement_domain

    # newswire storing rss feeds
    httpd.newswire = {}

    # maximum number of posts to appear in the newswire on the right column
    httpd.max_newswire_posts = max_newswire_posts

    # whether to require that all incoming posts have valid jsonld signatures
    httpd.verify_all_signatures = verify_all_signatures

    # This counter is used to update the list of blocked domains in memory.
    # It helps to avoid touching the disk and so improves flooding resistance
    httpd.blocklistUpdateCtr = 0
    httpd.blocklistUpdateInterval = 100
    httpd.domainBlocklist = get_domain_blocklist(base_dir)

    httpd.manual_follower_approval = manual_follower_approval
    if domain.endswith('.onion'):
        onion_domain = domain
    elif domain.endswith('.i2p'):
        i2p_domain = domain
    httpd.onion_domain = onion_domain
    httpd.i2p_domain = i2p_domain
    httpd.media_instance = media_instance
    httpd.blogs_instance = blogs_instance

    # load translations dictionary
    httpd.translate = {}
    httpd.system_language = 'en'
    if not unit_test:
        httpd.translate, httpd.system_language = \
            load_translations_from_file(base_dir, language)
        if not httpd.system_language:
            print('ERROR: no system language loaded')
            sys.exit()
        print('System language: ' + httpd.system_language)
        if not httpd.translate:
            print('ERROR: no translations were loaded')
            sys.exit()

    # create hashtag categories for cities
    load_city_hashtags(base_dir, httpd.translate)

    # spoofed city for gps location misdirection
    httpd.city = city

    # For moderated newswire feeds this is the amount of time allowed
    # for voting after the post arrives
    httpd.voting_time_mins = voting_time_mins
    # on the newswire, whether moderators vote positively for items
    # or against them (veto)
    httpd.positive_voting = positive_voting
    # number of votes needed to remove a newswire item from the news timeline
    # or if positive voting is anabled to add the item to the news timeline
    httpd.newswire_votes_threshold = newswire_votes_threshold
    # maximum overall size of an rss/atom feed read by the newswire daemon
    # If the feed is too large then this is probably a DoS attempt
    httpd.max_newswire_feed_size_kb = max_newswire_feed_size_kb

    # For each newswire source (account or rss feed)
    # this is the maximum number of posts to show for each.
    # This avoids one or two sources from dominating the news,
    # and also prevents big feeds from slowing down page load times
    httpd.max_newswire_posts_per_source = max_newswire_posts_per_source

    # Show only the date at the bottom of posts, and not the time
    httpd.show_published_date_only = show_published_date_only

    # maximum number of news articles to mirror
    httpd.max_mirrored_articles = max_mirrored_articles

    # maximum number of posts in the news timeline/outbox
    httpd.max_news_posts = max_news_posts

    # The maximum number of tags per post which can be
    # attached to RSS feeds pulled in via the newswire
    httpd.maxTags = 32

    # maximum number of followers per account
    httpd.max_followers = max_followers

    # whether to show an icon for publish on the
    # newswire, or a 'Publish' button
    httpd.show_publish_as_icon = show_publish_as_icon

    # Whether to show the timeline header containing inbox, outbox
    # calendar, etc as the full width of the screen or not
    httpd.full_width_tl_button_header = full_width_tl_button_header

    # whether to show icons in the header (eg calendar) as buttons
    httpd.icons_as_buttons = icons_as_buttons

    # whether to show the RSS icon at the top or the bottom of the timeline
    httpd.rss_icon_at_top = rss_icon_at_top

    # Whether to show the newswire publish button at the top,
    # above the header image
    httpd.publish_button_at_top = publish_button_at_top

    # maximum size of individual RSS feed items, in K
    httpd.max_feed_item_size_kb = max_feed_item_size_kb

    # maximum size of a hashtag category, in K
    httpd.maxCategoriesFeedItemSizeKb = 1024

    # how many months does a followed account need to be unseen
    # for it to be considered dormant?
    httpd.dormant_months = dormant_months

    # maximum number of likes to display on a post
    httpd.max_like_count = max_like_count
    if httpd.max_like_count < 0:
        httpd.max_like_count = 0
    elif httpd.max_like_count > 16:
        httpd.max_like_count = 16

    httpd.followingItemsPerPage = 12
    if registration == 'open':
        httpd.registration = True
    else:
        httpd.registration = False
    httpd.enable_shared_inbox = enable_shared_inbox
    httpd.outboxThread = {}
    httpd.outbox_thread_index = {}
    httpd.new_post_thread = {}
    httpd.project_version = project_version
    httpd.secure_mode = secure_mode
    # max POST size of 30M
    httpd.max_post_length = 1024 * 1024 * 30
    httpd.maxMediaSize = httpd.max_post_length
    # Maximum text length is 64K - enough for a blog post
    httpd.maxMessageLength = 64000
    # Maximum overall number of posts per box
    httpd.maxPostsInBox = 32000
    httpd.maxCacheAgeDays = 30
    httpd.domain = domain
    httpd.port = port
    httpd.domain_full = get_full_domain(domain, port)
    httpd.qrcode_scale = 6
    if onion_domain:
        save_domain_qrcode(base_dir, 'http', onion_domain, httpd.qrcode_scale)
    elif i2p_domain:
        save_domain_qrcode(base_dir, 'http', i2p_domain, httpd.qrcode_scale)
    else:
        save_domain_qrcode(base_dir, http_prefix, httpd.domain_full,
                           httpd.qrcode_scale)
    clear_person_qrcodes(base_dir)
    httpd.http_prefix = http_prefix
    httpd.debug = debug
    httpd.federation_list = fed_list.copy()
    httpd.shared_items_federated_domains = \
        shared_items_federated_domains.copy()
    httpd.base_dir = base_dir
    httpd.instance_id = instance_id
    httpd.person_cache = {}
    httpd.cached_webfingers = {}
    httpd.favicons_cache = {}
    httpd.proxy_type = proxy_type
    httpd.session = None
    httpd.session_onion = None
    httpd.session_i2p = None
    httpd.last_getreq = 0
    httpd.last_postreq = 0
    httpd.getreq_busy = False
    httpd.postreq_busy = False
    httpd.received_message = False
    httpd.inbox_queue: list[dict] = []
    httpd.send_threads = send_threads
    httpd.post_log: list[str] = []
    httpd.max_queue_length = 64
    httpd.allow_deletion = allow_deletion
    httpd.last_login_time = 0
    httpd.last_login_failure = 0
    httpd.login_failure_count = {}
    httpd.log_login_failures = log_login_failures
    httpd.max_replies = max_replies
    httpd.tokens = {}
    httpd.tokens_lookup = {}
    load_tokens(base_dir, httpd.tokens, httpd.tokens_lookup)
    httpd.instance_only_skills_search = instance_only_skills_search
    # contains threads used to send posts to followers
    httpd.followers_threads = []

    # create a cache of blocked domains in memory.
    # This limits the amount of slow disk reads which need to be done
    httpd.blocked_cache: list[str] = []
    httpd.blocked_cache_last_updated = 0
    httpd.blocked_cache_update_secs = 120
    httpd.blocked_cache_last_updated = \
        update_blocked_cache(base_dir, httpd.blocked_cache,
                             httpd.blocked_cache_last_updated, 0)

    # get the list of custom emoji, for use by the mastodon api
    httpd.custom_emoji = \
        metadata_custom_emoji(base_dir, http_prefix, httpd.domain_full)

    # whether to enable broch mode, which locks down the instance
    set_broch_mode(base_dir, httpd.domain_full, broch_mode)

    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str + '/inbox@' + domain):
        print('Creating shared inbox: inbox@' + domain)
        create_shared_inbox(base_dir, 'inbox', domain, port, http_prefix)

    if not os.path.isdir(dir_str + '/news@' + domain):
        print('Creating news inbox: news@' + domain)
        create_news_inbox(base_dir, domain, port, http_prefix)
        set_config_param(base_dir, "listsEnabled", "Murdoch press")

    # dict of known web crawlers accessing nodeinfo or the masto API
    # and how many times they have been seen
    httpd.known_crawlers = {}
    known_crawlers_filename = dir_str + '/knownCrawlers.json'
    if os.path.isfile(known_crawlers_filename):
        httpd.known_crawlers = load_json(known_crawlers_filename)
    # when was the last crawler seen?
    httpd.last_known_crawler = 0

    if lists_enabled:
        httpd.lists_enabled = lists_enabled
    else:
        httpd.lists_enabled = get_config_param(base_dir, "listsEnabled")
    httpd.cw_lists = load_cw_lists(base_dir, True)

    # set the avatar for the news account
    httpd.theme_name = get_config_param(base_dir, 'theme')
    if not httpd.theme_name:
        httpd.theme_name = 'default'
    if is_news_theme_name(base_dir, httpd.theme_name):
        news_instance = True

    httpd.news_instance = news_instance
    httpd.default_timeline = 'inbox'
    if media_instance:
        httpd.default_timeline = 'tlmedia'
    if blogs_instance:
        httpd.default_timeline = 'tlblogs'
    if news_instance:
        httpd.default_timeline = 'tlfeatures'

    set_news_avatar(base_dir,
                    httpd.theme_name,
                    http_prefix,
                    domain,
                    httpd.domain_full)

    if not os.path.isdir(base_dir + '/cache'):
        os.mkdir(base_dir + '/cache')
    if not os.path.isdir(base_dir + '/cache/actors'):
        print('Creating actors cache')
        os.mkdir(base_dir + '/cache/actors')
    if not os.path.isdir(base_dir + '/cache/announce'):
        print('Creating announce cache')
        os.mkdir(base_dir + '/cache/announce')
    if not os.path.isdir(base_dir + '/cache/avatars'):
        print('Creating avatars cache')
        os.mkdir(base_dir + '/cache/avatars')

    archive_dir = base_dir + '/archive'
    if not os.path.isdir(archive_dir):
        print('Creating archive')
        os.mkdir(archive_dir)

    if not os.path.isdir(base_dir + '/sharefiles'):
        print('Creating shared item files directory')
        os.mkdir(base_dir + '/sharefiles')

    print('THREAD: Creating fitness thread')
    httpd.thrFitness = \
        thread_with_trace(target=fitness_thread,
                          args=(base_dir, httpd.fitness), daemon=True)
    begin_thread(httpd.thrFitness, 'run_daemon thrFitness')

    httpd.recent_posts_cache = {}

    print('THREAD: Creating cache expiry thread')
    httpd.thrCache = \
        thread_with_trace(target=expire_cache,
                          args=(base_dir, httpd.person_cache,
                                httpd.http_prefix,
                                archive_dir,
                                httpd.recent_posts_cache,
                                httpd.maxPostsInBox,
                                httpd.maxCacheAgeDays), daemon=True)
    begin_thread(httpd.thrCache, 'run_daemon thrCache')

    # number of mins after which sending posts or updates will expire
    httpd.send_threads_timeout_mins = send_threads_timeout_mins

    print('THREAD: Creating posts queue')
    httpd.thrPostsQueue = \
        thread_with_trace(target=run_posts_queue,
                          args=(base_dir, httpd.send_threads, debug,
                                httpd.send_threads_timeout_mins), daemon=True)
    if not unit_test:
        print('THREAD: run_posts_watchdog')
        httpd.thrPostsWatchdog = \
            thread_with_trace(target=run_posts_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrPostsWatchdog, 'run_daemon thrPostWatchdog')
    else:
        begin_thread(httpd.thrPostsQueue, 'run_daemon thrPostWatchdog 2')

    print('THREAD: Creating expire thread for shared items')
    httpd.thrSharesExpire = \
        thread_with_trace(target=run_shares_expire,
                          args=(project_version, base_dir,
                                httpd),
                          daemon=True)
    if not unit_test:
        print('THREAD: run_shares_expire_watchdog')
        httpd.thrSharesExpireWatchdog = \
            thread_with_trace(target=run_shares_expire_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrSharesExpireWatchdog,
                     'run_daemon thrSharesExpireWatchdog')
    else:
        begin_thread(httpd.thrSharesExpire,
                     'run_daemon thrSharesExpireWatchdog 2')

    httpd.max_recent_posts = max_recent_posts
    httpd.iconsCache = {}
    httpd.fontsCache = {}

    # create tokens used for shared item federation
    fed_domains = httpd.shared_items_federated_domains
    httpd.shared_item_federation_tokens = \
        generate_shared_item_federation_tokens(fed_domains,
                                               base_dir)
    si_federation_tokens = httpd.shared_item_federation_tokens
    httpd.shared_item_federation_tokens = \
        create_shared_item_federation_token(base_dir, httpd.domain_full, False,
                                            si_federation_tokens)

    # load peertube instances from file into a list
    httpd.peertube_instances: list[str] = []
    load_peertube_instances(base_dir, httpd.peertube_instances)

    create_initial_last_seen(base_dir, http_prefix)

    httpd.max_mentions = max_mentions
    httpd.max_emoji = max_emoji
    httpd.max_hashtags = max_hashtags

    print('THREAD: Creating inbox queue')
    httpd.thrInboxQueue = \
        thread_with_trace(target=run_inbox_queue,
                          args=(httpd, httpd.recent_posts_cache,
                                httpd.max_recent_posts,
                                project_version,
                                base_dir, http_prefix, httpd.send_threads,
                                httpd.post_log, httpd.cached_webfingers,
                                httpd.person_cache, httpd.inbox_queue,
                                domain, onion_domain, i2p_domain,
                                port, proxy_type,
                                httpd.federation_list,
                                max_replies,
                                domain_max_posts_per_day,
                                account_max_posts_per_day,
                                allow_deletion, debug,
                                max_mentions, max_emoji,
                                httpd.translate, unit_test,
                                httpd.yt_replace_domain,
                                httpd.twitter_replacement_domain,
                                httpd.show_published_date_only,
                                httpd.max_followers,
                                httpd.allow_local_network_access,
                                httpd.peertube_instances,
                                verify_all_signatures,
                                httpd.theme_name,
                                httpd.system_language,
                                httpd.max_like_count,
                                httpd.signing_priv_key_pem,
                                httpd.default_reply_interval_hrs,
                                httpd.cw_lists,
                                httpd.max_hashtags), daemon=True)

    print('THREAD: Creating scheduled post thread')
    httpd.thrPostSchedule = \
        thread_with_trace(target=run_post_schedule,
                          args=(base_dir, httpd, 20), daemon=True)

    print('THREAD: Creating newswire thread')
    httpd.thrNewswireDaemon = \
        thread_with_trace(target=run_newswire_daemon,
                          args=(base_dir, httpd,
                                http_prefix, domain, port,
                                httpd.translate), daemon=True)

    print('THREAD: Creating federated shares thread')
    httpd.thrFederatedSharesDaemon = \
        thread_with_trace(target=run_federated_shares_daemon,
                          args=(base_dir, httpd,
                                http_prefix, httpd.domain_full,
                                proxy_type, debug,
                                httpd.system_language,
                                httpd.mitm_servers), daemon=True)

    # flags used when restarting the inbox queue
    httpd.restart_inbox_queue_in_progress = False
    httpd.restart_inbox_queue = False

    update_hashtag_categories(base_dir)

    print('Adding hashtag categories for language ' + httpd.system_language)
    load_hashtag_categories(base_dir, httpd.system_language)

    # signing key used for authorized fetch
    # this is the instance actor private key
    httpd.signing_priv_key_pem = get_instance_actor_key(base_dir, domain)

    # threads used for checking for actor changes when clicking on
    # avatar icon / person options
    httpd.thrCheckActor = {}

    if not unit_test:
        print('THREAD: Creating import following watchdog')
        httpd.thrImportFollowing = \
            thread_with_trace(target=run_import_following_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrImportFollowing,
                     'run_daemon thrImportFollowing')

        print('THREAD: Creating inbox queue watchdog')
        httpd.thrWatchdog = \
            thread_with_trace(target=run_inbox_queue_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrWatchdog, 'run_daemon thrWatchdog')

        print('THREAD: Creating scheduled post watchdog')
        httpd.thrWatchdogSchedule = \
            thread_with_trace(target=run_post_schedule_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrWatchdogSchedule,
                     'run_daemon thrWatchdogSchedule')

        print('THREAD: Creating newswire watchdog')
        httpd.thrNewswireWatchdog = \
            thread_with_trace(target=run_newswire_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrNewswireWatchdog,
                     'run_daemon thrNewswireWatchdog')

        print('THREAD: Creating federated shares watchdog')
        httpd.thrFederatedSharesWatchdog = \
            thread_with_trace(target=run_federated_shares_watchdog,
                              args=(project_version, httpd), daemon=True)
        begin_thread(httpd.thrFederatedSharesWatchdog,
                     'run_daemon thrFederatedSharesWatchdog')
        print('THREAD: Creating federated blocks thread')
        httpd.thrFederatedBlocksDaemon = \
            thread_with_trace(target=run_federated_blocks_daemon,
                              args=(base_dir, httpd, debug), daemon=True)
        begin_thread(httpd.thrFederatedBlocksDaemon,
                     'run_daemon thrFederatedBlocksDaemon')
    else:
        print('Starting inbox queue')
        begin_thread(httpd.thrInboxQueue, 'run_daemon start inbox')
        print('Starting scheduled posts daemon')
        begin_thread(httpd.thrPostSchedule,
                     'run_daemon start scheduled posts')
        print('Starting federated shares daemon')
        begin_thread(httpd.thrFederatedSharesDaemon,
                     'run_daemon start federated shares')

    update_memorial_flags(base_dir, httpd.person_cache)

    if client_to_server:
        print('Running ActivityPub client on ' +
              domain + ' port ' + str(proxy_port))
    else:
        print('Running ActivityPub server on ' +
              domain + ' port ' + str(proxy_port))
    httpd.starting_daemon = False
    httpd.serve_forever()
