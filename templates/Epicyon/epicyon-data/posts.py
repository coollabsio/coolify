__filename__ = "posts.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import json
import html
import datetime
import os
import shutil
import sys
import time
import random
from time import gmtime, strftime
from collections import OrderedDict
from threads import thread_with_trace
from threads import begin_thread
from cache import get_actor_public_key_from_id
from cache import store_person_in_cache
from cache import get_person_from_cache
from cache import expire_person_cache
from pprint import pprint
from session import create_session
from session import get_json
from session import post_json
from session import post_json_string
from session import post_image
from session import get_json_valid
from webfinger import webfinger_handle
from httpsig import create_signed_header
from siteactive import site_is_active
from languages import understood_post_language
from flags import is_evil
from flags import is_public_post
from flags import invalid_ciphertext
from flags import contains_private_key
from flags import has_group_type
from flags import is_premium_account
from flags import url_permitted
from utils import get_person_icon
from utils import remove_post_from_index
from utils import replace_strings
from utils import valid_content_warning
from utils import get_actor_from_post_id
from utils import string_contains
from utils import get_post_attachments
from utils import get_url_from_post
from utils import date_from_string_format
from utils import date_epoch
from utils import date_utcnow
from utils import get_attributed_to
from utils import contains_statuses
from utils import contains_invalid_actor_url_chars
from utils import acct_handle_dir
from utils import is_dm
from utils import remove_eol
from utils import text_in_file
from utils import get_media_descriptions_from_post
from utils import valid_hash_tag
from utils import get_audio_extensions
from utils import get_summary_from_post
from utils import get_user_paths
from utils import has_object_string_type
from utils import remove_id_ending
from utils import replace_users_with_at
from utils import get_base_content_from_post
from utils import remove_domain_port
from utils import get_port_from_domain
from utils import has_object_dict
from utils import reject_post_id
from utils import remove_invalid_chars
from utils import file_last_modified
from utils import has_users_path
from utils import valid_post_date
from utils import get_full_domain
from utils import get_followers_list
from utils import get_status_number
from utils import create_person_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import delete_post
from utils import valid_nickname
from utils import locate_post
from utils import load_json
from utils import save_json
from utils import get_config_param
from utils import locate_news_votes
from utils import locate_news_arrival
from utils import votes_on_newswire_item
from utils import remove_html
from utils import dangerous_markup
from utils import acct_dir
from utils import local_actor_url
from utils import get_reply_to
from utils import get_actor_from_post
from utils import data_dir
from media import get_music_metadata
from media import attach_media
from media import replace_you_tube
from media import replace_twitter
from content import reject_twitter_summary
from content import words_similarity
from content import limit_repeated_words
from content import post_tag_exists
from content import remove_long_words
from content import add_html_tags
from content import replace_emoji_from_tags
from content import remove_text_formatting
from content import add_auto_cw
from content import contains_invalid_local_links
from content import valid_url_lengths
from auth import create_basic_auth_header
from blocking import is_blocked_hashtag
from blocking import is_blocked
from blocking import is_blocked_domain
from filters import is_filtered
from filters import is_question_filtered
from git import convert_post_to_patch
from git import is_git_patch
from linked_data_sig import generate_json_signature
from petnames import resolve_petnames
from video import convert_video_to_note
from context import get_individual_post_context
from maps import get_location_dict_from_tags
from maps import geocoords_from_map_link
from keys import get_person_key
from markdown import markdown_to_html
from followerSync import update_followers_sync_cache
from question import is_question
from question import dangerous_question
from pyjsonld import JsonLdError
from conversation import conversation_tag_to_convthread_id
from conversation import post_id_to_convthread_id


def convert_post_content_to_html(message_json: {}) -> None:
    """Convert post content to html
    """
    obj_json = message_json
    if has_object_dict(message_json):
        obj_json = message_json['object']
    if not obj_json.get('mediaType'):
        return
    if not obj_json.get('content'):
        return
    if obj_json['mediaType'] not in ('text/markdown',
                                     'text/x.misskeymarkdown'):
        return
    content_str = obj_json['content']
    obj_json['content'] = markdown_to_html(content_str)
    obj_json['mediaType'] = 'text/html'
    if not obj_json.get('contentMap'):
        return
    langs_dict = obj_json['contentMap']
    if not isinstance(langs_dict, dict):
        return
    for lang, content_str in langs_dict.items():
        if not isinstance(content_str, str):
            continue
        obj_json['contentMap'][lang] = \
            markdown_to_html(content_str)


def is_moderator(base_dir: str, nickname: str) -> bool:
    """Returns true if the given nickname is a moderator
    """
    moderators_file = data_dir(base_dir) + '/moderators.txt'

    if not os.path.isfile(moderators_file):
        admin_name = get_config_param(base_dir, 'admin')
        if not admin_name:
            return False
        if admin_name == nickname:
            return True
        return False

    lines: list[str] = []
    try:
        with open(moderators_file, 'r', encoding='utf-8') as fp_mod:
            lines = fp_mod.readlines()
    except OSError:
        print('EX: is_moderator unable to read ' + moderators_file)

    if len(lines) == 0:
        admin_name = get_config_param(base_dir, 'admin')
        if not admin_name:
            return False
        if admin_name == nickname:
            return True
    for moderator in lines:
        moderator = moderator.strip('\n').strip('\r')
        if moderator == nickname:
            return True
    return False


def no_of_followers_on_domain(base_dir: str, handle: str,
                              domain: str, follow_file='followers.txt') -> int:
    """Returns the number of followers of the given handle from the
    given domain
    """
    filename = acct_handle_dir(base_dir, handle) + '/' + follow_file
    if not os.path.isfile(filename):
        return 0

    ctr = 0
    try:
        with open(filename, 'r', encoding='utf-8') as fp_followers:
            for follower_handle in fp_followers:
                if '@' not in follower_handle:
                    continue
                follower_domain = follower_handle.split('@')[1]
                follower_domain = remove_eol(follower_domain)
                if domain == follower_domain:
                    ctr += 1
    except OSError as exc:
        print('EX: no_of_followers_on_domain unable to read ' + filename +
              ' ' + str(exc))
    return ctr


def _clean_html(raw_html: str) -> str:
    """Remove escape codes from html string
    """
    text = raw_html
    return html.unescape(text)


def get_user_url(wf_request: {}, source_id: int, debug: bool) -> str:
    """Gets the actor url from a webfinger request
    """
    if not wf_request.get('links'):
        if source_id == 72367:
            print('get_user_url ' + str(source_id) +
                  ' failed to get display name for webfinger ' +
                  str(wf_request))
        else:
            print('get_user_url webfinger activity+json contains no links ' +
                  str(source_id) + ' ' + str(wf_request))
        return None
    for link in wf_request['links']:
        if not (link.get('type') and link.get('href')):
            continue
        if 'application/activity+json' not in link['type'] and \
           'application/ld+json' not in link['type']:
            continue
        if '/@' not in link['href']:
            if debug and not has_users_path(link['href']):
                print('get_user_url webfinger activity+json ' +
                      'contains single user instance actor ' +
                      str(source_id) + ' ' + str(link))
        url = link['href']
        if not contains_invalid_actor_url_chars(url):
            return remove_html(url)
    return None


def parse_user_feed(signing_priv_key_pem: str,
                    session, feed_url: str, as_header: {},
                    project_version: str, http_prefix: str,
                    origin_domain: str, debug: bool, depth: int,
                    mitm_servers: []) -> []:
    """Returns a feed of posts for an account url
    """
    if depth > 10:
        if debug:
            print('Maximum search depth reached')
        return None

    if debug:
        print('Getting user feed for ' + feed_url)
        print('User feed header ' + str(as_header))
        print('http_prefix ' + str(http_prefix))
        print('origin_domain ' + str(origin_domain))

    feed_json = \
        get_json(signing_priv_key_pem, session, feed_url, as_header, None,
                 debug, mitm_servers, project_version, http_prefix,
                 origin_domain)
    if not get_json_valid(feed_json):
        profile_str = 'https://www.w3.org/ns/activitystreams'
        accept_str = 'application/ld+json; profile="' + profile_str + '"'
        if as_header['Accept'] != accept_str:
            as_header = {
                'Accept': accept_str
            }
            feed_json = get_json(signing_priv_key_pem, session, feed_url,
                                 as_header, None, debug, mitm_servers,
                                 project_version, http_prefix, origin_domain)
    if not get_json_valid(feed_json):
        if debug:
            print('No user feed was returned')
        return None

    if debug:
        print('User feed:')
        pprint(feed_json)

    if 'orderedItems' in feed_json:
        return feed_json['orderedItems']
    if 'items' in feed_json:
        return feed_json['items']

    next_url = None
    if 'first' in feed_json:
        next_url = feed_json['first']
    elif 'next' in feed_json:
        next_url = feed_json['next']

    if debug:
        print('User feed next url: ' + str(next_url))

    if next_url:
        if isinstance(next_url, str):
            if '?max_id=0' not in next_url:
                user_feed = \
                    parse_user_feed(signing_priv_key_pem,
                                    session, next_url, as_header,
                                    project_version, http_prefix,
                                    origin_domain, debug, depth + 1,
                                    mitm_servers)
                if user_feed:
                    return user_feed
        elif isinstance(next_url, dict):
            user_feed = next_url
            if user_feed.get('orderedItems'):
                return user_feed['orderedItems']
            if user_feed.get('items'):
                return user_feed['items']
    return None


def _get_person_box_actor(session, base_dir: str, actor: str,
                          profile_str: str, as_header: {},
                          debug: bool, project_version: str,
                          http_prefix: str, origin_domain: str,
                          person_cache: {},
                          signing_priv_key_pem: str,
                          source_id: int,
                          mitm_servers: []) -> {}:
    """Returns the actor json for the given actor url
    """
    person_json = \
        get_person_from_cache(base_dir, actor, person_cache)
    if person_json:
        return person_json

    if '/channel/' in actor or '/accounts/' in actor:
        as_header = {
            'Accept': 'application/ld+json; profile="' + profile_str + '"'
        }
    person_json = \
        get_json(signing_priv_key_pem, session, actor, as_header, None,
                 debug, mitm_servers, project_version, http_prefix,
                 origin_domain)
    if get_json_valid(person_json):
        return person_json
    as_header = {
        'Accept': 'application/ld+json; profile="' + profile_str + '"'
    }
    person_json = \
        get_json(signing_priv_key_pem, session, actor, as_header, None,
                 debug, mitm_servers, project_version, http_prefix,
                 origin_domain)
    if get_json_valid(person_json):
        return person_json
    print('Unable to get actor for ' + actor + ' ' + str(source_id))
    if not signing_priv_key_pem:
        print('No signing key provided when getting actor')
    return None


def get_person_box(signing_priv_key_pem: str, origin_domain: str,
                   base_dir: str, session, wf_request: {}, person_cache: {},
                   project_version: str, http_prefix: str,
                   nickname: str, domain: str,
                   box_name: str, source_id: int,
                   system_language: str,
                   mitm_servers: []) -> (str, str, str, str, str,
                                         str, str, bool):
    debug = False
    profile_str = 'https://www.w3.org/ns/activitystreams'
    as_header = {
        'Accept': 'application/activity+json; profile="' + profile_str + '"'
    }
    if not wf_request:
        print('No webfinger given')
        return None, None, None, None, None, None, None, None

    # get the actor / person_url
    if not wf_request.get('errors'):
        # get the actor url from webfinger links
        person_url = get_user_url(wf_request, source_id, debug)
    else:
        if nickname == 'dev':
            # try single user instance
            print('get_person_box: Trying single user instance with ld+json')
            person_url = http_prefix + '://' + domain
            as_header = {
                'Accept': 'application/ld+json; profile="' + profile_str + '"'
            }
        else:
            # the final fallback is a mastodon style url
            person_url = local_actor_url(http_prefix, nickname, domain)
    if not person_url:
        return None, None, None, None, None, None, None, None

    # get the actor json from the url
    person_json = \
        _get_person_box_actor(session, base_dir, person_url,
                              profile_str, as_header,
                              debug, project_version,
                              http_prefix, origin_domain,
                              person_cache, signing_priv_key_pem,
                              source_id, mitm_servers)
    if not person_json:
        return None, None, None, None, None, None, None, None

    is_group = False
    if person_json.get('type'):
        if person_json['type'] == 'Group':
            is_group = True

    # get the url for the box/collection
    box_json = None
    if not person_json.get(box_name):
        if person_json.get('endpoints'):
            if person_json['endpoints'].get(box_name):
                box_json = person_json['endpoints'][box_name]
    else:
        box_json = person_json[box_name]
    if not box_json:
        return None, None, None, None, None, None, None, None

    person_id = None
    if person_json.get('id'):
        person_id = person_json['id']
    pub_key, pub_key_id = get_actor_public_key_from_id(person_json, None)
    shared_inbox = None
    if person_json.get('sharedInbox'):
        shared_inbox = person_json['sharedInbox']
    else:
        if person_json.get('endpoints'):
            if person_json['endpoints'].get('sharedInbox'):
                shared_inbox = person_json['endpoints']['sharedInbox']
    avatar_url = None
    if person_json.get('icon'):
        url_str = get_person_icon(person_json)
        if url_str:
            avatar_url = remove_html(url_str)
    display_name = None
    possible_display_name = None
    if person_json.get('name'):
        possible_display_name = person_json['name']
    if person_json.get('nameMap'):
        if isinstance(person_json['nameMap'], dict):
            if system_language in person_json['nameMap']:
                if isinstance(person_json['nameMap'][system_language], str):
                    possible_display_name = \
                        person_json['nameMap'][system_language]
    if possible_display_name:
        display_name = possible_display_name
        if dangerous_markup(possible_display_name, False, []):
            display_name = '*ADVERSARY*'
        elif is_filtered(base_dir,
                         nickname, domain,
                         possible_display_name, 'en'):
            display_name = '*FILTERED*'
        # have they moved?
        if person_json.get('movedTo'):
            display_name += ' ⌂'

    store_person_in_cache(base_dir, person_url, person_json,
                          person_cache, True)

    return box_json, pub_key_id, pub_key, person_id, shared_inbox, \
        avatar_url, display_name, is_group


def _is_public_feed_post(item: {}, person_posts: {}, debug: bool) -> bool:
    """Is the given post a public feed post?
    """
    if not isinstance(item, dict):
        if debug:
            print('item object is not a dict')
            pprint(item)
        return False
    if not item.get('id'):
        if debug:
            print('No id')
        return False
    if not item.get('type'):
        if debug:
            print('No type')
        return False
    allowed_post_types = ('Create', 'Announce', 'Page', 'Note', 'Event')
    if item['type'] not in allowed_post_types:
        if debug:
            print('Not a Create/Note/Announce type')
        return False
    if item.get('object'):
        if isinstance(item['object'], dict):
            if not item['object'].get('published'):
                if debug:
                    print('No published attribute 1')
                return False
        elif isinstance(item['object'], str):
            if item['type'] != 'Announce' and \
               not item.get('published'):
                if debug:
                    print('No published attribute 2')
                return False
        else:
            if debug:
                print('object is not a dict or string')
            return False
    elif item['type'] in ('Note', 'Event', 'Page'):
        if not item.get('published'):
            if debug:
                print('No published attribute 3')
            return False
    if not person_posts.get(item['id']):
        this_item = item
        if item.get('object'):
            this_item = item['object']
        # check that this is a public post
        # #Public should appear in the "to" list
        item_is_note = False
        if item['type'] in ('Note', 'Event', 'Page'):
            item_is_note = True

        if isinstance(this_item, dict):
            if this_item.get('to'):
                is_public = False
                if isinstance(this_item['to'], list):
                    for recipient in this_item['to']:
                        if recipient.endswith('#Public') or \
                           recipient == 'as:Public' or \
                           recipient == 'Public':
                            is_public = True
                            break
                elif isinstance(this_item['to'], str):
                    recipient = this_item['to']
                    if recipient.endswith('#Public') or \
                       recipient == 'as:Public' or \
                       recipient == 'Public':
                        is_public = True
                if not is_public:
                    return False
        elif isinstance(this_item, str) or item_is_note:
            if item.get('to'):
                is_public = False
                if isinstance(item['to'], list):
                    for recipient in item['to']:
                        if recipient.endswith('#Public') or \
                           recipient == 'as:Public' or \
                           recipient == 'Public':
                            is_public = True
                            break
                elif isinstance(item['to'], str):
                    recipient = item['to']
                    if recipient.endswith('#Public') or \
                       recipient == 'as:Public' or \
                       recipient == 'Public':
                        is_public = True
                if not is_public:
                    return False
    return True


def is_create_inside_announce(item: {}) -> bool:
    """ is this a Create inside of an Announce?
    eg. lemmy feed item
    """
    if not isinstance(item, dict):
        return False
    if not item.get('type'):
        return False
    if item['type'] != 'Announce':
        return False
    if not item.get('object'):
        return False
    if not isinstance(item['object'], dict):
        return False
    if not item['object'].get('type'):
        return False
    if item['object']['type'] != 'Create':
        return False
    return True


def _get_posts(session, outbox_url: str, max_posts: int,
               max_mentions: int,
               max_emoji: int, max_attachments: int,
               federation_list: [], raw: bool,
               simple: bool, debug: bool,
               project_version: str, http_prefix: str,
               origin_domain: str, system_language: str,
               signing_priv_key_pem: str,
               mitm_servers: []) -> {}:
    """Gets public posts from an outbox
    """
    if debug:
        print('Getting outbox posts for ' + outbox_url)
    person_posts = {}
    if not outbox_url:
        return person_posts
    profile_str = 'https://www.w3.org/ns/activitystreams'
    accept_str = \
        'application/activity+json; ' + \
        'profile="' + profile_str + '"'
    as_header = {
        'Accept': accept_str
    }
    if '/outbox/' in outbox_url:
        accept_str = \
            'application/ld+json; ' + \
            'profile="' + profile_str + '"'
        as_header = {
            'Accept': accept_str
        }
    if raw:
        if debug:
            print('Returning the raw feed')
        result: list[dict] = []
        i = 0
        user_feed = parse_user_feed(signing_priv_key_pem,
                                    session, outbox_url, as_header,
                                    project_version, http_prefix,
                                    origin_domain, debug, 0,
                                    mitm_servers)
        if user_feed:
            for item in user_feed:
                result.append(item)
                i += 1
                if i == max_posts:
                    break
        pprint(result)
        return None

    if debug:
        print('Returning a human readable version of the feed')
    user_feed = parse_user_feed(signing_priv_key_pem,
                                session, outbox_url, as_header,
                                project_version, http_prefix,
                                origin_domain, debug, 0,
                                mitm_servers)
    if not user_feed:
        return person_posts

    i = 0
    for item in user_feed:
        if is_create_inside_announce(item):
            item = item['object']

        if not _is_public_feed_post(item, person_posts, debug):
            continue

        this_item = item
        this_item_type = item['type']
        if this_item_type not in ('Note', 'Event', 'Page'):
            this_item = item['object']
            if isinstance(this_item, str):
                if '://' in this_item:
                    profile_str = 'https://www.w3.org/ns/activitystreams'
                    as_header2_str = 'application/ld+json; profile="' + \
                        profile_str + '"'
                    as_header2 = {
                        'Accept': as_header2_str
                    }
                    this_item = \
                        get_json(signing_priv_key_pem, session, this_item,
                                 as_header2, None, debug, mitm_servers,
                                 __version__, http_prefix, origin_domain)
                    if not get_json_valid(this_item):
                        continue

        content = get_base_content_from_post(this_item, system_language)
        if not content:
            continue
        content = content.replace('&apos;', "'")

        mentions: list[str] = []
        emoji = {}
        summary = ''
        in_reply_to = ''
        attachment: list[list] = []
        sensitive = False
        if isinstance(this_item, dict):
            if this_item.get('tag'):
                for tag_item in this_item['tag']:
                    if not tag_item.get('type'):
                        continue
                    tag_type = tag_item['type'].lower()
                    if tag_type == 'emoji':
                        if tag_item.get('name') and tag_item.get('icon'):
                            if tag_item['icon'].get('url'):
                                # No emoji from non-permitted domains
                                url_str = \
                                    get_url_from_post(tag_item['icon']['url'])
                                if url_permitted(url_str,
                                                 federation_list):
                                    emoji_name = tag_item['name']
                                    emoji_icon = remove_html(url_str)
                                    emoji[emoji_name] = emoji_icon
                                else:
                                    if debug:
                                        print('url not permitted ' + url_str)
                    if tag_type == 'mention':
                        if tag_item.get('name'):
                            if tag_item['name'] not in mentions:
                                mentions.append(tag_item['name'])
            if len(mentions) > max_mentions:
                if debug:
                    print('max mentions reached')
                continue
            if len(emoji) > max_emoji:
                if debug:
                    print('max emojis reached')
                continue

            if this_item.get('summaryMap'):
                if this_item['summaryMap'].get(system_language):
                    summary = this_item['summaryMap'][system_language]
            if not summary and this_item.get('summary'):
                if this_item['summary']:
                    summary = this_item['summary']

            reply_id = get_reply_to(this_item)
            if reply_id:
                if isinstance(reply_id, str):
                    # No replies to non-permitted domains
                    if not url_permitted(reply_id,
                                         federation_list):
                        if debug:
                            print('url not permitted ' +
                                  reply_id)
                        continue
                    in_reply_to = reply_id

            post_attachments = get_post_attachments(this_item)
            if post_attachments:
                if len(post_attachments) > max_attachments:
                    if debug:
                        print('max attachments reached')
                    continue
                if post_attachments:
                    for attach in post_attachments:
                        if attach.get('name') and attach.get('url'):
                            # no attachments from non-permitted domains
                            url_str = get_url_from_post(attach['url'])
                            attach_url = remove_html(url_str)
                            if url_permitted(attach_url,
                                             federation_list):
                                attachment.append([attach['name'],
                                                   attach_url])
                            else:
                                if debug:
                                    print('url not permitted ' + url_str)

            sensitive = False
            if this_item.get('sensitive'):
                sensitive = this_item['sensitive']

        if content:
            if simple:
                print(_clean_html(content) + '\n')
            else:
                pprint(item)
                person_posts[this_item['id']] = {
                    "sensitive": sensitive,
                    "inreplyto": in_reply_to,
                    "summary": summary,
                    "html": content,
                    "plaintext": _clean_html(content),
                    "attachment": attachment,
                    "mentions": mentions,
                    "emoji": emoji
                }
            i += 1

            if i == max_posts:
                break
    return person_posts


def _get_common_words() -> str:
    """Returns a list of common words
    """
    return (
        'that', 'some', 'about', 'then', 'they', 'were',
        'also', 'from', 'with', 'this', 'have', 'more',
        'need', 'here', 'would', 'these', 'into', 'very',
        'well', 'when', 'what', 'your', 'there', 'which',
        'even', 'there', 'such', 'just', 'those', 'only',
        'will', 'much', 'than', 'them', 'each', 'goes',
        'been', 'over', 'their', 'where', 'could', 'though',
        'like', 'think', 'same', 'maybe', 'really', 'thing',
        'something', 'possible', 'actual', 'actually',
        'because', 'around', 'having', 'especially', 'other',
        'making', 'made', 'make', 'makes', 'including',
        'includes', 'know', 'knowing', 'knows', 'things',
        'say', 'says', 'saying', 'many', 'somewhat',
        'problem', 'problems', 'idea', 'ideas',
        'using', 'uses', 'https', 'still', 'want', 'wants'
    )


def _update_word_frequency(content: str, word_frequency: {}) -> None:
    """Creates a dictionary containing words and the number of times
    that they appear
    """
    plain_text = remove_html(content)
    remove_chars = ('.', ';', '?', '\n', ':')
    for char in remove_chars:
        plain_text = plain_text.replace(char, ' ')
    words_list = plain_text.split(' ')
    common_words = _get_common_words()
    for word in words_list:
        word_len = len(word)
        if word_len < 3:
            continue
        if word_len < 4:
            if word.upper() != word:
                continue
        if string_contains(word, ('&', '"', '@', "'", "--", '//')):
            continue
        if word.lower() in common_words:
            continue
        if word_frequency.get(word):
            word_frequency[word] += 1
        else:
            word_frequency[word] = 1


def get_post_domains(session, outbox_url: str, max_posts: int, debug: bool,
                     project_version: str, http_prefix: str, domain: str,
                     word_frequency: {}, domain_list: [],
                     system_language: str, signing_priv_key_pem: str,
                     mitm_servers: []) -> []:
    """Returns a list of domains referenced within public posts
    """
    if not outbox_url:
        return []
    profile_str = 'https://www.w3.org/ns/activitystreams'
    accept_str = \
        'application/activity+json; ' + \
        'profile="' + profile_str + '"'
    as_header = {
        'Accept': accept_str
    }
    if '/outbox/' in outbox_url:
        accept_str = \
            'application/ld+json; ' + \
            'profile="' + profile_str + '"'
        as_header = {
            'Accept': accept_str
        }

    post_domains = domain_list

    i = 0
    user_feed = parse_user_feed(signing_priv_key_pem,
                                session, outbox_url, as_header,
                                project_version, http_prefix, domain,
                                debug, 0, mitm_servers)
    if not user_feed:
        return post_domains

    for item in user_feed:
        i += 1
        if i > max_posts:
            break
        if not has_object_dict(item):
            continue
        content_str = get_base_content_from_post(item, system_language)
        if content_str:
            _update_word_frequency(content_str, word_frequency)
        reply_id = get_reply_to(item['object'])
        if reply_id:
            if isinstance(reply_id, str):
                post_domain, _ = \
                    get_domain_from_actor(reply_id)
                if post_domain:
                    if post_domain not in post_domains:
                        post_domains.append(post_domain)

        if not item['object'].get('tag'):
            continue
        for tag_item in item['object']['tag']:
            if not tag_item.get('type'):
                continue
            tag_type = tag_item['type'].lower()
            if tag_type != 'mention':
                continue
            if not tag_item.get('href'):
                continue
            tag_url = remove_html(tag_item['href'])
            post_domain, _ = get_domain_from_actor(tag_url)
            if not post_domain:
                continue
            if post_domain not in post_domains:
                post_domains.append(post_domain)
    return post_domains


def _get_posts_for_blocked_domains(base_dir: str,
                                   session, outbox_url: str, max_posts: int,
                                   debug: bool,
                                   project_version: str, http_prefix: str,
                                   domain: str,
                                   signing_priv_key_pem: str,
                                   mitm_servers: []) -> {}:
    """Returns a dictionary of posts for blocked domains
    """
    if not outbox_url:
        return {}
    profile_str = 'https://www.w3.org/ns/activitystreams'
    accept_str = \
        'application/activity+json; ' + \
        'profile="' + profile_str + '"'
    as_header = {
        'Accept': accept_str
    }
    if '/outbox/' in outbox_url:
        accept_str = \
            'application/ld+json; ' + \
            'profile="' + profile_str + '"'
        as_header = {
            'Accept': accept_str
        }

    blocked_posts = {}

    i = 0
    user_feed = parse_user_feed(signing_priv_key_pem,
                                session, outbox_url, as_header,
                                project_version, http_prefix, domain,
                                debug, 0, mitm_servers)
    if not user_feed:
        return blocked_posts

    for item in user_feed:
        i += 1
        if i > max_posts:
            break
        if not has_object_dict(item):
            continue
        reply_id = get_reply_to(item['object'])
        if reply_id:
            if isinstance(reply_id, str):
                post_domain, _ = \
                    get_domain_from_actor(reply_id)
                if not post_domain:
                    continue
                if is_blocked_domain(base_dir, post_domain, None, None):
                    if item['object'].get('url'):
                        url = get_url_from_post(item['object']['url'])
                    else:
                        url = get_url_from_post(item['object']['id'])
                    url = remove_html(url)
                    if not blocked_posts.get(post_domain):
                        blocked_posts[post_domain] = [url]
                    else:
                        if url not in blocked_posts[post_domain]:
                            blocked_posts[post_domain].append(url)

        if item['object'].get('tag'):
            for tag_item in item['object']['tag']:
                if not tag_item.get('type'):
                    continue
                tag_type = tag_item['type'].lower()
                if tag_type == 'mention' and tag_item.get('href'):
                    tag_url = remove_html(tag_item['href'])
                    post_domain, _ = \
                        get_domain_from_actor(tag_url)
                    if not post_domain:
                        continue
                    if is_blocked_domain(base_dir, post_domain, None, None):
                        if item['object'].get('url'):
                            url = get_url_from_post(item['object']['url'])
                        else:
                            url = get_url_from_post(item['object']['id'])
                        url = remove_html(url)
                        if not blocked_posts.get(post_domain):
                            blocked_posts[post_domain] = [url]
                        else:
                            if url not in blocked_posts[post_domain]:
                                blocked_posts[post_domain].append(url)
    return blocked_posts


