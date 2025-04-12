__filename__ = "daemon_get_rss.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
from utils import data_dir
from utils import is_account_dir
from utils import acct_dir
from session import establish_session
from blog import html_blog_page_rss2
from blog import html_blog_page_rss3
from httpheaders import set_headers
from httpcodes import write2
from httpcodes import http_404
from fitnessFunctions import fitness_performance
from newswire import rss2header
from newswire import rss2footer


def get_rss2feed(self, calling_domain: str, path: str,
                 base_dir: str, http_prefix: str,
                 domain: str, port: int, proxy_type: str,
                 getreq_start_time, debug: bool,
                 curr_session,
                 max_posts_in_rss_feed: int,
                 translate: {},
                 system_language: str,
                 fitness: {}) -> None:
    """Returns an RSS2 feed for the blog
    """
    nickname = path.split('/blog/')[1]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    if not nickname.startswith('rss.'):
        account_dir = acct_dir(base_dir, nickname, domain)
        if os.path.isdir(account_dir):
            curr_session = \
                establish_session("RSS request",
                                  curr_session,
                                  proxy_type,
                                  self.server)
            if not curr_session:
                return

            msg = \
                html_blog_page_rss2(base_dir,
                                    http_prefix,
                                    translate,
                                    nickname,
                                    domain,
                                    port,
                                    max_posts_in_rss_feed, 1,
                                    True,
                                    system_language)
            if msg is not None:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/xml', msglen,
                            None, calling_domain, True)
                write2(self, msg)
                if debug:
                    print('Sent rss2 feed: ' +
                          path + ' ' + calling_domain)
                fitness_performance(getreq_start_time, fitness,
                                    '_GET', '_get_rss2feed',
                                    debug)
                return
    if debug:
        print('Failed to get rss2 feed: ' +
              path + ' ' + calling_domain)
    http_404(self, 22)


def get_rss2site(self, calling_domain: str, path: str,
                 base_dir: str, http_prefix: str,
                 domain_full: str, port: int, proxy_type: str,
                 translate: {},
                 getreq_start_time,
                 debug: bool,
                 curr_session,
                 max_posts_in_rss_feed: int,
                 system_language: str,
                 fitness: {}) -> None:
    """Returns an RSS2 feed for all blogs on this instance
    """
    curr_session = \
        establish_session("get_rss2site",
                          curr_session,
                          proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 23)
        return

    msg = ''
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for acct in dirs:
            if not is_account_dir(acct):
                continue
            nickname = acct.split('@')[0]
            domain = acct.split('@')[1]
            msg += \
                html_blog_page_rss2(base_dir,
                                    http_prefix,
                                    translate,
                                    nickname,
                                    domain,
                                    port,
                                    max_posts_in_rss_feed, 1,
                                    False,
                                    system_language)
        break
    if msg:
        msg = rss2header(http_prefix,
                         'news', domain_full,
                         'Site', translate) + msg + rss2footer()

        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/xml', msglen,
                    None, calling_domain, True)
        write2(self, msg)
        if debug:
            print('Sent rss2 feed: ' +
                  path + ' ' + calling_domain)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_get_rss2site',
                            debug)
        return
    if debug:
        print('Failed to get rss2 feed: ' +
              path + ' ' + calling_domain)
    http_404(self, 24)


def get_rss3feed(self, calling_domain: str, path: str,
                 base_dir: str, http_prefix: str,
                 domain: str, port: int, proxy_type: str,
                 getreq_start_time,
                 debug: bool, system_language: str,
                 curr_session,
                 max_posts_in_rss_feed: int,
                 fitness: {}) -> None:
    """Returns an RSS3 feed
    """
    nickname = path.split('/blog/')[1]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    if not nickname.startswith('rss.'):
        account_dir = acct_dir(base_dir, nickname, domain)
        if os.path.isdir(account_dir):
            curr_session = \
                establish_session("get_rss3feed",
                                  curr_session, proxy_type,
                                  self.server)
            if not curr_session:
                http_404(self, 29)
                return
            msg = \
                html_blog_page_rss3(base_dir, http_prefix,
                                    nickname, domain, port,
                                    max_posts_in_rss_feed, 1,
                                    system_language)
            if msg is not None:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/plain; charset=utf-8',
                            msglen, None, calling_domain, True)
                write2(self, msg)
                if debug:
                    print('Sent rss3 feed: ' +
                          path + ' ' + calling_domain)
                fitness_performance(getreq_start_time, fitness,
                                    '_GET', '_get_rss3feed', debug)
                return
    if debug:
        print('Failed to get rss3 feed: ' +
              path + ' ' + calling_domain)
    http_404(self, 20)
