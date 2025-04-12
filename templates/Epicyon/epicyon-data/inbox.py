__filename__ = "inbox.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import json
import os
import datetime
import time
import random
from shutil import copyfile
from linked_data_sig import verify_json_signature
from flags import is_system_account
from flags import is_blog_post
from flags import is_recent_post
from flags import is_reply
from flags import is_group_account
from flags import has_group_type
from flags import is_quote_toot
from flags import url_permitted
from utils import save_mitm_servers
from utils import harmless_markup
from utils import quote_toots_allowed
from utils import lines_in_file
from utils import date_epoch
from utils import date_utcnow
from utils import contains_statuses
from utils import get_actor_from_post_id
from utils import acct_handle_dir
from utils import text_in_file
from utils import get_media_descriptions_from_post
from utils import get_summary_from_post
from utils import get_account_timezone
from utils import domain_permitted
from utils import get_reply_interval_hours
from utils import can_reply_to
from utils import get_base_content_from_post
from utils import acct_dir
from utils import remove_domain_port
from utils import get_port_from_domain
from utils import has_object_dict
from utils import dm_allowed_from_domain
from utils import get_config_param
from utils import has_users_path
from utils import get_full_domain
from utils import remove_id_ending
from utils import create_inbox_queue_dir
from utils import get_status_number
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from utils import locate_post
from utils import delete_post
from utils import load_json
from utils import save_json
from utils import local_actor_url
from utils import get_attributed_to
from utils import get_reply_to
from utils import get_actor_from_post
from utils import data_dir
from utils import is_dm
from utils import has_actor
from httpsig import get_digest_algorithm_from_headers
from httpsig import verify_post_headers
from session import create_session
from follow import is_following_actor
from follow import get_followers_of_actor
from follow import is_follower_of_person
from follow import followed_account_accepts
from follow import store_follow_request
from follow import no_of_follow_requests
from follow import get_no_of_followers
from follow import follow_approval_required
from pprint import pprint
from cache import cache_svg_images
from cache import get_person_pub_key
from acceptreject import receive_accept_reject
from acceptreject import receive_quote_request
from blocking import is_blocked
from blocking import is_blocked_nickname
from blocking import is_blocked_domain
from blocking import broch_modeLapses
from filters import is_filtered
from httpsig import message_content_digest
from posts import outbox_message_create_wrap
from posts import convert_post_content_to_html
from posts import edited_post_filename
from posts import save_post_to_box
from posts import is_create_inside_announce
from posts import create_direct_message_post
from posts import is_muted_conv
from posts import is_image_media
from posts import send_signed_json
from posts import send_to_followers_thread
from posts import post_allow_comments
from posts import valid_post_content
from webapp_post import individual_post_as_html
from question import is_vote
from media import replace_you_tube
from media import replace_twitter
from git import receive_git_patch
from followingCalendar import receiving_calendar_events
from happening import save_event_post
from context import has_valid_context
from speaker import update_speaker
from announce import create_announce
from notifyOnPost import notify_when_person_posts
from conversation import update_conversation
from webapp_hashtagswarm import store_hash_tags
from person import valid_sending_actor
from fitnessFunctions import fitness_performance
from content import reject_twitter_summary
from content import load_dogwhistles
from threads import begin_thread
from reading import store_book_events
from inbox_receive import inbox_update_index
from inbox_receive import receive_edit_to_post
from inbox_receive import receive_like
from inbox_receive import receive_reaction
from inbox_receive import receive_zot_reaction
from inbox_receive import receive_bookmark
from inbox_receive import receive_announce
from inbox_receive import receive_delete
from inbox_receive import receive_question_vote
from inbox_receive import receive_move_activity
from inbox_receive import receive_update_activity
from inbox_receive_undo import receive_undo_like
from inbox_receive_undo import receive_undo_reaction
from inbox_receive_undo import receive_undo_bookmark
from inbox_receive_undo import receive_undo_announce
from inbox_receive_undo import receive_undo


def _store_last_post_id(base_dir: str, nickname: str, domain: str,
                        post_json_object: {}) -> None:
    """Stores the id of the last post made by an actor
    When a new post arrives this allows it to be compared against the last
    to see if it is an edited post.
    It would be great if edited posts contained a back reference id to the
    source but we don't live in that ideal world.
    """
    actor = post_id = None
    if has_object_dict(post_json_object):
        if post_json_object['object'].get('attributedTo'):
            actor_str = \
                get_attributed_to(post_json_object['object']['attributedTo'])
            if actor_str:
                actor = actor_str
                post_id = remove_id_ending(post_json_object['object']['id'])
    if not actor:
        actor = get_actor_from_post(post_json_object)
        post_id = remove_id_ending(post_json_object['id'])
    if not actor:
        return
    account_dir = acct_dir(base_dir, nickname, domain)
    lastpost_dir = account_dir + '/lastpost'
    if not os.path.isdir(lastpost_dir):
        if os.path.isdir(account_dir):
            os.mkdir(lastpost_dir)
    actor_filename = lastpost_dir + '/' + actor.replace('/', '#')
    try:
        with open(actor_filename, 'w+', encoding='utf-8') as fp_actor:
            fp_actor.write(post_id)
    except OSError:
        print('EX: Unable to write last post id to ' + actor_filename)


def _inbox_store_post_to_html_cache(recent_posts_cache: {},
                                    max_recent_posts: int,
                                    translate: {},
                                    base_dir: str, http_prefix: str,
                                    session, cached_webfingers: {},
                                    person_cache: {},
                                    nickname: str, domain: str, port: int,
                                    post_json_object: {},
                                    allow_deletion: bool, boxname: str,
                                    show_published_date_only: bool,
                                    peertube_instances: [],
                                    allow_local_network_access: bool,
                                    theme_name: str, system_language: str,
                                    max_like_count: int,
                                    signing_priv_key_pem: str,
                                    cw_lists: {},
                                    lists_enabled: str,
                                    timezone: str,
                                    mitm: bool,
                                    bold_reading: bool,
                                    dogwhistles: {},
                                    min_images_for_accounts: [],
                                    buy_sites: {},
                                    auto_cw_cache: {},
                                    mitm_servers: [],
                                    instance_software: {}) -> None:
    """Converts the json post into html and stores it in a cache
    This enables the post to be quickly displayed later
    """
    page_number = -999
    avatar_url = None
    if boxname != 'outbox':
        boxname = 'inbox'

    not_dm = not is_dm(post_json_object)
    yt_replace_domain = get_config_param(base_dir, 'youtubedomain')
    twitter_replacement_domain = get_config_param(base_dir, 'twitterdomain')
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    individual_post_as_html(signing_priv_key_pem,
                            True, recent_posts_cache, max_recent_posts,
                            translate, page_number,
                            base_dir, session, cached_webfingers,
                            person_cache,
                            nickname, domain, port, post_json_object,
                            avatar_url, True, allow_deletion,
                            http_prefix, __version__, boxname,
                            yt_replace_domain, twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances, allow_local_network_access,
                            theme_name, system_language, max_like_count,
                            not_dm, True, True, False, True, False,
                            cw_lists, lists_enabled, timezone, mitm,
                            bold_reading, dogwhistles, minimize_all_images,
                            None, buy_sites, auto_cw_cache,
                            mitm_servers, instance_software)


def valid_inbox(base_dir: str, nickname: str, domain: str) -> bool:
    """Checks whether files were correctly saved to the inbox
    """
    domain = remove_domain_port(domain)
    inbox_dir = acct_dir(base_dir, nickname, domain) + '/inbox'
    if not os.path.isdir(inbox_dir):
        return True
    for subdir, _, files in os.walk(inbox_dir):
        for fname in files:
            filename = os.path.join(subdir, fname)
            if not os.path.isfile(filename):
                print('filename: ' + filename)
                return False
            if text_in_file('postNickname', filename):
                print('queue file incorrectly saved to ' + filename)
                return False
        break
    return True


def valid_inbox_filenames(base_dir: str, nickname: str, domain: str,
                          expected_domain: str, expected_port: int) -> bool:
    """Used by unit tests to check that the port number gets appended to
    domain names within saved post filenames
    """
    domain = remove_domain_port(domain)
    inbox_dir = acct_dir(base_dir, nickname, domain) + '/inbox'
    if not os.path.isdir(inbox_dir):
        print('Not an inbox directory: ' + inbox_dir)
        return True
    expected_str = expected_domain + ':' + str(expected_port)
    expected_found = False
    ctr = 0
    for subdir, _, files in os.walk(inbox_dir):
        for fname in files:
            filename = os.path.join(subdir, fname)
            ctr += 1
            if not os.path.isfile(filename):
                print('filename: ' + filename)
                return False
            if expected_str in filename:
                expected_found = True
        break
    if ctr == 0:
        return True
    if not expected_found:
        print('Expected file was not found: ' + expected_str)
        for subdir, _, files in os.walk(inbox_dir):
            for fname in files:
                filename = os.path.join(subdir, fname)
                print(filename)
            break
        return False
    return True


def inbox_message_has_params(message_json: {}) -> bool:
    """Checks whether an incoming message contains expected parameters
    """
    expected_params = ['actor', 'type', 'object']
    for param in expected_params:
        if not message_json.get(param):
            # print('inbox_message_has_params: ' +
            #       param + ' ' + str(message_json))
            return False

    # actor should be a string
    actor_url = get_actor_from_post(message_json)
    if not actor_url:
        print('WARN: actor should be a string, but is actually: ' +
              actor_url)
        pprint(message_json)
        return False

    # type should be a string
    if not isinstance(message_json['type'], str):
        print('WARN: type from ' + actor_url +
              ' should be a string, but is actually: ' +
              str(message_json['type']))
        return False

    # object should be a dict or a string
    if not has_object_dict(message_json):
        if not isinstance(message_json['object'], str):
            print('WARN: object from ' + actor_url +
                  ' should be a dict or string, but is actually: ' +
                  str(message_json['object']))
            return False

    if not message_json.get('to'):
        allowed_without_to_param = ['Like', 'EmojiReact',
                                    'Follow', 'Join', 'Request',
                                    'Accept', 'Capability', 'Undo',
                                    'Move']
        if message_json['type'] not in allowed_without_to_param:
            return False
    return True


def inbox_permitted_message(domain: str, message_json: {},
                            federation_list: []) -> bool:
    """ check that we are receiving from a permitted domain
    """
    if not has_actor(message_json, False):
        return False

    actor = get_actor_from_post(message_json)
    # always allow the local domain
    if domain in actor:
        return True

    if not url_permitted(actor, federation_list):
        return False

    always_allowed_types = (
        'Follow', 'Join', 'Like', 'EmojiReact', 'Delete', 'Announce', 'Move',
        'QuoteRequest'
    )
    if message_json['type'] not in always_allowed_types:
        if not has_object_dict(message_json):
            return True
        reply_id = get_reply_to(message_json['object'])
        if reply_id:
            in_reply_to = reply_id
            if not isinstance(in_reply_to, str):
                return False
            if not url_permitted(in_reply_to, federation_list):
                return False

    return True


def _deny_non_follower(base_dir: str, nickname: str, domain: str,
                       reply_nickname: str, reply_domain: str,
                       sending_actor: str):
    """Returns true if replying to an account which is not a follower
    or mutual.
    This only applies if 'Only replies from followers' or
    'Only replies from mutuals' is selected on the edit profile screen
    """
    # Is this a reply to something written from this account?
    if reply_nickname != nickname or reply_domain != domain:
        return False

    # has this account specified to only receive replies from followers?
    account_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isfile(account_dir + '/.repliesFromFollowersOnly'):
        if not os.path.isfile(account_dir + '/.repliesFromMutualsOnly'):
            return False

    # is the sending actor a follower?
    follower_nickname = get_nickname_from_actor(sending_actor)
    follower_domain, _ = get_domain_from_actor(sending_actor)
    if not is_follower_of_person(base_dir, nickname, domain,
                                 follower_nickname, follower_domain):
        return True
    if os.path.isfile(account_dir + '/.repliesFromMutualsOnly'):
        if not is_following_actor(base_dir, nickname, domain,
                                  sending_actor):
            return True

    return False


