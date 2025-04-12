__filename__ = "delete.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os
from datetime import datetime, timezone
from utils import date_from_numbers
from utils import has_object_string
from utils import remove_domain_port
from utils import has_users_path
from utils import get_full_domain
from utils import remove_id_ending
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import locate_post
from utils import delete_post
from utils import remove_moderation_post_from_index
from utils import local_actor_url
from utils import date_utcnow
from utils import date_epoch
from utils import get_actor_from_post
from session import post_json
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from posts import get_person_box


def send_delete_via_server(base_dir: str, session,
                           from_nickname: str, password: str,
                           from_domain: str, from_port: int,
                           http_prefix: str, delete_object_url: str,
                           cached_webfingers: {}, person_cache: {},
                           debug: bool, project_version: str,
                           signing_priv_key_pem: str,
                           system_language: str,
                           mitm_servers: []) -> {}:
    """Creates a delete request message via c2s
    """
    if not session:
        print('WARN: No session for send_delete_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = actor + '/followers'

    new_delete_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'actor': actor,
        'cc': [cc_url],
        'object': delete_object_url,
        'to': [to_url],
        'type': 'Delete'
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: delete webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: delete webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem, origin_domain,
                            base_dir, session,
                            wf_request, person_cache,
                            project_version, http_prefix,
                            from_nickname,
                            from_domain, post_to_box, 53036,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: delete no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: delete no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, new_delete_json, [], inbox_url, headers, 3, True)
    if not post_result:
        if debug:
            print('DEBUG: POST delete failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST delete request success')

    return new_delete_json


def outbox_delete(base_dir: str, http_prefix: str,
                  nickname: str, domain: str,
                  message_json: {}, debug: bool,
                  allow_deletion: bool,
                  recent_posts_cache: {}) -> None:
    """ When a delete request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        if debug:
            print('DEBUG: delete - no type')
        return
    if not message_json['type'] == 'Delete':
        if debug:
            print('DEBUG: not a delete')
        return
    if not has_object_string(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s delete request arrived in outbox')
    delete_prefix = http_prefix + '://' + domain
    actor_url = get_actor_from_post(message_json)
    if (not allow_deletion and
        (not message_json['object'].startswith(delete_prefix) or
         not actor_url.startswith(delete_prefix))):
        if debug:
            print('DEBUG: delete not permitted from other instances')
        return
    message_id = remove_id_ending(message_json['object'])
    if '/statuses/' not in message_id:
        if debug:
            print('DEBUG: c2s delete object is not a status')
        return
    if not has_users_path(message_id):
        if debug:
            print('DEBUG: c2s delete object has no nickname')
        return
    delete_nickname = get_nickname_from_actor(message_id)
    if delete_nickname != nickname:
        if debug:
            print("DEBUG: you can't delete a post which " +
                  "wasn't created by you (nickname does not match)")
        return
    delete_domain, _ = get_domain_from_actor(message_id)
    domain = remove_domain_port(domain)
    if delete_domain != domain:
        if debug:
            print("DEBUG: you can't delete a post which " +
                  "wasn't created by you (domain does not match)")
        return
    remove_moderation_post_from_index(base_dir, message_id, debug)
    post_filename = locate_post(base_dir, delete_nickname, delete_domain,
                                message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s delete post not found in inbox or outbox')
            print(message_id)
        return True
    delete_post(base_dir, http_prefix, delete_nickname, delete_domain,
                post_filename, debug, recent_posts_cache, True)
    if debug:
        print('DEBUG: post deleted via c2s - ' + post_filename)


def remove_old_hashtags(base_dir: str, max_months: int) -> str:
    """Remove old hashtags
    """
    max_months = min(max_months, 11)
    prev_date = date_from_numbers(1970, 1 + max_months, 1, 0, 0)
    max_days_since_epoch = (date_utcnow() - prev_date).days
    remove_hashtags: list[str] = []

    for _, _, files in os.walk(base_dir + '/tags'):
        for fname in files:
            tags_filename = os.path.join(base_dir + '/tags', fname)
            if not os.path.isfile(tags_filename):
                continue
            # get last modified datetime
            mod_time_since_epoc = os.path.getmtime(tags_filename)
            last_modified_date = \
                datetime.fromtimestamp(mod_time_since_epoc,
                                       timezone.utc)
            prev_date_epoch = date_epoch()
            file_days_since_epoch = \
                (last_modified_date - prev_date_epoch).days

            # check of the file is too old
            if file_days_since_epoch < max_days_since_epoch:
                remove_hashtags.append(tags_filename)
        break

    for remove_filename in remove_hashtags:
        try:
            os.remove(remove_filename)
        except OSError:
            print('EX: remove_old_hashtags unable to delete ' +
                  remove_filename)