def delete_all_posts(base_dir: str,
                     nickname: str, domain: str, boxname: str) -> None:
    """Deletes all posts for a person from inbox or outbox
    """
    if boxname not in ('inbox', 'outbox', 'tlblogs', 'tlnews'):
        return
    box_dir = create_person_dir(nickname, domain, base_dir, boxname)
    for delete_filename in os.scandir(box_dir):
        delete_filename = delete_filename.name
        file_path = os.path.join(box_dir, delete_filename)
        try:
            if os.path.isfile(file_path):
                os.unlink(file_path)
            elif os.path.isdir(file_path):
                shutil.rmtree(file_path, ignore_errors=False, onexc=None)
        except OSError as ex:
            print('ERROR: delete_all_posts ' + str(ex))


def _save_last_published(base_dir: str, nickname: str, domain: str,
                         published: str) -> None:
    """Saves the last published date for the outbox
    """
    if not published:
        return

    published_filename = \
        acct_dir(base_dir, nickname, domain) + '/.last_published'
    try:
        with open(published_filename, 'w+', encoding='utf-8') as fp_last:
            fp_last.write(published)
    except OSError:
        print('EX: unable to save last published time ' +
              published_filename)


def save_post_to_box(base_dir: str, http_prefix: str, post_id: str,
                     nickname: str, domain: str, post_json_object: {},
                     boxname: str) -> str:
    """Saves the give json to the give box
    Returns the filename
    """
    if boxname not in ('inbox', 'outbox', 'tlblogs', 'tlnews', 'scheduled'):
        return None
    original_domain = domain
    domain = remove_domain_port(domain)

    if not post_id:
        status_number, _ = get_status_number()
        post_id = \
            local_actor_url(http_prefix, nickname, original_domain) + \
            '/statuses/' + status_number
        post_json_object['id'] = post_id + '/activity'
    published = None
    if post_json_object.get('published'):
        published = post_json_object['published']
    if has_object_dict(post_json_object):
        post_json_object['object']['id'] = post_id
        post_json_object['object']['atomUri'] = post_id
        if post_json_object['object'].get('published'):
            published = post_json_object['object']['published']

    box_dir = create_person_dir(nickname, domain, base_dir, boxname)
    filename = box_dir + '/' + post_id.replace('/', '#') + '.json'

    save_json(post_json_object, filename)
    # if this is an outbox post with a duplicate in the inbox then save to both
    # This happens for edited posts
    if '/outbox/' in filename:
        _save_last_published(base_dir, nickname, domain, published)

        inbox_filename = filename.replace('/outbox/', '/inbox/')
        if os.path.isfile(inbox_filename):
            save_json(post_json_object, inbox_filename)
            base_filename = \
                filename.replace('/outbox/',
                                 '/postcache/').replace('.json', '')
            ssml_filename = base_filename + '.ssml'
            if os.path.isfile(ssml_filename):
                try:
                    os.remove(ssml_filename)
                except OSError:
                    print('EX: save_post_to_box unable to delete ssml file ' +
                          ssml_filename)
            html_filename = base_filename + '.html'
            if os.path.isfile(html_filename):
                try:
                    os.remove(html_filename)
                except OSError:
                    print('EX: save_post_to_box unable to delete html file ' +
                          html_filename)
    return filename


def _update_hashtags_index(base_dir: str, tag: {}, new_post_id: str,
                           nickname: str) -> None:
    """Writes the post url for hashtags to a file
    This allows posts for a hashtag to be quickly looked up
    """
    if tag['type'] != 'Hashtag':
        return

    # create hashtags directory
    tags_dir = base_dir + '/tags'
    if not os.path.isdir(tags_dir):
        os.mkdir(tags_dir)
    tag_name = tag['name']
    tags_filename = tags_dir + '/' + tag_name[1:] + '.txt'

    new_post_id = new_post_id.replace('/', '#')

    if not os.path.isfile(tags_filename):
        days_diff = date_utcnow() - date_epoch()
        days_since_epoch = days_diff.days
        tag_line = \
            str(days_since_epoch) + '  ' + nickname + '  ' + \
            new_post_id + '\n'
        # create a new tags index file
        try:
            with open(tags_filename, 'w+', encoding='utf-8') as fp_tags:
                fp_tags.write(tag_line)
        except OSError:
            print('EX: _update_hashtags_index unable to write tags file ' +
                  tags_filename)
    else:
        # prepend to tags index file
        if not text_in_file(new_post_id, tags_filename):
            days_diff = date_utcnow() - date_epoch()
            days_since_epoch = days_diff.days
            tag_line = \
                str(days_since_epoch) + '  ' + nickname + '  ' + \
                new_post_id + '\n'
            try:
                with open(tags_filename, 'r+', encoding='utf-8') as fp_tags:
                    content = fp_tags.read()
                    if tag_line not in content:
                        fp_tags.seek(0, 0)
                        fp_tags.write(tag_line + content)
            except OSError as ex:
                print('EX: Failed to write entry to tags file ' +
                      tags_filename + ' ' + str(ex))


def _add_schedule_post(base_dir: str, nickname: str, domain: str,
                       event_date_str: str, post_id: str) -> None:
    """Adds a scheduled post to the index
    """
    handle = nickname + '@' + domain
    schedule_index_filename = \
        acct_handle_dir(base_dir, handle) + '/schedule.index'

    index_str = event_date_str + ' ' + post_id.replace('/', '#')
    if os.path.isfile(schedule_index_filename):
        if not text_in_file(index_str, schedule_index_filename):
            try:
                with open(schedule_index_filename, 'r+',
                          encoding='utf-8') as fp_schedule:
                    content = fp_schedule.read()
                    if index_str + '\n' not in content:
                        fp_schedule.seek(0, 0)
                        fp_schedule.write(index_str + '\n' + content)
                        print('DEBUG: scheduled post added to index')
            except OSError as ex:
                print('EX: Failed to write entry to scheduled posts index ' +
                      schedule_index_filename + ' ' + str(ex))
        return

    try:
        with open(schedule_index_filename, 'w+',
                  encoding='utf-8') as fp_schedule:
            fp_schedule.write(index_str + '\n')
    except OSError as ex:
        print('EX: Failed to write entry to scheduled posts index2 ' +
              schedule_index_filename + ' ' + str(ex))


def _create_post_cw_from_reply(base_dir: str, nickname: str, domain: str,
                               in_reply_to: str,
                               sensitive: bool, summary: str,
                               system_language: str,
                               languages_understood: []) -> (bool, str):
    """If this is a reply and the original post has a CW
    then use the same CW
    """
    reply_to_json = None
    if in_reply_to and not sensitive:
        # locate the post which this is a reply to and check if
        # it has a content warning. If it does then reproduce
        # the same warning
        reply_post_filename = \
            locate_post(base_dir, nickname, domain, in_reply_to)
        if reply_post_filename:
            reply_to_json = load_json(reply_post_filename)
    if not reply_to_json:
        return sensitive, summary
    if not reply_to_json.get('object'):
        return sensitive, summary
    if not reply_to_json['object'].get('sensitive'):
        return sensitive, summary
    if not reply_to_json['object']['sensitive']:
        return sensitive, summary
    sensitive = True
    if reply_to_json['object'].get('summary'):
        summary = get_summary_from_post(reply_to_json, system_language,
                                        languages_understood)

    return sensitive, summary


def _attach_post_license(post_json_object: {},
                         content_license_url: str) -> None:
    """Attaches a license to each post
    Also see:
    https://codeberg.org/fediverse/fep/src/branch/main/feps/fep-c118.md
    NOTE: at present (Jan 2023) there is no consensus about how to
    represent license information on ActivityPub posts, so this might
    need to change if such a consensus appears.
    """
    if not content_license_url:
        return
    post_json_object['attachment'].append({
        "type": "PropertyValue",
        "name": "license",
        "value": content_license_url
    })
    post_json_object['schema:license'] = content_license_url
    post_json_object['license'] = content_license_url


def _attach_buy_link(post_json_object: {},
                     buy_url: str, translate: {}) -> None:
    """Attaches a link for buying something
    """
    if not buy_url:
        return
    if '://' not in buy_url:
        return
    if ' ' in buy_url or '<' in buy_url:
        return
    buy_str = 'Buy'
    if translate.get(buy_str):
        buy_str = translate[buy_str]
    if 'attachment' not in post_json_object:
        post_json_object['attachment']: list[dict] = []
    post_json_object['attachment'].append({
        "type": "Link",
        "name": buy_str,
        "href": buy_url,
        "rel": "payment",
        "mediaType": "text/html"
    })


def _attach_chat_link(post_json_object: {},
                      chat_url: str) -> None:
    """Attaches a chat link
    """
    if not chat_url:
        return
    if '://' not in chat_url:
        return
    if ' ' in chat_url or '<' in chat_url:
        return
    if 'attachment' not in post_json_object:
        post_json_object['attachment']: list[dict] = []
    post_json_object['attachment'].append({
        "type": "Link",
        "name": "Chat",
        "href": chat_url,
        "rel": "discussion"
    })


def _create_post_s2s(base_dir: str, nickname: str, domain: str, port: int,
                     http_prefix: str, content: str, status_number: str,
                     published: str, new_post_id: str, post_context: {},
                     to_recipients: [], to_cc: [], in_reply_to: str,
                     sensitive: bool, comments_enabled: bool,
                     tags: [], attach_image_filename: str,
                     media_type: str, image_description: str,
                     video_transcript: str, city: str,
                     post_object_type: str, summary: str,
                     in_reply_to_atom_uri: str, system_language: str,
                     conversation_id: str, convthread_id: str,
                     low_bandwidth: bool, content_license_url: str,
                     media_license_url: str, media_creator: str,
                     buy_url: str, chat_url: str, translate: {},
                     searchable_by: [],
                     automatic_quote_approval: str) -> {}:
    """Creates a new server-to-server post
    """
    domain_full = get_full_domain(domain, port)
    actor_url = local_actor_url(http_prefix, nickname, domain_full)
    new_post_url = \
        http_prefix + '://' + domain + '/@' + nickname + '/' + status_number
    new_post_attributed_to = \
        local_actor_url(http_prefix, nickname, domain)
    conversation_root = ''
    if new_post_id == conversation_id:
        conversation_root = new_post_id
    if not conversation_id:
        conversation_id = post_id_to_convthread_id(new_post_id, published)
        conversation_root = new_post_id
    if not isinstance(conversation_id, str):
        conversation_id = post_id_to_convthread_id(new_post_id, published)
        conversation_root = new_post_id
    if conversation_id.startswith('tag:'):
        new_convthread_id = conversation_tag_to_convthread_id(conversation_id)
        if new_convthread_id:
            convthread_id = new_convthread_id
    replies_collection_id = \
        local_actor_url(http_prefix, nickname, domain) + \
        '/statuses/' + status_number + '/replies'
    replies_next = \
        replies_collection_id + '?only_other_accounts=true&page=true'
    # add opt-outs as in:
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/5e53/fep-5e53.md
    new_post = {
        '@context': post_context,
        'xRobotsTag': 'noai, noimageai, GPC',
        'secGPC': '1',
        'id': new_post_id + '/activity',
        'type': 'Create',
        'actor': actor_url,
        'published': published,
        'to': to_recipients,
        'cc': to_cc,
        'object': {
            'id': new_post_id,
            'xRobotsTag': 'noai, noimageai, GPC',
            'secGPC': '1',
            'conversation': conversation_id,
            'context': conversation_id,
            'type': post_object_type,
            'summary': summary,
            'inReplyTo': in_reply_to,
            'published': published,
            'url': new_post_url,
            'attributedTo': new_post_attributed_to,
            'to': to_recipients,
            'cc': to_cc,
            'sensitive': sensitive,
            'atomUri': new_post_id,
            'inReplyToAtomUri': in_reply_to_atom_uri,
            'commentsEnabled': comments_enabled,
            'rejectReplies': not comments_enabled,
            'mediaType': 'text/html',
            'content': content,
            'contentMap': {
                system_language: content
            },
            'attachment': [],
            'tag': tags,
            'replies': {
                'id': replies_collection_id,
                'repliesOf': new_post_id,
                'type': 'Collection',
                'first': {
                    'type': 'CollectionPage',
                    'next': replies_next,
                    'partOf': replies_collection_id,
                    'items': []
                }
            },
            "crawlable": False,
            "searchableBy": searchable_by,
            "interactionPolicy": {
                "canQuote": {
                    "automaticApproval": automatic_quote_approval
                }
            }
        }
    }

    # is this a root post of a conversation?
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/76ea/fep-76ea.md
    if conversation_root and not in_reply_to:
        new_post['object']['root'] = conversation_root
        if not convthread_id:
            convthread_id = post_id_to_convthread_id(new_post_id, published)

    # https://codeberg.org/fediverse/fep/src/branch/main/fep/76ea/fep-76ea.md
    if convthread_id:
        if isinstance(convthread_id, str):
            new_post['object']['thread'] = convthread_id

    # pixelfed/friendica style location representation
    location = get_location_dict_from_tags(tags)
    if location:
        if location.get('name') and \
           location.get('longitude') and \
           location.get('latitude'):
            new_post['object']['location'] = {
                'type': 'Place',
                'name': location['name'],
                'longitude': location['longitude'],
                'latitude': location['latitude']
            }
        elif location.get('name'):
            new_post['object']['location'] = {
                'type': 'Place',
                'name': location['name']
            }

    if attach_image_filename:
        new_post['object'] = \
            attach_media(base_dir, http_prefix, nickname, domain, port,
                         new_post['object'], attach_image_filename,
                         media_type, image_description, video_transcript,
                         city, low_bandwidth,
                         media_license_url, media_creator, system_language)
    _attach_post_license(new_post['object'], content_license_url)
    _attach_buy_link(new_post['object'], buy_url, translate)
    _attach_chat_link(new_post['object'], chat_url)
    return new_post


def _create_post_c2s(base_dir: str, nickname: str, domain: str, port: int,
                     http_prefix: str, content: str, status_number: str,
                     published: str, new_post_id: str, post_context: {},
                     to_recipients: [], to_cc: [], in_reply_to: str,
                     sensitive: bool, comments_enabled: bool,
                     tags: [], attach_image_filename: str,
                     media_type: str, image_description: str,
                     video_transcript: str, city: str,
                     post_object_type: str, summary: str,
                     in_reply_to_atom_uri: str, system_language: str,
                     conversation_id: str, convthread_id: str,
                     low_bandwidth: str,
                     content_license_url: str, media_license_url: str,
                     media_creator: str, buy_url: str, chat_url: str,
                     translate: {}, searchable_by: [],
                     automatic_quote_approval: str) -> {}:
    """Creates a new client-to-server post
    """
    domain_full = get_full_domain(domain, port)
    actor_url = local_actor_url(http_prefix, nickname, domain)
    new_post_url = \
        http_prefix + '://' + domain + '/@' + nickname + '/' + status_number
    conversation_root = ''
    if new_post_id == conversation_id:
        conversation_root = new_post_id
    if not conversation_id:
        conversation_id = post_id_to_convthread_id(new_post_id, published)
        conversation_root = new_post_id
    if not isinstance(conversation_id, str):
        conversation_id = post_id_to_convthread_id(new_post_id, published)
        conversation_root = new_post_id
    if conversation_id.startswith('tag:'):
        new_convthread_id = conversation_tag_to_convthread_id(conversation_id)
        if new_convthread_id:
            convthread_id = new_convthread_id
    replies_collection_id = \
        actor_url + '/statuses/' + status_number + '/replies'
    replies_next = \
        replies_collection_id + '?only_other_accounts=true&page=true'
    # add opt-outs as in:
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/5e53/fep-5e53.md
    new_post = {
        "@context": post_context,
        'xRobotsTag': 'noai, noimageai, GPC',
        'secGPC': '1',
        'id': new_post_id,
        'conversation': conversation_id,
        'thread': convthread_id,
        'context': conversation_id,
        'type': post_object_type,
        'summary': summary,
        'inReplyTo': in_reply_to,
        'published': published,
        'url': new_post_url,
        'attributedTo': local_actor_url(http_prefix, nickname, domain_full),
        'to': to_recipients,
        'cc': to_cc,
        'sensitive': sensitive,
        'atomUri': new_post_id,
        'inReplyToAtomUri': in_reply_to_atom_uri,
        'commentsEnabled': comments_enabled,
        'rejectReplies': not comments_enabled,
        'mediaType': 'text/html',
        'content': content,
        'contentMap': {
            system_language: content
        },
        'attachment': [],
        'tag': tags,
        'replies': {
            'id': replies_collection_id,
            'repliesOf': new_post_id,
            'type': 'Collection',
            'first': {
                'type': 'CollectionPage',
                'next': replies_next,
                'partOf': replies_collection_id,
                'items': []
            }
        },
        "crawlable": False,
        "searchableBy": searchable_by,
        "interactionPolicy": {
            "canQuote": {
                "automaticApproval": automatic_quote_approval
            }
        }
    }

    # is this a root post of a conversation?
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/76ea/fep-76ea.md
    if conversation_root and not in_reply_to:
        new_post['root'] = conversation_root
        if not convthread_id:
            convthread_id = post_id_to_convthread_id(new_post_id, published)

    # https://codeberg.org/fediverse/fep/src/branch/main/fep/76ea/fep-76ea.md
    if convthread_id:
        if isinstance(convthread_id, str):
            new_post['thread'] = convthread_id

    # pixelfed/friendica style location representation
    location = get_location_dict_from_tags(tags)
    if location:
        if location.get('name') and \
           location.get('longitude') and \
           location.get('latitude'):
            new_post['location'] = {
                'type': 'Place',
                'name': location['name'],
                'longitude': location['longitude'],
                'latitude': location['latitude']
            }
        elif location.get('name'):
            new_post['location'] = {
                'type': 'Place',
                'name': location['name']
            }

    if attach_image_filename:
        new_post = \
            attach_media(base_dir, http_prefix, nickname, domain, port,
                         new_post, attach_image_filename,
                         media_type, image_description, video_transcript,
                         city, low_bandwidth,
                         media_license_url, media_creator,
                         system_language)
    _attach_post_license(new_post, content_license_url)
    _attach_buy_link(new_post, buy_url, translate)
    _attach_chat_link(new_post, chat_url)
    return new_post


def _create_post_place_and_time(event_date: str, end_date: str,
                                event_time: str, end_time: str,
                                summary: str, content: str,
                                schedule_post: bool,
                                event_uuid: str,
                                location: str,
                                tags: [], session) -> str:
    """Adds a place and time to the tags on a new post
    """
    end_date_str = None
    if end_date:
        event_name = summary
        if not event_name:
            event_name = content
        end_date_str = end_date
        if end_time:
            if end_time.endswith('Z'):
                end_date_str = end_date + 'T' + end_time
            else:
                end_date_str = end_date + 'T' + end_time + \
                    ':00' + strftime("%z", gmtime())
        else:
            if not event_time:
                end_date_str = end_date + 'T12:00:00Z'
            else:
                if event_time.endswith('Z'):
                    end_date_str = end_date + 'T' + event_time
                else:
                    end_date_str = \
                        end_date + 'T' + event_time + ':00' + \
                        strftime("%z", gmtime())

    # get the starting date and time
    event_date_str = None
    if event_date:
        event_name = summary
        if not event_name:
            event_name = content
        event_date_str = event_date
        if event_time:
            if event_time.endswith('Z'):
                event_date_str = event_date + 'T' + event_time
            else:
                event_date_str = event_date + 'T' + event_time + \
                    ':00' + strftime("%z", gmtime())
        else:
            event_date_str = event_date + 'T12:00:00Z'
        if not end_date_str:
            end_date_str = event_date_str
        if not schedule_post and not event_uuid:
            tags.append({
                "@context": [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
                ],
                "type": "Event",
                "name": event_name,
                "startTime": event_date_str,
                "endTime": end_date_str
            })
    if location and not event_uuid:
        latitude = longitude = None
        if '://' in location:
            _, latitude, longitude = \
                geocoords_from_map_link(location, 'openstreetmap.org',
                                        session)
        if latitude and longitude:
            tags.append({
                "@context": [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
                ],
                "type": "Place",
                "name": location,
                "latitude": latitude,
                "longitude": longitude
            })
        else:
            tags.append({
                "@context": [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
                ],
                "type": "Place",
                "name": location
            })
    return event_date_str


def _consolidate_actors_list(actors_list: []) -> None:
    """ consolidate duplicated actors
    https://domain/@nick gets merged with https://domain/users/nick
    """
    possible_duplicate_actors: list[str] = []
    for cc_actor in actors_list:
        if '/@' in cc_actor:
            if '/@/' not in cc_actor:
                if cc_actor not in possible_duplicate_actors:
                    possible_duplicate_actors.append(cc_actor)
    if not possible_duplicate_actors:
        return
    u_paths = get_user_paths()
    remove_actors: list[str] = []
    for cc_actor in possible_duplicate_actors:
        for usr_path in u_paths:
            if '/@/' not in cc_actor:
                cc_actor_full = cc_actor.replace('/@', usr_path)
            else:
                cc_actor_full = cc_actor
            if cc_actor_full in actors_list:
                if cc_actor not in remove_actors:
                    remove_actors.append(cc_actor)
                break
    for cc_actor in remove_actors:
        actors_list.remove(cc_actor)


def _create_post_mentions(cc_url: str, new_post: {},
                          to_recipients: [], tags: []) -> None:
    """Updates mentions for a new post
    """
    if not cc_url:
        return
    if len(cc_url) == 0:
        return

    if new_post.get('object'):
        if cc_url not in new_post['object']['cc']:
            new_post['object']['cc'] = [cc_url] + new_post['object']['cc']

        # if this is a public post then include any mentions in cc
        to_cc = new_post['object']['cc']
        if len(to_recipients) != 1:
            return
        to_public_recipient = False
        if to_recipients[0].endswith('#Public') or \
           to_recipients[0] == 'as:Public' or \
           to_recipients[0] == 'Public':
            to_public_recipient = True
        if to_public_recipient and \
           cc_url.endswith('/followers'):
            for tag in tags:
                if tag['type'] != 'Mention':
                    continue
                if tag['href'] not in to_cc:
                    tag_url = remove_html(tag['href'])
                    new_post['object']['cc'].append(tag_url)

        _consolidate_actors_list(new_post['object']['cc'])
        new_post['cc'] = new_post['object']['cc']
    else:
        if cc_url not in new_post['cc']:
            new_post['cc'] = [cc_url] + new_post['cc']
        _consolidate_actors_list(['cc'])


def _create_post_mod_report(base_dir: str,
                            is_moderation_report: bool, new_post: {},
                            new_post_id: str) -> None:
    """ if this is a moderation report then add a status
    """
    if not is_moderation_report:
        return
    # add status
    if new_post.get('object'):
        new_post['object']['moderationStatus'] = 'pending'
    else:
        new_post['moderationStatus'] = 'pending'
    # save to index file
    moderation_index_file = data_dir(base_dir) + '/moderation.txt'
    try:
        with open(moderation_index_file, 'a+', encoding='utf-8') as fp_mod:
            fp_mod.write(new_post_id + '\n')
    except OSError:
        print('EX: unable to write moderation index file ' +
              moderation_index_file)


def get_actor_from_in_reply_to(in_reply_to: str) -> str:
    """Tries to get the replied to actor from the inReplyTo post id
    Note: this will not always be successful for some instance types
    """
    reply_nickname = get_nickname_from_actor(in_reply_to)
    if not reply_nickname:
        return None
    reply_actor = None
    if '/' + reply_nickname + '/' in in_reply_to:
        reply_actor = \
            in_reply_to.split('/' + reply_nickname + '/')[0] + \
            '/' + reply_nickname
    elif '#' + reply_nickname + '#' in in_reply_to:
        reply_actor = \
            in_reply_to.split('#' + reply_nickname + '#')[0] + \
            '#' + reply_nickname
        reply_actor = reply_actor.replace('#', '/')
    if not reply_actor:
        return None
    if '://' not in reply_actor:
        return None
    return reply_actor


def _create_post_base(base_dir: str,
                      nickname: str, domain: str, port: int,
                      to_url: str, cc_url: str, http_prefix: str, content: str,
                      save_to_file: bool,
                      client_to_server: bool, comments_enabled: bool,
                      attach_image_filename: str,
                      media_type: str, image_description: str,
                      video_transcript: str, city: str,
                      is_moderation_report: bool,
                      is_article: bool,
                      in_reply_to: str,
                      in_reply_to_atom_uri: str,
                      subject: str, schedule_post: bool,
                      event_date: str, event_time: str,
                      location: str,
                      event_uuid: str, category: str,
                      join_mode: str,
                      end_date: str, end_time: str,
                      maximum_attendee_capacity: int,
                      replies_moderation_option: str,
                      anonymous_participation_enabled: bool,
                      event_status: str, ticket_url: str,
                      system_language: str,
                      conversation_id: str, convthread_id: str,
                      low_bandwidth: bool, content_license_url: str,
                      media_license_url: str, media_creator: str,
                      languages_understood: [], translate: {},
                      buy_url: str, chat_url: str,
                      auto_cw_cache: {},
                      searchable_by: [],
                      session,
                      automatic_quote_approval: str) -> {}:
    """Creates a message
    """
    content = remove_invalid_chars(content)

    subject = add_auto_cw(base_dir, nickname, domain, subject, content,
                          auto_cw_cache)

    if nickname != 'news':
        mentioned_recipients = \
            get_mentioned_people(base_dir, http_prefix, content, domain, False)
    else:
        mentioned_recipients = ''

    # add hashtags from audio file ID3 tags, such as Artist, Album, etc
    if attach_image_filename and media_type:
        audio_types = get_audio_extensions()
        music_metadata = None
        for ext in audio_types:
            if ext in media_type:
                music_metadata = get_music_metadata(attach_image_filename)
                break
        if music_metadata:
            for audio_tag, audio_value in music_metadata.items():
                if audio_tag in ('title', 'track'):
                    continue
                # capitalize and remove any spaces
                audio_value = audio_value.title().replace(' ', '')
                # check that the tag is valid
                if valid_hash_tag(audio_value) and \
                   '#' + audio_value not in content:
                    # check that it hasn't been blocked
                    if not is_blocked_hashtag(base_dir, audio_value):
                        content += ' #' + audio_value

    tags: list[dict] = []
    hashtags_dict = {}

    domain = get_full_domain(domain, port)

    # add tags
    if nickname != 'news':
        content = \
            add_html_tags(base_dir, http_prefix,
                          nickname, domain, content,
                          mentioned_recipients,
                          hashtags_dict, translate, True)

    # replace emoji with unicode
    tags: list[dict] = []
    for tag_name, tag in hashtags_dict.items():
        tags.append(tag)

    # get list of tags
    if nickname != 'news':
        content = \
            replace_emoji_from_tags(None, base_dir, content, tags, 'content',
                                    False, True)
    # remove replaced emoji
    hashtags_dict_copy = hashtags_dict.copy()
    for tag_name, tag in hashtags_dict_copy.items():
        if tag.get('name'):
            if tag['name'].startswith(':'):
                if tag['name'] not in content:
                    del hashtags_dict[tag_name]

    status_number, published = get_status_number()
    new_post_id = \
        local_actor_url(http_prefix, nickname, domain) + \
        '/statuses/' + status_number

    sensitive = False
    summary = None
    if subject:
        summary = remove_invalid_chars(valid_content_warning(subject))
        sensitive = True

    to_recipients: list[str] = []
    to_cc: list[str] = []
    if to_url:
        if not isinstance(to_url, str):
            print('ERROR: to_url is not a string')
            return None
        to_recipients = [to_url]

    # who to send to
    if mentioned_recipients:
        for mention in mentioned_recipients:
            if mention not in to_cc:
                to_cc.append(mention)

    is_public = False
    for recipient in to_recipients:
        if recipient.endswith('#Public') or \
           recipient == 'as:Public' or \
           recipient == 'Public':
            is_public = True
            break

    # create a list of hashtags
    # Only posts which are #Public are searchable by hashtag
    if hashtags_dict:
        for tag_name, tag in hashtags_dict.items():
            if not post_tag_exists(tag['type'], tag['name'], tags):
                tags.append(tag)
            if is_public:
                _update_hashtags_index(base_dir, tag, new_post_id, nickname)
        # print('Content tags: ' + str(tags))

    sensitive, summary = \
        _create_post_cw_from_reply(base_dir, nickname, domain,
                                   in_reply_to, sensitive, summary,
                                   system_language, languages_understood)

    event_date_str = \
        _create_post_place_and_time(event_date, end_date,
                                    event_time, end_time,
                                    summary, content, schedule_post,
                                    event_uuid, location, tags,
                                    session)

    post_context = get_individual_post_context()

    if not is_public:
        # make sure that CC doesn't also contain a To address
        # eg. To: [ "https://mydomain/users/foo/followers" ]
        #     CC: [ "X", "Y", "https://mydomain/users/foo", "Z" ]
        remove_from_cc: list[str] = []
        for cc_recipient in to_cc:
            for send_to_actor in to_recipients:
                if cc_recipient in send_to_actor and \
                   cc_recipient not in remove_from_cc:
                    remove_from_cc.append(cc_recipient)
                    break
        for cc_removal in remove_from_cc:
            to_cc.remove(cc_removal)
    else:
        if in_reply_to:
            # If this is a public post then get the actor being
            # replied to end ensure that it is within the CC list
            reply_actor = get_actor_from_in_reply_to(in_reply_to)
            if reply_actor:
                if reply_actor not in to_cc:
                    to_cc.append(reply_actor)

    # the type of post to be made
    post_object_type = 'Note'
    if is_article:
        post_object_type = 'Article'

    # convert the searchable_by state into a url
    searchable_by_list: list[str] = []
    if searchable_by == 'public':
        searchable_by_list = ["https://www.w3.org/ns/activitystreams#Public"]
    elif searchable_by == 'yourself':
        searchable_by_list = \
            [local_actor_url(http_prefix, nickname, domain)]
    elif searchable_by == 'followers':
        searchable_by_list = \
            [local_actor_url(http_prefix, nickname, domain) + "/followers"]
    elif searchable_by == 'mutuals':
        searchable_by_list = \
            [local_actor_url(http_prefix, nickname, domain) + "/mutuals"]

    if not client_to_server:
        new_post = \
            _create_post_s2s(base_dir, nickname, domain, port,
                             http_prefix, content, status_number,
                             published, new_post_id, post_context,
                             to_recipients, to_cc, in_reply_to,
                             sensitive, comments_enabled,
                             tags, attach_image_filename,
                             media_type, image_description,
                             video_transcript, city,
                             post_object_type, summary,
                             in_reply_to_atom_uri, system_language,
                             conversation_id, convthread_id, low_bandwidth,
                             content_license_url, media_license_url,
                             media_creator, buy_url, chat_url,
                             translate, searchable_by_list,
                             automatic_quote_approval)
    else:
        new_post = \
            _create_post_c2s(base_dir, nickname, domain, port,
                             http_prefix, content, status_number,
                             published, new_post_id, post_context,
                             to_recipients, to_cc, in_reply_to,
                             sensitive, comments_enabled,
                             tags, attach_image_filename,
                             media_type, image_description,
                             video_transcript, city,
                             post_object_type, summary,
                             in_reply_to_atom_uri, system_language,
                             conversation_id, convthread_id, low_bandwidth,
                             content_license_url, media_license_url,
                             media_creator, buy_url, chat_url,
                             translate, searchable_by_list,
                             automatic_quote_approval)

    _create_post_mentions(cc_url, new_post, to_recipients, tags)

    _create_post_mod_report(base_dir, is_moderation_report,
                            new_post, new_post_id)

    # If a patch has been posted - i.e. the output from
    # git format-patch - then convert the activitypub type
    convert_post_to_patch(base_dir, nickname, domain, new_post)

    if schedule_post:
        if event_date and event_time:
            # add an item to the scheduled post index file
            _add_schedule_post(base_dir, nickname, domain,
                               event_date_str, new_post_id)
            save_post_to_box(base_dir, http_prefix, new_post_id,
                             nickname, domain, new_post, 'scheduled')
        else:
            print('Unable to create scheduled post without ' +
                  'date and time values')
            return new_post
    elif save_to_file:
        if is_article:
            save_post_to_box(base_dir, http_prefix, new_post_id,
                             nickname, domain, new_post, 'tlblogs')
        else:
            save_post_to_box(base_dir, http_prefix, new_post_id,
                             nickname, domain, new_post, 'outbox')
    return new_post


