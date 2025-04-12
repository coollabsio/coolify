__filename__ = "session.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Session"

import os
import requests
import json
import errno
from socket import error as SocketError
from http.client import HTTPConnection
from flags import is_image_file
from flags import url_permitted
from utils import text_in_file
from utils import acct_dir
from utils import binary_is_image
from utils import image_mime_types_dict
from utils import detect_mitm
from utils import get_domain_from_actor
from httpsig import create_signed_header


def create_session(proxy_type: str):
    """ Creates a new session
    """
    session = None
    try:
        session = requests.session()
    except requests.exceptions.RequestException as exc:
        print('EX: requests error during create_session ' + str(exc))
        return None
    except SocketError as exc:
        if exc.errno == errno.ECONNRESET:
            print('EX: connection was reset during create_session ' +
                  str(exc))
        else:
            print('EX: socket error during create_session ' + str(exc))
        return None
    except ValueError as exc:
        print('EX: error during create_session ' + str(exc))
        return None
    if not session:
        return None
    session.max_redirects = 3
    if proxy_type == 'tor':
        session.proxies = {}
        session.proxies['http'] = 'socks5h://localhost:9050'
        session.proxies['https'] = 'socks5h://localhost:9050'
    elif proxy_type == 'i2p':
        session.proxies = {}
        session.proxies['http'] = 'socks5h://localhost:4447'
        session.proxies['https'] = 'socks5h://localhost:4447'
    elif proxy_type == 'gnunet':
        session.proxies = {}
        session.proxies['http'] = 'socks5h://localhost:7777'
        session.proxies['https'] = 'socks5h://localhost:7777'
    elif proxy_type in ('ipfs', 'ipns'):
        session.proxies = {}
        session.proxies['ipfs'] = 'socks5h://localhost:4001'
    # print('New session created with proxy ' + str(proxy_type))
    return session


def url_exists(session, url: str, timeout_sec: int = 3,
               http_prefix: str = 'https', domain: str = 'testdomain') -> bool:
    """Is the given url resolvable?
    """
    if not isinstance(url, str):
        print('url: ' + str(url))
        print('ERROR: url_exists failed, url should be a string')
        return False
    session_params = {}
    session_headers = {}
    session_headers['User-Agent'] = 'Epicyon/' + __version__
    if domain:
        session_headers['User-Agent'] += \
            '; +' + http_prefix + '://' + domain + '/'
    if '://' not in url:
        if url.startswith('/'):
            url = http_prefix + '://' + domain + url
    if not session:
        print('WARN: url_exists failed, no session specified')
        return True
    try:
        result = session.get(url, headers=session_headers,
                             params=session_params,
                             timeout=timeout_sec,
                             allow_redirects=True)
        if result:
            if result.status_code in (200, 304):
                return True
            print('url_exists for ' + url + ' returned ' +
                  str(result.status_code))
    except BaseException as exc:
        print('EX: url_exists GET failed ' + str(url) + ' ' + str(exc))
    return False


def get_resolved_url(session, url: str, timeout_sec: int = 20) -> {}:
    """returns the URL after redirections
    eg. https://osm.org/go/0G0dJ91-?m=&relation=62414
    becomes
    https://www.openstreetmap.org/?mlat=53.05289268493652
    &mlon=8.644180297851562#map=11/53.05289268493652/8.644180297851562
    """
    try:
        result = session.get(url, headers={},
                             params={}, timeout=timeout_sec,
                             allow_redirects=True)
        if result.url:
            if isinstance(result.url, str):
                if '://' in result.url:
                    return result.url
    except ValueError as exc:
        print('EX: get_resolved_url failed, url: ' +
              str(url) + ', ' + str(exc))
    except SocketError as exc:
        if exc.errno == errno.ECONNRESET:
            print('EX: get_resolved_url failed, ' +
                  'connection was reset ' + str(exc))
    return None


