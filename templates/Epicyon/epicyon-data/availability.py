__filename__ = "availability.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"

import os
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from posts import get_person_box
from session import post_json
from utils import has_object_string
from utils import get_full_domain
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import load_json
from utils import save_json
from utils import acct_dir
from utils import local_actor_url
from utils import has_actor
from utils import get_actor_from_post


def set_availability(base_dir: str, nickname: str, domain: str,
                     status: str) -> bool:
    """Set an availability status
    """
    # avoid giant strings
    if len(status) > 128:
        return False
    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if not os.path.isfile(actor_filename):
        return False
    actor_json = load_json(actor_filename)
    if actor_json:
        actor_json['availability'] = status
        save_json(actor_json, actor_filename)
    return True


def get_availability(base_dir: str, nickname: str, domain: str) -> str:
    """Returns the availability for a given person
    """
    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if not os.path.isfile(actor_filename):
        return False
    actor_json = load_json(actor_filename)
    if actor_json:
        if not actor_json.get('availability'):
            return None
        return actor_json['availability']
    return None


def outbox_availability(base_dir: str, nickname: str, message_json: {},
                        debug: bool) -> bool:
    """Handles receiving an availability update
    """
    if not message_json.get('type'):
        return False
    if not message_json['type'] == 'Availability':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string(message_json, debug):
        return False

    actor_url = get_actor_from_post(message_json)
    actor_nickname = get_nickname_from_actor(actor_url)
    if not actor_nickname:
        return False
    if actor_nickname != nickname:
        return False
    domain, _ = get_domain_from_actor(actor_url)
    if not domain:
        return False
    status = message_json['object'].replace('"', '')

    return set_availability(base_dir, nickname, domain, status)


def send_availability_via_server(base_dir: str, session,
                                 nickname: str, password: str,
                                 domain: str, port: int,
                                 http_prefix: str,
                                 status: str,
                                 cached_webfingers: {}, person_cache: {},
                                 debug: bool, project_version: str,
                                 signing_priv_key_pem: str,
                                 system_language: str,
                                 mitm_servers: []) -> {}:
    """Sets the availability for a person via c2s
    """
    if not session:
        print('WARN: No session for send_availability_via_server')
        return 6

    domain_full = get_full_domain(domain, port)

    to_url = local_actor_url(http_prefix, nickname, domain_full)
    cc_url = to_url + '/followers'

    new_availability_json = {
        'type': 'Availability',
        'actor': to_url,
        'object': '"' + status + '"',
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + domain_full + '/@' + nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: availability webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: availability webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache, project_version,
                            http_prefix, nickname,
                            domain, post_to_box, 57262,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: availability no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: availability no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, domain_full,
                            session, new_availability_json, [],
                            inbox_url, headers, 30, True)
    if not post_result:
        print('WARN: availability failed to post')

    if debug:
        print('DEBUG: c2s POST availability success')

    return new_availability_json
