""" ActivityPub Accept or Reject json """

__filename__ = "acceptreject.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os
import time
from posts import send_signed_json
from flags import has_group_type
from flags import url_permitted
from utils import get_status_number
from utils import get_attributed_to
from utils import get_user_paths
from utils import text_in_file
from utils import has_object_string_object
from utils import has_users_path
from utils import get_full_domain
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from utils import domain_permitted
from utils import follow_person
from utils import acct_dir
from utils import local_actor_url
from utils import has_actor
from utils import has_object_string_type
from utils import get_actor_from_post


def _create_quote_accept_reject(receiving_actor: str,
                                sending_actor: str,
                                federation_list: [],
                                debug: bool,
                                quote_request_id: str,
                                quote_request_object: str,
                                quote_request_instrument: str,
                                accept_type: str) -> {}:
    """Creates an Accept or Reject response to QuoteRequest
    https://codeberg.org/fediverse/fep/src/branch/main/fep/044f/fep-044f.md
    """
    if not receiving_actor or \
       not sending_actor or \
       not quote_request_id or \
       not quote_request_object or \
       not quote_request_instrument:
        return None
    if not url_permitted(sending_actor, federation_list):
        return None

    status_number, _ = get_status_number()

    new_accept = {
        "@context": [
            "https://www.w3.org/ns/activitystreams",
            {
                "toot": "http://joinmastodon.org/ns#",
                "Quote": "toot:QuoteRequest"
            }
        ],
        "type": accept_type,
        "to": [sending_actor],
        "id": receiving_actor + "/statuses/" + status_number,
        "actor": receiving_actor,
        "object": {
            "type": "QuoteRequest",
            "id": quote_request_id,
            "actor": sending_actor,
            "object": "https://example.com/users/alice/statuses/1",
            "instrument": "https://example.org/users/bob/statuses/1"
        }
    }
    if debug:
        print('REJECT: QuoteRequest ' + str(new_accept))
    return new_accept


def _create_accept_reject(federation_list: [],
                          nickname: str, domain: str, port: int,
                          to_url: str, cc_url: str, http_prefix: str,
                          object_json: {}, accept_type: str) -> {}:
    """Accepts or rejects something (eg. a follow request or offer)
    Typically to_url will be https://www.w3.org/ns/activitystreams#Public
    and cc_url might be a specific person favorited or repeated and
    the followers url objectUrl is typically the url of the message,
    corresponding to url or atomUri in createPostBase
    """
    if not object_json.get('actor'):
        return None

    actor_url = get_actor_from_post(object_json)
    if not url_permitted(actor_url, federation_list):
        return None

    domain = get_full_domain(domain, port)

    new_accept = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': accept_type,
        'actor': local_actor_url(http_prefix, nickname, domain),
        'to': [to_url],
        'cc': [],
        'object': object_json
    }
    if cc_url:
        if len(cc_url) > 0:
            new_accept['cc'] = [cc_url]
    return new_accept


def create_accept(federation_list: [],
                  nickname: str, domain: str, port: int,
                  to_url: str, cc_url: str, http_prefix: str,
                  object_json: {}) -> {}:
    """ Create json for ActivityPub Accept """
    return _create_accept_reject(federation_list,
                                 nickname, domain, port,
                                 to_url, cc_url, http_prefix,
                                 object_json, 'Accept')


def create_reject(federation_list: [],
                  nickname: str, domain: str, port: int,
                  to_url: str, cc_url: str, http_prefix: str,
                  object_json: {}) -> {}:
    """ Create json for ActivityPub Reject """
    return _create_accept_reject(federation_list,
                                 nickname, domain, port,
                                 to_url, cc_url,
                                 http_prefix, object_json, 'Reject')


