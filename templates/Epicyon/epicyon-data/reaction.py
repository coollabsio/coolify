__filename__ = "reaction.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os
import re
import urllib.parse
from pprint import pprint
from flags import has_group_type
from flags import url_permitted
from utils import data_dir
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
from utils import undo_reaction_collection_entry
from utils import local_actor_url
from utils import load_json
from utils import save_json
from utils import remove_post_from_cache
from utils import get_cached_post_filename
from utils import contains_invalid_chars
from utils import remove_eol
from utils import get_actor_from_post
from posts import send_signed_json
from session import post_json
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from posts import get_person_box

# the maximum number of reactions from individual actors which can be
# added to a post. Hence an adversary can't bombard you with sockpuppet
# generated reactions and make the post infeasibly large
MAX_ACTOR_REACTIONS_PER_POST = 64

# regex defining permissable emoji icon range
EMOJI_REGEX = re.compile(r'[\u263a-\U0001f645]')


def valid_emoji_content(emoji_content: str) -> bool:
    """Is the given emoji content valid?
    """
    if not emoji_content:
        return False
    if len(emoji_content) > 2:
        return False
    if len(EMOJI_REGEX.findall(emoji_content)) == 0:
        return False
    if contains_invalid_chars(emoji_content):
        return False
    return True


def _reactionpost(recent_posts_cache: {},
                  session, base_dir: str, federation_list: [],
                  nickname: str, domain: str, port: int,
                  cc_list: [], http_prefix: str,
                  object_url: str, emoji_content: str,
                  actor_reaction: str,
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
    """Creates an emoji reaction
    actor is the person doing the reacting
    'to' might be a specific person (actor) whose post was reaction
    object is typically the url of the message which was reaction
    """
    if not url_permitted(object_url, federation_list):
        return None
    if not valid_emoji_content(emoji_content):
        print('_reaction: Invalid emoji reaction: "' + emoji_content + '"')
        return None

    full_domain = get_full_domain(domain, port)

    new_reaction_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'EmojiReact',
        'actor': local_actor_url(http_prefix, nickname, full_domain),
        'object': object_url,
        'content': emoji_content
    }
    if cc_list:
        if len(cc_list) > 0:
            new_reaction_json['cc'] = cc_list

    # Extract the domain and nickname from a statuses link
    reaction_post_nickname = None
    reaction_post_domain = None
    reaction_post_port = None
    group_account = False
    if actor_reaction:
        reaction_post_nickname = get_nickname_from_actor(actor_reaction)
        reaction_post_domain, reaction_post_port = \
            get_domain_from_actor(actor_reaction)
        group_account = has_group_type(base_dir, actor_reaction, person_cache)
    else:
        if has_users_path(object_url):
            reaction_post_nickname = get_nickname_from_actor(object_url)
            reaction_post_domain, reaction_post_port = \
                get_domain_from_actor(object_url)
            if reaction_post_domain:
                if '/' + str(reaction_post_nickname) + '/' in object_url:
                    actor_reaction = \
                        object_url.split('/' +
                                         reaction_post_nickname + '/')[0] + \
                        '/' + reaction_post_nickname
                    group_account = \
                        has_group_type(base_dir, actor_reaction, person_cache)

    if reaction_post_nickname:
        post_filename = locate_post(base_dir, nickname, domain, object_url)
        if not post_filename:
            print('DEBUG: reaction base_dir: ' + base_dir)
            print('DEBUG: reaction nickname: ' + nickname)
            print('DEBUG: reaction domain: ' + domain)
            print('DEBUG: reaction object_url: ' + object_url)
            return None

        actor_url = get_actor_from_post(new_reaction_json)
        update_reaction_collection(recent_posts_cache,
                                   base_dir, post_filename, object_url,
                                   actor_url,
                                   nickname, domain, debug, None,
                                   emoji_content)

        extra_headers = {}
        send_signed_json(new_reaction_json, session, base_dir,
                         nickname, domain, port,
                         reaction_post_nickname,
                         reaction_post_domain, reaction_post_port,
                         http_prefix, client_to_server, federation_list,
                         send_threads, post_log, cached_webfingers,
                         person_cache,
                         debug, project_version, None, group_account,
                         signing_priv_key_pem, 7165392,
                         curr_domain, onion_domain, i2p_domain,
                         extra_headers, sites_unavailable,
                         system_language, mitm_servers)

    return new_reaction_json