def _get_json_request(session, url: str, session_headers: {},
                      session_params: {}, timeout_sec: int,
                      quiet: bool, debug: bool,
                      return_json: bool,
                      mitm_servers: []) -> {}:
    """http GET for json
    """
    try:
        result = session.get(url, headers=session_headers,
                             params=session_params, timeout=timeout_sec,
                             allow_redirects=True)
        mitm = False
        try:
            mitm = detect_mitm(result)
        except BaseException:
            pass
        url_domain, _ = get_domain_from_actor(url)
        if mitm:
            if url_domain:
                if url_domain not in mitm_servers:
                    mitm_servers.append(url_domain)
            if debug:
                print('DEBUG: _get_json_request MITM ' +
                      str(result.headers))
        else:
            if url_domain in mitm_servers:
                mitm_servers.remove(url_domain)

        if result.status_code != 200:
            if result.status_code == 401:
                print("WARN: get_json " + url + ' rejected by secure mode')
                return {
                    "error": 401
                }
            elif result.status_code == 403:
                print('WARN: get_json Forbidden url: ' + url)
                return {
                    "error": 403
                }
            elif result.status_code == 404:
                print('WARN: get_json Not Found url: ' + url)
                return {
                    "error": 404
                }
            elif result.status_code == 410:
                print('WARN: get_json no longer available url: ' + url)
                return {
                    "error": 410
                }
            elif result.status_code == 303:
                print('WARN: get_json redirect not permitted: ' + url)
                return {
                    "error": 303
                }
            elif result.status_code == 301:
                print('WARN: get_json moved permanently: ' + url)
                return {
                    "error": 301
                }
            else:
                session_headers2 = session_headers.copy()
                if session_headers2.get('Authorization'):
                    session_headers2['Authorization'] = 'REDACTED'
                print('WARN: get_json url: ' + url +
                      ' failed with error code ' +
                      str(result.status_code) +
                      ' headers: ' + str(session_headers2))
        if return_json:
            return result.json()
        return result.content
    except requests.exceptions.RequestException as exc:
        session_headers2 = session_headers.copy()
        if session_headers2.get('Authorization'):
            session_headers2['Authorization'] = 'REDACTED'
        if debug and not quiet:
            print('EX: get_json failed, url: ' + str(url) + ', ' +
                  'headers: ' + str(session_headers2) + ', ' +
                  'params: ' + str(session_params) + ', ' + str(exc))
    except ValueError as exc:
        session_headers2 = session_headers.copy()
        if session_headers2.get('Authorization'):
            session_headers2['Authorization'] = 'REDACTED'
        if debug and not quiet:
            print('EX: get_json failed2, url: ' + str(url) + ', ' +
                  'headers: ' + str(session_headers2) + ', ' +
                  'params: ' + str(session_params) + ', ' + str(exc))
    except SocketError as exc:
        if not quiet:
            if exc.errno == errno.ECONNRESET:
                print('EX: get_json failed, ' +
                      'connection was reset during get_json ' + str(exc))
    return None


def _get_json_signed(session, url: str, domain_full: str, session_headers: {},
                     session_params: {}, timeout_sec: int,
                     signing_priv_key_pem: str, quiet: bool,
                     debug: bool, mitm_servers: []) -> {}:
    """Authorized fetch - a signed version of GET
    """
    if not domain_full:
        if debug:
            print('No sending domain for signed GET')
        return None
    if '://' not in url:
        print('Invalid url: ' + url)
        return None
    http_prefix = url.split('://')[0]
    to_domain_full = url.split('://')[1]
    if '/' in to_domain_full:
        to_domain_full = to_domain_full.split('/')[0]

    if ':' in domain_full:
        domain = domain_full.split(':')[0]
        port = domain_full.split(':')[1]
    else:
        domain = domain_full
        if http_prefix == 'https':
            port = 443
        else:
            port = 80

    if ':' in to_domain_full:
        to_domain = to_domain_full.split(':')[0]
        to_port = to_domain_full.split(':')[1]
    else:
        to_domain = to_domain_full
        if http_prefix == 'https':
            to_port = 443
        else:
            to_port = 80

    if debug:
        print('Signed GET domain: ' + domain + ' ' + str(port))
        print('Signed GET to_domain: ' + to_domain + ' ' + str(to_port))
        print('Signed GET url: ' + url)
        print('Signed GET http_prefix: ' + http_prefix)
    message_str = ''
    with_digest = False
    if to_domain_full + '/' in url:
        path = '/' + url.split(to_domain_full + '/')[1]
    else:
        path = '/actor'
    content_type = 'application/activity+json'
    if session_headers.get('Accept'):
        content_type = session_headers['Accept']
    signature_header_json = \
        create_signed_header(None, signing_priv_key_pem, 'actor', domain, port,
                             to_domain, to_port, path, http_prefix,
                             with_digest, message_str, content_type)
    if debug:
        print('Signed GET signature_header_json ' + str(signature_header_json))
    # update the session headers from the signature headers
    session_headers['Host'] = signature_header_json['host']
    session_headers['Date'] = signature_header_json['date']
    session_headers['Accept'] = signature_header_json['accept']
    session_headers['Signature'] = signature_header_json['signature']
    session_headers['Content-Length'] = '0'
    if debug:
        print('Signed GET session_headers ' + str(session_headers))

    return_json = True
    if 'json' not in content_type:
        return_json = False
    return _get_json_request(session, url, session_headers,
                             session_params, timeout_sec, quiet,
                             debug, return_json, mitm_servers)


