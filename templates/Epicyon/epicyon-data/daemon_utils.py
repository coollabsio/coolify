__filename__ = "daemon_utils.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon"

import os
import time
from auth import authorize
from threads import thread_with_trace
from threads import begin_thread
from outbox import post_message_to_outbox
from city import get_spoofed_city
from httpcodes import http_404
from httpcodes import http_503
from httpcodes import http_400
from httpcodes import write2
from context import has_valid_context
from inbox import save_post_to_inbox_queue
from inbox import clear_queue_items
from blocking import update_blocked_cache
from blocking import is_blocked_nickname
from blocking import is_blocked_domain
from content import valid_url_lengths
from posts import add_to_field
from utils import detect_mitm
from utils import data_dir
from utils import load_json
from utils import save_json
from utils import get_instance_url
from utils import remove_html
from utils import get_locked_account
from utils import post_summary_contains_links
from utils import local_only_is_local
from utils import get_local_network_addresses
from utils import has_object_dict
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_actor_from_post
from utils import has_actor
from utils import resembles_url
from flags import is_system_account
from cache import check_for_changed_actor
from cache import get_person_from_cache
from website import get_website
from website import get_gemini_link
from donate import get_donation_url
from pronouns import get_pronouns
from discord import get_discord
from art import get_art_site_url
from music import get_music_site_url
from youtube import get_youtube
from pixelfed import get_pixelfed
from peertube import get_peertube
from xmpp import get_xmpp_address
from matrix import get_matrix_address
from ssb import get_ssb_address
from blog import get_blog_address
from tox import get_tox_address
from briar import get_briar_address
from cwtch import get_cwtch_address
from pgp import get_pgp_fingerprint
from pgp import get_email_address
from pgp import get_deltachat_invite
from pgp import get_pgp_pub_key
from enigma import get_enigma_pub_key
from git import get_repo_url
from webapp_person_options import html_person_options
from httpheaders import redirect_headers
from httpheaders import set_headers
from fitnessFunctions import fitness_performance


def post_to_outbox(self, message_json: {}, version: str,
                   post_to_nickname: str,
                   curr_session, proxy_type: str) -> bool:
    """post is received by the outbox
    Client to server message post
    https://www.w3.org/TR/activitypub/#client-to-server-outbox-delivery
    """
    if not curr_session:
        return False

    city = self.server.city

    if post_to_nickname:
        print('Posting to nickname ' + post_to_nickname)
        self.post_to_nickname = post_to_nickname
        city = get_spoofed_city(self.server.city,
                                self.server.base_dir,
                                post_to_nickname, self.server.domain)

    shared_items_federated_domains = \
        self.server.shared_items_federated_domains
    shared_item_federation_tokens = \
        self.server.shared_item_federation_tokens
    return post_message_to_outbox(curr_session,
                                  self.server.translate,
                                  message_json,
                                  self.post_to_nickname,
                                  self.server,
                                  self.server.base_dir,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.domain_full,
                                  self.server.onion_domain,
                                  self.server.i2p_domain,
                                  self.server.port,
                                  self.server.recent_posts_cache,
                                  self.server.followers_threads,
                                  self.server.federation_list,
                                  self.server.send_threads,
                                  self.server.post_log,
                                  self.server.cached_webfingers,
                                  self.server.person_cache,
                                  self.server.allow_deletion,
                                  proxy_type, version,
                                  self.server.debug,
                                  self.server.yt_replace_domain,
                                  self.server.twitter_replacement_domain,
                                  self.server.show_published_date_only,
                                  self.server.allow_local_network_access,
                                  city, self.server.system_language,
                                  shared_items_federated_domains,
                                  shared_item_federation_tokens,
                                  self.server.low_bandwidth,
                                  self.server.signing_priv_key_pem,
                                  self.server.peertube_instances,
                                  self.server.theme_name,
                                  self.server.max_like_count,
                                  self.server.max_recent_posts,
                                  self.server.cw_lists,
                                  self.server.lists_enabled,
                                  self.server.content_license_url,
                                  self.server.dogwhistles,
                                  self.server.min_images_for_accounts,
                                  self.server.buy_sites,
                                  self.server.sites_unavailable,
                                  self.server.max_recent_books,
                                  self.server.books_cache,
                                  self.server.max_cached_readers,
                                  self.server.auto_cw_cache,
                                  self.server.block_federated,
                                  self.server.mitm_servers,
                                  self.server.instance_software)


