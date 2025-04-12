__filename__ = "conversation.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
from utils import has_object_dict
from utils import acct_dir
from utils import remove_id_ending
from utils import text_in_file
from utils import locate_post
from utils import load_json
from utils import harmless_markup
from utils import get_attributed_to
from utils import get_reply_to
from utils import resembles_url
from keys import get_instance_actor_key
from session import get_json
from session import get_json_valid


def _get_conversation_filename(base_dir: str, nickname: str, domain: str,
                               post_json_object: {}) -> str:
    """Returns the conversation filename
    Due to lack of AP specification maintenance, a conversation can also be
    referred to as a thread or (confusingly) "context"
    """
    if not has_object_dict(post_json_object):
        return None
    if not post_json_object['object'].get('conversation') and \
       not post_json_object['object'].get('thread') and \
       not post_json_object['object'].get('context'):
        return None
    if not post_json_object['object'].get('id'):
        return None
    conversation_dir = acct_dir(base_dir, nickname, domain) + '/conversation'
    if not os.path.isdir(conversation_dir):
        os.mkdir(conversation_dir)
    if post_json_object['object'].get('conversation'):
        conversation_id = post_json_object['object']['conversation']
    elif post_json_object['object'].get('context'):
        conversation_id = post_json_object['object']['context']
    else:
        conversation_id = post_json_object['object']['thread']
    if not isinstance(conversation_id, str):
        return None
    conversation_id = conversation_id.replace('/', '#')
    return conversation_dir + '/' + conversation_id


def update_conversation(base_dir: str, nickname: str, domain: str,
                        post_json_object: {}) -> bool:
    """Adds a post to a conversation index in the /conversation subdirectory
    """
    conversation_filename = \
        _get_conversation_filename(base_dir, nickname, domain,
                                   post_json_object)
    if not conversation_filename:
        return False
    post_id = remove_id_ending(post_json_object['object']['id'])
    if not os.path.isfile(conversation_filename):
        try:
            with open(conversation_filename, 'w+',
                      encoding='utf-8') as fp_conv:
                fp_conv.write(post_id + '\n')
                return True
        except OSError:
            print('EX: update_conversation ' +
                  'unable to write to ' + conversation_filename)
    elif not text_in_file(post_id + '\n', conversation_filename):
        try:
            with open(conversation_filename, 'a+',
                      encoding='utf-8') as fp_conv:
                fp_conv.write(post_id + '\n')
                return True
        except OSError:
            print('EX: update_conversation 2 ' +
                  'unable to write to ' + conversation_filename)
    return False


def mute_conversation(base_dir: str, nickname: str, domain: str,
                      conversation_id: str) -> None:
    """Mutes the given conversation
    """
    if not isinstance(conversation_id, str):
        return

    conversation_dir = acct_dir(base_dir, nickname, domain) + '/conversation'
    conversation_filename = \
        conversation_dir + '/' + conversation_id.replace('/', '#')
    if not os.path.isfile(conversation_filename):
        return
    if os.path.isfile(conversation_filename + '.muted'):
        return
    try:
        with open(conversation_filename + '.muted', 'w+',
                  encoding='utf-8') as fp_conv:
            fp_conv.write('\n')
    except OSError:
        print('EX: unable to write mute ' + conversation_filename)


def unmute_conversation(base_dir: str, nickname: str, domain: str,
                        conversation_id: str) -> None:
    """Unmutes the given conversation
    """
    if not isinstance(conversation_id, str):
        return

    conversation_dir = acct_dir(base_dir, nickname, domain) + '/conversation'
    conversation_filename = \
        conversation_dir + '/' + conversation_id.replace('/', '#')
    if not os.path.isfile(conversation_filename):
        return
    if not os.path.isfile(conversation_filename + '.muted'):
        return
    try:
        os.remove(conversation_filename + '.muted')
    except OSError:
        print('EX: unmute_conversation unable to delete ' +
              conversation_filename + '.muted')


