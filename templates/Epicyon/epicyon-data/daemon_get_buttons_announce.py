""" HTTP GET for announce/repeat/boost buttons within the user interface """

__filename__ = "daemon_get_buttons_announce.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
from utils import delete_post
from utils import locate_post
from utils import is_dm
from utils import get_cached_post_filename
from utils import remove_id_ending
from utils import local_actor_url
from utils import get_nickname_from_actor
from utils import get_instance_url
from utils import detect_mitm
from httpheaders import redirect_headers
from session import establish_session
from httpcodes import http_404
from announce import create_announce
from posts import save_post_to_box
from daemon_utils import post_to_outbox
from fitnessFunctions import fitness_performance
from follow import follower_approval_active
from webapp_post import individual_post_as_html


def announce_button(self, calling_domain: str, path: str,
                    base_dir: str,
                    cookie: str, proxy_type: str,
                    http_prefix: str,
                    domain: str, domain_full: str, port: int,
                    onion_domain: str, i2p_domain: str,
                    getreq_start_time,
                    repeat_private: bool,
                    debug: bool,
                    curr_session, sites_unavailable: [],
                    federation_list: [],
                    send_threads: {},
                    post_log: {},
                    person_cache: {},
                    cached_webfingers: {},
                    project_version: str,
                    signing_priv_key_pem: str,
                    system_language: str,
                    recent_posts_cache: {},
                    max_recent_posts: int,
                    translate: {},
                    allow_deletion: bool,
                    yt_replace_domain: str,
                    twitter_replacement_domain: str,
                    show_published_date_only: bool,
                    peertube_instances: [],
                    allow_local_network_access: bool,
                    theme_name: str,
                    max_like_count: int,
                    cw_lists: {},
                    lists_enabled: {},
                    dogwhistles: {},
                    buy_sites: [],
                    auto_cw_cache: {},
                    fitness: {},
                    icons_cache: {},
                    account_timezone: {},
                    bold_reading_nicknames: {},
                    min_images_for_accounts: int,
                    session_onion, session_i2p,
                    mitm_servers: [],
                    instance_software: {}) -> None:
    """The announce/repeat button was pressed on a post
    """
    page_number = 1
    repeat_url = path.split('?repeat=')[1]
    if '?' in repeat_url:
        repeat_url = repeat_url.split('?')[0]
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
    actor = path.split('?repeat=')[0]
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
        establish_session("announce_button",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 47)
        return
    self.server.actorRepeat = path.split('?actor=')[1]
    announce_to_str = \
        local_actor_url(http_prefix, self.post_to_nickname,
                        domain_full) + \
        '/followers'
    if not repeat_private:
        announce_to_str = 'https://www.w3.org/ns/activitystreams#Public'
    announce_id = None
    announce_json = \
        create_announce(curr_session,
                        base_dir,
                        federation_list,
                        self.post_to_nickname,
                        domain, port,
                        announce_to_str,
                        None, http_prefix,
                        repeat_url, False, False,
                        send_threads,
                        post_log,
                        person_cache,
                        cached_webfingers,
                        debug,
                        project_version,
                        signing_priv_key_pem,
                        domain,
                        onion_domain,
                        i2p_domain, sites_unavailable,
                        system_language,
                        mitm_servers)
    announce_filename = None
    if announce_json:
        # save the announce straight to the outbox
        # This is because the subsequent send is within a separate thread
        # but the html still needs to be generated before this call ends
        announce_id = remove_id_ending(announce_json['id'])
        announce_filename = \
            save_post_to_box(base_dir, http_prefix, announce_id,
                             self.post_to_nickname, domain_full,
                             announce_json, 'outbox')

        # clear the icon from the cache so that it gets updated
        if icons_cache.get('repeat.png'):
            del icons_cache['repeat.png']

        # send out the announce within a separate thread
        post_to_outbox(self, announce_json,
                       project_version,
                       self.post_to_nickname,
                       curr_session, proxy_type)

        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_announce_button postToOutboxThread',
                            debug)

    # generate the html for the announce
    if announce_json and announce_filename:
        if debug:
            print('Generating html post for announce')
        cached_post_filename = \
            get_cached_post_filename(base_dir, self.post_to_nickname,
                                     domain, announce_json)
        if debug:
            print('Announced post json: ' + str(announce_json))
            print('Announced post nickname: ' +
                  self.post_to_nickname + ' ' + domain)
            print('Announced post cache: ' + str(cached_post_filename))
        show_individual_post_icons = True
        manually_approve_followers = \
            follower_approval_active(base_dir,
                                     self.post_to_nickname, domain)
        show_repeats = not is_dm(announce_json)
        timezone = None
        if account_timezone.get(self.post_to_nickname):
            timezone = account_timezone.get(self.post_to_nickname)
        mitm = detect_mitm(self)
        if not mitm:
            mitm_filename = \
                announce_filename.replace('.json', '') + '.mitm'
            if os.path.isfile(mitm_filename):
                mitm = True
        bold_reading = False
        if bold_reading_nicknames.get(self.post_to_nickname):
            bold_reading = True
        minimize_all_images = False
        if self.post_to_nickname in min_images_for_accounts:
            minimize_all_images = True
        individual_post_as_html(signing_priv_key_pem, False,
                                recent_posts_cache,
                                max_recent_posts,
                                translate,
                                page_number, base_dir,
                                curr_session,
                                cached_webfingers,
                                person_cache,
                                self.post_to_nickname, domain,
                                port, announce_json,
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

    actor_absolute = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        '/users/' + self.post_to_nickname

    actor_path_str = \
        actor_absolute + '/' + timeline_str + '?page=' + \
        str(page_number) + first_post_id + timeline_bookmark
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_announce_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie, calling_domain, 303)


