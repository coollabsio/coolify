__filename__ = "daemon_get_images.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
import datetime
import time
from shutil import copyfile
from media import path_is_video
from media import path_is_transcript
from media import path_is_audio
from httpcodes import write2
from httpcodes import http_304
from httpcodes import http_404
from httpheaders import set_headers_etag
from utils import data_dir
from utils import get_nickname_from_actor
from utils import media_file_mime_type
from utils import get_image_mime_type
from utils import get_image_extensions
from utils import acct_dir
from flags import is_image_file
from daemon_utils import etag_exists
from fitnessFunctions import fitness_performance
from person import save_person_qrcode


def show_avatar_or_banner(self, referer_domain: str, path: str,
                          base_dir: str, domain: str,
                          getreq_start_time, fitness: {},
                          debug: bool) -> bool:
    """Shows an avatar or banner or profile background image
    """
    if '/users/' not in path:
        if '/system/accounts/avatars/' not in path and \
           '/system/accounts/headers/' not in path and \
           '/accounts/avatars/' not in path and \
           '/accounts/headers/' not in path:
            return False
    if not is_image_file(path):
        return False
    if '/system/accounts/avatars/' in path:
        avatar_str = path.split('/system/accounts/avatars/')[1]
    elif '/accounts/avatars/' in path:
        avatar_str = path.split('/accounts/avatars/')[1]
    elif '/system/accounts/headers/' in path:
        avatar_str = path.split('/system/accounts/headers/')[1]
    elif '/accounts/headers/' in path:
        avatar_str = path.split('/accounts/headers/')[1]
    else:
        avatar_str = path.split('/users/')[1]
    if not ('/' in avatar_str and '.temp.' not in path):
        return False
    avatar_nickname = avatar_str.split('/')[0]
    avatar_file = avatar_str.split('/')[1]
    avatar_file_ext = avatar_file.split('.')[-1]
    # remove any numbers, eg. avatar123.png becomes avatar.png
    if avatar_file.startswith('avatar'):
        avatar_file = 'avatar.' + avatar_file_ext
    elif avatar_file.startswith('banner'):
        avatar_file = 'banner.' + avatar_file_ext
    elif avatar_file.startswith('search_banner'):
        avatar_file = 'search_banner.' + avatar_file_ext
    elif avatar_file.startswith('image'):
        avatar_file = 'image.' + avatar_file_ext
    elif avatar_file.startswith('left_col_image'):
        avatar_file = 'left_col_image.' + avatar_file_ext
    elif avatar_file.startswith('right_col_image'):
        avatar_file = 'right_col_image.' + avatar_file_ext
    elif avatar_file.startswith('watermark_image'):
        avatar_file = 'watermark_image.' + avatar_file_ext
    avatar_filename = \
        acct_dir(base_dir, avatar_nickname, domain) + '/' + avatar_file
    if not os.path.isfile(avatar_filename):
        original_ext = avatar_file_ext
        original_avatar_file = avatar_file
        alt_ext = get_image_extensions()
        alt_found = False
        for alt in alt_ext:
            if alt == original_ext:
                continue
            avatar_file = \
                original_avatar_file.replace('.' + original_ext,
                                             '.' + alt)
            avatar_filename = \
                acct_dir(base_dir, avatar_nickname, domain) + \
                '/' + avatar_file
            if os.path.isfile(avatar_filename):
                alt_found = True
                break
        if not alt_found:
            return False
    if etag_exists(self, avatar_filename):
        # The file has not changed
        http_304(self)
        return True

    avatar_tm = os.path.getmtime(avatar_filename)
    last_modified_time = \
        datetime.datetime.fromtimestamp(avatar_tm, datetime.timezone.utc)
    last_modified_time_str = \
        last_modified_time.strftime('%a, %d %b %Y %H:%M:%S GMT')

    media_image_type = get_image_mime_type(avatar_file)
    media_binary = None
    try:
        with open(avatar_filename, 'rb') as fp_av:
            media_binary = fp_av.read()
    except OSError:
        print('EX: unable to read avatar ' + avatar_filename)
    if media_binary:
        set_headers_etag(self, avatar_filename, media_image_type,
                         media_binary, None,
                         referer_domain, True,
                         last_modified_time_str)
        write2(self, media_binary)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_avatar_or_banner',
                        debug)
    return True


