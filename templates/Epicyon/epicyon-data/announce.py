""" ActivityPub Announce (aka retweet/boost) """

__filename__ = "announce.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os
from flags import has_group_type
from flags import url_permitted
from utils import text_in_file
from utils import get_user_paths
from utils import has_object_string_object
from utils import has_object_dict
from utils import remove_domain_port
from utils import remove_id_ending
from utils import has_users_path
from utils import get_full_domain
from utils import get_status_number
from utils import create_outbox_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import locate_post
from utils import save_json
from utils import undo_announce_collection_entry
from utils import update_announce_collection
from utils import local_actor_url
from utils import replace_users_with_at
from utils import has_actor
from utils import has_object_string_type
from utils import get_actor_from_post
from posts import send_signed_json
from posts import get_person_box
from session import post_json
from webfinger import webfinger_handle
from auth import create_basic_auth_header


def no_of_announces(post_json_object: {}) -> int:
    """Returns the number of announces on a given post
    """
    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']
    if not obj.get('shares'):
        return 0
    if not isinstance(obj['shares'], dict):
        return 0
    if not obj['shares'].get('items'):
        obj['shares']['items']: list[dict] = []
        obj['shares']['totalItems'] = 0
    return len(obj['shares']['items'])


def is_announce(post_json_object: {}) -> bool:
    """Is the given post an announce?
    """
    if not post_json_object.get('type'):
        return False
    if post_json_object['type'] != 'Announce':
        return False
    return True


def is_self_announce(post_json_object: {}) -> bool:
    """Is the given post a self announce?
    """
    if not post_json_object.get('actor'):
        return False
    if not post_json_object.get('type'):
        return False
    if post_json_object['type'] != 'Announce':
        return False
    if not post_json_object.get('object'):
        return False
    actor_url = get_actor_from_post(post_json_object)
    if not isinstance(actor_url, str):
        return False
    if not isinstance(post_json_object['object'], str):
        return False
    return actor_url in post_json_object['object']


def outbox_announce(recent_posts_cache: {},
                    base_dir: str, message_json: {}, debug: bool) -> bool:
    """ Adds or removes announce entries from the shares collection
    within a given post
    """
    if not has_actor(message_json, debug):
        return False
    if not isinstance(message_json['actor'], str):
        return False
    if not message_json.get('type'):
        return False
    if not message_json.get('object'):
        return False
    if message_json['type'] == 'Announce':
        if not isinstance(message_json['object'], str):
            return False
        if is_self_announce(message_json):
            return False
        actor_url = get_actor_from_post(message_json)
        nickname = get_nickname_from_actor(actor_url)
        if not nickname:
            print('WARN: no nickname found in ' + actor_url)
            return False
        domain, _ = get_domain_from_actor(actor_url)
        if not domain:
            print('WARN: no domain found in ' + actor_url)
            return False
        post_filename = locate_post(base_dir, nickname, domain,
                                    message_json['object'])
        if post_filename:
            update_announce_collection(recent_posts_cache,
                                       base_dir, post_filename,
                                       actor_url,
                                       nickname, domain, debug)
            return True
    elif message_json['type'] == 'Undo':
        if not has_object_string_type(message_json, debug):
            return False
        if message_json['object']['type'] == 'Announce':
            if not isinstance(message_json['object']['object'], str):
                return False
            actor_url = get_actor_from_post(message_json)
            nickname = get_nickname_from_actor(actor_url)
            if not nickname:
                print('WARN: no nickname found in ' + actor_url)
                return False
            domain, _ = get_domain_from_actor(actor_url)
            if not domain:
                print('WARN: no domain found in ' + actor_url)
                return False
            post_filename = locate_post(base_dir, nickname, domain,
                                        message_json['object']['object'])
            if post_filename:
                undo_announce_collection_entry(recent_posts_cache,
                                               base_dir, post_filename,
                                               actor_url,
                                               domain, debug)
                return True
    return False


def announced_by_person(is_announced: bool, post_actor: str,
                        nickname: str, domain_full: str) -> bool:
    """Returns True if the given post is announced by the given person
    """
    if not post_actor:
        return False
    if is_announced:
        users_paths = get_user_paths()
        for possible_path in users_paths:
            if post_actor.endswith(domain_full + possible_path + nickname):
                return True
    return False


