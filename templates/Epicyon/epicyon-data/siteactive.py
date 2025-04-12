__filename__ = "siteactive.py"
__author__ = "Bob Mottram"
__credits__ = ["webchk"]
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import http.client
import ssl
from urllib.parse import urlparse
from utils import data_dir


class Result:
    """Holds result of an URL check.

    The redirect attribute is a Result object that the URL was redirected to.

    The sitemap_urls attribute will contain a list of Result object if url
    is a sitemap file and http_response() was run with parse set to True.
    """
    def __init__(self, url):
        self.url = url
        self.status = 0
        self.desc = ''
        self.headers = None
        self.latency = 0
        self.content = ''
        self.redirect = None
        self.sitemap_urls = None

    def __repr__(self):
        if self.status == 0:
            return '{} ... {}'.format(self.url, self.desc)
        return '{} ... {} {} ({})'.format(
            self.url, self.status, self.desc, self.latency
        )

    def fill_headers(self, headers):
        """Takes a list of tuples and converts it a dictionary."""
        self.headers = {h[0]: h[1] for h in headers}


def _site_active_parse_url(url):
    """Returns an object with properties representing

    scheme:   URL scheme specifier
    netloc:   Network location part
    path:     Hierarchical path
    params:   Parameters for last path element
    query:    Query component
    fragment: Fragment identifier
    username: User name
    password: Password
    hostname: Host name (lower case)
    port:     Port number as integer, if present
    """
    loc = urlparse(url)

    # if the scheme (http, https ...) is not available urlparse wont work
    if loc.scheme == "":
        url = "http://" + url
        loc = urlparse(url)
    return loc


def _site_active_http_connect(loc, timeout: int):
    """Connects to the host and returns an HTTP or HTTPS connections."""
    if loc.scheme == "https":
        ssl_context = ssl.SSLContext()
        return http.client.HTTPSConnection(
            loc.netloc, context=ssl_context, timeout=timeout)
    return http.client.HTTPConnection(loc.netloc, timeout=timeout)


def _site_active_http_request(loc, timeout: int):
    """Performs a HTTP request and return response in a Result object.
    """
    conn = _site_active_http_connect(loc, timeout)
    method = 'HEAD'

    conn.request(method, loc.path)
    resp = conn.getresponse()

    result = Result(loc.geturl())
    result.status = resp.status
    result.desc = resp.reason
    result.fill_headers(resp.getheaders())

    conn.close()
    return result


def site_is_active(url: str, timeout: int,
                   sites_unavailable: []) -> bool:
    """Returns true if the current url is resolvable.
    This can be used to check that an instance is online before
    trying to send posts to it.
    """
    if '<>' in url:
        url = url.replace('<>', '')
    if not url.startswith('http') and \
       not url.startswith('ipfs') and \
       not url.startswith('ipns'):
        return False
    if '.onion/' in url or '.i2p/' in url or \
       url.endswith('.onion') or \
       url.endswith('.i2p'):
        # skip this check for onion and i2p
        return True

    loc = _site_active_parse_url(url)
    result = Result(url=url)
    url2 = url
    if '://' in url:
        url2 = url.split('://')[1]

    try:
        result = _site_active_http_request(loc, timeout)

        if url2 in sites_unavailable:
            sites_unavailable.remove(url2)

        if 400 <= result.status < 500:
            # the site is available but denying access
            return result

        return True

    except BaseException as ex:
        print('EX: site_is_active ' + url + ' ' + str(ex))

    if url2 not in sites_unavailable:
        sites_unavailable.append(url2)
    return False


def referer_is_active(http_prefix: str,
                      referer_domain: str, ua_str: str,
                      calling_site_timeout: int,
                      sites_unavailable: []) -> bool:
    """Returns true if the given referer is an active website
    """
    referer_url = http_prefix + '://' + referer_domain
    if referer_domain + '/' in ua_str:
        referer_url = referer_url + ua_str.split(referer_domain)[1]
        ending_chars = (' ', ';', ')')
        for end_ch in ending_chars:
            if end_ch in referer_url:
                referer_url = referer_url.split(end_ch)[0]
    return site_is_active(referer_url, calling_site_timeout,
                          sites_unavailable)


def save_unavailable_sites(base_dir: str, sites_unavailable: []) -> None:
    """Save a list of unavailable sites
    """
    unavailable_sites_filename = data_dir(base_dir) + '/unavailable_sites.txt'
    sites_unavailable.sort()
    try:
        with open(unavailable_sites_filename, 'w+',
                  encoding='utf-8') as fp_sites:
            for site in sites_unavailable:
                if site:
                    fp_sites.write(site + '\n')
    except OSError:
        print('EX: unable to save unavailable sites')


def load_unavailable_sites(base_dir: str) -> []:
    """load a list of unavailable sites
    """
    unavailable_sites_filename = data_dir(base_dir) + '/unavailable_sites.txt'
    sites_unavailable: list[str] = []
    try:
        with open(unavailable_sites_filename, 'r',
                  encoding='utf-8') as fp_sites:
            sites_unavailable = fp_sites.read().split('\n')
    except OSError:
        print('EX: unable to read unavailable sites ' +
              unavailable_sites_filename)
    return sites_unavailable
