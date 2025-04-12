__filename__ = "httpheaders.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
import urllib.parse
from hashlib import md5
from utils import string_contains
from utils import get_instance_url
from utils import data_dir
from utils import save_json
from utils import remove_id_ending
from utils import has_object_dict
from utils import get_attributed_to


def login_headers(self, file_format: str, length: int,
                  calling_domain: str) -> None:
    self.send_response(200)
    self.send_header('Content-type', file_format)
    self.send_header('Content-Length', str(length))
    self.send_header('Host', calling_domain)
    self.send_header('WWW-Authenticate',
                     'title="Login to Epicyon", Basic realm="epicyon"')
    self.send_header('Sec-GPC', '1')
    self.end_headers()


def logout_headers(self, file_format: str, length: int,
                   calling_domain: str) -> None:
    self.send_response(200)
    self.send_header('Content-type', file_format)
    self.send_header('Content-Length', str(length))
    self.send_header('Set-Cookie', 'epicyon=; SameSite=Strict')
    self.send_header('Host', calling_domain)
    self.send_header('WWW-Authenticate',
                     'title="Login to Epicyon", Basic realm="epicyon"')
    self.end_headers()


def _quoted_redirect(redirect: str) -> str:
    """hashtag screen urls sometimes contain non-ascii characters which
    need to be url encoded
    """
    if '/tags/' not in redirect:
        return redirect
    last_str = redirect.split('/')[-1]
    return redirect.replace('/' + last_str, '/' +
                            urllib.parse.quote_plus(last_str))


def logout_redirect(self, redirect: str, calling_domain: str) -> None:
    if '://' not in redirect:
        redirect = get_instance_url(calling_domain,
                                    self.server.http_prefix,
                                    self.server.domain_full,
                                    self.server.onion_domain,
                                    self.server.i2p_domain) + \
                                    redirect
        print('WARN: redirect was not an absolute url, changed to ' +
              redirect + ' ' + calling_domain)

    quot_redirect = _quoted_redirect(redirect)
    self.send_response(303)
    self.send_header('Set-Cookie', 'epicyon=; SameSite=Strict')
    self.send_header('Location', quot_redirect)
    self.send_header('Host', calling_domain)
    self.send_header('X-AP-Instance-ID', self.server.instance_id)
    self.send_header('Content-Length', '0')
    self.send_header('Sec-GPC', '1')
    self.end_headers()


def redirect_headers(self, redirect: str, cookie: str,
                     calling_domain: str,
                     code: int) -> None:
    if '://' not in redirect:
        redirect = \
            get_instance_url(calling_domain,
                             self.server.http_prefix,
                             self.server.domain_full,
                             self.server.onion_domain,
                             self.server.i2p_domain) + redirect
        print('WARN: redirect was not an absolute url, changed to ' +
              redirect)

    self.send_response(code)

    if code != 303:
        print('Redirect headers: ' + str(code))

    if cookie:
        cookie_str = cookie.replace('SET:', '').strip()
        if 'HttpOnly;' not in cookie_str:
            if self.server.http_prefix == 'https':
                cookie_str += '; Secure'
            cookie_str += '; HttpOnly; SameSite=Strict'
        if not cookie.startswith('SET:'):
            self.send_header('Cookie', cookie_str)
        else:
            self.send_header('Set-Cookie', cookie_str)
    quot_redirect = _quoted_redirect(redirect)
    self.send_header('Location', quot_redirect)
    self.send_header('Host', calling_domain)
    self.send_header('X-AP-Instance-ID', self.server.instance_id)
    self.send_header('Content-Length', '0')
    self.send_header('Sec-GPC', '1')
    self.end_headers()


def clear_login_details(self, nickname: str, calling_domain: str) -> None:
    """Clears login details for the given account
    """
    # remove any token
    if self.server.tokens.get(nickname):
        del self.server.tokens_lookup[self.server.tokens[nickname]]
        del self.server.tokens[nickname]
    redirect_headers(self, self.server.http_prefix + '://' +
                     self.server.domain_full + '/login',
                     'epicyon=; SameSite=Strict',
                     calling_domain, 303)


