__filename__ = "daemon_get_nodeinfo.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from httpcodes import http_400
from httpcodes import http_404
from httpcodes import http_503
from httpcodes import write2
from httpheaders import set_headers
from utils import convert_domains
from utils import get_instance_url
from utils import local_network_host
from siteactive import referer_is_active
from crawlers import update_known_crawlers
from blocking import broch_mode_is_active
from metadata import meta_data_node_info


def get_nodeinfo(self, ua_str: str, calling_domain: str,
                 referer_domain: str,
                 http_prefix: str, calling_site_timeout: int,
                 debug: bool, base_dir: str,
                 unit_test: bool,
                 domain_full: str,
                 path: str,
                 allow_local_network_access: bool,
                 sites_unavailable: [],
                 known_crawlers: [],
                 onion_domain: str,
                 i2p_domain: str,
                 project_version: str,
                 show_node_info_version: bool,
                 show_node_info_accounts: bool,
                 registration: bool, domain: str,
                 instance_description_short: str,
                 instance_description: str) -> bool:
    """Supports nodeinfo version 2.x
    """
    if path.startswith('/nodeinfo/1.0'):
        http_400(self)
        return True
    if not path.startswith('/nodeinfo/2.') and \
       not path.startswith('/.well-known/host-meta') and \
       not path.startswith('/.well-known/nodeinfo') and \
       not path.startswith('/.well-known/x-nodeinfo'):
        return False
    if not referer_domain:
        if not debug and not unit_test:
            print('nodeinfo request has no referer domain ' + str(ua_str))
            http_400(self)
            return True
    if referer_domain == domain_full:
        print('nodeinfo request from self')
        http_400(self)
        return True
    if self.server.nodeinfo_is_active:
        if not referer_domain:
            print('nodeinfo is busy during request without referer domain')
        else:
            print('nodeinfo is busy during request from ' + referer_domain)
        http_503(self)
        return True
    self.server.nodeinfo_is_active = True
    # is this a real website making the call ?
    if not debug and not unit_test and referer_domain:
        # Does calling_domain look like a domain?
        if ' ' in referer_domain or \
           ';' in referer_domain or \
           '.' not in referer_domain:
            print('nodeinfo referer domain does not look like a domain ' +
                  referer_domain)
            http_400(self)
            self.server.nodeinfo_is_active = False
            return True
        if not allow_local_network_access:
            if local_network_host(referer_domain):
                print('nodeinfo referer domain is from the ' +
                      'local network ' + referer_domain)
                http_400(self)
                self.server.nodeinfo_is_active = False
                return True

        if not referer_is_active(http_prefix,
                                 referer_domain, ua_str,
                                 calling_site_timeout,
                                 sites_unavailable):
            print('nodeinfo referer url is not active ' +
                  referer_domain)
            http_400(self)
            self.server.nodeinfo_is_active = False
            return True
    if debug:
        print('DEBUG: nodeinfo ' + path)
    crawl_time = \
        update_known_crawlers(ua_str,
                              base_dir,
                              known_crawlers,
                              self.server.last_known_crawler)
    if crawl_time is not None:
        self.server.last_known_crawler = crawl_time

    # If we are in broch mode then don't show potentially
    # sensitive metadata.
    # For example, if this or allied instances are being attacked
    # then numbers of accounts may be changing as people
    # migrate, and that information may be useful to an adversary
    broch_mode = broch_mode_is_active(base_dir)

    node_info_version = project_version
    if not show_node_info_version or broch_mode:
        node_info_version = '0.0.0'

    if broch_mode:
        show_node_info_accounts = False

    instance_url = get_instance_url(calling_domain,
                                    http_prefix,
                                    domain_full,
                                    onion_domain,
                                    i2p_domain)
    about_url = instance_url + '/about'
    terms_of_service_url = instance_url + '/terms'
    info = meta_data_node_info(base_dir,
                               about_url, terms_of_service_url,
                               registration,
                               node_info_version,
                               show_node_info_accounts,
                               domain,
                               instance_description_short,
                               instance_description)
    if info:
        msg_str = json.dumps(info)
        msg_str = convert_domains(calling_domain, referer_domain,
                                  msg_str, http_prefix,
                                  domain,
                                  onion_domain,
                                  i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        protocol_str = \
            'application/json; profile=' + \
            '"http://nodeinfo.diaspora.software/ns/schema/2.1#"'
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, True)
        write2(self, msg)
        if referer_domain:
            print('nodeinfo sent to ' + referer_domain)
        else:
            print('nodeinfo sent to unknown referer')
        self.server.nodeinfo_is_active = False
        return True
    http_404(self, 5)
    self.server.nodeinfo_is_active = False
    return True