def save_post_to_inbox_queue(base_dir: str, http_prefix: str,
                             nickname: str, domain: str,
                             post_json_object: {},
                             original_post_json_object: {},
                             message_bytes: str,
                             http_headers: {},
                             post_path: str, debug: bool,
                             blocked_cache: [],
                             block_federated: [],
                             system_language: str,
                             mitm: bool,
                             max_message_bytes: int) -> str:
    """Saves the given json to the inbox queue for the person
    key_id specifies the actor sending the post
    """
    if len(message_bytes) > max_message_bytes:
        print('REJECT: inbox message too long ' +
              str(len(message_bytes)) + ' bytes')
        return None
    original_domain = domain
    domain = remove_domain_port(domain)

    # block at the ealiest stage possible, which means the data
    # isn't written to file
    post_nickname = None
    post_domain = None
    actor = None
    obj_dict_exists = False

    # who is sending the post?
    sending_actor = None
    if has_object_dict(post_json_object):
        obj_dict_exists = True
        if post_json_object['object'].get('attributedTo'):
            sending_actor = \
                get_attributed_to(post_json_object['object']['attributedTo'])
    else:
        if post_json_object.get('attributedTo'):
            sending_actor = \
                get_attributed_to(post_json_object['attributedTo'])
    if not sending_actor:
        if post_json_object.get('actor'):
            sending_actor = get_actor_from_post(post_json_object)

    # check that the sender is valid
    if sending_actor:
        if not isinstance(sending_actor, str):
            print('REJECT: sending actor is not a string ' +
                  str(sending_actor))
            return None
        actor = sending_actor
        post_nickname = get_nickname_from_actor(sending_actor)
        if not post_nickname:
            print('REJECT: No post Nickname in actor ' + sending_actor)
            return None
        post_domain, post_port = \
            get_domain_from_actor(sending_actor)
        if not post_domain:
            if debug:
                pprint(post_json_object)
            print('REJECT: No post Domain in actor ' + str(sending_actor))
            return None
        if is_blocked(base_dir, nickname, domain,
                      post_nickname, post_domain,
                      blocked_cache, block_federated):
            print('BLOCK: post from ' +
                  post_nickname + '@' + post_domain + ' blocked')
            return None
        post_domain = get_full_domain(post_domain, post_port)

    # get the content of the post
    content_str = \
        get_base_content_from_post(post_json_object, system_language)

    if obj_dict_exists:
        if is_quote_toot(post_json_object, content_str):
            allow_quotes = False
            if sending_actor:
                allow_quotes = \
                    quote_toots_allowed(base_dir, nickname, domain,
                                        post_nickname, post_domain)
            if not allow_quotes:
                if post_json_object.get('id'):
                    print('REJECT: inbox quote toot ' +
                          nickname + '@' + domain + ' ' +
                          str(post_json_object['id']))
                return None

        # is this a reply to a blocked domain or account?
        reply_id = get_reply_to(post_json_object['object'])
        if reply_id:
            if isinstance(reply_id, str):
                in_reply_to = reply_id
                reply_domain, _ = \
                    get_domain_from_actor(in_reply_to)
                if reply_domain:
                    if is_blocked_domain(base_dir, reply_domain,
                                         blocked_cache, block_federated):
                        print('BLOCK: post contains reply from ' +
                              str(actor) +
                              ' to a blocked domain: ' + reply_domain)
                        return None

                reply_nickname = \
                    get_nickname_from_actor(in_reply_to)
                if reply_nickname and reply_domain:
                    if is_blocked_nickname(base_dir, reply_domain,
                                           blocked_cache):
                        print('BLOCK: post contains reply from ' +
                              str(actor) +
                              ' to a blocked nickname: ' +
                              reply_nickname + '@' + reply_domain)
                        return None
                    if is_blocked(base_dir, nickname, domain,
                                  reply_nickname, reply_domain,
                                  blocked_cache, block_federated):
                        print('BLOCK: post contains reply from ' +
                              str(actor) +
                              ' to a blocked account: ' +
                              reply_nickname + '@' + reply_domain)
                        return None
                    if _deny_non_follower(base_dir, nickname, domain,
                                          reply_nickname, reply_domain,
                                          actor):
                        print('REJECT: post contains reply from ' +
                              str(actor) +
                              ' who is not a follower of ' +
                              nickname + '@' + domain)
                        return None

    # filter on the content of the post
    if content_str:
        summary_str = \
            get_summary_from_post(post_json_object,
                                  system_language, [])
        media_descriptions = \
            get_media_descriptions_from_post(post_json_object)
        content_all = \
            summary_str + ' ' + content_str + ' ' + media_descriptions
        if is_filtered(base_dir, nickname, domain, content_all,
                       system_language):
            if post_json_object.get('id'):
                print('REJECT: post was filtered out due to content ' +
                      str(post_json_object['id']))
            return None
        if reject_twitter_summary(base_dir, nickname, domain,
                                  summary_str):
            if post_json_object.get('id'):
                print('REJECT: post was filtered out due to ' +
                      'twitter summary ' + str(post_json_object['id']))
            return None

    original_post_id = None
    if post_json_object.get('id'):
        if not isinstance(post_json_object['id'], str):
            print('REJECT: post id is not a string ' +
                  str(post_json_object['id']))
            return None
        original_post_id = remove_id_ending(post_json_object['id'])

    curr_time = date_utcnow()

    post_id = None
    published = ''
    if post_json_object.get('id'):
        post_id = remove_id_ending(post_json_object['id'])
        published = curr_time.strftime("%Y-%m-%dT%H:%M:%SZ")
    if not post_id:
        status_number, published = get_status_number()
        if actor:
            post_id = actor + '/statuses/' + status_number
        else:
            post_id = \
                local_actor_url(http_prefix, nickname, original_domain) + \
                '/statuses/' + status_number

    if not published:
        return None

    # NOTE: don't change post_json_object['id'] before signature check

    inbox_queue_dir = create_inbox_queue_dir(nickname, domain, base_dir)

    handle = nickname + '@' + domain
    destination = data_dir(base_dir) + '/' + \
        handle + '/inbox/' + post_id.replace('/', '#') + '.json'
    filename = inbox_queue_dir + '/' + post_id.replace('/', '#') + '.json'

    shared_inbox_item = False
    if nickname == 'inbox':
        nickname = original_domain
        shared_inbox_item = True

    digest_start_time = time.time()
    digest_algorithm = get_digest_algorithm_from_headers(http_headers)
    digest = message_content_digest(message_bytes, digest_algorithm)
    time_diff_str = str(int((time.time() - digest_start_time) * 1000))
    if debug:
        while len(time_diff_str) < 6:
            time_diff_str = '0' + time_diff_str
        print('DIGEST|' + time_diff_str + '|' + filename)

    new_queue_item = {
        "originalId": original_post_id,
        "id": post_id,
        "actor": actor,
        "nickname": nickname,
        "domain": domain,
        "postNickname": post_nickname,
        "postDomain": post_domain,
        "sharedInbox": shared_inbox_item,
        "published": published,
        "httpHeaders": http_headers,
        "path": post_path,
        "post": post_json_object,
        "original": original_post_json_object,
        "digest": digest,
        "filename": filename,
        "destination": destination,
        "mitm": mitm
    }

    if debug:
        print('Inbox queue item created')
    save_json(new_queue_item, filename)
    return filename


def _inbox_post_recipients_add(base_dir: str, to_list: [],
                               recipients_dict: {},
                               domain_match: str, domain: str,
                               debug: bool,
                               onion_domain: str, i2p_domain: str) -> bool:
    """Given a list of post recipients (to_list) from 'to' or 'cc' parameters
    populate a recipients_dict with the handle for each
    """
    follower_recipients = False
    for recipient in to_list:
        if not recipient:
            continue
        # if the recipient is an onion or i2p address then
        # is it an account on a clearnet instance?
        # If so then change the onion/i2p to the account domain
        if onion_domain:
            if onion_domain + '/' in recipient:
                recipient = recipient.replace(onion_domain, domain)
        if i2p_domain:
            if i2p_domain + '/' in recipient:
                recipient = recipient.replace(i2p_domain, domain)
        # is this a to an account on this instance?
        if domain_match in recipient:
            # get the handle for the account on this instance
            nickname = recipient.split(domain_match)[1]
            handle = nickname + '@' + domain
            handle_dir = acct_handle_dir(base_dir, handle)
            if os.path.isdir(handle_dir):
                recipients_dict[handle] = None
            else:
                if debug:
                    print('DEBUG: ' + data_dir(base_dir) + '/' +
                          handle + ' does not exist')
        else:
            if debug:
                if recipient.endswith('#Public') or \
                   recipient == 'as:Public' or \
                   recipient == 'Public':
                    print('DEBUG: #Public recipient is too non-specific. ' +
                          recipient + ' ' + domain_match)
                else:
                    print('DEBUG: ' + recipient + ' is not local to ' +
                          domain_match)
                print(str(to_list))
        if recipient.endswith('followers'):
            if debug:
                print('DEBUG: followers detected as post recipients')
            follower_recipients = True
    return follower_recipients, recipients_dict


def _inbox_post_recipients(base_dir: str, post_json_object: {},
                           domain: str, port: int,
                           debug: bool,
                           onion_domain: str, i2p_domain: str) -> ([], []):
    """Returns dictionaries containing the recipients of the given post
    The shared dictionary contains followers
    """
    recipients_dict = {}
    recipients_dict_followers = {}

    if not post_json_object.get('actor'):
        if debug:
            pprint(post_json_object)
            print('WARNING: inbox post has no actor')
        return recipients_dict, recipients_dict_followers

    domain = remove_domain_port(domain)
    domain_base = domain
    domain = get_full_domain(domain, port)
    domain_match = '/' + domain + '/users/'

    actor = get_actor_from_post(post_json_object)
    # first get any specific people which the post is addressed to

    follower_recipients = False
    if has_object_dict(post_json_object):
        if post_json_object['object'].get('to'):
            if isinstance(post_json_object['object']['to'], list):
                recipients_list = post_json_object['object']['to']
            else:
                recipients_list = [post_json_object['object']['to']]
            if debug:
                print('DEBUG: resolving "to"')
            includes_followers, recipients_dict = \
                _inbox_post_recipients_add(base_dir,
                                           recipients_list,
                                           recipients_dict,
                                           domain_match, domain_base,
                                           debug,
                                           onion_domain, i2p_domain)
            if includes_followers:
                follower_recipients = True
        else:
            if debug:
                print('DEBUG: inbox post has no "to"')

        if post_json_object['object'].get('cc'):
            if isinstance(post_json_object['object']['cc'], list):
                recipients_list = post_json_object['object']['cc']
            else:
                recipients_list = [post_json_object['object']['cc']]
            includes_followers, recipients_dict = \
                _inbox_post_recipients_add(base_dir,
                                           recipients_list,
                                           recipients_dict,
                                           domain_match, domain_base,
                                           debug,
                                           onion_domain, i2p_domain)
            if includes_followers:
                follower_recipients = True
        else:
            if debug:
                print('DEBUG: inbox post has no cc')
    else:
        if debug and post_json_object.get('object'):
            if isinstance(post_json_object['object'], str):
                if contains_statuses(post_json_object['object']):
                    print('DEBUG: inbox item is a link to a post')
                else:
                    if '/users/' in post_json_object['object']:
                        print('DEBUG: inbox item is a link to an actor')

    if post_json_object.get('to'):
        if isinstance(post_json_object['to'], list):
            recipients_list = post_json_object['to']
        else:
            recipients_list = [post_json_object['to']]
        includes_followers, recipients_dict = \
            _inbox_post_recipients_add(base_dir,
                                       recipients_list,
                                       recipients_dict,
                                       domain_match, domain_base,
                                       debug,
                                       onion_domain, i2p_domain)
        if includes_followers:
            follower_recipients = True

    if post_json_object.get('cc'):
        if isinstance(post_json_object['cc'], list):
            recipients_list = post_json_object['cc']
        else:
            recipients_list = [post_json_object['cc']]
        includes_followers, recipients_dict = \
            _inbox_post_recipients_add(base_dir,
                                       recipients_list,
                                       recipients_dict,
                                       domain_match, domain_base,
                                       debug,
                                       onion_domain, i2p_domain)
        if includes_followers:
            follower_recipients = True

    if not follower_recipients:
        if debug:
            print('DEBUG: no followers were resolved')
        return recipients_dict, recipients_dict_followers

    # now resolve the followers
    recipients_dict_followers = \
        get_followers_of_actor(base_dir, actor, debug)

    return recipients_dict, recipients_dict_followers


def update_edited_post(base_dir: str,
                       nickname: str, domain: str,
                       message_json: {},
                       edited_published: str,
                       edited_postid: str,
                       recent_posts_cache: {},
                       box_name: str,
                       max_mentions: int, max_emoji: int,
                       allow_local_network_access: bool,
                       debug: bool,
                       system_language: str, http_prefix: str,
                       domain_full: str, person_cache: {},
                       signing_priv_key_pem: str,
                       max_recent_posts: int, translate: {},
                       session, cached_webfingers: {}, port: int,
                       allow_deletion: bool,
                       yt_replace_domain: str,
                       twitter_replacement_domain: str,
                       show_published_date_only: bool,
                       peertube_instances: [],
                       theme_name: str, max_like_count: int,
                       cw_lists: {}, dogwhistles: {},
                       min_images_for_accounts: [],
                       max_hashtags: int,
                       buy_sites: {},
                       auto_cw_cache: {},
                       onion_domain: str,
                       i2p_domain: str,
                       mitm_servers: [],
                       instance_software: {}) -> None:
    """ When an edited post is created this assigns
    a published and updated date to it, and uses
    the previous id
    """
    edited_updated = \
        message_json['object']['published']
    if edited_published:
        message_json['published'] = \
            edited_published
        message_json['object']['published'] = \
            edited_published
    message_json['id'] = \
        edited_postid + '/activity'
    message_json['object']['id'] = \
        edited_postid
    message_json['object']['url'] = \
        edited_postid
    message_json['updated'] = \
        edited_updated
    message_json['object']['updated'] = \
        edited_updated
    message_json['type'] = 'Update'

    message_json2 = message_json.copy()
    receive_edit_to_post(recent_posts_cache,
                         message_json2,
                         base_dir,
                         nickname, domain,
                         max_mentions, max_emoji,
                         allow_local_network_access,
                         debug,
                         system_language, http_prefix,
                         domain_full, person_cache,
                         signing_priv_key_pem,
                         max_recent_posts,
                         translate,
                         session,
                         cached_webfingers,
                         port,
                         allow_deletion,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         peertube_instances,
                         theme_name, max_like_count,
                         cw_lists, dogwhistles,
                         min_images_for_accounts,
                         max_hashtags, buy_sites,
                         auto_cw_cache,
                         onion_domain, i2p_domain,
                         mitm_servers,
                         instance_software)

    # update the index
    id_str = edited_postid.split('/')[-1]
    index_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + box_name + '.index'
    if not text_in_file(id_str, index_filename):
        try:
            with open(index_filename, 'r+',
                      encoding='utf-8') as fp_index:
                content = fp_index.read()
                if id_str + '\n' not in content:
                    fp_index.seek(0, 0)
                    fp_index.write(id_str + '\n' + content)
        except OSError as ex:
            print('WARN: Failed to write index after edit ' +
                  index_filename + ' ' + str(ex))


def populate_replies(base_dir: str, http_prefix: str, domain: str,
                     message_json: {}, max_replies: int, debug: bool) -> bool:
    """Updates the list of replies for a post on this domain if
    a reply to it arrives
    """
    if not message_json.get('id'):
        return False
    if not has_object_dict(message_json):
        return False
    reply_to = get_reply_to(message_json['object'])
    if not reply_to:
        return False
    if not message_json['object'].get('to'):
        return False
    if not isinstance(reply_to, str):
        return False
    if debug:
        print('DEBUG: post contains a reply')
    # is this a reply to a post on this domain?
    if not reply_to.startswith(http_prefix + '://' + domain + '/'):
        if debug:
            print('DEBUG: post is a reply to another not on this domain')
            print(reply_to)
            print('Expected: ' + http_prefix + '://' + domain + '/')
        return False
    reply_to_nickname = get_nickname_from_actor(reply_to)
    if not reply_to_nickname:
        print('DEBUG: no nickname found for ' + reply_to)
        return False
    reply_to_domain, _ = get_domain_from_actor(reply_to)
    if not reply_to_domain:
        if debug:
            print('DEBUG: no domain found for ' + reply_to)
        return False

    post_filename = locate_post(base_dir, reply_to_nickname,
                                reply_to_domain, reply_to)
    if not post_filename:
        if debug:
            print('DEBUG: post may have expired - ' + reply_to)
        return False

    if not post_allow_comments(post_filename):
        if debug:
            print('DEBUG: post does not allow comments - ' + reply_to)
        return False
    # populate a text file containing the ids of replies
    post_replies_filename = post_filename.replace('.json', '.replies')
    message_id = remove_id_ending(message_json['id'])
    if os.path.isfile(post_replies_filename):
        num_lines = lines_in_file(post_replies_filename)
        if num_lines > max_replies:
            return False
        if not text_in_file(message_id, post_replies_filename):
            try:
                with open(post_replies_filename, 'a+',
                          encoding='utf-8') as fp_replies:
                    fp_replies.write(message_id + '\n')
            except OSError:
                print('EX: populate_replies unable to append ' +
                      post_replies_filename)
    else:
        try:
            with open(post_replies_filename, 'w+',
                      encoding='utf-8') as fp_replies:
                fp_replies.write(message_id + '\n')
        except OSError:
            print('EX: populate_replies unable to write ' +
                  post_replies_filename)
    return True


