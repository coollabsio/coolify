__filename__ = "daemon_post_hashtags.py"
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
from httpcodes import http_404
from httpheaders import redirect_headers
from utils import get_instance_url
from utils import get_nickname_from_actor
from flags import is_editor
from content import extract_text_fields_in_post
from blocking import is_blocked_hashtag
from filters import is_filtered
from categories import set_hashtag_category


def set_hashtag_category2(self, calling_domain: str, cookie: str,
                          path: str, base_dir: str,
                          domain: str, debug: bool,
                          system_language: str,
                          http_prefix: str,
                          domain_full: str,
                          onion_domain: str,
                          i2p_domain: str,
                          max_post_length: int) -> None:
    """On the screen after selecting a hashtag from the swarm, this sets
    the category for that tag
    """
    users_path = path.replace('/sethashtagcategory', '')
    hashtag = ''
    if '/tags/' not in users_path:
        # no hashtag is specified within the path
        http_404(self, 14)
        return
    hashtag = users_path.split('/tags/')[1].strip()
    hashtag = urllib.parse.unquote_plus(hashtag)
    if not hashtag:
        # no hashtag was given in the path
        http_404(self, 15)
        return
    hashtag_filename = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_filename):
        # the hashtag does not exist
        http_404(self, 16)
        return
    users_path = users_path.split('/tags/')[0]
    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path
    tag_screen_str = actor_str + '/tags/' + hashtag

    boundary = None
    if ' boundary=' in self.headers['Content-type']:
        boundary = self.headers['Content-type'].split('boundary=')[1]
        if ';' in boundary:
            boundary = boundary.split(';')[0]

    # get the nickname
    nickname = get_nickname_from_actor(actor_str)
    editor = None
    if nickname:
        editor = is_editor(base_dir, nickname)
    if not hashtag or not editor:
        if not nickname:
            print('WARN: nickname not found in ' + actor_str)
        else:
            print('WARN: nickname is not a moderator' + actor_str)
        redirect_headers(self, tag_screen_str, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    if self.headers.get('Content-length'):
        length = int(self.headers['Content-length'])

        # check that the POST isn't too large
        if length > max_post_length:
            print('Maximum links data length exceeded ' + str(length))
            redirect_headers(self, tag_screen_str, cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return

    try:
        # read the bytes of the http form POST
        post_bytes = self.rfile.read(length)
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: connection was reset while ' +
                  'reading bytes from http form POST')
        else:
            print('EX: error while reading bytes ' +
                  'from http form POST')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: failed to read bytes for POST, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if not boundary:
        if b'--LYNX' in post_bytes:
            boundary = '--LYNX'

    if boundary:
        # extract all of the text fields into a dict
        fields = \
            extract_text_fields_in_post(post_bytes, boundary, debug, None)

        if fields.get('hashtagCategory'):
            category_str = fields['hashtagCategory'].lower()
            if not is_blocked_hashtag(base_dir, category_str) and \
               not is_filtered(base_dir, nickname, domain, category_str,
                               system_language):
                set_hashtag_category(base_dir, hashtag,
                                     category_str, False, False)
        else:
            category_filename = base_dir + '/tags/' + hashtag + '.category'
            if os.path.isfile(category_filename):
                try:
                    os.remove(category_filename)
                except OSError:
                    print('EX: _set_hashtag_category unable to delete ' +
                          category_filename)

    # redirect back to the default timeline
    redirect_headers(self, tag_screen_str,
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False
