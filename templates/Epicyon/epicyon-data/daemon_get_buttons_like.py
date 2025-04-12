""" HTTP GET for like buttons within the user interface """

__filename__ = "daemon_get_buttons_like.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
from utils import undo_likes_collection_entry
from utils import is_dm
from utils import get_cached_post_filename
from utils import load_json
from utils import locate_post
from utils import local_actor_url
from utils import get_nickname_from_actor
from utils import get_instance_url
from utils import detect_mitm
from daemon_utils import post_to_outbox
from follow import follower_approval_active
from httpheaders import redirect_headers
from session import establish_session
from httpcodes import http_404
from posts import get_original_post_from_announce_url
from fitnessFunctions import fitness_performance
from like import update_likes_collection
from webapp_post import individual_post_as_html


def like_button(self, calling_domain: str, path: str,
                base_dir: str, http_prefix: str,
                domain: str, domain_full: str,
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
                port: int,
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
                icons_cache: {},
                bold_reading_nicknames: {},
                min_images_for_accounts: [],
                session_onion,
                session_i2p,
                mitm_servers: [],
                instance_software: {}) -> None:
    """Press the like button
    """
    page_number = 1
    like_url = path.split('?like=')[1]
    if '?' in like_url:
        like_url = like_url.split('?')[0]
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
    actor = path.split('?like=')[0]
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
            '?page=' + str(page_number) + timeline_bookmark
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
        establish_session("like_button",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 52)
        return
    like_actor = \
        local_actor_url(http_prefix, self.post_to_nickname, domain_full)
    actor_liked = path.split('?actor=')[1]
    if '?' in actor_liked:
        actor_liked = actor_liked.split('?')[0]

    # if this is an announce then send the like to the original post
    orig_actor, orig_post_url, orig_filename = \
        get_original_post_from_announce_url(like_url, base_dir,
                                            self.post_to_nickname, domain)
    like_url2 = like_url
    liked_post_filename = orig_filename
    if orig_actor and orig_post_url:
        actor_liked = orig_actor
        like_url2 = orig_post_url
        liked_post_filename = None

    like_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Like',
        'actor': like_actor,
        'to': [actor_liked],
        'object': like_url2
    }

    # send out the like to followers
    post_to_outbox(self, like_json, project_version, None,
                   curr_session, proxy_type)

    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_like_button postToOutbox',
                        debug)

    print('Locating liked post ' + like_url)
    # directly like the post file
    if not liked_post_filename:
        liked_post_filename = \
            locate_post(base_dir, self.post_to_nickname, domain, like_url)
    if liked_post_filename:
        liked_post_json = load_json(liked_post_filename)
        if orig_filename and orig_post_url:
            update_likes_collection(recent_posts_cache,
                                    base_dir, liked_post_filename,
                                    like_url, like_actor,
                                    self.post_to_nickname,
                                    domain, debug, liked_post_json)
            like_url = orig_post_url
            liked_post_filename = orig_filename
        if debug:
            print('Updating likes for ' + liked_post_filename)
        update_likes_collection(recent_posts_cache,
                                base_dir, liked_post_filename, like_url,
                                like_actor, self.post_to_nickname, domain,
                                debug, None)
        if debug:
            print('Regenerating html post for changed likes collection')
        # clear the icon from the cache so that it gets updated
        if liked_post_json:
            cached_post_filename = \
                get_cached_post_filename(base_dir, self.post_to_nickname,
                                         domain, liked_post_json)
            if debug:
                print('Liked post json: ' + str(liked_post_json))
                print('Liked post nickname: ' +
                      self.post_to_nickname + ' ' + domain)
                print('Liked post cache: ' + str(cached_post_filename))
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         self.post_to_nickname, domain)
            show_repeats = not is_dm(liked_post_json)
            timezone = None
            if account_timezone.get(self.post_to_nickname):
                timezone = account_timezone.get(self.post_to_nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    liked_post_filename.replace('.json', '') + '.mitm'
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
                                    port, liked_post_json,
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
            print('WARN: Liked post not found: ' + liked_post_filename)
        # clear the icon from the cache so that it gets updated
        if icons_cache.get('like.png'):
            del icons_cache['like.png']
    else:
        print('WARN: unable to locate file for liked post ' +
              like_url)

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
                        '_GET', '_like_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie,
                     calling_domain, 303)


def like_button_undo(self, calling_domain: str, path: str,
                     base_dir: str, http_prefix: str,
                     domain: str, domain_full: str,
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
                     port: int,
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
                     icons_cache: {},
                     session_onion,
                     session_i2p,
                     mitm_servers: [],
                     instance_software: {}) -> None:
    """A button is pressed to undo
    """
    page_number = 1
    like_url = path.split('?unlike=')[1]
    if '?' in like_url:
        like_url = like_url.split('?')[0]
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
    actor = path.split('?unlike=')[0]
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
        establish_session("like_button_undo",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 53)
        return
    undo_actor = \
        local_actor_url(http_prefix, self.post_to_nickname, domain_full)
    actor_liked = path.split('?actor=')[1]
    if '?' in actor_liked:
        actor_liked = actor_liked.split('?')[0]

    # if this is an announce then send the like to the original post
    orig_actor, orig_post_url, orig_filename = \
        get_original_post_from_announce_url(like_url, base_dir,
                                            self.post_to_nickname, domain)
    like_url2 = like_url
    liked_post_filename = orig_filename
    if orig_actor and orig_post_url:
        actor_liked = orig_actor
        like_url2 = orig_post_url
        liked_post_filename = None

    undo_like_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Undo',
        'actor': undo_actor,
        'to': [actor_liked],
        'object': {
            'type': 'Like',
            'actor': undo_actor,
            'to': [actor_liked],
            'object': like_url2
        }
    }

    # send out the undo like to followers
    post_to_outbox(self, undo_like_json,
                   project_version, None,
                   curr_session, proxy_type)

    # directly undo the like within the post file
    if not liked_post_filename:
        liked_post_filename = locate_post(base_dir, self.post_to_nickname,
                                          domain, like_url)
    if liked_post_filename:
        liked_post_json = load_json(liked_post_filename)
        if orig_filename and orig_post_url:
            undo_likes_collection_entry(recent_posts_cache,
                                        base_dir, liked_post_filename,
                                        undo_actor,
                                        domain, debug,
                                        liked_post_json)
            like_url = orig_post_url
            liked_post_filename = orig_filename
        if debug:
            print('Removing likes for ' + liked_post_filename)
        undo_likes_collection_entry(recent_posts_cache,
                                    base_dir,
                                    liked_post_filename,
                                    undo_actor, domain, debug, None)
        if debug:
            print('Regenerating html post for changed likes collection')
        if liked_post_json:
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         self.post_to_nickname, domain)
            show_repeats = not is_dm(liked_post_json)
            timezone = None
            if account_timezone.get(self.post_to_nickname):
                timezone = account_timezone.get(self.post_to_nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    liked_post_filename.replace('.json', '') + '.mitm'
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
                                    port, liked_post_json,
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
            print('WARN: Unliked post not found: ' + liked_post_filename)
        # clear the icon from the cache so that it gets updated
        if icons_cache.get('like_inactive.png'):
            del icons_cache['like_inactive.png']
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
                        '_GET', '_undo_like_button', debug)
    redirect_headers(self, actor_path_str, cookie,
                     calling_domain, 303)
