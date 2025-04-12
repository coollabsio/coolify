__filename__ = "daemon_get_collections.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import json
from context import get_individual_post_context
from httpcodes import write2
from httpcodes import http_404
from httpheaders import set_headers
from posts import json_pin_post
from utils import convert_domains
from utils import get_json_content_from_accept
from follow import get_following_feed


def get_featured_collection(self, calling_domain: str,
                            referer_domain: str,
                            base_dir: str,
                            http_prefix: str,
                            nickname: str, domain: str,
                            domain_full: str,
                            system_language: str,
                            onion_domain: str,
                            i2p_domain: str) -> None:
    """Returns the featured posts collections in
    actor/collections/featured
    """
    featured_collection = \
        json_pin_post(base_dir, http_prefix,
                      nickname, domain, domain_full, system_language)
    msg_str = json.dumps(featured_collection,
                         ensure_ascii=False)
    msg_str = convert_domains(calling_domain, referer_domain,
                              msg_str, http_prefix,
                              domain, onion_domain, i2p_domain)
    msg = msg_str.encode('utf-8')
    msglen = len(msg)
    accept_str = self.headers['Accept']
    protocol_str = \
        get_json_content_from_accept(accept_str)
    set_headers(self, protocol_str, msglen,
                None, calling_domain, False)
    write2(self, msg)


def get_featured_tags_collection(self, calling_domain: str,
                                 referer_domain: str,
                                 path: str, http_prefix: str,
                                 domain_full: str, domain: str,
                                 onion_domain: str, i2p_domain: str) -> None:
    """Returns the featured tags collections in
    actor/collections/featuredTags
    """
    post_context = get_individual_post_context()
    featured_tags_collection = {
        '@context': post_context,
        'id': http_prefix + '://' + domain_full + path,
        'orderedItems': [],
        'totalItems': 0,
        'type': 'OrderedCollection'
    }
    msg_str = json.dumps(featured_tags_collection,
                         ensure_ascii=False)
    msg_str = convert_domains(calling_domain,
                              referer_domain,
                              msg_str, http_prefix,
                              domain,
                              onion_domain,
                              i2p_domain)
    msg = msg_str.encode('utf-8')
    msglen = len(msg)
    accept_str = self.headers['Accept']
    protocol_str = \
        get_json_content_from_accept(accept_str)
    set_headers(self, protocol_str, msglen,
                None, calling_domain, False)
    write2(self, msg)


def get_following_json(self, base_dir: str, path: str,
                       calling_domain: str, referer_domain: str,
                       http_prefix: str,
                       domain: str, port: int,
                       following_items_per_page: int,
                       debug: bool, list_name: str,
                       onion_domain: str, i2p_domain: str) -> None:
    """Returns json collection for following.txt
    """
    following_json = \
        get_following_feed(base_dir, domain, port, path, http_prefix,
                           True, following_items_per_page, list_name)
    if not following_json:
        if debug:
            print(list_name + ' json feed not found for ' + path)
        http_404(self, 109)
        return
    msg_str = json.dumps(following_json,
                         ensure_ascii=False)
    msg_str = convert_domains(calling_domain,
                              referer_domain,
                              msg_str, http_prefix,
                              domain,
                              onion_domain,
                              i2p_domain)
    msg = msg_str.encode('utf-8')
    msglen = len(msg)
    accept_str = self.headers['Accept']
    protocol_str = \
        get_json_content_from_accept(accept_str)
    set_headers(self, protocol_str, msglen,
                None, calling_domain, False)
    write2(self, msg)