def outbox_message_create_wrap(http_prefix: str,
                               nickname: str, domain: str, port: int,
                               message_json: {}) -> {}:
    """Wraps a received message in a Create
    https://www.w3.org/TR/activitypub/#object-without-create
    """

    domain = get_full_domain(domain, port)
    status_number, published = get_status_number()
    if message_json.get('published'):
        published = message_json['published']
    new_post_id = \
        local_actor_url(http_prefix, nickname, domain) + \
        '/statuses/' + status_number
    cc_list: list[str] = []
    if message_json.get('cc'):
        cc_list = message_json['cc']
    new_post = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': new_post_id + '/activity',
        'type': 'Create',
        'actor': local_actor_url(http_prefix, nickname, domain),
        'published': published,
        'to': message_json['to'],
        'cc': cc_list,
        'object': message_json
    }
    new_post['object']['id'] = new_post['id']
    new_post['object']['url'] = \
        http_prefix + '://' + domain + '/@' + nickname + '/' + status_number
    new_post['object']['atomUri'] = \
        local_actor_url(http_prefix, nickname, domain) + \
        '/statuses/' + status_number
    return new_post


def _post_is_addressed_to_followers(nickname: str, domain: str, port: int,
                                    http_prefix: str,
                                    post_json_object: {}) -> bool:
    """Returns true if the given post is addressed to followers of the nickname
    """
    domain_full = get_full_domain(domain, port)

    if not post_json_object.get('object'):
        return False
    to_list: list[str] = []
    cc_list: list[str] = []
    if post_json_object['type'] != 'Update' and \
       has_object_dict(post_json_object):
        if post_json_object['object'].get('to'):
            if isinstance(post_json_object['object']['to'], list):
                to_list = post_json_object['object']['to']
            elif isinstance(post_json_object['object']['to'], str):
                to_list = [post_json_object['object']['to']]
        if post_json_object['object'].get('cc'):
            if isinstance(post_json_object['object']['cc'], list):
                cc_list = post_json_object['object']['cc']
            elif isinstance(post_json_object['object']['cc'], str):
                cc_list = [post_json_object['object']['cc']]
    else:
        if post_json_object.get('to'):
            if isinstance(post_json_object['to'], list):
                to_list = post_json_object['to']
            elif isinstance(post_json_object['to'], str):
                to_list = [post_json_object['to']]
        if post_json_object.get('cc'):
            if isinstance(post_json_object['cc'], list):
                cc_list = post_json_object['cc']
            elif isinstance(post_json_object['cc'], str):
                cc_list = [post_json_object['cc']]

    followers_url = \
        local_actor_url(http_prefix, nickname, domain_full) + '/followers'

    # does the followers url exist in 'to' or 'cc' lists?
    addressed_to_followers = False
    if followers_url in to_list:
        addressed_to_followers = True
    elif followers_url in cc_list:
        addressed_to_followers = True
    return addressed_to_followers


def pin_post2(base_dir: str, nickname: str, domain: str,
              pinned_content: str) -> None:
    """Pins the given post Id to the profile of then given account
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    pinned_filename = account_dir + '/pinToProfile.txt'
    try:
        with open(pinned_filename, 'w+', encoding='utf-8') as fp_pin:
            fp_pin.write(pinned_content)
    except OSError:
        print('EX: unable to write ' + pinned_filename)


def undo_pinned_post(base_dir: str, nickname: str, domain: str) -> None:
    """Removes pinned content for then given account
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    pinned_filename = account_dir + '/pinToProfile.txt'
    if not os.path.isfile(pinned_filename):
        return
    try:
        os.remove(pinned_filename)
    except OSError:
        print('EX: undo_pinned_post unable to delete ' + pinned_filename)