def get_json_valid(test_json: {}) -> bool:
    """Is the given get_json result valid?
    """
    if not test_json:
        return False
    if 'error' in test_json:
        return False
    return True


def get_json(signing_priv_key_pem: str,
             session, url: str, headers: {}, params: {}, debug: bool,
             mitm_servers: [],
             version: str = __version__, http_prefix: str = 'https',
             domain: str = 'testdomain',
             timeout_sec: int = 20, quiet: bool = False) -> {}:
    """Download some json
    """
    if not isinstance(url, str):
        if debug and not quiet:
            print('url: ' + str(url))
            print('ERROR: get_json failed, url should be a string')
        return None
    session_params = {}
    session_headers = {}
    if headers:
        session_headers = headers
    if params:
        session_params = params
    session_headers['User-Agent'] = 'Epicyon/' + version
    if domain:
        session_headers['User-Agent'] += \
            '; +' + http_prefix + '://' + domain + '/'
    if not session:
        if not quiet:
            print('WARN: get_json failed, no session specified for get_json')
        return None

    if debug:
        HTTPConnection.debuglevel = 1

    if signing_priv_key_pem:
        return _get_json_signed(session, url, domain,
                                session_headers, session_params,
                                timeout_sec, signing_priv_key_pem,
                                quiet, debug, mitm_servers)
    return _get_json_request(session, url, session_headers,
                             session_params, timeout_sec,
                             quiet, debug, True, mitm_servers)


def get_vcard(xml_format: bool,
              session, url: str, params: {}, debug: bool,
              version: str, http_prefix: str, domain: str,
              timeout_sec: int = 20, quiet: bool = False) -> {}:
    """Download a vcard
    """
    if not isinstance(url, str):
        if debug and not quiet:
            print('url: ' + str(url))
            print('ERROR: get_vcard failed, url should be a string')
        return None
    headers = {
        'Accept': 'text/vcard'
    }
    if xml_format:
        headers['Accept'] = 'application/vcard+xml'
    session_params = {}
    session_headers = {}
    if headers:
        session_headers = headers
    if params:
        session_params = params
    session_headers['User-Agent'] = 'Epicyon/' + version
    if domain:
        session_headers['User-Agent'] += \
            '; +' + http_prefix + '://' + domain + '/'
    if not session:
        if not quiet:
            print('WARN: get_vcard failed, no session specified for get_vcard')
        return None

    if debug:
        HTTPConnection.debuglevel = 1

    try:
        result = session.get(url, headers=session_headers,
                             params=session_params, timeout=timeout_sec,
                             allow_redirects=True)
        if result.status_code != 200:
            if result.status_code == 401:
                print("WARN: get_vcard " + url + ' rejected by secure mode')
            elif result.status_code == 403:
                print('WARN: get_vcard Forbidden url: ' + url)
            elif result.status_code == 404:
                print('WARN: get_vcard Not Found url: ' + url)
            elif result.status_code == 410:
                print('WARN: get_vcard no longer available url: ' + url)
            else:
                session_headers2 = session_headers.copy()
                if session_headers2.get('Authorization'):
                    session_headers2['Authorization'] = 'REDACTED'
                print('WARN: get_vcard url: ' + url +
                      ' failed with error code ' +
                      str(result.status_code) +
                      ' headers: ' + str(session_headers2))
        return result.content.decode('utf-8')
    except requests.exceptions.RequestException as ex:
        session_headers2 = session_headers.copy()
        if session_headers2.get('Authorization'):
            session_headers2['Authorization'] = 'REDACTED'
        if debug and not quiet:
            print('EX: get_vcard failed, url: ' + str(url) + ', ' +
                  'headers: ' + str(session_headers2) + ', ' +
                  'params: ' + str(session_params) + ', ' + str(ex))
    except ValueError as exc:
        session_headers2 = session_headers.copy()
        if session_headers2.get('Authorization'):
            session_headers2['Authorization'] = 'REDACTED'
        if debug and not quiet:
            print('EX: get_vcard failed, url: ' + str(url) + ', ' +
                  'headers: ' + str(session_headers2) + ', ' +
                  'params: ' + str(session_params) + ', ' + str(exc))
    except SocketError as exc:
        if not quiet:
            if exc.errno == errno.ECONNRESET:
                print('EX: get_vcard failed, ' +
                      'connection was reset during get_vcard ' + str(exc))
    return None


