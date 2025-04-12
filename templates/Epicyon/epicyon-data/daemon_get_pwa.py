__filename__ = "daemon_get_pwa.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from httpcodes import write2
from httpheaders import set_headers
from webapp_pwa import pwa_manifest
from utils import convert_domains
from utils import get_json_content_from_accept
from fitnessFunctions import fitness_performance


def progressive_web_app_manifest(self, base_dir: str,
                                 calling_domain: str,
                                 referer_domain: str,
                                 getreq_start_time,
                                 http_prefix: str,
                                 domain: str,
                                 onion_domain: str,
                                 i2p_domain: str,
                                 fitness: {},
                                 debug: bool) -> None:
    """gets the PWA manifest
    """
    manifest = pwa_manifest(base_dir)
    msg_str = json.dumps(manifest, ensure_ascii=False)
    msg_str = convert_domains(calling_domain,
                              referer_domain,
                              msg_str,
                              http_prefix,
                              domain,
                              onion_domain,
                              i2p_domain)
    msg = msg_str.encode('utf-8')

    msglen = len(msg)
    protocol_str = \
        get_json_content_from_accept(self.headers['Accept'])
    set_headers(self, protocol_str, msglen,
                None, calling_domain, False)
    write2(self, msg)
    if debug:
        print('Sent manifest: ' + calling_domain)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_progressive_web_app_manifest',
                        debug)