def reaction_post(recent_posts_cache: {},
                  session, base_dir: str, federation_list: [],
                  nickname: str, domain: str, port: int, http_prefix: str,
                  reaction_nickname: str, reaction_domain: str,
                  reaction_port: int, cc_list: [],
                  reaction_status_number: int, emoji_content: str,
                  client_to_server: bool,
                  send_threads: [], post_log: [],
                  person_cache: {}, cached_webfingers: {},
                  debug: bool, project_version: str,
                  signing_priv_key_pem: str,
                  curr_domain: str, onion_domain: str, i2p_domain: str,
                  sites_unavailable: [], system_language: str,
                  mitm_servers: []) -> {}:
    """Adds a reaction to a given status post. This is only used by unit tests
    """
    reaction_domain = get_full_domain(reaction_domain, reaction_port)

    actor_reaction = \
        local_actor_url(http_prefix, reaction_nickname, reaction_domain)
    object_url = actor_reaction + '/statuses/' + str(reaction_status_number)

    return _reactionpost(recent_posts_cache,
                         session, base_dir, federation_list,
                         nickname, domain, port,
                         cc_list, http_prefix, object_url, emoji_content,
                         actor_reaction, client_to_server,
                         send_threads, post_log, person_cache,
                         cached_webfingers,
                         debug, project_version, signing_priv_key_pem,
                         curr_domain, onion_domain, i2p_domain,
                         sites_unavailable, system_language,
                         mitm_servers)


def send_reaction_via_server(base_dir: str, session,
                             from_nickname: str, password: str,
                             from_domain: str, from_port: int,
                             http_prefix: str, reaction_url: str,
                             emoji_content: str,
                             cached_webfingers: {}, person_cache: {},
                             debug: bool, project_version: str,
                             signing_priv_key_pem: str,
                             system_language: str,
                             mitm_servers: []) -> {}:
    """Creates a reaction via c2s
    """
    if not session:
        print('WARN: No session for send_reaction_via_server')
        return 6
    if not valid_emoji_content(emoji_content):
        print('send_reaction_via_server: Invalid emoji reaction: "' +
              emoji_content + '"')
        return 7

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)

    new_reaction_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'EmojiReact',
        'actor': actor,
        'object': reaction_url,
        'content': emoji_content
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: reaction webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: reaction webfinger for ' + handle +
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
            print('DEBUG: reaction no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: reaction no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_reaction_json, [], inbox_url,
                            headers, 3, True)
    if not post_result:
        if debug:
            print('WARN: POST reaction failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST reaction success')

    return new_reaction_json