def _reject_quote_request(message_json: {}, domain_full: str,
                          federation_list: [],
                          debug: bool,
                          session, session_onion, session_i2p,
                          base_dir: str,
                          http_prefix: str,
                          send_threads: [], post_log: [],
                          cached_webfingers: {},
                          person_cache: {}, project_version: str,
                          signing_priv_key_pem: str,
                          onion_domain: str, i2p_domain: str,
                          extra_headers: {},
                          sites_unavailable: {},
                          system_language: str,
                          mitm_servers: []) -> bool:
    """ Rejects a QuoteRequest
    https://codeberg.org/fediverse/fep/src/branch/main/fep/044f/fep-044f.md
    """
    sending_actor = None
    receiving_actor = None
    quote_request_id = None
    quote_request_object = None
    quote_request_instrument = None
    if message_json.get('actor'):
        sending_actor = message_json['actor']
    elif message_json.get('instrument'):
        if isinstance(message_json['instrument'], dict):
            instrument_dict = message_json['instrument']
            if instrument_dict.get('attributedTo'):
                sending_actor = \
                    get_attributed_to(instrument_dict['attributedTo'])
            if instrument_dict.get('to'):
                if isinstance(instrument_dict['to'], str):
                    receiving_actor = instrument_dict['to']
                elif isinstance(instrument_dict['to'], list):
                    for receiver in instrument_dict['to']:
                        if '#Public' not in receiver and \
                           '://' + domain_full + '/' in receiver:
                            receiving_actor = receiver
                            break
            if instrument_dict.get('id'):
                quote_request_instrument = instrument_dict['id']
            if instrument_dict.get('object'):
                quote_request_object = instrument_dict['object']
    if message_json.get('id'):
        quote_request_id = message_json['id']
    if not sending_actor:
        return False
    if receiving_actor:
        quote_request_object = receiving_actor
    reject_json = \
        _create_quote_accept_reject(receiving_actor,
                                    sending_actor,
                                    federation_list,
                                    debug,
                                    quote_request_id,
                                    quote_request_object,
                                    quote_request_instrument,
                                    'Reject')
    if reject_json:
        print('REJECT: QuoteRequest from ' + sending_actor)
        nickname = get_nickname_from_actor(receiving_actor)
        domain, from_port = get_domain_from_actor(receiving_actor)
        nickname_to_follow = get_nickname_from_actor(sending_actor)
        domain_to_follow, port = get_domain_from_actor(sending_actor)
        group_account = \
            has_group_type(base_dir, receiving_actor, person_cache)
        if nickname and domain and \
           nickname_to_follow and domain_to_follow:
            if debug:
                print('REJECT: QuoteRequest sending reject ' +
                      str(reject_json))

            curr_session = session
            curr_domain = domain
            curr_port = from_port
            curr_http_prefix = http_prefix
            if onion_domain and \
               not curr_domain.endswith('.onion') and \
               domain_to_follow.endswith('.onion'):
                curr_session = session_onion
                curr_http_prefix = 'http'
                curr_domain = onion_domain
                curr_port = 80
                port = 80
                if debug:
                    print('Domain switched from ' + domain +
                          ' to ' + curr_domain)
            elif (i2p_domain and
                  not curr_domain.endswith('.i2p') and
                  domain_to_follow.endswith('.i2p')):
                curr_session = session_i2p
                curr_http_prefix = 'http'
                curr_domain = i2p_domain
                curr_port = 80
                port = 80
                if debug:
                    print('Domain switched from ' + domain +
                          ' to ' + curr_domain)

            client_to_server = False
            send_signed_json(reject_json, curr_session, base_dir,
                             nickname_to_follow, domain_to_follow, port,
                             nickname, domain, curr_port,
                             curr_http_prefix, client_to_server,
                             federation_list,
                             send_threads, post_log, cached_webfingers,
                             person_cache, debug, project_version, None,
                             group_account, signing_priv_key_pem,
                             726235284, curr_domain, onion_domain, i2p_domain,
                             extra_headers, sites_unavailable,
                             system_language, mitm_servers)
        return True
    return False


