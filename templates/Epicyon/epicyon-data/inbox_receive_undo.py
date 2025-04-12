__filename__ = "inbox_receive_undo.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
from flags import has_group_type
from utils import undo_announce_collection_entry
from utils import has_object_dict
from utils import remove_domain_port
from utils import remove_id_ending
from utils import get_url_from_post
from utils import undo_reaction_collection_entry
from utils import remove_html
from utils import get_account_timezone
from utils import is_dm
from utils import get_cached_post_filename
from utils import load_json
from utils import undo_likes_collection_entry
from utils import locate_post
from utils import acct_handle_dir
from utils import has_object_string_object
from utils import has_object_string_type
from utils import has_actor
from utils import get_full_domain
from utils import get_actor_from_post
from utils import has_users_path
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from follow import unfollower_of_account
from follow import follower_approval_active
from bookmarks import undo_bookmarks_collection_entry
from webapp_post import individual_post_as_html


def _receive_undo_follow(base_dir: str, message_json: {},
                         debug: bool, domain: str,
                         onion_domain: str, i2p_domain: str) -> bool:
    """
    Receives an undo follow
    {
        "type": "Undo",
        "actor": "https://some.instance/@someone",
        "object": {
            "type": "Follow",
            "actor": "https://some.instance/@someone",
            "object": "https://social.example/@somenickname"
        }
    }
    """
    if not message_json['object'].get('object'):
        return False
    if not message_json['object'].get('actor'):
        if debug:
            print('DEBUG: undo follow request has no actor within object')
        return False
    actor = get_actor_from_post(message_json['object'])
    if not has_users_path(actor):
        if debug:
            print('DEBUG: undo follow request "users" or "profile" missing ' +
                  'from actor within object')
        return False
    if actor != get_actor_from_post(message_json):
        if debug:
            print('DEBUG: undo follow request actors do not match')
        return False

    nickname_follower = \
        get_nickname_from_actor(actor)
    if not nickname_follower:
        print('WARN: undo follow request unable to find nickname in ' +
              actor)
        return False
    domain_follower, port_follower = \
        get_domain_from_actor(actor)
    if not domain_follower:
        print('WARN: undo follow request unable to find domain in ' +
              actor)
        return False
    domain_follower_full = get_full_domain(domain_follower, port_follower)

    following_actor = None
    if isinstance(message_json['object']['object'], str):
        following_actor = message_json['object']['object']
    elif isinstance(message_json['object']['object'], dict):
        if message_json['object']['object'].get('id'):
            if isinstance(message_json['object']['object']['id'], str):
                following_actor = message_json['object']['object']['id']
    if not following_actor:
        print('WARN: undo follow without following actor')
        return False

    nickname_following = \
        get_nickname_from_actor(following_actor)
    if not nickname_following:
        print('WARN: undo follow request unable to find nickname in ' +
              following_actor)
        return False
    domain_following, port_following = \
        get_domain_from_actor(following_actor)
    if not domain_following:
        print('WARN: undo follow request unable to find domain in ' +
              following_actor)
        return False
    if onion_domain:
        if domain_following.endswith(onion_domain):
            domain_following = domain
    if i2p_domain:
        if domain_following.endswith(i2p_domain):
            domain_following = domain
    domain_following_full = get_full_domain(domain_following, port_following)

    group_account = has_group_type(base_dir, actor, None)
    if unfollower_of_account(base_dir,
                             nickname_following, domain_following_full,
                             nickname_follower, domain_follower_full,
                             debug, group_account):
        print(nickname_following + '@' + domain_following_full + ': '
              'Follower ' + nickname_follower + '@' + domain_follower_full +
              ' was removed')
        return True

    if debug:
        print('DEBUG: Follower ' +
              nickname_follower + '@' + domain_follower_full +
              ' was not removed')
    return False


def receive_undo(base_dir: str, message_json: {}, debug: bool,
                 domain: str, onion_domain: str, i2p_domain: str) -> bool:
    """Receives an undo request within the POST section of HTTPServer
    """
    if not message_json['type'].startswith('Undo'):
        return False
    if debug:
        print('DEBUG: Undo activity received')
    if not has_actor(message_json, debug):
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor')
        return False
    if not has_object_string_type(message_json, debug):
        return False
    if message_json['object']['type'] == 'Follow' or \
       message_json['object']['type'] == 'Join':
        _receive_undo_follow(base_dir, message_json,
                             debug, domain, onion_domain, i2p_domain)
        return True
    return False