def download_html(signing_priv_key_pem: str,
                  session, url: str, headers: {}, params: {}, debug: bool,
                  version: str, http_prefix: str, domain: str,
                  mitm_servers: [],
                  timeout_sec: int = 20, quiet: bool = False) -> {}:
    """Download a html document
    """
    if not isinstance(url, str):
        if debug and not quiet:
            print('url: ' + str(url))
            print('ERROR: download_html failed, url should be a string')
        return None
    session_params = {}
    session_headers = {}
    if headers:
        session_headers = headers
    if params:
        session_params = params
    session_headers['Accept'] = 'text/html'
    session_headers['User-Agent'] = 'Epicyon/' + version
    if domain:
        session_headers['User-Agent'] += \
            '; +' + http_prefix + '://' + domain + '/'
    if not session:
        if not quiet:
            print('WARN: download_html failed, ' +
                  'no session specified for download_html')
        return None

    if debug:
        HTTPConnection.debuglevel = 1

    if signing_priv_key_pem:
        return _get_json_signed(session, url, domain,
                                session_headers, session_params,
                                timeout_sec, signing_priv_key_pem,
                                quiet, debug, mitm_servers)
    return _get_json_request(session, url, session_headers,
                             session_params, timeout_sec,
                             quiet, debug, False, mitm_servers)


def verify_html(session, url: str, debug: bool,
                version: str, http_prefix: str, nickname: str, domain: str,
                mitm_servers: [],
                timeout_sec: int = 20, quiet: bool = False) -> bool:
    """Verify that the handle for nickname@domain exists within the
    given url
    """
    if not url_exists(session, url, 3, http_prefix, domain):
        return False

    if '://' not in url:
        if url.startswith('/'):
            url = http_prefix + '://' + domain + url

    as_header = {
        'Accept': 'text/html'
    }
    verification_site_html = \
        download_html(None, session, url,
                      as_header, None, debug, version,
                      http_prefix, domain, mitm_servers,
                      timeout_sec, quiet)
    if not verification_site_html:
        if debug:
            print('Verification site could not be contacted ' +
                  url)
        return False
    verification_site_html = verification_site_html.decode()

    # does the site contain rel="me" links?
    if ' rel="me" ' not in verification_site_html:
        return False

    # ensure that there are not too many rel="me" links
    sections = verification_site_html.split(' rel="me" ')
    me_links_count = len(sections) - 1
    if me_links_count > 5:
        return False

    actor_links = [
        domain + '/@' + nickname,
        domain + '/users/' + nickname
    ]
    for actor in actor_links:
        if domain.endswith('.onion') or domain.endswith('.i2p'):
            actor = 'http://' + actor
        else:
            actor = http_prefix + '://' + actor

        # double quotes
        link_str = ' rel="me" href="' + actor + '"'
        if link_str in verification_site_html:
            return True
        link_str = ' href="' + actor + '" rel="me"'
        if link_str in verification_site_html:
            return True

        # single quotes
        link_str = " rel=\"me\" href='" + actor + "'"
        if link_str in verification_site_html:
            return True
        link_str = " href='" + actor + "' rel=\"me\""
        if link_str in verification_site_html:
            return True
    return False