def _get_outbox_thread_index(self, nickname: str,
                             max_outbox_threads_per_account: int) -> int:
    """Returns the outbox thread index for the given account
    This is a ring buffer used to store the thread objects which
    are sending out posts
    """
    account_outbox_thread_name = nickname
    if not account_outbox_thread_name:
        account_outbox_thread_name = '*'

    # create the buffer for the given account
    if not self.server.outboxThread.get(account_outbox_thread_name):
        self.server.outboxThread[account_outbox_thread_name] = \
            [None] * max_outbox_threads_per_account
        self.server.outbox_thread_index[account_outbox_thread_name] = 0
        return 0

    # increment the ring buffer index
    index = self.server.outbox_thread_index[account_outbox_thread_name] + 1
    if index >= max_outbox_threads_per_account:
        index = 0

    self.server.outbox_thread_index[account_outbox_thread_name] = index

    # remove any existing thread from the current index in the buffer
    acct = account_outbox_thread_name
    if self.server.outboxThread.get(acct):
        if len(self.server.outboxThread[acct]) > index:
            try:
                if self.server.outboxThread[acct][index].is_alive():
                    self.server.outboxThread[acct][index].kill()
            except BaseException:
                pass
    return index


def post_to_outbox_thread(self, message_json: {},
                          curr_session, proxy_type: str) -> bool:
    """Creates a thread to send a post
    """
    account_outbox_thread_name = self.post_to_nickname
    if not account_outbox_thread_name:
        account_outbox_thread_name = '*'

    index = _get_outbox_thread_index(self, account_outbox_thread_name, 8)

    print('Creating outbox thread ' +
          account_outbox_thread_name + '/' +
          str(self.server.outbox_thread_index[account_outbox_thread_name]))
    print('THREAD: post_to_outbox')
    self.server.outboxThread[account_outbox_thread_name][index] = \
        thread_with_trace(target=post_to_outbox,
                          args=(self, message_json.copy(),
                                self.server.project_version, None,
                                curr_session, proxy_type),
                          daemon=True)
    print('Starting outbox thread')
    outbox_thread = \
        self.server.outboxThread[account_outbox_thread_name][index]
    begin_thread(outbox_thread, '_post_to_outbox_thread')
    return True


