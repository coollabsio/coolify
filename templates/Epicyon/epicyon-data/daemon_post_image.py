__filename__ = "daemon_post_image.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import errno
from socket import error as SocketError
from httpcodes import http_404
from utils import acct_dir
from utils import get_image_extension_from_mime_type
from utils import binary_is_image


def receive_image_attachment(self, length: int, path: str, base_dir: str,
                             domain: str, debug: bool,
                             outbox_authenticated: bool) -> None:
    """Receives an image via POST
    """
    if not outbox_authenticated:
        if debug:
            print('DEBUG: unauthenticated attempt to ' +
                  'post image to outbox')
        self.send_response(403)
        self.end_headers()
        self.server.postreq_busy = False
        return
    path_users_section = path.split('/users/')[1]
    if '/' not in path_users_section:
        http_404(self, 12)
        self.server.postreq_busy = False
        return
    self.post_from_nickname = path_users_section.split('/')[0]
    accounts_dir = acct_dir(base_dir, self.post_from_nickname, domain)
    if not os.path.isdir(accounts_dir):
        http_404(self, 13)
        self.server.postreq_busy = False
        return

    try:
        media_bytes = self.rfile.read(length)
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST media_bytes ' +
                  'connection reset by peer')
        else:
            print('EX: POST media_bytes socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST media_bytes rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    media_filename_base = accounts_dir + '/upload'
    media_filename = \
        media_filename_base + '.' + \
        get_image_extension_from_mime_type(self.headers['Content-type'])
    if not binary_is_image(media_filename, media_bytes):
        print('WARN: _receive_image image binary is not recognized ' +
              media_filename)
    try:
        with open(media_filename, 'wb') as fp_av:
            fp_av.write(media_bytes)
    except OSError:
        print('EX: receive_image_attachment unable to write ' + media_filename)
    if debug:
        print('DEBUG: image saved to ' + media_filename)
    self.send_response(201)
    self.end_headers()
    self.server.postreq_busy = False
