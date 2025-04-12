""" HTTP GET for mute buttons within the user interface """

__filename__ = "daemon_get_buttons_mute.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
from utils import is_dm
from utils import get_cached_post_filename
from utils import load_json
from utils import locate_post
from utils import get_nickname_from_actor
from utils import detect_mitm
from httpcodes import http_404
from httpheaders import redirect_headers
from blocking import unmute_post
from blocking import mute_post
from follow import follower_approval_active
from webapp_post import individual_post_as_html
from fitnessFunctions import fitness_performance


def mute_button(self, calling_domain: str, path: str,
                base_dir: str, http_prefix: str,
                domain: str, domain_full: str, port: int,
                onion_domain: str, i2p_domain: str,
                getreq_start_time, cookie: str,
                debug: str, curr_session,
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
                account_timezone: {},
                bold_reading_nicknames: {},
                min_images_for_accounts: [],
                default_timeline: str,
                mitm_servers: [],
                instance_software: {}) -> None:
    """Mute button is pressed
    """
    mute_url = path.split('?mute=')[1]
    if '?' in mute_url:
        mute_url = mute_url.split('?')[0]
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
    timeline_str = default_timeline
    if '?tl=' in path:
        timeline_str = path.split('?tl=')[1]
        if '?' in timeline_str:
            timeline_str = timeline_str.split('?')[0]
    page_number = 1
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
    actor = \
        http_prefix + '://' + domain_full + path.split('?mute=')[0]
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        http_404(self, 59)
        return
    mute_post(base_dir, nickname, domain, port,
              http_prefix, mute_url,
              recent_posts_cache, debug)
    mute_filename = \
        locate_post(base_dir, nickname, domain, mute_url)
    if mute_filename:
        print('mute_post: Regenerating html post for changed mute status')
        mute_post_json = load_json(mute_filename)
        if mute_post_json:
            cached_post_filename = \
                get_cached_post_filename(base_dir, nickname,
                                         domain, mute_post_json)
            print('mute_post: Muted post json: ' + str(mute_post_json))
            print('mute_post: Muted post nickname: ' +
                  nickname + ' ' + domain)
            print('mute_post: Muted post cache: ' +
                  str(cached_post_filename))
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         nickname, domain)
            show_repeats = not is_dm(mute_post_json)
            show_public_only = False
            store_to_cache = True
            use_cache_only = False
            allow_downloads = False
            show_avatar_options = True
            avatar_url = None
            timezone = None
            if account_timezone.get(nickname):
                timezone = account_timezone.get(nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    mute_filename.replace('.json', '') + '.mitm'
                if os.path.isfile(mitm_filename):
                    mitm = True
            bold_reading = False
            if bold_reading_nicknames.get(nickname):
                bold_reading = True
            minimize_all_images = False
            if nickname in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem,
                                    allow_downloads,
                                    recent_posts_cache,
                                    max_recent_posts,
                                    translate,
                                    page_number, base_dir,
                                    curr_session,
                                    cached_webfingers,
                                    person_cache,
                                    nickname, domain,
                                    port, mute_post_json,
                                    avatar_url, show_avatar_options,
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
                                    show_public_only, store_to_cache,
                                    use_cache_only,
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
            print('WARN: Muted post not found: ' + mute_filename)

    if calling_domain.endswith('.onion') and onion_domain:
        actor = \
            'http://' + onion_domain + \
            path.split('?mute=')[0]
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        actor = \
            'http://' + i2p_domain + \
            path.split('?mute=')[0]
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_mute_button', debug)

    page_number_str = str(page_number)
    redirect_str = \
        actor + '/' + timeline_str + '?page=' + page_number_str + \
        first_post_id + timeline_bookmark
    redirect_headers(self, redirect_str, cookie, calling_domain, 303)


def mute_button_undo(self, calling_domain: str, path: str,
                     base_dir: str, http_prefix: str,
                     domain: str, domain_full: str, port: int,
                     onion_domain: str, i2p_domain: str,
                     getreq_start_time, cookie: str,
                     debug: str, curr_session,
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
                     account_timezone: {},
                     bold_reading_nicknames: {},
                     min_images_for_accounts: [],
                     default_timeline: str,
                     mitm_servers: [],
                     instance_software: {}) -> None:
    """Undo mute button is pressed
    """
    mute_url = path.split('?unmute=')[1]
    if '?' in mute_url:
        mute_url = mute_url.split('?')[0]
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
    timeline_str = default_timeline
    if '?tl=' in path:
        timeline_str = path.split('?tl=')[1]
        if '?' in timeline_str:
            timeline_str = timeline_str.split('?')[0]
    page_number = 1
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
    actor = \
        http_prefix + '://' + domain_full + path.split('?unmute=')[0]
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        http_404(self, 60)
        return
    unmute_post(base_dir, nickname, domain, port,
                http_prefix, mute_url,
                recent_posts_cache, debug)
    mute_filename = \
        locate_post(base_dir, nickname, domain, mute_url)
    if mute_filename:
        print('unmute_post: ' +
              'Regenerating html post for changed unmute status')
        mute_post_json = load_json(mute_filename)
        if mute_post_json:
            cached_post_filename = \
                get_cached_post_filename(base_dir, nickname,
                                         domain, mute_post_json)
            print('unmute_post: Unmuted post json: ' + str(mute_post_json))
            print('unmute_post: Unmuted post nickname: ' +
                  nickname + ' ' + domain)
            print('unmute_post: Unmuted post cache: ' +
                  str(cached_post_filename))
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir, nickname, domain)
            show_repeats = not is_dm(mute_post_json)
            show_public_only = False
            store_to_cache = True
            use_cache_only = False
            allow_downloads = False
            show_avatar_options = True
            avatar_url = None
            timezone = None
            if account_timezone.get(nickname):
                timezone = account_timezone.get(nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    mute_filename.replace('.json', '') + '.mitm'
                if os.path.isfile(mitm_filename):
                    mitm = True
            bold_reading = False
            if bold_reading_nicknames.get(nickname):
                bold_reading = True
            minimize_all_images = False
            if nickname in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem,
                                    allow_downloads,
                                    recent_posts_cache,
                                    max_recent_posts,
                                    translate,
                                    page_number, base_dir,
                                    curr_session,
                                    cached_webfingers,
                                    person_cache,
                                    nickname, domain,
                                    port, mute_post_json,
                                    avatar_url, show_avatar_options,
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
                                    show_public_only, store_to_cache,
                                    use_cache_only,
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
            print('WARN: Unmuted post not found: ' + mute_filename)
    if calling_domain.endswith('.onion') and onion_domain:
        actor = \
            'http://' + onion_domain + path.split('?unmute=')[0]
    elif calling_domain.endswith('.i2p') and i2p_domain:
        actor = \
            'http://' + i2p_domain + path.split('?unmute=')[0]
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_undo_mute_button', debug)

    page_number_str = str(page_number)
    redirect_str = \
        actor + '/' + timeline_str + '?page=' + page_number_str + \
        first_post_id + timeline_bookmark
    redirect_headers(self, redirect_str, cookie, calling_domain, 303)
