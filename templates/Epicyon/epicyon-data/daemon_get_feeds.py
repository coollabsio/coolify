__filename__ = "daemon_get_feeds.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from follow import get_following_feed
from securemode import secure_mode
from city import get_spoofed_city
from httpheaders import set_headers
from httpcodes import http_404
from httpcodes import write2
from session import establish_session
from shares import get_shares_feed_for_person
from httprequests import request_http
from person import person_lookup
from webapp_profile import html_profile
from fitnessFunctions import fitness_performance
from utils import convert_domains
from utils import get_json_content_from_accept
from relationships import get_inactive_feed
from relationships import get_moved_feed


def show_shares_feed(self, authorized: bool,
                     calling_domain: str, referer_domain: str,
                     path: str, base_dir: str, http_prefix: str,
                     domain: str, port: int, getreq_start_time,
                     proxy_type: str, cookie: str,
                     debug: str, shares_file_type: str,
                     curr_session, shares_per_page: int,
                     access_keys: {}, key_shortcuts: {},
                     city: str,
                     shared_items_federated_domains: [],
                     account_timezone: {},
                     bold_reading_nicknames: {},
                     signing_priv_key_pem: str,
                     rss_icon_at_top: bool,
                     icons_as_buttons: bool,
                     default_timeline: str,
                     recent_posts_cache: {},
                     max_recent_posts: int,
                     translate: {},
                     project_version: str,
                     cached_webfingers: {},
                     person_cache: {},
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     show_published_date_only: bool,
                     newswire: {},
                     theme_name: str,
                     dormant_months: int,
                     peertube_instances: [],
                     allow_local_network_access: bool,
                     text_mode_banner: str,
                     system_language: str,
                     max_like_count: int,
                     cw_lists: {},
                     lists_enabled: {},
                     content_license_url: str,
                     buy_sites: [],
                     max_shares_on_profile: int,
                     sites_unavailable: [],
                     no_of_books: int,
                     auto_cw_cache: {},
                     fitness: {},
                     onion_domain: str,
                     i2p_domain: str,
                     mitm_servers: [],
                     hide_recent_posts: {}) -> bool:
    """Shows the shares feed for a particular account/actor
    """
    shares = \
        get_shares_feed_for_person(base_dir, domain, port, path,
                                   http_prefix, shares_file_type,
                                   shares_per_page)
    if shares:
        if request_http(self.headers, debug):
            page_number = 1
            if '?page=' not in path:
                search_path = path
                # get a page of shares, not the summary
                shares = \
                    get_shares_feed_for_person(base_dir, domain, port,
                                               path + '?page=true',
                                               http_prefix,
                                               shares_file_type,
                                               shares_per_page)
            else:
                page_number_str = path.split('?page=')[1]
                if ';' in page_number_str:
                    page_number_str = page_number_str.split(';')[0]
                if '#' in page_number_str:
                    page_number_str = page_number_str.split('#')[0]
                if len(page_number_str) > 5:
                    page_number_str = "1"
                if page_number_str.isdigit():
                    page_number = int(page_number_str)
                search_path = path.split('?page=')[0]
            search_path2 = search_path.replace('/' + shares_file_type, '')
            get_person = person_lookup(domain, search_path2, base_dir)
            if get_person:
                curr_session = \
                    establish_session("show_shares_feed",
                                      curr_session, proxy_type,
                                      self.server)
                if not curr_session:
                    http_404(self, 77)
                    self.server.getreq_busy = False
                    return True

                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if key_shortcuts.get(nickname):
                        access_keys = key_shortcuts[nickname]

                city = get_spoofed_city(city, base_dir, nickname, domain)

                timezone = None
                if account_timezone.get(nickname):
                    timezone = account_timezone.get(nickname)
                bold_reading = False
                if bold_reading_nicknames.get(nickname):
                    bold_reading = True
                known_epicyon_instances = \
                    self.server.known_epicyon_instances
                instance_software = \
                    self.server.instance_software
                msg = \
                    html_profile(signing_priv_key_pem,
                                 rss_icon_at_top,
                                 icons_as_buttons,
                                 default_timeline,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 project_version,
                                 base_dir, http_prefix,
                                 authorized,
                                 get_person, shares_file_type,
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 newswire,
                                 theme_name,
                                 dormant_months,
                                 peertube_instances,
                                 allow_local_network_access,
                                 text_mode_banner,
                                 debug,
                                 access_keys, city,
                                 system_language,
                                 max_like_count,
                                 shared_items_federated_domains,
                                 shares,
                                 page_number, shares_per_page,
                                 cw_lists,
                                 lists_enabled,
                                 content_license_url,
                                 timezone, bold_reading,
                                 buy_sites,
                                 None,
                                 max_shares_on_profile,
                                 sites_unavailable,
                                 no_of_books,
                                 auto_cw_cache,
                                 known_epicyon_instances,
                                 mitm_servers,
                                 instance_software,
                                 hide_recent_posts)
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET', '_show_shares_feed',
                                    debug)
                self.server.getreq_busy = False
                return True
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, self.path):
                msg_str = json.dumps(shares,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET', '_show_shares_feed json',
                                    debug)
            else:
                http_404(self, 78)
            return True
    return False