def site_is_verified(session, base_dir: str, http_prefix: str,
                     nickname: str, domain: str,
                     url: str, update: bool, debug: bool,
                     mitm_servers: []) -> bool:
    """Is the given website verified?
    """
    verified_sites_filename = \
        acct_dir(base_dir, nickname, domain) + '/verified_sites.txt'
    verified_file_exists = False
    if os.path.isfile(verified_sites_filename):
        verified_file_exists = True
        if text_in_file(url + '\n', verified_sites_filename, True):
            return True
    if not update:
        return False

    verified = \
        verify_html(session, url, debug,
                    __version__, http_prefix, nickname, domain,
                    mitm_servers)
    if verified:
        write_type = 'a+'
        if not verified_file_exists:
            write_type = 'w+'
        try:
            with open(verified_sites_filename, write_type,
                      encoding='utf-8') as fp_verified:
                fp_verified.write(url + '\n')
        except OSError:
            print('EX: Verified sites could not be updated ' +
                  verified_sites_filename)
    return verified


def download_ssml(signing_priv_key_pem: str,
                  session, url: str, headers: {}, params: {}, debug: bool,
                  version: str, http_prefix: str, domain: str,
                  mitm_servers: [],
                  timeout_sec: int = 20, quiet: bool = False) -> {}:
    """Download a ssml document
    """
    if not isinstance(url, str):
        if debug and not quiet:
            print('url: ' + str(url))
            print('ERROR: download_ssml failed, url should be a string')
        return None
    session_params = {}
    session_headers = {}
    if headers:
        session_headers = headers
    if params:
        session_params = params
    session_headers['Accept'] = 'application/ssml+xml'
    session_headers['User-Agent'] = 'Epicyon/' + version
    if domain:
        session_headers['User-Agent'] += \
            '; +' + http_prefix + '://' + domain + '/'
    if not session:
        if not quiet:
            print('WARN: download_ssml failed, no session specified')
        return None

    if debug:
        HTTPConnection.debuglevel = 1

    if signing_priv_key_pem:
        return _get_json_signed(session, url, domain,
                                session_headers, session_params,
                                timeout_sec, signing_priv_key_pem,
                                quiet, debug, mitm_servers)
    return _get_json_request(session, url, session_headers,
                             session_params, timeout_sec,
                             quiet, debug, False, mitm_servers)


def _set_user_agent(session, http_prefix: str, domain_full: str) -> None:
    """Sets the user agent
    """
    ua_str = \
        'Epicyon/' + __version__ + '; +' + \
        http_prefix + '://' + domain_full + '/'
    session.headers.update({'User-Agent': ua_str})


def post_json(http_prefix: str, domain_full: str,
              session, post_json_object: {}, federation_list: [],
              inbox_url: str, headers: {}, timeout_sec: int = 60,
              quiet: bool = False) -> str:
    """Post a json message to the inbox of another person
    """
    # check that we are posting to a permitted domain
    if not url_permitted(inbox_url, federation_list):
        if not quiet:
            print('post_json: ' + inbox_url + ' not permitted')
        return None

    _set_user_agent(session, http_prefix, domain_full)

    try:
        post_result = \
            session.post(url=inbox_url,
                         data=json.dumps(post_json_object),
                         headers=headers, timeout=timeout_sec,
                         allow_redirects=True)
    except requests.Timeout as exc:
        if not quiet:
            print('EX: post_json timeout ' + inbox_url + ' ' +
                  json.dumps(post_json_object) + ' ' + str(headers))
            print(exc)
        return ''
    except requests.exceptions.RequestException as exc:
        if not quiet:
            print('EX: post_json requests failed ' + inbox_url + ' ' +
                  json.dumps(post_json_object) + ' ' + str(headers) +
                  ' ' + str(exc))
        return None
    except SocketError as exc:
        if not quiet and exc.errno == errno.ECONNRESET:
            print('EX: connection was reset during post_json')
        return None
    except ValueError as exc:
        if not quiet:
            print('EX: post_json failed ' + inbox_url + ' ' +
                  json.dumps(post_json_object) + ' ' + str(headers) +
                  ' ' + str(exc))
        return None
    if post_result:
        return post_result.text
    return None


