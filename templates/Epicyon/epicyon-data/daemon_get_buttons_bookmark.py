""" HTTP GET for bookmark buttons within the user interface """

__filename__ = "daemon_get_buttons_bookmark.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
from utils import get_cached_post_filename
from utils import load_json
from utils import locate_post
from utils import is_dm
from utils import get_nickname_from_actor
from utils import get_instance_url
from utils import local_actor_url
from utils import detect_mitm
from session import establish_session
from httpheaders import redirect_headers
from httpcodes import http_404
from bookmarks import bookmark_post
from bookmarks import undo_bookmark_post
from follow import follower_approval_active
from webapp_post import individual_post_as_html
from fitnessFunctions import fitness_performance


def bookmark_button(self, calling_domain: str, path: str,
                    base_dir: str, http_prefix: str,
                    domain: str, domain_full: str, port: int,
                    onion_domain: str, i2p_domain: str,
                    getreq_start_time,
                    proxy_type: str, cookie: str,
                    debug: str,
                    curr_session,
                    signing_priv_key_pem: str,
                    recent_posts_cache: {},
                    max_recent_posts: int,
                    translate: {},
                    cached_webfingers: {},
                    person_cache: {},
                    allow_deletion: bool,
                    project_version: str,
                    yt_replace_domain: str,
                    twitter_replacement_domain: str,
                    show_published_date_only: bool,
                    peertube_instances: [],
                    allow_local_network_access: bool,
                    theme_name: str,
                    system_language: str,
                    max_like_count: int,
                    cw_lists: {},
                    lists_enabled: {},
                    dogwhistles: {},
                    buy_sites: [],
                    auto_cw_cache: {},
                    fitness: {},
                    federation_list: [],
                    icons_cache: {},
                    account_timezone: {},
                    bold_reading_nicknames: {},
                    min_images_for_accounts: [],
                    session_onion,
                    session_i2p,
                    mitm_servers: [],
                    instance_software: {}) -> None:
    """Bookmark button was pressed
    """
    page_number = 1
    bookmark_url = path.split('?bookmark=')[1]
    if '?' in bookmark_url:
        bookmark_url = bookmark_url.split('?')[0]
    first_post_id = ''
    if '?firstpost=' in path:
        first_post_id = path.split('?firstpost=')[1]
        if '?' in first_post_id:
            first_post_id = first_post_id.split('?')[0]
        first_post_id = first_post_id.replace('/', '--')
        first_post_id = ';firstpost=' + first_post_id.replace('#', '--')
    timeline_bookmark = ''
    if '?bm=' in path:
        timeline_bookmark = path.split('?bm=')[1]
        if '?' in timeline_bookmark:
            timeline_bookmark = timeline_bookmark.split('?')[0]
        timeline_bookmark = '#' + timeline_bookmark
    actor = path.split('?bookmark=')[0]
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if ';' in page_number_str:
            page_number_str = page_number_str.split(';')[0]
        if '?' in page_number_str:
            page_number_str = page_number_str.split('?')[0]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
    timeline_str = 'inbox'
    if '?tl=' in path:
        timeline_str = path.split('?tl=')[1]
        if '?' in timeline_str:
            timeline_str = timeline_str.split('?')[0]

    self.post_to_nickname = get_nickname_from_actor(actor)
    if not self.post_to_nickname:
        print('WARN: unable to find nickname in ' + actor)
        actor_absolute = \
            get_instance_url(calling_domain,
                             http_prefix,
                             domain_full,
                             onion_domain,
                             i2p_domain) + \
            actor
        actor_path_str = \
            actor_absolute + '/' + timeline_str + \
            '?page=' + str(page_number)
        redirect_headers(self, actor_path_str, cookie,
                         calling_domain, 303)
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
        establish_session("bookmark_button",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 56)
        return
    bookmark_actor = \
        local_actor_url(http_prefix, self.post_to_nickname, domain_full)
    cc_list: list[str] = []
    bookmark_post(recent_posts_cache,
                  base_dir, federation_list,
                  self.post_to_nickname, domain, port,
                  cc_list, http_prefix, bookmark_url, bookmark_actor,
                  debug)
    # clear the icon from the cache so that it gets updated
    if icons_cache.get('bookmark.png'):
        del icons_cache['bookmark.png']
    bookmark_filename = \
        locate_post(base_dir, self.post_to_nickname, domain, bookmark_url)
    if bookmark_filename:
        print('Regenerating html post for changed bookmark')
        bookmark_post_json = load_json(bookmark_filename)
        if bookmark_post_json:
            cached_post_filename = \
                get_cached_post_filename(base_dir, self.post_to_nickname,
                                         domain, bookmark_post_json)
            print('Bookmarked post json: ' + str(bookmark_post_json))
            print('Bookmarked post nickname: ' +
                  self.post_to_nickname + ' ' + domain)
            print('Bookmarked post cache: ' + str(cached_post_filename))
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         self.post_to_nickname, domain)
            show_repeats = not is_dm(bookmark_post_json)
            timezone = None
            if account_timezone.get(self.post_to_nickname):
                timezone = account_timezone.get(self.post_to_nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    bookmark_filename.replace('.json', '') + '.mitm'
                if os.path.isfile(mitm_filename):
                    mitm = True
            bold_reading = False
            if bold_reading_nicknames.get(self.post_to_nickname):
                bold_reading = True
            minimize_all_images = False
            if self.post_to_nickname in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem,
                                    False,
                                    recent_posts_cache,
                                    max_recent_posts,
                                    translate,
                                    page_number, base_dir,
                                    curr_session,
                                    cached_webfingers,
                                    person_cache,
                                    self.post_to_nickname, domain,
                                    port, bookmark_post_json,
                                    None, True,
                                    allow_deletion,
                                    http_prefix,
                                    project_version,
                                    timeline_str,
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name,
                                    system_language,
                                    max_like_count,
                                    show_repeats,
                                    show_individual_post_icons,
                                    manually_approve_followers,
                                    False, True, False,
                                    cw_lists,
                                    lists_enabled,
                                    timezone, mitm, bold_reading,
                                    dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites,
                                    auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
        else:
            print('WARN: Bookmarked post not found: ' + bookmark_filename)
    actor_absolute = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        '/users/' + self.post_to_nickname

    actor_path_str = \
        actor_absolute + '/' + timeline_str + \
        '?page=' + str(page_number) + first_post_id + \
        timeline_bookmark
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_bookmark_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie,
                     calling_domain, 303)