def show_cached_avatar(self, referer_domain: str, path: str,
                       base_dir: str, getreq_start_time,
                       fitness: {}, debug: bool) -> None:
    """Shows an avatar image obtained from the cache
    """
    media_filename = base_dir + '/cache' + path
    if os.path.isfile(media_filename):
        if etag_exists(self, media_filename):
            # The file has not changed
            http_304(self)
            return
        media_binary = None
        try:
            with open(media_filename, 'rb') as fp_av:
                media_binary = fp_av.read()
        except OSError:
            print('EX: unable to read cached avatar ' + media_filename)
        if media_binary:
            mime_type = media_file_mime_type(media_filename)
            set_headers_etag(self, media_filename,
                             mime_type,
                             media_binary, None,
                             referer_domain,
                             False, None)
            write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_cached_avatar',
                                debug)
            return
    http_404(self, 46)


def show_help_screen_image(self, path: str,
                           base_dir: str, getreq_start_time,
                           theme_name: str, domain_full: str,
                           fitness: {}, debug: bool) -> None:
    """Shows a help screen image
    """
    if not is_image_file(path):
        return
    media_str = path.split('/helpimages/')[1]
    if '/' not in media_str:
        if not theme_name:
            theme = 'default'
        else:
            theme = theme_name
        icon_filename = media_str
    else:
        theme = media_str.split('/')[0]
        icon_filename = media_str.split('/')[1]
    media_filename = \
        base_dir + '/theme/' + theme + '/helpimages/' + icon_filename
    # if there is no theme-specific help image then use the default one
    if not os.path.isfile(media_filename):
        media_filename = \
            base_dir + '/theme/default/helpimages/' + icon_filename
    if etag_exists(self, media_filename):
        # The file has not changed
        http_304(self)
        return
    if os.path.isfile(media_filename):
        media_binary = None
        try:
            with open(media_filename, 'rb') as fp_av:
                media_binary = fp_av.read()
        except OSError:
            print('EX: unable to read help image ' + media_filename)
        if media_binary:
            mime_type = media_file_mime_type(media_filename)
            set_headers_etag(self, media_filename,
                             mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_help_screen_image',
                            debug)
        return
    http_404(self, 43)


def show_manual_image(self, path: str,
                      base_dir: str, getreq_start_time,
                      icons_cache: {}, domain_full: str,
                      fitness: {}, debug: bool) -> None:
    """Shows an image within the manual
    """
    image_filename = path.split('/', 1)[1]
    if '/' in image_filename:
        http_404(self, 41)
        return
    media_filename = \
        base_dir + '/manual/' + image_filename
    if etag_exists(self, media_filename):
        # The file has not changed
        http_304(self)
        return
    if icons_cache.get(media_filename):
        media_binary = icons_cache[media_filename]
        mime_type_str = media_file_mime_type(media_filename)
        set_headers_etag(self, media_filename,
                         mime_type_str,
                         media_binary, None,
                         domain_full,
                         False, None)
        write2(self, media_binary)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_manual_image',
                            debug)
        return
    if os.path.isfile(media_filename):
        media_binary = None
        try:
            with open(media_filename, 'rb') as fp_av:
                media_binary = fp_av.read()
        except OSError:
            print('EX: unable to read manual image ' +
                  media_filename)
        if media_binary:
            mime_type = media_file_mime_type(media_filename)
            set_headers_etag(self, media_filename,
                             mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
            icons_cache[media_filename] = media_binary
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_manual_image',
                            debug)
        return
    http_404(self, 42)


def show_specification_image(self, path: str,
                             base_dir: str, getreq_start_time,
                             icons_cache: {}, domain_full: str,
                             fitness: {}, debug: bool) -> None:
    """Shows an image within the ActivityPub specification document
    """
    image_filename = path.split('/', 1)[1]
    if '/' in image_filename:
        http_404(self, 39)
        return
    media_filename = \
        base_dir + '/specification/' + image_filename
    if etag_exists(self, media_filename):
        # The file has not changed
        http_304(self)
        return
    if icons_cache.get(media_filename):
        media_binary = icons_cache[media_filename]
        mime_type_str = media_file_mime_type(media_filename)
        set_headers_etag(self, media_filename,
                         mime_type_str,
                         media_binary, None,
                         domain_full,
                         False, None)
        write2(self, media_binary)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_specification_image',
                            debug)
        return
    if os.path.isfile(media_filename):
        media_binary = None
        try:
            with open(media_filename, 'rb') as fp_av:
                media_binary = fp_av.read()
        except OSError:
            print('EX: unable to read specification image ' +
                  media_filename)
        if media_binary:
            mime_type = media_file_mime_type(media_filename)
            set_headers_etag(self, media_filename,
                             mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
            icons_cache[media_filename] = media_binary
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_specification_image',
                            debug)
        return
    http_404(self, 40)


