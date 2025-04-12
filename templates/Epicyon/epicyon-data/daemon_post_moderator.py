__filename__ = "daemon_post_moderator.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import errno
import urllib.parse
from socket import error as SocketError
from utils import data_dir
from utils import delete_post
from utils import locate_post
from utils import get_full_domain
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from utils import get_instance_url
from posts import is_moderator
from httpcodes import write2
from httpheaders import redirect_headers
from httpheaders import login_headers
from webapp_moderation import html_account_info
from webapp_moderation import html_moderation_info
from person import can_remove_post
from person import remove_account
from person import suspend_account
from person import reenable_account
from filters import add_global_filter
from filters import remove_global_filter
from cache import clear_actor_cache
from blocking import add_global_block
from blocking import update_blocked_cache
from blocking import remove_global_block


def moderator_actions(self, path: str, calling_domain: str, cookie: str,
                      base_dir: str, http_prefix: str,
                      domain: str, port: int, debug: bool,
                      domain_full: str,
                      onion_domain: str, i2p_domain: str,
                      translate: {},
                      system_language: str,
                      signing_priv_key_pem: str,
                      block_federated: [],
                      theme_name: str,
                      access_keys: {}, person_cache: {},
                      recent_posts_cache: {},
                      blocked_cache: {},
                      mitm_servers: []) -> None:
    """Actions on the moderator screen
    """
    users_path = path.replace('/moderationaction', '')
    nickname = users_path.replace('/users/', '')
    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path
    if not is_moderator(base_dir, nickname):
        redirect_headers(self, actor_str + '/moderation',
                         cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    length = int(self.headers['Content-length'])

    try:
        moderation_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST moderation_params connection was reset')
        else:
            print('EX: POST moderation_params ' +
                  'rfile.read socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST moderation_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&' in moderation_params:
        moderation_text = None
        moderation_button = None
        # get the moderation text first
        act_str = 'moderationAction='
        for moderation_str in moderation_params.split('&'):
            if moderation_str.startswith(act_str):
                if act_str in moderation_str:
                    moderation_text = \
                        moderation_str.split(act_str)[1].strip()
                    mod_text = moderation_text.replace('+', ' ')
                    moderation_text = \
                        urllib.parse.unquote_plus(mod_text.strip())
        # which button was pressed?
        for moderation_str in moderation_params.split('&'):
            if moderation_str.startswith('submitInfo='):
                if not moderation_text and \
                   'submitInfo=' in moderation_str:
                    moderation_text = \
                        moderation_str.split('submitInfo=')[1].strip()
                    mod_text = moderation_text.replace('+', ' ')
                    moderation_text = \
                        urllib.parse.unquote_plus(mod_text.strip())
                search_handle = moderation_text
                if search_handle:
                    if '/@' in search_handle and \
                       '/@/' not in search_handle:
                        search_nickname = \
                            get_nickname_from_actor(search_handle)
                        if search_nickname:
                            search_domain, _ = \
                                get_domain_from_actor(search_handle)
                            if search_domain:
                                search_handle = \
                                    search_nickname + '@' + search_domain
                            else:
                                search_handle = ''
                        else:
                            search_handle = ''
                    if '@' not in search_handle or \
                       '/@/' in search_handle:
                        if search_handle.startswith('http') or \
                           search_handle.startswith('ipfs') or \
                           search_handle.startswith('ipns'):
                            search_nickname = \
                                get_nickname_from_actor(search_handle)
                            if search_nickname:
                                search_domain, _ = \
                                    get_domain_from_actor(search_handle)
                                if search_domain:
                                    search_handle = \
                                        search_nickname + '@' + \
                                        search_domain
                                else:
                                    search_handle = ''
                            else:
                                search_handle = ''
                    if '@' not in search_handle:
                        # is this a local nickname on this instance?
                        local_handle = \
                            search_handle + '@' + domain
                        dir_str = data_dir(base_dir)
                        if os.path.isdir(dir_str + '/' + local_handle):
                            search_handle = local_handle
                        else:
                            search_handle = ''
                if search_handle is None:
                    search_handle = ''
                if '@' in search_handle:
                    msg = \
                        html_account_info(translate,
                                          base_dir, http_prefix,
                                          nickname,
                                          domain,
                                          search_handle,
                                          debug,
                                          system_language,
                                          signing_priv_key_pem,
                                          None,
                                          block_federated,
                                          mitm_servers)
                else:
                    msg = \
                        html_moderation_info(translate,
                                             base_dir, nickname,
                                             domain,
                                             theme_name,
                                             access_keys)
                if msg:
                    msg = msg.encode('utf-8')
                    msglen = len(msg)
                    login_headers(self, 'text/html',
                                  msglen, calling_domain)
                    write2(self, msg)
                self.server.postreq_busy = False
                return
            if moderation_str.startswith('submitBlock'):
                moderation_button = 'block'
            elif moderation_str.startswith('submitUnblock'):
                moderation_button = 'unblock'
            elif moderation_str.startswith('submitFilter'):
                moderation_button = 'filter'
            elif moderation_str.startswith('submitUnfilter'):
                moderation_button = 'unfilter'
            elif moderation_str.startswith('submitClearCache'):
                moderation_button = 'clearcache'
            elif moderation_str.startswith('submitSuspend'):
                moderation_button = 'suspend'
            elif moderation_str.startswith('submitUnsuspend'):
                moderation_button = 'unsuspend'
            elif moderation_str.startswith('submitRemove'):
                moderation_button = 'remove'
        if moderation_button and moderation_text:
            if debug:
                print('moderation_button: ' + moderation_button)
                print('moderation_text: ' + moderation_text)
            nickname = moderation_text
            if nickname.startswith('http') or \
               nickname.startswith('ipfs') or \
               nickname.startswith('ipns') or \
               nickname.startswith('hyper'):
                nickname = get_nickname_from_actor(nickname)
            if '@' in nickname:
                nickname = nickname.split('@')[0]
            if moderation_button == 'suspend':
                suspend_account(base_dir, nickname, domain)
            if moderation_button == 'unsuspend':
                reenable_account(base_dir, nickname, domain)
            if moderation_button == 'filter':
                add_global_filter(base_dir, moderation_text)
            if moderation_button == 'unfilter':
                remove_global_filter(base_dir, moderation_text)
            if moderation_button == 'clearcache':
                clear_actor_cache(base_dir, person_cache,
                                  moderation_text)
            if moderation_button == 'block':
                full_block_domain = None
                moderation_text = moderation_text.strip()
                moderation_reason = None
                if ' ' in moderation_text:
                    moderation_domain = moderation_text.split(' ', 1)[0]
                    moderation_reason = moderation_text.split(' ', 1)[1]
                else:
                    moderation_domain = moderation_text
                if moderation_domain.startswith('http') or \
                   moderation_domain.startswith('ipfs') or \
                   moderation_domain.startswith('ipns') or \
                   moderation_domain.startswith('hyper'):
                    # https://domain
                    block_domain, block_port = \
                        get_domain_from_actor(moderation_domain)
                    if block_domain:
                        full_block_domain = \
                            get_full_domain(block_domain, block_port)
                if '@' in moderation_domain:
                    # nick@domain or *@domain
                    full_block_domain = \
                        moderation_domain.split('@')[1]
                else:
                    # assume the text is a domain name
                    if not full_block_domain and '.' in moderation_domain:
                        nickname = '*'
                        full_block_domain = \
                            moderation_domain.strip()
                if full_block_domain or nickname.startswith('#'):
                    if nickname.startswith('#') and ' ' in nickname:
                        nickname = nickname.split(' ')[0]
                    add_global_block(base_dir, nickname,
                                     full_block_domain, moderation_reason)
                    blocked_cache_last_updated = \
                        self.server.blocked_cache_last_updated
                    self.server.blocked_cache_last_updated = \
                        update_blocked_cache(base_dir, blocked_cache,
                                             blocked_cache_last_updated, 0)
            if moderation_button == 'unblock':
                full_block_domain = None
                if ' ' in moderation_text:
                    moderation_domain = moderation_text.split(' ', 1)[0]
                else:
                    moderation_domain = moderation_text
                if moderation_domain.startswith('http') or \
                   moderation_domain.startswith('ipfs') or \
                   moderation_domain.startswith('ipns') or \
                   moderation_domain.startswith('hyper'):
                    # https://domain
                    block_domain, block_port = \
                        get_domain_from_actor(moderation_domain)
                    if block_domain:
                        full_block_domain = \
                            get_full_domain(block_domain, block_port)
                if '@' in moderation_domain:
                    # nick@domain or *@domain
                    full_block_domain = moderation_domain.split('@')[1]
                else:
                    # assume the text is a domain name
                    if not full_block_domain and '.' in moderation_domain:
                        nickname = '*'
                        full_block_domain = moderation_domain.strip()
                if full_block_domain or nickname.startswith('#'):
                    if nickname.startswith('#') and ' ' in nickname:
                        nickname = nickname.split(' ')[0]
                    remove_global_block(base_dir, nickname,
                                        full_block_domain)
                    blocked_cache_last_updated = \
                        self.server.blocked_cache_last_updated
                    self.server.blocked_cache_last_updated = \
                        update_blocked_cache(base_dir, blocked_cache,
                                             blocked_cache_last_updated, 0)
            if moderation_button == 'remove':
                if '/statuses/' not in moderation_text:
                    remove_account(base_dir, nickname, domain, port)
                else:
                    # remove a post or thread
                    post_filename = \
                        locate_post(base_dir, nickname, domain,
                                    moderation_text)
                    if post_filename:
                        if can_remove_post(base_dir, domain, port,
                                           moderation_text):
                            delete_post(base_dir,
                                        http_prefix,
                                        nickname, domain,
                                        post_filename,
                                        debug,
                                        recent_posts_cache,
                                        True)
                    if nickname != 'news':
                        # if this is a local blog post then also remove it
                        # from the news actor
                        post_filename = \
                            locate_post(base_dir, 'news', domain,
                                        moderation_text)
                        if post_filename:
                            if can_remove_post(base_dir, domain, port,
                                               moderation_text):
                                delete_post(base_dir,
                                            http_prefix,
                                            'news', domain,
                                            post_filename,
                                            debug,
                                            recent_posts_cache,
                                            True)

    redirect_headers(self, actor_str + '/moderation',
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False
    return