def update_inbox_queue(self, nickname: str, message_json: {},
                       message_bytes: str, debug: bool) -> int:
    """Update the inbox queue
    """
    if debug:
        print('INBOX: checking inbox queue restart')
    if self.server.restart_inbox_queue_in_progress:
        http_503(self)
        print('INBOX: ' +
              'message arrived but currently restarting inbox queue')
        self.server.postreq_busy = False
        return 2

    # check that the incoming message has a fully recognized
    # linked data context
    if debug:
        print('INBOX: checking valid context')
    if not has_valid_context(message_json):
        print('INBOX: ' +
              'message arriving at inbox queue has no valid context ' +
              str(message_json))
        http_400(self)
        self.server.postreq_busy = False
        return 3

    # check for blocked domains so that they can be rejected early
    if debug:
        print('INBOX: checking for actor')
    message_domain = None
    if not has_actor(message_json, debug):
        print('INBOX: message arriving at inbox queue has no actor')
        http_400(self)
        self.server.postreq_busy = False
        return 3

    # actor should be a string
    if debug:
        print('INBOX: checking that actor is string')
    actor_url = get_actor_from_post(message_json)
    if not isinstance(actor_url, str):
        print('INBOX: ' +
              'actor should be a string ' + str(actor_url))
        http_400(self)
        self.server.postreq_busy = False
        return 3

    # check that some additional fields are strings
    if debug:
        print('INBOX: checking fields 1')
    string_fields = ('id', 'type', 'published')
    for check_field in string_fields:
        if not message_json.get(check_field):
            continue
        if not isinstance(message_json[check_field], str):
            print('INBOX: ' +
                  'id, type and published fields should be strings ' +
                  check_field + ' ' + str(message_json[check_field]))
            http_400(self)
            self.server.postreq_busy = False
            return 3

    # check that to/cc fields are lists
    if debug:
        print('INBOX: checking to and cc fields')
    list_fields = ('to', 'cc')
    for check_field in list_fields:
        if not message_json.get(check_field):
            continue
        if not isinstance(message_json[check_field], list):
            print('INBOX: WARN: To and Cc fields should be lists, ' +
                  check_field + '=' + str(message_json[check_field]))
            # NOTE: this does not prevent further processing

    if has_object_dict(message_json):
        if debug:
            print('INBOX: checking object fields')
        # check that some fields are a string or list
        string_or_list_fields = ('url', 'attributedTo')
        for check_field in string_or_list_fields:
            if not message_json['object'].get(check_field):
                continue
            field_value = message_json['object'][check_field]
            if not isinstance(field_value, str) and \
               not isinstance(field_value, list):
                print('INBOX: ' +
                      check_field + ' should be a string or list ' +
                      str(message_json['object'][check_field]))
                http_400(self)
                self.server.postreq_busy = False
                return 3
        # check that some fields are strings
        string_fields = (
            'id', 'actor', 'type', 'content', 'published',
            'summary'
        )
        for check_field in string_fields:
            if not message_json['object'].get(check_field):
                continue
            if not isinstance(message_json['object'][check_field], str):
                print('INBOX: ' +
                      check_field + ' should be a string ' +
                      str(message_json['object'][check_field]))
                http_400(self)
                self.server.postreq_busy = False
                return 3
        # check attachment is a list or dict
        if debug:
            print('INBOX: checking attachment is a list or dict')
        if message_json['object'].get('attachment'):
            if not isinstance(message_json['object']['attachment'], list) and \
               not isinstance(message_json['object']['attachment'], dict):
                print('INBOX: attachment should be a list or dict ' +
                      str(message_json['object']['attachment']))
                http_400(self)
                self.server.postreq_busy = False
                return 3
        # check that some fields are lists
        if debug:
            print('INBOX: checking object to and cc fields')
        # check to and cc fields
        list_fields = ('to', 'cc')
        for check_field in list_fields:
            if not message_json['object'].get(check_field):
                continue
            if not isinstance(message_json['object'][check_field], list):
                print('INBOX: ' +
                      check_field + ' should be a list ' +
                      str(message_json['object'][check_field]))
                http_400(self)
                self.server.postreq_busy = False
                return 3
        # check that the content does not contain impossibly long urls
        if message_json['object'].get('content'):
            content_str = message_json['object']['content']
            if not valid_url_lengths(content_str, 2048):
                actor_url = get_actor_from_post(message_json)
                print('INBOX: content contains urls which are too long ' +
                      actor_url)
                http_400(self)
                self.server.postreq_busy = False
                return 3
        # check that the summary does not contain links
        if post_summary_contains_links(message_json):
            http_400(self)
            self.server.postreq_busy = False
            return 3
        # if this is a local only post, is it really local?
        if 'localOnly' in message_json['object'] and \
           message_json['object'].get('to') and \
           message_json['object'].get('attributedTo'):
            if not local_only_is_local(message_json,
                                       self.server.domain_full):
                http_400(self)
                self.server.postreq_busy = False
                return 3

    # actor should look like a url
    if debug:
        print('INBOX: checking that actor looks like a url')
    actor_url = get_actor_from_post(message_json)
    if not resembles_url(actor_url):
        print('INBOX: POST actor does not look like a url ' +
              actor_url)
        http_400(self)
        self.server.postreq_busy = False
        return 3

    # sent by an actor on a local network address?
    if debug:
        print('INBOX: checking for local network access')
    if not self.server.allow_local_network_access:
        local_network_pattern_list = get_local_network_addresses()
        actor_url = get_actor_from_post(message_json)
        for local_network_pattern in local_network_pattern_list:
            if local_network_pattern in actor_url:
                print('INBOX: POST actor contains local network address ' +
                      actor_url)
                http_400(self)
                self.server.postreq_busy = False
                return 3

    actor_url = get_actor_from_post(message_json)
    message_domain, _ = get_domain_from_actor(actor_url)
    if not message_domain:
        print('INBOX: POST from unknown domain ' + actor_url)
        http_400(self)
        self.server.postreq_busy = False
        return 3

    self.server.blocked_cache_last_updated = \
        update_blocked_cache(self.server.base_dir,
                             self.server.blocked_cache,
                             self.server.blocked_cache_last_updated,
                             self.server.blocked_cache_update_secs)

    if debug:
        print('INBOX: checking for blocked domain ' + message_domain)
    if is_blocked_domain(self.server.base_dir, message_domain,
                         self.server.blocked_cache,
                         self.server.block_federated):
        print('INBOX: POST from blocked domain ' + message_domain)
        http_400(self)
        self.server.postreq_busy = False
        return 3

    message_nickname = get_nickname_from_actor(actor_url)
    if not message_nickname:
        print('INBOX: POST from unknown nickname ' + actor_url)
        http_400(self)
        self.server.postreq_busy = False
        return 3
    if debug:
        print('INBOX: checking for blocked nickname ' + message_nickname)
    if is_blocked_nickname(self.server.base_dir, message_nickname,
                           self.server.blocked_cache):
        print('INBOX: POST from blocked nickname ' + message_nickname)
        http_400(self)
        self.server.postreq_busy = False
        return 3

    # if the inbox queue is full then return a busy code
    if debug:
        print('INBOX: checking for full queue')
    if len(self.server.inbox_queue) >= self.server.max_queue_length:
        if message_domain:
            print('INBOX: Queue: ' +
                  'Inbox queue is full. Incoming post from ' +
                  actor_url)
        else:
            print('INBOX: Queue: Inbox queue is full')
        http_503(self)
        clear_queue_items(self.server.base_dir, self.server.inbox_queue)
        if not self.server.restart_inbox_queue_in_progress:
            self.server.restart_inbox_queue = True
        self.server.postreq_busy = False
        return 2

    # follower synchronization endpoint information
    if self.headers.get('Collection-Synchronization'):
        if debug:
            print('Collection-Synchronization: ' +
                  str(self.headers['Collection-Synchronization']))

    # Convert the headers needed for signature verification to dict
    headers_dict = {}
    headers_dict['host'] = self.headers['host']
    headers_dict['signature'] = self.headers['signature']
    if self.headers.get('Date'):
        headers_dict['Date'] = self.headers['Date']
    elif self.headers.get('date'):
        headers_dict['Date'] = self.headers['date']
    if self.headers.get('digest'):
        headers_dict['digest'] = self.headers['digest']
    if self.headers.get('Collection-Synchronization'):
        headers_dict['Collection-Synchronization'] = \
            self.headers['Collection-Synchronization']
    if self.headers.get('Content-type'):
        headers_dict['Content-type'] = self.headers['Content-type']
    if self.headers.get('Content-Length'):
        headers_dict['Content-Length'] = self.headers['Content-Length']
    elif self.headers.get('content-length'):
        headers_dict['content-length'] = self.headers['content-length']

    original_message_json = message_json.copy()

    # whether to add a 'to' field to the message
    add_to_field_types = (
        'Follow', 'Like', 'EmojiReact', 'Add', 'Remove', 'Ignore', 'Move'
    )
    for add_to_type in add_to_field_types:
        message_json, _ = \
            add_to_field(add_to_type, message_json, debug)

    begin_save_time = time.time()
    # save the json for later queue processing
    message_bytes_decoded = message_bytes.decode('utf-8')

    self.server.blocked_cache_last_updated = \
        update_blocked_cache(self.server.base_dir,
                             self.server.blocked_cache,
                             self.server.blocked_cache_last_updated,
                             self.server.blocked_cache_update_secs)

    mitm = detect_mitm(self)

    if debug:
        print('INBOX: saving post to queue')
    queue_filename = \
        save_post_to_inbox_queue(self.server.base_dir,
                                 self.server.http_prefix,
                                 nickname,
                                 self.server.domain_full,
                                 message_json, original_message_json,
                                 message_bytes_decoded,
                                 headers_dict,
                                 self.path,
                                 debug,
                                 self.server.blocked_cache,
                                 self.server.block_federated,
                                 self.server.system_language,
                                 mitm,
                                 self.server.maxMessageLength)
    if queue_filename:
        # add json to the queue
        if queue_filename not in self.server.inbox_queue:
            self.server.inbox_queue.append(queue_filename)
        if debug:
            time_diff = int((time.time() - begin_save_time) * 1000)
            if time_diff > 200:
                print('SLOW: slow save of inbox queue item ' +
                      queue_filename + ' took ' + str(time_diff) + ' mS')
        self.send_response(201)
        self.end_headers()
        self.server.postreq_busy = False
        return 0
    http_503(self)
    self.server.postreq_busy = False
    return 1