def post_json_string(session, post_json_str: str,
                     federation_list: [],
                     inbox_url: str,
                     headers: {},
                     debug: bool,
                     http_prefix: str, domain_full: str,
                     timeout_sec: int = 30,
                     quiet: bool = False) -> (bool, bool, int):
    """Post a json message string to the inbox of another person
    The second boolean returned is true if the send if unauthorized
    NOTE: Here we post a string rather than the original json so that
    conversions between string and json format don't invalidate
    the message body digest of http signatures
    """
    # check that we are posting to a permitted domain
    if not url_permitted(inbox_url, federation_list):
        if not quiet:
            print('post_json_string: ' + inbox_url + ' not permitted')
        return False, True, 0

    _set_user_agent(session, http_prefix, domain_full)

    try:
        post_result = \
            session.post(url=inbox_url, data=post_json_str,
                         headers=headers, timeout=timeout_sec,
                         allow_redirects=True)
    except requests.exceptions.RequestException as exc:
        if not quiet:
            print('EX: error during post_json_string requests ' + str(exc))
        return None, None, 0
    except SocketError as exc:
        if not quiet and exc.errno == errno.ECONNRESET:
            print('EX: connection was reset during post_json_string')
        if not quiet:
            print('EX: post_json_string failed ' + inbox_url + ' ' +
                  post_json_str + ' ' + str(headers))
        return None, None, 0
    except ValueError as exc:
        if not quiet:
            print('EX: error during post_json_string ' + str(exc))
        return None, None, 0
    if post_result.status_code < 200 or post_result.status_code > 204:
        if post_result.status_code >= 400 and \
           post_result.status_code <= 405 and \
           post_result.status_code != 404:
            if not quiet:
                print('WARN: Post to ' + inbox_url +
                      ' is unauthorized. Code ' +
                      str(post_result.status_code))
            return False, True, post_result.status_code

        if not quiet:
            print('WARN: Failed to post to ' + inbox_url +
                  ' with headers ' + str(headers) +
                  ' status code ' + str(post_result.status_code))
        return False, False, post_result.status_code
    return True, False, 0


def post_image(session, attach_image_filename: str, federation_list: [],
               inbox_url: str, headers: {},
               http_prefix: str, domain_full: str) -> str:
    """Post an image to the inbox of another person or outbox via c2s
    """
    # check that we are posting to a permitted domain
    if not url_permitted(inbox_url, federation_list):
        print('post_json: ' + inbox_url + ' not permitted')
        return None

    if not is_image_file(attach_image_filename):
        print('Image must be png, jpg, jxl, webp, avif, heic, gif or svg')
        return None
    if not os.path.isfile(attach_image_filename):
        print('Image not found: ' + attach_image_filename)
        return None
    content_type = 'image/jpeg'
    if attach_image_filename.endswith('.png'):
        content_type = 'image/png'
    elif attach_image_filename.endswith('.gif'):
        content_type = 'image/gif'
    elif attach_image_filename.endswith('.webp'):
        content_type = 'image/webp'
    elif attach_image_filename.endswith('.avif'):
        content_type = 'image/avif'
    elif attach_image_filename.endswith('.heic'):
        content_type = 'image/heic'
    elif attach_image_filename.endswith('.jxl'):
        content_type = 'image/jxl'
    elif attach_image_filename.endswith('.svg'):
        content_type = 'image/svg+xml'
    headers['Content-type'] = content_type

    media_binary = None
    try:
        with open(attach_image_filename, 'rb') as fp_av:
            media_binary = fp_av.read()
    except OSError:
        print('EX: post_image unable to read binary ' +
              attach_image_filename)

    if media_binary:
        _set_user_agent(session, http_prefix, domain_full)

        try:
            post_result = session.post(url=inbox_url, data=media_binary,
                                       headers=headers, allow_redirects=True)
        except requests.exceptions.RequestException as ex:
            print('EX: error during post_image requests ' + str(ex))
            return None
        except SocketError as ex:
            if ex.errno == errno.ECONNRESET:
                print('EX: connection was reset during post_image')
            print('ERROR: post_image failed ' + inbox_url + ' ' +
                  str(headers) + ' ' + str(ex))
            return None
        except ValueError as ex:
            print('EX: error during post_image ' + str(ex))
            return None
        if post_result:
            return post_result.text
    return None


def _looks_like_url(url: str) -> bool:
    """Does the given string look like a url
    """
    if not url:
        return False
    if '.' not in url:
        return False
    if '://' not in url:
        return False
    return True


