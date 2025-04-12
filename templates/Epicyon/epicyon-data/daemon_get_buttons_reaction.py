""" HTTP GET for reaction buttons within the user interface """

__filename__ = "daemon_get_buttons_reaction.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
import urllib.parse
from utils import undo_reaction_collection_entry
from utils import get_cached_post_filename
from utils import load_json
from utils import locate_post
from utils import is_dm
from utils import local_actor_url
from utils import get_instance_url
from utils import get_nickname_from_actor
from utils import detect_mitm
from httpheaders import redirect_headers
from session import establish_session
from httpcodes import http_404
from posts import get_original_post_from_announce_url
from daemon_utils import post_to_outbox
from fitnessFunctions import fitness_performance
from reaction import update_reaction_collection
from follow import follower_approval_active
from webapp_post import individual_post_as_html


def reaction_button(self, calling_domain: str, path: str,
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
                    session_onion, session_i2p,
                    mitm_servers: [],
                    instance_software: {}) -> None:
    """Press an emoji reaction button
    Note that this is not the emoji reaction selection icon at the
    bottom of the post
    """
    page_number = 1
    reaction_url = path.split('?react=')[1]
    if '?' in reaction_url:
        reaction_url = reaction_url.split('?')[0]
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
    actor = path.split('?react=')[0]
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
    emoji_content_encoded = None
    if '?emojreact=' in path:
        emoji_content_encoded = path.split('?emojreact=')[1]
        if '?' in emoji_content_encoded:
            emoji_content_encoded = emoji_content_encoded.split('?')[0]
    if not emoji_content_encoded:
        print('WARN: no emoji reaction ' + actor)
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
    emoji_content = urllib.parse.unquote_plus(emoji_content_encoded)
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
        establish_session("reaction_button",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 54)
        return
    reaction_actor = \
        local_actor_url(http_prefix, self.post_to_nickname, domain_full)
    actor_reaction = path.split('?actor=')[1]
    if '?' in actor_reaction:
        actor_reaction = actor_reaction.split('?')[0]

    # if this is an announce then send the emoji reaction
    # to the original post
    orig_actor, orig_post_url, orig_filename = \
        get_original_post_from_announce_url(reaction_url, base_dir,
                                            self.post_to_nickname, domain)
    reaction_url2 = reaction_url
    reaction_post_filename = orig_filename
    if orig_actor and orig_post_url:
        actor_reaction = orig_actor
        reaction_url2 = orig_post_url
        reaction_post_filename = None

    reaction_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'EmojiReact',
        'actor': reaction_actor,
        'to': [actor_reaction],
        'object': reaction_url2,
        'content': emoji_content
    }

    # send out the emoji reaction to followers
    post_to_outbox(self, reaction_json, project_version, None,
                   curr_session, proxy_type)

    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_reaction_button postToOutbox',
                        debug)

    print('Locating emoji reaction post ' + reaction_url)
    # directly emoji reaction the post file
    if not reaction_post_filename:
        reaction_post_filename = \
            locate_post(base_dir, self.post_to_nickname, domain,
                        reaction_url)
    if reaction_post_filename:
        reaction_post_json = load_json(reaction_post_filename)
        if orig_filename and orig_post_url:
            update_reaction_collection(recent_posts_cache,
                                       base_dir, reaction_post_filename,
                                       reaction_url,
                                       reaction_actor,
                                       self.post_to_nickname,
                                       domain, debug, reaction_post_json,
                                       emoji_content)
            reaction_url = orig_post_url
            reaction_post_filename = orig_filename
        if debug:
            print('Updating emoji reaction for ' + reaction_post_filename)
        update_reaction_collection(recent_posts_cache,
                                   base_dir, reaction_post_filename,
                                   reaction_url,
                                   reaction_actor,
                                   self.post_to_nickname, domain,
                                   debug, None, emoji_content)
        if debug:
            print('Regenerating html post for changed ' +
                  'emoji reaction collection')
        # clear the icon from the cache so that it gets updated
        if reaction_post_json:
            cached_post_filename = \
                get_cached_post_filename(base_dir, self.post_to_nickname,
                                         domain, reaction_post_json)
            if debug:
                print('Reaction post json: ' + str(reaction_post_json))
                print('Reaction post nickname: ' +
                      self.post_to_nickname + ' ' + domain)
                print('Reaction post cache: ' + str(cached_post_filename))
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         self.post_to_nickname, domain)
            show_repeats = not is_dm(reaction_post_json)
            timezone = None
            if account_timezone.get(self.post_to_nickname):
                timezone = account_timezone.get(self.post_to_nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    reaction_post_filename.replace('.json', '') + '.mitm'
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
                                    port, reaction_post_json,
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
            print('WARN: Emoji reaction post not found: ' +
                  reaction_post_filename)
    else:
        print('WARN: unable to locate file for emoji reaction post ' +
              reaction_url)

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
                        '_GET', '_reaction_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie,
                     calling_domain, 303)