def bookmark_button_undo(self, calling_domain: str, path: str,
                         base_dir: str, http_prefix: str,
                         domain: str, domain_full: str, port: int,
                         onion_domain: str, i2p_domain: str,
                         getreq_start_time,
                         proxy_type: str, cookie: str,
                         debug: str,
                         curr_session,
                         signing_priv_key_pem: str,
                         recent_posts_cache: {},
                         max_recent_posts: int,
                         translate: {},
                         cached_webfingers: {},
                         person_cache: {},
                         allow_deletion: bool,
                         project_version: str,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         show_published_date_only: bool,
                         peertube_instances: [],
                         allow_local_network_access: bool,
                         theme_name: str,
                         system_language: str,
                         max_like_count: int,
                         cw_lists: {},
                         lists_enabled: {},
                         dogwhistles: {},
                         buy_sites: [],
                         auto_cw_cache: {},
                         fitness: {},
                         federation_list: [],
                         icons_cache: {},
                         account_timezone: {},
                         bold_reading_nicknames: {},
                         min_images_for_accounts: [],
                         session_onion,
                         session_i2p,
                         mitm_servers: [],
                         instance_software: {}) -> None:
    """Button pressed to undo a bookmark
    """
    page_number = 1
    bookmark_url = path.split('?unbookmark=')[1]
    if '?' in bookmark_url:
        bookmark_url = bookmark_url.split('?')[0]
    first_post_id = ''
    if '?firstpost=' in path:
        first_post_id = path.split('?firstpost=')[1]
        if '?' in first_post_id:
            first_post_id = first_post_id.split('?')[0]
        first_post_id = first_post_id.replace('/', '--')
        first_post_id = ';firstpost=' + first_post_id.replace('#', '--')
    timeline_bookmark = ''
    if '?bm=' in path:
        timeline_bookmark = path.split('?bm=')[1]
        if '?' in timeline_bookmark:
            timeline_bookmark = timeline_bookmark.split('?')[0]
        timeline_bookmark = '#' + timeline_bookmark
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if ';' in page_number_str:
            page_number_str = page_number_str.split(';')[0]
        if '?' in page_number_str:
            page_number_str = page_number_str.split('?')[0]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
    timeline_str = 'inbox'
    if '?tl=' in path:
        timeline_str = path.split('?tl=')[1]
        if '?' in timeline_str:
            timeline_str = timeline_str.split('?')[0]
    actor = path.split('?unbookmark=')[0]
    self.post_to_nickname = get_nickname_from_actor(actor)
    if not self.post_to_nickname:
        print('WARN: unable to find nickname in ' + actor)
        actor_absolute = \
            get_instance_url(calling_domain,
                             http_prefix,
                             domain_full,
                             onion_domain,
                             i2p_domain) + \
            actor
        actor_path_str = \
            actor_absolute + '/' + timeline_str + \
            '?page=' + str(page_number)
        redirect_headers(self, actor_path_str, cookie,
                         calling_domain, 303)
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
        establish_session("bookmark_button_undo",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 57)
        return
    undo_actor = \
        local_actor_url(http_prefix, self.post_to_nickname, domain_full)
    cc_list: list[str] = []
    undo_bookmark_post(recent_posts_cache,
                       base_dir, federation_list,
                       self.post_to_nickname,
                       domain, port, cc_list, http_prefix,
                       bookmark_url, undo_actor, debug)
    # clear the icon from the cache so that it gets updated
    if icons_cache.get('bookmark_inactive.png'):
        del icons_cache['bookmark_inactive.png']
    bookmark_filename = \
        locate_post(base_dir, self.post_to_nickname, domain, bookmark_url)
    if bookmark_filename:
        print('Regenerating html post for changed unbookmark')
        bookmark_post_json = load_json(bookmark_filename)
        if bookmark_post_json:
            cached_post_filename = \
                get_cached_post_filename(base_dir, self.post_to_nickname,
                                         domain, bookmark_post_json)
            print('Unbookmarked post json: ' + str(bookmark_post_json))
            print('Unbookmarked post nickname: ' +
                  self.post_to_nickname + ' ' + domain)
            print('Unbookmarked post cache: ' + str(cached_post_filename))
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         self.post_to_nickname, domain)
            show_repeats = not is_dm(bookmark_post_json)
            timezone = None
            if account_timezone.get(self.post_to_nickname):
                timezone = account_timezone.get(self.post_to_nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    bookmark_filename.replace('.json', '') + '.mitm'
                if os.path.isfile(mitm_filename):
                    mitm = True
            bold_reading = False
            if bold_reading_nicknames.get(self.post_to_nickname):
                bold_reading = True
            minimize_all_images = False
            if self.post_to_nickname in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem,
                                    False,
                                    recent_posts_cache,
                                    max_recent_posts,
                                    translate,
                                    page_number, base_dir,
                                    curr_session,
                                    cached_webfingers,
                                    person_cache,
                                    self.post_to_nickname, domain,
                                    port, bookmark_post_json,
                                    None, True,
                                    allow_deletion,
                                    http_prefix,
                                    project_version,
                                    timeline_str,
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name,
                                    system_language,
                                    max_like_count,
                                    show_repeats,
                                    show_individual_post_icons,
                                    manually_approve_followers,
                                    False, True, False,
                                    cw_lists,
                                    lists_enabled,
                                    timezone, mitm, bold_reading,
                                    dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites,
                                    auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
        else:
            print('WARN: Unbookmarked post not found: ' +
                  bookmark_filename)
    actor_absolute = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        '/users/' + self.post_to_nickname

    actor_path_str = \
        actor_absolute + '/' + timeline_str + \
        '?page=' + str(page_number) + first_post_id + \
        timeline_bookmark
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_undo_bookmark_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie,
                     calling_domain, 303)