def receive_undo_like(recent_posts_cache: {},
                      session, handle: str, base_dir: str,
                      http_prefix: str, domain: str, port: int,
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
    """Receives an undo like activity within the POST section of HTTPServer
    """
    if message_json['type'] != 'Undo':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string_type(message_json, debug):
        return False
    if message_json['object']['type'] != 'Like':
        return False
    if not has_object_string_object(message_json, debug):
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'] + ' like')
        return False
    if '/statuses/' not in message_json['object']['object']:
        if debug:
            print('DEBUG: "statuses" missing from like object in ' +
                  message_json['type'])
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of undo like - ' + handle)
    # if this post in the outbox of the person?
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    post_filename = \
        locate_post(base_dir, handle_name, handle_dom,
                    message_json['object']['object'])
    if not post_filename:
        if debug:
            print('DEBUG: unliked post not found in inbox or outbox')
            print(message_json['object']['object'])
        return True
    if debug:
        print('DEBUG: liked post found in inbox. Now undoing.')
    like_actor = get_actor_from_post(message_json)
    undo_likes_collection_entry(recent_posts_cache, base_dir, post_filename,
                                like_actor, domain, debug, None)
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
                        post_filename = announce_liked_filename
                        undo_likes_collection_entry(recent_posts_cache,
                                                    base_dir,
                                                    post_filename,
                                                    like_actor, domain, debug,
                                                    None)
        if liked_post_json:
            if debug:
                cached_post_filename = \
                    get_cached_post_filename(base_dir, handle_name, domain,
                                             liked_post_json)
                print('Unliked post json: ' + str(liked_post_json))
                print('Unliked post nickname: ' + handle_name + ' ' + domain)
                print('Unliked post cache: ' + str(cached_post_filename))
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
                                    minimize_all_images, None,
                                    buy_sites, auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
    return True


def receive_undo_reaction(recent_posts_cache: {},
                          session, handle: str, base_dir: str,
                          http_prefix: str, domain: str, port: int,
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
    """Receives an undo emoji reaction within the POST section of HTTPServer
    """
    if message_json['type'] != 'Undo':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string_type(message_json, debug):
        return False
    if message_json['object']['type'] != 'EmojiReact':
        return False
    if not has_object_string_object(message_json, debug):
        return False
    if 'content' not in message_json['object']:
        if debug:
            print('DEBUG: ' + message_json['type'] + ' has no "content"')
        return False
    if not isinstance(message_json['object']['content'], str):
        if debug:
            print('DEBUG: ' + message_json['type'] + ' content is not string')
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'] + ' reaction')
        return False
    if '/statuses/' not in message_json['object']['object']:
        if debug:
            print('DEBUG: "statuses" missing from reaction object in ' +
                  message_json['type'])
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of undo reaction - ' + handle)
    # if this post in the outbox of the person?
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    post_filename = \
        locate_post(base_dir, handle_name, handle_dom,
                    message_json['object']['object'])
    if not post_filename:
        if debug:
            print('DEBUG: unreaction post not found in inbox or outbox')
            print(message_json['object']['object'])
        return True
    if debug:
        print('DEBUG: reaction post found in inbox. Now undoing.')
    reaction_actor = actor_url
    emoji_content = remove_html(message_json['object']['content'])
    if not emoji_content:
        if debug:
            print('DEBUG: unreaction has no content')
        return True
    undo_reaction_collection_entry(recent_posts_cache, base_dir, post_filename,
                                   reaction_actor, domain,
                                   debug, None, emoji_content)
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
                        post_filename = announce_reaction_filename
                        undo_reaction_collection_entry(recent_posts_cache,
                                                       base_dir,
                                                       post_filename,
                                                       reaction_actor,
                                                       domain,
                                                       debug, None,
                                                       emoji_content)
        if reaction_post_json:
            if debug:
                cached_post_filename = \
                    get_cached_post_filename(base_dir, handle_name, domain,
                                             reaction_post_json)
                print('Unreaction post json: ' + str(reaction_post_json))
                print('Unreaction post nickname: ' +
                      handle_name + ' ' + domain)
                print('Unreaction post cache: ' + str(cached_post_filename))
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


def receive_undo_bookmark(recent_posts_cache: {},
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
                          lists_enabled: str, bold_reading: bool,
                          dogwhistles: {},
                          min_images_for_accounts: [],
                          buy_sites: {},
                          auto_cw_cache: {},
                          mitm_servers: [],
                          instance_software: {}) -> bool:
    """Receives an undo bookmark activity within the POST section of HTTPServer
    """
    if not message_json.get('type'):
        return False
    if message_json['type'] != 'Remove':
        return False
    if not has_actor(message_json, debug):
        return False
    if not message_json.get('target'):
        if debug:
            print('DEBUG: no target in inbox undo bookmark Remove')
        return False
    if not has_object_string_type(message_json, debug):
        return False
    if not isinstance(message_json['target'], str):
        if debug:
            print('DEBUG: inbox Remove bookmark target is not string')
        return False
    domain_full = get_full_domain(domain, port)
    nickname = handle.split('@')[0]
    actor_url = get_actor_from_post(message_json)
    if not actor_url.endswith(domain_full + '/users/' + nickname):
        if debug:
            print('DEBUG: inbox undo bookmark Remove unexpected actor')
        return False
    if not message_json['target'].endswith(actor_url +
                                           '/tlbookmarks'):
        if debug:
            print('DEBUG: inbox undo bookmark Remove target invalid ' +
                  message_json['target'])
        return False
    if message_json['object']['type'] != 'Document':
        if debug:
            print('DEBUG: inbox undo bookmark Remove type is not Document')
        return False
    if not message_json['object'].get('url'):
        if debug:
            print('DEBUG: inbox undo bookmark Remove missing url')
        return False
    url_str = get_url_from_post(message_json['object']['url'])
    if '/statuses/' not in url_str:
        if debug:
            print('DEBUG: inbox undo bookmark Remove missing statuses un url')
        return False
    if debug:
        print('DEBUG: c2s inbox Remove bookmark ' +
              'request arrived in outbox')

    message_url2 = remove_html(url_str)
    message_url = remove_id_ending(message_url2)
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_url)
    if not post_filename:
        if debug:
            print('DEBUG: c2s inbox like post not found in inbox or outbox')
            print(message_url)
        return True

    undo_bookmarks_collection_entry(recent_posts_cache, base_dir,
                                    post_filename,
                                    actor_url, domain, debug)
    # regenerate the html
    bookmarked_post_json = load_json(post_filename)
    if bookmarked_post_json:
        if debug:
            cached_post_filename = \
                get_cached_post_filename(base_dir, nickname, domain,
                                         bookmarked_post_json)
            print('Unbookmarked post json: ' + str(bookmarked_post_json))
            print('Unbookmarked post nickname: ' + nickname + ' ' + domain)
            print('Unbookmarked post cache: ' + str(cached_post_filename))
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
                                False, True, False, cw_lists, lists_enabled,
                                timezone, mitm, bold_reading,
                                dogwhistles, minimize_all_images, None,
                                buy_sites, auto_cw_cache,
                                mitm_servers, instance_software)
    return True