def get_pinned_post_as_json(base_dir: str, http_prefix: str,
                            nickname: str, domain: str,
                            domain_full: str, system_language: str) -> {}:
    """Returns the pinned profile post as json
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    pinned_filename = account_dir + '/pinToProfile.txt'
    pinned_post_json = {}
    actor = local_actor_url(http_prefix, nickname, domain_full)
    if os.path.isfile(pinned_filename):
        pinned_content = None
        try:
            with open(pinned_filename, 'r', encoding='utf-8') as fp_pin:
                pinned_content = fp_pin.read()
        except OSError:
            print('EX: get_pinned_post_as_json unable to read ' +
                  pinned_filename)
        if pinned_content:
            pinned_post_json = {
                'atomUri': actor + '/pinned',
                'attachment': [],
                'attributedTo': actor,
                'cc': [
                    actor + '/followers'
                ],
                'content': pinned_content,
                'contentMap': {
                    system_language: pinned_content
                },
                'id': actor + '/pinned',
                'inReplyTo': None,
                'inReplyToAtomUri': None,
                'published': file_last_modified(pinned_filename),
                'replies': {},
                'sensitive': False,
                'summary': None,
                'tag': [],
                'to': ['https://www.w3.org/ns/activitystreams#Public'],
                'type': 'Note',
                'url': replace_users_with_at(actor) + '/pinned'
            }
    return pinned_post_json


def json_pin_post(base_dir: str, http_prefix: str,
                  nickname: str, domain: str,
                  domain_full: str, system_language: str) -> {}:
    """Returns a pinned post as json
    """
    pinned_post_json = \
        get_pinned_post_as_json(base_dir, http_prefix,
                                nickname, domain,
                                domain_full, system_language)
    items_list = []
    if pinned_post_json:
        items_list: list[dict] = [pinned_post_json]

    actor = local_actor_url(http_prefix, nickname, domain_full)
    post_context = get_individual_post_context()
    return {
        '@context': post_context,
        'id': actor + '/collections/featured',
        'orderedItems': items_list,
        'totalItems': len(items_list),
        'type': 'OrderedCollection'
    }


def regenerate_index_for_box(base_dir: str,
                             nickname: str, domain: str,
                             box_name: str) -> None:
    """Generates an index for the given box if it doesn't exist
    Used by unit tests to artificially create an index
    """
    box_dir = acct_dir(base_dir, nickname, domain) + '/' + box_name
    box_index_filename = box_dir + '.index'

    if not os.path.isdir(box_dir):
        return
    if os.path.isfile(box_index_filename):
        return

    index_lines: list[str] = []
    for _, _, files in os.walk(box_dir):
        for fname in files:
            if ':##' not in fname:
                continue
            index_lines.append(fname)
        break

    index_lines.sort(reverse=True)

    result = ''
    try:
        with open(box_index_filename, 'w+', encoding='utf-8') as fp_box:
            for line in index_lines:
                result += line + '\n'
                fp_box.write(line + '\n')
    except OSError:
        print('EX: unable to generate index for ' + box_name + ' ' + result)
    print('Index generated for ' + box_name + '\n' + result)


def create_public_post(base_dir: str,
                       nickname: str, domain: str, port: int, http_prefix: str,
                       content: str, save_to_file: bool,
                       client_to_server: bool, comments_enabled: bool,
                       attach_image_filename: str, media_type: str,
                       image_description: str, video_transcript: str,
                       city: str, in_reply_to: str,
                       in_reply_to_atom_uri: str, subject: str,
                       schedule_post: bool,
                       event_date: str, event_time: str, event_end_time: str,
                       location: str, is_article: bool, system_language: str,
                       conversation_id: str, convthread_id: str,
                       low_bandwidth: bool, content_license_url: str,
                       media_license_url: str, media_creator: str,
                       languages_understood: [], translate: {},
                       buy_url: str, chat_url: str,
                       auto_cw_cache: {},
                       searchable_by: [],
                       session) -> {}:
    """Public post
    """
    domain_full = get_full_domain(domain, port)
    is_moderation_report = False
    event_uuid = None
    category = None
    join_mode = None
    end_date = event_date
    end_time = event_end_time
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = None
    ticket_url = None
    local_actor = local_actor_url(http_prefix, nickname, domain_full)
    automatic_quote_approval = "https://www.w3.org/ns/activitystreams#Public"

    return _create_post_base(base_dir, nickname, domain, port,
                             'https://www.w3.org/ns/activitystreams#Public',
                             local_actor + '/followers',
                             http_prefix, content, save_to_file,
                             client_to_server, comments_enabled,
                             attach_image_filename, media_type,
                             image_description, video_transcript, city,
                             is_moderation_report, is_article,
                             in_reply_to, in_reply_to_atom_uri, subject,
                             schedule_post, event_date, event_time, location,
                             event_uuid, category, join_mode,
                             end_date, end_time,
                             maximum_attendee_capacity,
                             replies_moderation_option,
                             anonymous_participation_enabled,
                             event_status, ticket_url, system_language,
                             conversation_id, convthread_id, low_bandwidth,
                             content_license_url,
                             media_license_url, media_creator,
                             languages_understood, translate, buy_url,
                             chat_url, auto_cw_cache, searchable_by,
                             session, automatic_quote_approval)


def create_reading_post(base_dir: str,
                        nickname: str, domain: str, port: int,
                        http_prefix: str,
                        mentions_str: str, reading_update_type: str,
                        book_title: str, book_url: str, book_rating: float,
                        save_to_file: bool,
                        client_to_server: bool, comments_enabled: bool,
                        attach_image_filename: str, media_type: str,
                        image_description: str, video_transcript: str,
                        city: str, in_reply_to: str,
                        in_reply_to_atom_uri: str, subject: str,
                        schedule_post: bool,
                        event_date: str, event_time: str, event_end_time: str,
                        location: str, is_article: bool, system_language: str,
                        conversation_id: str, convthread_id: str,
                        low_bandwidth: bool, content_license_url: str,
                        media_license_url: str, media_creator: str,
                        languages_understood: [], translate: {},
                        buy_url: str, chat_url: str,
                        auto_cw_cache: {},
                        searchable_by: [], session) -> {}:
    """ reading status post
    """
    content = ''
    if mentions_str:
        if not mentions_str.endswith(' '):
            mentions_str += ' '
    if reading_update_type == 'readingupdatewant':
        content = mentions_str + translate['wants to read'] + \
            ' <a href="' + book_url + \
            '"><i>' + book_title + '</i></a>'
    elif reading_update_type == 'readingupdateread':
        content = mentions_str + translate['reading'] + \
            ' <a href="' + book_url + \
            '"><i>' + book_title + '</i></a>'
    elif reading_update_type == 'readingupdatefinished':
        content = mentions_str + translate['finished reading'] + \
            ' <a href="' + book_url + \
            '"><i>' + book_title + '</i></a>'
    elif reading_update_type == 'readingupdaterating' and book_rating > 0:
        content = translate['rated'] + ' <a href="' + book_url + \
            '"><i>' + book_title + '</i></a>'
    if not content:
        return None
    post_json_object = \
        create_public_post(base_dir,
                           nickname, domain, port, http_prefix,
                           content, save_to_file,
                           client_to_server, comments_enabled,
                           attach_image_filename, media_type,
                           image_description, video_transcript,
                           city, in_reply_to,
                           in_reply_to_atom_uri, subject,
                           schedule_post,
                           event_date, event_time, event_end_time,
                           location, is_article, system_language,
                           conversation_id, convthread_id, low_bandwidth,
                           content_license_url,
                           media_license_url, media_creator,
                           languages_understood, translate,
                           buy_url, chat_url, auto_cw_cache,
                           searchable_by, session)
    if post_json_object:
        post_json_object['object']['tag'] = [{
            'href': book_url,
            'name': book_title,
            'type': 'Edition'
        }]
    return post_json_object


def _append_citations_to_blog_post(base_dir: str,
                                   nickname: str, domain: str,
                                   blog_json: {}) -> None:
    """Appends any citations to a new blog post
    """
    # append citations tags, stored in a file
    citations_filename = \
        acct_dir(base_dir, nickname, domain) + '/.citations.txt'
    if not os.path.isfile(citations_filename):
        return
    citations_separator = '#####'
    citations: list[str] = []
    try:
        with open(citations_filename, 'r', encoding='utf-8') as fp_cit:
            citations = fp_cit.readlines()
    except OSError:
        print('EX: _append_citations_to_blog_post unable to read ' +
              citations_filename)
    for line in citations:
        if citations_separator not in line:
            continue
        sections = line.strip().split(citations_separator)
        if len(sections) != 3:
            continue
        # date_str = sections[0]
        title = sections[1]
        link = sections[2]
        tag_json = {
            "type": "Article",
            "name": title,
            "url": link
        }
        blog_json['object']['tag'].append(tag_json)


def create_blog_post(base_dir: str,
                     nickname: str, domain: str, port: int, http_prefix: str,
                     content: str, save_to_file: bool,
                     client_to_server: bool, comments_enabled: bool,
                     attach_image_filename: str, media_type: str,
                     image_description: str, video_transcript: str,
                     city: str, in_reply_to: str, in_reply_to_atom_uri: str,
                     subject: str, schedule_post: bool,
                     event_date: str, event_time: str, event_end_time: str,
                     location: str, system_language: str,
                     conversation_id: str, convthread_id: str,
                     low_bandwidth: bool, content_license_url: str,
                     media_license_url: str, media_creator: str,
                     languages_understood: [], translate: {},
                     buy_url: str, chat_url: str,
                     searchable_by: [], session) -> {}:
    auto_cw_cache = {}
    blog_json = \
        create_public_post(base_dir,
                           nickname, domain, port, http_prefix,
                           content, save_to_file,
                           client_to_server, comments_enabled,
                           attach_image_filename, media_type,
                           image_description, video_transcript, city,
                           in_reply_to, in_reply_to_atom_uri, subject,
                           schedule_post,
                           event_date, event_time, event_end_time, location,
                           True, system_language,
                           conversation_id, convthread_id,
                           low_bandwidth, content_license_url,
                           media_license_url, media_creator,
                           languages_understood, translate, buy_url, chat_url,
                           auto_cw_cache, searchable_by, session)
    url_str = get_url_from_post(blog_json['object']['url'])
    obj_url = remove_html(url_str)
    if '/@/' not in obj_url:
        blog_json['object']['url'] = obj_url.replace('/@', '/users/')
    _append_citations_to_blog_post(base_dir, nickname, domain, blog_json)

    return blog_json


def create_news_post(base_dir: str,
                     domain: str, port: int, http_prefix: str,
                     content: str, save_to_file: bool,
                     attach_image_filename: str, media_type: str,
                     image_description: str, video_transcript: str, city: str,
                     subject: str, system_language: str,
                     conversation_id: str, convthread_id: str,
                     low_bandwidth: bool, content_license_url: str,
                     media_license_url: str, media_creator: str,
                     languages_understood: [], translate: {},
                     buy_url: str, chat_url: str, session) -> {}:
    auto_cw_cache = {}
    client_to_server = False
    in_reply_to = None
    in_reply_to_atom_uri = None
    schedule_post = False
    event_date = None
    event_time = None
    event_end_time = None
    location = None
    searchable_by = "https://www.w3.org/ns/activitystreams#Public"
    blog = \
        create_public_post(base_dir,
                           'news', domain, port, http_prefix,
                           content, save_to_file,
                           client_to_server, False,
                           attach_image_filename, media_type,
                           image_description, video_transcript, city,
                           in_reply_to, in_reply_to_atom_uri, subject,
                           schedule_post,
                           event_date, event_time, event_end_time, location,
                           True, system_language,
                           conversation_id, convthread_id,
                           low_bandwidth, content_license_url,
                           media_license_url, media_creator,
                           languages_understood, translate, buy_url, chat_url,
                           auto_cw_cache, searchable_by, session)
    blog['object']['type'] = 'Article'
    return blog


def create_question_post(base_dir: str,
                         nickname: str, domain: str, port: int,
                         http_prefix: str,
                         content: str, q_options: [],
                         save_to_file: bool,
                         client_to_server: bool, comments_enabled: bool,
                         attach_image_filename: str, media_type: str,
                         image_description: str, video_transcript: str,
                         city: str, subject: str, duration_days: int,
                         system_language: str, low_bandwidth: bool,
                         content_license_url: str,
                         media_license_url: str, media_creator: str,
                         languages_understood: [], translate: {},
                         auto_cw_cache: {}, session) -> {}:
    """Question post with multiple choice options
    """
    domain_full = get_full_domain(domain, port)
    local_actor = local_actor_url(http_prefix, nickname, domain_full)
    buy_url = ''
    chat_url = ''
    is_moderation_report = False
    is_article = False
    in_reply_to = in_reply_to_atom_uri = None
    schedule_post = False
    event_date = event_time = location = event_uuid = category = None
    join_mode = end_date = end_time = None
    maximum_attendee_capacity = replies_moderation_option = None
    anonymous_participation_enabled = event_status = ticket_url = None
    conversation_id = None
    convthread_id = None
    searchable_by: list[str] = []
    automatic_quote_approval = local_actor
    message_json = \
        _create_post_base(base_dir, nickname, domain, port,
                          'https://www.w3.org/ns/activitystreams#Public',
                          local_actor + '/followers',
                          http_prefix, content, save_to_file,
                          client_to_server, comments_enabled,
                          attach_image_filename, media_type,
                          image_description, video_transcript, city,
                          is_moderation_report, is_article,
                          in_reply_to, in_reply_to_atom_uri, subject,
                          schedule_post, event_date, event_time,
                          location, event_uuid, category,
                          join_mode, end_date, end_time,
                          maximum_attendee_capacity,
                          replies_moderation_option,
                          anonymous_participation_enabled, event_status,
                          ticket_url, system_language,
                          conversation_id, convthread_id, low_bandwidth,
                          content_license_url,
                          media_license_url, media_creator,
                          languages_understood, translate, buy_url,
                          chat_url, auto_cw_cache, searchable_by,
                          session, automatic_quote_approval)
    message_json['object']['type'] = 'Question'
    message_json['object']['oneOf']: list[dict] = []
    message_json['object']['votersCount'] = 0
    curr_time = date_utcnow()
    days_since_epoch = \
        int((curr_time - date_epoch()).days + duration_days)
    end_time = date_epoch() + \
        datetime.timedelta(days_since_epoch)
    message_json['object']['endTime'] = end_time.strftime("%Y-%m-%dT%H:%M:%SZ")
    for question_option in q_options:
        message_json['object']['oneOf'].append({
            "type": "Note",
            "name": question_option,
            "replies": {
                "type": "Collection",
                "totalItems": 0
            }
        })
    return message_json


def create_unlisted_post(base_dir: str,
                         nickname: str, domain: str, port: int,
                         http_prefix: str,
                         content: str, save_to_file: bool,
                         client_to_server: bool, comments_enabled: bool,
                         attach_image_filename: str, media_type: str,
                         image_description: str, video_transcript: str,
                         city: str, in_reply_to: str,
                         in_reply_to_atom_uri: str,
                         subject: str, schedule_post: bool,
                         event_date: str, event_time: str, event_end_time: str,
                         location: str, system_language: str,
                         conversation_id: str, convthread_id: str,
                         low_bandwidth: bool, content_license_url: str,
                         media_license_url: str, media_creator: str,
                         languages_understood: [], translate: {},
                         buy_url: str, chat_url: str,
                         auto_cw_cache: {}, session) -> {}:
    """Unlisted post. This has the #Public and followers links inverted.
    """
    domain_full = get_full_domain(domain, port)
    local_actor = local_actor_url(http_prefix, nickname, domain_full)
    is_moderation_report = False
    is_article = False
    event_uuid = category = join_mode = None
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = None
    ticket_url = None
    searchable_by: list[str] = []
    automatic_quote_approval = local_actor
    return _create_post_base(base_dir, nickname, domain, port,
                             local_actor + '/followers',
                             'https://www.w3.org/ns/activitystreams#Public',
                             http_prefix, content, save_to_file,
                             client_to_server, comments_enabled,
                             attach_image_filename, media_type,
                             image_description, video_transcript, city,
                             is_moderation_report, is_article,
                             in_reply_to, in_reply_to_atom_uri, subject,
                             schedule_post, event_date,
                             event_time, location,
                             event_uuid, category, join_mode,
                             event_date, event_end_time,
                             maximum_attendee_capacity,
                             replies_moderation_option,
                             anonymous_participation_enabled,
                             event_status,
                             ticket_url, system_language,
                             conversation_id, convthread_id, low_bandwidth,
                             content_license_url,
                             media_license_url, media_creator,
                             languages_understood, translate,
                             buy_url, chat_url, auto_cw_cache,
                             searchable_by, session,
                             automatic_quote_approval)


def create_followers_only_post(base_dir: str,
                               nickname: str, domain: str, port: int,
                               http_prefix: str, content: str,
                               save_to_file: bool,
                               client_to_server: bool, comments_enabled: bool,
                               attach_image_filename: str, media_type: str,
                               image_description: str, video_transcript: str,
                               city: str, in_reply_to: str,
                               in_reply_to_atom_uri: str,
                               subject: str, schedule_post: bool,
                               event_date: str,
                               event_time: str,  event_end_time: str,
                               location: str, system_language: str,
                               conversation_id: str, convthread_id: str,
                               low_bandwidth: bool, content_license_url: str,
                               media_license_url: str, media_creator: str,
                               languages_understood: [],
                               translate: {}, buy_url: str,
                               chat_url: str,
                               auto_cw_cache: {},
                               searchable_by: [], session) -> {}:
    """Followers only post
    """
    domain_full = get_full_domain(domain, port)
    local_actor = local_actor_url(http_prefix, nickname, domain_full)
    is_moderation_report = False
    is_article = False
    event_uuid = category = join_mode = None
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = None
    ticket_url = None
    automatic_quote_approval = local_actor + '/following'
    return _create_post_base(base_dir, nickname, domain, port,
                             local_actor + '/followers', None,
                             http_prefix, content, save_to_file,
                             client_to_server, comments_enabled,
                             attach_image_filename, media_type,
                             image_description, video_transcript, city,
                             is_moderation_report, is_article,
                             in_reply_to, in_reply_to_atom_uri, subject,
                             schedule_post, event_date, event_time, location,
                             event_uuid, category, join_mode,
                             event_date, event_end_time,
                             maximum_attendee_capacity,
                             replies_moderation_option,
                             anonymous_participation_enabled,
                             event_status, ticket_url, system_language,
                             conversation_id, convthread_id, low_bandwidth,
                             content_license_url,
                             media_license_url, media_creator,
                             languages_understood, translate,
                             buy_url, chat_url, auto_cw_cache,
                             searchable_by, session,
                             automatic_quote_approval)


def get_mentioned_people(base_dir: str, http_prefix: str,
                         content: str, domain: str, debug: bool) -> []:
    """Extracts a list of mentioned actors from the given message content
    """
    if '@' not in content:
        return None
    mentions: list[str] = []
    words = content.split(' ')
    for wrd in words:
        if not wrd.startswith('@'):
            continue
        handle = wrd[1:]
        if debug:
            print('DEBUG: mentioned handle ' + handle)
        if '@' not in handle:
            handle = handle + '@' + domain
            handle_dir = acct_handle_dir(base_dir, handle)
            if not os.path.isdir(handle_dir):
                continue
        else:
            external_domain = handle.split('@')[1]
            if not ('.' in external_domain or
                    external_domain == 'localhost'):
                continue
        mentioned_nickname = handle.split('@')[0]
        mentioned_domain = handle.split('@')[1].strip('\n').strip('\r')
        if ':' in mentioned_domain:
            mentioned_domain = remove_domain_port(mentioned_domain)
        if not valid_nickname(mentioned_domain, mentioned_nickname):
            continue
        actor = \
            local_actor_url(http_prefix, mentioned_nickname,
                            handle.split('@')[1])
        mentions.append(actor)
    return mentions


def create_direct_message_post(base_dir: str,
                               nickname: str, domain: str, port: int,
                               http_prefix: str, content: str,
                               save_to_file: bool, client_to_server: bool,
                               comments_enabled: bool,
                               attach_image_filename: str, media_type: str,
                               image_description: str, video_transcript: str,
                               city: str, in_reply_to: str,
                               in_reply_to_atom_uri: str,
                               subject: str, debug: bool,
                               schedule_post: bool,
                               event_date: str, event_time: str,
                               event_end_time: str,
                               location: str, system_language: str,
                               conversation_id: str, convthread_id: str,
                               low_bandwidth: bool, content_license_url: str,
                               media_license_url: str, media_creator: str,
                               languages_understood: [],
                               dm_is_chat: bool, translate: {},
                               buy_url: str, chat_url: str,
                               auto_cw_cache: {}, session) -> {}:
    """Direct Message post
    """
    domain_full = get_full_domain(domain, port)
    local_actor = local_actor_url(http_prefix, nickname, domain_full)
    content = resolve_petnames(base_dir, nickname, domain, content)
    mentioned_people = \
        get_mentioned_people(base_dir, http_prefix, content, domain, debug)
    if debug:
        print('mentioned_people: ' + str(mentioned_people))
    if not mentioned_people:
        return None
    post_to = None
    post_cc = None
    is_moderation_report = False
    is_article = False
    event_uuid = category = join_mode = None
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = None
    ticket_url = None
    searchable_by: list[str] = []
    automatic_quote_approval = local_actor
    message_json = \
        _create_post_base(base_dir, nickname, domain, port,
                          post_to, post_cc,
                          http_prefix, content, save_to_file,
                          client_to_server, comments_enabled,
                          attach_image_filename, media_type,
                          image_description, video_transcript, city,
                          is_moderation_report, is_article,
                          in_reply_to, in_reply_to_atom_uri, subject,
                          schedule_post, event_date, event_time, location,
                          event_uuid, category, join_mode,
                          event_date, event_end_time,
                          maximum_attendee_capacity,
                          replies_moderation_option,
                          anonymous_participation_enabled,
                          event_status, ticket_url, system_language,
                          conversation_id, convthread_id, low_bandwidth,
                          content_license_url,
                          media_license_url, media_creator,
                          languages_understood, translate, buy_url, chat_url,
                          auto_cw_cache, searchable_by, session,
                          automatic_quote_approval)
    # mentioned recipients go into To rather than Cc
    message_json['to'] = message_json['object']['cc']
    if not isinstance(message_json['to'], list):
        message_json['to'] = [message_json['to']]
    message_json['object']['to'] = message_json['to']
    message_json['cc']: list[str] = []
    message_json['object']['cc']: list[str] = []
    if dm_is_chat:
        message_json['object']['type'] = 'ChatMessage'
    if schedule_post:
        post_id = remove_id_ending(message_json['object']['id'])
        save_post_to_box(base_dir, http_prefix, post_id,
                         nickname, domain, message_json, 'scheduled')
    return message_json


def create_report_post(base_dir: str,
                       nickname: str, domain: str, port: int, http_prefix: str,
                       content: str, save_to_file: bool,
                       client_to_server: bool, comments_enabled: bool,
                       attach_image_filename: str, media_type: str,
                       image_description: str, video_transcript: str,
                       city: str, debug: bool, subject: str,
                       system_language: str, low_bandwidth: bool,
                       content_license_url: str,
                       media_license_url: str, media_creator: str,
                       languages_understood: [], translate: {},
                       auto_cw_cache: {}, session) -> {}:
    """Send a report to moderators
    """
    domain_full = get_full_domain(domain, port)

    # add a title to distinguish moderation reports from other posts
    report_title = 'Moderation Report'
    if not subject:
        subject = report_title
    else:
        if not subject.startswith(report_title):
            subject = report_title + ': ' + subject

    # create the list of moderators from the moderators file
    moderators_list: list[str] = []
    moderators_file = data_dir(base_dir) + '/moderators.txt'
    if os.path.isfile(moderators_file):
        try:
            with open(moderators_file, 'r', encoding='utf-8') as fp_mod:
                for line in fp_mod:
                    line = line.strip('\n').strip('\r')
                    if line.startswith('#'):
                        continue
                    if line.startswith('/users/'):
                        line = line.replace('users', '')
                    if line.startswith('@'):
                        line = line[1:]
                    if '@' in line:
                        nick = line.split('@')[0]
                        moderator_actor = \
                            local_actor_url(http_prefix, nick, domain_full)
                        if moderator_actor not in moderators_list:
                            moderators_list.append(moderator_actor)
                        continue
                    if line.startswith('http') or \
                       line.startswith('ipfs') or \
                       line.startswith('ipns') or \
                       line.startswith('hyper'):
                        # must be a local address - no remote moderators
                        if '://' + domain_full + '/' in line:
                            if line not in moderators_list:
                                moderators_list.append(line)
                    else:
                        if '/' not in line:
                            moderator_actor = \
                                local_actor_url(http_prefix, line, domain_full)
                            if moderator_actor not in moderators_list:
                                moderators_list.append(moderator_actor)
        except OSError:
            print('EX: create_report_post unable to read ' + moderators_file)
    if len(moderators_list) == 0:
        # if there are no moderators then the admin becomes the moderator
        admin_nickname = get_config_param(base_dir, 'admin')
        if admin_nickname:
            local_actor = \
                local_actor_url(http_prefix, admin_nickname, domain_full)
            moderators_list.append(local_actor)
    if not moderators_list:
        return None
    if debug:
        print('DEBUG: Sending report to moderators')
        print(str(moderators_list))
    post_to = moderators_list
    post_cc = None
    post_json_object = None
    buy_url = ''
    chat_url = ''
    is_moderation_report = True
    is_article = False
    in_reply_to = in_reply_to_atom_uri = None
    schedule_post = False
    event_date = None
    event_time = None
    location = None
    event_uuid = None
    category = None
    join_mode = None
    end_date = None
    end_time = None
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = None
    ticket_url = None
    conversation_id = None
    convthread_id = None
    searchable_by: list[str] = []
    sending_actor = local_actor_url(http_prefix, nickname, domain_full)
    automatic_quote_approval = sending_actor
    for to_url in post_to:
        # who is this report going to?
        to_nickname = to_url.split('/users/')[1]
        handle = to_nickname + '@' + domain
        post_json_object = \
            _create_post_base(base_dir, nickname, domain, port,
                              to_url, post_cc,
                              http_prefix, content, save_to_file,
                              client_to_server, comments_enabled,
                              attach_image_filename, media_type,
                              image_description, video_transcript, city,
                              is_moderation_report, is_article,
                              in_reply_to, in_reply_to_atom_uri, subject,
                              schedule_post, event_date, event_time,
                              location, event_uuid, category,
                              join_mode, end_date, end_time,
                              maximum_attendee_capacity,
                              replies_moderation_option,
                              anonymous_participation_enabled,
                              event_status, ticket_url, system_language,
                              conversation_id, convthread_id, low_bandwidth,
                              content_license_url,
                              media_license_url, media_creator,
                              languages_understood, translate,
                              buy_url, chat_url, auto_cw_cache,
                              searchable_by, session,
                              automatic_quote_approval)
        if not post_json_object:
            continue

        # save a notification file so that the moderator
        # knows something new has appeared
        new_report_file = acct_handle_dir(base_dir, handle) + '/.newReport'
        if os.path.isfile(new_report_file):
            continue
        try:
            with open(new_report_file, 'w+', encoding='utf-8') as fp_report:
                fp_report.write(to_url + '/moderation')
        except OSError:
            print('EX: create_report_post unable to write ' + new_report_file)

    return post_json_object


def _add_send_block(base_dir: str, nickname: str, domain: str,
                    inbox_url: str) -> None:
    """Adds an inbox which is blocking this instance
    """
    send_block_filename = \
        acct_dir(base_dir, nickname, domain) + '/send_blocks.txt'

    inbox_url = inbox_url + '\n'
    if inbox_url.endswith('/inbox\n'):
        inbox_url = inbox_url.replace('/inbox\n', '\n')

    if not os.path.isfile(send_block_filename):
        try:
            with open(send_block_filename, 'w+',
                      encoding='utf-8') as fp_blocks:
                fp_blocks.write(inbox_url)
        except OSError:
            print('EX: _add_send_block unable to create ' +
                  send_block_filename)
        return

    if not text_in_file(inbox_url, send_block_filename, False):
        try:
            with open(send_block_filename, 'a+',
                      encoding='utf-8') as fp_blocks:
                fp_blocks.write(inbox_url)
        except OSError:
            print('EX: _add_send_block unable to write ' +
                  send_block_filename)


def _remove_send_block(base_dir: str, nickname: str, domain: str,
                       inbox_url: str) -> None:
    """Removes an inbox which is blocking this instance
    """
    send_block_filename = \
        acct_dir(base_dir, nickname, domain) + '/send_blocks.txt'

    inbox_url = inbox_url + '\n'
    if inbox_url.endswith('/inbox\n'):
        inbox_url = inbox_url.replace('/inbox\n', '\n')

    if not os.path.isfile(send_block_filename):
        return

    if text_in_file(inbox_url, send_block_filename, False):
        send_blocks_str = ''
        try:
            with open(send_block_filename, 'r',
                      encoding='utf-8') as fp_blocks:
                send_blocks_str = fp_blocks.read()
        except OSError:
            print('EX: _remove_send_block unable to read ' +
                  send_block_filename)
        send_blocks_str = send_blocks_str.replace(inbox_url, '')
        try:
            with open(send_block_filename, 'w+',
                      encoding='utf-8') as fp_blocks:
                fp_blocks.write(send_blocks_str)
        except OSError:
            print('EX: _remove_send_block unable to write ' +
                  send_block_filename)


def thread_send_post(session, post_json_str: str, federation_list: [],
                     inbox_url: str, base_dir: str,
                     signature_header_json: {},
                     signature_header_json_ld: {},
                     post_log: [], debug: bool,
                     http_prefix: str, domain_full: str,
                     nickname: str, domain: str) -> None:
    """Sends a with retries
    """
    tries = 0
    send_interval_sec = 30
    for _ in range(20):
        post_result = None
        unauthorized = False
        if debug:
            print('Getting post_json_string for ' + inbox_url)
        try:
            post_result, unauthorized, return_code = \
                post_json_string(session, post_json_str, federation_list,
                                 inbox_url, signature_header_json,
                                 debug, http_prefix, domain_full)
            if return_code in range(500, 600):
                # if an instance is returning a code which indicates that
                # it might have a runtime error, like 503, then don't
                # continue to post to it
                break
            if debug:
                print('Obtained post_json_string for ' + inbox_url +
                      ' unauthorized: ' + str(unauthorized))
        except BaseException as ex:
            print('ERROR: post_json_string failed ' + str(ex))

        if unauthorized:
            # try again with application/ld+json header
            post_result = None
            unauthorized = False
            if debug:
                print('Getting ld post_json_string for ' + inbox_url)
            try:
                post_result, unauthorized, return_code = \
                    post_json_string(session, post_json_str, federation_list,
                                     inbox_url, signature_header_json_ld,
                                     debug, http_prefix, domain_full)
                if return_code in range(500, 600):
                    # if an instance is returning a code which indicates that
                    # it might have a runtime error, like 503, then don't
                    # continue to post to it
                    break
                if debug:
                    print('Obtained ld post_json_string for ' + inbox_url +
                          ' unauthorized: ' + str(unauthorized))
            except BaseException as ex:
                print('ERROR: ld post_json_string failed ' + str(ex))

        if unauthorized:
            _add_send_block(base_dir, nickname, domain, inbox_url)
            if debug:
                print('WARN: thread_send_post: Post is unauthorized ' +
                      inbox_url + ' ' + post_json_str)
            else:
                print('WARN: thread_send_post: Post is unauthorized ' +
                      inbox_url)
            break
        if post_result:
            log_str = 'Success on try ' + str(tries) + ': ' + post_json_str
        else:
            log_str = 'Retry ' + str(tries) + ': ' + post_json_str
        post_log.append(log_str)
        # keep the length of the log finite
        # Don't accumulate massive files on systems with limited resources
        while len(post_log) > 16:
            post_log.pop(0)
        if debug:
            # save the log file
            post_log_filename = base_dir + '/post.log'
            if os.path.isfile(post_log_filename):
                try:
                    with open(post_log_filename, 'a+',
                              encoding='utf-8') as fp_log:
                        fp_log.write(log_str + '\n')
                except OSError:
                    print('EX: thread_send_post unable to append ' +
                          post_log_filename)
            else:
                try:
                    with open(post_log_filename, 'w+',
                              encoding='utf-8') as fp_log:
                        fp_log.write(log_str + '\n')
                except OSError:
                    print('EX: thread_send_post unable to write ' +
                          post_log_filename)

        if post_result:
            _remove_send_block(base_dir, nickname, domain, inbox_url)
            if debug:
                print('DEBUG: successful json post to ' + inbox_url)
            # our work here is done
            break
        if debug:
            print(post_json_str)
            print('DEBUG: json post to ' + inbox_url +
                  ' failed. Waiting for ' +
                  str(send_interval_sec) + ' seconds.')
        time.sleep(send_interval_sec)
        tries += 1


def _get_destination_inbox_domain(inbox_url: str, to_domain: str) -> str:
    """ the inbox domain being sent to isn't always the same
    as the destination instance domain. eg public-api.wordpress.com
    rather than the wordpress instance domain
    """
    inbox_url_domain, inbox_url_port = get_domain_from_actor(inbox_url)
    if not inbox_url_domain:
        return inbox_url
    inbox_domain = get_full_domain(inbox_url_domain, inbox_url_port)
    if inbox_domain != to_domain:
        print('_get_destination_inbox_domain ' +
              'inbox domain is not the same as instance domain ' +
              inbox_domain + ' != ' + to_domain)
        return inbox_domain
    return to_domain


def send_post(signing_priv_key_pem: str, project_version: str,
              session, base_dir: str, nickname: str, domain: str, port: int,
              to_nickname: str, to_domain: str, to_port: int, cc_str: str,
              http_prefix: str, content: str,
              save_to_file: bool, client_to_server: bool,
              comments_enabled: bool,
              attach_image_filename: str, media_type: str,
              image_description: str, video_transcript: str, city: str,
              federation_list: [], send_threads: [], post_log: [],
              cached_webfingers: {}, person_cache: {},
              is_article: bool, system_language: str,
              languages_understood: [],
              shared_items_federated_domains: [],
              shared_item_federation_tokens: {},
              low_bandwidth: bool, content_license_url: str,
              media_license_url: str, media_creator: str,
              translate: {}, buy_url: str, chat_url: str,
              auto_cw_cache: {},
              debug: bool, in_reply_to: str,
              in_reply_to_atom_uri: str, subject: str,
              searchable_by: [],
              mitm_servers: []) -> int:
    """Post to another inbox. Used by unit tests.
    """
    with_digest = True
    conversation_id = None
    convthread_id = None

    if to_nickname == 'inbox':
        # shared inbox actor on @domain@domain
        to_nickname = to_domain

    to_domain = get_full_domain(to_domain, to_port)

    handle = http_prefix + '://' + to_domain + '/@' + to_nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: Webfinger for ' + handle + ' did not return a dict. ' +
              str(wf_request))
        return 1

    if not client_to_server:
        post_to_box = 'inbox'
    else:
        post_to_box = 'outbox'
        if is_article:
            post_to_box = 'tlblogs'

    # get the actor inbox for the To handle
    origin_domain = domain
    (inbox_url, _, pub_key, to_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            nickname, domain, post_to_box,
                            72533, system_language,
                            mitm_servers)

    if not inbox_url:
        return 3
    if not pub_key:
        return 4
    if not to_person_id:
        return 5
    # shared_inbox is optional

    is_moderation_report = False
    schedule_post = False
    event_date = event_time = location = None
    event_uuid = category = None
    join_mode = None
    end_date = None
    end_time = None
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = ticket_url = None
    domain_full = get_full_domain(domain, port)
    local_actor = local_actor_url(http_prefix, nickname, domain_full)
    automatic_quote_approval = local_actor
    post_json_object = \
        _create_post_base(base_dir, nickname, domain, port,
                          to_person_id, cc_str, http_prefix, content,
                          save_to_file, client_to_server,
                          comments_enabled,
                          attach_image_filename, media_type,
                          image_description, video_transcript, city,
                          is_moderation_report, is_article, in_reply_to,
                          in_reply_to_atom_uri, subject,
                          schedule_post,
                          event_date, event_time, location,
                          event_uuid, category,
                          join_mode,
                          end_date, end_time,
                          maximum_attendee_capacity,
                          replies_moderation_option,
                          anonymous_participation_enabled,
                          event_status, ticket_url, system_language,
                          conversation_id, convthread_id, low_bandwidth,
                          content_license_url,
                          media_license_url, media_creator,
                          languages_understood,
                          translate, buy_url, chat_url,
                          auto_cw_cache, searchable_by, session,
                          automatic_quote_approval)

    # get the senders private key
    private_key_pem = get_person_key(nickname, domain, base_dir,
                                     'private', debug)
    if len(private_key_pem) == 0:
        return 6

    # NOTE: the inbox domain being sent to isn't always the same
    # as the destination instance domain. eg public-api.wordpress.com
    # rather than the wordpress instance domain
    to_domain = _get_destination_inbox_domain(inbox_url, to_domain)

    if to_domain not in inbox_url:
        return 7

    # remove the domain to leave the path on its own
    post_path = inbox_url.split(to_domain, 1)[1]

    if not post_json_object.get('signature'):
        json_copied = False
        try:
            signed_post_json_object = post_json_object.copy()
            json_copied = True
        except BaseException as ex:
            print('WARN: send_post failed to copy json post, ' + str(ex))
            pprint(post_json_object)

        if json_copied:
            try:
                generate_json_signature(signed_post_json_object,
                                        private_key_pem, debug)
                post_json_object = signed_post_json_object
            except JsonLdError as ex:
                print('WARN: send_post failed to JSON-LD sign post, ' +
                      str(ex))
                pprint(signed_post_json_object)

    # convert json to string so that there are no
    # subsequent conversions after creating message body digest
    post_json_str = json.dumps(post_json_object)

    # construct the http header, including the message body digest
    signature_header_json = \
        create_signed_header(None, private_key_pem, nickname, domain, port,
                             to_domain, to_port,
                             post_path, http_prefix, with_digest,
                             post_json_str, 'application/activity+json')
    signature_header_json_ld = \
        create_signed_header(None, private_key_pem, nickname, domain, port,
                             to_domain, to_port,
                             post_path, http_prefix, with_digest,
                             post_json_str, 'application/ld+json')

    # if the "to" domain is within the shared items
    # federation list then send the token for this domain
    # so that it can request a catalog
    domain_full = get_full_domain(domain, port)
    if to_domain in shared_items_federated_domains:
        if shared_item_federation_tokens.get(domain_full):
            signature_header_json['Origin'] = domain_full
            signature_header_json_ld['Origin'] = domain_full
            signature_header_json['SharesCatalog'] = \
                shared_item_federation_tokens[domain_full]
            signature_header_json_ld['SharesCatalog'] = \
                shared_item_federation_tokens[domain_full]
            if debug:
                print('SharesCatalog added to header')
        elif debug:
            print(domain_full + ' not in shared_item_federation_tokens')
    elif debug:
        print(to_domain + ' not in shared_items_federated_domains ' +
              str(shared_items_federated_domains))

    if debug:
        print('signature_header_json: ' + str(signature_header_json))

    # Keep the number of threads being used small
    while len(send_threads) > 1000:
        print('WARN: Maximum threads reached - killing send thread')
        send_threads[0].kill()
        send_threads.pop(0)
        print('WARN: thread killed')
    print('THREAD: thread_send_post')
    thr = \
        thread_with_trace(target=thread_send_post,
                          args=(session,
                                post_json_str,
                                federation_list,
                                inbox_url, base_dir,
                                signature_header_json.copy(),
                                signature_header_json_ld.copy(),
                                post_log, debug, http_prefix,
                                domain_full,
                                nickname, domain), daemon=True)
    send_threads.append(thr)
    begin_thread(thr, 'send_post')
    return 0


def send_post_via_server(signing_priv_key_pem: str, project_version: str,
                         base_dir: str, session,
                         from_nickname: str, password: str,
                         from_domain: str, from_port: int,
                         to_nickname: str, to_domain: str, to_port: int,
                         cc_str: str,
                         http_prefix: str, content: str,
                         comments_enabled: bool,
                         attach_image_filename: str, media_type: str,
                         image_description: str, video_transcript: str,
                         city: str, cached_webfingers: {}, person_cache: {},
                         is_article: bool, system_language: str,
                         languages_understood: [],
                         low_bandwidth: bool,
                         content_license_url: str,
                         media_license_url: str, media_creator: str,
                         event_date: str, event_time: str, event_end_time: str,
                         location: str, translate: {},
                         buy_url: str, chat_url: str, auto_cw_cache: {},
                         debug: bool, in_reply_to: str,
                         in_reply_to_atom_uri: str,
                         conversation_id: str, convthread_id: str,
                         subject: str, searchable_by: [],
                         mitm_servers: []) -> int:
    """Send a post via a proxy (c2s)
    """
    if not session:
        print('WARN: No session for send_post_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         from_domain_full, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: post webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: post webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'
    if is_article:
        post_to_box = 'tlblogs'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            from_nickname,
                            from_domain_full, post_to_box,
                            82796, system_language,
                            mitm_servers)
    if not inbox_url:
        if debug:
            print('DEBUG: post no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: post no actor was found for ' + handle)
        return 4

    # Get the json for the c2s post, not saving anything to file
    # Note that base_dir is set to None
    save_to_file = False
    client_to_server = True
    if to_domain.lower().endswith('public'):
        to_person_id = 'https://www.w3.org/ns/activitystreams#Public'
        cc_str = \
            local_actor_url(http_prefix, from_nickname, from_domain_full) + \
            '/followers'
        automatic_quote_approval = \
            "https://www.w3.org/ns/activitystreams#Public"
    else:
        if to_domain.lower().endswith('followers') or \
           to_domain.lower().endswith('followersonly'):
            from_local_actor = \
                local_actor_url(http_prefix, from_nickname, from_domain_full)
            to_person_id = from_local_actor + '/followers'
            automatic_quote_approval = from_local_actor + '/following'
        else:
            to_domain_full = get_full_domain(to_domain, to_port)
            to_person_id = \
                local_actor_url(http_prefix, to_nickname, to_domain_full)
            automatic_quote_approval = \
                local_actor_url(http_prefix,
                                from_nickname, from_domain_full)

    is_moderation_report = False
    schedule_post = False
    event_uuid = category = join_mode = None
    maximum_attendee_capacity = None
    replies_moderation_option = None
    anonymous_participation_enabled = None
    event_status = ticket_url = None
    post_json_object = \
        _create_post_base(base_dir,
                          from_nickname, from_domain, from_port,
                          to_person_id, cc_str, http_prefix, content,
                          save_to_file, client_to_server,
                          comments_enabled,
                          attach_image_filename, media_type,
                          image_description, video_transcript, city,
                          is_moderation_report, is_article, in_reply_to,
                          in_reply_to_atom_uri, subject, schedule_post,
                          event_date, event_time, location,
                          event_uuid, category, join_mode,
                          event_date, event_end_time,
                          maximum_attendee_capacity,
                          replies_moderation_option,
                          anonymous_participation_enabled,
                          event_status, ticket_url, system_language,
                          conversation_id, convthread_id, low_bandwidth,
                          content_license_url,
                          media_license_url, media_creator,
                          languages_understood,
                          translate, buy_url, chat_url, auto_cw_cache,
                          searchable_by, session,
                          automatic_quote_approval)

    auth_header = create_basic_auth_header(from_nickname, password)

    if attach_image_filename:
        headers = {
            'host': from_domain_full,
            'Authorization': auth_header
        }
        post_result = \
            post_image(session, attach_image_filename, [],
                       inbox_url, headers, http_prefix, from_domain_full)
        if not post_result:
            if debug:
                print('DEBUG: post failed to upload image')
#            return 9

    headers = {
        'host': from_domain_full,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_dumps = json.dumps(post_json_object)
    post_result, unauthorized, return_code = \
        post_json_string(session, post_dumps, [],
                         inbox_url, headers, debug,
                         http_prefix, from_domain_full,
                         5, True)
    if not post_result:
        if debug:
            if unauthorized:
                print('DEBUG: POST failed for c2s to ' +
                      inbox_url + ' unathorized')
            else:
                print('DEBUG: POST failed for c2s to ' +
                      inbox_url + ' return code ' + str(return_code))
        return 5

    if debug:
        print('DEBUG: c2s POST success')
    return 0


def group_followers_by_domain(base_dir: str, nickname: str, domain: str) -> {}:
    """Returns a dictionary with followers grouped by domain
    """
    handle = nickname + '@' + domain
    followers_filename = acct_handle_dir(base_dir, handle) + '/followers.txt'
    if not os.path.isfile(followers_filename):
        return None
    grouped = {}
    try:
        with open(followers_filename, 'r', encoding='utf-8') as fp_foll:
            for follower_handle in fp_foll:
                if '@' not in follower_handle:
                    continue
                fhandle1 = follower_handle.strip()
                fhandle = remove_eol(fhandle1)
                follower_domain = fhandle.split('@')[1]
                if not grouped.get(follower_domain):
                    grouped[follower_domain] = [fhandle]
                else:
                    grouped[follower_domain].append(fhandle)
    except OSError:
        print('EX: group_followers_by_domain unable to read ' +
              followers_filename)
    return grouped


def _add_followers_to_public_post(post_json_object: {}) -> None:
    """Adds followers entry to cc if it doesn't exist
    """
    if not post_json_object.get('actor'):
        return

    if isinstance(post_json_object['object'], str):
        if not post_json_object.get('to'):
            return
        if len(post_json_object['to']) > 1:
            return
        if len(post_json_object['to']) == 0:
            return
        if not post_json_object['to'][0].endswith('#Public'):
            if not post_json_object['to'][0] == 'as:Public':
                if not post_json_object['to'][0] == 'Public':
                    return
        if post_json_object.get('cc'):
            return
        actor_url = get_actor_from_post(post_json_object)
        post_json_object['cc'] = [actor_url + '/followers']
    elif has_object_dict(post_json_object):
        if not post_json_object['object'].get('to'):
            return
        if len(post_json_object['object']['to']) > 1:
            return
        if len(post_json_object['object']['to']) == 0:
            return
        if not post_json_object['object']['to'][0].endswith('#Public'):
            if not post_json_object['object']['to'][0] == 'as:Public':
                if not post_json_object['object']['to'][0] == 'Public':
                    return
        if post_json_object['object'].get('cc'):
            return
        actor_url = get_actor_from_post(post_json_object)
        post_json_object['object']['cc'] = \
            [actor_url + '/followers']


def send_signed_json(post_json_object: {}, session, base_dir: str,
                     nickname: str, domain: str, port: int,
                     to_nickname: str, to_domain: str,
                     to_port: int, http_prefix: str,
                     client_to_server: bool, federation_list: [],
                     send_threads: [], post_log: [], cached_webfingers: {},
                     person_cache: {}, debug: bool, project_version: str,
                     shared_items_token: str, group_account: bool,
                     signing_priv_key_pem: str,
                     source_id: int, curr_domain: str,
                     onion_domain: str, i2p_domain: str,
                     extra_headers: {}, sites_unavailable: [],
                     system_language: str,
                     mitm_servers: []) -> int:
    """Sends a signed json object to an inbox/outbox
    """
    if debug:
        print('DEBUG: send_signed_json start')
    if not session:
        print('WARN: No session specified for send_signed_json')
        return 8
    with_digest = True

    if to_domain.endswith('.onion') or to_domain.endswith('.i2p'):
        http_prefix = 'http'

    if to_nickname == 'inbox':
        # shared inbox actor on @domain@domain
        to_nickname = to_domain

    to_domain = get_full_domain(to_domain, to_port)

    to_domain_url = http_prefix + '://' + to_domain
    if not site_is_active(to_domain_url, 10, sites_unavailable):
        print('send_signed_json domain is inactive: ' + to_domain_url)
        return 9
    print('Domain is active: ' + to_domain_url)
    handle_base = to_domain_url + '/@'
    if to_nickname:
        handle = handle_base + to_nickname
    else:
        single_user_instance_nickname = 'dev'
        handle = handle_base + single_user_instance_nickname

    if debug:
        print('DEBUG: send_signed_json handle - ' + handle +
              ' to_port ' + str(to_port))

    # domain shown in the user agent
    ua_domain = curr_domain
    if to_domain.endswith('.onion'):
        ua_domain = onion_domain
    elif to_domain.endswith('.i2p'):
        ua_domain = i2p_domain

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  ua_domain, project_version, debug,
                                  group_account, signing_priv_key_pem,
                                  mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: send_signed_json webfinger for ' +
                  handle + ' failed')
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: send_signed_json webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    if wf_request.get('errors'):
        if debug:
            print('DEBUG: send_signed_json webfinger for ' + handle +
                  ' failed with errors ' + str(wf_request['errors']))

    if not client_to_server:
        post_to_box = 'inbox'
    else:
        post_to_box = 'outbox'

    # get the actor inbox/outbox for the To handle
    origin_domain = domain
    (inbox_url, _, pub_key, to_person_id, shared_inbox_url, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            nickname, domain, post_to_box,
                            source_id, system_language,
                            mitm_servers)

    print("send_signed_json inbox_url: " + str(inbox_url))
    print("send_signed_json to_person_id: " + str(to_person_id))
    print("send_signed_json shared_inbox_url: " + str(shared_inbox_url))
    if inbox_url:
        if inbox_url.endswith('/actor/inbox') or \
           inbox_url.endswith('/instance.actor/inbox'):
            inbox_url = shared_inbox_url

    if not inbox_url:
        if debug:
            print('DEBUG: send_signed_json missing inbox_url')
        return 3

    if debug:
        print('DEBUG: send_signed_json sending to endpoint ' + inbox_url)

    if not pub_key:
        if debug:
            print('DEBUG: send_signed_json missing pubkey')
        return 4
    if not to_person_id:
        if debug:
            print('DEBUG: send_signed_json missing person_id')
        return 5
    # shared_inbox is optional

    # get the senders private key
    account_domain = origin_domain
    if onion_domain:
        if account_domain == onion_domain:
            account_domain = curr_domain
    if i2p_domain:
        if account_domain == i2p_domain:
            account_domain = curr_domain
    private_key_pem = \
        get_person_key(nickname, account_domain, base_dir, 'private', debug)
    if len(private_key_pem) == 0:
        if debug:
            print('DEBUG: send_signed_json private key not found for ' +
                  nickname + '@' + account_domain +
                  ' in ' + base_dir + '/keys/private')
        return 6

    # NOTE: the inbox domain being sent to isn't always the same
    # as the destination instance domain. eg public-api.wordpress.com
    # rather than the wordpress instance domain
    to_domain = _get_destination_inbox_domain(inbox_url, to_domain)

    if to_domain not in inbox_url:
        if debug:
            print('DEBUG: send_signed_json ' +
                  to_domain + ' is not in ' + inbox_url)
        return 7

    # remove the domain to leave the path on its own
    post_path = inbox_url.split(to_domain, 1)[1]

    _add_followers_to_public_post(post_json_object)

    if not post_json_object.get('signature'):
        try:
            signed_post_json_object = post_json_object.copy()
            generate_json_signature(signed_post_json_object,
                                    private_key_pem, debug)
            post_json_object = signed_post_json_object
        except BaseException as ex:
            print('WARN: send_signed_json failed to JSON-LD sign post, ' +
                  str(ex))
            pprint(signed_post_json_object)

    # convert json to string so that there are no
    # subsequent conversions after creating message body digest
    post_json_str = json.dumps(post_json_object)

    # if the sender domain has changed from clearnet to onion or i2p
    # then change the content of the post accordingly
    if debug:
        print('send_signed_json checking for changed origin domain: ' +
              domain + ' ' + curr_domain)
    if domain != curr_domain:
        if not curr_domain.endswith('.onion') and \
           not curr_domain.endswith('.i2p'):
            if debug:
                print('send_signed_json ' +
                      'changing post content sender domain from ' +
                      curr_domain + ' to ' + domain)
            post_json_str = \
                post_json_str.replace(curr_domain, domain)

    # construct the http header, including the message body digest
    signature_header_json = \
        create_signed_header(None, private_key_pem, nickname, domain, port,
                             to_domain, to_port,
                             post_path, http_prefix, with_digest,
                             post_json_str,
                             'application/activity+json')
    signature_header_json_ld = \
        create_signed_header(None, private_key_pem, nickname, domain, port,
                             to_domain, to_port,
                             post_path, http_prefix, with_digest,
                             post_json_str,
                             'application/ld+json')
    # optionally add a token so that the receiving instance may access
    # your shared items catalog
    if shared_items_token:
        signature_header_json['Origin'] = get_full_domain(domain, port)
        signature_header_json['SharesCatalog'] = shared_items_token
    elif debug:
        print('send_signed_json not sending shared items federation token')

    # add any extra headers
    for header_title, header_text in extra_headers.items():
        signature_header_json[header_title] = header_text

    # Keep the number of threads being used small
    while len(send_threads) > 1000:
        print('WARN: send_signed_json maximum threads reached - ' +
              'killing send thread')
        send_threads[0].kill()
        send_threads.pop(0)
        print('WARN: send_signed_json thread killed')

    if debug:
        print('DEBUG: send_signed_json starting thread to send post')
        pprint(post_json_object)
    domain_full = get_full_domain(domain, port)
    print('THREAD: send_signed_json thread_send_post 2')
    thr = \
        thread_with_trace(target=thread_send_post,
                          args=(session,
                                post_json_str,
                                federation_list,
                                inbox_url, base_dir,
                                signature_header_json.copy(),
                                signature_header_json_ld.copy(),
                                post_log, debug,
                                http_prefix, domain_full,
                                nickname, domain), daemon=True)
    send_threads.append(thr)
    # begin_thread(thr, 'send_signed_json')
    return 0


def add_to_field(activity_type: str, post_json_object: {},
                 debug: bool) -> ({}, bool):
    """The Follow/Add/Remove activity doesn't have a 'to' field and so one
    needs to be added so that activity distribution happens in a consistent way
    Returns true if a 'to' field exists or was added
    """
    if post_json_object.get('to'):
        return post_json_object, True

    if debug:
        pprint(post_json_object)
        print('DEBUG: no "to" field when sending to named addresses 2')

    is_same_type = False
    to_field_added = False
    if post_json_object.get('object'):
        if isinstance(post_json_object['object'], str):
            if post_json_object.get('type'):
                if post_json_object['type'] == activity_type:
                    is_same_type = True
                    if debug:
                        print('DEBUG: ' +
                              'add_to_field1 "to" field assigned to ' +
                              activity_type)
                    to_address = post_json_object['object']
                    if contains_statuses(to_address):
                        if has_users_path(to_address):
                            to_address = get_actor_from_post_id(to_address)
                    post_json_object['to'] = [to_address]
                    if debug:
                        print('DEBUG: "to" address added: ' + to_address)
                    to_field_added = True
        elif has_object_dict(post_json_object):
            # add a to field to bookmark add or remove
            if post_json_object.get('type') and \
               post_json_object.get('actor') and \
               post_json_object['object'].get('type'):
                if post_json_object['type'] == 'Add' or \
                   post_json_object['type'] == 'Remove':
                    if post_json_object['object']['type'] == 'Document':
                        actor_url = get_actor_from_post(post_json_object)
                        post_json_object['to'] = [actor_url]
                        post_json_object['object']['to'] = [actor_url]
                        to_field_added = True

            if not to_field_added and \
               post_json_object['object'].get('type'):
                if post_json_object['object']['type'] == activity_type:
                    is_same_type = True
                    if isinstance(post_json_object['object']['object'], str):
                        if debug:
                            print('DEBUG: add_to_field2 ' +
                                  '"to" field assigned to ' +
                                  activity_type)
                        to_address = post_json_object['object']['object']
                        if contains_statuses(to_address):
                            if has_users_path(to_address):
                                to_address = get_actor_from_post_id(to_address)
                        post_json_object['object']['to'] = [to_address]
                        post_json_object['to'] = [to_address]
                        if debug:
                            print('DEBUG: "to" address added: ' + to_address)
                        to_field_added = True

    if not is_same_type:
        return post_json_object, True
    if to_field_added:
        return post_json_object, True
    return post_json_object, False


def _is_profile_update(post_json_object: {}) -> bool:
    """Is the given post a profile update?
    for actor updates there is no 'to' within the object
    """
    if post_json_object.get('type'):
        if has_object_string_type(post_json_object, False):
            if (post_json_object['type'] == 'Update' and
                (post_json_object['object']['type'] == 'Person' or
                 post_json_object['object']['type'] == 'Application' or
                 post_json_object['object']['type'] == 'Group' or
                 post_json_object['object']['type'] == 'Service')):
                return True
    return False


def _send_to_named_addresses(server, session, session_onion, session_i2p,
                             base_dir: str,
                             nickname: str, domain: str,
                             onion_domain: str, i2p_domain: str, port: int,
                             http_prefix: str, federation_list: [],
                             send_threads: [], post_log: [],
                             cached_webfingers: {}, person_cache: {},
                             post_json_object: {}, debug: bool,
                             project_version: str,
                             shared_items_federated_domains: [],
                             shared_item_federation_tokens: {},
                             signing_priv_key_pem: str,
                             proxy_type: str,
                             followers_sync_cache: {},
                             sites_unavailable: [],
                             system_language: str,
                             mitm_servers: []) -> None:
    """sends a post to the specific named addresses in to/cc
    """
    if not session:
        print('WARN: No session for sendToNamedAddresses')
        return
    if not post_json_object.get('object'):
        return
    is_profile_update = False
    if has_object_dict(post_json_object):
        if _is_profile_update(post_json_object):
            # use the original object, which has a 'to'
            recipients_object = post_json_object
            is_profile_update = True

        if not is_profile_update:
            if not post_json_object['object'].get('to'):
                if debug:
                    pprint(post_json_object)
                    print('DEBUG: ' +
                          'no "to" field when sending to named addresses')
                if has_object_string_type(post_json_object, debug):
                    if post_json_object['object']['type'] == 'Follow' or \
                       post_json_object['object']['type'] == 'Join':
                        post_json_obj2 = post_json_object['object']['object']
                        if isinstance(post_json_obj2, str):
                            if debug:
                                print('DEBUG: _send_to_named_addresses ' +
                                      '"to" field assigned to Follow')
                            post_json_object['object']['to'] = \
                                [post_json_object['object']['object']]
                if not post_json_object['object'].get('to'):
                    return
            recipients_object = post_json_object['object']
    else:
        post_json_object, field_added = \
            add_to_field('Follow', post_json_object, debug)
        if not field_added:
            return
        post_json_object, field_added = \
            add_to_field('Like', post_json_object, debug)
        if not field_added:
            return
        recipients_object = post_json_object

    recipients: list[str] = []
    recipient_type = ('to', 'cc')
    for rtype in recipient_type:
        if not recipients_object.get(rtype):
            continue
        if isinstance(recipients_object[rtype], list):
            if debug:
                pprint(recipients_object)
                print('recipients_object: ' + str(recipients_object[rtype]))
            for address in recipients_object[rtype]:
                if not address:
                    continue
                if '/' not in address:
                    continue
                if address.endswith('#Public') or \
                   address == 'as:Public' or \
                   address == 'Public':
                    continue
                if address.endswith('/followers'):
                    continue
                recipients.append(address)
        elif isinstance(recipients_object[rtype], str):
            address = recipients_object[rtype]
            if address:
                if '/' in address:
                    if address.endswith('#Public') or \
                       address == 'as:Public' or \
                       address == 'Public':
                        continue
                    if address.endswith('/followers'):
                        continue
                    recipients.append(address)
    if not recipients:
        if debug:
            print('DEBUG: no individual recipients')
        return
    if debug:
        print('DEBUG: Sending individually addressed posts: ' +
              str(recipients))
    domain_full = get_full_domain(domain, port)
    # randomize the recipients list order, so that we are not favoring
    # any particular account in terms of delivery time
    random.shuffle(recipients)
    # this is after the message has arrived at the server
    client_to_server = False
    for address in recipients:
        to_nickname = get_nickname_from_actor(address)
        if not to_nickname:
            continue
        to_domain, to_port = get_domain_from_actor(address)
        if not to_domain:
            continue
        to_domain_full = get_full_domain(to_domain, to_port)
        # Don't send profile/actor updates to yourself
        if is_profile_update:
            if nickname == to_nickname and \
               domain_full == to_domain_full:
                if debug:
                    print('Not sending profile update to self. ' +
                          nickname + '@' + domain_full)
                continue

        # if we have an alt onion domain and we are sending to
        # another onion domain then switch the clearnet
        # domain for the onion one
        from_domain = domain
        from_domain_full = get_full_domain(domain, port)
        from_http_prefix = http_prefix
        curr_session = session
        curr_proxy_type = proxy_type
        session_type = 'default'
        if onion_domain:
            if not from_domain.endswith('.onion') and \
               to_domain.endswith('.onion'):
                from_domain = onion_domain
                from_domain_full = onion_domain
                from_http_prefix = 'http'
                curr_session = session_onion
                port = 80
                to_port = 80
                curr_proxy_type = 'tor'
                session_type = 'tor'
        if i2p_domain:
            if not from_domain.endswith('.i2p') and \
               to_domain.endswith('.i2p'):
                from_domain = i2p_domain
                from_domain_full = i2p_domain
                from_http_prefix = 'http'
                curr_session = session_i2p
                port = 80
                to_port = 80
                curr_proxy_type = 'i2p'
                session_type = 'i2p'

        extra_headers = {}
        # followers synchronization header
        # https://codeberg.org/fediverse/fep/src/branch/main/feps/fep-8fcf.md
        sending_actor = \
            from_http_prefix + '://' + from_domain_full + '/users/' + nickname
        _, followers_sync_hash = \
            update_followers_sync_cache(base_dir,
                                        nickname, domain,
                                        http_prefix, domain_full,
                                        to_domain_full,
                                        followers_sync_cache)
        if followers_sync_hash:
            collection_sync_str = \
                'collectionId="' + sending_actor + '/followers", ' + \
                'url="' + sending_actor + '/followers_synchronization", ' + \
                'digest="' + followers_sync_hash + '"'
            extra_headers["Collection-Synchronization"] = collection_sync_str
            if debug:
                print('DEBUG: extra_headers, ' + str(extra_headers))

        if debug:
            to_domain_full = get_full_domain(to_domain, to_port)
            print('DEBUG: Post sending s2s: ' +
                  nickname + '@' + from_domain_full +
                  ' to ' + to_nickname + '@' + to_domain_full)

        # if the "to" domain is within the shared items
        # federation list then send the token for this domain
        # so that it can request a catalog
        shared_items_token = None
        if to_domain in shared_items_federated_domains:
            if shared_item_federation_tokens.get(from_domain_full):
                shared_items_token = \
                    shared_item_federation_tokens[from_domain_full]

        group_account = has_group_type(base_dir, address, person_cache)

        if not curr_session:
            curr_session = create_session(curr_proxy_type)
            if server:
                if session_type == 'tor':
                    server.session_onion = curr_session
                elif session_type == 'i2p':
                    server.session_i2p = curr_session
                else:
                    server.session = curr_session

        send_signed_json(post_json_object, curr_session, base_dir,
                         nickname, from_domain, port,
                         to_nickname, to_domain, to_port,
                         from_http_prefix, client_to_server,
                         federation_list,
                         send_threads, post_log, cached_webfingers,
                         person_cache, debug, project_version,
                         shared_items_token, group_account,
                         signing_priv_key_pem, 34436782,
                         domain, onion_domain, i2p_domain,
                         extra_headers, sites_unavailable,
                         system_language, mitm_servers)


def send_to_named_addresses_thread(server, session, session_onion, session_i2p,
                                   base_dir: str, nickname: str, domain: str,
                                   onion_domain: str,
                                   i2p_domain: str, port: int,
                                   http_prefix: str, federation_list: [],
                                   send_threads: [], post_log: [],
                                   cached_webfingers: {}, person_cache: {},
                                   post_json_object: {}, debug: bool,
                                   project_version: str,
                                   shared_items_federated_domains: [],
                                   shared_item_federation_tokens: {},
                                   signing_priv_key_pem: str,
                                   proxy_type: str,
                                   followers_sync_cache: {},
                                   sites_unavailable: [],
                                   system_language: str,
                                   mitm_servers: []):
    """Returns a thread used to send a post to named addresses
    """
    print('THREAD: _send_to_named_addresses')
    send_thread = \
        thread_with_trace(target=_send_to_named_addresses,
                          args=(server, session, session_onion, session_i2p,
                                base_dir, nickname, domain,
                                onion_domain, i2p_domain, port,
                                http_prefix, federation_list,
                                send_threads, post_log,
                                cached_webfingers, person_cache,
                                post_json_object, debug,
                                project_version,
                                shared_items_federated_domains,
                                shared_item_federation_tokens,
                                signing_priv_key_pem,
                                proxy_type,
                                followers_sync_cache,
                                sites_unavailable,
                                system_language,
                                mitm_servers), daemon=True)
    if not begin_thread(send_thread, 'send_to_named_addresses_thread'):
        print('WARN: socket error while starting ' +
              'thread to send to named addresses.')
        return None
    return send_thread


def _has_shared_inbox(session, http_prefix: str, domain: str,
                      debug: bool, signing_priv_key_pem: str,
                      ua_domain: str, mitm_servers: []) -> bool:
    """Returns true if the given domain has a shared inbox
    This tries the new and the old way of webfingering the shared inbox
    """
    try_handles: list[str] = []
    if ':' not in domain:
        try_handles.append(domain + '@' + domain)
    try_handles.append('inbox@' + domain)
    for handle in try_handles:
        wf_request = webfinger_handle(session, handle, http_prefix, {},
                                      ua_domain, __version__, debug, False,
                                      signing_priv_key_pem, mitm_servers)
        if wf_request:
            if isinstance(wf_request, dict):
                if not wf_request.get('errors'):
                    return True
    return False


def _sending_profile_update(post_json_object: {}) -> bool:
    """Returns true if the given json is a profile update
    """
    if post_json_object['type'] != 'Update':
        return False
    if not has_object_string_type(post_json_object, False):
        return False
    activity_type = post_json_object['object']['type']
    if activity_type in ('Person', 'Application', 'Group', 'Service'):
        return True
    return False


def send_to_followers(server, session, session_onion, session_i2p,
                      base_dir: str, nickname: str, domain: str,
                      onion_domain: str, i2p_domain: str, port: int,
                      http_prefix: str, federation_list: [],
                      send_threads: [], post_log: [],
                      cached_webfingers: {}, person_cache: {},
                      post_json_object: {}, debug: bool,
                      project_version: str,
                      shared_items_federated_domains: [],
                      shared_item_federation_tokens: {},
                      signing_priv_key_pem: str,
                      sites_unavailable: [],
                      system_language: str,
                      mitm_servers: []) -> None:
    """sends a post to the followers of the given nickname
    """
    print('send_to_followers')
    if not _post_is_addressed_to_followers(nickname, domain,
                                           port, http_prefix,
                                           post_json_object):
        if debug:
            print('Post is not addressed to followers')
        return
    print('Post is addressed to followers')

    extra_headers = {}
    grouped = group_followers_by_domain(base_dir, nickname, domain)
    if not grouped:
        if debug:
            print('Post to followers did not resolve any domains')
        return
    print('Post to followers resolved domains')
    # print(str(grouped))

    # this is after the message has arrived at the server
    client_to_server = False

    curr_proxy_type = None
    if domain.endswith('.onion'):
        curr_proxy_type = 'tor'
    elif domain.endswith('.i2p'):
        curr_proxy_type = 'i2p'

    sending_start_time = date_utcnow()
    print('Sending post to followers begins ' +
          sending_start_time.strftime("%Y-%m-%dT%H:%M:%SZ"))
    sending_ctr = 0

    # randomize the order of sending to instances
    randomized_instances: list[str] = []
    for follower_domain, follower_handles in grouped.items():
        randomized_instances.append([follower_domain, follower_handles])
    random.shuffle(randomized_instances)

    # send out to each instance
    for group_send in randomized_instances:
        follower_domain = group_send[0]
        follower_handles = group_send[1]
        print('Sending post to followers progress ' +
              str(int(sending_ctr * 100 / len(grouped.items()))) + '% ' +
              follower_domain)
        sending_ctr += 1

        if debug:
            pprint(follower_handles)

        # if the followers domain is within the shared items
        # federation list then send the token for this domain
        # so that it can request a catalog
        shared_items_token = None
        if follower_domain in shared_items_federated_domains:
            domain_full = get_full_domain(domain, port)
            if shared_item_federation_tokens.get(domain_full):
                shared_items_token = shared_item_federation_tokens[domain_full]

        # check that the follower's domain is active
        follower_domain_url = http_prefix + '://' + follower_domain
        if not site_is_active(follower_domain_url, 10, sites_unavailable):
            print('Sending post to followers domain is inactive: ' +
                  follower_domain_url)
            continue
        print('Sending post to followers domain is active: ' +
              follower_domain_url)

        # select the appropriate session
        curr_session = session
        curr_http_prefix = http_prefix
        if onion_domain:
            if follower_domain.endswith('.onion'):
                curr_session = session_onion
                curr_http_prefix = 'http'
        if i2p_domain:
            if follower_domain.endswith('.i2p'):
                curr_session = session_i2p
                curr_http_prefix = 'http'

        # get the domain showin by the user agent
        ua_domain = domain
        if follower_domain.endswith('.onion'):
            ua_domain = onion_domain
        elif follower_domain.endswith('.i2p'):
            ua_domain = i2p_domain

        with_shared_inbox = \
            _has_shared_inbox(curr_session, curr_http_prefix, follower_domain,
                              debug, signing_priv_key_pem, ua_domain,
                              mitm_servers)
        if debug:
            if with_shared_inbox:
                print(follower_domain + ' has shared inbox')
        if not with_shared_inbox:
            print('Sending post to followers, ' + follower_domain +
                  ' does not have a shared inbox')

        to_port = port
        index = 0
        to_domain = follower_handles[index].split('@')[1]
        if ':' in to_domain:
            to_port = get_port_from_domain(to_domain)
            to_domain = remove_domain_port(to_domain)

        # if we are sending to an onion domain and we
        # have an alt onion domain then use the alt
        from_domain = domain
        from_http_prefix = http_prefix
        session_type = 'default'
        if onion_domain:
            if to_domain.endswith('.onion'):
                from_domain = onion_domain
                from_http_prefix = 'http'
                port = 80
                to_port = 80
                curr_proxy_type = 'tor'
                session_type = 'tor'
        if i2p_domain:
            if to_domain.endswith('.i2p'):
                from_domain = i2p_domain
                from_http_prefix = 'http'
                port = 80
                to_port = 80
                curr_proxy_type = 'i2p'
                session_type = 'i2p'

        if not curr_session:
            curr_session = create_session(curr_proxy_type)
            if server:
                if session_type == 'tor':
                    server.session_onion = curr_session
                elif session_type == 'i2p':
                    server.session_i2p = curr_session
                else:
                    server.session = curr_session

        if with_shared_inbox:
            to_nickname = follower_handles[index].split('@')[0]

            group_account = False
            if to_nickname.startswith('!'):
                group_account = True
                to_nickname = to_nickname[1:]

            # if there are more than one followers on the domain
            # then send the post to the shared inbox
            if len(follower_handles) > 1:
                to_nickname = 'inbox'

            if to_nickname != 'inbox' and post_json_object.get('type'):
                if _sending_profile_update(post_json_object):
                    print('Sending post to followers ' +
                          'shared inbox of ' + to_domain)
                    to_nickname = 'inbox'

            print('Sending post to followers from ' +
                  nickname + '@' + domain +
                  ' to ' + to_nickname + '@' + to_domain)

            send_signed_json(post_json_object, curr_session, base_dir,
                             nickname, from_domain, port,
                             to_nickname, to_domain, to_port,
                             from_http_prefix,
                             client_to_server, federation_list,
                             send_threads, post_log, cached_webfingers,
                             person_cache, debug, project_version,
                             shared_items_token, group_account,
                             signing_priv_key_pem, 639342,
                             domain, onion_domain, i2p_domain,
                             extra_headers, sites_unavailable,
                             system_language, mitm_servers)
        else:
            # randomize the order of handles, so that we are not
            # favoring any particular account in terms of its delivery time
            random.shuffle(follower_handles)
            # send to individual followers without using a shared inbox
            for handle in follower_handles:
                print('Sending post to followers ' + handle)
                to_nickname = handle.split('@')[0]

                group_account = False
                if to_nickname.startswith('!'):
                    group_account = True
                    to_nickname = to_nickname[1:]

                if post_json_object['type'] != 'Update':
                    print('Sending post to followers from ' +
                          nickname + '@' + domain + ' to ' +
                          to_nickname + '@' + to_domain)
                else:
                    print('Sending post to followers profile update from ' +
                          nickname + '@' + domain + ' to ' +
                          to_nickname + '@' + to_domain)

                send_signed_json(post_json_object, curr_session, base_dir,
                                 nickname, from_domain, port,
                                 to_nickname, to_domain, to_port,
                                 from_http_prefix,
                                 client_to_server, federation_list,
                                 send_threads, post_log, cached_webfingers,
                                 person_cache, debug, project_version,
                                 shared_items_token, group_account,
                                 signing_priv_key_pem, 634219,
                                 domain, onion_domain, i2p_domain,
                                 extra_headers, sites_unavailable,
                                 system_language, mitm_servers)

        time.sleep(4)

    if debug:
        print('DEBUG: End of send_to_followers')

    sending_end_time = date_utcnow()
    sending_mins = \
        int((sending_end_time - sending_start_time).total_seconds() / 60)
    print('Sending post to followers ends ' + str(sending_mins) + ' mins')


def send_to_followers_thread(server, session, session_onion, session_i2p,
                             base_dir: str, nickname: str, domain: str,
                             onion_domain: str, i2p_domain: str, port: int,
                             http_prefix: str, federation_list: [],
                             send_threads: [], post_log: [],
                             cached_webfingers: {}, person_cache: {},
                             post_json_object: {}, debug: bool,
                             project_version: str,
                             shared_items_federated_domains: [],
                             shared_item_federation_tokens: {},
                             signing_priv_key_pem: str,
                             sites_unavailable: [],
                             system_language: str,
                             mitm_servers: []):
    """Returns a thread used to send a post to followers
    """
    print('THREAD: send_to_followers')
    send_thread = \
        thread_with_trace(target=send_to_followers,
                          args=(server, session, session_onion, session_i2p,
                                base_dir, nickname, domain,
                                onion_domain, i2p_domain, port,
                                http_prefix, federation_list,
                                send_threads, post_log,
                                cached_webfingers, person_cache,
                                post_json_object.copy(), debug,
                                project_version,
                                shared_items_federated_domains,
                                shared_item_federation_tokens,
                                signing_priv_key_pem,
                                sites_unavailable,
                                system_language,
                                mitm_servers), daemon=True)
    if not begin_thread(send_thread, 'send_to_followers_thread'):
        print('WARN: error while starting ' +
              'thread to send to followers.')
        return None
    return send_thread


def create_inbox(recent_posts_cache: {},
                 base_dir: str, nickname: str, domain: str, port: int,
                 http_prefix: str, items_per_page: int, header_only: bool,
                 page_number: int, first_post_id: str) -> {}:
    return _create_box_indexed(recent_posts_cache,
                               base_dir, 'inbox',
                               nickname, domain, port, http_prefix,
                               items_per_page, header_only, True,
                               0, False, 0, page_number, first_post_id)


def create_bookmarks_timeline(base_dir: str,
                              nickname: str, domain: str,
                              port: int, http_prefix: str, items_per_page: int,
                              header_only: bool, page_number: int) -> {}:
    return _create_box_indexed({}, base_dir, 'tlbookmarks',
                               nickname, domain,
                               port, http_prefix, items_per_page, header_only,
                               True, 0, False, 0, page_number, '')


def create_dm_timeline(recent_posts_cache: {},
                       base_dir: str, nickname: str, domain: str,
                       port: int, http_prefix: str, items_per_page: int,
                       header_only: bool, page_number: int,
                       first_post_id: str) -> {}:
    return _create_box_indexed(recent_posts_cache,
                               base_dir, 'dm', nickname,
                               domain, port, http_prefix, items_per_page,
                               header_only, True, 0, False, 0, page_number,
                               first_post_id)


def create_replies_timeline(recent_posts_cache: {},
                            base_dir: str, nickname: str, domain: str,
                            port: int, http_prefix: str, items_per_page: int,
                            header_only: bool, page_number: int,
                            first_post_id: str) -> {}:
    return _create_box_indexed(recent_posts_cache,
                               base_dir, 'tlreplies',
                               nickname, domain, port, http_prefix,
                               items_per_page, header_only, True,
                               0, False, 0, page_number, first_post_id)


def create_blogs_timeline(base_dir: str, nickname: str, domain: str,
                          port: int, http_prefix: str, items_per_page: int,
                          header_only: bool, page_number: int) -> {}:
    return _create_box_indexed({}, base_dir, 'tlblogs', nickname,
                               domain, port, http_prefix,
                               items_per_page, header_only, True,
                               0, False, 0, page_number, '')


def create_features_timeline(base_dir: str,
                             nickname: str, domain: str,
                             port: int, http_prefix: str, items_per_page: int,
                             header_only: bool, page_number: int) -> {}:
    return _create_box_indexed({}, base_dir, 'tlfeatures', nickname,
                               domain, port, http_prefix,
                               items_per_page, header_only, True,
                               0, False, 0, page_number, '')


def create_media_timeline(base_dir: str, nickname: str, domain: str,
                          port: int, http_prefix: str, items_per_page: int,
                          header_only: bool, page_number: int) -> {}:
    return _create_box_indexed({}, base_dir, 'tlmedia', nickname,
                               domain, port, http_prefix,
                               items_per_page, header_only, True,
                               0, False, 0, page_number, '')


def create_news_timeline(base_dir: str, domain: str,
                         port: int, http_prefix: str, items_per_page: int,
                         header_only: bool, newswire_votes_threshold: int,
                         positive_voting: bool, voting_time_mins: int,
                         page_number: int) -> {}:
    return _create_box_indexed({}, base_dir, 'outbox', 'news',
                               domain, port, http_prefix,
                               items_per_page, header_only, True,
                               newswire_votes_threshold, positive_voting,
                               voting_time_mins, page_number, '')


def create_outbox(base_dir: str, nickname: str, domain: str,
                  port: int, http_prefix: str,
                  items_per_page: int, header_only: bool, authorized: bool,
                  page_number: int) -> {}:
    return _create_box_indexed({}, base_dir, 'outbox',
                               nickname, domain, port, http_prefix,
                               items_per_page, header_only, authorized,
                               0, False, 0, page_number, '')


def create_moderation(base_dir: str, nickname: str, domain: str, port: int,
                      http_prefix: str, items_per_page: int, header_only: bool,
                      page_number: int) -> {}:
    """
    """
    box_dir = create_person_dir(nickname, domain, base_dir, 'inbox')
    boxname = 'moderation'

    domain = get_full_domain(domain, port)

    if not page_number:
        page_number = 1

    page_str = '?page=' + str(page_number)
    box_url = local_actor_url(http_prefix, nickname, domain) + '/' + boxname
    box_header = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'first': box_url + '?page=true',
        'id': box_url,
        'last': box_url + '?page=true',
        'totalItems': 0,
        'type': 'OrderedCollection'
    }
    box_items = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': box_url + page_str,
        'orderedItems': [
        ],
        'partOf': box_url,
        'type': 'OrderedCollectionPage'
    }

    if is_moderator(base_dir, nickname):
        moderation_index_file = data_dir(base_dir) + '/moderation.txt'
        if os.path.isfile(moderation_index_file):
            lines: list[str] = []
            try:
                with open(moderation_index_file, 'r',
                          encoding='utf-8') as fp_index:
                    lines = fp_index.readlines()
            except OSError:
                print('EX: create_moderation unable to read ' +
                      moderation_index_file)
            box_header['totalItems'] = len(lines)
            if header_only:
                return box_header

            page_lines: list[str] = []
            if len(lines) > 0:
                end_line_number = \
                    len(lines) - 1 - int(items_per_page * page_number)
                end_line_number = max(end_line_number, 0)
                start_line_number = \
                    len(lines) - 1 - int(items_per_page * (page_number - 1))
                start_line_number = max(start_line_number, 0)
                line_number = start_line_number
                while line_number >= end_line_number:
                    line_no_str = lines[line_number].strip('\n').strip('\r')
                    page_lines.append(line_no_str)
                    line_number -= 1

            for post_url in page_lines:
                post_filename = \
                    box_dir + '/' + post_url.replace('/', '#') + '.json'
                if os.path.isfile(post_filename):
                    post_json_object = load_json(post_filename)
                    if post_json_object:
                        box_items['orderedItems'].append(post_json_object)

    if header_only:
        return box_header
    return box_items


def is_image_media(session, base_dir: str, http_prefix: str,
                   nickname: str, domain: str,
                   post_json_object: {},
                   yt_replace_domain: str,
                   twitter_replacement_domain: str,
                   allow_local_network_access: bool,
                   recent_posts_cache: {}, debug: bool,
                   system_language: str,
                   domain_full: str, person_cache: {},
                   signing_priv_key_pem: str,
                   bold_reading: bool,
                   show_vote_posts: bool,
                   languages_understood: [],
                   mitm_servers: []) -> bool:
    """Returns true if the given post has attached image media
    """
    if post_json_object['type'] == 'Announce':
        blocked_cache = {}
        block_federated: list[str] = []
        post_json_announce = \
            download_announce(session, base_dir, http_prefix,
                              nickname, domain, post_json_object,
                              __version__,
                              yt_replace_domain,
                              twitter_replacement_domain,
                              allow_local_network_access,
                              recent_posts_cache, debug,
                              system_language,
                              domain_full, person_cache,
                              signing_priv_key_pem,
                              blocked_cache, block_federated,
                              bold_reading,
                              show_vote_posts,
                              languages_understood,
                              mitm_servers)
        if post_json_announce:
            post_json_object = post_json_announce
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if post_json_object['object'].get('moderationStatus'):
        return False
    if post_json_object['object']['type'] not in ('Note', 'Page', 'Event',
                                                  'ChatMessage', 'Article'):
        return False
    post_attachments = get_post_attachments(post_json_object)
    if not post_attachments:
        return False
    for attach in post_attachments:
        if attach.get('mediaType') and attach.get('url'):
            if attach['mediaType'].startswith('image/') or \
               attach['mediaType'].startswith('audio/') or \
               attach['mediaType'].startswith('video/'):
                return True
    return False


def _add_post_string_to_timeline(post_str: str, boxname: str,
                                 posts_in_box: [], box_actor: str) -> bool:
    """ is this a valid timeline post?
    """
    # must be a recognized ActivityPub type
    if (string_contains(post_str,
                        ('"Note"', '"EncryptedMessage"', '"ChatMessage"',
                         '"Event"', '"Article"', '"Patch"', '"Announce"')) or
        ('"Question"' in post_str and
         ('"Create"' in post_str or '"Update"' in post_str))):

        if boxname == 'dm':
            if '#Public' in post_str or \
               '/followers' in post_str:
                return False
        elif boxname == 'tlreplies':
            if box_actor not in post_str:
                return False
        elif boxname in ('tlblogs', 'tlnews', 'tlfeatures'):
            if '"Create"' not in post_str:
                return False
            if '"Article"' not in post_str:
                return False
        elif boxname == 'tlmedia':
            if '"Create"' in post_str:
                if ('mediaType' not in post_str or
                    ('image/' not in post_str and
                     'video/' not in post_str and
                     'audio/' not in post_str)):
                    return False
        # add the post to the dictionary
        posts_in_box.append(post_str)
        return True
    return False


def _add_post_to_timeline(file_path: str, boxname: str,
                          posts_in_box: [], box_actor: str) -> bool:
    """ Reads a post from file and decides whether it is valid
    """
    post_str = ''
    try:
        with open(file_path, 'r', encoding='utf-8') as fp_post:
            post_str = fp_post.read()
    except OSError:
        print('EX: _add_post_to_timeline unable to read ' + file_path)

    if not post_str:
        return False

    if file_path.endswith('.json'):
        replies_filename = file_path.replace('.json', '.replies')
        if os.path.isfile(replies_filename):
            # append a replies identifier, which will later be removed
            post_str += '<hasReplies>'

        mitm_filename = file_path.replace('.json', '.mitm')
        if os.path.isfile(mitm_filename):
            # append a mitm identifier, which will later be removed
            post_str += '<postmitm>'

    return _add_post_string_to_timeline(post_str, boxname,
                                        posts_in_box, box_actor)


def remove_post_interactions(post_json_object: {}, force: bool) -> bool:
    """ Don't show likes, replies, bookmarks, DMs or shares (announces) to
    unauthorized viewers. This makes the timeline less useful to
    marketers and other surveillance-oriented organizations.
    Returns False if this is a private post
    """
    has_object = False
    if has_object_dict(post_json_object):
        has_object = True
    if has_object:
        post_obj = post_json_object['object']
        if not force:
            # If not authorized and it's a private post
            # then just don't show it within timelines
            if not is_public_post(post_json_object):
                return False
    else:
        post_obj = post_json_object

    # clear the likes
    if post_obj.get('likes'):
        post_obj['likes'] = {
            'items': []
        }
    # clear the reactions
    if post_obj.get('reactions'):
        post_obj['reactions'] = {
            'items': []
        }
    # remove other collections
    remove_collections = (
        'replies', 'shares', 'bookmarks', 'ignores'
    )
    for remove_name in remove_collections:
        if post_obj.get(remove_name):
            post_obj[remove_name] = {}
    return True


def _passed_newswire_voting(newswire_votes_threshold: int,
                            base_dir: str, domain: str,
                            post_filename: str,
                            positive_voting: bool,
                            voting_time_mins: int) -> bool:
    """Returns true if the post has passed through newswire voting
    """
    # apply votes within this timeline
    if newswire_votes_threshold <= 0:
        return True
    # note that the presence of an arrival file also indicates
    # that this post is moderated
    arrival_date = \
        locate_news_arrival(base_dir, domain, post_filename)
    if not arrival_date:
        return True
    # how long has elapsed since this post arrived?
    curr_date = date_utcnow()
    time_diff_mins = \
        int((curr_date - arrival_date).total_seconds() / 60)
    # has the voting time elapsed?
    if time_diff_mins < voting_time_mins:
        # voting is still happening, so don't add this
        # post to the timeline
        return False
    # if there a votes file for this post?
    votes_filename = \
        locate_news_votes(base_dir, domain, post_filename)
    if not votes_filename:
        return True
    # load the votes file and count the votes
    votes_json = load_json(votes_filename)
    if not votes_json:
        return True
    if not positive_voting:
        if votes_on_newswire_item(votes_json) >= \
           newswire_votes_threshold:
            # Too many veto votes.
            # Continue without incrementing
            # the posts counter
            return False
    else:
        if votes_on_newswire_item < \
           newswire_votes_threshold:
            # Not enough votes.
            # Continue without incrementing
            # the posts counter
            return False
    return True


def _create_box_items(base_dir: str,
                      timeline_nickname: str,
                      original_domain: str,
                      nickname: str, domain: str,
                      index_box_name: str,
                      first_post_id: str,
                      page_number: int,
                      items_per_page: int,
                      newswire_votes_threshold: int,
                      positive_voting: bool,
                      voting_time_mins: int,
                      post_urls_in_box: [],
                      recent_posts_cache: {},
                      boxname: str,
                      posts_in_box: [],
                      box_actor: str) -> (int, int):
    """Creates the list of posts within a timeline
    """
    index_filename = \
        acct_dir(base_dir, timeline_nickname, original_domain) + \
        '/' + index_box_name + '.index'
    total_posts_count = 0
    posts_added_to_timeline = 0
    if not os.path.isfile(index_filename):
        return total_posts_count, posts_added_to_timeline

    # format the first post into an hashed url
    # Why are url's hashed? Since storage is in the filesystem this avoids
    # confusion with directories by not using the / character
    if first_post_id:
        replacements = {
            '--': '#',
            '/': '#'
        }
        first_post_id = replace_strings(first_post_id, replacements)

    try:
        with open(index_filename, 'r', encoding='utf-8') as fp_index:
            posts_added_to_timeline = 0
            while posts_added_to_timeline < items_per_page:
                post_filename = fp_index.readline()

                if not post_filename:
                    break

                # if a first post is specified then wait until it is found
                # before starting to generate the timeline
                if first_post_id and total_posts_count == 0:
                    if first_post_id not in post_filename:
                        continue
                    total_posts_count = \
                        int((page_number - 1) * items_per_page)

                # Has this post passed through the newswire voting stage?
                if not _passed_newswire_voting(newswire_votes_threshold,
                                               base_dir, domain,
                                               post_filename,
                                               positive_voting,
                                               voting_time_mins):
                    continue

                # Skip through any posts previous to the current page
                if not first_post_id:
                    if total_posts_count < \
                       int((page_number - 1) * items_per_page):
                        total_posts_count += 1
                        continue

                # if this is a full path then remove the directories
                if '/' in post_filename:
                    post_filename = post_filename.split('/')[-1]

                # filename of the post without any extension or path
                # This should also correspond to any index entry in
                # the posts cache
                post_url = remove_eol(post_filename)
                post_url = post_url.replace('.json', '').strip()

                # is this a duplicate?
                if post_url in post_urls_in_box:
                    print('REJECT: Duplicate in timeline ' +
                          boxname + ' ' + post_url)
                    continue

                # is the post cached in memory?
                if recent_posts_cache.get('index'):
                    if post_url in recent_posts_cache['index']:
                        if recent_posts_cache['json'].get(post_url):
                            url = recent_posts_cache['json'][post_url]
                            if _add_post_string_to_timeline(url,
                                                            boxname,
                                                            posts_in_box,
                                                            box_actor):
                                total_posts_count += 1
                                posts_added_to_timeline += 1
                                post_urls_in_box.append(post_url)
                                continue
                            print('REJECT: Post not added to timeline ' +
                                  post_url)

                # read the post from file
                full_post_filename = \
                    locate_post(base_dir, nickname,
                                original_domain, post_url, False)
                if full_post_filename:
                    # has the post been rejected?
                    if os.path.isfile(full_post_filename + '.reject'):
                        post_url2 = post_url.replace('/', '#') + '.json'
                        remove_post_from_index(post_url2, False,
                                               index_filename)
                        print('REJECT: rejected post in timeline ' +
                              boxname + ' ' + post_url2 + ' ' +
                              full_post_filename)
                        continue

                    if _add_post_to_timeline(full_post_filename, boxname,
                                             posts_in_box, box_actor):
                        posts_added_to_timeline += 1
                        total_posts_count += 1
                        post_urls_in_box.append(post_url)
                    else:
                        print('REJECT: Unable to add post ' + post_url +
                              ' nickname ' + nickname +
                              ' timeline ' + boxname)
                else:
                    if timeline_nickname != nickname:
                        # if this is the features timeline
                        full_post_filename = \
                            locate_post(base_dir, timeline_nickname,
                                        original_domain, post_url, False)
                        if full_post_filename:
                            if _add_post_to_timeline(full_post_filename,
                                                     boxname,
                                                     posts_in_box, box_actor):
                                posts_added_to_timeline += 1
                                total_posts_count += 1
                                post_urls_in_box.append(post_url)
                            else:
                                print('REJECT: Unable to add features post ' +
                                      post_url + ' nickname ' + nickname +
                                      ' timeline ' + boxname)
                        else:
                            print('REJECT: features timeline. ' +
                                  'Unable to locate post ' + post_url)
                    else:
                        if timeline_nickname == 'news':
                            print('REJECT: Unable to locate news post ' +
                                  post_url + ' nickname ' + nickname)
                        else:
                            print('REJECT: Unable to locate post ' + post_url +
                                  ' nickname ' + nickname)
    except OSError as exc:
        print('EX: _create_box_items unable to read ' + index_filename +
              ' ' + str(exc))
    return total_posts_count, posts_added_to_timeline


def _create_box_indexed(recent_posts_cache: {},
                        base_dir: str, boxname: str,
                        nickname: str, domain: str, port: int,
                        http_prefix: str,
                        items_per_page: int, header_only: bool,
                        authorized: bool,
                        newswire_votes_threshold: int, positive_voting: bool,
                        voting_time_mins: int, page_number: int,
                        first_post_id: str) -> {}:
    """Constructs the box feed for a person with the given nickname
    """
    if not authorized or not page_number:
        page_number = 1

    if boxname not in ('inbox', 'dm', 'tlreplies', 'tlmedia',
                       'tlblogs', 'tlnews', 'tlfeatures', 'outbox',
                       'tlbookmarks', 'bookmarks'):
        print('ERROR: invalid boxname ' + boxname)
        return None

    unauthorized_premium = False
    if not authorized and boxname == 'outbox':
        unauthorized_premium = is_premium_account(base_dir, nickname, domain)

    # bookmarks and events timelines are like the inbox
    # but have their own separate index
    index_box_name = boxname
    timeline_nickname = nickname
    if boxname == "tlbookmarks":
        boxname = "bookmarks"
        index_box_name = boxname
    elif boxname == "tlfeatures":
        boxname = "tlblogs"
        index_box_name = boxname
        timeline_nickname = 'news'

    original_domain = domain
    domain = get_full_domain(domain, port)

    box_actor = local_actor_url(http_prefix, nickname, domain)

    page_str = '?page=true'
    if page_number:
        page_number = max(page_number, 1)
        try:
            page_str = '?page=' + str(page_number)
        except BaseException:
            print('EX: _create_box_indexed ' +
                  'unable to convert page number to string')
    box_url = local_actor_url(http_prefix, nickname, domain) + '/' + boxname
    box_header = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'first': box_url + '?page=true',
        'id': box_url,
        'last': box_url + '?page=true',
        'totalItems': 0,
        'type': 'OrderedCollection'
    }
    box_items = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': box_url + page_str,
        'orderedItems': [
        ],
        'partOf': box_url,
        'type': 'OrderedCollectionPage'
    }

    posts_in_box = []
    post_urls_in_box: list[str] = []

    if not unauthorized_premium:
        total_posts_count, posts_added_to_timeline = \
            _create_box_items(base_dir,
                              timeline_nickname,
                              original_domain,
                              nickname, domain,
                              index_box_name,
                              first_post_id,
                              page_number,
                              items_per_page,
                              newswire_votes_threshold,
                              positive_voting,
                              voting_time_mins,
                              post_urls_in_box,
                              recent_posts_cache,
                              boxname,
                              posts_in_box,
                              box_actor)
    else:
        # unauthorized requests for premium posts should return an empty set
        total_posts_count = posts_added_to_timeline = 0

    if first_post_id and \
       posts_added_to_timeline == 0 and \
       not unauthorized_premium:
        # no first post was found within the index, so just use the page number
        first_post_id = ''
        total_posts_count, posts_added_to_timeline = \
            _create_box_items(base_dir,
                              timeline_nickname,
                              original_domain,
                              nickname, domain,
                              index_box_name,
                              None,
                              page_number,
                              items_per_page,
                              newswire_votes_threshold,
                              positive_voting,
                              voting_time_mins,
                              post_urls_in_box,
                              recent_posts_cache,
                              boxname,
                              posts_in_box,
                              box_actor)

    if total_posts_count < 3:
        print('Posts added to json timeline ' + boxname + ': ' +
              str(posts_added_to_timeline))

    # Generate first and last entries within header
    if total_posts_count > 0:
        last_page = int(total_posts_count / items_per_page)
        last_page = max(last_page, 1)
        box_header['last'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/' + boxname + '?page=' + str(last_page)

    if header_only:
        box_header['totalItems'] = len(posts_in_box)
        prev_page_str = 'true'
        if page_number > 1:
            prev_page_str = str(page_number - 1)
        box_header['prev'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/' + boxname + '?page=' + prev_page_str

        next_page_str = str(page_number + 1)
        box_header['next'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/' + boxname + '?page=' + next_page_str
        return box_header

    for post_str in posts_in_box:
        # Check if the post has replies
        has_replies = False
        if post_str.endswith('<hasReplies>'):
            has_replies = True
            # remove the replies identifier
            post_str = post_str.replace('<hasReplies>', '')

        # Check if the post was delivered via a third party
        mitm = False
        if post_str.endswith('<postmitm>'):
            mitm = True
            # remove the mitm identifier
            post_str = post_str.replace('<postmitm>', '')

        pst = None
        try:
            pst = json.loads(post_str)
        except BaseException:
            print('EX: _create_box_indexed unable to load json ' + post_str)
            continue

        # Does this post have replies?
        # This will be used to indicate that replies exist within the html
        # created by individual_post_as_html
        pst['hasReplies'] = has_replies

        # was the post delivered via a third party?
        pst['mitm'] = mitm

        if not authorized:
            if not remove_post_interactions(pst, False):
                continue

        box_items['orderedItems'].append(pst)

    return box_items


def expire_cache(base_dir: str, person_cache: {},
                 http_prefix: str, archive_dir: str,
                 recent_posts_cache: {},
                 max_posts_in_box: int,
                 max_cache_age_days: int):
    """Thread used to expire actors from the cache and archive old posts
    """
    while True:
        # once per day
        time.sleep(60 * 60 * 24)
        expire_person_cache(person_cache)
        archive_posts(base_dir, http_prefix, archive_dir, recent_posts_cache,
                      max_posts_in_box, max_cache_age_days)


def _expire_announce_cache_for_person(base_dir: str,
                                      nickname: str, domain: str,
                                      max_age_days: int) -> int:
    """Expires entries within the announces cache
    """
    cache_dir = base_dir + '/cache/announce/' + nickname
    if not os.path.isdir(cache_dir):
        print('No cached announces for ' + nickname + '@' + domain)
        return 0
    expired_post_count = 0
    posts_in_cache = os.scandir(cache_dir)
    for cache_filename in posts_in_cache:
        cache_filename = cache_filename.name
        # Time of file creation
        full_filename = os.path.join(cache_dir, cache_filename)
        if not os.path.isfile(full_filename):
            continue
        last_modified = file_last_modified(full_filename)
        # get time difference
        if not valid_post_date(last_modified, max_age_days, False):
            try:
                os.remove(full_filename)
            except OSError:
                print('EX: unable to delete from announce cache ' +
                      full_filename)
            expired_post_count += 1
    return expired_post_count


def _expire_conversations_for_person(base_dir: str,
                                     nickname: str, domain: str,
                                     max_age_days: int) -> int:
    """Expires entries within the conversation directory
    """
    conv_dir = acct_dir(base_dir, nickname, domain) + '/conversation'
    if not os.path.isdir(conv_dir):
        print('No conversations for ' + nickname + '@' + domain)
        return 0
    expired_post_count = 0
    posts_in_conv = os.scandir(conv_dir)
    for conv_filename in posts_in_conv:
        conv_filename = conv_filename.name
        if conv_filename.endswith('.muted'):
            # don't expire muted conversations, so they stay muted
            continue
        # Time of file creation
        full_filename = os.path.join(conv_dir, conv_filename)
        if not os.path.isfile(full_filename):
            continue
        last_modified = file_last_modified(full_filename)
        # get time difference
        if not valid_post_date(last_modified, max_age_days, False):
            try:
                os.remove(full_filename)
            except OSError:
                print('EX: unable to delete from conversations ' +
                      full_filename)
            expired_post_count += 1
    return expired_post_count


def _expire_posts_cache_for_person(base_dir: str,
                                   nickname: str, domain: str,
                                   max_age_days: int) -> int:
    """Expires entries within the posts cache
    """
    cache_dir = acct_dir(base_dir, nickname, domain) + '/postcache'
    if not os.path.isdir(cache_dir):
        print('No cached posts for ' + nickname + '@' + domain)
        return 0
    expired_post_count = 0
    posts_in_cache = os.scandir(cache_dir)
    for cache_filename in posts_in_cache:
        cache_filename = cache_filename.name
        # Time of file creation
        full_filename = os.path.join(cache_dir, cache_filename)
        if not os.path.isfile(full_filename):
            continue
        last_modified = file_last_modified(full_filename)
        # get time difference
        if not valid_post_date(last_modified, max_age_days, False):
            try:
                os.remove(full_filename)
            except OSError:
                print('EX: unable to delete from post cache ' +
                      full_filename)
            expired_post_count += 1
    return expired_post_count


def _novel_fields_for_person(nickname: str, domain: str,
                             base_dir: str, boxname: str) -> None:
    """Check posts for a person to report any novel fields
    """
    if boxname not in ('inbox'):
        return
    box_dir = create_person_dir(nickname, domain, base_dir, boxname)
    posts_in_box = os.scandir(box_dir)

    posts_ctr = 0
    fields: list[str] = []
    expected_fields = (
        'alsoKnownAs',
        'attachment',
        'capabilities',
        'discoverable',
        'endpoints',
        'featured',
        'followers',
        'following',
        'location',
        '@context',
        'icon',
        'id',
        'image',
        'inbox',
        'manuallyApprovesFollowers',
        'name',
        'outbox',
        'preferredUsername',
        'publicKey',
        'summary',
        'tag',
        'type',
        'url',
        'inReplyTo',
        'published',
        'attributedTo',
        'to', 'cc',
        'sensitive',
        'atomUri',
        'inReplyToAtomUri',
        'conversation',
        'content',
        'contentMap',
        'replies',
        'localOnly',
        'shares',
        'actor',
        'bcc',
        'bto',
        'context',
        'object',
        'source',
        'directMessage',
        'updated',
        'likes',
        'ignores',
        'muted',
        'endTime',
        'votersCount',
        'oneOf',
        'bookmarks',
        'formerRepresentations',
        'anyOf',
        'commentsEnabled',
        'rejectReplies',
        'mediaType',
        'crawlable',
        'schema:license',
        'license',
        'summaryMap',
        'closed',
        'secGPC',
        'xRobotsTag',
        'content_is_html',
        'repliesCount',
        'thread', 'root',
        'searchableBy',
        'interactionPolicy',
        'canQuote',
        'automaticApproval'
    )
    for post_filename in posts_in_box:
        post_filename = post_filename.name
        if not post_filename.endswith('.json'):
            continue
        full_filename = os.path.join(box_dir, post_filename)
        if not os.path.isfile(full_filename):
            continue
        post_json_object = load_json(full_filename)
        if not post_json_object:
            continue
        if not has_object_dict(post_json_object):
            continue
        for fieldname, _ in post_json_object['object'].items():
            if fieldname in expected_fields:
                continue
            if fieldname not in fields:
                fields.append(fieldname)
                print(fieldname + ' ' + full_filename)
        posts_ctr += 1

    if posts_ctr > 0:
        print('Checked ' + str(posts_ctr) + ' ' + boxname +
              ' posts for ' + nickname + '@' + domain)


def novel_fields(base_dir: str) -> None:
    """Reports any unexpected fields within posts
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if '@' in handle:
                nickname = handle.split('@')[0]
                domain = handle.split('@')[1]
                _novel_fields_for_person(nickname, domain, base_dir,
                                         'inbox')
        break


