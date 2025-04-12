__filename__ = "daemon_post_confirm.py"
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
from flags import has_group_type
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_full_domain
from utils import local_actor_url
from utils import get_status_number
from follow import unfollow_account
from follow import send_follow_request
from follow import remove_follower
from daemon_utils import post_to_outbox_thread
from httpcodes import write2
from httpheaders import redirect_headers
from httpheaders import login_headers
from posts import is_moderator
from webapp_moderation import html_account_info
from session import establish_session
from blocking import remove_block
from blocking import remove_global_block
from blocking import update_blocked_cache
from blocking import add_block


def unfollow_confirm(self, calling_domain: str, cookie: str,
                     path: str, base_dir: str, http_prefix: str,
                     domain: str, domain_full: str, port: int,
                     onion_domain: str, i2p_domain: str,
                     debug: bool,
                     curr_session, proxy_type: str,
                     person_cache: {}) -> None:
    """Confirm an unfollow request from profile after search or from
    person options
    """
    users_path = path.split('/unfollowconfirm')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path
    follower_nickname = get_nickname_from_actor(origin_path_str)
    if not follower_nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    length = int(self.headers['Content-length'])

    try:
        follow_confirm_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST follow_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST follow_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST follow_confirm_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitYes=' in follow_confirm_params:
        following_actor = \
            urllib.parse.unquote_plus(follow_confirm_params)
        following_actor = following_actor.split('actor=')[1]
        if '&' in following_actor:
            following_actor = following_actor.split('&')[0]
        following_nickname = get_nickname_from_actor(following_actor)
        following_domain, following_port = \
            get_domain_from_actor(following_actor)
        if not following_nickname or not following_domain:
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return
        following_domain_full = \
            get_full_domain(following_domain, following_port)
        if follower_nickname == following_nickname and \
           following_domain == domain and \
           following_port == port:
            if debug:
                print('You cannot unfollow yourself!')
        else:
            if debug:
                print(follower_nickname + ' stops following ' +
                      following_actor)
            follow_actor = \
                local_actor_url(http_prefix,
                                follower_nickname, domain_full)
            status_number, _ = get_status_number()
            follow_id = follow_actor + '/statuses/' + str(status_number)
            unfollow_json = {
                "@context": [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
                ],
                'id': follow_id + '/undo',
                'type': 'Undo',
                'actor': follow_actor,
                'object': {
                    'id': follow_id,
                    'type': 'Follow',
                    'actor': follow_actor,
                    'object': following_actor
                }
            }
            path_users_section = path.split('/users/')[1]
            self.post_to_nickname = path_users_section.split('/')[0]
            group_account = has_group_type(base_dir, following_actor,
                                           person_cache)
            unfollow_account(base_dir, self.post_to_nickname,
                             domain,
                             following_nickname, following_domain_full,
                             debug, group_account,
                             'following.txt')
            post_to_outbox_thread(self, unfollow_json,
                                  curr_session, proxy_type)

    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
    self.server.postreq_busy = False