def receive_undo_announce(recent_posts_cache: {},
                          handle: str, base_dir: str, domain: str,
                          message_json: {}, debug: bool) -> bool:
    """Receives an undo announce activity within the POST section of HTTPServer
    """
    if message_json['type'] != 'Undo':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_dict(message_json):
        return False
    if not has_object_string_object(message_json, debug):
        return False
    if message_json['object']['type'] != 'Announce':
        return False
    actor_url = get_actor_from_post(message_json)
    if not has_users_path(actor_url):
        if debug:
            print('DEBUG: "users" or "profile" missing from actor in ' +
                  message_json['type'] + ' announce')
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('DEBUG: unknown recipient of undo announce - ' + handle)
    # if this post in the outbox of the person?
    handle_name = handle.split('@')[0]
    handle_dom = handle.split('@')[1]
    post_filename = locate_post(base_dir, handle_name, handle_dom,
                                message_json['object']['object'])
    if not post_filename:
        if debug:
            print('DEBUG: undo announce post not found in inbox or outbox')
            print(message_json['object']['object'])
        return True
    if debug:
        print('DEBUG: announced/repeated post to be undone found in inbox')

    post_json_object = load_json(post_filename)
    if post_json_object:
        if not post_json_object.get('type'):
            if post_json_object['type'] != 'Announce':
                if debug:
                    print("DEBUG: Attempt to undo something " +
                          "which isn't an announcement")
                return False
    undo_announce_collection_entry(recent_posts_cache, base_dir, post_filename,
                                   actor_url, domain, debug)
    if os.path.isfile(post_filename):
        try:
            os.remove(post_filename)
        except OSError:
            print('EX: _receive_undo_announce unable to delete ' +
                  str(post_filename))
    return True