def archive_posts(base_dir: str, http_prefix: str, archive_dir: str,
                  recent_posts_cache: {},
                  max_posts_in_box: int,
                  max_cache_age_days: int) -> None:
    """Archives posts for all accounts
    """
    if max_posts_in_box == 0:
        return

    if archive_dir:
        if not os.path.isdir(archive_dir):
            os.mkdir(archive_dir)

    if archive_dir:
        if not os.path.isdir(archive_dir + '/accounts'):
            os.mkdir(archive_dir + '/accounts')

    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if '@' in handle:
                nickname = handle.split('@')[0]
                domain = handle.split('@')[1]
                archive_subdir = None
                if archive_dir:
                    archive_handle_dir = acct_handle_dir(archive_dir, handle)
                    if not os.path.isdir(archive_handle_dir):
                        os.mkdir(archive_handle_dir)
                    if not os.path.isdir(archive_handle_dir + '/inbox'):
                        os.mkdir(archive_handle_dir + '/inbox')
                    if not os.path.isdir(archive_handle_dir + '/outbox'):
                        os.mkdir(archive_handle_dir + '/outbox')
                    archive_subdir = archive_handle_dir + '/inbox'
                archive_posts_for_person(http_prefix,
                                         nickname, domain, base_dir,
                                         'inbox', archive_subdir,
                                         recent_posts_cache, max_posts_in_box)
                expired_announces = \
                    _expire_announce_cache_for_person(base_dir,
                                                      nickname, domain,
                                                      max_cache_age_days)
                print('Expired ' + str(expired_announces) +
                      ' cached announces for ' + nickname + '@' + domain)

                expired_conversations = \
                    _expire_conversations_for_person(base_dir,
                                                     nickname, domain,
                                                     max_cache_age_days)
                print('Expired ' + str(expired_conversations) +
                      ' conversations for ' + nickname + '@' + domain)

                expired_posts = \
                    _expire_posts_cache_for_person(base_dir,
                                                   nickname, domain,
                                                   max_cache_age_days)
                print('Expired ' + str(expired_posts) +
                      ' cached posts for ' + nickname + '@' + domain)
                if archive_dir:
                    archive_subdir = archive_dir + '/accounts/' + \
                        handle + '/outbox'
                archive_posts_for_person(http_prefix,
                                         nickname, domain, base_dir,
                                         'outbox', archive_subdir,
                                         recent_posts_cache, max_posts_in_box)
        break