def show_following_feed(self, authorized: bool,
                        calling_domain: str, referer_domain: str,
                        path: str, base_dir: str, http_prefix: str,
                        domain: str, port: int, getreq_start_time,
                        proxy_type: str, cookie: str,
                        debug: str, curr_session,
                        follows_per_page: int,
                        access_keys: {}, key_shortcuts: {},
                        city: str, account_timezone: {},
                        content_license_url: str,
                        shared_items_federated_domains: [],
                        bold_reading_nicknames: {},
                        hide_follows: {},
                        max_shares_on_profile: int,
                        sites_unavailable: [],
                        signing_priv_key_pem: str,
                        rss_icon_at_top: bool,
                        icons_as_buttons: bool,
                        default_timeline: str,
                        recent_posts_cache: {},
                        max_recent_posts: int,
                        translate: {},
                        project_version: str,
                        cached_webfingers: {},
                        person_cache: {},
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        newswire: {},
                        theme_name: str,
                        dormant_months: int,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        text_mode_banner: str,
                        system_language: str,
                        max_like_count: int,
                        cw_lists: {},
                        lists_enabled: {},
                        buy_sites: [],
                        no_of_books: int,
                        auto_cw_cache: {},
                        fitness: {},
                        onion_domain: str,
                        i2p_domain: str,
                        mitm_servers: [],
                        hide_recent_posts: {}) -> bool:
    """Shows the following feed for a particular account/actor
    """
    following = \
        get_following_feed(base_dir, domain, port, path,
                           http_prefix, authorized, follows_per_page,
                           'following')
    if following:
        if request_http(self.headers, debug):
            page_number = 1
            if '?page=' not in path:
                search_path = path
                # get a page of following, not the summary
                following = \
                    get_following_feed(base_dir,
                                       domain,
                                       port,
                                       path + '?page=true',
                                       http_prefix,
                                       authorized, follows_per_page)
            else:
                page_number_str = path.split('?page=')[1]
                if ';' in page_number_str:
                    page_number_str = page_number_str.split(';')[0]
                if '#' in page_number_str:
                    page_number_str = page_number_str.split('#')[0]
                if len(page_number_str) > 5:
                    page_number_str = "1"
                if page_number_str.isdigit():
                    page_number = int(page_number_str)
                search_path = path.split('?page=')[0]
            get_person = \
                person_lookup(domain,
                              search_path.replace('/following', ''),
                              base_dir)
            if get_person:
                curr_session = \
                    establish_session("show_following_feed",
                                      curr_session, proxy_type,
                                      self.server)
                if not curr_session:
                    http_404(self, 79)
                    return True

                city = None
                timezone = None
                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if key_shortcuts.get(nickname):
                        access_keys = key_shortcuts[nickname]

                    city = get_spoofed_city(city, base_dir, nickname, domain)
                    if account_timezone.get(nickname):
                        timezone = account_timezone.get(nickname)

                bold_reading = False
                if bold_reading_nicknames.get(nickname):
                    bold_reading = True
                if not authorized and hide_follows.get(nickname):
                    following = {}
                known_epicyon_instances = \
                    self.server.known_epicyon_instances
                instance_software = \
                    self.server.instance_software

                msg = \
                    html_profile(signing_priv_key_pem,
                                 rss_icon_at_top,
                                 icons_as_buttons,
                                 default_timeline,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 project_version,
                                 base_dir, http_prefix,
                                 authorized,
                                 get_person, 'following',
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 newswire,
                                 theme_name,
                                 dormant_months,
                                 peertube_instances,
                                 allow_local_network_access,
                                 text_mode_banner,
                                 debug,
                                 access_keys, city,
                                 system_language,
                                 max_like_count,
                                 shared_items_federated_domains,
                                 following,
                                 page_number,
                                 follows_per_page,
                                 cw_lists,
                                 lists_enabled,
                                 content_license_url,
                                 timezone, bold_reading,
                                 buy_sites,
                                 None,
                                 max_shares_on_profile,
                                 sites_unavailable,
                                 no_of_books,
                                 auto_cw_cache,
                                 known_epicyon_instances,
                                 mitm_servers,
                                 instance_software,
                                 hide_recent_posts).encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html',
                            msglen, cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET', '_show_following_feed',
                                    debug)
                return True
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, self.path):
                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if nickname and not authorized and \
                       hide_follows.get(nickname):
                        following = {}

                msg_str = json.dumps(following,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET',
                                    '_show_following_feed json', debug)
            else:
                http_404(self, 80)
            return True
    return False