def _set_headers_base(self, file_format: str, length: int, cookie: str,
                      calling_domain: str, permissive: bool) -> None:
    self.send_response(200)
    self.send_header('Content-type', file_format)
    if string_contains(file_format, ('image/', 'audio/', 'video/')):
        cache_control = 'public, max-age=84600, immutable'
        self.send_header('Cache-Control', cache_control)
    else:
        self.send_header('Cache-Control', 'public')
    self.send_header('Origin', self.server.domain_full)
    if length > -1:
        self.send_header('Content-Length', str(length))
    if calling_domain:
        self.send_header('Host', calling_domain)
    if permissive:
        self.send_header('Access-Control-Allow-Origin', '*')
        return
    self.send_header('X-Robots-Tag', 'noai, noimageai, GPC')
    self.send_header('X-AP-Instance-ID', self.server.instance_id)
    self.send_header('X-Clacks-Overhead', self.server.clacks)
    self.send_header('Sec-GPC', '1')
    self.send_header('User-Agent',
                     'Epicyon/' + __version__ +
                     '; +' + self.server.http_prefix + '://' +
                     self.server.domain_full + '/')
    if cookie:
        cookie_str = cookie
        if 'HttpOnly;' not in cookie_str:
            if self.server.http_prefix == 'https':
                cookie_str += '; Secure'
            cookie_str += '; HttpOnly; SameSite=Strict'
        self.send_header('Cookie', cookie_str)


def set_headers(self, file_format: str, length: int, cookie: str,
                calling_domain: str, permissive: bool) -> None:
    _set_headers_base(self, file_format, length, cookie, calling_domain,
                      permissive)
    self.end_headers()


def set_html_post_headers(self, length: int, cookie: str,
                          calling_domain: str, permissive: bool,
                          post_json_object: {}) -> None:
    """A HTML format post representing an individual ActivityPub post
    """
    _set_headers_base(self, 'text/html', length, cookie, calling_domain,
                      permissive)
    if post_json_object.get('id'):
        # Discover activitypub version of the html post
        # https://swicg.github.io/activitypub-html-discovery/#http-link-header
        # link to the activitypub post
        post_id = remove_id_ending(post_json_object['id'])
        ap_post_link = \
            '<' + post_id + '>; rel="alternate"; ' + \
            'type="application/activity+json"'
        self.send_header('Link', ap_post_link)

        # https://swicg.github.io/
        # activitypub-html-discovery/#http-link-header-1
        # link to the author's actor
        obj = post_json_object
        if has_object_dict(post_json_object):
            obj = post_json_object['object']
        if obj.get('attributedTo'):
            actor = get_attributed_to(obj['attributedTo'])
            if actor:
                ap_author_link = \
                    '<' + actor + '>; rel="author"; ' + \
                    'type="application/activity+json"'
                self.send_header('Link', ap_author_link)

    self.end_headers()


def set_headers_head(self, file_format: str, length: int, etag: str,
                     calling_domain: str, permissive: bool,
                     last_modified_time_str: str) -> None:
    _set_headers_base(self, file_format, length, None, calling_domain,
                      permissive)
    if etag:
        self.send_header('ETag', '"' + etag + '"')
    if last_modified_time_str:
        self.send_header('last-modified',
                         last_modified_time_str)
    self.end_headers()


def set_headers_etag(self, media_filename: str, file_format: str,
                     data, cookie: str, calling_domain: str,
                     permissive: bool, last_modified: str) -> None:
    datalen = len(data)
    _set_headers_base(self, file_format, datalen, cookie, calling_domain,
                      permissive)
    etag = None
    if os.path.isfile(media_filename + '.etag'):
        try:
            with open(media_filename + '.etag', 'r',
                      encoding='utf-8') as fp_media:
                etag = fp_media.read()
        except OSError:
            print('EX: _set_headers_etag ' +
                  'unable to read ' + media_filename + '.etag')
    if not etag:
        etag = md5(data).hexdigest()  # nosec
        try:
            with open(media_filename + '.etag', 'w+',
                      encoding='utf-8') as fp_media:
                fp_media.write(etag)
        except OSError:
            print('EX: _set_headers_etag ' +
                  'unable to write ' + media_filename + '.etag')
    # if etag:
    #     self.send_header('ETag', '"' + etag + '"')
    if last_modified:
        self.send_header('last-modified', last_modified)
    self.send_header('accept-ranges', 'bytes')
    self.end_headers()


def update_headers_catalog(base_dir: str, headers_catalog: {},
                           headers: {}) -> None:
    """Creates a catalog of headers
    This allows us to spot anything unexpected for later investigation
    """
    headers_catalog_fieldname = data_dir(base_dir) + '/headers_catalog.json'
    changed = False
    for fieldname, fieldvalue in headers.items():
        if fieldname in headers_catalog:
            continue
        if fieldname in ('cookie', 'Cookie'):
            fieldvalue = ""
        headers_catalog[fieldname] = fieldvalue
        changed = True

    if changed:
        save_json(headers_catalog, headers_catalog_fieldname)


def contains_suspicious_headers(headers: {}) -> bool:
    """returns true if the given headers contain something suspicious
    """
    if 'Shellshock' in headers or \
       'shellshock' in headers or \
       'think-lang' in headers or \
       'Think-lang' in headers:
        return True
    headers_str = str(headers)
    sus_strings = ('../../', 'index.php', 'passwd')
    if string_contains(headers_str, sus_strings):
        return True
    return False