def create_announce(session, base_dir: str, federation_list: [],
                    nickname: str, domain: str, port: int,
                    to_url: str, cc_url: str, http_prefix: str,
                    object_url: str, save_to_file: bool,
                    client_to_server: bool,
                    send_threads: [], post_log: [],
                    person_cache: {}, cached_webfingers: {},
                    debug: bool, project_version: str,
                    signing_priv_key_pem: str,
                    curr_domain: str,
                    onion_domain: str, i2p_domain: str,
                    sites_unavailable: [],
                    system_language: str,
                    mitm_servers: []) -> {}:
    """Creates an announce message
    Typically to_url will be https://www.w3.org/ns/activitystreams#Public
    and cc_url might be a specific person favorited or repeated and the
    followers url object_url is typically the url of the message,
    corresponding to url or atomUri in createPostBase
    """
    if not url_permitted(object_url, federation_list):
        return None

    domain = remove_domain_port(domain)
    full_domain = get_full_domain(domain, port)

    status_number, published = get_status_number()
    new_announce_id = http_prefix + '://' + full_domain + \
        '/users/' + nickname + '/statuses/' + status_number
    atom_uri_str = local_actor_url(http_prefix, nickname, full_domain) + \
        '/statuses/' + status_number
    new_announce = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'actor': local_actor_url(http_prefix, nickname, full_domain),
        'atomUri': atom_uri_str,
        'cc': [],
        'id': new_announce_id + '/activity',
        'object': object_url,
        'published': published,
        'to': [to_url],
        'type': 'Announce'
    }
    if cc_url:
        if len(cc_url) > 0:
            new_announce['cc'] = [cc_url]
    if save_to_file:
        outbox_dir = create_outbox_dir(nickname, domain, base_dir)
        filename = \
            outbox_dir + '/' + new_announce_id.replace('/', '#') + '.json'
        save_json(new_announce, filename)

    announce_nickname = None
    announce_domain = None
    announce_port = None
    group_account = False
    if has_users_path(object_url):
        announce_nickname = get_nickname_from_actor(object_url)
        announce_domain, announce_port = get_domain_from_actor(object_url)
        if announce_nickname and announce_domain:
            if '/' + str(announce_nickname) + '/' in object_url:
                announce_actor = \
                    object_url.split('/' + announce_nickname + '/')[0] + \
                    '/' + announce_nickname
                if has_group_type(base_dir, announce_actor, person_cache):
                    group_account = True

    if announce_nickname and announce_domain:
        extra_headers = {}
        send_signed_json(new_announce, session, base_dir,
                         nickname, domain, port,
                         announce_nickname, announce_domain,
                         announce_port,
                         http_prefix, client_to_server, federation_list,
                         send_threads, post_log, cached_webfingers,
                         person_cache,
                         debug, project_version, None, group_account,
                         signing_priv_key_pem, 639633,
                         curr_domain, onion_domain, i2p_domain,
                         extra_headers, sites_unavailable,
                         system_language, mitm_servers)

    return new_announce


def announce_public(session, base_dir: str, federation_list: [],
                    nickname: str, domain: str, port: int, http_prefix: str,
                    object_url: str, client_to_server: bool,
                    send_threads: [], post_log: [],
                    person_cache: {}, cached_webfingers: {},
                    debug: bool, project_version: str,
                    signing_priv_key_pem: str,
                    curr_domain: str,
                    onion_domain: str, i2p_domain: str,
                    sites_unavailable: [],
                    system_language: str,
                    mitm_servers: []) -> {}:
    """Makes a public announcement
    """
    from_domain = get_full_domain(domain, port)

    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = local_actor_url(http_prefix, nickname, from_domain) + '/followers'
    return create_announce(session, base_dir, federation_list,
                           nickname, domain, port,
                           to_url, cc_url, http_prefix,
                           object_url, True, client_to_server,
                           send_threads, post_log,
                           person_cache, cached_webfingers,
                           debug, project_version,
                           signing_priv_key_pem, curr_domain,
                           onion_domain, i2p_domain,
                           sites_unavailable,
                           system_language, mitm_servers)


def send_announce_via_server(base_dir: str, session,
                             from_nickname: str, password: str,
                             from_domain: str, from_port: int,
                             http_prefix: str, repeat_object_url: str,
                             cached_webfingers: {}, person_cache: {},
                             debug: bool, project_version: str,
                             signing_priv_key_pem: str,
                             system_language: str,
                             mitm_servers: []) -> {}:
    """Creates an announce message via c2s
    """
    if not session:
        print('WARN: No session for send_announce_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    actor_str = local_actor_url(http_prefix, from_nickname, from_domain_full)
    cc_url = actor_str + '/followers'

    status_number, published = get_status_number()
    new_announce_id = actor_str + '/statuses/' + status_number
    new_announce_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'actor': actor_str,
        'atomUri': new_announce_id,
        'cc': [cc_url],
        'id': new_announce_id + '/activity',
        'object': repeat_object_url,
        'published': published,
        'to': [to_url],
        'type': 'Announce'
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: announce webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: announce webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id,
     _, _, _, _) = get_person_box(signing_priv_key_pem,
                                  origin_domain,
                                  base_dir, session, wf_request,
                                  person_cache,
                                  project_version, http_prefix,
                                  from_nickname, from_domain,
                                  post_to_box, 73528,
                                  system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: announce no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: announce no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_announce_json, [], inbox_url,
                            headers, 3, True)
    if not post_result:
        print('WARN: announce not posted')

    if debug:
        print('DEBUG: c2s POST announce success')

    return new_announce_json


