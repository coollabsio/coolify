__filename__ = "daemon_head.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon"

import os
import datetime
from hashlib import md5
from flags import is_image_file
from utils import media_file_mime_type
from utils import data_dir
from utils import string_contains
from utils import decoded_host
from utils import check_bad_path
from httpcodes import http_400
from httpcodes import http_404
from httpheaders import set_headers_head
from media import path_is_video
from media import path_is_audio
from daemon_utils import get_user_agent
from daemon_utils import log_epicyon_instances


def daemon_http_head(self) -> None:
    """ HTTP HEAD
    """
    if self.server.starting_daemon:
        return
    if check_bad_path(self.path):
        http_400(self)
        return

    calling_domain = self.server.domain_full

    ua_str = get_user_agent(self)

    if ua_str:
        if 'Epicyon/' in ua_str:
            log_epicyon_instances(self.server.base_dir, ua_str,
                                  self.server.known_epicyon_instances)

    if self.headers.get('Host'):
        calling_domain = decoded_host(self.headers['Host'])
        if self.server.onion_domain:
            if calling_domain not in (self.server.domain,
                                      self.server.domain_full,
                                      self.server.onion_domain):
                print('HEAD domain blocked: ' + calling_domain)
                http_400(self)
                return
        else:
            if calling_domain not in (self.server.domain,
                                      self.server.domain_full):
                print('HEAD domain blocked: ' + calling_domain)
                http_400(self)
                return

    check_path = self.path
    etag = None
    file_length = -1
    last_modified_time_str = None

    if string_contains(self.path,
                       ('/media/', '/accounts/avatars/',
                        '/accounts/headers/')):
        if is_image_file(self.path) or \
           path_is_video(self.path) or \
           path_is_audio(self.path):
            if '/media/' in self.path:
                media_str = self.path.split('/media/')[1]
                media_filename = \
                    self.server.base_dir + '/media/' + media_str
            elif '/accounts/avatars/' in self.path:
                avatar_file = self.path.split('/accounts/avatars/')[1]
                if '/' not in avatar_file:
                    http_404(self, 149)
                    return
                nickname = avatar_file.split('/')[0]
                avatar_file = avatar_file.split('/')[1]
                avatar_file_ext = avatar_file.split('.')[-1]
                # remove any numbers, eg. avatar123.png becomes avatar.png
                if avatar_file.startswith('avatar'):
                    avatar_file = 'avatar.' + avatar_file_ext
                media_filename = \
                    data_dir(self.server.base_dir) + '/' + \
                    nickname + '@' + self.server.domain + '/' + \
                    avatar_file
            else:
                banner_file = self.path.split('/accounts/headers/')[1]
                if '/' not in banner_file:
                    http_404(self, 150)
                    return
                nickname = banner_file.split('/')[0]
                banner_file = banner_file.split('/')[1]
                banner_file_ext = banner_file.split('.')[-1]
                # remove any numbers, eg. banner123.png becomes banner.png
                if banner_file.startswith('banner'):
                    banner_file = 'banner.' + banner_file_ext
                media_filename = \
                    data_dir(self.server.base_dir) + '/' + \
                    nickname + '@' + self.server.domain + '/' + \
                    banner_file

            if os.path.isfile(media_filename):
                check_path = media_filename
                file_length = os.path.getsize(media_filename)
                media_tm = os.path.getmtime(media_filename)
                last_modified_time = \
                    datetime.datetime.fromtimestamp(media_tm,
                                                    datetime.timezone.utc)
                time_format_str = '%a, %d %b %Y %H:%M:%S GMT'
                last_modified_time_str = \
                    last_modified_time.strftime(time_format_str)
                media_tag_filename = media_filename + '.etag'
                if os.path.isfile(media_tag_filename):
                    try:
                        with open(media_tag_filename, 'r',
                                  encoding='utf-8') as fp_efile:
                            etag = fp_efile.read()
                    except OSError:
                        print('EX: do_HEAD unable to read ' +
                              media_tag_filename)
                else:
                    media_binary = None
                    try:
                        with open(media_filename, 'rb') as fp_av:
                            media_binary = fp_av.read()
                    except OSError:
                        print('EX: unable to read media binary ' +
                              media_filename)
                    if media_binary:
                        etag = md5(media_binary).hexdigest()  # nosec
                        try:
                            with open(media_tag_filename, 'w+',
                                      encoding='utf-8') as fp_efile:
                                fp_efile.write(etag)
                        except OSError:
                            print('EX: do_HEAD unable to write ' +
                                  media_tag_filename)
            else:
                http_404(self, 151)
                return

    media_file_type = media_file_mime_type(check_path)
    set_headers_head(self, media_file_type, file_length,
                     etag, calling_domain, False,
                     last_modified_time_str)