def is_authorized(self) -> bool:
    self.authorized_nickname = None

    not_auth_paths = (
        '/icons/', '/avatars/', '/favicons/',
        '/system/accounts/avatars/',
        '/system/accounts/headers/',
        '/system/media_attachments/files/',
        '/accounts/avatars/', '/accounts/headers/',
        '/favicon.ico', '/newswire.xml',
        '/newswire_favicon.ico', '/categories.xml'
    )
    for not_auth_str in not_auth_paths:
        if self.path.startswith(not_auth_str):
            return False

    # token based authenticated used by the web interface
    if self.headers.get('Cookie'):
        if self.headers['Cookie'].startswith('epicyon='):
            token_str = self.headers['Cookie'].split('=', 1)[1].strip()
            if ';' in token_str:
                token_str = token_str.split(';')[0].strip()
            if self.server.tokens_lookup.get(token_str):
                nickname = self.server.tokens_lookup[token_str]
                if not is_system_account(nickname):
                    self.authorized_nickname = nickname
                    # default to the inbox of the person
                    if self.path == '/':
                        self.path = '/users/' + nickname + '/inbox'
                    # check that the path contains the same nickname
                    # as the cookie otherwise it would be possible
                    # to be authorized to use an account you don't own
                    if '/' + nickname + '/' in self.path:
                        return True
                    if '/' + nickname + '?' in self.path:
                        return True
                    if self.path.endswith('/' + nickname):
                        return True
                    if self.server.debug:
                        print('AUTH: nickname ' + nickname +
                              ' was not found in path ' + self.path)
                return False
            print('AUTH: epicyon cookie ' +
                  'authorization failed, header=' +
                  self.headers['Cookie'].replace('epicyon=', '') +
                  ' token_str=' + token_str)
            return False
        print('AUTH: Header cookie was not authorized')
        return False
    # basic auth for c2s
    if self.headers.get('Authorization'):
        if authorize(self.server.base_dir, self.path,
                     self.headers['Authorization'],
                     self.server.debug):
            return True
        print('AUTH: C2S Basic auth did not authorize ' +
              self.headers['Authorization'])
    return False


