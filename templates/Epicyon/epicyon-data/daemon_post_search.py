__filename__ = "daemon_post_search.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import errno
import urllib.parse
from socket import error as SocketError
from utils import get_instance_url
from httpcodes import write2
from httpheaders import login_headers
from httpheaders import redirect_headers
from utils import string_ends_with
from utils import has_users_path
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_full_domain
from utils import local_actor_url
from utils import remove_eol
from webapp_utils import get_avatar_image_url
from webapp_search import html_hashtag_search
from webapp_search import html_skills_search
from webapp_search import html_history_search
from webapp_search import html_search_emoji
from webapp_search import html_search_shared_items
from webapp_profile import html_profile_after_search
from follow import is_follower_of_person
from follow import is_following_actor
from session import establish_session
from daemon_utils import show_person_options


def _receive_search_redirect(self, calling_domain: str,
                             http_prefix: str,
                             domain_full: str,
                             onion_domain: str,
                             i2p_domain: str,
                             users_path: str,
                             default_timeline: str,
                             cookie: str) -> None:
    """Redirect to a different screen after search
    """
    actor_str = \
        get_instance_url(calling_domain, http_prefix,
                         domain_full, onion_domain, i2p_domain) + users_path
    redirect_headers(self, actor_str + '/' + default_timeline,
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False


def _receive_search_hashtag(self, actor_str: str,
                            account_timezone: {},
                            bold_reading_nicknames: {},
                            domain: str, port: int,
                            recent_posts_cache: {},
                            max_recent_posts: int,
                            translate: {},
                            base_dir: str,
                            search_str: str,
                            max_posts_in_hashtag_feed: int,
                            curr_session,
                            cached_webfingers: {},
                            person_cache: {},
                            http_prefix: str,
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
                            min_images_for_accounts: {},
                            buy_sites: [],
                            auto_cw_cache: {},
                            calling_domain: str,
                            ua_str: str,
                            mitm_servers: [],
                            instance_software: {}) -> bool:
    """Receive a search for a hashtag from the search screen
    """
    nickname = get_nickname_from_actor(actor_str)
    if not nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return True

    # hashtag search
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
                            base_dir,
                            search_str[1:], 1,
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
        login_headers(self, 'text/html',
                      msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def _receive_search_skills(self, search_str: str,
                           actor_str: str,
                           translate: {},
                           base_dir: str,
                           instance_only_skills_search: bool,
                           domain: str,
                           theme_name: str, access_keys: {},
                           calling_domain: str) -> bool:
    """Receive a search for a skill from the search screen
    """
    possible_endings = (
        ' skill'
    )
    for poss_ending in possible_endings:
        if search_str.endswith(poss_ending):
            search_str = search_str.replace(poss_ending, '')
            break
    # skill search
    search_str = search_str.replace('*', '').strip()
    nickname = get_nickname_from_actor(actor_str)
    skill_str = \
        html_skills_search(actor_str, translate,
                           base_dir, search_str,
                           instance_only_skills_search,
                           64, nickname, domain,
                           theme_name, access_keys)
    if skill_str:
        msg = skill_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html',
                      msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def _receive_search_my_posts(self, search_str: str,
                             actor_str: str,
                             account_timezone: {},
                             bold_reading_nicknames: {},
                             translate: {},
                             base_dir: str,
                             http_prefix: str,
                             domain: str,
                             max_posts_in_feed: int,
                             page_number: int,
                             project_version: str,
                             recent_posts_cache: {},
                             max_recent_posts: int,
                             curr_session,
                             cached_webfingers: {},
                             person_cache: {},
                             port: int,
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
                             access_keys: {},
                             min_images_for_accounts: {},
                             buy_sites: [],
                             auto_cw_cache: {},
                             calling_domain: str,
                             mitm_servers: [],
                             instance_software: {}) -> bool:
    """Receive a search for your own posts from the search screen
    """
    # your post history search
    possible_endings = (
        ' in my posts',
        ' in my history',
        ' in my outbox',
        ' in sent posts',
        ' in outgoing posts',
        ' in sent items',
        ' in history',
        ' in outbox',
        ' in outgoing',
        ' in sent',
        ' history'
    )
    for poss_ending in possible_endings:
        if search_str.endswith(poss_ending):
            search_str = search_str.replace(poss_ending, '')
            break
    nickname = get_nickname_from_actor(actor_str)
    if not nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return True
    search_str = search_str.replace("'", '', 1).strip()
    timezone = None
    if account_timezone.get(nickname):
        timezone = account_timezone.get(nickname)
    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True
    history_str = \
        html_history_search(translate,
                            base_dir,
                            http_prefix,
                            nickname,
                            domain,
                            search_str,
                            max_posts_in_feed,
                            page_number,
                            project_version,
                            recent_posts_cache,
                            max_recent_posts,
                            curr_session,
                            cached_webfingers,
                            person_cache,
                            port,
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            theme_name, 'outbox',
                            system_language,
                            max_like_count,
                            signing_priv_key_pem,
                            cw_lists,
                            lists_enabled,
                            timezone, bold_reading,
                            dogwhistles,
                            access_keys,
                            min_images_for_accounts,
                            buy_sites,
                            auto_cw_cache,
                            mitm_servers,
                            instance_software)
    if history_str:
        msg = history_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html',
                      msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def _receive_search_bookmarks(self, search_str: str,
                              actor_str: str,
                              account_timezone: {},
                              bold_reading_nicknames: {},
                              translate: {},
                              base_dir: str,
                              http_prefix: str,
                              domain: str,
                              max_posts_in_feed: int,
                              page_number: int,
                              project_version: str,
                              recent_posts_cache: {},
                              max_recent_posts: int,
                              curr_session,
                              cached_webfingers: {},
                              person_cache: {},
                              port: int,
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
                              access_keys: {},
                              min_images_for_accounts: {},
                              buy_sites: [],
                              auto_cw_cache: {},
                              calling_domain: str,
                              mitm_servers: [],
                              instance_software: {}) -> bool:
    """Receive a search for bookmarked posts from the search screen
    """
    # bookmark search
    possible_endings = (
        ' in my bookmarks'
        ' in my saved posts'
        ' in my saved items'
        ' in my saved'
        ' in my saves'
        ' in saved posts'
        ' in saved items'
        ' in saved'
        ' in saves'
        ' in bookmarks'
        ' bookmark'
    )
    for poss_ending in possible_endings:
        if search_str.endswith(poss_ending):
            search_str = search_str.replace(poss_ending, '')
            break
    nickname = get_nickname_from_actor(actor_str)
    if not nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return True
    search_str = search_str.replace('-', '', 1).strip()
    timezone = None
    if account_timezone.get(nickname):
        timezone = account_timezone.get(nickname)
    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True
    bookmarks_str = \
        html_history_search(translate,
                            base_dir,
                            http_prefix,
                            nickname,
                            domain,
                            search_str,
                            max_posts_in_feed,
                            page_number,
                            project_version,
                            recent_posts_cache,
                            max_recent_posts,
                            curr_session,
                            cached_webfingers,
                            person_cache,
                            port,
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            theme_name, 'bookmarks',
                            system_language,
                            max_like_count,
                            signing_priv_key_pem,
                            cw_lists,
                            lists_enabled,
                            timezone, bold_reading,
                            dogwhistles,
                            access_keys,
                            min_images_for_accounts,
                            buy_sites,
                            auto_cw_cache,
                            mitm_servers,
                            instance_software)
    if bookmarks_str:
        msg = bookmarks_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html',
                      msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def _receive_search_handle(self, search_str: str,
                           calling_domain: str, http_prefix: str,
                           domain_full: str, onion_domain: str,
                           i2p_domain: str, users_path: str,
                           cookie: str, path: str, base_dir: str,
                           domain: str, proxy_type: str,
                           person_cache: {}, signing_priv_key_pem: str,
                           getreq_start_time, debug: bool,
                           authorized: bool, key_shortcuts: {},
                           account_timezone: {},
                           bold_reading_nicknames: {},
                           recent_posts_cache: {},
                           max_recent_posts: int,
                           translate: {}, port: int,
                           cached_webfingers: {},
                           project_version: str,
                           yt_replace_domain: str,
                           twitter_replacement_domain: str,
                           show_published_date_only: bool,
                           default_timeline: str,
                           peertube_instances: [],
                           allow_local_network_access: bool,
                           theme_name: str,
                           system_language: str,
                           max_like_count: int,
                           cw_lists: {},
                           lists_enabled: {},
                           dogwhistles: {},
                           min_images_for_accounts: {},
                           buy_sites: [],
                           max_shares_on_profile: int,
                           no_of_books: int,
                           auto_cw_cache: {},
                           actor_str: str,
                           curr_session, access_keys: {},
                           mitm_servers: [],
                           ua_str: str,
                           instance_software: {}) -> bool:
    """Receive a search for a fediverse handle or url from the search screen
    """
    remote_only = False
    if search_str.endswith(';remote'):
        search_str = search_str.replace(';remote', '')
        remote_only = True
    if string_ends_with(search_str, (':', ';', '.')):
        actor_str = \
            get_instance_url(calling_domain, http_prefix,
                             domain_full, onion_domain,
                             i2p_domain) + users_path
        redirect_headers(self, actor_str + '/search',
                         cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return True
    # profile search
    nickname = get_nickname_from_actor(actor_str)
    if not nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return True
    profile_path_str = path.replace('/searchhandle', '')

    # are we already following or followed by the searched
    # for handle?
    search_nickname = get_nickname_from_actor(search_str)
    search_domain, search_port = \
        get_domain_from_actor(search_str)
    search_follower = \
        is_follower_of_person(base_dir, nickname, domain,
                              search_nickname, search_domain)
    search_following = \
        is_following_actor(base_dir, nickname, domain, search_str)
    if not remote_only and (search_follower or search_following):
        # get the actor
        if not has_users_path(search_str):
            if not search_nickname or not search_domain:
                self.send_response(400)
                self.end_headers()
                self.server.postreq_busy = False
                return True
            search_domain_full = \
                get_full_domain(search_domain, search_port)
            actor = \
                local_actor_url(http_prefix, search_nickname,
                                search_domain_full)
        else:
            actor = search_str

        # establish the session
        curr_proxy_type = proxy_type
        if '.onion/' in actor:
            curr_proxy_type = 'tor'
            curr_session = self.server.session_onion
        elif '.i2p/' in actor:
            curr_proxy_type = 'i2p'
            curr_session = self.server.session_i2p

        curr_session = \
            establish_session("handle search", curr_session,
                              curr_proxy_type, self.server)
        if not curr_session:
            self.server.postreq_busy = False
            return True

        # get the avatar url for the actor
        avatar_url = \
            get_avatar_image_url(curr_session,
                                 base_dir, http_prefix,
                                 actor, person_cache,
                                 None, True,
                                 signing_priv_key_pem,
                                 mitm_servers)
        profile_path_str += \
            '?options=' + actor + ';1;' + avatar_url

        show_person_options(self, calling_domain, profile_path_str,
                            base_dir, domain, domain_full,
                            getreq_start_time,
                            cookie, debug, authorized,
                            curr_session)
        return True

    if key_shortcuts.get(nickname):
        access_keys = key_shortcuts[nickname]

    timezone = None
    if account_timezone.get(nickname):
        timezone = account_timezone.get(nickname)

    profile_handle = remove_eol(search_str).strip()

    # establish the session
    curr_proxy_type = proxy_type
    if '.onion/' in profile_handle or \
       profile_handle.endswith('.onion'):
        curr_proxy_type = 'tor'
        curr_session = self.server.session_onion
    elif ('.i2p/' in profile_handle or
          profile_handle.endswith('.i2p')):
        curr_proxy_type = 'i2p'
        curr_session = self.server.session_i2p

    curr_session = \
        establish_session("handle search", curr_session,
                          curr_proxy_type, self.server)
    if not curr_session:
        self.server.postreq_busy = False
        return True

    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True

    profile_str = \
        html_profile_after_search(authorized,
                                  recent_posts_cache,
                                  max_recent_posts,
                                  translate,
                                  base_dir,
                                  profile_path_str,
                                  http_prefix,
                                  nickname,
                                  domain,
                                  port,
                                  profile_handle,
                                  curr_session,
                                  cached_webfingers,
                                  person_cache,
                                  debug,
                                  project_version,
                                  yt_replace_domain,
                                  twitter_replacement_domain,
                                  show_published_date_only,
                                  default_timeline,
                                  peertube_instances,
                                  allow_local_network_access,
                                  theme_name,
                                  access_keys,
                                  system_language,
                                  max_like_count,
                                  signing_priv_key_pem,
                                  cw_lists,
                                  lists_enabled,
                                  timezone,
                                  onion_domain,
                                  i2p_domain,
                                  bold_reading,
                                  dogwhistles,
                                  min_images_for_accounts,
                                  buy_sites,
                                  max_shares_on_profile,
                                  no_of_books,
                                  auto_cw_cache,
                                  mitm_servers,
                                  ua_str,
                                  instance_software)
    if profile_str:
        msg = profile_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html',
                      msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True

    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix, domain_full,
                         onion_domain, i2p_domain) + users_path
    redirect_headers(self, actor_str + '/search',
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False
    return True


def _receive_search_emoji(self, search_str: str,
                          actor_str: str, translate: {},
                          base_dir: str, domain: str,
                          theme_name: str, access_keys: {},
                          calling_domain: str) -> bool:
    """Receive a search for an emoji from the search screen
    """
    # eg. "cat emoji"
    if search_str.endswith(' emoji'):
        search_str = \
            search_str.replace(' emoji', '')
    # emoji search
    nickname = get_nickname_from_actor(actor_str)
    emoji_str = \
        html_search_emoji(translate,
                          base_dir, search_str,
                          nickname, domain,
                          theme_name, access_keys)
    if emoji_str:
        msg = emoji_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def _receive_search_wanted(self, search_str: str,
                           actor_str: str,
                           translate: {},
                           base_dir: str,
                           page_number: int,
                           max_posts_in_feed: int,
                           http_prefix: str,
                           domain_full: str,
                           calling_domain: str,
                           shared_items_federated_domains: [],
                           domain: str,
                           theme_name: str,
                           access_keys: {}) -> bool:
    """Receive a search for wanted items from the search screen
    """
    # wanted items search
    nickname = get_nickname_from_actor(actor_str)
    wanted_items_str = \
        html_search_shared_items(translate,
                                 base_dir,
                                 search_str[1:], page_number,
                                 max_posts_in_feed,
                                 http_prefix,
                                 domain_full,
                                 actor_str, calling_domain,
                                 shared_items_federated_domains,
                                 'wanted', nickname, domain,
                                 theme_name,
                                 access_keys)
    if wanted_items_str:
        msg = wanted_items_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def _receive_search_shared(self, search_str: str,
                           actor_str: str,
                           translate: {}, base_dir: str,
                           page_number: int,
                           max_posts_in_feed: int,
                           http_prefix: str, domain_full: str,
                           calling_domain: str,
                           shared_items_federated_domains: [],
                           domain: str,
                           theme_name: str, access_keys: {}) -> bool:
    """Receive a search for shared items from the search screen
    """
    # shared items search
    nickname = get_nickname_from_actor(actor_str)
    shared_items_str = \
        html_search_shared_items(translate, base_dir,
                                 search_str, page_number,
                                 max_posts_in_feed,
                                 http_prefix, domain_full,
                                 actor_str, calling_domain,
                                 shared_items_federated_domains,
                                 'shares', nickname, domain,
                                 theme_name, access_keys)
    if shared_items_str:
        msg = shared_items_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html',
                      msglen, calling_domain)
        write2(self, msg)
        self.server.postreq_busy = False
        return True
    return False


def receive_search_query(self, calling_domain: str, cookie: str,
                         authorized: bool, path: str,
                         base_dir: str, http_prefix: str,
                         domain: str, domain_full: str,
                         port: int, search_for_emoji: bool,
                         onion_domain: str, i2p_domain: str,
                         getreq_start_time, debug: bool,
                         curr_session, proxy_type: str,
                         max_posts_in_hashtag_feed: int,
                         max_posts_in_feed: int,
                         default_timeline: str,
                         account_timezone: {},
                         bold_reading_nicknames: {},
                         recent_posts_cache: {},
                         max_recent_posts: int,
                         translate: {},
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
                         instance_only_skills_search: bool,
                         key_shortcuts: {},
                         max_shares_on_profile: int,
                         no_of_books: int,
                         shared_items_federated_domains: [],
                         ua_str: str,
                         mitm_servers: [],
                         instance_software: {}) -> None:
    """Receive a search query
    """
    # get the page number
    page_number = 1
    if '/searchhandle?page=' in path:
        page_number_str = path.split('/searchhandle?page=')[1]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
        path = path.split('?page=')[0]

    users_path = path.replace('/searchhandle', '')
    actor_str = \
        get_instance_url(calling_domain, http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path
    length = int(self.headers['Content-length'])
    try:
        search_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST search_params connection was reset')
        else:
            print('EX: POST search_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST search_params rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    if 'submitBack=' in search_params:
        # go back on search screen
        redirect_headers(self, actor_str + '/' +
                         default_timeline, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return
    if 'searchtext=' not in search_params:
        _receive_search_redirect(self, calling_domain,
                                 http_prefix, domain_full,
                                 onion_domain, i2p_domain,
                                 users_path, default_timeline,
                                 cookie)
        return

    search_str = search_params.split('searchtext=')[1]
    if '&' in search_str:
        search_str = search_str.split('&')[0]
    search_str = \
        urllib.parse.unquote_plus(search_str.strip())
    search_str = search_str.strip()
    print('search_str: ' + search_str)
    if search_for_emoji:
        search_str = ':' + search_str + ':'

    my_posts_endings = (
        ' history', ' in sent', ' in outbox', ' in outgoing',
        ' in sent items', ' in sent posts', ' in outgoing posts',
        ' in my history', ' in my outbox', ' in my posts')
    bookmark_endings = (
        ' in my saved items', ' in my saved posts',
        ' in my bookmarks', ' in my saved', ' in my saves',
        ' in saved posts', ' in saved items', ' in bookmarks',
        ' in saved', ' in saves', ' bookmark')

    if search_str.startswith('#'):
        if _receive_search_hashtag(self, actor_str,
                                   account_timezone,
                                   bold_reading_nicknames,
                                   domain, port,
                                   recent_posts_cache,
                                   max_recent_posts,
                                   translate,
                                   base_dir,
                                   search_str,
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
                                   dogwhistles,
                                   map_format,
                                   access_keys,
                                   min_images_for_accounts,
                                   buy_sites,
                                   auto_cw_cache,
                                   calling_domain,
                                   ua_str,
                                   mitm_servers,
                                   instance_software):
            return
    elif (search_str.startswith('*') or
          search_str.endswith(' skill')):
        if _receive_search_skills(self, search_str,
                                  actor_str,
                                  translate,
                                  base_dir,
                                  instance_only_skills_search,
                                  domain,
                                  theme_name, access_keys,
                                  calling_domain):
            return
    elif (search_str.startswith("'") or
          string_ends_with(search_str, my_posts_endings)):
        if _receive_search_my_posts(self, search_str,
                                    actor_str,
                                    account_timezone,
                                    bold_reading_nicknames,
                                    translate,
                                    base_dir,
                                    http_prefix,
                                    domain,
                                    max_posts_in_feed,
                                    page_number,
                                    project_version,
                                    recent_posts_cache,
                                    max_recent_posts,
                                    curr_session,
                                    cached_webfingers,
                                    person_cache,
                                    port,
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
                                    dogwhistles,
                                    access_keys,
                                    min_images_for_accounts,
                                    buy_sites,
                                    auto_cw_cache,
                                    calling_domain,
                                    mitm_servers,
                                    instance_software):
            return
    elif (search_str.startswith('-') or
          string_ends_with(search_str, bookmark_endings)):
        if _receive_search_bookmarks(self, search_str,
                                     actor_str,
                                     account_timezone,
                                     bold_reading_nicknames,
                                     translate,
                                     base_dir,
                                     http_prefix,
                                     domain,
                                     max_posts_in_feed,
                                     page_number,
                                     project_version,
                                     recent_posts_cache,
                                     max_recent_posts,
                                     curr_session,
                                     cached_webfingers,
                                     person_cache,
                                     port,
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
                                     dogwhistles,
                                     access_keys,
                                     min_images_for_accounts,
                                     buy_sites,
                                     auto_cw_cache,
                                     calling_domain,
                                     mitm_servers,
                                     instance_software):
            return
    elif ('@' in search_str or
          ('://' in search_str and
           has_users_path(search_str))):
        if _receive_search_handle(self, search_str,
                                  calling_domain, http_prefix,
                                  domain_full, onion_domain,
                                  i2p_domain, users_path,
                                  cookie, path, base_dir,
                                  domain, proxy_type,
                                  person_cache, signing_priv_key_pem,
                                  getreq_start_time, debug,
                                  authorized, key_shortcuts,
                                  account_timezone,
                                  bold_reading_nicknames,
                                  recent_posts_cache,
                                  max_recent_posts,
                                  translate, port,
                                  cached_webfingers,
                                  project_version,
                                  yt_replace_domain,
                                  twitter_replacement_domain,
                                  show_published_date_only,
                                  default_timeline,
                                  peertube_instances,
                                  allow_local_network_access,
                                  theme_name,
                                  system_language,
                                  max_like_count,
                                  cw_lists,
                                  lists_enabled,
                                  dogwhistles,
                                  min_images_for_accounts,
                                  buy_sites,
                                  max_shares_on_profile,
                                  no_of_books,
                                  auto_cw_cache, actor_str,
                                  curr_session, access_keys,
                                  mitm_servers,
                                  ua_str,
                                  instance_software):
            return
    elif (search_str.startswith(':') or
          search_str.endswith(' emoji')):
        if _receive_search_emoji(self, search_str,
                                 actor_str, translate,
                                 base_dir, domain,
                                 theme_name, access_keys,
                                 calling_domain):
            return
    elif search_str.startswith('.'):
        if _receive_search_wanted(self, search_str,
                                  actor_str, translate,
                                  base_dir, page_number,
                                  max_posts_in_feed,
                                  http_prefix, domain_full,
                                  calling_domain,
                                  shared_items_federated_domains,
                                  domain, theme_name,
                                  access_keys):
            return
    else:
        if _receive_search_shared(self, search_str,
                                  actor_str,
                                  translate, base_dir,
                                  page_number,
                                  max_posts_in_feed,
                                  http_prefix, domain_full,
                                  calling_domain,
                                  shared_items_federated_domains,
                                  domain,
                                  theme_name, access_keys):
            return

    _receive_search_redirect(self, calling_domain,
                             http_prefix, domain_full,
                             onion_domain, i2p_domain,
                             users_path, default_timeline,
                             cookie)