def send_undo_announce_via_server(base_dir: str, session,
                                  undo_post_json_object: {},
                                  nickname: str, password: str,
                                  domain: str, port: int, http_prefix: str,
                                  cached_webfingers: {}, person_cache: {},
                                  debug: bool, project_version: str,
                                  signing_priv_key_pem: str,
                                  system_language: str,
                                  mitm_servers: []) -> {}:
    """Undo an announce message via c2s
    """
    if not session:
        print('WARN: No session for send_undo_announce_via_server')
        return 6

    domain_full = get_full_domain(domain, port)

    actor = local_actor_url(http_prefix, nickname, domain_full)
    handle = replace_users_with_at(actor)

    status_number, _ = get_status_number()
    unannounce_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': actor + '/statuses/' + str(status_number) + '/undo',
        'type': 'Undo',
        'actor': actor,
        'object': undo_post_json_object['object']
    }

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: undo announce webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: undo announce webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = domain
    (inbox_url, _, _, from_person_id,
     _, _, _, _) = get_person_box(signing_priv_key_pem,
                                  origin_domain,
                                  base_dir, session, wf_request,
                                  person_cache,
                                  project_version, http_prefix,
                                  nickname, domain,
                                  post_to_box, 73528,
                                  system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: undo announce no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: undo announce no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, domain_full,
                            session, unannounce_json, [], inbox_url,
                            headers, 3, True)
    if not post_result:
        print('WARN: undo announce not posted')

    if debug:
        print('DEBUG: c2s POST undo announce success')

    return unannounce_json


def outbox_undo_announce(recent_posts_cache: {},
                         base_dir: str, nickname: str, domain: str,
                         message_json: {}, debug: bool) -> None:
    """ When an undo announce is received by the outbox from c2s
    """
    if not message_json.get('type'):
        return
    if not message_json['type'] == 'Undo':
        return
    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'Announce':
        if debug:
            print('DEBUG: not a undo announce')
        return
    if not has_object_string_object(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s undo announce request arrived in outbox')

    message_id = remove_id_ending(message_json['object']['object'])
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s undo announce post not found in inbox or outbox')
            print(message_id)
        return True
    actor_url = get_actor_from_post(message_json)
    undo_announce_collection_entry(recent_posts_cache, base_dir, post_filename,
                                   actor_url, domain, debug)
    if debug:
        print('DEBUG: post undo announce via c2s - ' + post_filename)


def announce_seen(base_dir: str, nickname: str, domain: str,
                  message_json: {}) -> bool:
    """have the given announce been seen?
    """
    if not message_json.get('id'):
        return False
    if not isinstance(message_json['id'], str):
        return False
    if not message_json.get('object'):
        return False
    if not isinstance(message_json['object'], str):
        return False

    # is this your own announce?
    announce_id = remove_id_ending(message_json['id'])
    if '://' + domain in announce_id and \
       '/users/' + nickname + '/' in announce_id:
        return False

    post_url = remove_id_ending(message_json['object'])
    post_filename = locate_post(base_dir, nickname, domain, post_url)
    if not post_filename:
        return False
    seen_filename = post_filename + '.seen'
    if not os.path.isfile(seen_filename):
        return False

    if text_in_file(announce_id, seen_filename):
        return False
    return True


def mark_announce_as_seen(base_dir: str, nickname: str, domain: str,
                          message_json: {}) -> None:
    """Marks the given announce post as seen
    """
    if not message_json.get('id'):
        return
    if not isinstance(message_json['id'], str):
        return
    if not message_json.get('object'):
        return
    if not isinstance(message_json['object'], str):
        return
    post_url = remove_id_ending(message_json['object'])
    post_filename = locate_post(base_dir, nickname, domain, post_url)
    if not post_filename:
        return
    seen_filename = post_filename + '.seen'
    if os.path.isfile(seen_filename):
        return
    announce_id = remove_id_ending(message_json['id'])
    try:
        with open(seen_filename, 'w+', encoding='utf-8') as fp_seen:
            fp_seen.write(announce_id)
    except OSError:
        print('EX: mark_announce_as_seen unable to write ' + seen_filename)