def _get_replies_to_post(post_json_object: {},
                         signing_priv_key_pem: str,
                         session, as_header, debug: bool,
                         http_prefix: str,
                         base_dir: str, nickname: str,
                         domain: str, depth: int, ids: [],
                         mitm_servers: []) -> []:
    """Returns a list of reply posts to the given post as json
    """
    result: list[dict] = []
    post_obj = post_json_object
    if has_object_dict(post_json_object):
        post_obj = post_json_object['object']
    if not post_obj.get('replies'):
        return result

    # get the replies collection url
    replies_collection_id = None
    if isinstance(post_obj['replies'], dict):
        if post_obj['replies'].get('id'):
            replies_collection_id = post_obj['replies']['id']
    elif isinstance(post_obj['replies'], str):
        replies_collection_id = post_obj['replies']

    if replies_collection_id:
        if debug:
            print('DEBUG: get_replies_to_post replies_collection_id ' +
                  str(replies_collection_id))

        replies_collection = \
            get_json(signing_priv_key_pem, session, replies_collection_id,
                     as_header, None, debug, mitm_servers, __version__,
                     http_prefix, domain)
        if not get_json_valid(replies_collection):
            return result

        if debug:
            print('DEBUG: get_replies_to_post replies_collection ' +
                  str(replies_collection))
        # get the list of replies
        if not replies_collection.get('first'):
            return result
        if not isinstance(replies_collection['first'], dict):
            return result
        if not replies_collection['first'].get('items'):
            if not replies_collection['first'].get('next'):
                return result

        items_list: list[dict] = []
        if replies_collection['first'].get('items'):
            items_list = replies_collection['first']['items']
        if not items_list:
            # if there are no items try the next one
            next_page_id = replies_collection['first']['next']
            if not isinstance(next_page_id, str):
                return result
            replies_collection = \
                get_json(signing_priv_key_pem, session, next_page_id,
                         as_header, None, debug, mitm_servers, __version__,
                         http_prefix, domain)
            if debug:
                print('DEBUG: get_replies_to_post next replies_collection ' +
                      str(replies_collection))
            if not get_json_valid(replies_collection):
                return result
            if not replies_collection.get('items'):
                return result
            if not isinstance(replies_collection['items'], list):
                return result
            items_list = replies_collection['items']

        if debug:
            print('DEBUG: get_replies_to_post items_list ' +
                  str(items_list))

        if not isinstance(items_list, list):
            return result

        # check each item in the list
        for item in items_list:
            # download the item if needed
            if isinstance(item, str):
                if resembles_url(item):
                    if debug:
                        print('Downloading conversation item ' + item)
                    item_dict = \
                        get_json(signing_priv_key_pem, session, item,
                                 as_header, None, debug, mitm_servers,
                                 __version__, http_prefix, domain)
                    if not get_json_valid(item_dict):
                        continue
                    item = item_dict

            if not isinstance(item, dict):
                continue
            if not has_object_dict(item):
                if not item.get('attributedTo'):
                    continue
                attrib_str = get_attributed_to(item['attributedTo'])
                if not attrib_str:
                    continue
                if not item.get('published'):
                    continue
                if not item.get('id'):
                    continue
                if not isinstance(item['id'], str):
                    continue
                if not item.get('to'):
                    continue
                if not isinstance(item['to'], list):
                    continue
                if 'cc' not in item:
                    continue
                if not isinstance(item['cc'], list):
                    continue
                wrapped_post = {
                    "@context": [
                        'https://www.w3.org/ns/activitystreams',
                        'https://w3id.org/security/v1'
                    ],
                    'id': item['id'] + '/activity',
                    'type': 'Create',
                    'actor': attrib_str,
                    'published': item['published'],
                    'to': item['to'],
                    'cc': item['cc'],
                    'object': item
                }
                item = wrapped_post
            if not item['object'].get('published'):
                continue

            # render harmless any dangerous markup
            harmless_markup(item)

            # keep a list of ids encountered, to avoid circularity
            reply_post_id = None
            if item.get('id'):
                if isinstance(item['id'], str):
                    reply_post_id = item['id']
                    if reply_post_id in ids:
                        continue
                    ids.append(reply_post_id)

            # add it to the list
            result.append(item)

            update_conversation(base_dir, nickname, domain,
                                item)

            if depth < 10 and reply_post_id:
                result += \
                    _get_replies_to_post(item,
                                         signing_priv_key_pem,
                                         session, as_header,
                                         debug,
                                         http_prefix, base_dir,
                                         nickname, domain,
                                         depth + 1, ids,
                                         mitm_servers)
    return result


