__filename__ = "like.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os
from pprint import pprint
from flags import has_group_type
from flags import url_permitted
from utils import has_object_string
from utils import has_object_string_object
from utils import has_object_string_type
from utils import remove_domain_port
from utils import has_object_dict
from utils import has_users_path
from utils import get_full_domain
from utils import remove_id_ending
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import locate_post
from utils import undo_likes_collection_entry
from utils import local_actor_url
from utils import load_json
from utils import save_json
from utils import remove_post_from_cache
from utils import get_cached_post_filename
from utils import get_actor_from_post
from posts import send_signed_json
from session import post_json
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from posts import get_person_box


def no_of_likes(post_json_object: {}) -> int:
    """Returns the number of likes on a given post
    """
    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']
    if not obj.get('likes'):
        return 0
    if not isinstance(obj['likes'], dict):
        return 0
    if not obj['likes'].get('items'):
        obj['likes']['items']: list[dict] = []
        obj['likes']['totalItems'] = 0
    return len(obj['likes']['items'])


def liked_by_person(post_json_object: {}, nickname: str, domain: str) -> bool:
    """Returns True if the given post is liked by the given person
    """
    if no_of_likes(post_json_object) == 0:
        return False
    actor_match = domain + '/users/' + nickname

    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']

    for item in obj['likes']['items']:
        if item['actor'].endswith(actor_match):
            return True
    return False


def _create_like(recent_posts_cache: {},
                 session, base_dir: str, federation_list: [],
                 nickname: str, domain: str, port: int,
                 cc_list: [], http_prefix: str,
                 object_url: str, actor_liked: str,
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
    """Creates a like
    actor is the person doing the liking
    'to' might be a specific person (actor) whose post was liked
    object is typically the url of the message which was liked
    """
    if not url_permitted(object_url, federation_list):
        return None

    full_domain = get_full_domain(domain, port)

    new_like_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Like',
        'actor': local_actor_url(http_prefix, nickname, full_domain),
        'object': object_url
    }
    if cc_list:
        if len(cc_list) > 0:
            new_like_json['cc'] = cc_list

    # Extract the domain and nickname from a statuses link
    liked_post_nickname = None
    liked_post_domain = None
    liked_post_port = None
    group_account = False
    if actor_liked:
        liked_post_nickname = get_nickname_from_actor(actor_liked)
        liked_post_domain, liked_post_port = get_domain_from_actor(actor_liked)
        group_account = has_group_type(base_dir, actor_liked, person_cache)
    else:
        if has_users_path(object_url):
            liked_post_nickname = get_nickname_from_actor(object_url)
            liked_post_domain, liked_post_port = \
                get_domain_from_actor(object_url)
            if liked_post_nickname and liked_post_domain:
                if '/' + str(liked_post_nickname) + '/' in object_url:
                    actor_liked = \
                        object_url.split('/' +
                                         liked_post_nickname + '/')[0] + \
                        '/' + liked_post_nickname
                    group_account = \
                        has_group_type(base_dir, actor_liked, person_cache)

    if liked_post_nickname:
        post_filename = locate_post(base_dir, nickname, domain, object_url)
        if not post_filename:
            print('DEBUG: like base_dir: ' + base_dir)
            print('DEBUG: like nickname: ' + nickname)
            print('DEBUG: like domain: ' + domain)
            print('DEBUG: like object_url: ' + object_url)
            return None

        actor_url = get_actor_from_post(new_like_json)
        update_likes_collection(recent_posts_cache,
                                base_dir, post_filename, object_url,
                                actor_url,
                                nickname, domain, debug, None)
        extra_headers = {}
        send_signed_json(new_like_json, session, base_dir,
                         nickname, domain, port,
                         liked_post_nickname, liked_post_domain,
                         liked_post_port,
                         http_prefix, client_to_server, federation_list,
                         send_threads, post_log, cached_webfingers,
                         person_cache,
                         debug, project_version, None, group_account,
                         signing_priv_key_pem, 7367374,
                         curr_domain, onion_domain, i2p_domain,
                         extra_headers, sites_unavailable,
                         system_language, mitm_servers)

    return new_like_json


def like_post(recent_posts_cache: {},
              session, base_dir: str, federation_list: [],
              nickname: str, domain: str, port: int, http_prefix: str,
              like_nickname: str, like_domain: str, like_port: int,
              cc_list: [],
              like_status_number: int, client_to_server: bool,
              send_threads: [], post_log: [],
              person_cache: {}, cached_webfingers: {},
              debug: bool, project_version: str,
              signing_priv_key_pem: str,
              curr_domain: str, onion_domain: str, i2p_domain: str,
              sites_unavailable: [],
              system_language: str,
              mitm_servers: []) -> {}:
    """Likes a given status post. This is only used by unit tests
    """
    like_domain = get_full_domain(like_domain, like_port)

    actor_liked = local_actor_url(http_prefix, like_nickname, like_domain)
    object_url = actor_liked + '/statuses/' + str(like_status_number)

    return _create_like(recent_posts_cache,
                        session, base_dir, federation_list,
                        nickname, domain, port,
                        cc_list, http_prefix, object_url, actor_liked,
                        client_to_server,
                        send_threads, post_log, person_cache,
                        cached_webfingers,
                        debug, project_version, signing_priv_key_pem,
                        curr_domain, onion_domain, i2p_domain,
                        sites_unavailable, system_language,
                        mitm_servers)


