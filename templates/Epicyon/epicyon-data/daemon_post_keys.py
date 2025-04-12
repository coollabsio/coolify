__filename__ = "daemon_post_image.py"
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
from httpheaders import redirect_headers
from utils import acct_dir
from utils import save_json


def keyboard_shortcuts(self, calling_domain: str, cookie: str,
                       base_dir: str, http_prefix: str, nickname: str,
                       domain: str, domain_full: str,
                       onion_domain: str, i2p_domain: str,
                       access_keys2: {}, default_timeline: str,
                       access_keys: {}, key_shortcuts: {}) -> None:
    """Receive POST from webapp_accesskeys
    """
    users_path = '/users/' + nickname
    origin_path_str = \
        http_prefix + '://' + domain_full + users_path + '/' + \
        default_timeline
    length = int(self.headers['Content-length'])

    try:
        access_keys_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST access_keys_params ' +
                  'connection reset by peer')
        else:
            print('EX: POST access_keys_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST access_keys_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    access_keys_params = \
        urllib.parse.unquote_plus(access_keys_params)

    # key shortcuts screen, back button
    # See html_access_keys
    if 'submitAccessKeysCancel=' in access_keys_params or \
       'submitAccessKeys=' not in access_keys_params:
        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str = \
                'http://' + onion_domain + users_path + '/' + \
                default_timeline
        elif calling_domain.endswith('.i2p') and i2p_domain:
            origin_path_str = \
                'http://' + i2p_domain + users_path + \
                '/' + default_timeline
        redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    save_keys = False
    access_keys_template = access_keys
    for variable_name, _ in access_keys_template.items():
        if not access_keys2.get(variable_name):
            access_keys2[variable_name] = \
                access_keys_template[variable_name]

        variable_name2 = variable_name.replace(' ', '_')
        if variable_name2 + '=' in access_keys_params:
            new_key = access_keys_params.split(variable_name2 + '=')[1]
            if '&' in new_key:
                new_key = new_key.split('&')[0]
            if new_key:
                if len(new_key) > 1:
                    new_key = new_key[0]
                if new_key != access_keys2[variable_name]:
                    access_keys2[variable_name] = new_key
                    save_keys = True

    if save_keys:
        access_keys_filename = \
            acct_dir(base_dir, nickname, domain) + '/access_keys.json'
        save_json(access_keys2, access_keys_filename)
        if not key_shortcuts.get(nickname):
            key_shortcuts[nickname] = access_keys2.copy()

    # redirect back from key shortcuts screen
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = \
            'http://' + onion_domain + users_path + '/' + default_timeline
    elif calling_domain.endswith('.i2p') and i2p_domain:
        origin_path_str = \
            'http://' + i2p_domain + users_path + '/' + default_timeline
    redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
    self.server.postreq_busy = False
    return
