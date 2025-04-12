__filename__ = "daemon_get_masto_api.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from httpheaders import set_headers
from httpcodes import write2
from mastoapiv1 import masto_api_v1_response
from mastoapiv2 import masto_api_v2_response
from siteactive import referer_is_active
from httpcodes import http_400
from httpcodes import http_404
from httpcodes import http_503
from utils import get_json_content_from_accept
from utils import convert_domains
from utils import local_network_host
from crawlers import update_known_crawlers
from blocking import broch_mode_is_active
from daemon_utils import has_accept


def masto_api(self, path: str, calling_domain: str,
              ua_str: str,
              authorized: bool, http_prefix: str,
              base_dir: str, nickname: str, domain: str,
              domain_full: str,
              onion_domain: str, i2p_domain: str,
              translate: {},
              registration: bool,
              system_language: str,
              project_version: str,
              custom_emoji: [],
              show_node_info_accounts: bool,
              referer_domain: str, debug: bool,
              known_crawlers: {},
              sites_unavailable: [],
              unit_test: bool,
              allow_local_network_access: bool) -> bool:
    if _masto_api_v2(self, path, calling_domain, ua_str, authorized,
                     http_prefix, base_dir, nickname, domain,
                     domain_full, onion_domain, i2p_domain,
                     translate, registration, system_language,
                     project_version,
                     show_node_info_accounts,
                     referer_domain, debug, 5,
                     known_crawlers, sites_unavailable, unit_test,
                     allow_local_network_access):
        return True
    return _masto_api_v1(self, path, calling_domain, ua_str, authorized,
                         http_prefix, base_dir, nickname, domain,
                         domain_full, onion_domain, i2p_domain,
                         translate, registration, system_language,
                         project_version, custom_emoji,
                         show_node_info_accounts,
                         referer_domain, debug, 5,
                         known_crawlers, sites_unavailable,
                         unit_test,
                         allow_local_network_access)


