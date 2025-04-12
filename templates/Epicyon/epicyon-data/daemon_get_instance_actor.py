__filename__ = "daemon_get_instance_actor.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from httprequests import request_http
from httpcodes import write2
from httpcodes import http_404
from person import person_lookup
from utils import get_instance_url
from utils import convert_domains
from httpheaders import set_headers
from fitnessFunctions import fitness_performance


def show_instance_actor(self, calling_domain: str,
                        referer_domain: str, path: str,
                        base_dir: str, http_prefix: str,
                        domain: str, domain_full: str,
                        onion_domain: str, i2p_domain: str,
                        getreq_start_time,
                        cookie: str, debug: str,
                        enable_shared_inbox: bool,
                        fitness: {}) -> bool:
    """Shows the instance actor
    """
    if debug:
        print('Instance actor requested by ' + calling_domain)
    if request_http(self.headers, debug):
        http_404(self, 88)
        return False
    actor_json = person_lookup(domain, path, base_dir)
    if not actor_json:
        print('ERROR: no instance actor found')
        http_404(self, 89)
        return False
    accept_str = self.headers['Accept']
    actor_domain_url = get_instance_url(calling_domain,
                                        http_prefix, domain_full,
                                        onion_domain, i2p_domain)
    actor_url = actor_domain_url + '/users/Actor'
    remove_fields = (
        'icon', 'image', 'tts', 'shares',
        'alsoKnownAs', 'hasOccupation', 'featured',
        'featuredTags', 'discoverable', 'published',
        'devices'
    )
    for rfield in remove_fields:
        if rfield in actor_json:
            del actor_json[rfield]
    actor_json['endpoints'] = {}
    if enable_shared_inbox:
        actor_json['endpoints'] = {
            'sharedInbox': actor_domain_url + '/inbox'
        }
    actor_json['name'] = 'ACTOR'
    actor_json['preferredUsername'] = domain_full
    actor_json['id'] = actor_domain_url + '/actor'
    actor_json['type'] = 'Application'
    actor_json['summary'] = 'Instance Actor'
    actor_json['publicKey']['id'] = actor_domain_url + '/actor#main-key'
    actor_json['publicKey']['owner'] = actor_domain_url + '/actor'
    actor_json['url'] = actor_domain_url + '/actor'
    actor_json['inbox'] = actor_url + '/inbox'
    actor_json['followers'] = actor_url + '/followers'
    actor_json['following'] = actor_url + '/following'
    msg_str = json.dumps(actor_json, ensure_ascii=False)
    msg_str = convert_domains(calling_domain,
                              referer_domain,
                              msg_str, http_prefix,
                              domain,
                              onion_domain,
                              i2p_domain)
    msg = msg_str.encode('utf-8')
    msglen = len(msg)
    if 'application/ld+json' in accept_str:
        set_headers(self, 'application/ld+json', msglen,
                    cookie, calling_domain, False)
    elif 'application/jrd+json' in accept_str:
        set_headers(self, 'application/jrd+json', msglen,
                    cookie, calling_domain, False)
    else:
        set_headers(self, 'application/activity+json', msglen,
                    cookie, calling_domain, False)
    write2(self, msg)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_instance_actor',
                        debug)
    return True
