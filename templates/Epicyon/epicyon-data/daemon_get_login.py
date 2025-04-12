__filename__ = "daemon_get_login.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon Login"

from utils import get_instance_url
from utils import string_ends_with
from utils import string_contains
from httpcodes import write2
from httpheaders import login_headers
from httpheaders import redirect_headers
from fitnessFunctions import fitness_performance
from webapp_login import html_login


def redirect_to_login_screen(self, calling_domain: str, path: str,
                             http_prefix: str, domain_full: str,
                             onion_domain: str, i2p_domain: str,
                             getreq_start_time,
                             authorized: bool, debug: bool,
                             news_instance: bool, fitness: {}) -> bool:
    """Redirects to the login screen if necessary
    """
    divert_to_login_screen = False
    non_login_paths = ('/media/', '/ontologies/', '/data/', '/sharefiles/',
                       '/statuses/', '/emoji/', '/tags/', '/tagmaps/',
                       '/avatars/', '/favicons/', '/headers/', '/fonts/',
                       '/icons/')
    if not string_contains(path, non_login_paths):
        divert_to_login_screen = True
        if path.startswith('/users/'):
            nick_str = path.split('/users/')[1]
            if '/' not in nick_str and '?' not in nick_str:
                divert_to_login_screen = False
            else:
                possible_endings = ('/following', '/followers',
                                    '/skills', '/roles', '/wanted', '/shares')
                if string_ends_with(path, possible_endings):
                    divert_to_login_screen = False

    if divert_to_login_screen and not authorized:
        divert_path = '/login'
        if news_instance:
            # for news instances if not logged in then show the
            # front page
            divert_path = '/users/news'
        if debug:
            print('DEBUG: divert_to_login_screen=' +
                  str(divert_to_login_screen))
            print('DEBUG: authorized=' + str(authorized))
            print('DEBUG: path=' + path)
        redirect_url = \
            get_instance_url(calling_domain,
                             http_prefix, domain_full,
                             onion_domain, i2p_domain) + \
            divert_path
        redirect_headers(self, redirect_url, None, calling_domain, 303)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_redirect_to_login_screen',
                            debug)
        return True
    return False


def show_login_screen(path: str, authorized: bool,
                      news_instance: bool,
                      translate: {},
                      base_dir: str,
                      http_prefix: str,
                      domain_full: str,
                      system_language: str,
                      ua_str: str, theme_name: str,
                      calling_domain: str,
                      getreq_start_time,
                      fitness: {}, debug: bool,
                      self) -> bool:
    """ Shows the login screen
    """
    if path.startswith('/login') or \
       (path == '/' and not authorized and not news_instance):
        # request basic auth
        msg = html_login(translate, base_dir,
                         http_prefix, domain_full,
                         system_language, True, ua_str,
                         theme_name).encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'login shown', debug)
        return True
    return False