def show_share_image(self, path: str,
                     base_dir: str, getreq_start_time,
                     domain_full: str, fitness: {},
                     debug: bool) -> bool:
    """Show a shared item image
    """
    if not is_image_file(path):
        http_404(self, 101)
        return True

    media_str = path.split('/sharefiles/')[1]
    media_filename = base_dir + '/sharefiles/' + media_str
    if not os.path.isfile(media_filename):
        http_404(self, 102)
        return True

    if etag_exists(self, media_filename):
        # The file has not changed
        http_304(self)
        return True

    media_file_type = get_image_mime_type(media_filename)
    media_binary = None
    try:
        with open(media_filename, 'rb') as fp_av:
            media_binary = fp_av.read()
    except OSError:
        print('EX: unable to read binary ' + media_filename)
    if media_binary:
        set_headers_etag(self, media_filename,
                         media_file_type,
                         media_binary, None,
                         domain_full,
                         False, None)
        write2(self, media_binary)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_share_image',
                        debug)
    return True


def show_icon(self, path: str,
              base_dir: str, getreq_start_time,
              theme_name: str,
              icons_cache: {}, domain_full: str,
              fitness: {}, debug: bool) -> None:
    """Shows an icon
    """
    if not path.endswith('.png'):
        http_404(self, 37)
        return
    media_str = path.split('/icons/')[1]
    if '/' not in media_str:
        if not theme_name:
            theme = 'default'
        else:
            theme = theme_name
        icon_filename = media_str
    else:
        theme = media_str.split('/')[0]
        icon_filename = media_str.split('/')[1]
    media_filename = \
        base_dir + '/theme/' + theme + '/icons/' + icon_filename
    if etag_exists(self, media_filename):
        # The file has not changed
        http_304(self)
        return
    if icons_cache.get(media_str):
        media_binary = icons_cache[media_str]
        mime_type_str = media_file_mime_type(media_filename)
        set_headers_etag(self, media_filename,
                         mime_type_str,
                         media_binary, None,
                         domain_full,
                         False, None)
        write2(self, media_binary)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_icon', debug)
        return
    if os.path.isfile(media_filename):
        media_binary = None
        try:
            with open(media_filename, 'rb') as fp_av:
                media_binary = fp_av.read()
        except OSError:
            print('EX: unable to read icon image ' + media_filename)
        if media_binary:
            mime_type = media_file_mime_type(media_filename)
            set_headers_etag(self, media_filename,
                             mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
            icons_cache[media_str] = media_binary
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_icon', debug)
        return
    http_404(self, 38)


def show_media(self, path: str, base_dir: str,
               getreq_start_time, fitness: {},
               debug: bool) -> None:
    """Returns a media file
    """
    if is_image_file(path) or \
       path_is_video(path) or \
       path_is_transcript(path) or \
       path_is_audio(path):
        media_str = path.split('/media/')[1]
        media_filename = base_dir + '/media/' + media_str
        if os.path.isfile(media_filename):
            if etag_exists(self, media_filename):
                # The file has not changed
                http_304(self)
                return

            media_file_type = media_file_mime_type(media_filename)

            media_tm = os.path.getmtime(media_filename)
            last_modified_time = \
                datetime.datetime.fromtimestamp(media_tm,
                                                datetime.timezone.utc)
            last_modified_time_str = \
                last_modified_time.strftime('%a, %d %b %Y %H:%M:%S GMT')

            if media_filename.endswith('.vtt'):
                media_transcript = None
                try:
                    with open(media_filename, 'r',
                              encoding='utf-8') as fp_vtt:
                        media_transcript = fp_vtt.read()
                        media_file_type = 'text/vtt; charset=utf-8'
                except OSError:
                    print('EX: unable to read media binary ' +
                          media_filename)
                if media_transcript:
                    media_transcript = media_transcript.encode('utf-8')
                    set_headers_etag(self, media_filename, media_file_type,
                                     media_transcript, None,
                                     None, True,
                                     last_modified_time_str)
                    write2(self, media_transcript)
                    fitness_performance(getreq_start_time,
                                        fitness,
                                        '_GET', 'show_media',
                                        debug)
                    return
                http_404(self, 32)
                return

            media_binary = None
            try:
                with open(media_filename, 'rb') as fp_av:
                    media_binary = fp_av.read()
            except OSError:
                print('EX: unable to read media binary ' + media_filename)
            if media_binary:
                set_headers_etag(self, media_filename, media_file_type,
                                 media_binary, None,
                                 None, True,
                                 last_modified_time_str)
                write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_media', debug)
            return
    http_404(self, 33)


def show_qrcode(self, calling_domain: str, path: str,
                base_dir: str, domain: str, domain_full: str,
                onion_domain: str, i2p_domain: str,
                port: int, getreq_start_time,
                fitness: {}, debug: bool) -> bool:
    """Shows a QR code for an account
    """
    nickname = get_nickname_from_actor(path)
    if not nickname:
        http_404(self, 93)
        return True
    if onion_domain:
        qrcode_domain = onion_domain
        port = 80
    elif i2p_domain:
        qrcode_domain = i2p_domain
        port = 80
    else:
        qrcode_domain = domain
    save_person_qrcode(base_dir, nickname, domain, qrcode_domain, port)
    qr_filename = \
        acct_dir(base_dir, nickname, domain) + '/qrcode.png'
    if os.path.isfile(qr_filename):
        if etag_exists(self, qr_filename):
            # The file has not changed
            http_304(self)
            return True

        tries = 0
        media_binary = None
        while tries < 5:
            try:
                with open(qr_filename, 'rb') as fp_av:
                    media_binary = fp_av.read()
                    break
            except OSError as ex:
                print('EX: _show_qrcode ' + str(tries) + ' ' + str(ex))
                time.sleep(1)
                tries += 1
        if media_binary:
            mime_type = media_file_mime_type(qr_filename)
            set_headers_etag(self, qr_filename, mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_qrcode',
                                debug)
            return True
    http_404(self, 94)
    return True


def search_screen_banner(self, path: str,
                         base_dir: str, domain: str,
                         getreq_start_time,
                         domain_full: str,
                         fitness: {}, debug: bool) -> bool:
    """Shows a banner image on the search screen
    """
    nickname = get_nickname_from_actor(path)
    if not nickname:
        http_404(self, 95)
        return True
    banner_filename = \
        acct_dir(base_dir, nickname, domain) + '/search_banner.png'
    if not os.path.isfile(banner_filename):
        if os.path.isfile(base_dir + '/theme/default/search_banner.png'):
            copyfile(base_dir + '/theme/default/search_banner.png',
                     banner_filename)
    if os.path.isfile(banner_filename):
        if etag_exists(self, banner_filename):
            # The file has not changed
            http_304(self)
            return True

        tries = 0
        media_binary = None
        while tries < 5:
            try:
                with open(banner_filename, 'rb') as fp_av:
                    media_binary = fp_av.read()
                    break
            except OSError as ex:
                print('EX: _search_screen_banner ' +
                      str(tries) + ' ' + str(ex))
                time.sleep(1)
                tries += 1
        if media_binary:
            mime_type = media_file_mime_type(banner_filename)
            set_headers_etag(self, banner_filename, mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'search_screen_banner',
                                debug)
            return True
    http_404(self, 96)
    return True


def column_image(self, side: str, path: str, base_dir: str, domain: str,
                 getreq_start_time, domain_full: str,
                 fitness: {}, debug: bool) -> bool:
    """Shows an image at the top of the left/right column
    """
    nickname = get_nickname_from_actor(path)
    if not nickname:
        http_404(self, 97)
        return True
    banner_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + \
        side + '_col_image.png'
    if os.path.isfile(banner_filename):
        if etag_exists(self, banner_filename):
            # The file has not changed
            http_304(self)
            return True

        tries = 0
        media_binary = None
        while tries < 5:
            try:
                with open(banner_filename, 'rb') as fp_av:
                    media_binary = fp_av.read()
                    break
            except OSError as ex:
                print('EX: _column_image ' + str(tries) + ' ' + str(ex))
                time.sleep(1)
                tries += 1
        if media_binary:
            mime_type = media_file_mime_type(banner_filename)
            set_headers_etag(self, banner_filename, mime_type,
                             media_binary, None,
                             domain_full,
                             False, None)
            write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'column_image ' + side,
                                debug)
            return True
    http_404(self, 98)
    return True


