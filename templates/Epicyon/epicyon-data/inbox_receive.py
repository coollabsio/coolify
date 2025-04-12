__filename__ = "inbox_receive.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
import time
from flags import is_recent_post
from flags import is_quote_toot
from utils import get_quote_toot_url
from utils import get_actor_from_post_id
from utils import contains_invalid_actor_url_chars
from utils import get_attributed_to
from utils import remove_eol
from utils import update_announce_collection
from utils import get_protocol_prefixes
from utils import contains_statuses
from utils import delete_post
from utils import remove_moderation_post_from_index
from utils import remove_domain_port
from utils import get_reply_to
from utils import acct_handle_dir
from utils import has_object_string
from utils import has_users_path
from utils import has_object_string_type
from utils import get_config_param
from utils import acct_dir
from utils import get_account_timezone
from utils import is_dm
from utils import delete_cached_html
from utils import harmless_markup
from utils import has_object_dict
from utils import remove_post_from_cache
from utils import get_cached_post_filename
from utils import get_actor_from_post
from utils import locate_post
from utils import remove_id_ending
from utils import has_actor
from utils import remove_avatar_from_cache
from utils import text_in_file
from utils import is_account_dir
from utils import data_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import save_json
from utils import load_json
from utils import get_url_from_post
from utils import remove_html
from utils import get_full_domain
from utils import get_user_paths
from cache import get_actor_public_key_from_id
from cache import store_person_in_cache
from cache import get_person_pub_key
from person import get_person_avatar_url
from filters import is_question_filtered
from question import dangerous_question
from question import question_update_votes
from posts import convert_post_content_to_html
from posts import download_announce
from posts import send_to_followers_thread
from posts import valid_post_content
from follow import send_follow_request
from follow import is_following_actor
from follow import follower_approval_active
from blocking import is_blocked
from blocking import is_blocked_domain
from blocking import is_blocked_nickname
from blocking import allowed_announce
from like import update_likes_collection
from reaction import valid_emoji_content
from reaction import update_reaction_collection
from bookmarks import update_bookmarks_collection
from announce import is_self_announce
from speaker import update_speaker
from webapp_post import individual_post_as_html
from webapp_hashtagswarm import store_hash_tags


def inbox_update_index(boxname: str, base_dir: str, handle: str,
                       destination_filename: str, debug: bool) -> bool:
    """Updates the index of received posts
    The new entry is added to the top of the file
    """
    index_filename = \
        acct_handle_dir(base_dir, handle) + '/' + boxname + '.index'
    if debug:
        print('DEBUG: Updating index ' + index_filename)

    if '/' + boxname + '/' in destination_filename:
        destination_filename = \
            destination_filename.split('/' + boxname + '/')[1]

    # remove the path
    if '/' in destination_filename:
        destination_filename = destination_filename.split('/')[-1]

    written = False
    if os.path.isfile(index_filename):
        try:
            with open(index_filename, 'r+', encoding='utf-8') as fp_index:
                content = fp_index.read()
                if destination_filename + '\n' not in content:
                    fp_index.seek(0, 0)
                    fp_index.write(destination_filename + '\n' + content)
                written = True
                return True
        except OSError as ex:
            print('EX: Failed to write entry to index ' + str(ex))
    else:
        try:
            with open(index_filename, 'w+', encoding='utf-8') as fp_index:
                fp_index.write(destination_filename + '\n')
                written = True
        except OSError as ex:
            print('EX: Failed to write initial entry to index ' + str(ex))

    return written