def announce_button_undo(self, calling_domain: str, path: str,
                         base_dir: str, cookie: str, proxy_type: str,
                         http_prefix: str, domain: str, domain_full: str,
                         onion_domain: str, i2p_domain: str,
                         getreq_start_time, debug: bool,
                         recent_posts_cache: {}, curr_session,
                         icons_cache: {},
                         project_version: str,
                         fitness: {},
                         session_onion, session_i2p) -> None:
    """Undo announce/repeat button was pressed
    """
    page_number = 1

    # the post which was referenced by the announce post
    repeat_url = path.split('?unrepeat=')[1]
    if '?' in repeat_url:
        repeat_url = repeat_url.split('?')[0]

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
    actor = path.split('?unrepeat=')[0]
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
            actor_absolute + '/' + timeline_str + '?page=' + \
            str(page_number)
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
        establish_session("announce_button_undo",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 48)
        return
    undo_announce_actor = \
        http_prefix + '://' + domain_full + \
        '/users/' + self.post_to_nickname
    un_repeat_to_str = 'https://www.w3.org/ns/activitystreams#Public'
    new_undo_announce = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'actor': undo_announce_actor,
        'type': 'Undo',
        'cc': [undo_announce_actor + '/followers'],
        'to': [un_repeat_to_str],
        'object': {
            'actor': undo_announce_actor,
            'cc': [undo_announce_actor + '/followers'],
            'object': repeat_url,
            'to': [un_repeat_to_str],
            'type': 'Announce'
        }
    }
    # clear the icon from the cache so that it gets updated
    if icons_cache.get('repeat_inactive.png'):
        del icons_cache['repeat_inactive.png']

    # delete the announce post
    if '?unannounce=' in path:
        announce_url = path.split('?unannounce=')[1]
        if '?' in announce_url:
            announce_url = announce_url.split('?')[0]
        post_filename = None
        nickname = get_nickname_from_actor(announce_url)
        if nickname:
            if domain_full + '/users/' + nickname + '/' in announce_url:
                post_filename = \
                    locate_post(base_dir, nickname, domain, announce_url)
        if post_filename:
            delete_post(base_dir, http_prefix,
                        nickname, domain, post_filename,
                        debug, recent_posts_cache, True)

    post_to_outbox(self, new_undo_announce,
                   project_version,
                   self.post_to_nickname,
                   curr_session, proxy_type)

    actor_absolute = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        '/users/' + self.post_to_nickname

    actor_path_str = \
        actor_absolute + '/' + timeline_str + '?page=' + \
        str(page_number) + first_post_id + timeline_bookmark
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_undo_announce_button',
                        debug)
    redirect_headers(self, actor_path_str, cookie, calling_domain, 303)