def show_default_profile_background(self, base_dir: str, theme_name: str,
                                    getreq_start_time,
                                    domain_full: {},
                                    fitness: {}, debug: bool) -> bool:
    """If a background image is missing after searching for a handle
    then substitute this image
    """
    image_extensions = get_image_extensions()
    for ext in image_extensions:
        bg_filename = \
            base_dir + '/theme/' + theme_name + '/image.' + ext
        if os.path.isfile(bg_filename):
            if etag_exists(self, bg_filename):
                # The file has not changed
                http_304(self)
                return True

            tries = 0
            bg_binary = None
            while tries < 5:
                try:
                    with open(bg_filename, 'rb') as fp_av:
                        bg_binary = fp_av.read()
                        break
                except OSError as ex:
                    print('EX: _show_default_profile_background ' +
                          str(tries) + ' ' + str(ex))
                    time.sleep(1)
                    tries += 1
            if bg_binary:
                if ext == 'jpg':
                    ext = 'jpeg'
                set_headers_etag(self, bg_filename,
                                 'image/' + ext,
                                 bg_binary, None,
                                 domain_full,
                                 False, None)
                write2(self, bg_binary)
                fitness_performance(getreq_start_time, fitness,
                                    '_GET',
                                    'show_default_profile_background',
                                    debug)
                return True
            break

    http_404(self, 100)
    return True