def download_image(session, url: str, image_filename: str, debug: bool,
                   force: bool = False) -> bool:
    """Downloads an image with an expected mime type
    """
    if not _looks_like_url(url):
        if debug:
            print('WARN: download_image, ' +
                  url + ' does not look like a url')
        return None

    # try different image types
    image_formats = image_mime_types_dict()
    session_headers = None
    for im_format, mime_type in image_formats.items():
        if url.endswith('.' + im_format) or \
           '.' + im_format + '?' in url:
            session_headers = {
                'Accept': 'image/' + mime_type
            }
            break

    if not session_headers:
        if debug:
            print('download_image: no session headers')
        return False

    if not os.path.isfile(image_filename) or force:
        try:
            if debug:
                print('Downloading image url: ' + url)
            result = session.get(url,
                                 headers=session_headers,
                                 params=None,
                                 allow_redirects=True)
            if result.status_code < 200 or \
               result.status_code > 202:
                if debug:
                    print('Image download failed with status ' +
                          str(result.status_code))
                # remove partial download
                if os.path.isfile(image_filename):
                    try:
                        os.remove(image_filename)
                    except OSError:
                        print('EX: download_image unable to delete ' +
                              image_filename)
            else:
                media_binary = result.content
                if binary_is_image(image_filename, media_binary):
                    with open(image_filename, 'wb') as fp_im:
                        fp_im.write(media_binary)
                        if debug:
                            print('Image downloaded from ' + url)
                        return True
                else:
                    print('WARN: download_image binary not recognized ' +
                          image_filename)
        except BaseException as ex:
            print('EX: Failed to download image: ' +
                  str(url) + ' ' + str(ex))
    return False


def download_image_any_mime_type(session, url: str,
                                 timeout_sec: int, debug: bool):
    """http GET for an image with any mime type
    """
    # check that this looks like a url
    if not _looks_like_url(url):
        if debug:
            print('WARN: download_image_any_mime_type, ' +
                  url + ' does not look like a url')
        return None, None

    mime_type = None
    content_type = None
    result = None
    image_mime_types = \
        'image/x-icon, image/png, image/webp, image/jpeg, image/gif, ' + \
        'image/avif, image/heic, image/jxl, image/svg+xml'
    session_headers = {
        'Accept': image_mime_types
    }
    try:
        result = session.get(url, headers=session_headers,
                             timeout=timeout_sec,
                             allow_redirects=True)
    except requests.exceptions.RequestException as ex:
        print('EX: download_image_any_mime_type failed1: ' +
              str(url) + ', ' + str(ex))
        return None, None
    except ValueError as ex:
        print('EX: download_image_any_mime_type failed2: ' +
              str(url) + ', ' + str(ex))
        return None, None
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: download_image_any_mime_type failed, ' +
                  'connection was reset ' + str(ex))
        return None, None

    if not result:
        return None, None

    if result.status_code != 200:
        print('WARN: download_image_any_mime_type: ' + url +
              ' failed with error code ' + str(result.status_code))
        return None, None

    if result.headers.get('content-type'):
        content_type = result.headers['content-type']
    elif result.headers.get('Content-type'):
        content_type = result.headers['Content-type']
    elif result.headers.get('Content-Type'):
        content_type = result.headers['Content-Type']

    if not content_type:
        return None, None

    image_formats = image_mime_types_dict()
    for _, m_type in image_formats.items():
        if 'image/' + m_type in content_type:
            mime_type = 'image/' + m_type
    return result.content, mime_type


