__filename__ = "daemon_get_blog.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

from httpcodes import write2
from session import establish_session
from httpcodes import http_404
from httpheaders import set_headers
from blog import html_blog_page
from fitnessFunctions import fitness_performance


def show_blog_page(self, authorized: bool,
                   calling_domain: str, path: str,
                   base_dir: str, http_prefix: str,
                   domain: str, port: int,
                   getreq_start_time,
                   proxy_type: str, cookie: str,
                   translate: {}, debug: str,
                   curr_session,
                   max_posts_in_blogs_feed: int,
                   peertube_instances: [],
                   system_language: str,
                   person_cache: {},
                   fitness: {}) -> bool:
    """Shows a blog page
    """
    page_number = 1
    nickname = path.split('/blog/')[1]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    if '?' in nickname:
        nickname = nickname.split('?')[0]
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if ';' in page_number_str:
            page_number_str = page_number_str.split(';')[0]
        if '?' in page_number_str:
            page_number_str = page_number_str.split('?')[0]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
            if page_number < 1:
                page_number = 1
            elif page_number > 10:
                page_number = 10
    curr_session = \
        establish_session("show_blog_page",
                          curr_session, proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 90)
        self.server.getreq_busy = False
        return True
    msg = html_blog_page(authorized,
                         curr_session,
                         base_dir,
                         http_prefix,
                         translate,
                         nickname,
                         domain, port,
                         max_posts_in_blogs_feed, page_number,
                         peertube_instances,
                         system_language,
                         person_cache,
                         debug)
    if msg is not None:
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time,
                            fitness,
                            '_GET', 'show_blog_page',
                            debug)
        return True
    http_404(self, 91)
    return True