def send_like_via_server(base_dir: str, session,
                         from_nickname: str, password: str,
                         from_domain: str, from_port: int,
                         http_prefix: str, like_url: str,
                         cached_webfingers: {}, person_cache: {},
                         debug: bool, project_version: str,
                         signing_priv_key_pem: str,
                         system_language: str,
                         mitm_servers: []) -> {}:
    """Creates a like via c2s
    """
    if not session:
        print('WARN: No session for send_like_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)

    new_like_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Like',
        'actor': actor,
        'object': like_url
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: like webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: like webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            from_nickname, from_domain,
                            post_to_box, 72873,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: like no ' + post_to_box + ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: like no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_like_json, [], inbox_url,
                            headers, 3, True)
    if not post_result:
        if debug:
            print('WARN: POST like failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST like success')

    return new_like_json


def send_undo_like_via_server(base_dir: str, session,
                              from_nickname: str, password: str,
                              from_domain: str, from_port: int,
                              http_prefix: str, like_url: str,
                              cached_webfingers: {}, person_cache: {},
                              debug: bool, project_version: str,
                              signing_priv_key_pem: str,
                              system_language: str,
                              mitm_servers: []) -> {}:
    """Undo a like via c2s
    """
    if not session:
        print('WARN: No session for send_undo_like_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)

    new_undo_like_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Undo',
        'actor': actor,
        'object': {
            'type': 'Like',
            'actor': actor,
            'object': like_url
        }
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: unlike webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        if debug:
            print('WARN: unlike webfinger for ' + handle +
                  ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache, project_version,
                            http_prefix, from_nickname,
                            from_domain, post_to_box,
                            72625, system_language,
                            mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: unlike no ' + post_to_box + ' was found for ' +
                  handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: unlike no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_undo_like_json, [], inbox_url,
                            headers, 3, True)
    if not post_result:
        if debug:
            print('WARN: POST unlike failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST unlike success')

    return new_undo_like_json


def outbox_like(recent_posts_cache: {},
                base_dir: str, nickname: str, domain: str,
                message_json: {}, debug: bool) -> None:
    """ When a like request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        if debug:
            print('DEBUG: like - no type')
        return
    if not message_json['type'] == 'Like':
        if debug:
            print('DEBUG: not a like')
        return
    if not has_object_string(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s like request arrived in outbox')

    message_id = remove_id_ending(message_json['object'])
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s like post not found in inbox or outbox')
            print(message_id)
        return True
    actor_url = get_actor_from_post(message_json)
    update_likes_collection(recent_posts_cache,
                            base_dir, post_filename, message_id,
                            actor_url,
                            nickname, domain, debug, None)
    if debug:
        print('DEBUG: post liked via c2s - ' + post_filename)


def outbox_undo_like(recent_posts_cache: {},
                     base_dir: str, nickname: str, domain: str,
                     message_json: {}, debug: bool) -> None:
    """ When an undo like request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        return
    if not message_json['type'] == 'Undo':
        return
    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'Like':
        if debug:
            print('DEBUG: not a undo like')
        return
    if not has_object_string_object(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s undo like request arrived in outbox')

    message_id = remove_id_ending(message_json['object']['object'])
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s undo like post not found in inbox or outbox')
            print(message_id)
        return True
    actor_url = get_actor_from_post(message_json)
    undo_likes_collection_entry(recent_posts_cache, base_dir, post_filename,
                                actor_url, domain, debug, None)
    if debug:
        print('DEBUG: post undo liked via c2s - ' + post_filename)


def update_likes_collection(recent_posts_cache: {},
                            base_dir: str, post_filename: str,
                            object_url: str, actor: str,
                            nickname: str, domain: str, debug: bool,
                            post_json_object: {}) -> None:
    """Updates the likes collection within a post
    """
    if not post_json_object:
        post_json_object = load_json(post_filename)
    if not post_json_object:
        return

    # remove any cached version of this post so that the
    # like icon is changed
    remove_post_from_cache(post_json_object, recent_posts_cache)
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname,
                                 domain, post_json_object)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
            except OSError:
                print('EX: update_likes_collection unable to delete ' +
                      cached_post_filename)

    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']

    # append 'likes' to the object url to create the collection_id
    likes_ending = '/likes'
    if not object_url.endswith(likes_ending):
        likes_of = object_url
        collection_id = object_url + likes_ending
    else:
        collection_id = object_url
        likes_len = len(collection_id) - len(likes_ending)
        likes_of = collection_id[:likes_len]

    if not obj.get('likes'):
        if debug:
            print('DEBUG: Adding initial like to ' + object_url)
        likes_json = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'id': collection_id,
            'likesOf': likes_of,
            'type': 'Collection',
            "totalItems": 1,
            'items': [{
                'type': 'Like',
                'actor': actor
            }]
        }
        obj['likes'] = likes_json
    else:
        if not obj['likes'].get('items'):
            obj['likes']['items']: list[dict] = []
        for like_item in obj['likes']['items']:
            if like_item.get('actor'):
                if like_item['actor'] == actor:
                    # already liked
                    return
        new_like = {
            'type': 'Like',
            'actor': actor
        }
        obj['likes']['items'].append(new_like)
        it_len = len(obj['likes']['items'])
        obj['likes']['totalItems'] = it_len

    if debug:
        print('DEBUG: saving post with likes added')
        pprint(post_json_object)
    save_json(post_json_object, post_filename)