def get_method(method_name: str, xml_str: str,
               session, url: str, params: {}, headers: {}, debug: bool,
               version: str, http_prefix: str, domain: str,
               timeout_sec: int = 20, quiet: bool = False) -> {}:
    """Part of the vcard interface
    """
    if method_name not in ("REPORT", "PUT", "PROPFIND"):
        print("Unrecognized method: " + method_name)
        return None
    if not isinstance(url, str):
        if debug and not quiet:
            print('url: ' + str(url))
            print('ERROR: get_method failed, url should be a string')
        return None
    if not headers:
        headers = {
            'Accept': 'application/xml'
        }
    else:
        headers['Accept'] = 'application/xml'
    session_params = {}
    session_headers = {}
    if headers:
        session_headers = headers
    if params:
        session_params = params
    session_headers['User-Agent'] = 'Epicyon/' + version
    if domain:
        session_headers['User-Agent'] += \
            '; +' + http_prefix + '://' + domain + '/'
    if not session:
        if not quiet:
            print('WARN: get_method failed, ' +
                  'no session specified for get_vcard')
        return None

    if debug:
        HTTPConnection.debuglevel = 1

    try:
        result = session.request(method_name, url, headers=session_headers,
                                 data=xml_str,
                                 params=session_params, timeout=timeout_sec,
                                 allow_redirects=True)
        if result.status_code not in (200, 207):
            if result.status_code == 401:
                print("WARN: get_method " + url + ' rejected by secure mode')
            elif result.status_code == 403:
                print('WARN: get_method Forbidden url: ' + url)
            elif result.status_code == 404:
                print('WARN: get_method Not Found url: ' + url)
            elif result.status_code == 410:
                print('WARN: get_method no longer available url: ' + url)
            else:
                session_headers2 = session_headers.copy()
                if session_headers2.get('Authorization'):
                    session_headers2['Authorization'] = 'REDACTED'
                print('WARN: get_method url: ' + url +
                      ' failed with error code ' +
                      str(result.status_code) +
                      ' headers: ' + str(session_headers2))
        return result.content.decode('utf-8')
    except requests.exceptions.RequestException as ex:
        session_headers2 = session_headers.copy()
        if session_headers2.get('Authorization'):
            session_headers2['Authorization'] = 'REDACTED'
        if debug and not quiet:
            print('EX: get_method failed, url: ' + str(url) + ', ' +
                  'headers: ' + str(session_headers2) + ', ' +
                  'params: ' + str(session_params) + ', ' + str(ex))
    except ValueError as ex:
        session_headers2 = session_headers.copy()
        if session_headers2.get('Authorization'):
            session_headers2['Authorization'] = 'REDACTED'
        if debug and not quiet:
            print('EX: get_method failed, url: ' + str(url) + ', ' +
                  'headers: ' + str(session_headers2) + ', ' +
                  'params: ' + str(session_params) + ', ' + str(ex))
    except SocketError as ex:
        if not quiet:
            if ex.errno == errno.ECONNRESET:
                print('EX: get_method failed, ' +
                      'connection was reset during get_vcard ' + str(ex))
    return None


def get_session_for_domains(server, calling_domain: str, referer_domain: str):
    """Returns the appropriate session for the given domains
    """
    if referer_domain is None:
        referer_domain = ''

    if '.onion:' in calling_domain or \
       calling_domain.endswith('.onion') or \
       '.onion:' in referer_domain or \
       referer_domain.endswith('.onion'):
        if not server.domain.endswith('.onion'):
            if server.onion_domain and server.session_onion:
                return server.session_onion, 'tor'
    if '.i2p:' in calling_domain or \
       calling_domain.endswith('.i2p') or \
       '.i2p:' in referer_domain or \
       referer_domain.endswith('.i2p'):
        if not server.domain.endswith('.i2p'):
            if server.i2p_domain and server.session_i2p:
                return server.session_i2p, 'i2p'
    return server.session, server.proxy_type


def get_session_for_domain(server, referer_domain: str):
    """Returns the appropriate session for the given domain
    """
    return get_session_for_domains(server, referer_domain, referer_domain)


def set_session_for_sender(server, proxy_type: str, new_session) -> None:
    """Sets the appropriate session for the given sender
    """
    if proxy_type == 'tor':
        if not server.domain.endswith('.onion'):
            if server.onion_domain and server.session_onion:
                server.session_onion = new_session
                return
    if proxy_type == 'i2p':
        if not server.domain.endswith('.i2p'):
            if server.i2p_domain and server.session_i2p:
                server.session_i2p = new_session
                return
    server.session = new_session


def establish_session(calling_function: str,
                      curr_session, proxy_type: str,
                      server):
    """Recreates session if needed
    """
    if curr_session:
        return curr_session
    print('DEBUG: creating new session during ' + calling_function)
    curr_session = create_session(proxy_type)
    if curr_session:
        set_session_for_sender(server, proxy_type, curr_session)
        return curr_session
    print('ERROR: GET failed to create session during ' +
          calling_function)
    return None