def show_background_image(self, path: str,
                          base_dir: str, getreq_start_time,
                          domain_full: str, fitness: {},
                          debug: bool) -> bool:
    """Show a background image
    """
    image_extensions = get_image_extensions()
    for ext in image_extensions:
        for bg_im in ('follow', 'options', 'login', 'welcome'):
            # follow screen background image
            if path.endswith('/' + bg_im + '-background.' + ext):
                bg_filename = \
                    data_dir(base_dir) + '/' + \
                    bg_im + '-background.' + ext
                if os.path.isfile(bg_filename):
                    if etag_exists(self, bg_filename):
                        # The file has not changed
                        http_304(self)
                        return True

                    tries = 0
                    bg_binary = None
                    while tries < 5:
                        try:
                            with open(bg_filename, 'rb') as fp_av:
                                bg_binary = fp_av.read()
                                break
                        except OSError as ex:
                            print('EX: _show_background_image ' +
                                  str(tries) + ' ' + str(ex))
                            time.sleep(1)
                            tries += 1
                    if bg_binary:
                        if ext == 'jpg':
                            ext = 'jpeg'
                        set_headers_etag(self, bg_filename,
                                         'image/' + ext,
                                         bg_binary, None,
                                         domain_full,
                                         False, None)
                        write2(self, bg_binary)
                        fitness_performance(getreq_start_time, fitness,
                                            '_GET',
                                            'show_background_image',
                                            debug)
                        return True
    http_404(self, 99)
    return True


def show_emoji(self, path: str,
               base_dir: str, getreq_start_time,
               domain_full: {}, fitness: {},
               debug: bool) -> None:
    """Returns an emoji image
    """
    if is_image_file(path):
        emoji_str = path.split('/emoji/')[1]
        emoji_filename = base_dir + '/emoji/' + emoji_str
        if not os.path.isfile(emoji_filename):
            emoji_filename = base_dir + '/emojicustom/' + emoji_str
        if os.path.isfile(emoji_filename):
            if etag_exists(self, emoji_filename):
                # The file has not changed
                http_304(self)
                return

            media_image_type = get_image_mime_type(emoji_filename)
            media_binary = None
            try:
                with open(emoji_filename, 'rb') as fp_av:
                    media_binary = fp_av.read()
            except OSError:
                print('EX: unable to read emoji image ' + emoji_filename)
            if media_binary:
                set_headers_etag(self, emoji_filename,
                                 media_image_type,
                                 media_binary, None,
                                 domain_full,
                                 False, None)
                write2(self, media_binary)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_emoji', debug)
            return
    http_404(self, 36)