def _obtain_avatar_for_reply_post(session, base_dir: str, http_prefix: str,
                                  domain: str, onion_domain: str,
                                  i2p_domain: str,
                                  person_cache: {},
                                  post_json_object: {}, debug: bool,
                                  signing_priv_key_pem: str,
                                  mitm_servers: []) -> None:
    """Tries to obtain the actor for the person being replied to
    so that their avatar can later be shown
    """
    if not has_object_dict(post_json_object):
        return

    reply_id = get_reply_to(post_json_object['object'])
    if not reply_id:
        return

    lookup_actor = reply_id
    if not lookup_actor:
        return

    if not isinstance(lookup_actor, str):
        return

    if not has_users_path(lookup_actor):
        return

    if contains_statuses(lookup_actor):
        lookup_actor = get_actor_from_post_id(lookup_actor)

    if debug:
        print('DEBUG: Obtaining actor for reply post ' + lookup_actor)

    for tries in range(6):
        pub_key = \
            get_person_pub_key(base_dir, session, lookup_actor,
                               person_cache, debug,
                               __version__, http_prefix,
                               domain, onion_domain, i2p_domain,
                               signing_priv_key_pem,
                               mitm_servers)
        if pub_key:
            if not isinstance(pub_key, dict):
                if debug:
                    print('DEBUG: public key obtained for reply: ' +
                          lookup_actor)
            else:
                if debug:
                    print('DEBUG: http error code for public key ' +
                          'obtained for reply: ' + lookup_actor + ' ' +
                          str(pub_key))
            break

        if debug:
            print('DEBUG: Retry ' + str(tries + 1) +
                  ' obtaining actor for ' + lookup_actor)
        time.sleep(5)


def _dm_notify(base_dir: str, handle: str, url: str) -> None:
    """Creates a notification that a new DM has arrived
    """
    account_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(account_dir):
        return
    dm_file = account_dir + '/.newDM'
    if not os.path.isfile(dm_file):
        try:
            with open(dm_file, 'w+', encoding='utf-8') as fp_dm:
                fp_dm.write(url)
        except OSError:
            print('EX: _dm_notify unable to write ' + dm_file)


def _notify_post_arrival(base_dir: str, handle: str, url: str) -> None:
    """Creates a notification that a new post has arrived.
    This is for followed accounts with the notify checkbox enabled
    on the person options screen
    """
    account_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(account_dir):
        return
    notify_file = account_dir + '/.newNotifiedPost'
    if os.path.isfile(notify_file):
        # check that the same notification is not repeatedly sent
        try:
            with open(notify_file, 'r', encoding='utf-8') as fp_notify:
                existing_notification_message = fp_notify.read()
                if url in existing_notification_message:
                    return
        except OSError:
            print('EX: _notify_post_arrival unable to read ' + notify_file)
    try:
        with open(notify_file, 'w+', encoding='utf-8') as fp_notify:
            fp_notify.write(url)
    except OSError:
        print('EX: _notify_post_arrival unable to write ' + notify_file)


def _reply_notify(base_dir: str, handle: str, url: str) -> None:
    """Creates a notification that a new reply has arrived
    """
    account_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(account_dir):
        return
    reply_file = account_dir + '/.newReply'
    if not os.path.isfile(reply_file):
        try:
            with open(reply_file, 'w+', encoding='utf-8') as fp_reply:
                fp_reply.write(url)
        except OSError:
            print('EX: _reply_notify unable to write ' + reply_file)


def _git_patch_notify(base_dir: str, handle: str, subject: str,
                      from_nickname: str, from_domain: str) -> None:
    """Creates a notification that a new git patch has arrived
    """
    account_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(account_dir):
        return
    patch_file = account_dir + '/.newPatch'
    subject = subject.replace('[PATCH]', '').strip()
    handle = '@' + from_nickname + '@' + from_domain
    try:
        with open(patch_file, 'w+', encoding='utf-8') as fp_patch:
            fp_patch.write('git ' + handle + ' ' + subject)
    except OSError:
        print('EX: _git_patch_notify unable to write ' + patch_file)


def _group_handle(base_dir: str, handle: str) -> bool:
    """Is the given account handle a group?
    """
    actor_file = acct_handle_dir(base_dir, handle) + '.json'
    if not os.path.isfile(actor_file):
        return False
    actor_json = load_json(actor_file)
    if not actor_json:
        return False
    if not actor_json.get('type'):
        return False
    return actor_json['type'] == 'Group'


def _send_to_group_members(server, session, session_onion, session_i2p,
                           base_dir: str, handle: str, port: int,
                           post_json_object: {},
                           http_prefix: str, federation_list: [],
                           send_threads: [], post_log: [],
                           cached_webfingers: {},
                           person_cache: {}, debug: bool,
                           curr_domain: str,
                           onion_domain: str, i2p_domain: str,
                           signing_priv_key_pem: str,
                           sites_unavailable: [],
                           system_language: str,
                           mitm_servers: []) -> None:
    """When a post arrives for a group send it out to the group members
    """
    if debug:
        print('\n\n=========================================================')
        print(handle + ' sending to group members')

    shared_item_federation_tokens = {}
    shared_items_federated_domains: list[str] = []
    shared_items_federated_domains_str = \
        get_config_param(base_dir, 'shared_items_federated_domains')
    if shared_items_federated_domains_str:
        si_federated_domains_list = \
            shared_items_federated_domains_str.split(',')
        for shared_federated_domain in si_federated_domains_list:
            domain_str = shared_federated_domain.strip()
            shared_items_federated_domains.append(domain_str)

    followers_file = acct_handle_dir(base_dir, handle) + '/followers.txt'
    if not os.path.isfile(followers_file):
        return
    if not post_json_object.get('to'):
        return
    if not post_json_object.get('object'):
        return
    if not has_object_dict(post_json_object):
        return
    nickname = handle.split('@')[0].replace('!', '')
    domain = handle.split('@')[1]
    domain_full = get_full_domain(domain, port)
    group_actor = local_actor_url(http_prefix, nickname, domain_full)
    if isinstance(post_json_object['to'], list):
        if group_actor not in post_json_object['to']:
            return
    else:
        if group_actor != post_json_object['to']:
            return
    cc_str = ''
    nickname = handle.split('@')[0].replace('!', '')

    # save to the group outbox so that replies will be to the group
    # rather than the original sender
    save_post_to_box(base_dir, http_prefix, None,
                     nickname, domain, post_json_object, 'outbox')

    post_id = remove_id_ending(post_json_object['object']['id'])
    if debug:
        print('Group announce: ' + post_id)
    announce_json = \
        create_announce(session, base_dir, federation_list,
                        nickname, domain, port,
                        group_actor + '/followers', cc_str,
                        http_prefix, post_id, False, False,
                        send_threads, post_log,
                        person_cache, cached_webfingers,
                        debug, __version__, signing_priv_key_pem,
                        curr_domain, onion_domain, i2p_domain,
                        sites_unavailable, system_language,
                        mitm_servers)

    send_to_followers_thread(server, session, session_onion, session_i2p,
                             base_dir, nickname, domain,
                             onion_domain, i2p_domain, port,
                             http_prefix, federation_list,
                             send_threads, post_log,
                             cached_webfingers, person_cache,
                             announce_json, debug, __version__,
                             shared_items_federated_domains,
                             shared_item_federation_tokens,
                             signing_priv_key_pem,
                             sites_unavailable, system_language,
                             mitm_servers)


def _inbox_update_calendar_from_tag(base_dir: str, handle: str,
                                    post_json_object: {}) -> None:
    """Detects whether the tag list on a post contains calendar events
    and if so saves the post id to a file in the calendar directory
    for the account
    """
    if not post_json_object.get('actor'):
        return
    if not has_object_dict(post_json_object):
        return
    if not post_json_object['object'].get('tag'):
        return
    if not isinstance(post_json_object['object']['tag'], list):
        return

    actor = get_actor_from_post(post_json_object)
    actor_nickname = get_nickname_from_actor(actor)
    if not actor_nickname:
        return
    actor_domain, _ = get_domain_from_actor(actor)
    if not actor_domain:
        return
    handle_nickname = handle.split('@')[0]
    handle_domain = handle.split('@')[1]
    if not receiving_calendar_events(base_dir,
                                     handle_nickname, handle_domain,
                                     actor_nickname, actor_domain):
        return

    post_id = remove_id_ending(post_json_object['id']).replace('/', '#')

    # look for events within the tags list
    for tag_dict in post_json_object['object']['tag']:
        if not tag_dict.get('type'):
            continue
        if tag_dict['type'] != 'Event':
            continue
        if not tag_dict.get('startTime'):
            continue
        save_event_post(base_dir, handle, post_id, tag_dict)


def _inbox_update_calendar_from_event(base_dir: str, handle: str,
                                      post_json_object: {}) -> None:
    """Detects whether the post contains calendar events
    and if so saves the post id to a file in the calendar directory
    for the account
    This is for Friendica-style calendar events
    """
    if not post_json_object.get('actor'):
        return
    if not has_object_dict(post_json_object):
        return
    if post_json_object['object']['type'] != 'Event':
        return
    if not post_json_object['object'].get('startTime'):
        return
    if not isinstance(post_json_object['object']['startTime'], str):
        return

    actor = get_actor_from_post(post_json_object)
    actor_nickname = get_nickname_from_actor(actor)
    if not actor_nickname:
        return
    actor_domain, _ = get_domain_from_actor(actor)
    if not actor_domain:
        return
    handle_nickname = handle.split('@')[0]
    handle_domain = handle.split('@')[1]
    if not receiving_calendar_events(base_dir,
                                     handle_nickname, handle_domain,
                                     actor_nickname, actor_domain):
        return

    post_id = remove_id_ending(post_json_object['id']).replace('/', '#')
    save_event_post(base_dir, handle, post_id, post_json_object['object'])


def _update_last_seen(base_dir: str, handle: str, actor: str) -> None:
    """Updates the time when the given handle last saw the given actor
    This can later be used to indicate if accounts are dormant/abandoned/moved
    """
    if '@' not in handle:
        return
    nickname = handle.split('@')[0]
    domain = handle.split('@')[1]
    domain = remove_domain_port(domain)
    account_path = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(account_path):
        return
    if not is_following_actor(base_dir, nickname, domain, actor):
        return
    last_seen_path = account_path + '/lastseen'
    if not os.path.isdir(last_seen_path):
        os.mkdir(last_seen_path)
    last_seen_filename = \
        last_seen_path + '/' + actor.replace('/', '#') + '.txt'
    curr_time = date_utcnow()
    days_since_epoch = (curr_time - date_epoch()).days
    # has the value changed?
    if os.path.isfile(last_seen_filename):
        try:
            with open(last_seen_filename, 'r',
                      encoding='utf-8') as fp_last_seen:
                days_since_epoch_file = fp_last_seen.read()
                if int(days_since_epoch_file) == days_since_epoch:
                    # value hasn't changed, so we can save writing
                    # anything to file
                    return
        except OSError:
            print('EX: _update_last_seen unable to read ' + last_seen_filename)
    try:
        with open(last_seen_filename, 'w+',
                  encoding='utf-8') as fp_last_seen:
            fp_last_seen.write(str(days_since_epoch))
    except OSError:
        print('EX: _update_last_seen unable to write ' + last_seen_filename)


def _bounce_dm(sender_post_id: str, session, http_prefix: str,
               base_dir: str, nickname: str, domain: str, port: int,
               sending_handle: str, federation_list: [],
               send_threads: [], post_log: [],
               cached_webfingers: {}, person_cache: {},
               translate: {}, debug: bool,
               last_bounce_message: [], system_language: str,
               signing_priv_key_pem: str,
               dm_license_url: str,
               languages_understood: [],
               bounce_is_chat: bool,
               curr_domain: str, onion_domain: str, i2p_domain: str,
               sites_unavailable: [],
               mitm_servers: []) -> bool:
    """Sends a bounce message back to the sending handle
    if a DM has been rejected
    """
    print(nickname + '@' + domain +
          ' cannot receive DM from ' + sending_handle +
          ' because they do not follow them')

    # Don't send out bounce messages too frequently.
    # Otherwise an adversary could try to DoS your instance
    # by continuously sending DMs to you
    curr_time = int(time.time())
    if curr_time - last_bounce_message[0] < 60:
        return False

    # record the last time that a bounce was generated
    last_bounce_message[0] = curr_time

    sender_nickname = sending_handle.split('@')[0]
    group_account = False
    if sending_handle.startswith('!'):
        sending_handle = sending_handle[1:]
        group_account = True
    sender_domain = sending_handle.split('@')[1]
    sender_port = port
    if ':' in sender_domain:
        sender_port = get_port_from_domain(sender_domain)
        sender_domain = remove_domain_port(sender_domain)

    # create the bounce DM
    subject = None
    content = translate['DM bounce']
    save_to_file = False
    client_to_server = False
    comments_enabled = False
    attach_image_filename = None
    media_type = None
    image_description = ''
    video_transcript = None
    city = 'London, England'
    in_reply_to = remove_id_ending(sender_post_id)
    in_reply_to_atom_uri = None
    schedule_post = False
    event_date = None
    event_time = None
    event_end_time = None
    location = None
    conversation_id = None
    convthread_id = None
    low_bandwidth = False
    buy_url = ''
    chat_url = ''
    auto_cw_cache = {}
    post_json_object = \
        create_direct_message_post(base_dir, nickname, domain, port,
                                   http_prefix, content,
                                   save_to_file, client_to_server,
                                   comments_enabled,
                                   attach_image_filename, media_type,
                                   image_description, video_transcript, city,
                                   in_reply_to, in_reply_to_atom_uri,
                                   subject, debug, schedule_post,
                                   event_date, event_time, event_end_time,
                                   location, system_language, conversation_id,
                                   convthread_id,
                                   low_bandwidth, dm_license_url,
                                   dm_license_url, '',
                                   languages_understood, bounce_is_chat,
                                   translate, buy_url, chat_url,
                                   auto_cw_cache, session)
    if not post_json_object:
        print('WARN: unable to create bounce message to ' + sending_handle)
        return False
    extra_headers = {}
    # bounce DM goes back to the sender
    print('Sending bounce DM to ' + sending_handle)
    send_signed_json(post_json_object, session, base_dir,
                     nickname, domain, port,
                     sender_nickname, sender_domain, sender_port,
                     http_prefix, False, federation_list,
                     send_threads, post_log, cached_webfingers,
                     person_cache, debug, __version__, None, group_account,
                     signing_priv_key_pem, 7238634,
                     curr_domain, onion_domain, i2p_domain,
                     extra_headers, sites_unavailable, system_language,
                     mitm_servers)
    return True