def _masto_api_v1(self, path: str, calling_domain: str,
                  ua_str: str,
                  authorized: bool,
                  http_prefix: str,
                  base_dir: str, nickname: str, domain: str,
                  domain_full: str,
                  onion_domain: str, i2p_domain: str,
                  translate: {},
                  registration: bool,
                  system_language: str,
                  project_version: str,
                  custom_emoji: [],
                  show_node_info_accounts: bool,
                  referer_domain: str,
                  debug: bool,
                  calling_site_timeout: int,
                  known_crawlers: {},
                  sites_unavailable: [],
                  unit_test: bool,
                  allow_local_network_access: bool) -> bool:
    """This is a vestigil mastodon API for the purpose
    of returning an empty result to sites like
    https://mastopeek.app-dist.eu
    """
    if not path.startswith('/api/v1/'):
        return False

    if not referer_domain:
        if not (debug and unit_test):
            print('mastodon api request has no referer domain ' +
                  str(ua_str))
            http_400(self)
            return True
    if referer_domain == domain_full:
        print('mastodon api request from self')
        http_400(self)
        return True
    if self.server.masto_api_is_active:
        print('mastodon api is busy during request from ' +
              referer_domain)
        http_503(self)
        return True
    self.server.masto_api_is_active = True
    # is this a real website making the call ?
    if not debug and not unit_test and referer_domain:
        # Does calling_domain look like a domain?
        if ' ' in referer_domain or \
           ';' in referer_domain or \
           '.' not in referer_domain:
            print('mastodon api ' +
                  'referer does not look like a domain ' +
                  referer_domain)
            http_400(self)
            self.server.masto_api_is_active = False
            return True
        if not allow_local_network_access:
            if local_network_host(referer_domain):
                print('mastodon api referer domain is from the ' +
                      'local network ' + referer_domain)
                http_400(self)
                self.server.masto_api_is_active = False
                return True
        if not referer_is_active(http_prefix,
                                 referer_domain, ua_str,
                                 calling_site_timeout,
                                 sites_unavailable):
            print('mastodon api referer url is not active ' +
                  referer_domain)
            http_400(self)
            self.server.masto_api_is_active = False
            return True

    print('mastodon api v1: ' + path)
    print('mastodon api v1: authorized ' + str(authorized))
    print('mastodon api v1: nickname ' + str(nickname))
    print('mastodon api v1: referer ' + str(referer_domain))
    crawl_time = \
        update_known_crawlers(ua_str, base_dir,
                              known_crawlers,
                              self.server.last_known_crawler)
    if crawl_time is not None:
        self.server.last_known_crawler = crawl_time

    broch_mode = broch_mode_is_active(base_dir)
    send_json, send_json_str = \
        masto_api_v1_response(path,
                              calling_domain,
                              ua_str,
                              authorized,
                              http_prefix,
                              base_dir,
                              nickname, domain,
                              domain_full,
                              onion_domain,
                              i2p_domain,
                              translate,
                              registration,
                              system_language,
                              project_version,
                              custom_emoji,
                              show_node_info_accounts,
                              broch_mode)

    if send_json is not None:
        msg_str = json.dumps(send_json)
        msg_str = convert_domains(calling_domain, referer_domain,
                                  msg_str, http_prefix, domain,
                                  onion_domain, i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        if has_accept(self, calling_domain):
            protocol_str = \
                get_json_content_from_accept(self.headers.get('Accept'))
            set_headers(self, protocol_str, msglen,
                        None, calling_domain, True)
        else:
            set_headers(self, 'application/ld+json', msglen,
                        None, calling_domain, True)
        write2(self, msg)
        if send_json_str:
            print(send_json_str)
        self.server.masto_api_is_active = False
        return True

    # no api endpoints were matched
    http_404(self, 1)
    self.server.masto_api_is_active = False
    return True


def _masto_api_v2(self, path: str, calling_domain: str,
                  ua_str: str,
                  authorized: bool,
                  http_prefix: str,
                  base_dir: str, nickname: str, domain: str,
                  domain_full: str,
                  onion_domain: str, i2p_domain: str,
                  translate: {},
                  registration: bool,
                  system_language: str,
                  project_version: str,
                  show_node_info_accounts: bool,
                  referer_domain: str,
                  debug: bool,
                  calling_site_timeout: int,
                  known_crawlers: {},
                  sites_unavailable: [],
                  unit_test: bool,
                  allow_local_network_access: bool) -> bool:
    """This is a vestigil mastodon v2 API for the purpose
    of returning an empty result to sites like
    https://mastopeek.app-dist.eu
    """
    if not path.startswith('/api/v2/'):
        return False

    if not referer_domain:
        if not (debug and unit_test):
            print('mastodon api v2 request has no referer domain ' +
                  str(ua_str))
            http_400(self)
            return True
    if referer_domain == domain_full:
        print('mastodon api v2 request from self')
        http_400(self)
        return True
    if self.server.masto_api_is_active:
        print('mastodon api v2 is busy during request from ' +
              referer_domain)
        http_503(self)
        return True
    self.server.masto_api_is_active = True
    # is this a real website making the call ?
    if not debug and not unit_test and referer_domain:
        # Does calling_domain look like a domain?
        if ' ' in referer_domain or \
           ';' in referer_domain or \
           '.' not in referer_domain:
            print('mastodon api v2 ' +
                  'referer does not look like a domain ' +
                  referer_domain)
            http_400(self)
            self.server.masto_api_is_active = False
            return True
        if not allow_local_network_access:
            if local_network_host(referer_domain):
                print('mastodon api v2 referer domain is from the ' +
                      'local network ' + referer_domain)
                http_400(self)
                self.server.masto_api_is_active = False
                return True
        if not referer_is_active(http_prefix,
                                 referer_domain, ua_str,
                                 calling_site_timeout,
                                 sites_unavailable):
            print('mastodon api v2 referer url is not active ' +
                  referer_domain)
            http_400(self)
            self.server.masto_api_is_active = False
            return True

    print('mastodon api v2: ' + path)
    print('mastodon api v2: authorized ' + str(authorized))
    print('mastodon api v2: nickname ' + str(nickname))
    print('mastodon api v2: referer ' + str(referer_domain))
    crawl_time = \
        update_known_crawlers(ua_str, base_dir,
                              known_crawlers,
                              self.server.last_known_crawler)
    if crawl_time is not None:
        self.server.last_known_crawler = crawl_time

    broch_mode = broch_mode_is_active(base_dir)
    send_json, send_json_str = \
        masto_api_v2_response(path,
                              calling_domain,
                              ua_str,
                              http_prefix,
                              base_dir,
                              domain,
                              domain_full,
                              onion_domain,
                              i2p_domain,
                              translate,
                              registration,
                              system_language,
                              project_version,
                              show_node_info_accounts,
                              broch_mode)

    if send_json is not None:
        msg_str = json.dumps(send_json)
        msg_str = convert_domains(calling_domain, referer_domain,
                                  msg_str, http_prefix, domain,
                                  onion_domain, i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        if has_accept(self, calling_domain):
            protocol_str = \
                get_json_content_from_accept(self.headers.get('Accept'))
            set_headers(self, protocol_str, msglen,
                        None, calling_domain, True)
        else:
            set_headers(self, 'application/ld+json', msglen,
                        None, calling_domain, True)
        write2(self, msg)
        if send_json_str:
            print(send_json_str)
        self.server.masto_api_is_active = False
        return True

    # no api v2 endpoints were matched
    http_404(self, 2)
    self.server.masto_api_is_active = False
    return True