def _expire_posts_for_person(http_prefix: str, nickname: str, domain: str,
                             base_dir: str, recent_posts_cache: {},
                             max_age_days: int, debug: bool,
                             keep_dms: bool) -> int:
    """Removes posts older than some number of days
    """
    expired_post_count = 0
    if max_age_days <= 0:
        return expired_post_count

    boxname = 'outbox'
    box_dir = create_person_dir(nickname, domain, base_dir, boxname)

    posts_in_box = os.scandir(box_dir)
    for post_filename in posts_in_box:
        post_filename = post_filename.name
        if not post_filename.endswith('.json'):
            continue
        # Time of file creation
        full_filename = os.path.join(box_dir, post_filename)
        if not os.path.isfile(full_filename):
            continue
        content = ''
        try:
            with open(full_filename, 'r', encoding='utf-8') as fp_content:
                content = fp_content.read()
        except OSError:
            print('EX: expire_posts_for_person unable to open content ' +
                  full_filename)
        if '"published":' not in content:
            continue
        published_str = content.split('"published":')[1]
        if '"' not in published_str:
            continue
        published_str = published_str.split('"')[1]
        if not published_str.endswith('Z'):
            continue
        # get time difference
        if not valid_post_date(published_str, max_age_days, debug):
            if keep_dms:
                post_json_object = load_json(full_filename)
                if not post_json_object:
                    continue
                if is_dm(post_json_object):
                    continue
            delete_post(base_dir, http_prefix, nickname, domain,
                        full_filename, debug, recent_posts_cache, True)
            expired_post_count += 1

    return expired_post_count