def _is_valid_dm(base_dir: str, nickname: str, domain: str, port: int,
                 post_json_object: {}, update_index_list: [],
                 session, http_prefix: str,
                 federation_list: [],
                 send_threads: [], post_log: [],
                 cached_webfingers: {},
                 person_cache: {},
                 translate: {}, debug: bool,
                 last_bounce_message: [],
                 handle: str, system_language: str,
                 signing_priv_key_pem: str,
                 dm_license_url: str,
                 languages_understood: [],
                 curr_domain: str, onion_domain: str, i2p_domain: str,
                 sites_unavailable: [],
                 mitm_servers: []) -> bool:
    """Is the given message a valid DM?
    """
    if nickname == 'inbox':
        # going to the shared inbox
        return True

    # check for the flag file which indicates to
    # only receive DMs from people you are following
    follow_dms_filename = acct_dir(base_dir, nickname, domain) + '/.followDMs'
    if not os.path.isfile(follow_dms_filename):
        # dm index will be updated
        update_index_list.append('dm')
        act_url = local_actor_url(http_prefix, nickname, domain)
        _dm_notify(base_dir, handle, act_url + '/dm')
        return True

    # get the file containing following handles
    following_filename = \
        acct_dir(base_dir, nickname, domain) + '/following.txt'
    # who is sending a DM?
    if not post_json_object.get('actor'):
        return False
    sending_actor = get_actor_from_post(post_json_object)
    sending_actor_nickname = \
        get_nickname_from_actor(sending_actor)
    if not sending_actor_nickname:
        return False
    sending_actor_domain, _ = \
        get_domain_from_actor(sending_actor)
    if not sending_actor_domain:
        return False
    # Is this DM to yourself? eg. a reminder
    sending_to_self = False
    if sending_actor_nickname == nickname and \
       sending_actor_domain == domain:
        sending_to_self = True

    # check that the following file exists
    if not sending_to_self:
        if not os.path.isfile(following_filename):
            print('No following.txt file exists for ' +
                  nickname + '@' + domain +
                  ' so not accepting DM from ' +
                  sending_actor_nickname + '@' +
                  sending_actor_domain)
            return False

    # Not sending to yourself
    if not sending_to_self:
        # is this a vote on a question?
        if is_vote(base_dir, nickname, domain,
                   post_json_object, debug):
            # make the content the same as the vote answer
            post_json_object['object']['content'] = \
                post_json_object['object']['name']
            # remove any other content
            if post_json_object['object'].get("contentMap"):
                del post_json_object['object']['contentMap']
            # remove any summary / cw
            post_json_object['object']['summary'] = None
            if post_json_object['object'].get("summaryMap"):
                del post_json_object['object']['summaryMap']
            return True
        # get the handle of the DM sender
        send_h = sending_actor_nickname + '@' + sending_actor_domain
        # check the follow
        if not is_following_actor(base_dir, nickname, domain, send_h):
            # DMs may always be allowed from some domains
            if not dm_allowed_from_domain(base_dir,
                                          nickname, domain,
                                          sending_actor_domain):
                # send back a bounce DM
                if post_json_object.get('id') and \
                   post_json_object.get('object'):
                    obj_has_dict = has_object_dict(post_json_object)
                    # don't send bounces back to
                    # replies to bounce messages
                    obj = post_json_object['object']
                    if obj_has_dict and \
                       not get_reply_to(obj):
                        bounced_id = \
                            remove_id_ending(post_json_object['id'])
                        bounce_chat = False
                        if obj.get('type'):
                            if obj['type'] == 'ChatMessage':
                                bounce_chat = True
                        _bounce_dm(bounced_id,
                                   session, http_prefix,
                                   base_dir,
                                   nickname, domain,
                                   port, send_h,
                                   federation_list,
                                   send_threads, post_log,
                                   cached_webfingers,
                                   person_cache,
                                   translate, debug,
                                   last_bounce_message,
                                   system_language,
                                   signing_priv_key_pem,
                                   dm_license_url,
                                   languages_understood,
                                   bounce_chat,
                                   curr_domain,
                                   onion_domain, i2p_domain,
                                   sites_unavailable,
                                   mitm_servers)
                return False

    # dm index will be updated
    update_index_list.append('dm')
    act_url = local_actor_url(http_prefix, nickname, domain)
    _dm_notify(base_dir, handle, act_url + '/dm')
    return True


def _create_reply_notification_file(base_dir: str, nickname: str, domain: str,
                                    handle: str, debug: bool, post_is_dm: bool,
                                    post_json_object: {}, actor: str,
                                    update_index_list: [], http_prefix: str,
                                    default_reply_interval_hrs: int) -> bool:
    """Generates a file indicating that a new reply has arrived
    The file can then be used by other systems to create a notification
    xmpp, matrix, email, etc
    """
    is_reply_to_muted_post = False
    if post_is_dm:
        return is_reply_to_muted_post
    if not is_reply(post_json_object, actor):
        return is_reply_to_muted_post
    if nickname == 'inbox':
        return is_reply_to_muted_post
    # replies index will be updated
    update_index_list.append('tlreplies')

    # Due to lack of AP specification maintenance, a conversation can also be
    # referred to as a thread or (confusingly) "context"
    conversation_id = None
    if post_json_object['object'].get('conversation'):
        conversation_id = post_json_object['object']['conversation']
    elif post_json_object['object'].get('context'):
        conversation_id = post_json_object['object']['context']
    elif post_json_object['object'].get('thread'):
        conversation_id = post_json_object['object']['thread']

    # is there a post being replied to?
    in_reply_to = get_reply_to(post_json_object['object'])
    if not in_reply_to:
        return is_reply_to_muted_post
    if not isinstance(in_reply_to, str):
        return is_reply_to_muted_post

    # is this your own reply?
    if post_json_object['object'].get('attributedTo'):
        attrib = get_attributed_to(post_json_object['object']['attributedTo'])
        if attrib:
            if attrib == actor:
                # no need to notify about your own replies
                return False

    if not is_muted_conv(base_dir, nickname, domain, in_reply_to,
                         conversation_id):
        # check if the reply is within the allowed time period
        # after publication
        reply_interval_hours = \
            get_reply_interval_hours(base_dir, nickname, domain,
                                     default_reply_interval_hrs)
        if can_reply_to(base_dir, nickname, domain, in_reply_to,
                        reply_interval_hours):
            act_url = local_actor_url(http_prefix, nickname, domain)
            _reply_notify(base_dir, handle, act_url + '/tlreplies')
        else:
            if debug:
                print('Reply to ' + in_reply_to + ' is outside of the ' +
                      'permitted interval of ' + str(reply_interval_hours) +
                      ' hours')
            return False
    else:
        is_reply_to_muted_post = True
    return is_reply_to_muted_post


def _low_frequency_post_notification(base_dir: str, http_prefix: str,
                                     nickname: str, domain: str,
                                     port: int, handle: str,
                                     post_is_dm: bool, json_obj: {}) -> None:
    """Should we notify that a post from this person has arrived?
    This is for cases where the notify checkbox is enabled on the
    person options screen
    """
    if post_is_dm:
        return
    if not json_obj:
        return
    if not json_obj.get('attributedTo'):
        return
    if not json_obj.get('id'):
        return
    attributed_to = get_attributed_to(json_obj['attributedTo'])
    if not attributed_to:
        return
    from_nickname = get_nickname_from_actor(attributed_to)
    if not from_nickname:
        return
    from_domain, from_port = get_domain_from_actor(attributed_to)
    if not from_domain:
        return
    from_domain_full = get_full_domain(from_domain, from_port)
    if notify_when_person_posts(base_dir, nickname, domain,
                                from_nickname, from_domain_full):
        post_id = remove_id_ending(json_obj['id'])
        dom_full = get_full_domain(domain, port)
        post_link = \
            local_actor_url(http_prefix, nickname, dom_full) + \
            '?notifypost=' + post_id.replace('/', '-')
        _notify_post_arrival(base_dir, handle, post_link)


def _check_for_git_patches(base_dir: str, nickname: str, domain: str,
                           handle: str, json_obj: {}) -> int:
    """check for incoming git patches
    """
    if not json_obj:
        return 0
    if not json_obj.get('content'):
        return 0
    if not json_obj.get('summary'):
        return 0
    if not json_obj.get('attributedTo'):
        return 0
    attributed_to = get_attributed_to(json_obj['attributedTo'])
    if not attributed_to:
        return 0
    from_nickname = get_nickname_from_actor(attributed_to)
    if not from_nickname:
        return 0
    from_domain, from_port = get_domain_from_actor(attributed_to)
    if not from_domain:
        return 0
    from_domain_full = get_full_domain(from_domain, from_port)
    if receive_git_patch(base_dir, nickname, domain,
                         json_obj['type'], json_obj['summary'],
                         json_obj['content'],
                         from_nickname, from_domain_full):
        _git_patch_notify(base_dir, handle, json_obj['summary'],
                          from_nickname, from_domain_full)
        return 1
    if '[PATCH]' in json_obj['content']:
        print('WARN: git patch not accepted - ' + json_obj['summary'])
        return 2
    return 0


def _has_former_representations(post_json_object: {}) -> bool:
    """Does the given post contain a list of previous edits?
    """
    post_obj = post_json_object['object']
    if not isinstance(post_obj, dict):
        return False
    if not post_obj.get('id'):
        return False
    if not post_obj.get('formerRepresentations'):
        return False
    if not isinstance(post_obj['formerRepresentations'], dict):
        return False
    if not post_obj['formerRepresentations'].get('orderedItems'):
        return False
    if not isinstance(post_obj['formerRepresentations']['orderedItems'],
                      list):
        return False
    return True


def _former_representations_to_edits(base_dir: str,
                                     nickname: str, domain: str,
                                     post_json_object: {},
                                     max_mentions: int,
                                     max_emoji: int,
                                     allow_local_network_access: bool,
                                     debug: bool,
                                     system_language: str,
                                     http_prefix: str,
                                     domain_full: str, person_cache: {},
                                     max_hashtags: int,
                                     port: int,
                                     onion_domain: str,
                                     i2p_domain: str) -> bool:
    """ Some instances use formerRepresentations to store
    previous edits
    """
    if not _has_former_representations(post_json_object):
        return False
    post_obj = post_json_object['object']
    prev_edits_list = post_obj['formerRepresentations']['orderedItems']

    post_id = remove_id_ending(post_obj['id'])
    post_filename = \
        locate_post(base_dir, nickname, domain, post_id, False)
    if not post_filename:
        return False
    post_history_filename = post_filename.replace('.json', '.edits')

    post_history_json = {}
    if os.path.isfile(post_history_filename):
        post_history_json = load_json(post_history_filename)

    # check each former post and add it to the edits file if needed
    posts_added = False
    for prev_post_json in prev_edits_list:
        prev_post_obj = prev_post_json
        if has_object_dict(prev_post_json):
            prev_post_obj = prev_post_json['object']

        # get the published date for the previous post
        if not prev_post_obj.get('published'):
            continue
        published_str = prev_post_obj['published']

        # was the previous post already logged?
        if post_history_json.get(published_str):
            continue

        # add Create to the previous post if needed
        if not has_object_dict(prev_post_json):
            prev_post_id = None
            if prev_post_json.get('id'):
                prev_post_id = prev_post_json['id']

            outbox_message_create_wrap(http_prefix,
                                       nickname, domain, port,
                                       prev_post_json)
            if prev_post_id:
                prev_post_json['id'] = prev_post_id
                prev_post_json['object']['id'] = prev_post_id
                prev_post_json['object']['url'] = prev_post_id
                prev_post_json['object']['atomUri'] = prev_post_id

        # validate the previous post
        harmless_markup(prev_post_json)
        if not valid_post_content(base_dir, nickname, domain,
                                  prev_post_json,
                                  max_mentions, max_emoji,
                                  allow_local_network_access, debug,
                                  system_language, http_prefix,
                                  domain_full, person_cache,
                                  max_hashtags, onion_domain, i2p_domain):
            continue

        post_history_json[published_str] = prev_post_json
        posts_added = True

    if posts_added:
        save_json(post_history_json, post_history_filename)
        print('formerRepresentations updated for ' + post_filename)
    return True


