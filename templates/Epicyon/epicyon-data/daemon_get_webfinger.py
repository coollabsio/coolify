__filename__ = "daemon_get_webfinger.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from httpcodes import write2
from httpcodes import http_404
from httpheaders import redirect_headers
from httpheaders import set_headers
from webfinger import webfinger_lookup
from webfinger import webfinger_node_info
from webfinger import webfinger_meta
from webfinger import wellknown_protocol_handler
from utils import get_json_content_from_accept
from utils import convert_domains
from daemon_utils import has_accept


def get_webfinger(self, calling_domain: str, referer_domain: str,
                  cookie: str, path: str, debug: bool,
                  onion_domain: str, i2p_domain: str,
                  http_prefix: str, domain: str, domain_full: str,
                  base_dir: str, port: int) -> bool:
    if not path.startswith('/.well-known'):
        return False
    if debug:
        print('DEBUG: WEBFINGER well-known')

    if debug:
        print('DEBUG: WEBFINGER host-meta')
    if path.startswith('/.well-known/host-meta'):
        if calling_domain.endswith('.onion') and \
           onion_domain:
            wf_result = \
                webfinger_meta('http', onion_domain)
        elif (calling_domain.endswith('.i2p') and
              i2p_domain):
            wf_result = \
                webfinger_meta('http', i2p_domain)
        else:
            wf_result = \
                webfinger_meta(http_prefix, domain_full)
        if wf_result:
            msg = wf_result.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'application/xrd+xml', msglen,
                        None, calling_domain, True)
            write2(self, msg)
            return True
        http_404(self, 6)
        return True
    if path.startswith('/api/statusnet') or \
       path.startswith('/api/gnusocial') or \
       path.startswith('/siteinfo') or \
       path.startswith('/poco') or \
       path.startswith('/friendi'):
        http_404(self, 7)
        return True
    # protocol handler. See https://fedi-to.github.io/protocol-handler.html
    if path.startswith('/.well-known/protocol-handler'):
        if calling_domain.endswith('.onion'):
            protocol_url, _ = \
                wellknown_protocol_handler(path, 'http', onion_domain)
        elif calling_domain.endswith('.i2p'):
            protocol_url, _ = \
                wellknown_protocol_handler(path, 'http', i2p_domain)
        else:
            protocol_url, _ = \
                wellknown_protocol_handler(path, http_prefix, domain_full)
        if protocol_url:
            redirect_headers(self, protocol_url, cookie,
                             calling_domain, 308)
        else:
            http_404(self, 8)
        return True
    # nodeinfo
    if path.startswith('/.well-known/nodeinfo') or \
       path.startswith('/.well-known/x-nodeinfo'):
        if calling_domain.endswith('.onion') and onion_domain:
            wf_result = \
                webfinger_node_info('http', onion_domain)
        elif (calling_domain.endswith('.i2p') and i2p_domain):
            wf_result = \
                webfinger_node_info('http', i2p_domain)
        else:
            wf_result = \
                webfinger_node_info(http_prefix, domain_full)
        if wf_result:
            msg_str = json.dumps(wf_result)
            msg_str = convert_domains(calling_domain, referer_domain,
                                      msg_str, http_prefix, domain,
                                      onion_domain, i2p_domain)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            if has_accept(self, calling_domain):
                accept_str = self.headers.get('Accept')
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, True)
            else:
                set_headers(self, 'application/ld+json', msglen,
                            None, calling_domain, True)
            write2(self, msg)
            return True
        http_404(self, 9)
        return True

    if debug:
        print('DEBUG: WEBFINGER lookup ' + path + ' ' + str(base_dir))
    wf_result = \
        webfinger_lookup(path, base_dir,
                         domain, onion_domain,
                         i2p_domain, port, debug)
    if wf_result:
        msg_str = json.dumps(wf_result)
        msg_str = convert_domains(calling_domain, referer_domain,
                                  msg_str, http_prefix, domain,
                                  onion_domain, i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'application/jrd+json', msglen,
                    None, calling_domain, True)
        write2(self, msg)
    else:
        if debug:
            print('DEBUG: WEBFINGER lookup 404 ' + path)
        http_404(self, 10)
    return True