def get_post_expiry_keep_dms(base_dir: str, nickname: str, domain: str) -> int:
    """Returns true if dms should expire
    """
    keep_dms = True
    handle = nickname + '@' + domain
    expire_dms_filename = \
        acct_handle_dir(base_dir, handle) + '/.expire_posts_dms'
    if os.path.isfile(expire_dms_filename):
        keep_dms = False
    return keep_dms


def set_post_expiry_keep_dms(base_dir: str, nickname: str, domain: str,
                             keep_dms: bool) -> None:
    """Sets whether to keep DMs during post expiry for an account
    """
    handle = nickname + '@' + domain
    expire_dms_filename = \
        acct_handle_dir(base_dir, handle) + '/.expire_posts_dms'
    if keep_dms:
        if os.path.isfile(expire_dms_filename):
            try:
                os.remove(expire_dms_filename)
            except OSError:
                print('EX: unable to write set_post_expiry_keep_dms False ' +
                      expire_dms_filename)
        return
    try:
        with open(expire_dms_filename, 'w+', encoding='utf-8') as fp_expire:
            fp_expire.write('\n')
    except OSError:
        print('EX: unable to write set_post_expiry_keep_dms True ' +
              expire_dms_filename)


def expire_posts(base_dir: str, http_prefix: str,
                 recent_posts_cache: {}, debug: bool) -> int:
    """Expires posts for instance accounts
    """
    expired_post_count = 0
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if '@' not in handle:
                continue
            nickname = handle.split('@')[0]
            domain = handle.split('@')[1]
            expire_posts_filename = \
                acct_handle_dir(base_dir, handle) + '/.expire_posts_days'
            if not os.path.isfile(expire_posts_filename):
                continue
            keep_dms = get_post_expiry_keep_dms(base_dir, nickname, domain)
            expire_days_str = None
            try:
                with open(expire_posts_filename, 'r',
                          encoding='utf-8') as fp_expire:
                    expire_days_str = fp_expire.read()
            except OSError:
                print('EX: expire_posts failed to read days file ' +
                      expire_posts_filename)
                continue
            if not expire_days_str:
                continue
            if not expire_days_str.isdigit():
                continue
            max_age_days = int(expire_days_str)
            if max_age_days <= 0:
                continue
            expired_post_count += \
                _expire_posts_for_person(http_prefix,
                                         nickname, domain, base_dir,
                                         recent_posts_cache,
                                         max_age_days, debug,
                                         keep_dms)
        break
    return expired_post_count


def get_post_expiry_days(base_dir: str, nickname: str, domain: str) -> int:
    """Returns the post expiry period for the given account
    """
    handle = nickname + '@' + domain
    expire_posts_filename = \
        acct_handle_dir(base_dir, handle) + '/.expire_posts_days'
    if not os.path.isfile(expire_posts_filename):
        return 0
    days_str = None
    try:
        with open(expire_posts_filename, 'r', encoding='utf-8') as fp_expire:
            days_str = fp_expire.read()
    except OSError:
        print('EX: unable to write post expire days ' +
              expire_posts_filename)
    if not days_str:
        return 0
    if not days_str.isdigit():
        return 0
    return int(days_str)


def set_post_expiry_days(base_dir: str, nickname: str, domain: str,
                         max_age_days: int) -> None:
    """Sets the number of days after which posts from an account will expire
    """
    handle = nickname + '@' + domain
    expire_posts_filename = \
        acct_handle_dir(base_dir, handle) + '/.expire_posts_days'
    try:
        with open(expire_posts_filename, 'w+', encoding='utf-8') as fp_expire:
            fp_expire.write(str(max_age_days))
    except OSError:
        print('EX: unable to write post expire days ' +
              expire_posts_filename)


def archive_posts_for_person(http_prefix: str, nickname: str, domain: str,
                             base_dir: str,
                             boxname: str, archive_dir: str,
                             recent_posts_cache: {},
                             max_posts_in_box=32000) -> None:
    """Retain a maximum number of posts within the given box
    Move any others to an archive directory
    """
    if boxname not in ('inbox', 'outbox'):
        return
    if archive_dir:
        if not os.path.isdir(archive_dir):
            os.mkdir(archive_dir)
    box_dir = create_person_dir(nickname, domain, base_dir, boxname)
    posts_in_box = os.scandir(box_dir)
    no_of_posts = 0
    for _ in posts_in_box:
        no_of_posts += 1
    if no_of_posts <= max_posts_in_box:
        print('Checked ' + str(no_of_posts) + ' ' + boxname +
              ' posts for ' + nickname + '@' + domain)
        return

    # remove entries from the index
    handle = nickname + '@' + domain
    index_filename = \
        acct_handle_dir(base_dir, handle) + '/' + boxname + '.index'
    if os.path.isfile(index_filename):
        index_ctr = 0
        # get the existing index entries as a string
        new_index = ''
        try:
            with open(index_filename, 'r', encoding='utf-8') as fp_index:
                for post_id in fp_index:
                    new_index += post_id
                    index_ctr += 1
                    if index_ctr >= max_posts_in_box:
                        break
        except OSError as ex:
            print('EX: archive_posts_for_person unable to read ' +
                  index_filename + ' ' + str(ex))
        # save the new index file
        if len(new_index) > 0:
            try:
                with open(index_filename, 'w+',
                          encoding='utf-8') as fp_index:
                    fp_index.write(new_index)
            except OSError:
                print('EX: archive_posts_for_person unable to write ' +
                      index_filename)

    posts_in_box_dict = {}
    posts_ctr = 0
    posts_in_box = os.scandir(box_dir)
    for post_filename in posts_in_box:
        post_filename = post_filename.name
        if not post_filename.endswith('.json'):
            continue
        # Time of file creation
        full_filename = os.path.join(box_dir, post_filename)
        if os.path.isfile(full_filename):
            content = ''
            try:
                with open(full_filename, 'r', encoding='utf-8') as fp_content:
                    content = fp_content.read()
            except OSError:
                print('EX: unable to open content ' + full_filename)
            if '"published":' in content:
                published_str = content.split('"published":')[1]
                if '"' in published_str:
                    published_str = published_str.split('"')[1]
                    if published_str.endswith('Z'):
                        posts_in_box_dict[published_str] = post_filename
                        posts_ctr += 1

    no_of_posts = posts_ctr
    if no_of_posts <= max_posts_in_box:
        print('Checked ' + str(no_of_posts) + ' ' + boxname +
              ' posts for ' + nickname + '@' + domain)
        return

    # sort the list in ascending order of date
    posts_in_box_sorted = \
        OrderedDict(sorted(posts_in_box_dict.items(), reverse=False))

    # directory containing cached html posts
    post_cache_dir = box_dir.replace('/' + boxname, '/postcache')

    remove_ctr = 0
    for published_str, post_filename in posts_in_box_sorted.items():
        file_path = os.path.join(box_dir, post_filename)
        if not os.path.isfile(file_path):
            continue
        if archive_dir:
            archive_path = os.path.join(archive_dir, post_filename)
            os.rename(file_path, archive_path)

            extensions = ('replies', 'votes', 'arrived', 'muted')
            for ext in extensions:
                ext_path = file_path.replace('.json', '.' + ext)
                if os.path.isfile(ext_path):
                    os.rename(ext_path,
                              archive_path.replace('.json', '.' + ext))
                else:
                    ext_path = file_path.replace('.json',
                                                 '.json.' + ext)
                    if os.path.isfile(ext_path):
                        os.rename(ext_path,
                                  archive_path.replace('.json',
                                                       '.json.' + ext))
        else:
            delete_post(base_dir, http_prefix, nickname, domain,
                        file_path, False, recent_posts_cache, False)

        # remove cached html posts
        post_cache_filename = \
            os.path.join(post_cache_dir, post_filename)
        post_cache_filename = post_cache_filename.replace('.json', '.html')
        if os.path.isfile(post_cache_filename):
            try:
                os.remove(post_cache_filename)
            except OSError:
                print('EX: archive_posts_for_person unable to delete ' +
                      post_cache_filename)

        no_of_posts -= 1
        remove_ctr += 1
        if no_of_posts <= max_posts_in_box:
            break
    if archive_dir:
        print('Archived ' + str(remove_ctr) + ' ' + boxname +
              ' posts for ' + nickname + '@' + domain)
    else:
        print('Removed ' + str(remove_ctr) + ' ' + boxname +
              ' posts for ' + nickname + '@' + domain)
    print(nickname + '@' + domain + ' has ' + str(no_of_posts) +
          ' in ' + boxname)


def get_public_posts_of_person(base_dir: str, nickname: str, domain: str,
                               raw: bool, simple: bool, proxy_type: str,
                               port: int, http_prefix: str,
                               debug: bool, project_version: str,
                               system_language: str,
                               signing_priv_key_pem: str,
                               origin_domain: str,
                               mitm_servers: []) -> None:
    """ This is really just for test purposes
    """
    if debug:
        if signing_priv_key_pem:
            print('Signing key available')
        else:
            print('Signing key missing')

    if debug:
        print('Starting new session for getting public posts')
    session = create_session(proxy_type)
    if not session:
        if debug:
            print('Session was not created')
        return
    person_cache = {}
    cached_webfingers = {}
    federation_list: list[str] = []
    group_account = False
    if nickname.startswith('!'):
        nickname = nickname[1:]
        group_account = True
    domain_full = get_full_domain(domain, port)
    handle = http_prefix + "://" + domain_full + "/@" + nickname

    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         origin_domain, project_version, debug, group_account,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('No webfinger result was returned for ' + handle)
        sys.exit()
    if not isinstance(wf_request, dict):
        print('Webfinger for ' + handle + ' did not return a dict. ' +
              str(wf_request))
        sys.exit()

    if debug:
        print('\nGetting the outbox for ' + handle)
    (person_url, _, _, person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            nickname, domain, 'outbox',
                            62524, system_language,
                            mitm_servers)
    if debug:
        print('Actor url: ' + str(person_id))
    if not person_id:
        return

    max_mentions = 10
    max_emoji = 10
    max_attachments = 5
    _get_posts(session, person_url, 30, max_mentions, max_emoji,
               max_attachments, federation_list, raw, simple, debug,
               project_version, http_prefix, origin_domain, system_language,
               signing_priv_key_pem, mitm_servers)


def get_public_post_domains(session, base_dir: str, nickname: str, domain: str,
                            origin_domain: str,
                            proxy_type: str, port: int, http_prefix: str,
                            debug: bool, project_version: str,
                            word_frequency: {}, domain_list: [],
                            system_language: str,
                            signing_priv_key_pem: str,
                            mitm_servers: []) -> []:
    """ Returns a list of domains referenced within public posts
    """
    if not session:
        session = create_session(proxy_type)
    if not session:
        return domain_list
    person_cache = {}
    cached_webfingers = {}

    domain_full = get_full_domain(domain, port)
    handle = http_prefix + "://" + domain_full + "/@" + nickname
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        return domain_list
    if not isinstance(wf_request, dict):
        print('Webfinger for ' + handle + ' did not return a dict. ' +
              str(wf_request))
        return domain_list

    (person_url, _, _, _, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            nickname, domain, 'outbox',
                            92522, system_language,
                            mitm_servers)
    post_domains = \
        get_post_domains(session, person_url, 64, debug,
                         project_version, http_prefix, domain,
                         word_frequency, domain_list, system_language,
                         signing_priv_key_pem, mitm_servers)
    post_domains.sort()
    return post_domains


def download_follow_collection(signing_priv_key_pem: str,
                               follow_type: str,
                               session, http_prefix: str,
                               actor: str, page_number: int,
                               no_of_pages: int, debug: bool,
                               mitm_servers: []) -> []:
    """Returns a list of following/followers for the given actor
    by downloading the json for their following/followers collection
    """
    prof = 'https://www.w3.org/ns/activitystreams'
    if '/channel/' not in actor or '/accounts/' not in actor:
        accept_str = \
            'application/activity+json; ' + \
            'profile="' + prof + '"'
        session_headers = {
            'Accept': accept_str
        }
    else:
        accept_str = \
            'application/ld+json; ' + \
            'profile="' + prof + '"'
        session_headers = {
            'Accept': accept_str
        }
    result: list[str] = []
    for page_ctr in range(no_of_pages):
        url = \
            actor + '/' + follow_type + '?page=' + str(page_number + page_ctr)
        followers_json = \
            get_json(signing_priv_key_pem, session, url, session_headers, None,
                     debug, mitm_servers, __version__, http_prefix, None)
        if get_json_valid(followers_json):
            if followers_json.get('orderedItems'):
                for follower_actor in followers_json['orderedItems']:
                    if follower_actor not in result:
                        result.append(follower_actor)
            elif followers_json.get('items'):
                for follower_actor in followers_json['items']:
                    if follower_actor not in result:
                        result.append(follower_actor)
            else:
                break
        else:
            break
    return result


def get_public_post_info(session, base_dir: str, nickname: str, domain: str,
                         origin_domain: str,
                         proxy_type: str, port: int, http_prefix: str,
                         debug: bool, project_version: str,
                         word_frequency: {}, system_language: str,
                         signing_priv_key_pem: str,
                         mitm_servers: []) -> []:
    """ Returns a dict of domains referenced within public posts
    """
    if not session:
        session = create_session(proxy_type)
    if not session:
        return {}
    person_cache = {}
    cached_webfingers = {}

    domain_full = get_full_domain(domain, port)
    handle = http_prefix + "://" + domain_full + "/@" + nickname
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        return {}
    if not isinstance(wf_request, dict):
        print('Webfinger for ' + handle + ' did not return a dict. ' +
              str(wf_request))
        return {}

    (person_url, _, _, _, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            nickname, domain, 'outbox',
                            13863, system_language,
                            mitm_servers)
    max_posts = 64
    post_domains = \
        get_post_domains(session, person_url, max_posts, debug,
                         project_version, http_prefix, domain,
                         word_frequency, [], system_language,
                         signing_priv_key_pem, mitm_servers)
    post_domains.sort()
    domains_info = {}
    for pdomain in post_domains:
        if not domains_info.get(pdomain):
            domains_info[pdomain]: list[str] = []

    blocked_posts = \
        _get_posts_for_blocked_domains(base_dir, session,
                                       person_url, max_posts,
                                       debug,
                                       project_version, http_prefix,
                                       domain, signing_priv_key_pem,
                                       mitm_servers)
    for blocked_domain, post_url_list in blocked_posts.items():
        domains_info[blocked_domain] += post_url_list

    return domains_info


def get_public_post_domains_blocked(session, base_dir: str,
                                    nickname: str, domain: str,
                                    proxy_type: str, port: int,
                                    http_prefix: str,
                                    debug: bool, project_version: str,
                                    word_frequency: {}, domain_list: [],
                                    system_language: str,
                                    signing_priv_key_pem: str,
                                    mitm_servers: []) -> []:
    """ Returns a list of domains referenced within public posts which
    are globally blocked on this instance
    """
    origin_domain = domain
    post_domains = \
        get_public_post_domains(session, base_dir, nickname, domain,
                                origin_domain,
                                proxy_type, port, http_prefix,
                                debug, project_version,
                                word_frequency, domain_list, system_language,
                                signing_priv_key_pem, mitm_servers)
    if not post_domains:
        return []

    blocking_filename = data_dir(base_dir) + '/blocking.txt'
    if not os.path.isfile(blocking_filename):
        return []

    # read the blocked domains as a single string
    blocked_str = ''
    try:
        with open(blocking_filename, 'r', encoding='utf-8') as fp_block:
            blocked_str = fp_block.read()
    except OSError:
        print('EX: get_public_post_domains_blocked unable to read ' +
              blocking_filename)

    blocked_domains: list[str] = []
    for domain_name in post_domains:
        if '@' not in domain_name:
            continue
        # get the domain after the @
        domain_name = domain_name.split('@')[1].strip()
        if is_evil(domain_name):
            blocked_domains.append(domain_name)
            continue
        if domain_name in blocked_str:
            blocked_domains.append(domain_name)

    return blocked_domains


def _get_non_mutuals_of_person(base_dir: str,
                               nickname: str, domain: str) -> []:
    """Returns the followers who are not mutuals of a person
    i.e. accounts which follow you but you don't follow them
    """
    followers = \
        get_followers_list(base_dir, nickname, domain, 'followers.txt')
    following = \
        get_followers_list(base_dir, nickname, domain, 'following.txt')
    non_mutuals: list[str] = []
    for handle in followers:
        if handle not in following:
            non_mutuals.append(handle)
    return non_mutuals


def check_domains(session, base_dir: str,
                  nickname: str, domain: str,
                  proxy_type: str, port: int, http_prefix: str,
                  debug: bool, project_version: str,
                  max_blocked_domains: int, single_check: bool,
                  system_language: str,
                  signing_priv_key_pem: str,
                  mitm_servers: []) -> None:
    """Checks follower accounts for references to globally blocked domains
    """
    word_frequency = {}
    non_mutuals = _get_non_mutuals_of_person(base_dir, nickname, domain)
    if not non_mutuals:
        print('No non-mutual followers were found')
        return
    follower_warning_filename = data_dir(base_dir) + '/followerWarnings.txt'
    update_follower_warnings = False
    follower_warning_str = ''
    if os.path.isfile(follower_warning_filename):
        try:
            with open(follower_warning_filename, 'r',
                      encoding='utf-8') as fp_warn:
                follower_warning_str = fp_warn.read()
        except OSError:
            print('EX: check_domains unable to read ' +
                  follower_warning_filename)

    if single_check:
        # checks a single random non-mutual
        index = random.randrange(0, len(non_mutuals))
        handle = non_mutuals[index]
        if '@' in handle:
            non_mutual_nickname = handle.split('@')[0]
            non_mutual_domain = handle.split('@')[1].strip()
            blocked_domains = \
                get_public_post_domains_blocked(session, base_dir,
                                                non_mutual_nickname,
                                                non_mutual_domain,
                                                proxy_type, port, http_prefix,
                                                debug, project_version,
                                                word_frequency, [],
                                                system_language,
                                                signing_priv_key_pem,
                                                mitm_servers)
            if blocked_domains:
                if len(blocked_domains) > max_blocked_domains:
                    follower_warning_str += handle + '\n'
                    update_follower_warnings = True
    else:
        # checks all non-mutuals
        for handle in non_mutuals:
            if '@' not in handle:
                continue
            if handle in follower_warning_str:
                continue
            non_mutual_nickname = handle.split('@')[0]
            non_mutual_domain = handle.split('@')[1].strip()
            blocked_domains = \
                get_public_post_domains_blocked(session, base_dir,
                                                non_mutual_nickname,
                                                non_mutual_domain,
                                                proxy_type, port, http_prefix,
                                                debug, project_version,
                                                word_frequency, [],
                                                system_language,
                                                signing_priv_key_pem,
                                                mitm_servers)
            if blocked_domains:
                print(handle)
                for bdomain in blocked_domains:
                    print('  ' + bdomain)
                if len(blocked_domains) > max_blocked_domains:
                    follower_warning_str += handle + '\n'
                    update_follower_warnings = True

    if update_follower_warnings and follower_warning_str:
        try:
            with open(follower_warning_filename, 'w+',
                      encoding='utf-8') as fp_warn:
                fp_warn.write(follower_warning_str)
        except OSError:
            print('EX: check_domains unable to write ' +
                  follower_warning_filename)
        if not single_check:
            print(follower_warning_str)


def populate_replies_json(base_dir: str, nickname: str, domain: str,
                          post_replies_filename: str, authorized: bool,
                          replies_json: {}) -> None:
    pub_str = 'https://www.w3.org/ns/activitystreams#Public'
    # populate the items list with replies
    replies_boxes = ('outbox', 'inbox')
    try:
        with open(post_replies_filename, 'r',
                  encoding='utf-8') as fp_replies:
            for message_id in fp_replies:
                reply_found = False
                # examine inbox and outbox
                for boxname in replies_boxes:
                    message_id2 = remove_eol(message_id)
                    search_filename = \
                        acct_dir(base_dir, nickname, domain) + '/' + \
                        boxname + '/' + \
                        message_id2.replace('/', '#') + '.json'
                    if os.path.isfile(search_filename):
                        if authorized or \
                           text_in_file(pub_str, search_filename):
                            post_json_object = load_json(search_filename)
                            if post_json_object:
                                pjo = post_json_object
                                ordered_items = replies_json['orderedItems']
                                if pjo['object'].get('cc'):
                                    if (authorized or
                                        (pub_str in pjo['object']['to'] or
                                         pub_str in pjo['object']['cc'])):
                                        ordered_items.append(pjo)
                                        reply_found = True
                                else:
                                    if authorized or \
                                       pub_str in pjo['object']['to']:
                                        ordered_items.append(pjo)
                                        reply_found = True
                        break
                # if not in either inbox or outbox then examine the
                # shared inbox
                if not reply_found:
                    message_id2 = remove_eol(message_id)
                    search_filename = \
                        data_dir(base_dir) + '/inbox@' + \
                        domain + '/inbox/' + \
                        message_id2.replace('/', '#') + '.json'
                    if os.path.isfile(search_filename):
                        if authorized or \
                           text_in_file(pub_str, search_filename):
                            # get the json of the reply and append it to
                            # the collection
                            post_json_object = load_json(search_filename)
                            if post_json_object:
                                pjo = post_json_object
                                ordered_items = replies_json['orderedItems']
                                if pjo['object'].get('cc'):
                                    if (authorized or
                                        (pub_str in pjo['object']['to'] or
                                         pub_str in pjo['object']['cc'])):
                                        ordered_items.append(pjo)
                                else:
                                    if authorized or \
                                       pub_str in pjo['object']['to']:
                                        ordered_items.append(pjo)
    except OSError:
        print('EX: populate_replies_json unable to read ' +
              post_replies_filename)


def _reject_announce(announce_filename: str,
                     base_dir: str, nickname: str, domain: str,
                     announce_post_id: str, recent_posts_cache: {},
                     debug: bool):
    """Marks an announce as rejected
    """
    reject_post_id(base_dir, nickname, domain, announce_post_id,
                   recent_posts_cache, debug)

    # reject the post referenced by the announce activity object
    if not os.path.isfile(announce_filename + '.reject'):
        try:
            with open(announce_filename + '.reject', 'w+',
                      encoding='utf-8') as fp_reject_announce:
                fp_reject_announce.write('\n')
        except OSError:
            print('EX: _reject_announce unable to write ' +
                  announce_filename + '.reject')