def _inbox_after_initial(server, inbox_start_time,
                         recent_posts_cache: {}, max_recent_posts: int,
                         session, session_onion, session_i2p,
                         key_id: str, handle: str, message_json: {},
                         base_dir: str, http_prefix: str, send_threads: [],
                         post_log: [], cached_webfingers: {}, person_cache: {},
                         domain: str, onion_domain: str, i2p_domain: str,
                         port: int, federation_list: [], debug: bool,
                         queue_filename: str, destination_filename: str,
                         max_replies: int, allow_deletion: bool,
                         max_mentions: int, max_emoji: int, translate: {},
                         unit_test: bool,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         show_published_date_only: bool,
                         allow_local_network_access: bool,
                         peertube_instances: [],
                         last_bounce_message: [],
                         theme_name: str, system_language: str,
                         max_like_count: int,
                         signing_priv_key_pem: str,
                         default_reply_interval_hrs: int,
                         cw_lists: {}, lists_enabled: str,
                         dm_license_url: str,
                         languages_understood: [],
                         mitm: bool, bold_reading: bool,
                         dogwhistles: {},
                         max_hashtags: int, buy_sites: {},
                         sites_unavailable: [],
                         mitm_servers: [],
                         instance_software: {}) -> bool:
    """ Anything which needs to be done after initial checks have passed
    """
    # if this is a clearnet instance then replace any onion/i2p
    # domains with the account domain
    if onion_domain or i2p_domain:
        message_str = json.dumps(message_json, ensure_ascii=False)
        if onion_domain:
            if onion_domain in message_str:
                message_str = message_str.replace(onion_domain, domain)
                try:
                    message_json = json.loads(message_str)
                except json.decoder.JSONDecodeError as ex:
                    print('EX: json decode error ' + str(ex) +
                          ' from _inbox_after_initial onion ' +
                          str(message_str))
                    inbox_start_time = time.time()
                    return False
        if i2p_domain:
            if i2p_domain in message_str:
                message_str = message_str.replace(i2p_domain, domain)
                try:
                    message_json = json.loads(message_str)
                except json.decoder.JSONDecodeError as ex:
                    print('EX: json decode error ' + str(ex) +
                          ' from _inbox_after_initial i2p ' +
                          str(message_str))
                    inbox_start_time = time.time()
                    return False

    actor = key_id
    if '#' in actor:
        actor = key_id.split('#')[0]

    _update_last_seen(base_dir, handle, actor)

    post_is_dm = False
    is_group = _group_handle(base_dir, handle)
    fitness_performance(inbox_start_time, server.fitness,
                        'INBOX', '_group_handle',
                        debug)
    inbox_start_time = time.time()

    handle_name = handle.split('@')[0]

    if receive_like(recent_posts_cache,
                    session, handle,
                    base_dir, http_prefix,
                    domain, port,
                    onion_domain, i2p_domain,
                    cached_webfingers,
                    person_cache,
                    message_json,
                    debug, signing_priv_key_pem,
                    max_recent_posts, translate,
                    allow_deletion,
                    yt_replace_domain,
                    twitter_replacement_domain,
                    peertube_instances,
                    allow_local_network_access,
                    theme_name, system_language,
                    max_like_count, cw_lists, lists_enabled,
                    bold_reading, dogwhistles,
                    server.min_images_for_accounts,
                    buy_sites, server.auto_cw_cache,
                    mitm_servers, instance_software):
        if debug:
            print('DEBUG: Like accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_like',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_undo_like(recent_posts_cache,
                         session, handle,
                         base_dir, http_prefix,
                         domain, port,
                         cached_webfingers,
                         person_cache,
                         message_json,
                         debug, signing_priv_key_pem,
                         max_recent_posts, translate,
                         allow_deletion,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         peertube_instances,
                         allow_local_network_access,
                         theme_name, system_language,
                         max_like_count, cw_lists, lists_enabled,
                         bold_reading, dogwhistles,
                         server.min_images_for_accounts,
                         buy_sites, server.auto_cw_cache,
                         mitm_servers, instance_software):
        if debug:
            print('DEBUG: Undo like accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_undo_like',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_reaction(recent_posts_cache,
                        session, handle,
                        base_dir, http_prefix,
                        domain, port,
                        onion_domain,
                        cached_webfingers,
                        person_cache,
                        message_json,
                        debug, signing_priv_key_pem,
                        max_recent_posts, translate,
                        allow_deletion,
                        yt_replace_domain,
                        twitter_replacement_domain,
                        peertube_instances,
                        allow_local_network_access,
                        theme_name, system_language,
                        max_like_count, cw_lists, lists_enabled,
                        bold_reading, dogwhistles,
                        server.min_images_for_accounts,
                        buy_sites, server.auto_cw_cache,
                        mitm_servers, instance_software):
        if debug:
            print('DEBUG: Reaction accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_reaction',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_zot_reaction(recent_posts_cache,
                            session, handle,
                            base_dir, http_prefix,
                            domain, port,
                            onion_domain,
                            cached_webfingers,
                            person_cache,
                            message_json,
                            debug, signing_priv_key_pem,
                            max_recent_posts, translate,
                            allow_deletion,
                            yt_replace_domain,
                            twitter_replacement_domain,
                            peertube_instances,
                            allow_local_network_access,
                            theme_name, system_language,
                            max_like_count, cw_lists, lists_enabled,
                            bold_reading, dogwhistles,
                            server.min_images_for_accounts,
                            buy_sites, server.auto_cw_cache,
                            mitm_servers, instance_software):
        if debug:
            print('DEBUG: Zot reaction accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_zot_reaction',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_undo_reaction(recent_posts_cache,
                             session, handle,
                             base_dir, http_prefix,
                             domain, port,
                             cached_webfingers,
                             person_cache,
                             message_json,
                             debug, signing_priv_key_pem,
                             max_recent_posts, translate,
                             allow_deletion,
                             yt_replace_domain,
                             twitter_replacement_domain,
                             peertube_instances,
                             allow_local_network_access,
                             theme_name, system_language,
                             max_like_count, cw_lists, lists_enabled,
                             bold_reading, dogwhistles,
                             server.min_images_for_accounts,
                             buy_sites, server.auto_cw_cache,
                             mitm_servers, instance_software):
        if debug:
            print('DEBUG: Undo reaction accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_undo_reaction',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_bookmark(recent_posts_cache,
                        session, handle,
                        base_dir, http_prefix,
                        domain, port,
                        cached_webfingers,
                        person_cache,
                        message_json,
                        debug, signing_priv_key_pem,
                        max_recent_posts, translate,
                        allow_deletion,
                        yt_replace_domain,
                        twitter_replacement_domain,
                        peertube_instances,
                        allow_local_network_access,
                        theme_name, system_language,
                        max_like_count, cw_lists, lists_enabled,
                        bold_reading, dogwhistles,
                        server.min_images_for_accounts,
                        server.buy_sites,
                        server.auto_cw_cache,
                        mitm_servers, instance_software):
        if debug:
            print('DEBUG: Bookmark accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_bookmark',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_undo_bookmark(recent_posts_cache,
                             session, handle,
                             base_dir, http_prefix,
                             domain, port,
                             cached_webfingers,
                             person_cache,
                             message_json,
                             debug, signing_priv_key_pem,
                             max_recent_posts, translate,
                             allow_deletion,
                             yt_replace_domain,
                             twitter_replacement_domain,
                             peertube_instances,
                             allow_local_network_access,
                             theme_name, system_language,
                             max_like_count, cw_lists, lists_enabled,
                             bold_reading, dogwhistles,
                             server.min_images_for_accounts,
                             server.buy_sites,
                             server.auto_cw_cache,
                             mitm_servers, instance_software):
        if debug:
            print('DEBUG: Undo bookmark accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_undo_bookmark',
                            debug)
        inbox_start_time = time.time()
        return False

    if is_create_inside_announce(message_json):
        message_json = message_json['object']
    fitness_performance(inbox_start_time, server.fitness,
                        'INBOX', 'is_create_inside_announce',
                        debug)
    inbox_start_time = time.time()

    # store any bookwyrm type notes
    store_book_events(base_dir,
                      message_json,
                      system_language,
                      languages_understood,
                      translate, debug,
                      server.max_recent_books,
                      server.books_cache,
                      server.max_cached_readers)

    if receive_announce(recent_posts_cache,
                        session, handle,
                        base_dir, http_prefix,
                        domain, onion_domain, i2p_domain, port,
                        cached_webfingers,
                        person_cache,
                        message_json,
                        debug, translate,
                        yt_replace_domain,
                        twitter_replacement_domain,
                        allow_local_network_access,
                        theme_name, system_language,
                        signing_priv_key_pem,
                        max_recent_posts,
                        allow_deletion,
                        peertube_instances,
                        max_like_count, cw_lists, lists_enabled,
                        bold_reading, dogwhistles, mitm,
                        server.min_images_for_accounts,
                        server.buy_sites,
                        languages_understood,
                        server.auto_cw_cache,
                        server.block_federated,
                        mitm_servers, instance_software):
        if debug:
            print('DEBUG: Announce accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_announce',
                            debug)
        inbox_start_time = time.time()

    if receive_undo_announce(recent_posts_cache,
                             handle, base_dir, domain,
                             message_json, debug):
        if debug:
            print('DEBUG: Undo announce accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_undo_announce',
                            debug)
        inbox_start_time = time.time()
        return False

    if receive_delete(handle,
                      base_dir, http_prefix,
                      domain, port,
                      message_json,
                      debug, allow_deletion,
                      recent_posts_cache):
        if debug:
            print('DEBUG: Delete accepted from ' + actor)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_delete',
                            debug)
        inbox_start_time = time.time()
        return False

    if debug:
        print('DEBUG: initial checks passed')
        print('copy queue file from ' + queue_filename +
              ' to ' + destination_filename)

    if os.path.isfile(destination_filename):
        return True

    if message_json.get('postNickname'):
        post_json_object = message_json['post']
    else:
        post_json_object = message_json
    nickname = handle.split('@')[0]

    if is_vote(base_dir, nickname, domain, post_json_object, debug):
        receive_question_vote(server, base_dir, nickname, domain,
                              http_prefix, handle, debug,
                              post_json_object, recent_posts_cache,
                              session, session_onion, session_i2p,
                              onion_domain, i2p_domain, port,
                              federation_list, send_threads, post_log,
                              cached_webfingers, person_cache,
                              signing_priv_key_pem,
                              max_recent_posts, translate,
                              allow_deletion,
                              yt_replace_domain,
                              twitter_replacement_domain,
                              peertube_instances,
                              allow_local_network_access,
                              theme_name, system_language,
                              max_like_count,
                              cw_lists, lists_enabled,
                              bold_reading, dogwhistles,
                              server.min_images_for_accounts,
                              server.buy_sites,
                              server.sites_unavailable,
                              server.auto_cw_cache,
                              mitm_servers, instance_software)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_receive_question_vote',
                            debug)
        inbox_start_time = time.time()

    json_obj = None
    domain_full = get_full_domain(domain, port)
    convert_post_content_to_html(post_json_object)

    # neutralise anything harmful
    harmless_markup(post_json_object)

    if valid_post_content(base_dir, nickname, domain,
                          post_json_object, max_mentions, max_emoji,
                          allow_local_network_access, debug,
                          system_language, http_prefix,
                          domain_full, person_cache,
                          max_hashtags, onion_domain, i2p_domain):
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'valid_post_content',
                            debug)
        inbox_start_time = time.time()
        # is the sending actor valid?
        if not valid_sending_actor(session, base_dir, nickname, domain,
                                   person_cache, post_json_object,
                                   signing_priv_key_pem, debug, unit_test,
                                   system_language, mitm_servers):
            if debug:
                print('Inbox sending actor is not valid ' +
                      str(post_json_object))
                fitness_performance(inbox_start_time, server.fitness,
                                    'INBOX', 'not_valid_sending_actor',
                                    debug)
                inbox_start_time = time.time()
            return False
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'valid_sending_actor',
                            debug)
        inbox_start_time = time.time()

        if post_json_object.get('object'):
            json_obj = post_json_object['object']
            if not isinstance(json_obj, dict):
                json_obj = None
        else:
            json_obj = post_json_object

        if _check_for_git_patches(base_dir, nickname, domain,
                                  handle, json_obj) == 2:
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_check_for_git_patches',
                                debug)
            inbox_start_time = time.time()
            return False

        # replace YouTube links, so they get less tracking data
        replace_you_tube(post_json_object, yt_replace_domain, system_language)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'replace_you_tube',
                            debug)
        inbox_start_time = time.time()
        # replace twitter link domains, so that you can view twitter posts
        # without having an account
        replace_twitter(post_json_object, twitter_replacement_domain,
                        system_language)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'replace_you_twitter',
                            debug)
        inbox_start_time = time.time()

        # list of indexes to be updated
        update_index_list = ['inbox']
        populate_replies(base_dir, http_prefix, domain, post_json_object,
                         max_replies, debug)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'populate_replies',
                            debug)
        inbox_start_time = time.time()

        is_reply_to_muted_post = False

        if not is_group:
            # create a DM notification file if needed
            post_is_dm = is_dm(post_json_object)
            if post_is_dm:
                if not _is_valid_dm(base_dir, nickname, domain, port,
                                    post_json_object, update_index_list,
                                    session, http_prefix,
                                    federation_list,
                                    send_threads, post_log,
                                    cached_webfingers,
                                    person_cache,
                                    translate, debug,
                                    last_bounce_message,
                                    handle, system_language,
                                    signing_priv_key_pem,
                                    dm_license_url,
                                    languages_understood,
                                    domain,
                                    onion_domain, i2p_domain,
                                    server.sites_unavailable,
                                    mitm_servers):
                    if debug:
                        print('Invalid DM ' + str(post_json_object))
                    return False
                fitness_performance(inbox_start_time, server.fitness,
                                    'INBOX', '_is_valid_dm',
                                    debug)
                inbox_start_time = time.time()

            # get the actor being replied to
            actor = local_actor_url(http_prefix, nickname, domain_full)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'local_actor_url',
                                debug)
            inbox_start_time = time.time()

            # create a reply notification file if needed
            is_reply_to_muted_post = \
                _create_reply_notification_file(base_dir, nickname, domain,
                                                handle, debug, post_is_dm,
                                                post_json_object, actor,
                                                update_index_list, http_prefix,
                                                default_reply_interval_hrs)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_create_reply_notification_file',
                                debug)
            inbox_start_time = time.time()

            show_vote_posts = True
            show_vote_file = acct_dir(base_dir, nickname, domain) + '/.noVotes'
            if os.path.isfile(show_vote_file):
                show_vote_posts = False

            if is_image_media(session, base_dir, http_prefix,
                              nickname, domain, post_json_object,
                              yt_replace_domain,
                              twitter_replacement_domain,
                              allow_local_network_access,
                              recent_posts_cache, debug, system_language,
                              domain_full, person_cache, signing_priv_key_pem,
                              bold_reading, show_vote_posts,
                              languages_understood, mitm_servers):
                # media index will be updated
                update_index_list.append('tlmedia')
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'is_image_media',
                                debug)
            inbox_start_time = time.time()
            if is_blog_post(post_json_object):
                # blogs index will be updated
                update_index_list.append('tlblogs')

        # get the avatar for a reply/announce
        _obtain_avatar_for_reply_post(session, base_dir,
                                      http_prefix, domain,
                                      onion_domain, i2p_domain,
                                      person_cache, post_json_object, debug,
                                      signing_priv_key_pem, mitm_servers)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_obtain_avatar_for_reply_post',
                            debug)

        # cache any svg image attachments locally
        # This is so that any scripts can be removed
        cache_svg_images(session, base_dir, http_prefix,
                         domain, domain_full,
                         onion_domain, i2p_domain,
                         post_json_object,
                         federation_list, debug, None)

        inbox_start_time = time.time()

        # save the post to file
        if save_json(post_json_object, destination_filename):
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'save_json',
                                debug)
            inbox_start_time = time.time()
            if mitm:
                # write a file to indicate that this post was delivered
                # via a third party
                destination_filename_mitm = \
                    destination_filename.replace('.json', '') + '.mitm'
                try:
                    with open(destination_filename_mitm, 'w+',
                              encoding='utf-8') as fp_mitm:
                        fp_mitm.write('\n')
                except OSError:
                    print('EX: _inbox_after_initial unable to write ' +
                          destination_filename_mitm)

            _low_frequency_post_notification(base_dir, http_prefix,
                                             nickname, domain, port,
                                             handle, post_is_dm, json_obj)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_low_frequency_post_notification',
                                debug)
            inbox_start_time = time.time()

            # If this is a reply to a muted post then also mute it.
            # This enables you to ignore a threat that's getting boring
            if is_reply_to_muted_post:
                print('MUTE REPLY: ' + destination_filename)
                destination_filename_muted = destination_filename + '.muted'
                try:
                    with open(destination_filename_muted, 'w+',
                              encoding='utf-8') as fp_mute:
                        fp_mute.write('\n')
                except OSError:
                    print('EX: _inbox_after_initial unable to write 2 ' +
                          destination_filename_muted)

            # is this an edit of a previous post?
            # in Mastodon "delete and redraft"
            # NOTE: this must be done before update_conversation is called
            edited_filename, edited_json = \
                edited_post_filename(base_dir, handle_name, domain,
                                     post_json_object, debug, 300,
                                     system_language)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'edited_post_filename',
                                debug)
            inbox_start_time = time.time()

            # handle any previous edits
            if _former_representations_to_edits(base_dir,
                                                nickname, domain,
                                                post_json_object,
                                                max_mentions,
                                                max_emoji,
                                                allow_local_network_access,
                                                debug,
                                                system_language,
                                                http_prefix,
                                                domain_full,
                                                person_cache,
                                                max_hashtags, port,
                                                onion_domain,
                                                i2p_domain):
                # ensure that there is an updated entry
                # for the publication date
                if post_json_object['object'].get('published') and \
                   not post_json_object['object'].get('updated'):
                    post_json_object['object']['updated'] = \
                        post_json_object['object']['published']
                    save_json(post_json_object, destination_filename)

            # If this was an edit then update the edits json file and
            # delete the previous version of the post
            if edited_filename and edited_json:
                prev_edits_filename = \
                    edited_filename.replace('.json', '.edits')
                edits_filename = \
                    destination_filename.replace('.json', '.edits')
                modified = edited_json['object']['published']
                if os.path.isfile(edits_filename):
                    edits_json = load_json(edits_filename)
                    if edits_json:
                        if not edits_json.get(modified):
                            edits_json[modified] = edited_json
                            save_json(edits_json, edits_filename)
                else:
                    if os.path.isfile(prev_edits_filename):
                        if prev_edits_filename != edits_filename:
                            try:
                                copyfile(prev_edits_filename, edits_filename)
                            except OSError:
                                print('EX: failed to copy edits file')
                        edits_json = load_json(edits_filename)
                        if edits_json:
                            if not edits_json.get(modified):
                                edits_json[modified] = edited_json
                                save_json(edits_json, edits_filename)
                    else:
                        edits_json = {
                            modified: edited_json
                        }
                        save_json(edits_json, edits_filename)

                if edited_filename != destination_filename:
                    delete_post(base_dir, http_prefix,
                                nickname, domain, edited_filename,
                                debug, recent_posts_cache, True)

            # update the indexes for different timelines
            for boxname in update_index_list:
                fitness_performance(inbox_start_time,
                                    server.fitness,
                                    'INBOX', 'box_' + boxname,
                                    debug)
                inbox_start_time = time.time()
                if not inbox_update_index(boxname, base_dir, handle,
                                          destination_filename, debug):
                    fitness_performance(inbox_start_time,
                                        server.fitness,
                                        'INBOX', 'inbox_update_index',
                                        debug)
                    inbox_start_time = time.time()
                    print('ERROR: unable to update ' + boxname + ' index')
                else:
                    if boxname == 'inbox':
                        if is_recent_post(post_json_object, 3):
                            domain_full = get_full_domain(domain, port)
                            update_speaker(base_dir, http_prefix,
                                           nickname, domain, domain_full,
                                           post_json_object, person_cache,
                                           translate, None, theme_name,
                                           system_language, boxname)
                            fitness_performance(inbox_start_time,
                                                server.fitness,
                                                'INBOX', 'update_speaker',
                                                debug)
                            inbox_start_time = time.time()
                    if not unit_test:
                        if debug:
                            print('Saving inbox post as html to cache')

                        html_cache_start_time = time.time()
                        allow_local_net_access = allow_local_network_access
                        show_pub_date_only = show_published_date_only
                        timezone = \
                            get_account_timezone(base_dir, handle_name, domain)
                        fitness_performance(inbox_start_time,
                                            server.fitness,
                                            'INBOX', 'get_account_timezone',
                                            debug)
                        inbox_start_time = time.time()
                        min_img_for_accounts = \
                            server.min_images_for_accounts
                        instance_software = server.instance_software
                        _inbox_store_post_to_html_cache(recent_posts_cache,
                                                        max_recent_posts,
                                                        translate, base_dir,
                                                        http_prefix,
                                                        session,
                                                        cached_webfingers,
                                                        person_cache,
                                                        handle_name,
                                                        domain, port,
                                                        post_json_object,
                                                        allow_deletion,
                                                        boxname,
                                                        show_pub_date_only,
                                                        peertube_instances,
                                                        allow_local_net_access,
                                                        theme_name,
                                                        system_language,
                                                        max_like_count,
                                                        signing_priv_key_pem,
                                                        cw_lists,
                                                        lists_enabled,
                                                        timezone, mitm,
                                                        bold_reading,
                                                        dogwhistles,
                                                        min_img_for_accounts,
                                                        buy_sites,
                                                        server.auto_cw_cache,
                                                        server.mitm_servers,
                                                        instance_software)
                        fitness_performance(inbox_start_time,
                                            server.fitness,
                                            'INBOX',
                                            '_inbox_store_post_to_html_cache',
                                            debug)
                        inbox_start_time = time.time()
                        if debug:
                            time_diff = \
                                str(int((time.time() - html_cache_start_time) *
                                        1000))
                            print('Saved ' +
                                  boxname + ' post as html to cache in ' +
                                  time_diff + ' mS')

            update_conversation(base_dir, handle_name, domain,
                                post_json_object)
            fitness_performance(inbox_start_time,
                                server.fitness,
                                'INBOX', 'update_conversation',
                                debug)
            inbox_start_time = time.time()

            # store the id of the last post made by this actor
            _store_last_post_id(base_dir, nickname, domain, post_json_object)
            fitness_performance(inbox_start_time,
                                server.fitness,
                                'INBOX', '_store_last_post_id',
                                debug)
            inbox_start_time = time.time()

            _inbox_update_calendar_from_tag(base_dir, handle, post_json_object)
            fitness_performance(inbox_start_time,
                                server.fitness,
                                'INBOX', '_inbox_update_calendar_from_tag',
                                debug)
            inbox_start_time = time.time()

            _inbox_update_calendar_from_event(base_dir, handle,
                                              post_json_object)
            fitness_performance(inbox_start_time,
                                server.fitness,
                                'INBOX', '_inbox_update_calendar_from_event',
                                debug)
            inbox_start_time = time.time()

            store_hash_tags(base_dir, handle_name, domain,
                            http_prefix, domain_full,
                            post_json_object, translate, session)
            fitness_performance(inbox_start_time,
                                server.fitness,
                                'INBOX', 'store_hash_tags',
                                debug)
            inbox_start_time = time.time()

            # send the post out to group members
            if is_group:
                _send_to_group_members(server,
                                       session, session_onion, session_i2p,
                                       base_dir, handle, port,
                                       post_json_object,
                                       http_prefix, federation_list,
                                       send_threads,
                                       post_log, cached_webfingers,
                                       person_cache, debug,
                                       domain, onion_domain, i2p_domain,
                                       signing_priv_key_pem,
                                       sites_unavailable,
                                       system_language,
                                       mitm_servers)
                fitness_performance(inbox_start_time,
                                    server.fitness,
                                    'INBOX', '_send_to_group_members',
                                    debug)
                inbox_start_time = time.time()
    else:
        if debug:
            print("Inbox post is not valid " + str(post_json_object))
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'invalid_post',
                            debug)
        inbox_start_time = time.time()

    # if the post wasn't saved
    if not os.path.isfile(destination_filename):
        if debug:
            print("Inbox post was not saved " + destination_filename)
        return False
    fitness_performance(inbox_start_time,
                        server.fitness,
                        'INBOX', 'end_after_initial',
                        debug)
    inbox_start_time = time.time()

    return True


