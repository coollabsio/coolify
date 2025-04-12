__filename__ = "httprequests.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

from webapp_utils import text_mode_browser


def request_csv(headers: {}) -> bool:
    """Should a csv response be given?
    """
    if not headers.get('Accept'):
        return False
    accept_str = headers['Accept']
    if 'text/csv' in accept_str:
        return True
    return False


def request_ssml(headers: {}) -> bool:
    """Should a ssml response be given?
    """
    if not headers.get('Accept'):
        return False
    accept_str = headers['Accept']
    if 'application/ssml' in accept_str:
        if 'text/html' not in accept_str:
            return True
    return False


def request_http(headers: {}, debug: bool) -> bool:
    """Should a http response be given?
    """
    if not headers.get('Accept'):
        return False
    accept_str = headers['Accept']
    if debug:
        print('ACCEPT: ' + accept_str)
    if 'application/ssml' in accept_str:
        if 'text/html' not in accept_str:
            return False
    if 'image/' in accept_str:
        if 'text/html' not in accept_str:
            return False
    if 'video/' in accept_str:
        if 'text/html' not in accept_str:
            return False
    if 'audio/' in accept_str:
        if 'text/html' not in accept_str:
            return False
    if accept_str.startswith('*') or 'text/html' in accept_str:
        if headers.get('User-Agent'):
            ua_str = headers['User-Agent']
            if text_mode_browser(ua_str) or 'NetSurf/' in ua_str:
                return True
        if 'text/html' not in accept_str:
            return False
    if 'json' in accept_str:
        return False
    return True


def request_icalendar(headers: {}) -> bool:
    """Should an icalendar response be given?
    """
    if not headers.get('Accept'):
        return False
    accept_str = headers['Accept']
    if 'text/calendar' in accept_str:
        return True
    return False