def show_person_options(self, calling_domain: str, path: str,
                        base_dir: str,
                        domain: str, domain_full: str,
                        getreq_start_time,
                        cookie: str, debug: bool,
                        authorized: bool,
                        curr_session) -> None:
    """Show person options screen
    """
    back_to_path = ''
    options_str = path.split('?options=')[1]
    origin_path_str = path.split('?options=')[0]
    if ';' in options_str and '/users/news/' not in path:
        page_number = 1
        options_list = options_str.split(';')
        options_actor = options_list[0]
        options_page_number = 1
        if len(options_list) > 1:
            options_page_number = options_list[1]
        options_profile_url = ''
        if len(options_list) > 2:
            options_profile_url = options_list[2]
        if '.' in options_profile_url and \
           options_profile_url.startswith('/members/'):
            ext = options_profile_url.split('.')[-1]
            options_profile_url = options_profile_url.split('/members/')[1]
            options_profile_url = \
                options_profile_url.replace('.' + ext, '')
            options_profile_url = \
                '/users/' + options_profile_url + '/avatar.' + ext
            back_to_path = 'moderation'
        if len(options_page_number) > 5:
            options_page_number = "1"
        if options_page_number.isdigit():
            page_number = int(options_page_number)
        options_link = None
        if len(options_list) > 3:
            options_link = options_list[3]
        is_group = False
        donate_url = None
        website_url = None
        gemini_link = None
        enigma_pub_key = None
        pgp_pub_key = None
        pgp_fingerprint = None
        pronouns = None
        pixelfed = None
        discord = None
        art_site_url = None
        music_site_url = None
        youtube = None
        peertube = None
        xmpp_address = None
        matrix_address = None
        blog_address = None
        tox_address = None
        briar_address = None
        cwtch_address = None
        ssb_address = None
        email_address = None
        deltachat_invite = None
        locked_account = False
        also_known_as = None
        moved_to = ''
        repo_url = None
        actor_json = \
            get_person_from_cache(base_dir,
                                  options_actor,
                                  self.server.person_cache)
        if actor_json:
            if actor_json.get('movedTo'):
                moved_to = actor_json['movedTo']
                if '"' in moved_to:
                    moved_to = moved_to.split('"')[1]
            if actor_json.get('type'):
                if actor_json['type'] == 'Group':
                    is_group = True
            locked_account = get_locked_account(actor_json)
            donate_url = get_donation_url(actor_json)
            website_url = get_website(actor_json, self.server.translate)
            gemini_link = get_gemini_link(actor_json)
            pronouns = get_pronouns(actor_json)
            pixelfed = get_pixelfed(actor_json)
            discord = get_discord(actor_json)
            art_site_url = get_art_site_url(actor_json)
            music_site_url = get_music_site_url(actor_json)
            youtube = get_youtube(actor_json)
            peertube = get_peertube(actor_json)
            xmpp_address = get_xmpp_address(actor_json)
            matrix_address = get_matrix_address(actor_json)
            ssb_address = get_ssb_address(actor_json)
            blog_address = get_blog_address(actor_json)
            tox_address = get_tox_address(actor_json)
            briar_address = get_briar_address(actor_json)
            cwtch_address = get_cwtch_address(actor_json)
            email_address = get_email_address(actor_json)
            deltachat_invite = \
                get_deltachat_invite(actor_json, self.server.translate)
            enigma_pub_key = get_enigma_pub_key(actor_json)
            pgp_pub_key = get_pgp_pub_key(actor_json)
            pgp_fingerprint = get_pgp_fingerprint(actor_json)
            if actor_json.get('alsoKnownAs'):
                also_known_as = remove_html(actor_json['alsoKnownAs'])
            repo_url = get_repo_url(actor_json)

        access_keys = self.server.access_keys
        nickname = 'instance'
        if '/users/' in path:
            nickname = path.split('/users/')[1]
            if '/' in nickname:
                nickname = nickname.split('/')[0]
            if self.server.key_shortcuts.get(nickname):
                access_keys = self.server.key_shortcuts[nickname]

        if curr_session:
            # because this is slow, do it in a separate thread
            if self.server.thrCheckActor.get(nickname):
                # kill existing thread
                self.server.thrCheckActor[nickname].kill()

            self.server.thrCheckActor[nickname] = \
                thread_with_trace(target=check_for_changed_actor,
                                  args=(curr_session,
                                        base_dir,
                                        self.server.http_prefix,
                                        domain_full,
                                        options_actor, options_profile_url,
                                        self.server.person_cache,
                                        self.server.check_actor_timeout),
                                  daemon=True)
            begin_thread(self.server.thrCheckActor[nickname],
                         '_show_person_options')

        msg = \
            html_person_options(self.server.default_timeline,
                                self.server.translate,
                                base_dir, domain,
                                domain_full,
                                origin_path_str,
                                options_actor,
                                options_profile_url,
                                options_link,
                                page_number, donate_url, website_url,
                                gemini_link, pronouns,
                                xmpp_address, matrix_address,
                                ssb_address, blog_address,
                                tox_address, briar_address,
                                cwtch_address,
                                enigma_pub_key,
                                pgp_pub_key, pgp_fingerprint,
                                email_address, deltachat_invite,
                                self.server.dormant_months,
                                back_to_path,
                                locked_account,
                                moved_to, also_known_as,
                                self.server.text_mode_banner,
                                self.server.news_instance,
                                authorized,
                                access_keys, is_group,
                                self.server.theme_name,
                                self.server.blocked_cache,
                                repo_url,
                                self.server.sites_unavailable,
                                youtube, peertube, pixelfed,
                                discord, music_site_url,
                                art_site_url,
                                self.server.mitm_servers)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', '_show_person_options', debug)
        else:
            http_404(self, 31)
        return

    if '/users/news/' in path:
        redirect_headers(self, origin_path_str + '/tlfeatures',
                         cookie, calling_domain, 303)
        return

    origin_path_str_absolute = \
        get_instance_url(calling_domain,
                         self.server.http_prefix,
                         domain_full,
                         self.server.onion_domain,
                         self.server.i2p_domain) + \
        origin_path_str
    redirect_headers(self, origin_path_str_absolute, cookie,
                     calling_domain, 303)