def download_announce(session, base_dir: str, http_prefix: str,
                      nickname: str, domain: str,
                      post_json_object: {}, project_version: str,
                      yt_replace_domain: str,
                      twitter_replacement_domain: str,
                      allow_local_network_access: bool,
                      recent_posts_cache: {}, debug: bool,
                      system_language: str,
                      domain_full: str, person_cache: {},
                      signing_priv_key_pem: str,
                      blocked_cache: {}, block_federated: [],
                      bold_reading: bool,
                      show_vote_posts: bool,
                      languages_understood: [],
                      mitm_servers: []) -> {}:
    """Download the post referenced by an announce
    """
    if not post_json_object.get('object'):
        return None
    if not isinstance(post_json_object['object'], str):
        return None
    # ignore self-boosts
    actor_url = get_actor_from_post(post_json_object)
    if actor_url in post_json_object['object']:
        return None

    # get the announced post
    announce_cache_dir = base_dir + '/cache/announce/' + nickname
    if not os.path.isdir(announce_cache_dir):
        os.mkdir(announce_cache_dir)

    post_id = None
    if post_json_object.get('id'):
        post_id = remove_id_ending(post_json_object['id'])
    announce_filename = \
        announce_cache_dir + '/' + \
        post_json_object['object'].replace('/', '#') + '.json'

    if os.path.isfile(announce_filename + '.reject'):
        return None

    if os.path.isfile(announce_filename):
        if debug:
            print('Reading cached Announce content for ' +
                  post_json_object['object'])
        post_json_object = load_json(announce_filename)
        if post_json_object:
            return post_json_object
    else:
        profile_str = 'https://www.w3.org/ns/activitystreams'
        accept_str = \
            'application/activity+json; ' + \
            'profile="' + profile_str + '"'
        as_header = {
            'Accept': accept_str
        }
        if '/channel/' in actor_url or \
           '/accounts/' in actor_url:
            accept_str = \
                'application/ld+json; ' + \
                'profile="' + profile_str + '"'
            as_header = {
                'Accept': accept_str
            }
        actor_nickname = get_nickname_from_actor(actor_url)
        if not actor_nickname:
            print('WARN: download_announce no actor_nickname')
            return None
        actor_domain, actor_port = \
            get_domain_from_actor(actor_url)
        if not actor_domain:
            print('Announce actor does not contain a ' +
                  'valid domain or port number: ' +
                  actor_url)
            return None
        if is_blocked(base_dir, nickname, domain,
                      actor_nickname, actor_domain,
                      None, block_federated):
            print('Announce download blocked actor: ' +
                  actor_nickname + '@' + actor_domain)
            return None
        object_nickname = get_nickname_from_actor(post_json_object['object'])
        object_domain, _ = \
            get_domain_from_actor(post_json_object['object'])
        if not object_domain:
            print('Announce object does not contain a ' +
                  'valid domain or port number: ' +
                  str(post_json_object['object']))
            return None
        if is_blocked(base_dir, nickname, domain, object_nickname,
                      object_domain, None, block_federated):
            if object_nickname and object_domain:
                print('Announce download blocked handle: ' +
                      object_nickname + '@' + object_domain)
            else:
                print('Announce download blocked handle 2: ' +
                      str(post_json_object['object']))
            return None
        if debug:
            print('Downloading Announce content for ' +
                  post_json_object['object'])
        announced_json = \
            get_json(signing_priv_key_pem, session,
                     post_json_object['object'],
                     as_header, None, debug, mitm_servers,
                     project_version, http_prefix, domain)

        if not get_json_valid(announced_json):
            return None

        if not isinstance(announced_json, dict):
            print('WARN: announced post json is not a dict - ' +
                  post_json_object['object'] + ' ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if announced_json.get('error'):
            print('WARN: ' +
                  'Attempt to download announce returned an error ' +
                  post_json_object['object'] + ' ' +
                  str(announced_json))
            return None
        if not announced_json.get('id'):
            print('WARN: announced post does not have an id ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None

        announced_actor = announced_json['id']
        if announced_json.get('attributedTo'):
            announced_actor = get_attributed_to(announced_json['attributedTo'])

        if not announced_json.get('type'):
            print('WARN: announced post does not have a type ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if announced_json['type'] == 'Video':
            converted_json = \
                convert_video_to_note(base_dir, nickname, domain,
                                      system_language,
                                      announced_json, blocked_cache,
                                      block_federated,
                                      languages_understood)
            if converted_json:
                announced_json = converted_json
        if not contains_statuses(announced_json['id']):
            print('WARN: announced post id does not contain /statuses/ ' +
                  'or /objects/ or /p/ ' + str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if not has_users_path(announced_actor):
            print('WARN: announced post id does not contain /users/ ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if announced_json['type'] not in ('Note', 'Event', 'Page',
                                          'Question', 'Article'):
            print('WARN: announced post is not Note/Page/Article/Question ' +
                  str(announced_json))
            # You can only announce Note, Page, Article or Question types
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if announced_json['type'] == 'Question':
            if not announced_json.get('endTime') or \
               not announced_json.get('oneOf'):
                # announced Question should have an end time
                # and options list
                print('WARN: announced Question should have endTime ' +
                      str(announced_json))
                _reject_announce(announce_filename,
                                 base_dir, nickname, domain, post_id,
                                 recent_posts_cache, debug)
                return None
            if not isinstance(announced_json['oneOf'], list):
                print('WARN: announced Question oneOf should be a list ' +
                      str(announced_json))
                _reject_announce(announce_filename,
                                 base_dir, nickname, domain, post_id,
                                 recent_posts_cache, debug)
                return None
            if is_question_filtered(base_dir, nickname, domain,
                                    system_language, announced_json):
                if announced_json.get('id'):
                    print('REJECT: announced question was filtered ' +
                          str(announced_json['id']))
                _reject_announce(announce_filename,
                                 base_dir, nickname, domain, post_id,
                                 recent_posts_cache, debug)
                return None
        if 'content' not in announced_json:
            print('WARN: announced post does not have content ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if not announced_json.get('published'):
            print('WARN: announced post does not have published ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if '.' in announced_json['published'] and \
           'Z' in announced_json['published']:
            announced_json['published'] = \
                announced_json['published'].split('.')[0] + 'Z'
        if not valid_post_date(announced_json['published'], 90, debug):
            print('WARN: announced post is not recently published ' +
                  str(announced_json['published']))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if not understood_post_language(base_dir, nickname,
                                        announced_json, system_language,
                                        http_prefix, domain_full,
                                        person_cache):
            return None
        # Check the content of the announce
        convert_post_content_to_html(announced_json)
        content_str = announced_json['content']
        using_content_map = False
        if 'contentMap' in announced_json:
            if announced_json['contentMap'].get(system_language):
                content_str = announced_json['contentMap'][system_language]
                using_content_map = True
        if dangerous_markup(content_str, allow_local_network_access, []):
            print('WARN: announced post contains dangerous markup ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        summary_str = \
            get_summary_from_post(announced_json, system_language, [])
        media_descriptions = \
            get_media_descriptions_from_post(announced_json)
        content_all = content_str
        if summary_str:
            content_all = \
                summary_str + ' ' + content_str + ' ' + media_descriptions
        if is_filtered(base_dir, nickname, domain, content_all,
                       system_language):
            if announced_json.get('id'):
                print('REJECT: announced post has been filtered ' +
                      str(announced_json['id']))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if reject_twitter_summary(base_dir, nickname, domain,
                                  summary_str):
            print('WARN: announced post has twitter summary ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if contains_private_key(content_str):
            print("WARN: announced post contains someone's private key " +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None
        if invalid_ciphertext(content_str):
            print('WARN: announced post contains invalid ciphertext ' +
                  str(announced_json))
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None

        # remove any long words
        content_str = remove_long_words(content_str, 40, [])

        # Prevent the same word from being repeated many times
        content_str = limit_repeated_words(content_str, 6)

        # remove text formatting, such as bold/italics
        content_str = remove_text_formatting(content_str, bold_reading)

        # set the content after santitization
        if using_content_map:
            announced_json['contentMap'][system_language] = content_str
        announced_json['content'] = content_str

        # wrap in create to be consistent with other posts
        announced_json = \
            outbox_message_create_wrap(http_prefix,
                                       actor_nickname, actor_domain,
                                       actor_port, announced_json)
        if announced_json['type'] != 'Create':
            print('WARN: announced post could not be wrapped in Create ' +
                  str(announced_json))
            # Create wrap failed
            _reject_announce(announce_filename,
                             base_dir, nickname, domain, post_id,
                             recent_posts_cache, debug)
            return None

        # if poll/vote/question is not to be shown
        if not show_vote_posts:
            if is_question(announced_json):
                return None

        # set the id to the original status
        announced_json['id'] = post_json_object['object']
        announced_json['object']['id'] = post_json_object['object']
        # check that the repeat isn't for a blocked account
        attributed_nickname = \
            get_nickname_from_actor(announced_json['object']['id'])
        attributed_domain, attributed_port = \
            get_domain_from_actor(announced_json['object']['id'])
        if attributed_nickname and attributed_domain:
            attributed_domain = \
                get_full_domain(attributed_domain, attributed_port)
            if is_blocked(base_dir, nickname, domain,
                          attributed_nickname, attributed_domain,
                          None, block_federated):
                print('WARN: announced post handle is blocked ' +
                      str(attributed_nickname) + '@' + attributed_domain)
                _reject_announce(announce_filename,
                                 base_dir, nickname, domain, post_id,
                                 recent_posts_cache, debug)
                return None
        post_json_object = announced_json
        replace_you_tube(post_json_object, yt_replace_domain, system_language)
        replace_twitter(post_json_object, twitter_replacement_domain,
                        system_language)
        if save_json(post_json_object, announce_filename):
            return post_json_object
    return None


def is_muted_conv(base_dir: str, nickname: str, domain: str, post_id: str,
                  conversation_id: str) -> bool:
    """Returns true if the given post is muted
    """
    if conversation_id:
        if not isinstance(conversation_id, str):
            return False
        conv_muted_filename = \
            acct_dir(base_dir, nickname, domain) + '/conversation/' + \
            conversation_id.replace('/', '#') + '.muted'
        if os.path.isfile(conv_muted_filename):
            return True
    post_filename = locate_post(base_dir, nickname, domain, post_id)
    if not post_filename:
        return False
    if os.path.isfile(post_filename + '.muted'):
        return True
    return False


def send_block_via_server(base_dir: str, session,
                          from_nickname: str, password: str,
                          from_domain: str, from_port: int,
                          http_prefix: str, blocked_url: str,
                          cached_webfingers: {}, person_cache: {},
                          debug: bool, project_version: str,
                          signing_priv_key_pem: str,
                          system_language: str,
                          mitm_servers: []) -> {}:
    """Creates a block via c2s
    """
    if not session:
        print('WARN: No session for send_block_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    block_actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = block_actor + '/followers'

    new_block_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Block',
        'actor': block_actor,
        'object': blocked_url,
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: block webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: block Webfinger for ' + handle +
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
                            from_nickname,
                            from_domain, post_to_box, 72652,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: block no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: block no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_block_json, [], inbox_url,
                            headers, 30, True)
    if not post_result:
        print('WARN: block unable to post')

    if debug:
        print('DEBUG: c2s POST block success')

    return new_block_json


def send_mute_via_server(base_dir: str, session,
                         from_nickname: str, password: str,
                         from_domain: str, from_port: int,
                         http_prefix: str, muted_url: str,
                         cached_webfingers: {}, person_cache: {},
                         debug: bool, project_version: str,
                         signing_priv_key_pem: str,
                         system_language: str,
                         mitm_servers: []) -> {}:
    """Creates a mute via c2s
    """
    if not session:
        print('WARN: No session for send_mute_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    handle = replace_users_with_at(actor)

    new_mute_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Ignore',
        'actor': actor,
        'to': [actor],
        'object': muted_url
    }

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: mute webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: mute Webfinger for ' + handle +
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
                            from_nickname,
                            from_domain, post_to_box, 72652,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: mute no ' + post_to_box + ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: mute no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_mute_json, [], inbox_url,
                            headers, 3, True)
    if post_result is None:
        print('WARN: mute unable to post')

    if debug:
        print('DEBUG: c2s POST mute success')

    return new_mute_json


def send_undo_mute_via_server(base_dir: str, session,
                              from_nickname: str, password: str,
                              from_domain: str, from_port: int,
                              http_prefix: str, muted_url: str,
                              cached_webfingers: {}, person_cache: {},
                              debug: bool, project_version: str,
                              signing_priv_key_pem: str,
                              system_language: str,
                              mitm_servers: []) -> {}:
    """Undoes a mute via c2s
    """
    if not session:
        print('WARN: No session for send_undo_mute_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    handle = replace_users_with_at(actor)

    undo_mute_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Undo',
        'actor': actor,
        'to': [actor],
        'object': {
            'type': 'Ignore',
            'actor': actor,
            'to': [actor],
            'object': muted_url
        }
    }

    # lookup the inbox for the To handle
    wf_request = webfinger_handle(session, handle, http_prefix,
                                  cached_webfingers,
                                  from_domain, project_version, debug, False,
                                  signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: undo mute webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: undo mute Webfinger for ' + handle +
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
                            from_nickname,
                            from_domain, post_to_box, 72652,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: undo mute no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: undo mute no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, undo_mute_json, [], inbox_url,
                            headers, 3, True)
    if post_result is None:
        print('WARN: undo mute unable to post')

    if debug:
        print('DEBUG: c2s POST undo mute success')

    return undo_mute_json


def send_undo_block_via_server(base_dir: str, session,
                               from_nickname: str, password: str,
                               from_domain: str, from_port: int,
                               http_prefix: str, blocked_url: str,
                               cached_webfingers: {}, person_cache: {},
                               debug: bool, project_version: str,
                               signing_priv_key_pem: str,
                               system_language: str,
                               mitm_servers: []) -> {}:
    """Creates a block via c2s
    """
    if not session:
        print('WARN: No session for send_block_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    block_actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = block_actor + '/followers'

    new_block_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Undo',
        'actor': block_actor,
        'object': {
            'type': 'Block',
            'actor': block_actor,
            'object': blocked_url,
            'to': [to_url],
            'cc': [cc_url]
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
            print('DEBUG: unblock webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: unblock webfinger for ' + handle +
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
                            from_nickname,
                            from_domain, post_to_box, 53892,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: unblock no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: unblock no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = post_json(http_prefix, from_domain_full,
                            session, new_block_json, [], inbox_url,
                            headers, 30, True)
    if not post_result:
        print('WARN: unblock unable to post')

    if debug:
        print('DEBUG: c2s POST unblock success')

    return new_block_json


def post_is_muted(base_dir: str, nickname: str, domain: str,
                  post_json_object: {}, message_id: str) -> bool:
    """ Returns true if the given post is muted
    """
    is_muted = None
    if 'muted' in post_json_object:
        is_muted = post_json_object['muted']
    if is_muted is True or is_muted is False:
        return is_muted

    is_muted = False
    post_dir = acct_dir(base_dir, nickname, domain)
    mute_filename = \
        post_dir + '/inbox/' + message_id.replace('/', '#') + '.json.muted'
    if os.path.isfile(mute_filename):
        is_muted = True
    else:
        mute_filename = \
            post_dir + '/outbox/' + \
            message_id.replace('/', '#') + '.json.muted'
        if os.path.isfile(mute_filename):
            is_muted = True
        else:
            mute_filename = \
                data_dir(base_dir) + '/cache/announce/' + nickname + \
                '/' + message_id.replace('/', '#') + '.json.muted'
            if os.path.isfile(mute_filename):
                is_muted = True
    return is_muted


def c2s_box_json(session, nickname: str, password: str,
                 domain: str, port: int,
                 http_prefix: str,
                 box_name: str, page_number: int,
                 debug: bool, signing_priv_key_pem: str,
                 mitm_servers: []) -> {}:
    """C2S Authenticated GET of posts for a timeline
    """
    if not session:
        print('WARN: No session for c2s_box_json')
        return None

    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)

    auth_header = create_basic_auth_header(nickname, password)

    profile_str = 'https://www.w3.org/ns/activitystreams'
    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header,
        'Accept': 'application/ld+json; profile="' + profile_str + '"'
    }

    # GET json
    url = actor + '/' + box_name + '?page=' + str(page_number)
    box_json = get_json(signing_priv_key_pem, session, url, headers, None,
                        debug, mitm_servers, __version__, http_prefix, None)

    if get_json_valid(box_json) and debug:
        print('DEBUG: GET c2s_box_json success')

    return box_json


def seconds_between_published(published1: str, published2: str) -> int:
    """Returns the number of seconds between two published dates
    """
    published1_time = \
        date_from_string_format(published1, ['%Y-%m-%dT%H:%M:%S%z'])
    if not published1_time:
        print('EX: seconds_between_published unable to parse date 1 ' +
              str(published1))
        return -1
    published2_time = \
        date_from_string_format(published2, ['%Y-%m-%dT%H:%M:%S%z'])
    if not published2_time:
        print('EX: seconds_between_published unable to parse date 2 ' +
              str(published2))
        return -1
    return (published2_time - published1_time).total_seconds()


def edited_post_filename(base_dir: str, nickname: str, domain: str,
                         post_json_object: {}, debug: bool,
                         max_time_diff_seconds: int,
                         system_language: str) -> (str, {}):
    """Returns the filename of the edited post
    """
    if not has_object_dict(post_json_object):
        return '', None
    if not post_json_object.get('type'):
        return '', None
    if not post_json_object['object'].get('type'):
        return '', None
    if not post_json_object['object'].get('published'):
        return '', None
    if not post_json_object['object'].get('id'):
        return '', None
    if 'content' not in post_json_object['object']:
        return '', None
    if not post_json_object['object'].get('attributedTo'):
        return '', None
    if not get_attributed_to(post_json_object['object']['attributedTo']):
        return '', None
    actor = get_attributed_to(post_json_object['object']['attributedTo'])
    # file storing the last post id for a given actor
    actor_filename = \
        acct_dir(base_dir, nickname, domain) + '/lastpost/' + \
        actor.replace('/', '#')
    if not os.path.isfile(actor_filename):
        return '', None
    post_id = remove_id_ending(post_json_object['object']['id'])
    lastpost_id = None
    try:
        with open(actor_filename, 'r',
                  encoding='utf-8') as fp_actor:
            lastpost_id = fp_actor.read()
    except OSError:
        print('EX: edited_post_filename unable to read ' + actor_filename)
        return '', None
    if not lastpost_id:
        return '', None
    if lastpost_id == post_id:
        return '', None
    lastpost_filename = \
        locate_post(base_dir, nickname, domain, lastpost_id, False)
    if not lastpost_filename:
        return '', None
    lastpost_json = load_json(lastpost_filename)
    if not lastpost_json:
        return '', None
    if not lastpost_json.get('type'):
        return '', None
    if lastpost_json['type'] != post_json_object['type']:
        return '', None
    if not lastpost_json.get('object'):
        return '', None
    if not isinstance(lastpost_json['object'], dict):
        return '', None
    if not lastpost_json['object'].get('type'):
        return '', None
    if lastpost_json['object']['type'] != post_json_object['object']['type']:
        return '', None
    if not lastpost_json['object'].get('published'):
        return '', None
    if not lastpost_json['object'].get('id'):
        return '', None
    if 'content' not in lastpost_json['object']:
        return '', None
    if not lastpost_json['object'].get('attributedTo'):
        return '', None
    if not get_attributed_to(lastpost_json['object']['attributedTo']):
        return '', None
    time_diff_seconds = \
        seconds_between_published(lastpost_json['object']['published'],
                                  post_json_object['object']['published'])
    if time_diff_seconds > max_time_diff_seconds:
        return '', None
    if debug:
        print(post_id + ' might be an edit of ' + lastpost_id)
    lastpost_content = lastpost_json['object']['content']
    if 'contentMap' in lastpost_json['object']:
        if lastpost_json['object']['contentMap'].get(system_language):
            lastpost_content = \
                lastpost_json['object']['contentMap'][system_language]
    content = post_json_object['object']['content']
    if 'contentMap' in post_json_object['object']:
        if post_json_object['object']['contentMap'].get(system_language):
            content = \
                post_json_object['object']['contentMap'][system_language]
    if words_similarity(lastpost_content, content, 10) < 70:
        return '', None
    print(post_id + ' is an edit of ' + lastpost_id)
    return lastpost_filename, lastpost_json


def get_original_post_from_announce_url(announce_url: str, base_dir: str,
                                        nickname: str,
                                        domain: str) -> (str, str, str):
    """From the url of an announce this returns the actor, url and
    filename (if available) of the original post being announced
    """
    post_filename = locate_post(base_dir, nickname, domain, announce_url)
    if not post_filename:
        return None, None, None
    announce_post_json = load_json(post_filename)
    if not announce_post_json:
        return None, None, post_filename
    if not announce_post_json.get('type'):
        return None, None, post_filename
    if announce_post_json['type'] != 'Announce':
        return None, None, post_filename
    if not announce_post_json.get('object'):
        return None, None, post_filename
    if not isinstance(announce_post_json['object'], str):
        return None, None, post_filename
    actor = url = None
    # do we have the original post?
    orig_post_id = announce_post_json['object']
    orig_filename = locate_post(base_dir, nickname, domain, orig_post_id)
    if orig_filename:
        # we have the original post
        orig_post_json = load_json(orig_filename)
        if orig_post_json:
            if has_object_dict(orig_post_json):
                if orig_post_json['object'].get('attributedTo'):
                    attrib_field = \
                        orig_post_json['object']['attributedTo']
                    attrib = get_attributed_to(attrib_field)
                    if attrib:
                        actor = attrib
                        url = orig_post_id
                elif orig_post_json['object'].get('actor'):
                    actor = get_actor_from_post(orig_post_json)
                    url = orig_post_id
    else:
        # we don't have the original post
        if has_users_path(orig_post_id):
            # get the actor from the original post url
            orig_nick = get_nickname_from_actor(orig_post_id)
            orig_domain, _ = get_domain_from_actor(orig_post_id)
            if orig_nick and orig_domain:
                actor = \
                    orig_post_id.split('/' + orig_nick + '/')[0] + \
                    '/' + orig_nick
                url = orig_post_id

    return actor, url, orig_filename


def get_max_profile_posts(base_dir: str, nickname: str, domain: str,
                          max_recent_posts: int) -> int:
    """Returns the maximum number of posts to show on the profile screen
    """
    max_posts_filename = \
        acct_dir(base_dir, nickname, domain) + '/max_profile_posts.txt'
    max_profile_posts = 4
    if not os.path.isfile(max_posts_filename):
        return max_profile_posts
    try:
        with open(max_posts_filename, 'r', encoding='utf-8') as fp_posts:
            max_posts_str = fp_posts.read()
            if max_posts_str:
                if max_posts_str.isdigit():
                    max_profile_posts = int(max_posts_str)
    except OSError:
        print('EX: unable to read maximum profile posts ' +
              max_posts_filename)
    if max_profile_posts < 1:
        max_profile_posts = 1
    if max_profile_posts > max_recent_posts:
        max_profile_posts = max_recent_posts
    return max_profile_posts


def set_max_profile_posts(base_dir: str, nickname: str, domain: str,
                          max_recent_posts: int) -> bool:
    """Sets the maximum number of posts to show on the profile screen
    """
    max_posts_filename = \
        acct_dir(base_dir, nickname, domain) + '/max_profile_posts.txt'
    max_recent_posts_str = str(max_recent_posts)
    try:
        with open(max_posts_filename, 'w+',
                  encoding='utf-8') as fp_posts:
            fp_posts.write(max_recent_posts_str)
    except OSError:
        print('EX: unable to save maximum profile posts ' +
              max_posts_filename)
        return False
    return True


def json_post_allows_comments(post_json_object: {}) -> bool:
    """Returns true if the given post allows comments/replies
    """
    # reply control with
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/5624/fep-5624.md
    reply_control = None
    if 'canReply' in post_json_object:
        reply_control = post_json_object['canReply']
    if 'capabilities' in post_json_object:
        if isinstance(post_json_object['capabilities'], dict):
            if 'reply' in post_json_object['capabilities']:
                if isinstance(post_json_object['capabilities']['reply'], str):
                    reply_control = post_json_object['capabilities']['reply']
            else:
                # capabilities exist but there is no reply field
                reply_control = 'noreply'
    obj_dict_exists = False
    if has_object_dict(post_json_object):
        obj_dict_exists = True
        post_obj = post_json_object['object']
        if 'canReply' in post_obj:
            reply_control = post_obj['canReply']
        if 'capabilities' in post_obj:
            if isinstance(post_obj['capabilities'], dict):
                if 'reply' in post_obj['capabilities']:
                    if isinstance(post_obj['capabilities']['reply'], str):
                        reply_control = post_obj['capabilities']['reply']
                else:
                    # capabilities exist but there is no reply field
                    reply_control = 'noreply'
    if reply_control:
        if isinstance(reply_control, str):
            if reply_control == 'noreply':
                return False
            if not reply_control.endswith('#Public'):
                # TODO handle non-public reply permissions
                print('CAPABILITIES: replies ' + str(reply_control))
                return False

    if 'commentsEnabled' in post_json_object:
        return post_json_object['commentsEnabled']
    if 'rejectReplies' in post_json_object:
        return not post_json_object['rejectReplies']

    if post_json_object.get('object'):
        if not obj_dict_exists:
            return False
        if 'commentsEnabled' in post_json_object['object']:
            return post_json_object['object']['commentsEnabled']
        if 'rejectReplies' in post_json_object['object']:
            return not post_json_object['object']['rejectReplies']

    return True


def _estimate_number_of_mentions(content: str) -> int:
    """Returns a rough estimate of the number of mentions
    """
    return content.count('>@<')


def _estimate_number_of_emoji(content: str) -> int:
    """Returns a rough estimate of the number of emoji
    """
    return content.count(' :')


def _estimate_number_of_hashtags(content: str) -> int:
    """Returns a rough estimate of the number of hashtags
    """
    return content.count('>#<')


def post_allow_comments(post_filename: str) -> bool:
    """Returns true if the given post allows comments/replies
    """
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return False
    return json_post_allows_comments(post_json_object)


def valid_post_content(base_dir: str, nickname: str, domain: str,
                       message_json: {}, max_mentions: int, max_emoji: int,
                       allow_local_network_access: bool, debug: bool,
                       system_language: str,
                       http_prefix: str, domain_full: str,
                       person_cache: {},
                       max_hashtags: int,
                       onion_domain: str, i2p_domain: str) -> bool:
    """Is the content of a received post valid?
    Check for bad html
    Check for hellthreads
    Check that the language is understood
    Check if it's a git patch
    Check number of tags and mentions is reasonable
    """
    if not has_object_dict(message_json):
        return True
    if 'content' not in message_json['object']:
        return True

    if not message_json['object'].get('published'):
        if message_json['object'].get('id'):
            print('REJECT inbox post does not have a published date. ' +
                  str(message_json['object']['id']))
        return False
    published = message_json['object']['published']
    if 'T' not in published:
        if message_json['object'].get('id'):
            print('REJECT inbox post does not use expected time format. ' +
                  published + ' ' + str(message_json['object']['id']))
        return False
    if 'Z' not in published:
        if message_json['object'].get('id'):
            print('REJECT inbox post does not use Zulu time format. ' +
                  published + ' ' + str(message_json['object']['id']))
        return False
    if '.' in published:
        # converts 2022-03-30T17:37:58.734Z into 2022-03-30T17:37:58Z
        published = published.split('.')[0] + 'Z'
        message_json['object']['published'] = published
    if not valid_post_date(published, 90, debug):
        if message_json['object'].get('id'):
            print('REJECT: invalid post published date ' +
                  str(published) + ' ' +
                  str(message_json['object']['id']))
        return False

    # if the post has been edited then check its edit date
    if message_json['object'].get('updated'):
        published_update = message_json['object']['updated']
        if 'T' not in published_update:
            if message_json['object'].get('id'):
                print('REJECT: invalid post update date format ' +
                      str(published_update) + ' ' +
                      str(message_json['object']['id']))
            return False
        if 'Z' not in published_update:
            if message_json['object'].get('id'):
                print('REJECT: post update date not in Zulu time ' +
                      str(published_update) + ' ' +
                      str(message_json['object']['id']))
            return False
        if '.' in published_update:
            # converts 2022-03-30T17:37:58.734Z into 2022-03-30T17:37:58Z
            published_update = published_update.split('.')[0] + 'Z'
            message_json['object']['updated'] = published_update
        if not valid_post_date(published_update, 90, debug):
            if message_json['object'].get('id'):
                print('REJECT: invalid post update date ' +
                      str(published_update) + ' ' +
                      str(message_json['object']['id']))
            return False

    summary = None
    if message_json['object'].get('summary'):
        summary = message_json['object']['summary']
        if not isinstance(summary, str):
            if message_json['object'].get('id'):
                print('REJECT: content warning is not a string ' +
                      str(summary) + ' ' + str(message_json['object']['id']))
            return False
        if summary != valid_content_warning(summary):
            if message_json['object'].get('id'):
                print('REJECT: invalid content warning ' + summary + ' ' +
                      str(message_json['object']['id']))
            return False
        if dangerous_markup(summary, allow_local_network_access, []):
            if message_json['object'].get('id'):
                print('REJECT ARBITRARY HTML 1: ' +
                      message_json['object']['id'])
            print('REJECT ARBITRARY HTML: bad string in summary - ' +
                  summary)
            return False

    # check for patches before dangeousMarkup, which excludes code
    if is_git_patch(base_dir, nickname, domain,
                    message_json['object']['type'],
                    summary,
                    message_json['object']['content']):
        return True

    if is_question(message_json):
        if is_question_filtered(base_dir, nickname, domain,
                                system_language, message_json):
            print('REJECT: incoming question options filter')
            return False
        if dangerous_question(message_json, allow_local_network_access):
            print('REJECT: incoming question markup filter')
            return False

    content_str = get_base_content_from_post(message_json, system_language)
    if dangerous_markup(content_str, allow_local_network_access, ['pre']):
        if message_json['object'].get('id'):
            print('REJECT ARBITRARY HTML 2: ' +
                  str(message_json['object']['id']))
        if debug:
            print('REJECT ARBITRARY HTML: bad string in post - ' +
                  content_str)
        return False

    if contains_invalid_local_links(domain_full,
                                    onion_domain, i2p_domain,
                                    content_str):
        if message_json['object'].get('id'):
            print('REJECT: post contains invalid local links ' +
                  str(message_json['object']['id']) + ' ' +
                  str(content_str))
        return False

    # check (rough) number of mentions
    mentions_est = _estimate_number_of_mentions(content_str)
    if mentions_est > max_mentions:
        if message_json['object'].get('id'):
            print('REJECT HELLTHREAD: ' + str(message_json['object']['id']))
        if debug:
            print('REJECT HELLTHREAD: Too many mentions in post - ' +
                  content_str)
        return False
    if _estimate_number_of_emoji(content_str) > max_emoji:
        if message_json['object'].get('id'):
            print('REJECT EMOJI OVERLOAD: ' +
                  str(message_json['object']['id']))
        if debug:
            print('REJECT EMOJI OVERLOAD: Too many emoji in post - ' +
                  content_str)
        return False
    if _estimate_number_of_hashtags(content_str) > max_hashtags:
        if message_json['object'].get('id'):
            print('REJECT HASHTAG OVERLOAD: ' +
                  str(message_json['object']['id']))
        if debug:
            print('REJECT HASHTAG OVERLOAD: Too many hashtags in post - ' +
                  content_str)
        return False
    # check number of tags
    if message_json['object'].get('tag'):
        if not isinstance(message_json['object']['tag'], list):
            message_json['object']['tag']: list[dict] = []
        else:
            if len(message_json['object']['tag']) > int(max_mentions * 2):
                if message_json['object'].get('id'):
                    print('REJECT: ' + message_json['object']['id'])
                print('REJECT: Too many tags in post - ' +
                      str(message_json['object']['tag']))
                return False
    # check that the post is in a language suitable for this account
    if not understood_post_language(base_dir, nickname,
                                    message_json, system_language,
                                    http_prefix, domain_full,
                                    person_cache):
        if message_json['object'].get('id'):
            print('REJECT: content not understood ' +
                  str(message_json['object']['id']))
        return False

    # check for urls which are too long
    if not valid_url_lengths(content_str, 2048):
        print('REJECT: url within content too long')
        return False

    # check for filtered content
    media_descriptions = get_media_descriptions_from_post(message_json)
    content_all = content_str
    if summary:
        content_all = summary + ' ' + content_str + ' ' + media_descriptions
    if is_filtered(base_dir, nickname, domain, content_all,
                   system_language):
        if message_json['object'].get('id'):
            print('REJECT: content filtered ' +
                  str(message_json['object']['id']))
        return False
    reply_id = get_reply_to(message_json['object'])
    if reply_id:
        if isinstance(reply_id, str):
            # this is a reply
            original_post_id = reply_id
            post_post_filename = locate_post(base_dir, nickname, domain,
                                             original_post_id)
            if post_post_filename:
                if not post_allow_comments(post_post_filename):
                    print('REJECT: reply to post which does not ' +
                          'allow comments: ' + original_post_id)
                    return False
    if contains_private_key(message_json['object']['content']):
        if message_json['object'].get('id'):
            print('REJECT: someone posted their private key ' +
                  str(message_json['object']['id']) + ' ' +
                  message_json['object']['content'])
        return False
    if invalid_ciphertext(message_json['object']['content']):
        if message_json['object'].get('id'):
            print('REJECT: malformed ciphertext in content ' +
                  str(message_json['object']['id']) + ' ' +
                  message_json['object']['content'])
        return False
    if debug:
        print('ACCEPT: post content is valid')
    return True