def download_conversation_posts(authorized: bool, session,
                                http_prefix: str, base_dir: str,
                                nickname: str, domain: str,
                                post_id: str, debug: bool,
                                mitm_servers: []) -> []:
    """Downloads all posts for a conversation and returns a list of the
    json objects
    """
    if '://' not in post_id:
        return []
    profile_str = 'https://www.w3.org/ns/activitystreams'
    as_header = {
        'Accept': 'application/ld+json; profile="' + profile_str + '"'
    }
    conversation_view: list[dict] = []
    signing_priv_key_pem = get_instance_actor_key(base_dir, domain)
    post_id = remove_id_ending(post_id)
    post_filename = \
        locate_post(base_dir, nickname, domain, post_id)
    post_json_object = None
    if authorized:
        if post_filename:
            post_json_object = load_json(post_filename)
        else:
            post_json_object = \
                get_json(signing_priv_key_pem, session, post_id,
                         as_header, None, debug, mitm_servers,
                         __version__, http_prefix, domain)
    if debug:
        if not get_json_valid(post_json_object):
            print(post_id + ' returned no json')

    if post_json_object:
        update_conversation(base_dir, nickname, domain,
                            post_json_object)

    # get any replies
    replies_to_post: list[dict] = []
    if get_json_valid(post_json_object):
        replies_to_post = \
            _get_replies_to_post(post_json_object,
                                 signing_priv_key_pem,
                                 session, as_header, debug,
                                 http_prefix, base_dir, nickname,
                                 domain, 0, [], mitm_servers)

    ids: list[str] = []
    while get_json_valid(post_json_object):
        if not isinstance(post_json_object, dict):
            break
        if not has_object_dict(post_json_object):
            if not post_json_object.get('id'):
                break
            if not isinstance(post_json_object['id'], str):
                break
            if not post_json_object.get('attributedTo'):
                if debug:
                    print(str(post_json_object))
                    print(post_json_object['id'] + ' has no attributedTo')
                break
            attrib_str = get_attributed_to(post_json_object['attributedTo'])
            if not attrib_str:
                break
            if not post_json_object.get('published'):
                if debug:
                    print(str(post_json_object))
                    print(post_json_object['id'] + ' has no published date')
                break
            if not post_json_object.get('to'):
                if debug:
                    print(str(post_json_object))
                    print(post_json_object['id'] + ' has no "to" list')
                break
            if not isinstance(post_json_object['to'], list):
                break
            if 'cc' not in post_json_object:
                if debug:
                    print(str(post_json_object))
                    print(post_json_object['id'] + ' has no "cc" list')
                break
            if not isinstance(post_json_object['cc'], list):
                break
            wrapped_post = {
                "@context": [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
                ],
                'id': post_json_object['id'] + '/activity',
                'type': 'Create',
                'actor': attrib_str,
                'published': post_json_object['published'],
                'to': post_json_object['to'],
                'cc': post_json_object['cc'],
                'object': post_json_object
            }
            post_json_object = wrapped_post
        if not post_json_object['object'].get('published'):
            break

        # avoid any circularity in previous conversation posts
        if post_json_object.get('id'):
            if isinstance(post_json_object['id'], str):
                if post_json_object['id'] in ids:
                    break
                ids.append(post_json_object['id'])

        # render harmless any dangerous markup
        harmless_markup(post_json_object)

        conversation_view = [post_json_object] + conversation_view

        update_conversation(base_dir, nickname, domain,
                            post_json_object)

        if not authorized:
            # only show a single post to non-authorized viewers
            break
        post_id = get_reply_to(post_json_object['object'])
        if not post_id:
            if debug:
                print(post_id + ' is not a reply')
            break
        post_id = remove_id_ending(post_id)
        post_filename = \
            locate_post(base_dir, nickname, domain, post_id)
        post_json_object = None
        if post_filename:
            post_json_object = load_json(post_filename)
        else:
            if authorized:
                post_json_object = \
                    get_json(signing_priv_key_pem, session, post_id,
                             as_header, None, debug, mitm_servers,
                             __version__, http_prefix, domain)

        if debug:
            if get_json_valid(post_json_object):
                print(post_id + ' returned no json')

    return conversation_view + replies_to_post


def conversation_tag_to_convthread_id(tag: str) -> str:
    """Converts a converation tag, such as
    tag:domain,2024-09-28:objectId=647832678:objectType=Conversation
    into a convthread id such as 20240928647832678
    """
    if not isinstance(tag, str):
        return ''
    convthread_id = ''
    for tag_chr in tag:
        if tag_chr.isdigit():
            convthread_id += tag_chr
    return convthread_id


def convthread_id_to_conversation_tag(domain: str,
                                      convthread_id: str) -> str:
    """Converts a convthread id such as 20240928647832678
    into a converation tag, such as
    tag:domain,2024-09-28:objectId=647832678:objectType=Conversation
    """
    if len(convthread_id) < 10:
        return ''
    year = convthread_id[:4]
    month = convthread_id[4:][:2]
    day = convthread_id[6:][:2]
    post_id = convthread_id[8:]
    conversation_id = \
        'tag:' + domain + ',' + year + '-' + month + '-' + day + \
        ':objectId=' + post_id + ':objectType=Conversation'
    return conversation_id


def post_id_to_convthread_id(post_id: str, published: str) -> str:
    """Converts a post ID into a conversation thread ID
    """
    if '/statuses/' not in post_id or len(published) < 10:
        return post_id
    date_prefix = published[:10].replace('-', '')
    convthread_id = post_id.replace('/statuses/', '/thread/' + date_prefix)
    return convthread_id
