__filename__ = "daemon_get_vcard.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
from daemon_utils import has_accept
from httpcodes import write2
from httpcodes import http_400
from httpcodes import http_404
from httpcodes import http_503
from httpheaders import set_headers
from utils import acct_dir
from utils import load_json
from utils import string_contains
from pgp import actor_to_vcard_xml
from pgp import actor_to_vcard


def show_vcard(self, base_dir: str, path: str, calling_domain: str,
               referer_domain: str, domain: str, translate: {}) -> bool:
    """Returns a vcard for the given account
    """
    if not has_accept(self, calling_domain):
        return False
    if not path.startswith('/users/'):
        return False
    if path.endswith('.vcf'):
        path = path.split('.vcf')[0]
        accept_str = 'text/vcard'
    else:
        accept_str = self.headers['Accept']
    vcard_mime_types = (
        'text/vcard',
        'application/vcard+xml'
    )
    if not string_contains(accept_str, vcard_mime_types):
        return False
    print('Downloading vcard ' + path)
    if path.startswith('/@'):
        if '/@/' not in path:
            path = path.replace('/@', '/users/', 1)
    if not path.startswith('/users/'):
        http_400(self)
        return True
    nickname = path.split('/users/')[1]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    if '?' in nickname:
        nickname = nickname.split('?')[0]
    if self.server.vcard_is_active:
        print('vcard is busy during request from ' + str(referer_domain))
        http_503(self)
        return True
    self.server.vcard_is_active = True
    actor_json = None
    actor_filename = \
        acct_dir(base_dir, nickname, domain) + '.json'
    if os.path.isfile(actor_filename):
        actor_json = load_json(actor_filename)
    if not actor_json:
        print('WARN: vcard actor not found ' + actor_filename)
        http_404(self, 3)
        self.server.vcard_is_active = False
        return True
    if 'application/vcard+xml' in accept_str:
        vcard_str = actor_to_vcard_xml(actor_json, domain, translate)
        header_type = 'application/vcard+xml; charset=utf-8'
    else:
        vcard_str = actor_to_vcard(actor_json, domain, translate)
        header_type = 'text/vcard; charset=utf-8'
    if vcard_str:
        msg = vcard_str.encode('utf-8')
        msglen = len(msg)
        set_headers(self, header_type, msglen,
                    None, calling_domain, True)
        write2(self, msg)
        print('vcard sent to ' + str(referer_domain))
        self.server.vcard_is_active = False
        return True
    print('WARN: vcard string not returned')
    http_404(self, 4)
    self.server.vcard_is_active = False
    return True