def clear_queue_items(base_dir: str, queue: []) -> None:
    """Clears the queue for each account
    """
    ctr = 0
    queue.clear()
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for account in dirs:
            queue_dir = dir_str + '/' + account + '/queue'
            if not os.path.isdir(queue_dir):
                continue
            for _, _, queuefiles in os.walk(queue_dir):
                for qfile in queuefiles:
                    try:
                        os.remove(os.path.join(queue_dir, qfile))
                        ctr += 1
                    except OSError:
                        print('EX: clear_queue_items unable to delete ' +
                              qfile)
                break
        break
    if ctr > 0:
        print('Removed ' + str(ctr) + ' inbox queue items')


def _restore_queue_items(base_dir: str, queue: []) -> None:
    """Checks the queue for each account and appends filenames
    """
    queue.clear()
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for account in dirs:
            queue_dir = dir_str + '/' + account + '/queue'
            if not os.path.isdir(queue_dir):
                continue
            for _, _, queuefiles in os.walk(queue_dir):
                for qfile in queuefiles:
                    queue.append(os.path.join(queue_dir, qfile))
                break
        break
    if len(queue) > 0:
        print('Restored ' + str(len(queue)) + ' inbox queue items')


def run_inbox_queue_watchdog(project_version: str, httpd) -> None:
    """This tries to keep the inbox thread running even if it dies
    """
    print('THREAD: Starting inbox queue watchdog ' + project_version)
    inbox_queue_original = httpd.thrInboxQueue.clone(run_inbox_queue)
    begin_thread(httpd.thrInboxQueue, 'run_inbox_queue_watchdog')
    while True:
        time.sleep(20)
        if not httpd.thrInboxQueue.is_alive() or httpd.restart_inbox_queue:
            httpd.restart_inbox_queue_in_progress = True
            httpd.thrInboxQueue.kill()
            print('THREAD: restarting inbox queue watchdog')
            httpd.thrInboxQueue = inbox_queue_original.clone(run_inbox_queue)
            httpd.inbox_queue.clear()
            begin_thread(httpd.thrInboxQueue, 'run_inbox_queue_watchdog 2')
            print('Restarting inbox queue...')
            httpd.restart_inbox_queue_in_progress = False
            httpd.restart_inbox_queue = False


def _inbox_quota_exceeded(queue: {}, queue_filename: str,
                          queue_json: {}, quotas_daily: {}, quotas_per_min: {},
                          domain_max_posts_per_day: int,
                          account_max_posts_per_day: int,
                          debug: bool) -> bool:
    """limit the number of posts which can arrive per domain per day
    """
    post_domain = queue_json['postDomain']
    if not post_domain:
        return False

    if domain_max_posts_per_day > 0:
        if quotas_daily['domains'].get(post_domain):
            if quotas_daily['domains'][post_domain] > \
               domain_max_posts_per_day:
                print('Queue: Quota per day - Maximum posts for ' +
                      post_domain + ' reached (' +
                      str(domain_max_posts_per_day) + ')')
                if len(queue) > 0:
                    try:
                        os.remove(queue_filename)
                    except OSError:
                        print('EX: _inbox_quota_exceeded unable to delete 1 ' +
                              str(queue_filename))
                    if len(queue) > 0:
                        queue.pop(0)
                return True
            quotas_daily['domains'][post_domain] += 1
        else:
            quotas_daily['domains'][post_domain] = 1

        if quotas_per_min['domains'].get(post_domain):
            domain_max_posts_per_min = \
                int(domain_max_posts_per_day / (24 * 60))
            if domain_max_posts_per_min < 5:
                domain_max_posts_per_min = 5
            if quotas_per_min['domains'][post_domain] > \
               domain_max_posts_per_min:
                print('Queue: Quota per min - Maximum posts for ' +
                      post_domain + ' reached (' +
                      str(domain_max_posts_per_min) + ')')
                if len(queue) > 0:
                    try:
                        os.remove(queue_filename)
                    except OSError:
                        print('EX: _inbox_quota_exceeded unable to delete 2 ' +
                              str(queue_filename))
                    if len(queue) > 0:
                        queue.pop(0)
                return True
            quotas_per_min['domains'][post_domain] += 1
        else:
            quotas_per_min['domains'][post_domain] = 1

    if account_max_posts_per_day > 0:
        post_handle = queue_json['postNickname'] + '@' + post_domain
        if quotas_daily['accounts'].get(post_handle):
            if quotas_daily['accounts'][post_handle] > \
               account_max_posts_per_day:
                print('Queue: Quota account posts per day -' +
                      ' Maximum posts for ' +
                      post_handle + ' reached (' +
                      str(account_max_posts_per_day) + ')')
                if len(queue) > 0:
                    try:
                        os.remove(queue_filename)
                    except OSError:
                        print('EX: _inbox_quota_exceeded unable to delete 3 ' +
                              str(queue_filename))
                    if len(queue) > 0:
                        queue.pop(0)
                return True
            quotas_daily['accounts'][post_handle] += 1
        else:
            quotas_daily['accounts'][post_handle] = 1

        if quotas_per_min['accounts'].get(post_handle):
            account_max_posts_per_min = \
                int(account_max_posts_per_day / (24 * 60))
            account_max_posts_per_min = max(account_max_posts_per_min, 5)
            if quotas_per_min['accounts'][post_handle] > \
               account_max_posts_per_min:
                print('Queue: Quota account posts per min -' +
                      ' Maximum posts for ' +
                      post_handle + ' reached (' +
                      str(account_max_posts_per_min) + ')')
                if len(queue) > 0:
                    try:
                        os.remove(queue_filename)
                    except OSError:
                        print('EX: _inbox_quota_exceeded unable to delete 4 ' +
                              str(queue_filename))
                    if len(queue) > 0:
                        queue.pop(0)
                return True
            quotas_per_min['accounts'][post_handle] += 1
        else:
            quotas_per_min['accounts'][post_handle] = 1

    if debug:
        if account_max_posts_per_day > 0 or domain_max_posts_per_day > 0:
            pprint(quotas_daily)
    return False


def _check_json_signature(base_dir: str, queue_json: {}) -> (bool, bool):
    """check if a json signature exists on this post
    """
    has_json_signature = False
    jwebsig_type = None
    original_json = queue_json['original']
    if not original_json.get('@context') or \
       not original_json.get('signature'):
        return has_json_signature, jwebsig_type
    if not isinstance(original_json['signature'], dict):
        return has_json_signature, jwebsig_type
    # see https://tools.ietf.org/html/rfc7515
    jwebsig = original_json['signature']
    # signature exists and is of the expected type
    if not jwebsig.get('type') or \
       not jwebsig.get('signatureValue'):
        return has_json_signature, jwebsig_type
    jwebsig_type = jwebsig['type']
    if jwebsig_type == 'RsaSignature2017':
        if has_valid_context(original_json):
            has_json_signature = True
        else:
            unknown_contexts_file = \
                data_dir(base_dir) + '/unknownContexts.txt'
            unknown_context = str(original_json['@context'])

            print('unrecognized @context: ' + unknown_context)

            already_unknown = False
            if os.path.isfile(unknown_contexts_file):
                if text_in_file(unknown_context, unknown_contexts_file):
                    already_unknown = True

            if not already_unknown:
                try:
                    with open(unknown_contexts_file, 'a+',
                              encoding='utf-8') as fp_unknown:
                        fp_unknown.write(unknown_context + '\n')
                except OSError:
                    print('EX: _check_json_signature unable to append ' +
                          unknown_contexts_file)
    else:
        print('Unrecognized jsonld signature type: ' + jwebsig_type)

        unknown_signatures_file = \
            data_dir(base_dir) + '/unknownJsonSignatures.txt'

        already_unknown = False
        if os.path.isfile(unknown_signatures_file):
            if text_in_file(jwebsig_type, unknown_signatures_file):
                already_unknown = True

        if not already_unknown:
            try:
                with open(unknown_signatures_file, 'a+',
                          encoding='utf-8') as fp_unknown:
                    fp_unknown.write(jwebsig_type + '\n')
            except OSError:
                print('EX: _check_json_signature unable to append ' +
                      unknown_signatures_file)
    return has_json_signature, jwebsig_type