def show_moved_feed(self, authorized: bool,
                    calling_domain: str, referer_domain: str,
                    path: str, base_dir: str, http_prefix: str,
                    domain: str, port: int, getreq_start_time,
                    proxy_type: str, cookie: str,
                    debug: str, curr_session,
                    follows_per_page: int,
                    access_keys: {},
                    key_shortcuts: {}, city: str,
                    account_timezone: {},
                    content_license_url: str,
                    shared_items_federated_domains: [],
                    bold_reading_nicknames: {},
                    max_shares_on_profile: int,
                    sites_unavailable: [],
                    signing_priv_key_pem: str,
                    rss_icon_at_top: bool,
                    icons_as_buttons: bool,
                    default_timeline: str,
                    recent_posts_cache: {},
                    max_recent_posts: int,
                    translate: {},
                    project_version: str,
                    cached_webfingers: {},
                    person_cache: {},
                    yt_replace_domain: str,
                    twitter_replacement_domain: str,
                    show_published_date_only: bool,
                    newswire: {},
                    theme_name: str,
                    dormant_months: int,
                    peertube_instances: [],
                    allow_local_network_access: bool,
                    text_mode_banner: str,
                    system_language: str,
                    max_like_count: int,
                    cw_lists: {},
                    lists_enabled: {},
                    buy_sites: [],
                    no_of_books: int,
                    auto_cw_cache: {},
                    fitness: {},
                    onion_domain: str,
                    i2p_domain: str,
                    mitm_servers: [],
                    hide_recent_posts: {}) -> bool:
    """Shows the moved feed for a particular account/actor
    """
    following = \
        get_moved_feed(base_dir, domain, port, path,
                       http_prefix, authorized, follows_per_page)
    if following:
        if request_http(self.headers, debug):
            page_number = 1
            if '?page=' not in path:
                search_path = path
                # get a page of following, not the summary
                following = \
                    get_moved_feed(base_dir, domain, port, path,
                                   http_prefix, authorized,
                                   follows_per_page)
            else:
                page_number_str = path.split('?page=')[1]
                if ';' in page_number_str:
                    page_number_str = page_number_str.split(';')[0]
                if '#' in page_number_str:
                    page_number_str = page_number_str.split('#')[0]
                if len(page_number_str) > 5:
                    page_number_str = "1"
                if page_number_str.isdigit():
                    page_number = int(page_number_str)
                search_path = path.split('?page=')[0]
            get_person = \
                person_lookup(domain,
                              search_path.replace('/moved', ''),
                              base_dir)
            if get_person:
                curr_session = \
                    establish_session("show_moved_feed",
                                      curr_session, proxy_type,
                                      self.server)
                if not curr_session:
                    http_404(self, 81)
                    return True

                city = None
                timezone = None
                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if key_shortcuts.get(nickname):
                        access_keys = key_shortcuts[nickname]

                    city = get_spoofed_city(city, base_dir, nickname, domain)
                    if account_timezone.get(nickname):
                        timezone = account_timezone.get(nickname)

                bold_reading = False
                if bold_reading_nicknames.get(nickname):
                    bold_reading = True

                known_epicyon_instances = \
                    self.server.known_epicyon_instances
                instance_software = \
                    self.server.instance_software

                msg = \
                    html_profile(signing_priv_key_pem,
                                 rss_icon_at_top,
                                 icons_as_buttons,
                                 default_timeline,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 project_version,
                                 base_dir, http_prefix,
                                 authorized,
                                 get_person, 'moved',
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 newswire,
                                 theme_name,
                                 dormant_months,
                                 peertube_instances,
                                 allow_local_network_access,
                                 text_mode_banner,
                                 debug,
                                 access_keys, city,
                                 system_language,
                                 max_like_count,
                                 shared_items_federated_domains,
                                 following,
                                 page_number,
                                 follows_per_page,
                                 cw_lists,
                                 lists_enabled,
                                 content_license_url,
                                 timezone, bold_reading,
                                 buy_sites,
                                 None,
                                 max_shares_on_profile,
                                 sites_unavailable,
                                 no_of_books,
                                 auto_cw_cache,
                                 known_epicyon_instances,
                                 mitm_servers,
                                 instance_software,
                                 hide_recent_posts).encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html',
                            msglen, cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET', '_show_moved_feed',
                                    debug)
                return True
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, self.path):
                msg_str = json.dumps(following,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET', '_show_moved_feed json',
                                    debug)
            else:
                http_404(self, 81)
            return True
    return False