def follow_confirm2(self, calling_domain: str, cookie: str,
                    path: str, base_dir: str, http_prefix: str,
                    domain: str, domain_full: str, port: int,
                    onion_domain: str, i2p_domain: str,
                    debug: bool,
                    curr_session, proxy_type: str,
                    translate: {},
                    system_language: str,
                    signing_priv_key_pem: str,
                    block_federated: [],
                    federation_list: [],
                    send_threads: [],
                    post_log: str,
                    cached_webfingers: {},
                    person_cache: {},
                    project_version: str,
                    sites_unavailable: [],
                    mitm_servers: []) -> None:
    """Confirm a follow from profile after search or from person options
    """
    users_path = path.split('/followconfirm')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path
    follower_nickname = get_nickname_from_actor(origin_path_str)
    if not follower_nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    length = int(self.headers['Content-length'])

    try:
        follow_confirm_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST follow_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST follow_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST follow_confirm_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitView=' in follow_confirm_params:
        following_actor = \
            urllib.parse.unquote_plus(follow_confirm_params)
        following_actor = following_actor.split('actor=')[1]
        if '&' in following_actor:
            following_actor = following_actor.split('&')[0]
        redirect_headers(self, following_actor, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    if '&submitInfo=' in follow_confirm_params:
        following_actor = \
            urllib.parse.unquote_plus(follow_confirm_params)
        following_actor = following_actor.split('actor=')[1]
        if '&' in following_actor:
            following_actor = following_actor.split('&')[0]
        if is_moderator(base_dir, follower_nickname):
            msg = \
                html_account_info(translate,
                                  base_dir, http_prefix,
                                  follower_nickname,
                                  domain,
                                  following_actor,
                                  debug,
                                  system_language,
                                  signing_priv_key_pem,
                                  users_path,
                                  block_federated,
                                  mitm_servers)
            if msg:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                login_headers(self, 'text/html',
                              msglen, calling_domain)
                write2(self, msg)
                self.server.postreq_busy = False
                return
        redirect_headers(self, following_actor, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    if '&submitYes=' in follow_confirm_params:
        following_actor = \
            urllib.parse.unquote_plus(follow_confirm_params)
        following_actor = following_actor.split('actor=')[1]
        if '&' in following_actor:
            following_actor = following_actor.split('&')[0]
        following_nickname = get_nickname_from_actor(following_actor)
        following_domain, following_port = \
            get_domain_from_actor(following_actor)
        if not following_nickname or not following_domain:
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return
        if follower_nickname == following_nickname and \
           following_domain == domain and \
           following_port == port:
            if debug:
                print('You cannot follow yourself!')
        elif (following_nickname == 'news' and
              following_domain == domain and
              following_port == port):
            if debug:
                print('You cannot follow the news actor')
        else:
            print('Sending follow request from ' +
                  follower_nickname + ' to ' + following_actor)
            if not signing_priv_key_pem:
                print('Sending follow request with no signing key')

            curr_domain = domain
            curr_port = port
            curr_http_prefix = http_prefix
            curr_proxy_type = proxy_type
            if onion_domain:
                if not curr_domain.endswith('.onion') and \
                   following_domain.endswith('.onion'):
                    curr_session = self.server.session_onion
                    curr_domain = onion_domain
                    curr_port = 80
                    following_port = 80
                    curr_http_prefix = 'http'
                    curr_proxy_type = 'tor'
            if i2p_domain:
                if not curr_domain.endswith('.i2p') and \
                   following_domain.endswith('.i2p'):
                    curr_session = self.server.session_i2p
                    curr_domain = i2p_domain
                    curr_port = 80
                    following_port = 80
                    curr_http_prefix = 'http'
                    curr_proxy_type = 'i2p'

            curr_session = \
                establish_session("follow request confirm",
                                  curr_session,
                                  curr_proxy_type,
                                  self.server)

            send_follow_request(curr_session,
                                base_dir, follower_nickname,
                                domain, curr_domain, curr_port,
                                curr_http_prefix,
                                following_nickname,
                                following_domain,
                                following_actor,
                                following_port, curr_http_prefix,
                                False, federation_list,
                                send_threads,
                                post_log,
                                cached_webfingers,
                                person_cache, debug,
                                project_version,
                                signing_priv_key_pem,
                                domain,
                                onion_domain,
                                i2p_domain,
                                sites_unavailable,
                                system_language,
                                mitm_servers)

    if '&submitUnblock=' in follow_confirm_params:
        blocking_actor = \
            urllib.parse.unquote_plus(follow_confirm_params)
        blocking_actor = blocking_actor.split('actor=')[1]
        if '&' in blocking_actor:
            blocking_actor = blocking_actor.split('&')[0]
        blocking_nickname = get_nickname_from_actor(blocking_actor)
        blocking_domain, blocking_port = \
            get_domain_from_actor(blocking_actor)
        if not blocking_nickname or not blocking_domain:
            if calling_domain.endswith('.onion') and onion_domain:
                origin_path_str = 'http://' + onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and i2p_domain):
                origin_path_str = 'http://' + i2p_domain + users_path
            print('WARN: unable to find blocked nickname or domain in ' +
                  blocking_actor)
            redirect_headers(self, origin_path_str,
                             cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return
        blocking_domain_full = \
            get_full_domain(blocking_domain, blocking_port)
        if follower_nickname == blocking_nickname and \
           blocking_domain == domain and \
           blocking_port == port:
            if debug:
                print('You cannot unblock yourself!')
        else:
            if debug:
                print(follower_nickname + ' stops blocking ' +
                      blocking_actor)
            remove_block(base_dir,
                         follower_nickname, domain,
                         blocking_nickname, blocking_domain_full)
            if is_moderator(base_dir, follower_nickname):
                remove_global_block(base_dir,
                                    blocking_nickname,
                                    blocking_domain_full)
                blocked_cache_last_updated = \
                    self.server.blocked_cache_last_updated
                self.server.blocked_cache_last_updated = \
                    update_blocked_cache(base_dir,
                                         self.server.blocked_cache,
                                         blocked_cache_last_updated, 0)

    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
    self.server.postreq_busy = False


def block_confirm2(self, calling_domain: str, cookie: str,
                   path: str, base_dir: str, http_prefix: str,
                   domain: str, domain_full: str, port: int,
                   onion_domain: str, i2p_domain: str,
                   debug: bool) -> None:
    """Confirms a block from the person options screen
    """
    users_path = path.split('/blockconfirm')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path
    blocker_nickname = get_nickname_from_actor(origin_path_str)
    if not blocker_nickname:
        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str = 'http://' + onion_domain + users_path
        elif (calling_domain.endswith('.i2p') and i2p_domain):
            origin_path_str = 'http://' + i2p_domain + users_path
        print('WARN: unable to find nickname in ' + origin_path_str)
        redirect_headers(self, origin_path_str,
                         cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    length = int(self.headers['Content-length'])

    try:
        block_confirm_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST block_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST block_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST block_confirm_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitYes=' in block_confirm_params:
        blocking_confirm_str = \
            urllib.parse.unquote_plus(block_confirm_params)
        block_reason = blocking_confirm_str.split('blockReason=')[1]
        if '&' in block_reason:
            block_reason = block_reason.split('&')[0]
        blocking_actor = blocking_confirm_str.split('actor=')[1]
        if '&' in blocking_actor:
            blocking_actor = blocking_actor.split('&')[0]
        blocking_nickname = get_nickname_from_actor(blocking_actor)
        blocking_domain, blocking_port = \
            get_domain_from_actor(blocking_actor)
        if not blocking_nickname or not blocking_domain:
            if calling_domain.endswith('.onion') and onion_domain:
                origin_path_str = 'http://' + onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and i2p_domain):
                origin_path_str = 'http://' + i2p_domain + users_path
            print('WARN: unable to find nickname or domain in ' +
                  blocking_actor)
            redirect_headers(self, origin_path_str,
                             cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return
        blocking_domain_full = \
            get_full_domain(blocking_domain, blocking_port)
        if blocker_nickname == blocking_nickname and \
           blocking_domain == domain and \
           blocking_port == port:
            if debug:
                print('You cannot block yourself!')
        else:
            print('Adding block by ' + blocker_nickname +
                  ' of ' + blocking_actor)
            add_block(base_dir, blocker_nickname,
                      domain, blocking_nickname,
                      blocking_domain_full, block_reason)
            remove_follower(base_dir, blocker_nickname,
                            domain,
                            blocking_nickname,
                            blocking_domain_full)
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
    self.server.postreq_busy = False


def unblock_confirm(self, calling_domain: str, cookie: str,
                    path: str, base_dir: str, http_prefix: str,
                    domain: str, domain_full: str, port: int,
                    onion_domain: str, i2p_domain: str,
                    debug: bool) -> None:
    """Confirms an unblock from the person options screen
    """
    users_path = path.split('/unblockconfirm')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path
    blocker_nickname = get_nickname_from_actor(origin_path_str)
    if not blocker_nickname:
        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str = 'http://' + onion_domain + users_path
        elif (calling_domain.endswith('.i2p') and i2p_domain):
            origin_path_str = 'http://' + i2p_domain + users_path
        print('WARN: unable to find nickname in ' + origin_path_str)
        redirect_headers(self, origin_path_str,
                         cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    length = int(self.headers['Content-length'])

    try:
        block_confirm_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST block_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST block_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST block_confirm_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitYes=' in block_confirm_params:
        blocking_actor = \
            urllib.parse.unquote_plus(block_confirm_params)
        blocking_actor = blocking_actor.split('actor=')[1]
        if '&' in blocking_actor:
            blocking_actor = blocking_actor.split('&')[0]
        blocking_nickname = get_nickname_from_actor(blocking_actor)
        blocking_domain, blocking_port = \
            get_domain_from_actor(blocking_actor)
        if not blocking_nickname or not blocking_domain:
            if calling_domain.endswith('.onion') and onion_domain:
                origin_path_str = 'http://' + onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and i2p_domain):
                origin_path_str = 'http://' + i2p_domain + users_path
            print('WARN: unable to find nickname in ' + blocking_actor)
            redirect_headers(self, origin_path_str,
                             cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return
        blocking_domain_full = \
            get_full_domain(blocking_domain, blocking_port)
        if blocker_nickname == blocking_nickname and \
           blocking_domain == domain and \
           blocking_port == port:
            if debug:
                print('You cannot unblock yourself!')
        else:
            if debug:
                print(blocker_nickname + ' stops blocking ' +
                      blocking_actor)
            remove_block(base_dir,
                         blocker_nickname, domain,
                         blocking_nickname, blocking_domain_full)
            if is_moderator(base_dir, blocker_nickname):
                remove_global_block(base_dir,
                                    blocking_nickname,
                                    blocking_domain_full)
                blocked_cache_last_updated = \
                    self.server.blocked_cache_last_updated
                self.server.blocked_cache_last_updated = \
                    update_blocked_cache(base_dir,
                                         self.server.blocked_cache,
                                         blocked_cache_last_updated, 0)
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str,
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False
