__filename__ = "daemon_post_login.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import time
import errno
from hashlib import sha256
from socket import error as SocketError
from auth import create_password
from auth import record_login_failure
from auth import authorize_basic
from auth import create_basic_auth_header
from httpcodes import write2
from httpcodes import http_200
from httpcodes import http_401
from httpcodes import http_503
from httpheaders import login_headers
from httpheaders import redirect_headers
from httpheaders import clear_login_details
from webapp_login import html_get_login_credentials
from webapp_suspended import html_suspended
from flags import is_suspended
from flags import is_local_network_address
from utils import data_dir
from utils import acct_dir
from utils import get_instance_url
from utils import valid_password
from flags import is_system_account
from person import person_upgrade_actor
from person import activate_account2
from person import register_account


def post_login_screen(self, calling_domain: str, cookie: str,
                      base_dir: str, http_prefix: str,
                      domain: str, port: int,
                      ua_str: str, debug: bool,
                      registrations_open: bool,
                      domain_full: str,
                      onion_domain: str,
                      i2p_domain: str,
                      manual_follower_approval: bool,
                      default_timeline: str) -> None:
    """POST to login screen, containing credentials
    """
    # ensure that there is a minimum delay between failed login
    # attempts, to mitigate brute force
    if int(time.time()) - self.server.last_login_failure < 5:
        http_503(self)
        self.server.postreq_busy = False
        return

    # get the contents of POST containing login credentials
    length = int(self.headers['Content-length'])
    if length > 512:
        print('Login failed - credentials too long')
        http_401(self, 'Credentials are too long')
        self.server.postreq_busy = False
        return

    try:
        login_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST login read ' +
                  'connection reset by peer')
        else:
            print('EX: POST login read socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST login read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    login_nickname, login_password, register = \
        html_get_login_credentials(login_params,
                                   self.server.last_login_time,
                                   registrations_open,
                                   calling_domain, ua_str)
    if login_nickname and login_password:
        if is_system_account(login_nickname):
            print('Invalid username login: ' + login_nickname +
                  ' (system account)')
            clear_login_details(self, login_nickname, calling_domain)
            self.server.postreq_busy = False
            return
        self.server.last_login_time = int(time.time())
        if register:
            if not valid_password(login_password, debug):
                self.server.postreq_busy = False
                login_url = \
                    get_instance_url(calling_domain,
                                     http_prefix,
                                     domain_full,
                                     onion_domain,
                                     i2p_domain) + \
                    '/login'
                redirect_headers(self, login_url, cookie, calling_domain, 303)
                return

            if not register_account(base_dir, http_prefix, domain, port,
                                    login_nickname, login_password,
                                    manual_follower_approval):
                self.server.postreq_busy = False
                login_url = \
                    get_instance_url(calling_domain,
                                     http_prefix,
                                     domain_full,
                                     onion_domain,
                                     i2p_domain) + \
                    '/login'
                redirect_headers(self, login_url, cookie, calling_domain, 303)
                return
        auth_header = \
            create_basic_auth_header(login_nickname, login_password)
        if self.headers.get('X-Forward-For'):
            ip_address = self.headers['X-Forward-For']
        elif self.headers.get('X-Forwarded-For'):
            ip_address = self.headers['X-Forwarded-For']
        else:
            ip_address = self.client_address[0]
        if not domain.endswith('.onion'):
            if not is_local_network_address(ip_address):
                print('Login attempt from IP: ' + str(ip_address))
        if not authorize_basic(base_dir, '/users/' +
                               login_nickname + '/outbox',
                               auth_header, False):
            print('Login failed: ' + login_nickname)
            clear_login_details(self, login_nickname, calling_domain)
            fail_time = int(time.time())
            self.server.last_login_failure = fail_time
            if not domain.endswith('.onion'):
                if not is_local_network_address(ip_address):
                    record_login_failure(base_dir, ip_address,
                                         self.server.login_failure_count,
                                         fail_time,
                                         self.server.log_login_failures)
            self.server.postreq_busy = False
            return
        else:
            if self.server.login_failure_count.get(ip_address):
                del self.server.login_failure_count[ip_address]
            if is_suspended(base_dir, login_nickname):
                msg = \
                    html_suspended(base_dir).encode('utf-8')
                msglen = len(msg)
                login_headers(self, 'text/html',
                              msglen, calling_domain)
                write2(self, msg)
                self.server.postreq_busy = False
                return
            # login success - redirect with authorization
            print('====== Login success: ' + login_nickname +
                  ' ' + ua_str)
            # re-activate account if needed
            activate_account2(base_dir, login_nickname, domain)
            # This produces a deterministic token based
            # on nick+password+salt
            salt_filename = \
                acct_dir(base_dir, login_nickname, domain) + '/.salt'
            salt = create_password(32)
            if os.path.isfile(salt_filename):
                try:
                    with open(salt_filename, 'r',
                              encoding='utf-8') as fp_salt:
                        salt = fp_salt.read()
                except OSError as ex:
                    print('EX: Unable to read salt for ' +
                          login_nickname + ' ' + str(ex))
            else:
                try:
                    with open(salt_filename, 'w+',
                              encoding='utf-8') as fp_salt:
                        fp_salt.write(salt)
                except OSError as ex:
                    print('EX: Unable to save salt for ' +
                          login_nickname + ' ' + str(ex))

            token_text = login_nickname + login_password + salt
            token = sha256(token_text.encode('utf-8')).hexdigest()
            self.server.tokens[login_nickname] = token
            login_handle = login_nickname + '@' + domain
            token_filename = \
                data_dir(base_dir) + '/' + login_handle + '/.token'
            try:
                with open(token_filename, 'w+',
                          encoding='utf-8') as fp_tok:
                    fp_tok.write(token)
            except OSError as ex:
                print('EX: Unable to save token for ' +
                      login_nickname + ' ' + str(ex))

            dir_str = data_dir(base_dir)
            person_upgrade_actor(base_dir, None,
                                 dir_str + '/' + login_handle + '.json')

            index = self.server.tokens[login_nickname]
            self.server.tokens_lookup[index] = login_nickname
            cookie_str = 'SET:epicyon=' + \
                self.server.tokens[login_nickname] + '; SameSite=Strict'
            tl_url = \
                get_instance_url(calling_domain,
                                 http_prefix,
                                 domain_full,
                                 onion_domain,
                                 i2p_domain) + \
                '/users/' + login_nickname + '/' + \
                default_timeline
            redirect_headers(self, tl_url, cookie_str, calling_domain, 303)
            self.server.postreq_busy = False
            return
    else:
        print('WARN: No login credentials presented to /login')
        if debug:
            # be careful to avoid logging the password
            login_str = login_params
            if '=' in login_params:
                login_params_list = login_params.split('=')
                login_str = ''
                skip_param = False
                for login_prm in login_params_list:
                    if not skip_param:
                        login_str += login_prm + '='
                    else:
                        len_str = login_prm.split('&')[0]
                        if len(len_str) > 0:
                            login_str += login_prm + '*'
                        len_str = ''
                        if '&' in login_prm:
                            login_str += \
                                '&' + login_prm.split('&')[1] + '='
                    skip_param = False
                    if 'password' in login_prm:
                        skip_param = True
                login_str = login_str[:len(login_str) - 1]
            print(login_str)
        http_401(self, 'No login credentials were posted')
        self.server.postreq_busy = False
    http_200(self)
    self.server.postreq_busy = False