def show_inactive_feed(self, authorized: bool,
                       calling_domain: str, referer_domain: str,
                       path: str, base_dir: str, http_prefix: str,
                       domain: str, port: int, getreq_start_time,
                       proxy_type: str, cookie: str,
                       debug: str, curr_session,
                       dormant_months: int,
                       sites_unavailable: [],
                       follows_per_page: int,
                       access_keys: {},
                       key_shortcuts: {}, city: str,
                       account_timezone: {},
                       content_license_url: str,
                       shared_items_federated_domains: [],
                       bold_reading_nicknames: {},
                       max_shares_on_profile: int,
                       signing_priv_key_pem: str,
                       rss_icon_at_top: bool,
                       icons_as_buttons: bool,
                       default_timeline: str,
                       recent_posts_cache: {},
                       max_recent_posts: int,
                       translate: {},
                       project_version: str,
                       cached_webfingers: {},
                       person_cache: {},
                       yt_replace_domain: str,
                       twitter_replacement_domain: str,
                       show_published_date_only: bool,
                       newswire: {},
                       theme_name: str,
                       peertube_instances: [],
                       allow_local_network_access: bool,
                       text_mode_banner: str,
                       system_language: str,
                       max_like_count: int,
                       cw_lists: {},
                       lists_enabled: {},
                       buy_sites: [],
                       no_of_books: int,
                       auto_cw_cache: {},
                       fitness: {},
                       onion_domain: str,
                       i2p_domain: str,
                       mitm_servers: [],
                       hide_recent_posts: {}) -> bool:
    """Shows the inactive accounts feed for a particular account/actor
    """
    following = \
        get_inactive_feed(base_dir, domain, port, path,
                          http_prefix, authorized,
                          dormant_months,
                          follows_per_page, sites_unavailable)
    if following:
        if request_http(self.headers, debug):
            page_number = 1
            if '?page=' not in path:
                search_path = path
                # get a page of following, not the summary
                following = \
                    get_inactive_feed(base_dir, domain, port, path,
                                      http_prefix, authorized,
                                      dormant_months,
                                      follows_per_page,
                                      sites_unavailable)
            else:
                page_number_str = path.split('?page=')[1]
                if ';' in page_number_str:
                    page_number_str = page_number_str.split(';')[0]
                if '#' in page_number_str:
                    page_number_str = page_number_str.split('#')[0]
                if len(page_number_str) > 5:
                    page_number_str = "1"
                if page_number_str.isdigit():
                    page_number = int(page_number_str)
                search_path = path.split('?page=')[0]
            get_person = \
                person_lookup(domain,
                              search_path.replace('/inactive', ''),
                              base_dir)
            if get_person:
                curr_session = \
                    establish_session("show_inactive_feed",
                                      curr_session, proxy_type,
                                      self.server)
                if not curr_session:
                    http_404(self, 82)
                    return True

                city = None
                timezone = None
                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if key_shortcuts.get(nickname):
                        access_keys = key_shortcuts[nickname]

                    city = get_spoofed_city(city, base_dir, nickname, domain)
                    if account_timezone.get(nickname):
                        timezone = account_timezone.get(nickname)
                bold_reading = False
                if bold_reading_nicknames.get(nickname):
                    bold_reading = True
                known_epicyon_instances = \
                    self.server.known_epicyon_instances
                instance_software = \
                    self.server.instance_software
                msg = \
                    html_profile(signing_priv_key_pem,
                                 rss_icon_at_top,
                                 icons_as_buttons,
                                 default_timeline,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 project_version,
                                 base_dir, http_prefix,
                                 authorized,
                                 get_person, 'inactive',
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 newswire,
                                 theme_name,
                                 dormant_months,
                                 peertube_instances,
                                 allow_local_network_access,
                                 text_mode_banner,
                                 debug,
                                 access_keys, city,
                                 system_language,
                                 max_like_count,
                                 shared_items_federated_domains,
                                 following,
                                 page_number,
                                 follows_per_page,
                                 cw_lists,
                                 lists_enabled,
                                 content_license_url,
                                 timezone, bold_reading,
                                 buy_sites,
                                 None,
                                 max_shares_on_profile,
                                 sites_unavailable,
                                 no_of_books,
                                 auto_cw_cache,
                                 known_epicyon_instances,
                                 mitm_servers,
                                 instance_software,
                                 hide_recent_posts).encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html',
                            msglen, cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness,
                                    '_GET', '_show_inactive_feed',
                                    debug)
                return True
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, self.path):
                msg_str = json.dumps(following,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET',
                                    '_show_inactive_feed json', debug)
            else:
                http_404(self, 83)
            return True
    return False