def _accept_follow(base_dir: str, message_json: {},
                   federation_list: [], debug: bool,
                   curr_domain: str,
                   onion_domain: str, i2p_domain: str) -> None:
    """ Receiving an ActivityPub follow Accept activity
    Your follow was accepted
    """
    if not has_object_string_type(message_json, debug):
        return
    if message_json['object']['type'] not in ('Follow', 'Join'):
        return
    if debug:
        print('DEBUG: receiving Follow activity')
    if not message_json['object'].get('actor'):
        print('DEBUG: no actor in Follow activity')
        return
    # no, this isn't a mistake
    if not has_object_string_object(message_json, debug):
        return
    if not message_json.get('to'):
        if debug:
            print('DEBUG: No "to" parameter in follow Accept')
        return
    if debug:
        print('DEBUG: follow Accept received ' + str(message_json))
    this_actor = get_actor_from_post(message_json['object'])
    nickname = get_nickname_from_actor(this_actor)
    if not nickname:
        print('WARN: no nickname found in ' + this_actor)
        return
    accepted_domain, accepted_port = get_domain_from_actor(this_actor)
    if not accepted_domain:
        if debug:
            print('DEBUG: domain not found in ' + this_actor)
        return
    if not nickname:
        if debug:
            print('DEBUG: nickname not found in ' + this_actor)
        return
    if accepted_port:
        if '/' + accepted_domain + ':' + str(accepted_port) + \
           '/users/' + nickname not in this_actor:
            if debug:
                print('Port: ' + str(accepted_port))
                print('Expected: /' + accepted_domain + ':' +
                      str(accepted_port) + '/users/' + nickname)
                print('Actual:   ' + this_actor)
                print('DEBUG: unrecognized actor ' + this_actor)
            return
    else:
        actor_found = False
        users_list = get_user_paths()
        for users_str in users_list:
            if '/' + accepted_domain + users_str + nickname in this_actor:
                actor_found = True
                break

        if not actor_found:
            if debug:
                print('Expected: /' + accepted_domain + '/users/' + nickname)
                print('Actual:   ' + this_actor)
                print('DEBUG: unrecognized actor ' + this_actor)
            return
    followed_actor = message_json['object']['object']
    followed_domain, port = get_domain_from_actor(followed_actor)
    if not followed_domain:
        print('DEBUG: no domain found within Follow activity object ' +
              followed_actor)
        return
    followed_domain_full = followed_domain
    if port:
        followed_domain_full = followed_domain + ':' + str(port)
    followed_nickname = get_nickname_from_actor(followed_actor)
    if not followed_nickname:
        print('DEBUG: no nickname found within Follow activity object ' +
              followed_actor)
        return

    # convert from onion/i2p to clearnet accepted domain
    if onion_domain:
        if accepted_domain.endswith('.onion') and \
           not curr_domain.endswith('.onion'):
            accepted_domain = curr_domain
    if i2p_domain:
        if accepted_domain.endswith('.i2p') and \
           not curr_domain.endswith('.i2p'):
            accepted_domain = curr_domain

    accepted_domain_full = accepted_domain
    if accepted_port:
        accepted_domain_full = accepted_domain + ':' + str(accepted_port)

    # has this person already been unfollowed?
    unfollowed_filename = \
        acct_dir(base_dir, nickname, accepted_domain_full) + '/unfollowed.txt'
    if os.path.isfile(unfollowed_filename):
        if text_in_file(followed_nickname + '@' + followed_domain_full,
                        unfollowed_filename):
            if debug:
                print('DEBUG: follow accept arrived for ' +
                      nickname + '@' + accepted_domain_full +
                      ' from ' +
                      followed_nickname + '@' + followed_domain_full +
                      ' but they have been unfollowed')
            return

    # does the url path indicate that this is a group actor
    group_account = has_group_type(base_dir, followed_actor, None, debug)
    if debug:
        print('Accepted follow is a group: ' + str(group_account) +
              ' ' + followed_actor + ' ' + base_dir)

    if follow_person(base_dir,
                     nickname, accepted_domain_full,
                     followed_nickname, followed_domain_full,
                     federation_list, debug, group_account,
                     'following.txt'):
        if debug:
            print('DEBUG: ' + nickname + '@' + accepted_domain_full +
                  ' followed ' +
                  followed_nickname + '@' + followed_domain_full)
    else:
        if debug:
            print('DEBUG: Unable to create follow - ' +
                  nickname + '@' + accepted_domain + ' -> ' +
                  followed_nickname + '@' + followed_domain)


def receive_accept_reject(base_dir: str, domain: str, message_json: {},
                          federation_list: [], debug: bool, curr_domain: str,
                          onion_domain: str, i2p_domain: str) -> bool:
    """Receives an Accept or Reject within the POST section of HTTPServer
    """
    if message_json['type'] not in ('Accept', 'Reject'):
        return False
    if not has_actor(message_json, debug):
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'] + '. Assuming single user instance.')
    domain, _ = get_domain_from_actor(actor_url)
    if not domain_permitted(domain, federation_list):
        if debug:
            print('DEBUG: ' + message_json['type'] +
                  ' from domain not permitted - ' + domain)
        return False
    nickname = get_nickname_from_actor(actor_url)
    if not nickname:
        # single user instance
        nickname = 'dev'
        if debug:
            print('DEBUG: ' + message_json['type'] +
                  ' does not contain a nickname. ' +
                  'Assuming single user instance.')
    # receive follow accept
    _accept_follow(base_dir, message_json, federation_list, debug,
                   curr_domain, onion_domain, i2p_domain)
    if debug:
        print('DEBUG: Uh, ' + message_json['type'] + ', I guess')
    return True


def receive_quote_request(message_json: {}, federation_list: [],
                          debug: bool,
                          domain_full: str,
                          session, session_onion, session_i2p,
                          base_dir: str,
                          http_prefix: str,
                          send_threads: [], post_log: [],
                          cached_webfingers: {},
                          person_cache: {}, project_version: str,
                          signing_priv_key_pem: str,
                          onion_domain: str,
                          i2p_domain: str,
                          extra_headers: {},
                          sites_unavailable: {},
                          system_language: str,
                          mitm_servers: [],
                          last_quote_request) -> bool:
    """Receives a QuoteRequest within the POST section of HTTPServer
    https://codeberg.org/fediverse/fep/src/branch/main/fep/044f/fep-044f.md
    """
    if message_json['type'] != 'QuoteRequest':
        return False
    curr_time = int(time.time())
    seconds_since_last_quote_request = curr_time - last_quote_request
    if seconds_since_last_quote_request < 30:
        # don't handle quote requests too often
        return True
    _reject_quote_request(message_json, domain_full,
                          federation_list, debug,
                          session, session_onion, session_i2p, base_dir,
                          http_prefix,
                          send_threads, post_log,
                          cached_webfingers,
                          person_cache, project_version,
                          signing_priv_key_pem,
                          onion_domain,
                          i2p_domain,
                          extra_headers,
                          sites_unavailable,
                          system_language,
                          mitm_servers)

    return True