def _receive_follow_request(session, session_onion, session_i2p,
                            base_dir: str, http_prefix: str,
                            port: int, send_threads: [], post_log: [],
                            cached_webfingers: {}, person_cache: {},
                            message_json: {}, federation_list: [],
                            debug: bool, project_version: str,
                            max_followers: int,
                            this_domain: str, onion_domain: str,
                            i2p_domain: str, signing_priv_key_pem: str,
                            unit_test: bool, system_language: str,
                            followers_sync_cache: {},
                            sites_unavailable: [],
                            mitm_servers: []) -> bool:
    """Receives a follow request within the POST section of HTTPServer
    """
    if not message_json['type'].startswith('Follow'):
        if not message_json['type'].startswith('Join'):
            return False
    print('Receiving follow request')
    if not has_actor(message_json, debug):
        return True
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: ' +
                  'users/profile/author/accounts/channel missing from actor')
        return True
    domain, temp_port = get_domain_from_actor(actor_url)
    if not domain:
        if debug:
            print('DEBUG: receive follow request actor without domain ' +
                  actor_url)
        return True
    from_port = port
    domain_full = get_full_domain(domain, temp_port)
    if temp_port:
        from_port = temp_port
    if not domain_permitted(domain, federation_list):
        if debug:
            print('DEBUG: follower from domain not permitted - ' + domain)
        return True
    nickname = get_nickname_from_actor(actor_url)
    if not nickname:
        # single user instance
        nickname = 'dev'
        if debug:
            print('DEBUG: follow request does not contain a ' +
                  'nickname. Assuming single user instance.')
    if not message_json.get('to'):
        message_json['to'] = message_json['object']
        if not isinstance(message_json['to'], list):
            message_json['to'] = [message_json['to']]
    if not has_users_path(message_json['object']):
        if debug:
            print('DEBUG: users/profile/author/channel/accounts ' +
                  'not found within object')
        return True
    domain_to_follow, temp_port = get_domain_from_actor(message_json['object'])
    if not domain_to_follow:
        if debug:
            print('DEBUG: receive follow request no domain found in object ' +
                  message_json['object'])
        return True
    # switch to the local domain rather than its onion or i2p version
    if onion_domain:
        if domain_to_follow.endswith(onion_domain):
            domain_to_follow = this_domain
    if i2p_domain:
        if domain_to_follow.endswith(i2p_domain):
            domain_to_follow = this_domain
    if not domain_permitted(domain_to_follow, federation_list):
        if debug:
            print('DEBUG: follow domain not permitted ' + domain_to_follow)
        return True
    domain_to_follow_full = get_full_domain(domain_to_follow, temp_port)
    nickname_to_follow = get_nickname_from_actor(message_json['object'])
    if not nickname_to_follow:
        if debug:
            print('DEBUG: follow request does not contain a ' +
                  'nickname for the account followed')
        return True
    if is_system_account(nickname_to_follow):
        if debug:
            print('DEBUG: Cannot follow system account - ' +
                  nickname_to_follow)
        return True
    if max_followers > 0:
        if get_no_of_followers(base_dir, nickname_to_follow,
                               domain_to_follow) > max_followers:
            print('WARN: ' + nickname_to_follow +
                  ' has reached their maximum number of followers')
            return True
    handle_to_follow = nickname_to_follow + '@' + domain_to_follow
    if domain_to_follow == domain:
        handle_dir = acct_handle_dir(base_dir, handle_to_follow)
        if not os.path.isdir(handle_dir):
            if debug:
                print('DEBUG: followed account not found - ' +
                      handle_dir)
            return True

    is_already_follower = False
    if is_follower_of_person(base_dir,
                             nickname_to_follow, domain_to_follow_full,
                             nickname, domain_full):
        if debug:
            print('DEBUG: ' + nickname + '@' + domain +
                  ' is already a follower of ' +
                  nickname_to_follow + '@' + domain_to_follow)
        is_already_follower = True

    approve_handle = nickname + '@' + domain_full

    curr_session = session
    curr_http_prefix = http_prefix
    curr_domain = domain
    curr_port = from_port
    if onion_domain and \
       not curr_domain.endswith('.onion') and \
       domain_to_follow.endswith('.onion'):
        curr_session = session_onion
        curr_http_prefix = 'http'
        curr_domain = onion_domain
        curr_port = 80
        port = 80
        if debug:
            print('Domain switched from ' + domain + ' to ' + curr_domain)
    elif (i2p_domain and
          not curr_domain.endswith('.i2p') and
          domain_to_follow.endswith('.i2p')):
        curr_session = session_i2p
        curr_http_prefix = 'http'
        curr_domain = i2p_domain
        curr_port = 80
        port = 80
        if debug:
            print('Domain switched from ' + domain + ' to ' + curr_domain)

    # is the actor sending the request valid?
    if not valid_sending_actor(curr_session, base_dir,
                               nickname_to_follow, domain_to_follow,
                               person_cache, message_json,
                               signing_priv_key_pem, debug, unit_test,
                               system_language, mitm_servers):
        print('REJECT spam follow request ' + approve_handle)
        return True

    # what is the followers policy?
    if not is_already_follower and \
       follow_approval_required(base_dir, nickname_to_follow,
                                domain_to_follow, debug, approve_handle):
        print('Follow approval is required')
        if domain.endswith('.onion'):
            if no_of_follow_requests(base_dir,
                                     nickname_to_follow, domain_to_follow,
                                     'onion') > 5:
                print('Too many follow requests from onion addresses')
                return True
        elif domain.endswith('.i2p'):
            if no_of_follow_requests(base_dir,
                                     nickname_to_follow, domain_to_follow,
                                     'i2p') > 5:
                print('Too many follow requests from i2p addresses')
                return True
        else:
            if no_of_follow_requests(base_dir,
                                     nickname_to_follow, domain_to_follow,
                                     '') > 10:
                print('Too many follow requests')
                return True

        # Get the actor for the follower and add it to the cache.
        # Getting their public key has the same result
        if debug:
            print('Obtaining the following actor: ' + actor_url)
        pubkey_result = \
            get_person_pub_key(base_dir, curr_session,
                               actor_url,
                               person_cache, debug, project_version,
                               curr_http_prefix,
                               this_domain, onion_domain,
                               i2p_domain, signing_priv_key_pem,
                               mitm_servers)
        if not pubkey_result:
            if debug:
                print('Unable to obtain following actor: ' +
                      actor_url)
        elif isinstance(pubkey_result, dict):
            if debug:
                print('http error code trying to obtain following actor: ' +
                      actor_url + ' ' + str(pubkey_result))

        group_account = \
            has_group_type(base_dir, actor_url, person_cache)
        if group_account and is_group_account(base_dir, nickname, domain):
            print('Group cannot follow a group')
            return True

        print('Storing follow request for approval')
        return store_follow_request(base_dir,
                                    nickname_to_follow, domain_to_follow, port,
                                    nickname, domain, from_port,
                                    message_json, debug, actor_url,
                                    group_account)
    else:
        if is_already_follower:
            print(approve_handle + ' is already a follower. ' +
                  'Re-sending Accept.')
        else:
            print('Follow request does not require approval ' +
                  approve_handle)
        # update the followers
        account_to_be_followed = \
            acct_dir(base_dir, nickname_to_follow, domain_to_follow)
        if os.path.isdir(account_to_be_followed):
            followers_filename = account_to_be_followed + '/followers.txt'

            # for actors which don't follow the mastodon
            # /users/ path convention store the full actor
            if '/users/' not in actor_url:
                approve_handle = actor_url

            # Get the actor for the follower and add it to the cache.
            # Getting their public key has the same result
            if debug:
                print('Obtaining the following actor: ' +
                      actor_url)
            pubkey_result = \
                get_person_pub_key(base_dir, curr_session,
                                   actor_url,
                                   person_cache, debug, project_version,
                                   curr_http_prefix, this_domain,
                                   onion_domain, i2p_domain,
                                   signing_priv_key_pem,
                                   mitm_servers)
            if not pubkey_result:
                if debug:
                    print('Unable to obtain following actor: ' +
                          actor_url)
            elif isinstance(pubkey_result, dict):
                if debug:
                    print('http error code trying to obtain ' +
                          'following actor: ' + actor_url +
                          ' ' + str(pubkey_result))

            print('Updating followers file: ' +
                  followers_filename + ' adding ' + approve_handle)
            if os.path.isfile(followers_filename):
                if not text_in_file(approve_handle, followers_filename):
                    group_account = \
                        has_group_type(base_dir,
                                       actor_url, person_cache)
                    if debug:
                        print(approve_handle + ' / ' + actor_url +
                              ' is Group: ' + str(group_account))
                    if group_account and \
                       is_group_account(base_dir, nickname, domain):
                        print('Group cannot follow a group')
                        return True
                    try:
                        with open(followers_filename, 'r+',
                                  encoding='utf-8') as fp_followers:
                            content = fp_followers.read()
                            if approve_handle + '\n' not in content:
                                fp_followers.seek(0, 0)
                                if not group_account:
                                    fp_followers.write(approve_handle +
                                                       '\n' + content)
                                else:
                                    fp_followers.write('!' + approve_handle +
                                                       '\n' + content)
                    except OSError as ex:
                        print('WARN: ' +
                              'Failed to write entry to followers file ' +
                              str(ex))
            else:
                try:
                    with open(followers_filename, 'w+',
                              encoding='utf-8') as fp_followers:
                        fp_followers.write(approve_handle + '\n')
                except OSError:
                    print('EX: _receive_follow_request unable to write ' +
                          followers_filename)
        else:
            print('ACCEPT: Follow Accept account directory not found: ' +
                  account_to_be_followed)

    print('Beginning follow accept')
    return followed_account_accepts(curr_session, base_dir, curr_http_prefix,
                                    nickname_to_follow, domain_to_follow, port,
                                    nickname, curr_domain, curr_port,
                                    actor_url, federation_list,
                                    message_json, send_threads, post_log,
                                    cached_webfingers, person_cache,
                                    debug, project_version, True,
                                    signing_priv_key_pem,
                                    this_domain, onion_domain, i2p_domain,
                                    followers_sync_cache, sites_unavailable,
                                    system_language, mitm_servers)