def show_followers_feed(self, authorized: bool,
                        calling_domain: str, referer_domain: str,
                        path: str, base_dir: str, http_prefix: str,
                        domain: str, port: int, getreq_start_time,
                        proxy_type: str, cookie: str,
                        debug: str, curr_session,
                        follows_per_page: int,
                        access_keys: {},
                        key_shortcuts: {},
                        city: str, account_timezone: {},
                        content_license_url: str,
                        shared_items_federated_domains: [],
                        bold_reading_nicknames: {},
                        hide_follows: {},
                        max_shares_on_profile: int,
                        sites_unavailable: [],
                        signing_priv_key_pem: str,
                        rss_icon_at_top: bool,
                        icons_as_buttons: bool,
                        default_timeline: str,
                        recent_posts_cache: {},
                        max_recent_posts: int,
                        translate: {},
                        project_version: str,
                        cached_webfingers: {},
                        person_cache: {},
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        newswire: {},
                        theme_name: str,
                        dormant_months: int,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        text_mode_banner: str,
                        system_language: str,
                        max_like_count: int,
                        cw_lists: {},
                        lists_enabled: {},
                        buy_sites: [],
                        no_of_books: int,
                        auto_cw_cache: {},
                        fitness: {},
                        onion_domain: str,
                        i2p_domain: str,
                        mitm_servers: [],
                        hide_recent_posts: {}) -> bool:
    """Shows the followers feed for a particular account/actor
    """
    followers = \
        get_following_feed(base_dir, domain, port, path, http_prefix,
                           authorized, follows_per_page, 'followers')
    if followers:
        if request_http(self.headers, debug):
            page_number = 1
            if '?page=' not in path:
                search_path = path
                # get a page of followers, not the summary
                followers = \
                    get_following_feed(base_dir,
                                       domain,
                                       port,
                                       path + '?page=1',
                                       http_prefix,
                                       authorized, follows_per_page,
                                       'followers')
            else:
                page_number_str = path.split('?page=')[1]
                if ';' in page_number_str:
                    page_number_str = page_number_str.split(';')[0]
                if '#' in page_number_str:
                    page_number_str = page_number_str.split('#')[0]
                if len(page_number_str) > 5:
                    page_number_str = "1"
                if page_number_str.isdigit():
                    page_number = int(page_number_str)
                search_path = path.split('?page=')[0]
            get_person = \
                person_lookup(domain,
                              search_path.replace('/followers', ''),
                              base_dir)
            if get_person:
                curr_session = \
                    establish_session("show_followers_feed",
                                      curr_session, proxy_type,
                                      self.server)
                if not curr_session:
                    http_404(self, 84)
                    return True

                city = None
                timezone = None
                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if key_shortcuts.get(nickname):
                        access_keys = key_shortcuts[nickname]

                    city = get_spoofed_city(city, base_dir, nickname, domain)
                    if account_timezone.get(nickname):
                        timezone = account_timezone.get(nickname)
                bold_reading = False
                if bold_reading_nicknames.get(nickname):
                    bold_reading = True
                known_epicyon_instances = \
                    self.server.known_epicyon_instances
                instance_software = \
                    self.server.instance_software
                msg = \
                    html_profile(signing_priv_key_pem,
                                 rss_icon_at_top,
                                 icons_as_buttons,
                                 default_timeline,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 project_version,
                                 base_dir,
                                 http_prefix,
                                 authorized,
                                 get_person, 'followers',
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 newswire,
                                 theme_name,
                                 dormant_months,
                                 peertube_instances,
                                 allow_local_network_access,
                                 text_mode_banner,
                                 debug,
                                 access_keys, city,
                                 system_language,
                                 max_like_count,
                                 shared_items_federated_domains,
                                 followers,
                                 page_number,
                                 follows_per_page,
                                 cw_lists,
                                 lists_enabled,
                                 content_license_url,
                                 timezone, bold_reading,
                                 buy_sites,
                                 None,
                                 max_shares_on_profile,
                                 sites_unavailable,
                                 no_of_books,
                                 auto_cw_cache,
                                 known_epicyon_instances,
                                 mitm_servers,
                                 instance_software,
                                 hide_recent_posts).encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET', '_show_followers_feed',
                                    debug)
                return True
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, self.path):
                if '/users/' in path:
                    nickname = path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if nickname and not authorized and \
                       hide_follows.get(nickname):
                        followers = {}

                msg_str = json.dumps(followers,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time,
                                    fitness, '_GET',
                                    '_show_followers_feed json', debug)
            else:
                http_404(self, 85)
        return True
    return False
