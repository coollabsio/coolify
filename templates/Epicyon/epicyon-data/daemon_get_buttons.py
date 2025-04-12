""" HTTP GET for buttons within the user interface """

__filename__ = "daemon_get_buttons.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

from manualapprove import manual_deny_follow_request_thread
from manualapprove import manual_approve_follow_request_thread
from fitnessFunctions import fitness_performance
from session import establish_session
from httpheaders import set_headers
from httpheaders import redirect_headers
from httpcodes import write2
from httpcodes import http_400
from httpcodes import http_404
from utils import get_full_domain
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from webapp_confirm import html_confirm_delete


def follow_approve_button(self, calling_domain: str, path: str,
                          cookie: str,
                          base_dir: str, http_prefix: str,
                          domain: str, domain_full: str, port: int,
                          onion_domain: str, i2p_domain: str,
                          getreq_start_time,
                          proxy_type: str, debug: bool,
                          curr_session,
                          federation_list: [],
                          send_threads: {},
                          post_log: {},
                          cached_webfingers: {},
                          person_cache: {},
                          project_version: str,
                          sites_unavailable: [],
                          system_language: str,
                          fitness: {},
                          signing_priv_key_pem: str,
                          followers_sync_cache: {},
                          session_onion, session_i2p,
                          session, mitm_servers: []) -> None:
    """Follow approve button was pressed
    """
    origin_path_str = path.split('/followapprove=')[0]
    follower_nickname = origin_path_str.replace('/users/', '')
    following_handle = path.split('/followapprove=')[1]
    if '://' in following_handle:
        handle_nickname = get_nickname_from_actor(following_handle)
        handle_domain, handle_port = \
            get_domain_from_actor(following_handle)
        if not handle_nickname or not handle_domain:
            http_404(self, 49)
            return
        following_handle = \
            handle_nickname + '@' + \
            get_full_domain(handle_domain, handle_port)
    if '@' in following_handle:
        if onion_domain:
            if following_handle.endswith('.onion'):
                curr_session = session_onion
                proxy_type = 'tor'
                port = 80
        if i2p_domain:
            if following_handle.endswith('.i2p'):
                curr_session = session_i2p
                proxy_type = 'i2p'
                port = 80

        curr_session = \
            establish_session("follow_approve_button",
                              curr_session, proxy_type,
                              self.server)
        if not curr_session:
            print('WARN: unable to establish session ' +
                  'when approving follow request')
            http_404(self, 50)
            return
        manual_approve_follow_request_thread(session,
                                             session_onion,
                                             session_i2p,
                                             onion_domain,
                                             i2p_domain,
                                             base_dir, http_prefix,
                                             follower_nickname,
                                             domain, port,
                                             following_handle,
                                             federation_list,
                                             send_threads,
                                             post_log,
                                             cached_webfingers,
                                             person_cache,
                                             debug,
                                             project_version,
                                             signing_priv_key_pem,
                                             proxy_type,
                                             followers_sync_cache,
                                             sites_unavailable,
                                             system_language,
                                             mitm_servers)
    origin_path_str_absolute = \
        http_prefix + '://' + domain_full + origin_path_str
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str_absolute = \
            'http://' + onion_domain + origin_path_str
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str_absolute = \
            'http://' + i2p_domain + origin_path_str
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_follow_approve_button',
                        debug)
    redirect_headers(self, origin_path_str_absolute,
                     cookie, calling_domain, 303)


def follow_deny_button(self, calling_domain: str, path: str,
                       cookie: str, base_dir: str, http_prefix: str,
                       domain: str, domain_full: str, port: int,
                       onion_domain: str, i2p_domain: str,
                       getreq_start_time, debug: bool,
                       federation_list: [],
                       send_threads: {},
                       post_log: {},
                       cached_webfingers: {},
                       person_cache: {},
                       project_version: str,
                       signing_priv_key_pem: str,
                       followers_sync_cache: {},
                       sites_unavailable: [],
                       system_language: str,
                       fitness: {},
                       session, session_onion, session_i2p,
                       mitm_servers: []) -> None:
    """Follow deny button was pressed
    """
    origin_path_str = path.split('/followdeny=')[0]
    follower_nickname = origin_path_str.replace('/users/', '')
    following_handle = path.split('/followdeny=')[1]
    if '://' in following_handle:
        handle_nickname = get_nickname_from_actor(following_handle)
        handle_domain, handle_port = \
            get_domain_from_actor(following_handle)
        if not handle_nickname or not handle_domain:
            http_404(self, 51)
            return
        following_handle = \
            handle_nickname + '@' + \
            get_full_domain(handle_domain, handle_port)
    if '@' in following_handle:
        manual_deny_follow_request_thread(session,
                                          session_onion,
                                          session_i2p,
                                          onion_domain,
                                          i2p_domain,
                                          base_dir, http_prefix,
                                          follower_nickname,
                                          domain, port,
                                          following_handle,
                                          federation_list,
                                          send_threads,
                                          post_log,
                                          cached_webfingers,
                                          person_cache,
                                          debug,
                                          project_version,
                                          signing_priv_key_pem,
                                          followers_sync_cache,
                                          sites_unavailable,
                                          system_language,
                                          mitm_servers)
    origin_path_str_absolute = \
        http_prefix + '://' + domain_full + origin_path_str
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str_absolute = \
            'http://' + onion_domain + origin_path_str
    elif calling_domain.endswith('.i2p') and i2p_domain:
        origin_path_str_absolute = \
            'http://' + i2p_domain + origin_path_str
    redirect_headers(self, origin_path_str_absolute,
                     cookie, calling_domain, 303)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_follow_deny_button',
                        debug)