def get_user_agent(self) -> str:
    """Returns the user agent string from the headers
    """
    ua_str = None
    if self.headers.get('User-Agent'):
        ua_str = self.headers['User-Agent']
    elif self.headers.get('user-agent'):
        ua_str = self.headers['user-agent']
    elif self.headers.get('User-agent'):
        ua_str = self.headers['User-agent']
    return ua_str


def has_accept(self, calling_domain: str) -> bool:
    """Do the http headers have an Accept field?
    """
    if not self.headers.get('Accept'):
        if self.headers.get('accept'):
            print('Upper case Accept')
            self.headers['Accept'] = self.headers['accept']

    if self.headers.get('Accept') or calling_domain.endswith('.b32.i2p'):
        if not self.headers.get('Accept'):
            self.headers['Accept'] = \
                'text/html,application/xhtml+xml,' \
                'application/xml;q=0.9,image/webp,*/*;q=0.8'
        return True
    return False


def etag_exists(self, media_filename: str) -> bool:
    """Does an etag header exist for the given file?
    """
    etag_header = 'If-None-Match'
    if not self.headers.get(etag_header):
        etag_header = 'if-none-match'
        if not self.headers.get(etag_header):
            etag_header = 'If-none-match'

    if self.headers.get(etag_header):
        old_etag = self.headers[etag_header].replace('"', '')
        if os.path.isfile(media_filename + '.etag'):
            # load the etag from file
            curr_etag = ''
            try:
                with open(media_filename + '.etag', 'r',
                          encoding='utf-8') as fp_media:
                    curr_etag = fp_media.read()
            except OSError:
                print('EX: _etag_exists unable to read ' +
                      str(media_filename))
            if curr_etag and old_etag == curr_etag:
                # The file has not changed
                return True
    return False