def reaction_button_undo(self, calling_domain: str, path: str,
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
                         session_onion,
                         session_i2p,
                         mitm_servers: [],
                         instance_software: {}) -> None:
    """A button is pressed to undo emoji reaction
    """
    page_number = 1
    reaction_url = path.split('?unreact=')[1]
    if '?' in reaction_url:
        reaction_url = reaction_url.split('?')[0]
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
    actor = path.split('?unreact=')[0]
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
    emoji_content_encoded = None
    if '?emojreact=' in path:
        emoji_content_encoded = path.split('?emojreact=')[1]
        if '?' in emoji_content_encoded:
            emoji_content_encoded = emoji_content_encoded.split('?')[0]
    if not emoji_content_encoded:
        print('WARN: no emoji reaction ' + actor)
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
    emoji_content = urllib.parse.unquote_plus(emoji_content_encoded)

    if onion_domain:
        if '.onion/' in actor:
            curr_session = session_onion
            proxy_type = 'tor'
    if i2p_domain:
        if '.onion/' in actor:
            curr_session = session_i2p
            proxy_type = 'i2p'

    curr_session = \
        establish_session("reaction_button_undo",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 55)
        return
    undo_actor = \
        local_actor_url(http_prefix, self.post_to_nickname, domain_full)
    actor_reaction = path.split('?actor=')[1]
    if '?' in actor_reaction:
        actor_reaction = actor_reaction.split('?')[0]

    # if this is an announce then send the emoji reaction
    # to the original post
    orig_actor, orig_post_url, orig_filename = \
        get_original_post_from_announce_url(reaction_url, base_dir,
                                            self.post_to_nickname, domain)
    reaction_url2 = reaction_url
    reaction_post_filename = orig_filename
    if orig_actor and orig_post_url:
        actor_reaction = orig_actor
        reaction_url2 = orig_post_url
        reaction_post_filename = None

    undo_reaction_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Undo',
        'actor': undo_actor,
        'to': [actor_reaction],
        'object': {
            'type': 'EmojiReact',
            'actor': undo_actor,
            'to': [actor_reaction],
            'object': reaction_url2
        }
    }

    # send out the undo emoji reaction to followers
    post_to_outbox(self, undo_reaction_json,
                   project_version, None,
                   curr_session, proxy_type)

    # directly undo the emoji reaction within the post file
    if not reaction_post_filename:
        reaction_post_filename = \
            locate_post(base_dir, self.post_to_nickname, domain,
                        reaction_url)
    if reaction_post_filename:
        reaction_post_json = load_json(reaction_post_filename)
        if orig_filename and orig_post_url:
            undo_reaction_collection_entry(recent_posts_cache,
                                           base_dir,
                                           reaction_post_filename,
                                           undo_actor, domain, debug,
                                           reaction_post_json,
                                           emoji_content)
            reaction_url = orig_post_url
            reaction_post_filename = orig_filename
        if debug:
            print('Removing emoji reaction for ' + reaction_post_filename)
        undo_reaction_collection_entry(recent_posts_cache,
                                       base_dir, reaction_post_filename,
                                       undo_actor, domain, debug,
                                       reaction_post_json, emoji_content)
        if debug:
            print('Regenerating html post for changed ' +
                  'emoji reaction collection')
        if reaction_post_json:
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir,
                                         self.post_to_nickname, domain)
            show_repeats = not is_dm(reaction_post_json)
            timezone = None
            if account_timezone.get(self.post_to_nickname):
                timezone = account_timezone.get(self.post_to_nickname)
            mitm = detect_mitm(self)
            if not mitm:
                mitm_filename = \
                    reaction_post_filename.replace('.json', '') + '.mitm'
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
                                    port, reaction_post_json,
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
            print('WARN: Unreaction post not found: ' +
                  reaction_post_filename)

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
                        '_GET', '_undo_reaction_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie, calling_domain, 303)