def delete_button(self, calling_domain: str, path: str,
                  base_dir: str, http_prefix: str,
                  domain_full: str,
                  onion_domain: str, i2p_domain: str,
                  getreq_start_time,
                  proxy_type: str, cookie: str,
                  debug: str, curr_session,
                  recent_posts_cache: {},
                  max_recent_posts: int,
                  translate: {},
                  project_version: str,
                  cached_webfingers: {},
                  person_cache: {},
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
                  min_images_for_accounts: [],
                  buy_sites: [],
                  auto_cw_cache: {},
                  fitness: {},
                  allow_deletion: bool,
                  session_onion,
                  session_i2p,
                  default_timeline: str,
                  mitm_servers: [],
                  instance_software: {}) -> None:
    """Delete button is pressed on a post
    """
    if not cookie:
        print('ERROR: no cookie given when deleting ' + path)
        http_400(self)
        return
    page_number = 1
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if '?' in page_number_str:
            page_number_str = page_number_str.split('?')[0]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
    delete_url = path.split('?delete=')[1]
    if '?' in delete_url:
        delete_url = delete_url.split('?')[0]
    timeline_str = default_timeline
    if '?tl=' in path:
        timeline_str = path.split('?tl=')[1]
        if '?' in timeline_str:
            timeline_str = timeline_str.split('?')[0]
    users_path = path.split('?delete=')[0]
    actor = \
        http_prefix + '://' + domain_full + users_path
    if allow_deletion or delete_url.startswith(actor):
        if debug:
            print('DEBUG: delete_url=' + delete_url)
            print('DEBUG: actor=' + actor)
        if actor not in delete_url:
            # You can only delete your own posts
            if calling_domain.endswith('.onion') and onion_domain:
                actor = 'http://' + onion_domain + users_path
            elif calling_domain.endswith('.i2p') and i2p_domain:
                actor = 'http://' + i2p_domain + users_path
            redirect_headers(self, actor + '/' + timeline_str,
                             cookie, calling_domain, 303)
            return
        self.post_to_nickname = get_nickname_from_actor(actor)
        if not self.post_to_nickname:
            print('WARN: unable to find nickname in ' + actor)
            if calling_domain.endswith('.onion') and onion_domain:
                actor = 'http://' + onion_domain + users_path
            elif calling_domain.endswith('.i2p') and i2p_domain:
                actor = 'http://' + i2p_domain + users_path
            redirect_headers(self, actor + '/' + timeline_str,
                             cookie, calling_domain, 303)
            return

        if onion_domain:
            if '.onion/' in actor:
                curr_session = session_onion
                proxy_type = 'tor'
        if i2p_domain:
            if '.onion/' in actor:
                curr_session = session_i2p
                proxy_type = 'i2p'

        curr_session = \
            establish_session("delete_button",
                              curr_session, proxy_type,
                              self.server)
        if not curr_session:
            http_404(self, 58)
            return

        delete_str = \
            html_confirm_delete(self.server,
                                recent_posts_cache,
                                max_recent_posts,
                                translate, page_number,
                                curr_session, base_dir,
                                delete_url, http_prefix,
                                project_version,
                                cached_webfingers,
                                person_cache, calling_domain,
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
                                min_images_for_accounts,
                                buy_sites,
                                auto_cw_cache,
                                mitm_servers,
                                instance_software)
        if delete_str:
            delete_str_len = len(delete_str)
            set_headers(self, 'text/html', delete_str_len,
                        cookie, calling_domain, False)
            write2(self, delete_str.encode('utf-8'))
            self.server.getreq_busy = False
            return
    if calling_domain.endswith('.onion') and onion_domain:
        actor = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        actor = 'http://' + i2p_domain + users_path
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_delete_button',
                        debug)
    redirect_headers(self, actor + '/' + timeline_str,
                     cookie, calling_domain, 303)