def _get_epicyon_domain_from_user_agent(ua_str: str) -> str:
    """Extracts the epicyon domain from the user agent
    """
    if 'Epicyon/' not in ua_str:
        return ''
    ua_text = ua_str.split('Epicyon/')[1]
    if '://' not in ua_text:
        return ''
    domain = ua_text.split('://')[1]
    if '/' in domain:
        domain = domain.split('/')[0]
    return domain


def log_epicyon_instances(base_dir: str, ua_str: str,
                          known_epicyon_instances: []) -> None:
    """Saves a log of known epicyon instances
    """
    calling_domain = _get_epicyon_domain_from_user_agent(ua_str)
    if not calling_domain:
        return
    if calling_domain in known_epicyon_instances:
        return
    known_epicyon_instances.append(calling_domain)
    known_epicyon_instances.sort()
    epicyon_instances_filename = \
        data_dir(base_dir) + '/known_epicyon_instances.json'
    print('DEBUG: log_epicyon_instances: ' +
          epicyon_instances_filename + ' ' + str(known_epicyon_instances))
    save_json(known_epicyon_instances, epicyon_instances_filename)


def load_known_epicyon_instances(base_dir: str) -> []:
    """Loads a list of known epicyon instances
    """
    epicyon_instances_filename = \
        data_dir(base_dir) + '/known_epicyon_instances.json'
    if not os.path.isfile(epicyon_instances_filename):
        return []
    known_epicyon_instances = load_json(epicyon_instances_filename)
    if not known_epicyon_instances:
        return []
    return known_epicyon_instances