def _notify_moved(base_dir: str, domain_full: str,
                  prev_actor_handle: str, new_actor_handle: str,
                  prev_actor: str, prev_avatar_image_url: str,
                  http_prefix: str) -> None:
    """Notify that an actor has moved
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for account in dirs:
            if not is_account_dir(account):
                continue
            account_dir = dir_str + '/' + account
            following_filename = account_dir + '/following.txt'
            if not os.path.isfile(following_filename):
                continue
            if not text_in_file(prev_actor_handle + '\n', following_filename):
                continue
            if text_in_file(new_actor_handle + '\n', following_filename):
                continue
            # notify
            moved_file = account_dir + '/.newMoved'
            if os.path.isfile(moved_file):
                if not text_in_file('##sent##', moved_file):
                    continue

            nickname = account.split('@')[0]
            url = \
                http_prefix + '://' + domain_full + '/users/' + nickname + \
                '?options=' + prev_actor + ';1;' + prev_avatar_image_url
            moved_str = \
                prev_actor_handle + ' ' + new_actor_handle + ' ' + url

            if os.path.isfile(moved_file):
                try:
                    with open(moved_file, 'r',
                              encoding='utf-8') as fp_move:
                        prev_moved_str = fp_move.read()
                        if prev_moved_str == moved_str:
                            continue
                except OSError:
                    print('EX: _notify_moved unable to read ' + moved_file)
            try:
                with open(moved_file, 'w+', encoding='utf-8') as fp_move:
                    fp_move.write(moved_str)
            except OSError:
                print('EX: ERROR: unable to save moved notification ' +
                      moved_file)
        break


def _person_receive_update(base_dir: str,
                           domain: str, port: int,
                           update_nickname: str, update_domain: str,
                           update_port: int,
                           person_json: {}, person_cache: {},
                           debug: bool, http_prefix: str) -> bool:
    """Changes an actor. eg: avatar or display name change
    """
    url_str = get_url_from_post(person_json['url'])
    person_url = remove_html(url_str)
    if debug:
        print('Receiving actor update for ' + person_url +
              ' ' + str(person_json))
    domain_full = get_full_domain(domain, port)
    update_domain_full = get_full_domain(update_domain, update_port)
    users_paths = get_user_paths()
    users_str_found = False
    for users_str in users_paths:
        actor = update_domain_full + users_str + update_nickname
        if actor in person_json['id']:
            users_str_found = True
            break
    if not users_str_found:
        actor = update_domain_full + '/' + update_nickname
        if actor in person_json['id']:
            users_str_found = True
    if not users_str_found:
        if debug:
            print('actor: ' + actor)
            print('id: ' + person_json['id'])
            print('DEBUG: Actor does not match id')
        return False
    if update_domain_full == domain_full:
        if debug:
            print('DEBUG: You can only receive actor updates ' +
                  'for domains other than your own')
        return False
    person_pub_key, _ = \
        get_actor_public_key_from_id(person_json, None)
    if not person_pub_key:
        if debug:
            print('DEBUG: actor update does not contain a public key')
        return False
    actor_filename = base_dir + '/cache/actors/' + \
        person_json['id'].replace('/', '#') + '.json'
    # check that the public keys match.
    # If they don't then this may be a nefarious attempt to hack an account
    idx = person_json['id']
    if person_cache.get(idx):
        cache_pub_key, _ = \
            get_actor_public_key_from_id(person_cache[idx]['actor'], None)
        if cache_pub_key != person_pub_key:
            if debug:
                print('WARN: Public key does not match when updating actor')
            return False
    else:
        if os.path.isfile(actor_filename):
            existing_person_json = load_json(actor_filename)
            if existing_person_json:
                existing_pub_key, _ = \
                    get_actor_public_key_from_id(existing_person_json, None)
                if existing_pub_key != person_pub_key:
                    if debug:
                        print('WARN: Public key does not match ' +
                              'cached actor when updating')
                    return False
    # save to cache in memory
    store_person_in_cache(base_dir, idx, person_json,
                          person_cache, True)
    # save to cache on file
    if save_json(person_json, actor_filename):
        if debug:
            print('actor updated for ' + idx)

    if person_json.get('movedTo'):
        prev_domain_full = None
        prev_domain, prev_port = get_domain_from_actor(idx)
        if prev_domain:
            prev_domain_full = get_full_domain(prev_domain, prev_port)
        prev_nickname = get_nickname_from_actor(idx)
        new_domain = None
        new_domain, new_port = get_domain_from_actor(person_json['movedTo'])
        if new_domain:
            new_domain_full = get_full_domain(new_domain, new_port)
        new_nickname = get_nickname_from_actor(person_json['movedTo'])

        if prev_nickname and prev_domain_full and new_domain and \
           new_nickname and new_domain_full:
            new_actor = prev_nickname + '@' + prev_domain_full + ' ' + \
                new_nickname + '@' + new_domain_full
            refollow_str = ''
            refollow_filename = data_dir(base_dir) + '/actors_moved.txt'
            refollow_file_exists = False
            if os.path.isfile(refollow_filename):
                try:
                    with open(refollow_filename, 'r',
                              encoding='utf-8') as fp_refollow:
                        refollow_str = fp_refollow.read()
                        refollow_file_exists = True
                except OSError:
                    print('EX: _person_receive_update unable to read ' +
                          refollow_filename)
            if new_actor not in refollow_str:
                refollow_type = 'w+'
                if refollow_file_exists:
                    refollow_type = 'a+'
                try:
                    with open(refollow_filename, refollow_type,
                              encoding='utf-8') as fp_refollow:
                        fp_refollow.write(new_actor + '\n')
                except OSError:
                    print('EX: _person_receive_update unable to write to ' +
                          refollow_filename)
                prev_avatar_url = \
                    get_person_avatar_url(base_dir, person_json['id'],
                                          person_cache)
                if prev_avatar_url is None:
                    prev_avatar_url = ''
                _notify_moved(base_dir, domain_full,
                              prev_nickname + '@' + prev_domain_full,
                              new_nickname + '@' + new_domain_full,
                              person_json['id'], prev_avatar_url, http_prefix)

    # remove avatar if it exists so that it will be refreshed later
    # when a timeline is constructed
    actor_str = person_json['id'].replace('/', '-')
    remove_avatar_from_cache(base_dir, actor_str)
    return True


def _receive_update_to_question(recent_posts_cache: {}, message_json: {},
                                base_dir: str,
                                nickname: str, domain: str,
                                system_language: str,
                                allow_local_network_access: bool) -> bool:
    """Updating a question as new votes arrive
    """
    # message url of the question
    if not message_json.get('id'):
        return False
    if not has_actor(message_json, False):
        return False
    message_id = remove_id_ending(message_json['id'])
    if '#' in message_id:
        message_id = message_id.split('#', 1)[0]
    # find the question post
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        return False
    # load the json for the question
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return False
    if not post_json_object.get('actor'):
        return False
    if is_question_filtered(base_dir, nickname, domain,
                            system_language, post_json_object):
        return False
    if dangerous_question(post_json_object, allow_local_network_access):
        return False
    # does the actor match?
    actor_url = get_actor_from_post(post_json_object)
    actor_url2 = get_actor_from_post(message_json)
    if actor_url != actor_url2:
        return False
    save_json(message_json, post_filename)
    # ensure that the cached post is removed if it exists, so
    # that it then will be recreated
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, message_json)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
            except OSError:
                print('EX: _receive_update_to_question unable to delete ' +
                      cached_post_filename)
    # remove from memory cache
    remove_post_from_cache(message_json, recent_posts_cache)
    return True


def receive_edit_to_post(recent_posts_cache: {}, message_json: {},
                         base_dir: str,
                         nickname: str, domain: str,
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
                         instance_software: {}) -> bool:
    """A post was edited
    """
    if not has_object_dict(message_json):
        return False
    if not message_json['object'].get('id'):
        return False
    if not message_json.get('actor'):
        return False
    if not has_actor(message_json, False):
        return False
    if not has_actor(message_json['object'], False):
        return False
    message_id = remove_id_ending(message_json['object']['id'])
    if '#' in message_id:
        message_id = message_id.split('#', 1)[0]
    # find the original post which was edited
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        print('EDITPOST: ' + message_id + ' has already expired')
        return False
    convert_post_content_to_html(message_json)
    harmless_markup(message_json)
    if not valid_post_content(base_dir, nickname, domain,
                              message_json, max_mentions, max_emoji,
                              allow_local_network_access, debug,
                              system_language, http_prefix,
                              domain_full, person_cache,
                              max_hashtags, onion_domain, i2p_domain):
        print('EDITPOST: contains invalid content' + str(message_json))
        return False

    # load the json for the original post
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return False
    if not post_json_object.get('actor'):
        return False
    if not has_object_dict(post_json_object):
        return False
    if 'content' not in post_json_object['object']:
        return False
    if 'content' not in message_json['object']:
        return False
    # does the actor match?
    actor_url = get_actor_from_post(post_json_object)
    actor_url2 = get_actor_from_post(message_json)
    if actor_url != actor_url2:
        print('EDITPOST: actors do not match ' +
              actor_url + ' != ' + actor_url2)
        return False
    # has the content changed?
    if post_json_object['object']['content'] == \
       message_json['object']['content']:
        # same content. Has the summary changed?
        if 'summary' in post_json_object['object'] and \
           'summary' in message_json['object']:
            if post_json_object['object']['summary'] == \
               message_json['object']['summary']:
                return False
        else:
            return False
    # save the edit history to file
    post_history_filename = post_filename.replace('.json', '') + '.edits'
    post_history_json = {}
    if os.path.isfile(post_history_filename):
        post_history_json = load_json(post_history_filename)
    # get the updated or published date
    if post_json_object['object'].get('updated'):
        published_str = post_json_object['object']['updated']
    else:
        published_str = post_json_object['object']['published']
    # add to the history for this date
    if not post_history_json.get(published_str):
        post_history_json[published_str] = post_json_object
        save_json(post_history_json, post_history_filename)
    # Change Update to Create
    message_json['type'] = 'Create'
    save_json(message_json, post_filename)
    # if the post has been saved both within the outbox and inbox
    # (eg. edited reminder)
    if '/outbox/' in post_filename:
        inbox_post_filename = post_filename.replace('/outbox/', '/inbox/')
        if os.path.isfile(inbox_post_filename):
            save_json(message_json, inbox_post_filename)
    # ensure that the cached post is removed if it exists, so
    # that it then will be recreated
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, message_json)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
            except OSError:
                print('EX: _receive_edit_to_post unable to delete ' +
                      cached_post_filename)
    # remove any cached html for the post which was edited
    delete_cached_html(base_dir, nickname, domain, post_json_object)
    # remove from memory cache
    remove_post_from_cache(message_json, recent_posts_cache)
    # regenerate html for the post
    page_number = 1
    show_published_date_only = False
    show_individual_post_icons = True
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    not_dm = not is_dm(message_json)
    timezone = get_account_timezone(base_dir, nickname, domain)
    mitm = False
    if os.path.isfile(post_filename.replace('.json', '') + '.mitm'):
        mitm = True
    bold_reading = False
    bold_reading_filename = \
        acct_dir(base_dir, nickname, domain) + '/.boldReading'
    if os.path.isfile(bold_reading_filename):
        bold_reading = True
    timezone = get_account_timezone(base_dir, nickname, domain)
    lists_enabled = get_config_param(base_dir, "listsEnabled")
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    individual_post_as_html(signing_priv_key_pem, False,
                            recent_posts_cache, max_recent_posts,
                            translate, page_number, base_dir,
                            session, cached_webfingers, person_cache,
                            nickname, domain, port, message_json,
                            None, True, allow_deletion,
                            http_prefix, __version__, 'inbox',
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            theme_name, system_language,
                            max_like_count, not_dm,
                            show_individual_post_icons,
                            manually_approve_followers,
                            False, True, False, cw_lists,
                            lists_enabled, timezone, mitm,
                            bold_reading, dogwhistles,
                            minimize_all_images, None,
                            buy_sites, auto_cw_cache,
                            mitm_servers, instance_software)
    return True


def receive_move_activity(session, base_dir: str,
                          http_prefix: str, domain: str, port: int,
                          cached_webfingers: {},
                          person_cache: {}, message_json: {},
                          nickname: str, debug: bool,
                          signing_priv_key_pem: str,
                          send_threads: [],
                          post_log: [],
                          federation_list: [],
                          onion_domain: str,
                          i2p_domain: str,
                          sites_unavailable: [],
                          blocked_cache: [],
                          block_federated: [],
                          system_language: str,
                          mitm_servers: []) -> bool:
    """Receives a move activity within the POST section of HTTPServer
    https://codeberg.org/fediverse/fep/src/branch/main/fep/7628/fep-7628.md
    """
    if message_json['type'] != 'Move':
        return False
    if not has_actor(message_json, debug):
        if debug:
            print('INBOX: Move activity has no actor: ' + str(message_json))
        return False
    if not message_json.get('object'):
        if debug:
            print('INBOX: Move activity object not found: ' +
                  str(message_json))
        return False
    if not isinstance(message_json['object'], str):
        if debug:
            print('INBOX: Move activity object is not a string: ' +
                  str(message_json))
        return False
    if not message_json.get('target'):
        if debug:
            print('INBOX: Move activity has no target')
        return False
    if not isinstance(message_json['target'], str):
        if debug:
            print('INBOX: Move activity target is not a string: ' +
                  str(message_json['target']))
        return False
    previous_actor = None
    actor_url = get_actor_from_post(message_json)
    if message_json['object'] == actor_url:
        print('INBOX: Move activity sent by old actor ' +
              actor_url + ' moving to ' + message_json['target'])
        previous_actor = actor_url
    elif message_json['target'] == actor_url:
        print('INBOX: Move activity sent by new actor ' +
              actor_url + ' moving from ' +
              message_json['object'])
        previous_actor = message_json['object']
    if not previous_actor:
        print('INBOX: Move activity previous actor not found: ' +
              str(message_json))
    moved_actor = message_json['target']
    # are we following the previous actor?
    if not is_following_actor(base_dir, nickname, domain, previous_actor):
        print('INBOX: Move activity not following previous actor: ' +
              nickname + ' ' + previous_actor)
        return False
    # are we already following the moved actor?
    if is_following_actor(base_dir, nickname, domain, moved_actor):
        print('INBOX: Move activity not following previous actor: ' +
              nickname + ' ' + moved_actor)
        return False
    # follow the moved actor
    moved_nickname = get_nickname_from_actor(moved_actor)
    if not moved_nickname:
        print('INBOX: Move activity invalid actor: ' + moved_actor)
        return False
    moved_domain, moved_port = get_domain_from_actor(moved_actor)
    if not moved_domain:
        print('INBOX: Move activity invalid domain: ' + moved_actor)
        return False
    # is the moved actor blocked?
    if is_blocked(base_dir, nickname, domain,
                  moved_nickname, moved_domain,
                  blocked_cache, block_federated):
        print('INBOX: Move activity actor is blocked: ' + moved_actor)
        return False
    print('INBOX: Move activity sending follow request: ' +
          nickname + ' ' + moved_actor)
    send_follow_request(session,
                        base_dir, nickname,
                        domain, domain, port,
                        http_prefix,
                        moved_nickname,
                        moved_domain,
                        moved_actor,
                        moved_port, http_prefix,
                        False, federation_list,
                        send_threads,
                        post_log,
                        cached_webfingers,
                        person_cache, debug,
                        __version__,
                        signing_priv_key_pem,
                        domain,
                        onion_domain,
                        i2p_domain,
                        sites_unavailable,
                        system_language,
                        mitm_servers)
    return True


def receive_update_activity(recent_posts_cache: {}, session, base_dir: str,
                            http_prefix: str, domain: str, port: int,
                            cached_webfingers: {},
                            person_cache: {}, message_json: {},
                            nickname: str, debug: bool,
                            max_mentions: int, max_emoji: int,
                            allow_local_network_access: bool,
                            system_language: str,
                            signing_priv_key_pem: str,
                            max_recent_posts: int, translate: {},
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
                            instance_software: {}) -> bool:
    """Receives an Update activity within the POST section of HTTPServer
    """
    if message_json['type'] != 'Update':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string_type(message_json, debug):
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'])
        return False

    if message_json['object']['type'] == 'Question':
        if _receive_update_to_question(recent_posts_cache, message_json,
                                       base_dir, nickname, domain,
                                       system_language,
                                       allow_local_network_access):
            if debug:
                print('DEBUG: Question update was received')
            return True
    elif message_json['object']['type'] in ('Note', 'Event'):
        if message_json['object'].get('id'):
            domain_full = get_full_domain(domain, port)
            if receive_edit_to_post(recent_posts_cache, message_json,
                                    base_dir, nickname, domain,
                                    max_mentions, max_emoji,
                                    allow_local_network_access,
                                    debug, system_language, http_prefix,
                                    domain_full, person_cache,
                                    signing_priv_key_pem,
                                    max_recent_posts, translate,
                                    session, cached_webfingers, port,
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
                                    instance_software):
                print('EDITPOST: received ' + message_json['object']['id'])
                return True
        else:
            print('EDITPOST: rejected ' + str(message_json))
            return False

    if message_json['object']['type'] == 'Person' or \
       message_json['object']['type'] == 'Application' or \
       message_json['object']['type'] == 'Group' or \
       message_json['object']['type'] == 'Service':
        if message_json['object'].get('url') and \
           message_json['object'].get('id'):
            if debug:
                print('Request to update actor: ' + str(message_json))
            actor_url = get_actor_from_post(message_json)
            update_nickname = get_nickname_from_actor(actor_url)
            update_domain, update_port = \
                get_domain_from_actor(actor_url)
            if update_nickname and update_domain:
                if _person_receive_update(base_dir,
                                          domain, port,
                                          update_nickname, update_domain,
                                          update_port,
                                          message_json['object'],
                                          person_cache, debug, http_prefix):
                    print('Person Update: ' + str(message_json))
                    if debug:
                        print('DEBUG: Profile update was received for ' +
                              str(message_json['object']['url']))
                        return True
    return False


def _already_liked(base_dir: str, nickname: str, domain: str,
                   post_url: str, liker_actor: str) -> bool:
    """Is the given post already liked by the given handle?
    """
    post_filename = \
        locate_post(base_dir, nickname, domain, post_url)
    if not post_filename:
        return False
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return False
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('likes'):
        return False
    if not post_json_object['object']['likes'].get('items'):
        return False
    for like in post_json_object['object']['likes']['items']:
        if not like.get('type'):
            continue
        if not like.get('actor'):
            continue
        if like['type'] != 'Like':
            continue
        if like['actor'] == liker_actor:
            return True
    return False


def _already_reacted(base_dir: str, nickname: str, domain: str,
                     post_url: str, reaction_actor: str,
                     emoji_content: str) -> bool:
    """Is the given post already emoji reacted by the given handle?
    """
    post_filename = \
        locate_post(base_dir, nickname, domain, post_url)
    if not post_filename:
        return False
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return False
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('reactions'):
        return False
    if not post_json_object['object']['reactions'].get('items'):
        return False
    for react in post_json_object['object']['reactions']['items']:
        if not react.get('type'):
            continue
        if not react.get('content'):
            continue
        if not react.get('actor'):
            continue
        if react['type'] != 'EmojiReact':
            continue
        if react['content'] != emoji_content:
            continue
        if react['actor'] == reaction_actor:
            return True
    return False


def _like_notify(base_dir: str, domain: str,
                 onion_domain: str, i2p_domain: str,
                 handle: str, actor: str, url: str) -> None:
    """Creates a notification that a like has arrived
    """
    # This is not you liking your own post
    if actor in url:
        return

    # check that the liked post was by this handle
    nickname = handle.split('@')[0]
    if '/' + domain + '/users/' + nickname not in url:
        if onion_domain:
            if '/' + onion_domain + '/users/' + nickname not in url:
                return
        if i2p_domain:
            if '/' + i2p_domain + '/users/' + nickname not in url:
                return
        if not i2p_domain and not onion_domain:
            return

    account_dir = acct_handle_dir(base_dir,  handle)

    # are like notifications enabled?
    notify_likes_enabled_filename = account_dir + '/.notifyLikes'
    if not os.path.isfile(notify_likes_enabled_filename):
        return

    like_file = account_dir + '/.newLike'
    if os.path.isfile(like_file):
        if not text_in_file('##sent##', like_file):
            return

    liker_nickname = get_nickname_from_actor(actor)
    liker_domain, _ = get_domain_from_actor(actor)
    if liker_nickname and liker_domain:
        liker_handle = liker_nickname + '@' + liker_domain
    else:
        print('_like_notify liker_handle: ' +
              str(liker_nickname) + '@' + str(liker_domain))
        liker_handle = actor
    if liker_handle == handle:
        return

    like_str = liker_handle + ' ' + url + '?likedBy=' + actor
    prev_like_file = account_dir + '/.prevLike'
    # was there a previous like notification?
    if os.path.isfile(prev_like_file):
        # is it the same as the current notification ?
        try:
            with open(prev_like_file, 'r', encoding='utf-8') as fp_like:
                prev_like_str = fp_like.read()
                if prev_like_str == like_str:
                    return
        except OSError:
            print('EX: _like_notify unable to read ' + prev_like_file)
    try:
        with open(prev_like_file, 'w+', encoding='utf-8') as fp_like:
            fp_like.write(like_str)
    except OSError:
        print('EX: ERROR: unable to save previous like notification ' +
              prev_like_file)

    try:
        with open(like_file, 'w+', encoding='utf-8') as fp_like:
            fp_like.write(like_str)
    except OSError:
        print('EX: ERROR: unable to write like notification file ' +
              like_file)


def _reaction_notify(base_dir: str, domain: str, onion_domain: str,
                     handle: str, actor: str,
                     url: str, emoji_content: str) -> None:
    """Creates a notification that an emoji reaction has arrived
    """
    # This is not you reacting to your own post
    if actor in url:
        return

    # check that the reaction post was by this handle
    nickname = handle.split('@')[0]
    if '/' + domain + '/users/' + nickname not in url:
        if not onion_domain:
            return
        if '/' + onion_domain + '/users/' + nickname not in url:
            return

    account_dir = acct_handle_dir(base_dir, handle)

    # are reaction notifications enabled?
    notify_reaction_enabled_filename = account_dir + '/.notifyReactions'
    if not os.path.isfile(notify_reaction_enabled_filename):
        return

    reaction_file = account_dir + '/.newReaction'
    if os.path.isfile(reaction_file):
        if not text_in_file('##sent##', reaction_file):
            return

    reaction_nickname = get_nickname_from_actor(actor)
    reaction_domain, _ = get_domain_from_actor(actor)
    if reaction_nickname and reaction_domain:
        reaction_handle = reaction_nickname + '@' + reaction_domain
    else:
        print('_reaction_notify reaction_handle: ' +
              str(reaction_nickname) + '@' + str(reaction_domain))
        reaction_handle = actor
    if reaction_handle == handle:
        return
    reaction_str = \
        reaction_handle + ' ' + url + '?reactBy=' + actor + \
        ';emoj=' + emoji_content
    prev_reaction_file = account_dir + '/.prevReaction'
    # was there a previous reaction notification?
    if os.path.isfile(prev_reaction_file):
        # is it the same as the current notification ?
        try:
            with open(prev_reaction_file, 'r', encoding='utf-8') as fp_react:
                prev_reaction_str = fp_react.read()
                if prev_reaction_str == reaction_str:
                    return
        except OSError:
            print('EX: _reaction_notify unable to read ' + prev_reaction_file)
    try:
        with open(prev_reaction_file, 'w+', encoding='utf-8') as fp_react:
            fp_react.write(reaction_str)
    except OSError:
        print('EX: ERROR: unable to save previous reaction notification ' +
              prev_reaction_file)

    try:
        with open(reaction_file, 'w+', encoding='utf-8') as fp_react:
            fp_react.write(reaction_str)
    except OSError:
        print('EX: ERROR: unable to write reaction notification file ' +
              reaction_file)


def receive_like(recent_posts_cache: {},
                 session, handle: str, base_dir: str,
                 http_prefix: str, domain: str, port: int,
                 onion_domain: str, i2p_domain: str,
                 cached_webfingers: {},
                 person_cache: {}, message_json: {},
                 debug: bool,
                 signing_priv_key_pem: str,
                 max_recent_posts: int, translate: {},
                 allow_deletion: bool,
                 yt_replace_domain: str,
                 twitter_replacement_domain: str,
                 peertube_instances: [],
                 allow_local_network_access: bool,
                 theme_name: str, system_language: str,
                 max_like_count: int, cw_lists: {},
                 lists_enabled: str,
                 bold_reading: bool, dogwhistles: {},
                 min_images_for_accounts: [],
                 buy_sites: {},
                 auto_cw_cache: {},
                 mitm_servers: [],
                 instance_software: {}) -> bool:
    """Receives a Like activity within the POST section of HTTPServer
    """
    if message_json['type'] != 'Like':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string(message_json, debug):
        return False
    if not message_json.get('to'):
        if debug:
            print('DEBUG: ' + message_json['type'] + ' has no "to" list')
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'])
        return False
    if '/statuses/' not in message_json['object']:
        if debug:
            print('DEBUG: "statuses" missing from object in ' +
                  message_json['type'])
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of like - ' + handle)
    # if this post in the outbox of the person?
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    post_liked_id = message_json['object']
    post_filename = \
        locate_post(base_dir, handle_name, handle_dom, post_liked_id)
    if not post_filename:
        if debug:
            print('DEBUG: post not found in inbox or outbox')
            print(post_liked_id)
        return True
    if debug:
        print('DEBUG: liked post found in inbox')

    like_actor = get_actor_from_post(message_json)
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    if not _already_liked(base_dir,
                          handle_name, handle_dom,
                          post_liked_id,
                          like_actor):
        _like_notify(base_dir, domain, onion_domain, i2p_domain, handle,
                     like_actor, post_liked_id)
    update_likes_collection(recent_posts_cache, base_dir, post_filename,
                            post_liked_id, like_actor,
                            handle_name, domain, debug, None)
    # regenerate the html
    liked_post_json = load_json(post_filename)
    if liked_post_json:
        if liked_post_json.get('type'):
            if liked_post_json['type'] == 'Announce' and \
               liked_post_json.get('object'):
                if isinstance(liked_post_json['object'], str):
                    announce_like_url = liked_post_json['object']
                    announce_liked_filename = \
                        locate_post(base_dir, handle_name,
                                    domain, announce_like_url)
                    if announce_liked_filename:
                        post_liked_id = announce_like_url
                        post_filename = announce_liked_filename
                        update_likes_collection(recent_posts_cache,
                                                base_dir,
                                                post_filename,
                                                post_liked_id,
                                                like_actor,
                                                handle_name,
                                                domain, debug, None)
        if liked_post_json:
            if debug:
                cached_post_filename = \
                    get_cached_post_filename(base_dir, handle_name, domain,
                                             liked_post_json)
                print('Liked post json: ' + str(liked_post_json))
                print('Liked post nickname: ' + handle_name + ' ' + domain)
                print('Liked post cache: ' + str(cached_post_filename))
            page_number = 1
            show_published_date_only = False
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir, handle_name, domain)
            not_dm = not is_dm(liked_post_json)
            timezone = get_account_timezone(base_dir, handle_name, domain)
            mitm = False
            if os.path.isfile(post_filename.replace('.json', '') + '.mitm'):
                mitm = True
            minimize_all_images = False
            if handle_name in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem, False,
                                    recent_posts_cache, max_recent_posts,
                                    translate, page_number, base_dir,
                                    session, cached_webfingers, person_cache,
                                    handle_name, domain, port, liked_post_json,
                                    None, True, allow_deletion,
                                    http_prefix, __version__,
                                    'inbox',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count, not_dm,
                                    show_individual_post_icons,
                                    manually_approve_followers,
                                    False, True, False, cw_lists,
                                    lists_enabled, timezone, mitm,
                                    bold_reading, dogwhistles,
                                    minimize_all_images, None, buy_sites,
                                    auto_cw_cache, mitm_servers,
                                    instance_software)
    return True


def receive_reaction(recent_posts_cache: {},
                     session, handle: str, base_dir: str,
                     http_prefix: str, domain: str, port: int,
                     onion_domain: str,
                     cached_webfingers: {},
                     person_cache: {}, message_json: {},
                     debug: bool,
                     signing_priv_key_pem: str,
                     max_recent_posts: int, translate: {},
                     allow_deletion: bool,
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     peertube_instances: [],
                     allow_local_network_access: bool,
                     theme_name: str, system_language: str,
                     max_like_count: int, cw_lists: {},
                     lists_enabled: str, bold_reading: bool,
                     dogwhistles: {},
                     min_images_for_accounts: [],
                     buy_sites: {},
                     auto_cw_cache: {},
                     mitm_servers: [],
                     instance_software: {}) -> bool:
    """Receives an emoji reaction within the POST section of HTTPServer
    """
    if message_json['type'] != 'EmojiReact':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string(message_json, debug):
        return False
    if 'content' not in message_json:
        if debug:
            print('DEBUG: ' + message_json['type'] + ' has no "content"')
        return False
    if not isinstance(message_json['content'], str):
        if debug:
            print('DEBUG: ' + message_json['type'] + ' content is not string')
        return False
    actor_url = get_actor_from_post(message_json)
    if not valid_emoji_content(message_json['content']):
        print('_receive_reaction: Invalid emoji reaction: "' +
              message_json['content'] + '" from ' + actor_url)
        return False
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'])
        return False
    if '/statuses/' not in message_json['object']:
        if debug:
            print('DEBUG: "statuses" missing from object in ' +
                  message_json['type'])
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of emoji reaction - ' + handle)
    if os.path.isfile(handle_dir + '/.hideReactionButton'):
        print('Emoji reaction rejected by ' + handle +
              ' due to their settings')
        return True
    # if this post in the outbox of the person?
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]

    post_reaction_id = message_json['object']
    emoji_content = remove_html(message_json['content'])
    if not emoji_content:
        if debug:
            print('DEBUG: emoji reaction has no content')
        return True
    post_filename = locate_post(base_dir, handle_name, handle_dom,
                                post_reaction_id)
    if not post_filename:
        if debug:
            print('DEBUG: emoji reaction post not found in inbox or outbox')
            print(post_reaction_id)
        return True
    if debug:
        print('DEBUG: emoji reaction post found in inbox')

    reaction_actor = get_actor_from_post(message_json)
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    if not _already_reacted(base_dir,
                            handle_name, handle_dom,
                            post_reaction_id,
                            reaction_actor,
                            emoji_content):
        _reaction_notify(base_dir, domain, onion_domain, handle,
                         reaction_actor, post_reaction_id, emoji_content)
    update_reaction_collection(recent_posts_cache, base_dir, post_filename,
                               post_reaction_id, reaction_actor,
                               handle_name, domain, debug, None, emoji_content)
    # regenerate the html
    reaction_post_json = load_json(post_filename)
    if reaction_post_json:
        if reaction_post_json.get('type'):
            if reaction_post_json['type'] == 'Announce' and \
               reaction_post_json.get('object'):
                if isinstance(reaction_post_json['object'], str):
                    announce_reaction_url = reaction_post_json['object']
                    announce_reaction_filename = \
                        locate_post(base_dir, handle_name,
                                    domain, announce_reaction_url)
                    if announce_reaction_filename:
                        post_reaction_id = announce_reaction_url
                        post_filename = announce_reaction_filename
                        update_reaction_collection(recent_posts_cache,
                                                   base_dir,
                                                   post_filename,
                                                   post_reaction_id,
                                                   reaction_actor,
                                                   handle_name,
                                                   domain, debug, None,
                                                   emoji_content)
        if reaction_post_json:
            if debug:
                cached_post_filename = \
                    get_cached_post_filename(base_dir, handle_name, domain,
                                             reaction_post_json)
                print('Reaction post json: ' + str(reaction_post_json))
                print('Reaction post nickname: ' + handle_name + ' ' + domain)
                print('Reaction post cache: ' + str(cached_post_filename))
            page_number = 1
            show_published_date_only = False
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir, handle_name, domain)
            not_dm = not is_dm(reaction_post_json)
            timezone = get_account_timezone(base_dir, handle_name, domain)
            mitm = False
            if os.path.isfile(post_filename.replace('.json', '') + '.mitm'):
                mitm = True
            minimize_all_images = False
            if handle_name in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem, False,
                                    recent_posts_cache, max_recent_posts,
                                    translate, page_number, base_dir,
                                    session, cached_webfingers, person_cache,
                                    handle_name, domain, port,
                                    reaction_post_json,
                                    None, True, allow_deletion,
                                    http_prefix, __version__,
                                    'inbox',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count, not_dm,
                                    show_individual_post_icons,
                                    manually_approve_followers,
                                    False, True, False, cw_lists,
                                    lists_enabled, timezone, mitm,
                                    bold_reading, dogwhistles,
                                    minimize_all_images, None, buy_sites,
                                    auto_cw_cache, mitm_servers,
                                    instance_software)
    return True


def receive_zot_reaction(recent_posts_cache: {},
                         session, handle: str, base_dir: str,
                         http_prefix: str, domain: str, port: int,
                         onion_domain: str,
                         cached_webfingers: {},
                         person_cache: {}, message_json: {},
                         debug: bool,
                         signing_priv_key_pem: str,
                         max_recent_posts: int, translate: {},
                         allow_deletion: bool,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         peertube_instances: [],
                         allow_local_network_access: bool,
                         theme_name: str, system_language: str,
                         max_like_count: int, cw_lists: {},
                         lists_enabled: str, bold_reading: bool,
                         dogwhistles: {},
                         min_images_for_accounts: [],
                         buy_sites: {},
                         auto_cw_cache: {},
                         mitm_servers: [],
                         instance_software: {}) -> bool:
    """Receives an zot-style emoji reaction within the POST section of
    HTTPServer A zot style emoji reaction is an ordinary reply Note whose
    content is exactly one emoji
    """
    if not has_actor(message_json, debug):
        return False
    if not has_object_dict(message_json):
        return False
    if not message_json['object'].get('type'):
        return False
    if not isinstance(message_json['object']['type'], str):
        return False
    if message_json['object']['type'] != 'Note':
        return False
    if 'content' not in message_json['object']:
        if debug:
            print('DEBUG: ' + message_json['object']['type'] +
                  ' has no "content"')
        return False
    reply_id = get_reply_to(message_json['object'])
    if not reply_id:
        if debug:
            print('DEBUG: ' + message_json['object']['type'] +
                  ' has no "inReplyTo"')
        return False
    if not isinstance(message_json['object']['content'], str):
        if debug:
            print('DEBUG: ' + message_json['object']['type'] +
                  ' content is not string')
        return False
    if len(message_json['object']['content']) > 4:
        if debug:
            print('DEBUG: content is too long to be an emoji reaction')
        return False
    if not isinstance(reply_id, str):
        if debug:
            print('DEBUG: ' + message_json['object']['type'] +
                  ' inReplyTo is not string')
        return False
    actor_url = get_actor_from_post(message_json)
    if not valid_emoji_content(message_json['object']['content']):
        print('_receive_zot_reaction: Invalid emoji reaction: "' +
              message_json['object']['content'] + '" from ' +
              actor_url)
        return False
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['object']['type'])
        return False
    if '/statuses/' not in reply_id:
        if debug:
            print('DEBUG: "statuses" missing from inReplyTo in ' +
                  message_json['object']['type'])
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of zot emoji reaction - ' + handle)
    if os.path.isfile(handle_dir + '/.hideReactionButton'):
        print('Zot emoji reaction rejected by ' + handle +
              ' due to their settings')
        return True
    # if this post in the outbox of the person?
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]

    post_reaction_id = get_reply_to(message_json['object'])
    emoji_content = remove_html(message_json['object']['content'])
    if not emoji_content:
        if debug:
            print('DEBUG: zot emoji reaction has no content')
        return True
    post_filename = locate_post(base_dir, handle_name, handle_dom,
                                post_reaction_id)
    if not post_filename:
        if debug:
            print('DEBUG: ' +
                  'zot emoji reaction post not found in inbox or outbox')
            print(post_reaction_id)
        return True
    if debug:
        print('DEBUG: zot emoji reaction post found in inbox')

    reaction_actor = get_actor_from_post(message_json)
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    if not _already_reacted(base_dir,
                            handle_name, handle_dom,
                            post_reaction_id,
                            reaction_actor,
                            emoji_content):
        _reaction_notify(base_dir, domain, onion_domain, handle,
                         reaction_actor, post_reaction_id, emoji_content)
    update_reaction_collection(recent_posts_cache, base_dir, post_filename,
                               post_reaction_id, reaction_actor,
                               handle_name, domain, debug, None, emoji_content)
    # regenerate the html
    reaction_post_json = load_json(post_filename)
    if reaction_post_json:
        if reaction_post_json.get('type'):
            if reaction_post_json['type'] == 'Announce' and \
               reaction_post_json.get('object'):
                if isinstance(reaction_post_json['object'], str):
                    announce_reaction_url = reaction_post_json['object']
                    announce_reaction_filename = \
                        locate_post(base_dir, handle_name,
                                    domain, announce_reaction_url)
                    if announce_reaction_filename:
                        post_reaction_id = announce_reaction_url
                        post_filename = announce_reaction_filename
                        update_reaction_collection(recent_posts_cache,
                                                   base_dir,
                                                   post_filename,
                                                   post_reaction_id,
                                                   reaction_actor,
                                                   handle_name,
                                                   domain, debug, None,
                                                   emoji_content)
        if reaction_post_json:
            if debug:
                cached_post_filename = \
                    get_cached_post_filename(base_dir, handle_name, domain,
                                             reaction_post_json)
                print('Reaction post json: ' + str(reaction_post_json))
                print('Reaction post nickname: ' + handle_name + ' ' + domain)
                print('Reaction post cache: ' + str(cached_post_filename))
            page_number = 1
            show_published_date_only = False
            show_individual_post_icons = True
            manually_approve_followers = \
                follower_approval_active(base_dir, handle_name, domain)
            not_dm = not is_dm(reaction_post_json)
            timezone = get_account_timezone(base_dir, handle_name, domain)
            mitm = False
            if os.path.isfile(post_filename.replace('.json', '') + '.mitm'):
                mitm = True
            minimize_all_images = False
            if handle_name in min_images_for_accounts:
                minimize_all_images = True
            individual_post_as_html(signing_priv_key_pem, False,
                                    recent_posts_cache, max_recent_posts,
                                    translate, page_number, base_dir,
                                    session, cached_webfingers, person_cache,
                                    handle_name, domain, port,
                                    reaction_post_json,
                                    None, True, allow_deletion,
                                    http_prefix, __version__,
                                    'inbox',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count, not_dm,
                                    show_individual_post_icons,
                                    manually_approve_followers,
                                    False, True, False, cw_lists,
                                    lists_enabled, timezone, mitm,
                                    bold_reading, dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites, auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
    return True


def receive_bookmark(recent_posts_cache: {},
                     session, handle: str, base_dir: str,
                     http_prefix: str, domain: str, port: int,
                     cached_webfingers: {},
                     person_cache: {}, message_json: {},
                     debug: bool, signing_priv_key_pem: str,
                     max_recent_posts: int, translate: {},
                     allow_deletion: bool,
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     peertube_instances: [],
                     allow_local_network_access: bool,
                     theme_name: str, system_language: str,
                     max_like_count: int, cw_lists: {},
                     lists_enabled: {}, bold_reading: bool,
                     dogwhistles: {},
                     min_images_for_accounts: [],
                     buy_sites: {},
                     auto_cw_cache: {},
                     mitm_servers: [],
                     instance_software: {}) -> bool:
    """Receives a bookmark activity within the POST section of HTTPServer
    """
    if not message_json.get('type'):
        return False
    if message_json['type'] != 'Add':
        return False
    if not has_actor(message_json, debug):
        return False
    if not message_json.get('target'):
        if debug:
            print('DEBUG: no target in inbox bookmark Add')
        return False
    if not has_object_string_type(message_json, debug):
        return False
    if not isinstance(message_json['target'], str):
        if debug:
            print('DEBUG: inbox bookmark Add target is not string')
        return False
    domain_full = get_full_domain(domain, port)
    nickname = handle.split('@')[0]
    actor_url = get_actor_from_post(message_json)
    if not actor_url.endswith(domain_full + '/users/' + nickname):
        if debug:
            print('DEBUG: inbox bookmark Add unexpected actor')
        return False
    if not message_json['target'].endswith(actor_url +
                                           '/tlbookmarks'):
        if debug:
            print('DEBUG: inbox bookmark Add target invalid ' +
                  message_json['target'])
        return False
    if message_json['object']['type'] != 'Document':
        if debug:
            print('DEBUG: inbox bookmark Add type is not Document')
        return False
    if not message_json['object'].get('url'):
        if debug:
            print('DEBUG: inbox bookmark Add missing url')
        return False
    url_str = get_url_from_post(message_json['object']['url'])
    if '/statuses/' not in url_str:
        if debug:
            print('DEBUG: inbox bookmark Add missing statuses un url')
        return False
    if debug:
        print('DEBUG: c2s inbox bookmark Add request arrived in outbox')

    message_url2 = remove_html(url_str)
    message_url = remove_id_ending(message_url2)
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_url)
    if not post_filename:
        if debug:
            print('DEBUG: c2s inbox like post not found in inbox or outbox')
            print(message_url)
        return True

    update_bookmarks_collection(recent_posts_cache, base_dir, post_filename,
                                message_url2, actor_url, domain, debug)
    # regenerate the html
    bookmarked_post_json = load_json(post_filename)
    if bookmarked_post_json:
        if debug:
            cached_post_filename = \
                get_cached_post_filename(base_dir, nickname, domain,
                                         bookmarked_post_json)
            print('Bookmarked post json: ' + str(bookmarked_post_json))
            print('Bookmarked post nickname: ' + nickname + ' ' + domain)
            print('Bookmarked post cache: ' + str(cached_post_filename))
        page_number = 1
        show_published_date_only = False
        show_individual_post_icons = True
        manually_approve_followers = \
            follower_approval_active(base_dir, nickname, domain)
        not_dm = not is_dm(bookmarked_post_json)
        timezone = get_account_timezone(base_dir, nickname, domain)
        mitm = False
        if os.path.isfile(post_filename.replace('.json', '') + '.mitm'):
            mitm = True
        minimize_all_images = False
        if nickname in min_images_for_accounts:
            minimize_all_images = True
        individual_post_as_html(signing_priv_key_pem, False,
                                recent_posts_cache, max_recent_posts,
                                translate, page_number, base_dir,
                                session, cached_webfingers, person_cache,
                                nickname, domain, port, bookmarked_post_json,
                                None, True, allow_deletion,
                                http_prefix, __version__,
                                'inbox',
                                yt_replace_domain,
                                twitter_replacement_domain,
                                show_published_date_only,
                                peertube_instances,
                                allow_local_network_access,
                                theme_name, system_language,
                                max_like_count, not_dm,
                                show_individual_post_icons,
                                manually_approve_followers,
                                False, True, False, cw_lists,
                                lists_enabled, timezone, mitm,
                                bold_reading, dogwhistles,
                                minimize_all_images, None,
                                buy_sites, auto_cw_cache,
                                mitm_servers,
                                instance_software)
    return True


def receive_delete(handle: str, base_dir: str,
                   http_prefix: str, domain: str, port: int,
                   message_json: {},
                   debug: bool, allow_deletion: bool,
                   recent_posts_cache: {}) -> bool:
    """Receives a Delete activity within the POST section of HTTPServer
    """
    if message_json['type'] != 'Delete':
        return False
    if not has_actor(message_json, debug):
        return False
    if debug:
        print('DEBUG: Delete activity arrived')
    if not has_object_string(message_json, debug):
        return False
    domain_full = get_full_domain(domain, port)
    delete_prefix = http_prefix + '://' + domain_full + '/'
    actor_url = get_actor_from_post(message_json)
    if (not allow_deletion and
        (not message_json['object'].startswith(delete_prefix) or
         not actor_url.startswith(delete_prefix))):
        if debug:
            print('DEBUG: delete not permitted from other instances')
        return False
    if not message_json.get('to'):
        if debug:
            print('DEBUG: ' + message_json['type'] + ' has no "to" list')
        return False
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: ' +
                  '"users" or "profile" missing from actor in ' +
                  message_json['type'])
        return False
    if '/statuses/' not in message_json['object']:
        if debug:
            print('DEBUG: "statuses" missing from object in ' +
                  message_json['type'])
        return False
    if actor_url not in message_json['object']:
        if debug:
            print('DEBUG: actor is not the owner of the post to be deleted')
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of like - ' + handle)
    # if this post in the outbox of the person?
    message_id = remove_id_ending(message_json['object'])
    remove_moderation_post_from_index(base_dir, message_id, debug)
    handle_nickname = handle.split('@')[0]
    handle_domain = handle.split('@')[1]
    post_filename = locate_post(base_dir, handle_nickname,
                                handle_domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: delete post not found in inbox or outbox')
            print(message_id)
        return True
    delete_post(base_dir, http_prefix, handle_nickname,
                handle_domain, post_filename, debug,
                recent_posts_cache, True)
    if debug:
        print('DEBUG: post deleted - ' + post_filename)

    # also delete any local blogs saved to the news actor
    if handle_nickname != 'news' and handle_domain == domain_full:
        post_filename = locate_post(base_dir, 'news',
                                    handle_domain, message_id)
        if post_filename:
            delete_post(base_dir, http_prefix, 'news',
                        handle_domain, post_filename, debug,
                        recent_posts_cache, True)
            if debug:
                print('DEBUG: blog post deleted - ' + post_filename)
    return True


def receive_announce(recent_posts_cache: {},
                     session, handle: str, base_dir: str,
                     http_prefix: str,
                     domain: str,
                     onion_domain: str, i2p_domain: str, port: int,
                     cached_webfingers: {},
                     person_cache: {}, message_json: {},
                     debug: bool, translate: {},
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     allow_local_network_access: bool,
                     theme_name: str, system_language: str,
                     signing_priv_key_pem: str,
                     max_recent_posts: int,
                     allow_deletion: bool,
                     peertube_instances: [],
                     max_like_count: int, cw_lists: {},
                     lists_enabled: str, bold_reading: bool,
                     dogwhistles: {}, mitm: bool,
                     min_images_for_accounts: [],
                     buy_sites: {},
                     languages_understood: [],
                     auto_cw_cache: {},
                     block_federated: [],
                     mitm_servers: [],
                     instance_software: {}) -> bool:
    """Receives an announce activity within the POST section of HTTPServer
    """
    if message_json['type'] != 'Announce':
        return False
    if '@' not in handle:
        if debug:
            print('DEBUG: bad handle ' + handle)
        return False
    if not has_actor(message_json, debug):
        return False
    if debug:
        print('DEBUG: receiving announce on ' + handle)
    is_quote = False
    if not has_object_string(message_json, debug):
        if not is_quote_toot(message_json, ''):
            return False
        else:
            is_quote = True
    if not message_json.get('to'):
        if debug:
            print('DEBUG: ' + message_json['type'] + ' has no "to" list')
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        print('WARN: unknown users path ' + actor_url)
        if debug:
            print('DEBUG: ' +
                  '"users" or "profile" missing from actor in ' +
                  message_json['type'])
        return False
    if is_self_announce(message_json):
        if debug:
            print('DEBUG: self-boost rejected')
        return False
    if not is_quote:
        announce_url = str(message_json['object'])
    else:
        announce_url = get_quote_toot_url(message_json)
    if not has_users_path(announce_url):
        # log any unrecognised statuses
        if not contains_statuses(announce_url):
            print('WARN: unknown statuses path ' + announce_url)
        if debug:
            print('DEBUG: ' +
                  '"users", "channel" or "profile" missing in ' +
                  message_json['type'])
        return False

    blocked_cache = {}
    prefixes = get_protocol_prefixes()
    # is the domain of the announce actor blocked?
    object_domain = announce_url
    for prefix in prefixes:
        object_domain = object_domain.replace(prefix, '')
    if '/' in object_domain:
        object_domain = object_domain.split('/')[0]
    if is_blocked_domain(base_dir, object_domain, None, block_federated):
        if debug:
            print('DEBUG: announced domain is blocked')
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of announce - ' + handle)

    # is the announce actor blocked?
    nickname = handle.split('@')[0]
    actor_nickname = get_nickname_from_actor(actor_url)
    if not actor_nickname:
        print('WARN: _receive_announce no actor_nickname')
        return False
    actor_domain, _ = get_domain_from_actor(actor_url)
    if not actor_domain:
        print('WARN: _receive_announce no actor_domain')
        return False
    if is_blocked_nickname(base_dir, actor_nickname):
        if debug:
            print('DEBUG: announced nickname is blocked')
        return False
    if is_blocked(base_dir, nickname, domain, actor_nickname, actor_domain,
                  None, block_federated):
        print('Receive announce blocked for actor: ' +
              actor_nickname + '@' + actor_domain)
        return False

    # Are announces permitted from the given actor?
    if not allowed_announce(base_dir, nickname, domain,
                            actor_nickname, actor_domain):
        print('Announce not allowed for: ' +
              actor_nickname + '@' + actor_domain)
        return False

    # also check the actor for the url being announced
    announced_actor_nickname = get_nickname_from_actor(announce_url)
    if not announced_actor_nickname:
        print('WARN: _receive_announce no announced_actor_nickname')
        return False
    announced_actor_domain, _ = get_domain_from_actor(announce_url)
    if not announced_actor_domain:
        print('WARN: _receive_announce no announced_actor_domain')
        return False
    if is_blocked(base_dir, nickname, domain,
                  announced_actor_nickname, announced_actor_domain,
                  None, block_federated):
        print('Receive announce object blocked for actor: ' +
              announced_actor_nickname + '@' + announced_actor_domain)
        return False

    # is this post in the inbox or outbox of the account?
    post_filename = locate_post(base_dir, nickname, domain, announce_url)
    if not post_filename:
        if debug:
            print('DEBUG: announce post not found in inbox or outbox')
            print(announce_url)
        return True
    # add actor to the list of announcers for a post
    actor_url = get_actor_from_post(message_json)
    update_announce_collection(recent_posts_cache, base_dir, post_filename,
                               actor_url, nickname, domain, debug)
    if debug:
        print('DEBUG: Downloading announce post ' + actor_url +
              ' -> ' + announce_url)
    domain_full = get_full_domain(domain, port)

    # Generate html. This also downloads the announced post.
    page_number = 1
    show_published_date_only = False
    show_individual_post_icons = True
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    not_dm = True
    if debug:
        print('Generating html for announce ' + message_json['id'])
    timezone = get_account_timezone(base_dir, nickname, domain)

    if mitm:
        post_filename_mitm = \
            post_filename.replace('.json', '') + '.mitm'
        try:
            with open(post_filename_mitm, 'w+',
                      encoding='utf-8') as fp_mitm:
                fp_mitm.write('\n')
        except OSError:
            print('EX: unable to write mitm ' + post_filename_mitm)
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True

    show_vote_posts = True
    show_vote_file = acct_dir(base_dir, nickname, domain) + '/.noVotes'
    if os.path.isfile(show_vote_file):
        show_vote_posts = False

    announce_html = \
        individual_post_as_html(signing_priv_key_pem, True,
                                recent_posts_cache, max_recent_posts,
                                translate, page_number, base_dir,
                                session, cached_webfingers, person_cache,
                                nickname, domain, port, message_json,
                                None, True, allow_deletion,
                                http_prefix, __version__,
                                'inbox',
                                yt_replace_domain,
                                twitter_replacement_domain,
                                show_published_date_only,
                                peertube_instances,
                                allow_local_network_access,
                                theme_name, system_language,
                                max_like_count, not_dm,
                                show_individual_post_icons,
                                manually_approve_followers,
                                False, True, False, cw_lists,
                                lists_enabled, timezone, mitm,
                                bold_reading, dogwhistles,
                                minimize_all_images, None,
                                buy_sites, auto_cw_cache,
                                mitm_servers,
                                instance_software)
    if not announce_html:
        print('WARN: Unable to generate html for announce ' +
              str(message_json))
    else:
        if debug:
            announce_html2 = remove_eol(announce_html)
            print('Generated announce html ' + announce_html2)

    post_json_object = download_announce(session, base_dir,
                                         http_prefix,
                                         nickname, domain,
                                         message_json,
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
    # are annouced/boosted replies allowed?
    announce_denied = False
    if post_json_object:
        if has_object_dict(post_json_object):
            if post_json_object['object'].get('inReplyTo'):
                account_dir = acct_dir(base_dir, nickname, domain)
                no_reply_boosts_filename = account_dir + '/.noReplyBoosts'
                if os.path.isfile(no_reply_boosts_filename):
                    post_json_object = None
                    announce_denied = True

    if not post_json_object:
        if not announce_denied:
            print('WARN: unable to download announce: ' + str(message_json))
        else:
            print('REJECT: Announce/Boost of reply denied ' +
                  actor_url + ' 🔁 ' + announce_url)
        not_in_onion = True
        if onion_domain:
            if onion_domain in announce_url:
                not_in_onion = False
        if domain not in announce_url and not_in_onion:
            if os.path.isfile(post_filename):
                # if the announce can't be downloaded then remove it
                try:
                    os.remove(post_filename)
                except OSError:
                    print('EX: _receive_announce unable to delete ' +
                          str(post_filename))
    else:
        if debug:
            actor_url = get_actor_from_post(message_json)
            print('DEBUG: Announce post downloaded for ' +
                  actor_url + ' -> ' + announce_url)

        store_hash_tags(base_dir, nickname, domain,
                        http_prefix, domain_full,
                        post_json_object, translate, session)
        # Try to obtain the actor for this person
        # so that their avatar can be shown
        lookup_actor = None
        if post_json_object.get('attributedTo'):
            attrib = get_attributed_to(post_json_object['attributedTo'])
            if attrib:
                if not contains_invalid_actor_url_chars(attrib):
                    lookup_actor = attrib
        else:
            if has_object_dict(post_json_object):
                if post_json_object['object'].get('attributedTo'):
                    attrib_field = post_json_object['object']['attributedTo']
                    attrib = get_attributed_to(attrib_field)
                    if attrib:
                        if not contains_invalid_actor_url_chars(attrib):
                            lookup_actor = attrib
        if lookup_actor:
            lookup_actor = get_actor_from_post_id(lookup_actor)
            if lookup_actor:
                if is_recent_post(post_json_object, 3):
                    if not os.path.isfile(post_filename + '.tts'):
                        domain_full = get_full_domain(domain, port)
                        update_speaker(base_dir, http_prefix,
                                       nickname, domain, domain_full,
                                       post_json_object, person_cache,
                                       translate, lookup_actor,
                                       theme_name, system_language,
                                       'inbox')
                        try:
                            with open(post_filename + '.tts', 'w+',
                                      encoding='utf-8') as fp_tts:
                                fp_tts.write('\n')
                        except OSError:
                            print('EX: unable to write recent post ' +
                                  post_filename)

                if debug:
                    print('DEBUG: Obtaining actor for announce post ' +
                          lookup_actor)
                for tries in range(6):
                    pub_key = \
                        get_person_pub_key(base_dir, session, lookup_actor,
                                           person_cache, debug,
                                           __version__, http_prefix,
                                           domain, onion_domain,
                                           i2p_domain,
                                           signing_priv_key_pem,
                                           mitm_servers)
                    if pub_key:
                        if not isinstance(pub_key, dict):
                            if debug:
                                print('DEBUG: ' +
                                      'public key obtained for announce: ' +
                                      lookup_actor)
                        else:
                            if debug:
                                print('DEBUG: http error code returned for ' +
                                      'public key obtained for announce: ' +
                                      lookup_actor + ' ' + str(pub_key))
                        break

                    if debug:
                        print('DEBUG: Retry ' + str(tries + 1) +
                              ' obtaining actor for ' + lookup_actor)
                    time.sleep(5)
        if debug:
            print('DEBUG: announced/repeated post arrived in inbox')
    return True


def receive_question_vote(server, base_dir: str, nickname: str, domain: str,
                          http_prefix: str, handle: str, debug: bool,
                          post_json_object: {}, recent_posts_cache: {},
                          session, session_onion, session_i2p,
                          onion_domain: str, i2p_domain: str, port: int,
                          federation_list: [], send_threads: [], post_log: [],
                          cached_webfingers: {}, person_cache: {},
                          signing_priv_key_pem: str,
                          max_recent_posts: int, translate: {},
                          allow_deletion: bool,
                          yt_replace_domain: str,
                          twitter_replacement_domain: str,
                          peertube_instances: [],
                          allow_local_network_access: bool,
                          theme_name: str, system_language: str,
                          max_like_count: int,
                          cw_lists: {}, lists_enabled: bool,
                          bold_reading: bool, dogwhistles: {},
                          min_images_for_accounts: [],
                          buy_sites: {},
                          sites_unavailable: [],
                          auto_cw_cache: {},
                          mitm_servers: [],
                          instance_software: {}) -> None:
    """Updates the votes on a Question/poll
    """
    # if this is a reply to a question then update the votes
    question_json, question_post_filename = \
        question_update_votes(base_dir, nickname, domain,
                              post_json_object, debug)
    if not question_json:
        return
    if not question_post_filename:
        return

    remove_post_from_cache(question_json, recent_posts_cache)
    # ensure that the cached post is removed if it exists, so
    # that it then will be recreated
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, question_json)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
            except OSError:
                print('EX: replytoQuestion unable to delete ' +
                      cached_post_filename)

    page_number = 1
    show_published_date_only = False
    show_individual_post_icons = True
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    not_dm = not is_dm(question_json)
    timezone = get_account_timezone(base_dir, nickname, domain)
    mitm = False
    if os.path.isfile(question_post_filename.replace('.json', '') + '.mitm'):
        mitm = True
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    individual_post_as_html(signing_priv_key_pem, False,
                            recent_posts_cache, max_recent_posts,
                            translate, page_number, base_dir,
                            session, cached_webfingers, person_cache,
                            nickname, domain, port, question_json,
                            None, True, allow_deletion,
                            http_prefix, __version__,
                            'inbox',
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            theme_name, system_language,
                            max_like_count, not_dm,
                            show_individual_post_icons,
                            manually_approve_followers,
                            False, True, False, cw_lists,
                            lists_enabled, timezone, mitm,
                            bold_reading, dogwhistles,
                            minimize_all_images, None,
                            buy_sites, auto_cw_cache,
                            mitm_servers,
                            instance_software)

    # add id to inbox index
    inbox_update_index('inbox', base_dir, handle,
                       question_post_filename, debug)

    # Is this a question created by this instance?
    id_prefix = http_prefix + '://' + domain
    if not question_json['object']['id'].startswith(id_prefix):
        return
    # if the votes on a question have changed then
    # send out an update
    question_json['type'] = 'Update'
    shared_items_federated_domains: list[str] = []
    shared_item_federation_tokens = {}
    send_to_followers_thread(server, session, session_onion, session_i2p,
                             base_dir, nickname, domain,
                             onion_domain, i2p_domain, port,
                             http_prefix, federation_list,
                             send_threads, post_log,
                             cached_webfingers, person_cache,
                             post_json_object, debug, __version__,
                             shared_items_federated_domains,
                             shared_item_federation_tokens,
                             signing_priv_key_pem,
                             sites_unavailable, system_language,
                             mitm_servers)