def run_inbox_queue(server,
                    recent_posts_cache: {}, max_recent_posts: int,
                    project_version: str,
                    base_dir: str, http_prefix: str,
                    send_threads: [], post_log: [],
                    cached_webfingers: {}, person_cache: {}, queue: [],
                    domain: str,
                    onion_domain: str, i2p_domain: str,
                    port: int, proxy_type: str,
                    federation_list: [], max_replies: int,
                    domain_max_posts_per_day: int,
                    account_max_posts_per_day: int,
                    allow_deletion: bool, debug: bool, max_mentions: int,
                    max_emoji: int, translate: {}, unit_test: bool,
                    yt_replace_domain: str,
                    twitter_replacement_domain: str,
                    show_published_date_only: bool,
                    max_followers: int,
                    allow_local_network_access: bool,
                    peertube_instances: [],
                    verify_all_signatures: bool,
                    theme_name: str, system_language: str,
                    max_like_count: int, signing_priv_key_pem: str,
                    default_reply_interval_hrs: int,
                    cw_lists: {}, max_hashtags: int) -> None:
    """Processes received items and moves them to the appropriate
    directories
    """
    inbox_start_time = time.time()
    print('Starting new session when starting inbox queue')
    fitness_performance(inbox_start_time, server.fitness,
                        'INBOX', 'start', debug)
    inbox_start_time = time.time()

    curr_session_time = int(time.time())
    session_last_update = 0
    session = create_session(proxy_type)
    if session:
        session_last_update = curr_session_time

    session_restart_interval_secs = random.randrange(18000, 20000)

    # is this is a clearnet instance then optionally start sessions
    # for onion and i2p domains
    session_onion = None
    session_i2p = None
    session_last_update_onion = 0
    session_last_update_i2p = 0
    if proxy_type != 'tor' and onion_domain:
        print('Starting onion session when starting inbox queue')
        session_onion = create_session('tor')
        if session_onion:
            session_onion = curr_session_time
    if proxy_type != 'i2p' and i2p_domain:
        print('Starting i2p session when starting inbox queue')
        session_i2p = create_session('i2p')
        if session_i2p:
            session_i2p = curr_session_time

    inbox_handle = 'inbox@' + domain
    if debug:
        print('DEBUG: Inbox queue running')

    # if queue processing was interrupted (eg server crash)
    # then this loads any outstanding items back into the queue
    _restore_queue_items(base_dir, queue)
    fitness_performance(inbox_start_time, server.fitness,
                        'INBOX', '_restore_queue_items', debug)
    inbox_start_time = time.time()

    # keep track of numbers of incoming posts per day
    quotas_last_update_daily = int(time.time())
    quotas_daily = {
        'domains': {},
        'accounts': {}
    }
    quotas_last_update_per_min = int(time.time())
    quotas_per_min = {
        'domains': {},
        'accounts': {}
    }

    heart_beat_ctr = 0
    queue_restore_ctr = 0
    curr_mitm_servers: list[str] = []

    # time when the last DM bounce message was sent
    # This is in a list so that it can be changed by reference
    # within _bounce_dm
    last_bounce_message = [int(time.time())]

    # how long it takes for broch mode to lapse
    broch_lapse_days = random.randrange(7, 14)

    fitness_performance(inbox_start_time, server.fitness,
                        'INBOX', 'while_loop_start', debug)
    inbox_start_time = time.time()
    # the last time that a quote request was last received
    last_quote_request = 0
    while True:
        time.sleep(1)
        inbox_start_time = time.time()
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'while_loop_itteration', debug)
        inbox_start_time = time.time()

        # heartbeat to monitor whether the inbox queue is running
        heart_beat_ctr += 1
        if heart_beat_ctr >= 10:
            # turn off broch mode after it has timed out
            if broch_modeLapses(base_dir, broch_lapse_days):
                broch_lapse_days = random.randrange(7, 14)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'broch_modeLapses', debug)
            inbox_start_time = time.time()
            print('>>> Heartbeat Q:' + str(len(queue)) + ' ' +
                  '{:%F %T}'.format(datetime.datetime.now()))
            heart_beat_ctr = 0

            # save MITM servers list if it has changed
            if str(server.mitm_servers) != str(curr_mitm_servers):
                curr_mitm_servers = server.mitm_servers.copy()
                save_mitm_servers(base_dir, curr_mitm_servers)

        if len(queue) == 0:
            # restore any remaining queue items
            queue_restore_ctr += 1
            if queue_restore_ctr >= 30:
                queue_restore_ctr = 0
                _restore_queue_items(base_dir, queue)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'restore_queue', debug)
            inbox_start_time = time.time()
            continue

        # oldest item first
        queue.sort()
        queue_filename = queue[0]
        if not os.path.isfile(queue_filename):
            print("Queue: queue item rejected because it has no file: " +
                  queue_filename)
            if len(queue) > 0:
                queue.pop(0)
            continue

        if debug:
            print('Loading queue item ' + queue_filename)

        # Load the queue json
        queue_json = load_json(queue_filename)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'load_queue_json', debug)
        inbox_start_time = time.time()
        if not queue_json:
            print('Queue: run_inbox_queue failed to load inbox queue item ' +
                  queue_filename)
            # Assume that the file is probably corrupt/unreadable
            if len(queue) > 0:
                queue.pop(0)
            # delete the queue file
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 1 unable to delete ' +
                          str(queue_filename))
            continue

        curr_time = int(time.time())

        # clear the daily quotas for maximum numbers of received posts
        if curr_time - quotas_last_update_daily > 60 * 60 * 24:
            quotas_daily = {
                'domains': {},
                'accounts': {}
            }
            quotas_last_update_daily = curr_time

        if curr_time - quotas_last_update_per_min > 60:
            # clear the per minute quotas for maximum numbers of received posts
            quotas_per_min = {
                'domains': {},
                'accounts': {}
            }
            # also check if the json signature enforcement has changed
            verify_all_sigs = get_config_param(base_dir, "verifyAllSignatures")
            if verify_all_sigs is not None:
                verify_all_signatures = verify_all_sigs
            # change the last time that this was done
            quotas_last_update_per_min = curr_time

        if _inbox_quota_exceeded(queue, queue_filename,
                                 queue_json, quotas_daily, quotas_per_min,
                                 domain_max_posts_per_day,
                                 account_max_posts_per_day, debug):
            continue
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_inbox_quota_exceeded', debug)
        inbox_start_time = time.time()

        # recreate the session periodically
        time_diff = curr_time - session_last_update
        if not session or time_diff > session_restart_interval_secs:
            print('Regenerating inbox queue session at 5hr interval')
            session = create_session(proxy_type)
            if session:
                session_last_update = curr_time
            else:
                print('WARN: inbox session not created')
                continue
        if onion_domain:
            time_diff = curr_time - session_last_update_onion
            if not session_onion or time_diff > session_restart_interval_secs:
                print('Regenerating inbox queue onion session at 5hr interval')
                session_onion = create_session('tor')
                if session_onion:
                    session_last_update_onion = curr_time
                else:
                    print('WARN: inbox onion session not created')
                    continue
        if i2p_domain:
            time_diff = curr_time - session_last_update_i2p
            if not session_i2p or time_diff > session_restart_interval_secs:
                print('Regenerating inbox queue i2p session at 5hr interval')
                session_i2p = create_session('i2p')
                if session_i2p:
                    session_last_update_i2p = curr_time
                else:
                    print('WARN: inbox i2p session not created')
                    continue
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'recreate_session', debug)
        inbox_start_time = time.time()

        curr_session = session
        if queue_json.get('actor'):
            if isinstance(queue_json['actor'], str):
                sender_domain, _ = get_domain_from_actor(queue_json['actor'])
                if sender_domain:
                    if sender_domain.endswith('.onion') and \
                       session_onion and proxy_type != 'tor':
                        curr_session = session_onion
                    elif (sender_domain.endswith('.i2p') and
                          session_i2p and proxy_type != 'i2p'):
                        curr_session = session_i2p

        if debug and queue_json.get('actor'):
            print('Obtaining public key for actor ' + queue_json['actor'])

        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'start_get_pubkey', debug)
        inbox_start_time = time.time()
        # Try a few times to obtain the public key
        pub_key = None
        key_id = None
        for tries in range(8):
            key_id = None
            signature_params = \
                queue_json['httpHeaders']['signature'].split(',')
            for signature_item in signature_params:
                if signature_item.startswith('keyId='):
                    if '"' in signature_item:
                        key_id = signature_item.split('"')[1]
                        break
            if not key_id:
                print('Queue: No keyId in signature: ' +
                      queue_json['httpHeaders']['signature'])
                pub_key = None
                break

            pub_key = \
                get_person_pub_key(base_dir, curr_session, key_id,
                                   person_cache, debug,
                                   project_version, http_prefix,
                                   domain, onion_domain, i2p_domain,
                                   signing_priv_key_pem,
                                   server.mitm_servers)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'get_person_pub_key', debug)
            inbox_start_time = time.time()
            if pub_key:
                if not isinstance(pub_key, dict):
                    if debug:
                        print('DEBUG: public key: ' + str(pub_key))
                else:
                    if debug:
                        print('DEBUG: http code error for public key: ' +
                              str(pub_key))
                    pub_key = None
                break

            if debug:
                print('DEBUG: Retry ' + str(tries+1) +
                      ' obtaining public key for ' + key_id)
            time.sleep(1)

        if not pub_key:
            if debug:
                print('Queue: public key could not be obtained from ' + key_id)
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 2 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            continue

        # check the http header signature
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'begin_check_signature', debug)
        inbox_start_time = time.time()
        if debug:
            print('DEBUG: checking http header signature')
            pprint(queue_json['httpHeaders'])
        post_str = json.dumps(queue_json['post'])
        http_signature_failed = False
        if not verify_post_headers(http_prefix, pub_key,
                                   queue_json['httpHeaders'],
                                   queue_json['path'], False,
                                   queue_json['digest'],
                                   post_str, debug):
            http_signature_failed = True
            print('Queue: Header signature check failed')
            pprint(queue_json['httpHeaders'])
        else:
            if debug:
                print('DEBUG: http header signature check success')
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'verify_post_headers', debug)
        inbox_start_time = time.time()

        # check if a json signature exists on this post
        has_json_signature, jwebsig_type = \
            _check_json_signature(base_dir, queue_json)
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_check_json_signature', debug)
        inbox_start_time = time.time()

        # strict enforcement of json signatures
        if not has_json_signature:
            if http_signature_failed:
                if jwebsig_type:
                    print('Queue: Header signature check failed and does ' +
                          'not have a recognised jsonld signature type ' +
                          jwebsig_type)
                else:
                    print('Queue: Header signature check failed and ' +
                          'does not have jsonld signature')
                if debug:
                    pprint(queue_json['httpHeaders'])

            if verify_all_signatures:
                original_json = queue_json['original']
                print('Queue: inbox post does not have a jsonld signature ' +
                      key_id + ' ' + str(original_json))

            if http_signature_failed or verify_all_signatures:
                if os.path.isfile(queue_filename):
                    try:
                        os.remove(queue_filename)
                    except OSError:
                        print('EX: run_inbox_queue 3 unable to delete ' +
                              str(queue_filename))
                if len(queue) > 0:
                    queue.pop(0)
                continue
        else:
            if http_signature_failed or verify_all_signatures:
                # use the original json message received, not one which
                # may have been modified along the way
                original_json = queue_json['original']
                if not verify_json_signature(original_json, pub_key):
                    if debug:
                        print('WARN: jsonld inbox signature check failed ' +
                              key_id + ' ' + pub_key + ' ' +
                              str(original_json))
                    else:
                        print('WARN: jsonld inbox signature check failed ' +
                              key_id)
                    if os.path.isfile(queue_filename):
                        try:
                            os.remove(queue_filename)
                        except OSError:
                            print('EX: run_inbox_queue 4 unable to delete ' +
                                  str(queue_filename))
                    if len(queue) > 0:
                        queue.pop(0)
                    fitness_performance(inbox_start_time, server.fitness,
                                        'INBOX', 'not_verify_signature',
                                        debug)
                    inbox_start_time = time.time()
                    continue

                if http_signature_failed:
                    print('jsonld inbox signature check success ' +
                          'via relay ' + key_id)
                else:
                    print('jsonld inbox signature check success ' + key_id)
                fitness_performance(inbox_start_time, server.fitness,
                                    'INBOX', 'verify_signature_success',
                                    debug)
                inbox_start_time = time.time()

        dogwhistles_filename = data_dir(base_dir) + '/dogwhistles.txt'
        if not os.path.isfile(dogwhistles_filename):
            dogwhistles_filename = base_dir + '/default_dogwhistles.txt'
        dogwhistles = load_dogwhistles(dogwhistles_filename)

        # set the id to the same as the post filename
        # This makes the filename and the id consistent
        # if queue_json['post'].get('id'):
        #     queue_json['post']['id'] = queue_json['id']

        if receive_undo(base_dir, queue_json['post'],
                        debug, domain, onion_domain, i2p_domain):
            print('Queue: Undo accepted from ' + key_id)
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 5 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_receive_undo',
                                debug)
            inbox_start_time = time.time()
            continue

        if debug:
            print('DEBUG: checking for follow requests')
        if _receive_follow_request(curr_session, session_onion, session_i2p,
                                   base_dir, http_prefix, port,
                                   send_threads, post_log,
                                   cached_webfingers,
                                   person_cache,
                                   queue_json['post'],
                                   federation_list,
                                   debug, project_version,
                                   max_followers, domain,
                                   onion_domain, i2p_domain,
                                   signing_priv_key_pem, unit_test,
                                   system_language,
                                   server.followers_sync_cache,
                                   server.sites_unavailable,
                                   server.mitm_servers):
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 6 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            print('Queue: Follow activity for ' + key_id +
                  ' removed from queue')
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_receive_follow_request',
                                debug)
            inbox_start_time = time.time()
            continue

        if debug:
            print('DEBUG: No follow requests')

        if receive_accept_reject(base_dir, domain, queue_json['post'],
                                 federation_list, debug,
                                 domain, onion_domain, i2p_domain):
            print('Queue: Accept/Reject received from ' + key_id)
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 7 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'receive_accept_reject',
                                debug)
            inbox_start_time = time.time()
            continue

        if receive_quote_request(queue_json['post'],
                                 federation_list,
                                 debug, domain,
                                 session, session_onion, session_i2p,
                                 base_dir,
                                 http_prefix,
                                 send_threads, post_log,
                                 cached_webfingers,
                                 person_cache, project_version,
                                 signing_priv_key_pem,
                                 onion_domain,
                                 i2p_domain, {},
                                 server.sites_unavailable,
                                 system_language,
                                 server.mitm_servers,
                                 last_quote_request):
            last_quote_request = int(time.time())
            print('Queue: QuoteRequest received from ' + key_id)
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 7 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'receive_quote_request',
                                debug)
            inbox_start_time = time.time()
            continue

        if receive_move_activity(curr_session, base_dir,
                                 http_prefix, domain, port,
                                 cached_webfingers,
                                 person_cache,
                                 queue_json['post'],
                                 queue_json['postNickname'],
                                 debug,
                                 signing_priv_key_pem,
                                 send_threads,
                                 post_log,
                                 federation_list,
                                 onion_domain,
                                 i2p_domain,
                                 server.sites_unavailable,
                                 server.blocked_cache,
                                 server.block_federated,
                                 server.system_language,
                                 server.mitm_servers):
            if debug:
                print('Queue: _receive_move_activity ' + key_id)
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 8 unable to receive move ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_receive_move_activity',
                                debug)
            inbox_start_time = time.time()
            continue

        if receive_update_activity(recent_posts_cache, curr_session,
                                   base_dir, http_prefix,
                                   domain, port,
                                   cached_webfingers,
                                   person_cache,
                                   queue_json['post'],
                                   queue_json['postNickname'],
                                   debug,
                                   max_mentions, max_emoji,
                                   allow_local_network_access,
                                   system_language,
                                   signing_priv_key_pem,
                                   max_recent_posts, translate,
                                   allow_deletion,
                                   yt_replace_domain,
                                   twitter_replacement_domain,
                                   show_published_date_only,
                                   peertube_instances,
                                   theme_name, max_like_count,
                                   cw_lists, dogwhistles,
                                   server.min_images_for_accounts,
                                   max_hashtags, server.buy_sites,
                                   server.auto_cw_cache,
                                   onion_domain,
                                   i2p_domain, server.mitm_servers,
                                   server.instance_software):
            if debug:
                print('Queue: Update accepted from ' + key_id)
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 8 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', '_receive_update_activity',
                                debug)
            inbox_start_time = time.time()
            continue

        # get recipients list
        recipients_dict, recipients_dict_followers = \
            _inbox_post_recipients(base_dir, queue_json['post'],
                                   domain, port, debug,
                                   onion_domain, i2p_domain)
        if len(recipients_dict.items()) == 0 and \
           len(recipients_dict_followers.items()) == 0:
            if debug:
                print('Queue: no recipients were resolved ' +
                      'for post arriving in inbox')
            if os.path.isfile(queue_filename):
                try:
                    os.remove(queue_filename)
                except OSError:
                    print('EX: run_inbox_queue 9 unable to delete ' +
                          str(queue_filename))
            if len(queue) > 0:
                queue.pop(0)
            continue
        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', '_post_recipients',
                            debug)
        inbox_start_time = time.time()

        # if there are only a small number of followers then
        # process them as if they were specifically
        # addresses to particular accounts
        no_of_follow_items = len(recipients_dict_followers.items())
        if no_of_follow_items > 0:
            # always deliver to individual inboxes
            if no_of_follow_items < 999999:
                if debug:
                    print('DEBUG: moving ' + str(no_of_follow_items) +
                          ' inbox posts addressed to followers')
                for handle, post_item in recipients_dict_followers.items():
                    recipients_dict[handle] = post_item
                recipients_dict_followers = {}
#            recipients_list = [recipients_dict, recipients_dict_followers]

        if debug:
            print('*************************************')
            print('Resolved recipients list:')
            pprint(recipients_dict)
            print('Resolved followers list:')
            pprint(recipients_dict_followers)
            print('*************************************')

        # Copy any posts addressed to followers into the shared inbox
        # this avoid copying file multiple times to potentially many
        # individual inboxes
        if len(recipients_dict_followers) > 0:
            shared_inbox_post_filename = \
                queue_json['destination'].replace(inbox_handle, inbox_handle)
            if not os.path.isfile(shared_inbox_post_filename):
                save_json(queue_json['post'], shared_inbox_post_filename)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'shared_inbox_save',
                                debug)
            inbox_start_time = time.time()

        lists_enabled = get_config_param(base_dir, "listsEnabled")
        dm_license_url = ''

        fitness_performance(inbox_start_time, server.fitness,
                            'INBOX', 'distribute_post',
                            debug)
        inbox_start_time = time.time()

        # for posts addressed to specific accounts
        for handle, _ in recipients_dict.items():
            destination = \
                queue_json['destination'].replace(inbox_handle, handle)
            languages_understood: list[str] = []
            mitm = False
            if queue_json.get('mitm'):
                mitm = True
            bold_reading = False
            bold_reading_filename = \
                acct_handle_dir(base_dir, handle) + '/.boldReading'
            if os.path.isfile(bold_reading_filename):
                bold_reading = True
            _inbox_after_initial(server, inbox_start_time,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 session, session_onion, session_i2p,
                                 key_id, handle,
                                 queue_json['post'],
                                 base_dir, http_prefix,
                                 send_threads, post_log,
                                 cached_webfingers,
                                 person_cache, domain,
                                 onion_domain, i2p_domain,
                                 port, federation_list,
                                 debug,
                                 queue_filename, destination,
                                 max_replies, allow_deletion,
                                 max_mentions, max_emoji,
                                 translate, unit_test,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 allow_local_network_access,
                                 peertube_instances,
                                 last_bounce_message,
                                 theme_name, system_language,
                                 max_like_count,
                                 signing_priv_key_pem,
                                 default_reply_interval_hrs,
                                 cw_lists, lists_enabled,
                                 dm_license_url,
                                 languages_understood, mitm,
                                 bold_reading, dogwhistles,
                                 max_hashtags, server.buy_sites,
                                 server.sites_unavailable,
                                 server.mitm_servers,
                                 server.instance_software)
            fitness_performance(inbox_start_time, server.fitness,
                                'INBOX', 'handle_after_initial',
                                debug)
            inbox_start_time = time.time()
            if debug:
                pprint(queue_json['post'])
                print('Queue: Queue post accepted')
        if os.path.isfile(queue_filename):
            try:
                os.remove(queue_filename)
            except OSError:
                print('EX: run_inbox_queue 10 unable to delete ' +
                      str(queue_filename))
        if len(queue) > 0:
            queue.pop(0)
