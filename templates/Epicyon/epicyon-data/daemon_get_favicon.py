__filename__ = "daemon_get_favicon.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
import urllib.parse
from fitnessFunctions import fitness_performance
from httpheaders import set_headers_etag
from httpcodes import write2
from httpcodes import http_304
from httpcodes import http_404
from daemon_utils import has_accept
from daemon_utils import etag_exists
from utils import get_config_param
from utils import media_file_mime_type
from utils import binary_is_image


def get_favicon(self, calling_domain: str,
                base_dir: str, debug: bool,
                fav_filename: str,
                icons_cache: {}, domain_full: str) -> None:
    """Return the site favicon or default newswire favicon
    """
    fav_type = 'image/x-icon'
    if has_accept(self, calling_domain):
        if 'image/webp' in self.headers['Accept']:
            fav_type = 'image/webp'
            fav_filename = fav_filename.split('.')[0] + '.webp'
        if 'image/avif' in self.headers['Accept']:
            fav_type = 'image/avif'
            fav_filename = fav_filename.split('.')[0] + '.avif'
        if 'image/heic' in self.headers['Accept']:
            fav_type = 'image/heic'
            fav_filename = fav_filename.split('.')[0] + '.heic'
        if 'image/jxl' in self.headers['Accept']:
            fav_type = 'image/jxl'
            fav_filename = fav_filename.split('.')[0] + '.jxl'
    if not self.server.theme_name:
        self.theme_name = get_config_param(base_dir, 'theme')
    if not self.server.theme_name:
        self.server.theme_name = 'default'
    # custom favicon
    favicon_filename = \
        base_dir + '/theme/' + self.server.theme_name + \
        '/icons/' + fav_filename
    if not fav_filename.endswith('.ico'):
        if not os.path.isfile(favicon_filename):
            if fav_filename.endswith('.webp'):
                fav_filename = fav_filename.replace('.webp', '.ico')
            elif fav_filename.endswith('.avif'):
                fav_filename = fav_filename.replace('.avif', '.ico')
            elif fav_filename.endswith('.heic'):
                fav_filename = fav_filename.replace('.heic', '.ico')
            elif fav_filename.endswith('.jxl'):
                fav_filename = fav_filename.replace('.jxl', '.ico')
    if not os.path.isfile(favicon_filename):
        # default favicon
        favicon_filename = \
            base_dir + '/theme/default/icons/' + fav_filename
    if etag_exists(self, favicon_filename):
        # The file has not changed
        if debug:
            print('favicon icon has not changed: ' + calling_domain)
        http_304(self)
        return
    if icons_cache.get(fav_filename):
        fav_binary = icons_cache[fav_filename]
        set_headers_etag(self, favicon_filename,
                         fav_type,
                         fav_binary, None,
                         domain_full,
                         False, None)
        write2(self, fav_binary)
        if debug:
            print('Sent favicon from cache: ' + calling_domain)
        return
    if os.path.isfile(favicon_filename):
        fav_binary = None
        try:
            with open(favicon_filename, 'rb') as fp_fav:
                fav_binary = fp_fav.read()
        except OSError:
            print('EX: unable to read favicon ' + favicon_filename)
        if fav_binary:
            set_headers_etag(self, favicon_filename,
                             fav_type,
                             fav_binary, None,
                             domain_full,
                             False, None)
            write2(self, fav_binary)
            icons_cache[fav_filename] = fav_binary
            if debug:
                print('Sent favicon from file: ' + calling_domain)
            return
    if debug:
        print('favicon not sent: ' + calling_domain)
    http_404(self, 17)


def show_cached_favicon(self, referer_domain: str, path: str,
                        base_dir: str, getreq_start_time,
                        favicons_cache: {},
                        fitness: {}, debug: bool) -> None:
    """Shows a favicon image obtained from the cache
    """
    fav_file = path.replace('/favicons/', '')
    fav_filename = base_dir + urllib.parse.unquote_plus(path)
    print('showCachedFavicon: ' + fav_filename)
    if favicons_cache.get(fav_file):
        media_binary = favicons_cache[fav_file]
        mime_type = media_file_mime_type(fav_filename)
        set_headers_etag(self, fav_filename,
                         mime_type,
                         media_binary, None,
                         referer_domain,
                         False, None)
        write2(self, media_binary)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_show_cached_favicon2', debug)
        return
    if not os.path.isfile(fav_filename):
        http_404(self, 44)
        return
    if etag_exists(self, fav_filename):
        # The file has not changed
        http_304(self)
        return
    media_binary = None
    try:
        with open(fav_filename, 'rb') as fp_av:
            media_binary = fp_av.read()
    except OSError:
        print('EX: unable to read cached favicon ' + fav_filename)
    if media_binary:
        if binary_is_image(fav_filename, media_binary):
            mime_type = media_file_mime_type(fav_filename)
            set_headers_etag(self, fav_filename,
                             mime_type,
                             media_binary, None,
                             referer_domain,
                             False, None)
            write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', '_show_cached_favicon', debug)
            favicons_cache[fav_file] = media_binary
            return
        else:
            print('WARN: favicon is not an image ' + fav_filename)
    http_404(self, 45)