def send_undo_reaction_via_server(base_dir: str, session,
                                  from_nickname: str, password: str,
                                  from_domain: str, from_port: int,
                                  http_prefix: str, reaction_url: str,
                                  emoji_content: str,
                                  cached_webfingers: {}, person_cache: {},
                                  debug: bool, project_version: str,
                                  signing_priv_key_pem: str,
                                  system_language: str,
                                  mitm_servers: []) -> {}:
    """Undo a reaction via c2s
    """
    if not session:
        print('WARN: No session for send_undo_reaction_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)

    new_undo_reaction_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Undo',
        'actor': actor,
        'object': {
            'type': 'EmojiReact',
            'actor': actor,
            'object': reaction_url,
            'content': emoji_content
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
            print('DEBUG: unreaction webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        if debug:
            print('WARN: unreaction webfinger for ' + handle +
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
            print('DEBUG: unreaction no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: unreaction no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_undo_reaction_json, [], inbox_url,
                            headers, 3, True)
    if not post_result:
        if debug:
            print('WARN: POST unreaction failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST unreaction success')

    return new_undo_reaction_json


def outbox_reaction(recent_posts_cache: {},
                    base_dir: str, nickname: str, domain: str,
                    message_json: {}, debug: bool) -> None:
    """ When a reaction request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        if debug:
            print('DEBUG: reaction - no type')
        return
    if not message_json['type'] == 'EmojiReact':
        if debug:
            print('DEBUG: not a reaction')
        return
    if not has_object_string(message_json, debug):
        return
    if not message_json.get('content'):
        return
    if not isinstance(message_json['content'], str):
        return
    if not valid_emoji_content(message_json['content']):
        print('outbox_reaction: Invalid emoji reaction: "' +
              message_json['content'] + '"')
        return
    if debug:
        print('DEBUG: c2s reaction request arrived in outbox')

    message_id = remove_id_ending(message_json['object'])
    domain = remove_domain_port(domain)
    emoji_content = message_json['content']
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s reaction post not found in inbox or outbox')
            print(message_id)
        return True
    actor_url = get_actor_from_post(message_json)
    update_reaction_collection(recent_posts_cache,
                               base_dir, post_filename, message_id,
                               actor_url,
                               nickname, domain, debug, None, emoji_content)
    if debug:
        print('DEBUG: post reaction via c2s - ' + post_filename)


def outbox_undo_reaction(recent_posts_cache: {},
                         base_dir: str, nickname: str, domain: str,
                         message_json: {}, debug: bool) -> None:
    """ When an undo reaction request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        return
    if not message_json['type'] == 'Undo':
        return
    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'EmojiReact':
        if debug:
            print('DEBUG: not a undo reaction')
        return
    if not message_json['object'].get('content'):
        return
    if not isinstance(message_json['object']['content'], str):
        return
    if not has_object_string_object(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s undo reaction request arrived in outbox')

    message_id = remove_id_ending(message_json['object']['object'])
    emoji_content = message_json['object']['content']
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s undo reaction post not found in inbox or outbox')
            print(message_id)
        return True
    actor_url = get_actor_from_post(message_json)
    undo_reaction_collection_entry(recent_posts_cache, base_dir, post_filename,
                                   actor_url, domain, debug, None,
                                   emoji_content)
    if debug:
        print('DEBUG: post undo reaction via c2s - ' + post_filename)


def _update_common_reactions(base_dir: str, emoji_content: str) -> None:
    """Updates the list of commonly used reactions
    """
    common_reactions_filename = data_dir(base_dir) + '/common_reactions.txt'
    common_reactions = None
    if os.path.isfile(common_reactions_filename):
        try:
            with open(common_reactions_filename, 'r',
                      encoding='utf-8') as fp_react:
                common_reactions = fp_react.readlines()
        except OSError:
            print('EX: unable to load common reactions file' +
                  common_reactions_filename)
    if common_reactions:
        new_common_reactions: list[str] = []
        reaction_found = False
        for line in common_reactions:
            if ' ' + emoji_content in line:
                if not reaction_found:
                    reaction_found = True
                    counter = 1
                    count_str = line.split(' ')[0]
                    if count_str.isdigit():
                        counter = int(count_str) + 1
                    count_str = str(counter).zfill(16)
                    line = count_str + ' ' + emoji_content
                    new_common_reactions.append(line)
            else:
                line1 = remove_eol(line)
                new_common_reactions.append(line1)
        if not reaction_found:
            new_common_reactions.append(str(1).zfill(16) + ' ' + emoji_content)
        new_common_reactions.sort(reverse=True)
        try:
            with open(common_reactions_filename, 'w+',
                      encoding='utf-8') as fp_react:
                for line in new_common_reactions:
                    fp_react.write(line + '\n')
        except OSError:
            print('EX: error writing common reactions 1')
            return
    else:
        line = str(1).zfill(16) + ' ' + emoji_content + '\n'
        try:
            with open(common_reactions_filename, 'w+',
                      encoding='utf-8') as fp_react:
                fp_react.write(line)
        except OSError:
            print('EX: error writing common reactions 2')
            return


def update_reaction_collection(recent_posts_cache: {},
                               base_dir: str, post_filename: str,
                               object_url: str, actor: str,
                               nickname: str, domain: str, debug: bool,
                               post_json_object: {},
                               emoji_content: str) -> None:
    """Updates the reactions collection within a post
    """
    if not post_json_object:
        post_json_object = load_json(post_filename)
    if not post_json_object:
        return

    # remove any cached version of this post so that the
    # reaction icon is changed
    remove_post_from_cache(post_json_object, recent_posts_cache)
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname,
                                 domain, post_json_object)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
            except OSError:
                print('EX: update_reaction_collection unable to delete ' +
                      cached_post_filename)

    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']

    reactions_ending = '/reactions'
    if not object_url.endswith(reactions_ending):
        collection_id = object_url + reactions_ending
    else:
        collection_id = object_url
        reactions_ending_len = len(object_url) - len(reactions_ending)
        object_url = object_url[:reactions_ending_len]

    if not obj.get('reactions'):
        if debug:
            print('DEBUG: Adding initial emoji reaction to ' + object_url)
        reactions_json = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'id': collection_id,
            'reactionsOf': object_url,
            'type': 'Collection',
            "totalItems": 1,
            'items': [{
                'type': 'EmojiReact',
                'actor': actor,
                'content': emoji_content
            }]
        }
        obj['reactions'] = reactions_json
    else:
        if not obj['reactions'].get('items'):
            obj['reactions']['items']: list[dict] = []
        # upper limit for the number of reactions on a post
        if len(obj['reactions']['items']) >= MAX_ACTOR_REACTIONS_PER_POST:
            return
        for reaction_item in obj['reactions']['items']:
            if reaction_item.get('actor') and reaction_item.get('content'):
                if reaction_item['actor'] == actor and \
                   reaction_item['content'] == emoji_content:
                    # already reaction
                    return
        new_reaction = {
            'type': 'EmojiReact',
            'actor': actor,
            'content': emoji_content
        }
        obj['reactions']['items'].append(new_reaction)
        itlen = len(obj['reactions']['items'])
        obj['reactions']['totalItems'] = itlen

    _update_common_reactions(base_dir, emoji_content)

    if debug:
        print('DEBUG: saving post with emoji reaction added')
        pprint(post_json_object)
    save_json(post_json_object, post_filename)


def html_emoji_reactions(post_json_object: {}, interactive: bool,
                         actor: str, max_reaction_types: int,
                         box_name: str, page_number: int) -> str:
    """html containing row of emoji reactions
    displayed at the bottom of posts, above the icons
    """
    if not has_object_dict(post_json_object):
        return ''
    if not post_json_object.get('actor'):
        return ''
    if not post_json_object['object'].get('reactions'):
        return ''
    if not post_json_object['object']['reactions'].get('items'):
        return ''
    reactions = {}
    reacted_to_by_this_actor: list[str] = []
    for item in post_json_object['object']['reactions']['items']:
        emoji_content = item['content']
        emoji_actor = item['actor']
        emoji_nickname = get_nickname_from_actor(emoji_actor)
        if not emoji_nickname:
            return ''
        emoji_domain, _ = get_domain_from_actor(emoji_actor)
        if not emoji_domain:
            return ''
        emoji_handle = emoji_nickname + '@' + emoji_domain
        if emoji_actor == actor:
            if emoji_content not in reacted_to_by_this_actor:
                reacted_to_by_this_actor.append(emoji_content)
        if not reactions.get(emoji_content):
            if len(reactions.items()) < max_reaction_types:
                reactions[emoji_content] = {
                    "handles": [emoji_handle],
                    "count": 1
                }
        else:
            reactions[emoji_content]['count'] += 1
            if len(reactions[emoji_content]['handles']) < 32:
                reactions[emoji_content]['handles'].append(emoji_handle)
    if len(reactions.items()) == 0:
        return ''
    react_by = remove_id_ending(post_json_object['object']['id'])
    html_str = '<div class="emojiReactionBar">\n'
    for emoji_content, item in reactions.items():
        count = item['count']

        # get the handles of actors who reacted
        handles_str = ''
        item['handles'].sort()
        for handle in item['handles']:
            if handles_str:
                handles_str += '&#10;'
            handles_str += handle

        if emoji_content not in reacted_to_by_this_actor:
            base_url = actor + '?react=' + react_by
        else:
            base_url = actor + '?unreact=' + react_by
        actor_url = get_actor_from_post(post_json_object)
        base_url += '?actor=' + actor_url
        base_url += '?tl=' + box_name
        base_url += '?page=' + str(page_number)
        base_url += '?emojreact='

        html_str += '  <div class="emojiReactionButton">\n'
        if count < 100:
            count_str = str(count)
        else:
            count_str = '99+'
        emoji_content_str = emoji_content + count_str
        if interactive:
            # urlencode the emoji
            emoji_content_encoded = urllib.parse.quote_plus(emoji_content)
            emoji_content_str = \
                '    <a href="' + base_url + emoji_content_encoded + \
                '" title="' + handles_str + '">' + \
                emoji_content_str + '</a>\n'
        html_str += emoji_content_str
        html_str += '  </div>\n'
    html_str += '</div>\n'
    return html_str
