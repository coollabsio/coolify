__filename__ = "follow.py"
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
from utils import get_user_paths
from utils import acct_handle_dir
from utils import has_object_string_object
from utils import has_object_string_type
from utils import remove_domain_port
from utils import has_users_path
from utils import get_full_domain
from utils import valid_nickname
from utils import domain_permitted
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from utils import get_status_number
from utils import follow_person
from posts import send_signed_json
from posts import get_person_box
from utils import load_json
from utils import save_json
from utils import is_account_dir
from utils import acct_dir
from utils import local_actor_url
from utils import text_in_file
from utils import remove_eol
from utils import get_actor_from_post
from utils import data_dir
from acceptreject import create_accept
from acceptreject import create_reject
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from session import get_json
from session import get_json_valid
from session import post_json
from followerSync import remove_followers_sync


def create_initial_last_seen(base_dir: str, http_prefix: str) -> None:
    """Creates initial lastseen files for all follows.
    The lastseen files are used to generate the Zzz icons on
    follows/following lists on the profile screen.
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for acct in dirs:
            if not is_account_dir(acct):
                continue
            account_dir = os.path.join(dir_str, acct)
            following_filename = account_dir + '/following.txt'
            if not os.path.isfile(following_filename):
                continue
            last_seen_dir = account_dir + '/lastseen'
            if not os.path.isdir(last_seen_dir):
                os.mkdir(last_seen_dir)
            following_handles: list[str] = []
            try:
                with open(following_filename, 'r',
                          encoding='utf-8') as fp_foll:
                    following_handles = fp_foll.readlines()
            except OSError:
                print('EX: create_initial_last_seen ' + following_filename)
            for handle in following_handles:
                if '#' in handle:
                    continue
                if '@' not in handle:
                    continue
                handle = remove_eol(handle)
                nickname = handle.split('@')[0]
                domain = handle.split('@')[1]
                if nickname.startswith('!'):
                    nickname = nickname[1:]
                actor = local_actor_url(http_prefix, nickname, domain)
                last_seen_filename = \
                    last_seen_dir + '/' + actor.replace('/', '#') + '.txt'
                if os.path.isfile(last_seen_filename):
                    continue
                try:
                    with open(last_seen_filename, 'w+',
                              encoding='utf-8') as fp_last:
                        fp_last.write(str(100))
                except OSError:
                    print('EX: create_initial_last_seen 2 ' +
                          last_seen_filename)
        break


def _pre_approved_follower(base_dir: str,
                           nickname: str, domain: str,
                           approve_handle: str) -> bool:
    """Is the given handle an already manually approved follower?
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    approved_filename = account_dir + '/approved.txt'
    if os.path.isfile(approved_filename):
        if text_in_file(approve_handle, approved_filename):
            return True
    return False


def _remove_from_follow_base(base_dir: str,
                             nickname: str, domain: str,
                             accept_or_deny_handle: str, follow_file: str,
                             debug: bool) -> None:
    """Removes a handle/actor from follow requests or rejects file
    """
    accounts_dir = acct_dir(base_dir, nickname, domain)
    approve_follows_filename = accounts_dir + '/' + follow_file + '.txt'
    if not os.path.isfile(approve_follows_filename):
        if debug:
            print('There is no ' + follow_file +
                  ' to remove ' + nickname + '@' + domain + ' from')
        return
    accept_deny_actor = None
    if not text_in_file(accept_or_deny_handle, approve_follows_filename):
        # is this stored in the file as an actor rather than a handle?
        accept_deny_nickname = accept_or_deny_handle.split('@')[0]
        accept_deny_domain = accept_or_deny_handle.split('@')[1]
        # for each possible users path construct an actor and
        # check if it exists in the file
        users_paths = get_user_paths()
        actor_found = False
        for users_name in users_paths:
            accept_deny_actor = \
                '://' + accept_deny_domain + users_name + accept_deny_nickname
            if text_in_file(accept_deny_actor, approve_follows_filename):
                actor_found = True
                break
        if not actor_found:
            accept_deny_actor = \
                '://' + accept_deny_domain + '/' + accept_deny_nickname
            if text_in_file(accept_deny_actor, approve_follows_filename):
                actor_found = True
        if not actor_found:
            return
    try:
        with open(approve_follows_filename + '.new', 'w+',
                  encoding='utf-8') as fp_approve_new:
            with open(approve_follows_filename, 'r',
                      encoding='utf-8') as fp_approve:
                if not accept_deny_actor:
                    for approve_handle in fp_approve:
                        accept_deny_handle = accept_or_deny_handle
                        if not approve_handle.startswith(accept_deny_handle):
                            fp_approve_new.write(approve_handle)
                else:
                    for approve_handle in fp_approve:
                        if accept_deny_actor not in approve_handle:
                            fp_approve_new.write(approve_handle)
    except OSError as ex:
        print('EX: _remove_from_follow_base ' +
              approve_follows_filename + ' ' + str(ex))

    os.rename(approve_follows_filename + '.new', approve_follows_filename)


def remove_from_follow_requests(base_dir: str,
                                nickname: str, domain: str,
                                deny_handle: str, debug: bool) -> None:
    """Removes a handle from follow requests
    """
    _remove_from_follow_base(base_dir, nickname, domain,
                             deny_handle, 'followrequests', debug)


def _remove_from_follow_rejects(base_dir: str,
                                nickname: str, domain: str,
                                accept_handle: str, debug: bool) -> None:
    """Removes a handle from follow rejects
    """
    _remove_from_follow_base(base_dir, nickname, domain,
                             accept_handle, 'followrejects', debug)


def is_following_actor(base_dir: str,
                       nickname: str, domain: str, actor: str) -> bool:
    """Is the given nickname following the given actor?
    The actor can also be a handle: nickname@domain
    """
    domain = remove_domain_port(domain)
    accounts_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(accounts_dir):
        return False
    following_file = accounts_dir + '/following.txt'
    if not os.path.isfile(following_file):
        return False
    if actor.startswith('@'):
        actor = actor[1:]
    if text_in_file(actor, following_file, False):
        return True
    following_nickname = get_nickname_from_actor(actor)
    if not following_nickname:
        print('WARN: unable to find nickname in ' + actor)
        return False
    following_domain, following_port = get_domain_from_actor(actor)
    if not following_domain:
        print('WARN: unable to find domain in ' + actor)
        return False
    following_handle = \
        get_full_domain(following_nickname + '@' + following_domain,
                        following_port)
    if text_in_file(following_handle, following_file, False):
        return True
    return False


def add_follower_of_person(base_dir: str, nickname: str, domain: str,
                           follower_nickname: str, follower_domain: str,
                           federation_list: [], debug: bool,
                           group_account: bool) -> bool:
    """Adds a follower of the given person
    """
    return follow_person(base_dir, nickname, domain,
                         follower_nickname, follower_domain,
                         federation_list, debug, group_account,
                         'followers.txt')


def get_follower_domains(base_dir: str, nickname: str, domain: str) -> []:
    """Returns a list of domains for followers
    """
    domain = remove_domain_port(domain)
    followers_file = acct_dir(base_dir, nickname, domain) + '/followers.txt'
    if not os.path.isfile(followers_file):
        return []

    lines: list[str] = []
    try:
        with open(followers_file, 'r', encoding='utf-8') as fp_foll:
            lines = fp_foll.readlines()
    except OSError:
        print('EX: get_follower_domains ' + followers_file)

    domains_list: list[str] = []
    for handle in lines:
        handle = remove_eol(handle)
        follower_domain, _ = get_domain_from_actor(handle)
        if not follower_domain:
            continue
        if follower_domain not in domains_list:
            domains_list.append(follower_domain)
    return domains_list


def is_follower_of_person(base_dir: str, nickname: str, domain: str,
                          follower_nickname: str,
                          follower_domain: str) -> bool:
    """is the given nickname a follower of follower_nickname?
    """
    if not follower_domain:
        print('No follower_domain')
        return False
    if not follower_nickname:
        print('No follower_nickname for ' + follower_domain)
        return False
    domain = remove_domain_port(domain)
    followers_file = acct_dir(base_dir, nickname, domain) + '/followers.txt'
    if not os.path.isfile(followers_file):
        return False
    handle = follower_nickname + '@' + follower_domain

    already_following = False

    followers_str = ''
    try:
        with open(followers_file, 'r', encoding='utf-8') as fp_foll:
            followers_str = fp_foll.read()
    except OSError:
        print('EX: is_follower_of_person ' + followers_file)

    if handle in followers_str:
        already_following = True
    else:
        paths = get_user_paths()
        for user_path in paths:
            url = '://' + follower_domain + user_path + follower_nickname
            if url in followers_str:
                already_following = True
                break
        if not already_following:
            url = '://' + follower_domain + '/' + follower_nickname
            if url in followers_str:
                already_following = True

    return already_following


def unfollow_account(base_dir: str, nickname: str, domain: str,
                     follow_nickname: str, follow_domain: str,
                     debug: bool, group_account: bool,
                     follow_file: str) -> bool:
    """Removes a person to the follow list
    """
    domain = remove_domain_port(domain)
    handle = nickname + '@' + domain
    handle_to_unfollow = follow_nickname + '@' + follow_domain
    if group_account:
        handle_to_unfollow = '!' + handle_to_unfollow
    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str):
        os.mkdir(dir_str)
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        os.mkdir(handle_dir)

    accounts_dir = acct_dir(base_dir, nickname, domain)
    filename = accounts_dir + '/' + follow_file
    if not os.path.isfile(filename):
        if debug:
            print('DEBUG: follow file ' + filename + ' was not found')
        return False
    handle_to_unfollow_lower = handle_to_unfollow.lower()
    if not text_in_file(handle_to_unfollow_lower, filename, False):
        if debug:
            print('DEBUG: handle to unfollow ' + handle_to_unfollow +
                  ' is not in ' + filename)
        return False
    lines: list[str] = []
    try:
        with open(filename, 'r', encoding='utf-8') as fp_unfoll:
            lines = fp_unfoll.readlines()
    except OSError:
        print('EX: unfollow_account ' + filename)
    if lines:
        try:
            with open(filename, 'w+', encoding='utf-8') as fp_unfoll:
                for line in lines:
                    check_handle = line.strip("\n").strip("\r").lower()
                    if check_handle not in (handle_to_unfollow_lower,
                                            '!' + handle_to_unfollow_lower):
                        fp_unfoll.write(line)
        except OSError as ex:
            print('EX: unfollow_account unable to write ' +
                  filename + ' ' + str(ex))

    # write to an unfollowed file so that if a follow accept
    # later arrives then it can be ignored
    unfollowed_filename = accounts_dir + '/unfollowed.txt'
    if os.path.isfile(unfollowed_filename):
        if not text_in_file(handle_to_unfollow_lower,
                            unfollowed_filename, False):
            try:
                with open(unfollowed_filename, 'a+',
                          encoding='utf-8') as fp_unfoll:
                    fp_unfoll.write(handle_to_unfollow + '\n')
            except OSError:
                print('EX: unfollow_account unable to append ' +
                      unfollowed_filename)
    else:
        try:
            with open(unfollowed_filename, 'w+',
                      encoding='utf-8') as fp_unfoll:
                fp_unfoll.write(handle_to_unfollow + '\n')
        except OSError:
            print('EX: unfollow_account unable to write ' +
                  unfollowed_filename)

    return True


def unfollower_of_account(base_dir: str, nickname: str, domain: str,
                          follower_nickname: str, follower_domain: str,
                          debug: bool, group_account: bool) -> bool:
    """Remove a follower of a person
    """
    return unfollow_account(base_dir, nickname, domain,
                            follower_nickname, follower_domain,
                            debug, group_account, 'followers.txt')


def clear_follows(base_dir: str, nickname: str, domain: str,
                  follow_file: str) -> None:
    """Removes all follows
    """
    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str):
        os.mkdir(dir_str)
    accounts_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(accounts_dir):
        os.mkdir(accounts_dir)
    filename = accounts_dir + '/' + follow_file
    if os.path.isfile(filename):
        try:
            os.remove(filename)
        except OSError:
            print('EX: clear_follows unable to delete ' + filename)


def clear_followers(base_dir: str, nickname: str, domain: str) -> None:
    """Removes all followers
    """
    clear_follows(base_dir, nickname, domain, 'followers.txt')


def _get_no_of_follows(base_dir: str, nickname: str, domain: str,
                       follow_file='following.txt') -> int:
    """Returns the number of follows or followers
    """
    # only show number of followers to authenticated
    # account holders
    # if not authenticated:
    #     return 9999
    accounts_dir = acct_dir(base_dir, nickname, domain)
    filename = accounts_dir + '/' + follow_file
    if not os.path.isfile(filename):
        return 0
    ctr = 0
    lines: list[str] = []
    try:
        with open(filename, 'r', encoding='utf-8') as fp_foll:
            lines = fp_foll.readlines()
    except OSError:
        print('EX: _get_no_of_follows ' + filename)
    if lines:
        for line in lines:
            if '#' in line:
                continue
            if '@' in line and \
               '.' in line and \
               not line.startswith('http'):
                ctr += 1
            elif ((line.startswith('http') or
                   line.startswith('ipfs') or
                   line.startswith('ipns') or
                   line.startswith('hyper')) and
                  has_users_path(line)):
                ctr += 1
    return ctr


def get_no_of_followers(base_dir: str, nickname: str, domain: str) -> int:
    """Returns the number of followers of the given person
    """
    return _get_no_of_follows(base_dir, nickname, domain, 'followers.txt')


def get_following_feed(base_dir: str, domain: str, port: int, path: str,
                       http_prefix: str, authorized: bool,
                       follows_per_page=12,
                       follow_file='following') -> {}:
    """Returns the following and followers feeds from GET requests.
    This accesses the following.txt or followers.txt and builds a collection.
    """
    # Show a small number of follows to non-authorized viewers
    if not authorized:
        follows_per_page = 6

    if '/' + follow_file not in path:
        return None
    # handle page numbers
    header_only = True
    page_number = None
    if '?page=' in path:
        page_number = path.split('?page=')[1]
        if len(page_number) > 5:
            page_number = "1"
        if page_number == 'true' or not authorized:
            page_number = 1
        else:
            try:
                page_number = int(page_number)
            except BaseException:
                print('EX: get_following_feed unable to convert to int ' +
                      str(page_number))
        path = path.split('?page=')[0]
        header_only = False

    if not path.endswith('/' + follow_file):
        return None
    nickname = None
    if path.startswith('/users/'):
        nickname = \
            path.replace('/users/', '', 1).replace('/' + follow_file, '')
    if path.startswith('/@'):
        nickname = path.replace('/@', '', 1).replace('/' + follow_file, '')
    if not nickname:
        return None
    if not valid_nickname(domain, nickname):
        return None

    domain = get_full_domain(domain, port)

    if header_only:
        first_str = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/' + follow_file + '?page=1'
        id_str = \
            local_actor_url(http_prefix, nickname, domain) + '/' + follow_file
        total_str = \
            _get_no_of_follows(base_dir, nickname, domain)
        following = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'first': first_str,
            'id': id_str,
            'totalItems': total_str,
            'type': 'OrderedCollection'
        }
        return following

    if not page_number:
        page_number = 1

    next_page_number = int(page_number + 1)
    following_of_actor = local_actor_url(http_prefix, nickname, domain)
    part_of_str = following_of_actor + '/' + follow_file
    collection_id = part_of_str + '?page=' + str(page_number)
    following = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': collection_id,
        'orderedItems': [],
        'partOf': part_of_str,
        follow_file + 'Of': following_of_actor,
        'totalItems': 0,
        'type': 'OrderedCollectionPage'
    }

    handle_domain = domain
    handle_domain = remove_domain_port(handle_domain)
    accounts_dir = acct_dir(base_dir, nickname, handle_domain)
    filename = accounts_dir + '/' + follow_file + '.txt'
    if not os.path.isfile(filename):
        return following
    curr_page = 1
    page_ctr = 0
    total_ctr = 0
    lines: list[str] = []
    try:
        with open(filename, 'r', encoding='utf-8') as fp_foll:
            lines = fp_foll.readlines()
    except OSError:
        print('EX: get_following_feed ' + filename)
    for line in lines:
        if '#' not in line:
            if '@' in line and not line.startswith('http'):
                # nickname@domain
                page_ctr += 1
                total_ctr += 1
                if curr_page == page_number:
                    line2_lower = line.lower()
                    line2 = remove_eol(line2_lower)
                    nick = line2.split('@')[0]
                    dom = line2.split('@')[1]
                    if not nick.startswith('!'):
                        # person actor
                        url = local_actor_url(http_prefix, nick, dom)
                    else:
                        # group actor
                        url = http_prefix + '://' + dom + '/c/' + nick
                    following['orderedItems'].append(url)
            elif ((line.startswith('http') or
                   line.startswith('ipfs') or
                   line.startswith('ipns') or
                   line.startswith('hyper')) and
                  has_users_path(line)):
                # https://domain/users/nickname
                page_ctr += 1
                total_ctr += 1
                if curr_page == page_number:
                    append_str1 = line.lower()
                    append_str = remove_eol(append_str1)
                    following['orderedItems'].append(append_str)
        if page_ctr >= follows_per_page:
            page_ctr = 0
            curr_page += 1
    following['totalItems'] = total_ctr
    last_page = int(total_ctr / follows_per_page)
    last_page = max(last_page, 1)
    if next_page_number > last_page:
        following['next'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/' + follow_file + '?page=' + str(last_page)
    return following


def follow_approval_required(base_dir: str, nickname_to_follow: str,
                             domain_to_follow: str, debug: bool,
                             follow_request_handle: str) -> bool:
    """ Returns the policy for follower approvals
    """
    # has this handle already been manually approved?
    if _pre_approved_follower(base_dir, nickname_to_follow, domain_to_follow,
                              follow_request_handle):
        return False

    manually_approve_follows = False
    domain_to_follow = remove_domain_port(domain_to_follow)
    actor_filename = data_dir(base_dir) + '/' + \
        nickname_to_follow + '@' + domain_to_follow + '.json'
    if os.path.isfile(actor_filename):
        actor = load_json(actor_filename)
        if actor:
            if 'manuallyApprovesFollowers' in actor:
                manually_approve_follows = actor['manuallyApprovesFollowers']
            else:
                if debug:
                    print(nickname_to_follow + '@' + domain_to_follow +
                          ' automatically approves followers')
    else:
        if debug:
            print('DEBUG: Actor file not found: ' + actor_filename)
    return manually_approve_follows


def no_of_follow_requests(base_dir: str,
                          nickname_to_follow: str, domain_to_follow: str,
                          follow_type: str) -> int:
    """Returns the current number of follow requests
    """
    accounts_dir = acct_dir(base_dir, nickname_to_follow, domain_to_follow)
    approve_follows_filename = accounts_dir + '/followrequests.txt'
    if not os.path.isfile(approve_follows_filename):
        return 0
    ctr = 0
    lines: list[str] = []
    try:
        with open(approve_follows_filename, 'r',
                  encoding='utf-8') as fp_approve:
            lines = fp_approve.readlines()
    except OSError:
        print('EX: no_of_follow_requests ' + approve_follows_filename)
    if lines:
        if follow_type == "onion":
            for file_line in lines:
                if '.onion' in file_line:
                    ctr += 1
        elif follow_type == "i2p":
            for file_line in lines:
                if '.i2p' in file_line:
                    ctr += 1
        else:
            return len(lines)
    return ctr


def store_follow_request(base_dir: str,
                         nickname_to_follow: str,
                         domain_to_follow: str, port: int,
                         nickname: str, domain: str, from_port: int,
                         follow_json: {},
                         debug: bool, person_url: str,
                         group_account: bool) -> bool:
    """Stores the follow request for later use
    """
    accounts_dir = acct_dir(base_dir, nickname_to_follow, domain_to_follow)
    if not os.path.isdir(accounts_dir):
        return False

    domain_full = get_full_domain(domain, from_port)
    approve_handle = get_full_domain(nickname + '@' + domain, from_port)

    if group_account:
        approve_handle = '!' + approve_handle

    followers_filename = accounts_dir + '/followers.txt'
    if os.path.isfile(followers_filename):
        already_following = False

        followers_str = ''
        try:
            with open(followers_filename, 'r',
                      encoding='utf-8') as fp_foll:
                followers_str = fp_foll.read()
        except OSError:
            print('EX: store_follow_request ' + followers_filename)

        if approve_handle in followers_str:
            already_following = True
        else:
            users_paths = get_user_paths()
            for possible_users_path in users_paths:
                url = '://' + domain_full + possible_users_path + nickname
                if url in followers_str:
                    already_following = True
                    break
            if not already_following:
                url = '://' + domain_full + '/' + nickname
                if url in followers_str:
                    already_following = True

        if already_following:
            if debug:
                print('DEBUG: ' +
                      nickname_to_follow + '@' + domain_to_follow +
                      ' already following ' + approve_handle)
            return True

    # should this follow be denied?
    deny_follows_filename = accounts_dir + '/followrejects.txt'
    if os.path.isfile(deny_follows_filename):
        if text_in_file(approve_handle, deny_follows_filename):
            remove_from_follow_requests(base_dir, nickname_to_follow,
                                        domain_to_follow, approve_handle,
                                        debug)
            print(approve_handle + ' was already denied as a follower of ' +
                  nickname_to_follow)
            return True

    # add to a file which contains a list of requests
    approve_follows_filename = accounts_dir + '/followrequests.txt'

    # store either nick@domain or the full person/actor url
    approve_handle_stored = approve_handle
    if '/users/' not in person_url:
        approve_handle_stored = person_url
        if group_account:
            approve_handle = '!' + approve_handle

    if os.path.isfile(approve_follows_filename):
        if not text_in_file(approve_handle, approve_follows_filename):
            try:
                with open(approve_follows_filename, 'a+',
                          encoding='utf-8') as fp_approve:
                    fp_approve.write(approve_handle_stored + '\n')
            except OSError:
                print('EX: store_follow_request 2 ' + approve_follows_filename)
        else:
            if debug:
                print('DEBUG: ' + approve_handle_stored +
                      ' is already awaiting approval')
    else:
        try:
            with open(approve_follows_filename, 'w+',
                      encoding='utf-8') as fp_approve:
                fp_approve.write(approve_handle_stored + '\n')
        except OSError:
            print('EX: store_follow_request 3 ' + approve_follows_filename)

    # store the follow request in its own directory
    # We don't rely upon the inbox because items in there could expire
    requests_dir = accounts_dir + '/requests'
    if not os.path.isdir(requests_dir):
        os.mkdir(requests_dir)
    follow_activity_filename = requests_dir + '/' + approve_handle + '.follow'
    return save_json(follow_json, follow_activity_filename)


def followed_account_accepts(session, base_dir: str, http_prefix: str,
                             nickname_to_follow: str, domain_to_follow: str,
                             port: int,
                             nickname: str, domain: str, from_port: int,
                             person_url: str, federation_list: [],
                             follow_json: {}, send_threads: [], post_log: [],
                             cached_webfingers: {}, person_cache: {},
                             debug: bool, project_version: str,
                             remove_follow_activity: bool,
                             signing_priv_key_pem: str,
                             curr_domain: str,
                             onion_domain: str, i2p_domain: str,
                             followers_sync_cache: {},
                             sites_unavailable: [],
                             system_language: str,
                             mitm_servers: []):
    """The person receiving a follow request accepts the new follower
    and sends back an Accept activity
    """
    accept_handle = nickname + '@' + domain

    # send accept back
    print('Sending follow Accept activity for ' +
          'follow request which arrived at ' +
          nickname_to_follow + '@' + domain_to_follow +
          ' back to ' + accept_handle)
    accept_json = create_accept(federation_list,
                                nickname_to_follow, domain_to_follow, port,
                                person_url, '', http_prefix,
                                follow_json)
    pprint(accept_json)
    print('DEBUG: sending follow Accept from ' +
          nickname_to_follow + '@' + domain_to_follow +
          ' port ' + str(port) + ' to ' +
          accept_handle + ' port ' + str(from_port))
    client_to_server = False

    if remove_follow_activity:
        # remove the follow request json
        follow_activity_filename = \
            acct_dir(base_dir, nickname_to_follow, domain_to_follow) + \
            '/requests/' + nickname + '@' + domain + '.follow'
        if os.path.isfile(follow_activity_filename):
            try:
                os.remove(follow_activity_filename)
            except OSError:
                print('EX: follow Accept ' +
                      'followed_account_accepts unable to delete ' +
                      follow_activity_filename)

    group_account = False
    if follow_json:
        if follow_json.get('actor'):
            actor_url = get_actor_from_post(follow_json)
            if has_group_type(base_dir, actor_url, person_cache):
                group_account = True

    extra_headers = {}
    domain_full = get_full_domain(domain, from_port)
    remove_followers_sync(followers_sync_cache,
                          nickname_to_follow,
                          domain_full)
    return send_signed_json(accept_json, session, base_dir,
                            nickname_to_follow, domain_to_follow, port,
                            nickname, domain, from_port,
                            http_prefix, client_to_server,
                            federation_list,
                            send_threads, post_log, cached_webfingers,
                            person_cache, debug, project_version, None,
                            group_account, signing_priv_key_pem,
                            7856837, curr_domain, onion_domain, i2p_domain,
                            extra_headers, sites_unavailable,
                            system_language, mitm_servers)


def followed_account_rejects(session, session_onion, session_i2p,
                             onion_domain: str, i2p_domain: str,
                             base_dir: str, http_prefix: str,
                             nickname_to_follow: str, domain_to_follow: str,
                             port: int,
                             nickname: str, domain: str, from_port: int,
                             federation_list: [],
                             send_threads: [], post_log: [],
                             cached_webfingers: {}, person_cache: {},
                             debug: bool, project_version: str,
                             signing_priv_key_pem: str,
                             followers_sync_cache: {},
                             sites_unavailable: [],
                             system_language: str,
                             mitm_servers: []):
    """The person receiving a follow request rejects the new follower
    and sends back a Reject activity
    """
    # send reject back
    if debug:
        print('DEBUG: sending Reject activity for ' +
              'follow request which arrived at ' +
              nickname_to_follow + '@' + domain_to_follow +
              ' back to ' + nickname + '@' + domain)

    # get the json for the original follow request
    follow_activity_filename = \
        acct_dir(base_dir, nickname_to_follow, domain_to_follow) + \
        '/requests/' + nickname + '@' + domain + '.follow'
    follow_json = load_json(follow_activity_filename)
    if not follow_json:
        print('No follow request json was found for ' +
              follow_activity_filename)
        return None
    # actor who made the follow request
    person_url = get_actor_from_post(follow_json)

    # create the reject activity
    reject_json = \
        create_reject(federation_list,
                      nickname_to_follow, domain_to_follow, port,
                      person_url, '', http_prefix, follow_json)
    if debug:
        pprint(reject_json)
        print('DEBUG: sending follow Reject from ' +
              nickname_to_follow + '@' + domain_to_follow +
              ' port ' + str(port) + ' to ' +
              nickname + '@' + domain + ' port ' + str(from_port))
    client_to_server = False
    deny_handle = get_full_domain(nickname + '@' + domain, from_port)
    group_account = False
    if has_group_type(base_dir, person_url, person_cache):
        group_account = True
    # remove from the follow requests file
    remove_from_follow_requests(base_dir, nickname_to_follow, domain_to_follow,
                                deny_handle, debug)
    # remove the follow request json
    try:
        os.remove(follow_activity_filename)
    except OSError:
        print('EX: followed_account_rejects unable to delete ' +
              follow_activity_filename)
    curr_session = session
    if domain.endswith('.onion') and session_onion:
        curr_session = session_onion
    elif domain.endswith('.i2p') and session_i2p:
        curr_session = session_i2p
    extra_headers = {}
    domain_full = get_full_domain(domain, from_port)
    remove_followers_sync(followers_sync_cache,
                          nickname_to_follow,
                          domain_full)
    # send the reject activity
    return send_signed_json(reject_json, curr_session, base_dir,
                            nickname_to_follow, domain_to_follow, port,
                            nickname, domain, from_port,
                            http_prefix, client_to_server,
                            federation_list,
                            send_threads, post_log, cached_webfingers,
                            person_cache, debug, project_version, None,
                            group_account, signing_priv_key_pem,
                            6393063,
                            domain, onion_domain, i2p_domain,
                            extra_headers, sites_unavailable,
                            system_language, mitm_servers)


def send_follow_request(session, base_dir: str,
                        nickname: str, domain: str,
                        sender_domain: str, sender_port: int,
                        http_prefix: str,
                        follow_nickname: str, follow_domain: str,
                        followed_actor: str,
                        follow_port: int, follow_http_prefix: str,
                        client_to_server: bool, federation_list: [],
                        send_threads: [], post_log: [], cached_webfingers: {},
                        person_cache: {}, debug: bool,
                        project_version: str, signing_priv_key_pem: str,
                        curr_domain: str,
                        onion_domain: str, i2p_domain: str,
                        sites_unavailable: [],
                        system_language: str,
                        mitm_servers: []) -> {}:
    """Gets the json object for sending a follow request
    """
    if not signing_priv_key_pem:
        print('WARN: follow request without signing key')

    if not domain_permitted(follow_domain, federation_list):
        print('You are not permitted to follow the domain ' + follow_domain)
        return None

    full_domain = get_full_domain(sender_domain, sender_port)
    follow_actor = local_actor_url(http_prefix, nickname, full_domain)

    request_domain = get_full_domain(follow_domain, follow_port)

    status_number, _ = get_status_number()

    group_account = False
    if follow_nickname:
        followed_id = followed_actor
        follow_handle = follow_nickname + '@' + request_domain
        group_account = has_group_type(base_dir, followed_actor, person_cache)
        if group_account:
            follow_handle = '!' + follow_handle
            print('Follow request being sent to group account')
    else:
        if debug:
            print('DEBUG: send_follow_request - assuming single user instance')
        followed_id = follow_http_prefix + '://' + request_domain
        single_user_nickname = 'dev'
        follow_handle = single_user_nickname + '@' + request_domain

    # remove follow handle from unfollowed.txt
    unfollowed_filename = \
        acct_dir(base_dir, nickname, domain) + '/unfollowed.txt'
    if os.path.isfile(unfollowed_filename):
        if text_in_file(follow_handle, unfollowed_filename):
            unfollowed_file = None
            try:
                with open(unfollowed_filename, 'r',
                          encoding='utf-8') as fp_unfoll:
                    unfollowed_file = fp_unfoll.read()
            except OSError:
                print('EX: send_follow_request ' + unfollowed_filename)
            if unfollowed_file:
                unfollowed_file = \
                    unfollowed_file.replace(follow_handle + '\n', '')
                try:
                    with open(unfollowed_filename, 'w+',
                              encoding='utf-8') as fp_unfoll:
                        fp_unfoll.write(unfollowed_file)
                except OSError:
                    print('EX: send_follow_request unable to write ' +
                          unfollowed_filename)

    new_follow_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': follow_actor + '/statuses/' + str(status_number),
        'type': 'Follow',
        'actor': follow_actor,
        'object': followed_id
    }
    if group_account:
        new_follow_json['to'] = [followed_id]
        print('Follow request: ' + str(new_follow_json))

    if follow_approval_required(base_dir, nickname, domain, debug,
                                follow_handle):
        # Remove any follow requests rejected for the account being followed.
        # It's assumed that if you are following someone then you are
        # ok with them following back. If this isn't the case then a rejected
        # follow request will block them again.
        _remove_from_follow_rejects(base_dir,
                                    nickname, domain,
                                    follow_handle, debug)
    extra_headers = {}
    send_signed_json(new_follow_json, session, base_dir,
                     nickname, sender_domain, sender_port,
                     follow_nickname, follow_domain, follow_port,
                     http_prefix, client_to_server,
                     federation_list,
                     send_threads, post_log, cached_webfingers, person_cache,
                     debug, project_version, None, group_account,
                     signing_priv_key_pem, 8234389,
                     curr_domain, onion_domain, i2p_domain,
                     extra_headers, sites_unavailable,
                     system_language, mitm_servers)

    return new_follow_json


def send_follow_request_via_server(base_dir: str, session,
                                   from_nickname: str, password: str,
                                   from_domain: str, from_port: int,
                                   follow_nickname: str, follow_domain: str,
                                   follow_port: int,
                                   http_prefix: str,
                                   cached_webfingers: {}, person_cache: {},
                                   debug: bool, project_version: str,
                                   signing_priv_key_pem: str,
                                   system_language: str,
                                   mitm_servers: []) -> {}:
    """Creates a follow request via c2s
    """
    if not session:
        print('WARN: No session for send_follow_request_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    follow_domain_full = get_full_domain(follow_domain, follow_port)

    follow_actor = \
        local_actor_url(http_prefix, from_nickname, from_domain_full)
    followed_id = \
        http_prefix + '://' + follow_domain_full + '/@' + follow_nickname

    status_number, _ = get_status_number()
    new_follow_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': follow_actor + '/statuses/' + str(status_number),
        'type': 'Follow',
        'actor': follow_actor,
        'object': followed_id
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: follow request webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: follow request Webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem, origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            project_version, http_prefix,
                            from_nickname,
                            from_domain, post_to_box, 52025,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: follow request no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: follow request no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, new_follow_json, [], inbox_url, headers, 3, True)
    if not post_result:
        if debug:
            print('DEBUG: POST follow request failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST follow request success')

    return new_follow_json


def send_unfollow_request_via_server(base_dir: str, session,
                                     from_nickname: str, password: str,
                                     from_domain: str, from_port: int,
                                     follow_nickname: str, follow_domain: str,
                                     follow_port: int,
                                     http_prefix: str,
                                     cached_webfingers: {}, person_cache: {},
                                     debug: bool, project_version: str,
                                     signing_priv_key_pem: str,
                                     system_language: str,
                                     mitm_servers: []) -> {}:
    """Creates a unfollow request via c2s
    """
    if not session:
        print('WARN: No session for send_unfollow_request_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)
    follow_domain_full = get_full_domain(follow_domain, follow_port)

    follow_actor = \
        local_actor_url(http_prefix, from_nickname, from_domain_full)
    followed_id = \
        http_prefix + '://' + follow_domain_full + '/@' + follow_nickname
    status_number, _ = get_status_number()

    unfollow_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': follow_actor + '/statuses/' + str(status_number) + '/undo',
        'type': 'Undo',
        'actor': follow_actor,
        'object': {
            'id': follow_actor + '/statuses/' + str(status_number),
            'type': 'Follow',
            'actor': follow_actor,
            'object': followed_id
        }
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: unfollow webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: unfollow webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session,
                            wf_request, person_cache,
                            project_version, http_prefix,
                            from_nickname,
                            from_domain, post_to_box,
                            76536, system_language,
                            mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: unfollow no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: unfollow no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, unfollow_json, [], inbox_url, headers, 3, True)
    if not post_result:
        if debug:
            print('DEBUG: POST unfollow failed for c2s to ' + inbox_url)
        return 5

    if debug:
        print('DEBUG: c2s POST unfollow success')

    return unfollow_json


def get_following_via_server(session, nickname: str, password: str,
                             domain: str, port: int,
                             http_prefix: str, page_number: int,
                             debug: bool, project_version: str,
                             signing_priv_key_pem: str,
                             mitm_servers: []) -> {}:
    """Gets a page from the following collection as json
    """
    if not session:
        print('WARN: No session for get_following_via_server')
        return 6

    domain_full = get_full_domain(domain, port)
    follow_actor = local_actor_url(http_prefix, nickname, domain_full)

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }

    page_number = max(page_number, 1)
    url = follow_actor + '/following?page=' + str(page_number)
    following_json = \
        get_json(signing_priv_key_pem, session, url, headers, {}, debug,
                 mitm_servers, project_version, http_prefix, domain, 10, True)
    if not get_json_valid(following_json):
        if debug:
            print('DEBUG: GET following list failed for c2s to ' + url)
        return 5

    if debug:
        print('DEBUG: c2s GET following list request success')

    return following_json


def get_followers_via_server(session, nickname: str, password: str,
                             domain: str, port: int,
                             http_prefix: str, page_number: int,
                             debug: bool, project_version: str,
                             signing_priv_key_pem: str,
                             mitm_servers: []) -> {}:
    """Gets a page from the followers collection as json
    """
    if not session:
        print('WARN: No session for get_followers_via_server')
        return 6

    domain_full = get_full_domain(domain, port)
    follow_actor = local_actor_url(http_prefix, nickname, domain_full)

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }

    page_number = max(page_number, 1)
    url = follow_actor + '/followers?page=' + str(page_number)
    followers_json = \
        get_json(signing_priv_key_pem, session, url, headers, {}, debug,
                 mitm_servers, project_version, http_prefix, domain, 10, True)
    if not get_json_valid(followers_json):
        if debug:
            print('DEBUG: GET followers list failed for c2s to ' + url)
        return 5

    if debug:
        print('DEBUG: c2s GET followers list request success')

    return followers_json


def get_follow_requests_via_server(session,
                                   nickname: str, password: str,
                                   domain: str, port: int,
                                   http_prefix: str, page_number: int,
                                   debug: bool, project_version: str,
                                   signing_priv_key_pem: str,
                                   mitm_servers: []) -> {}:
    """Gets a page from the follow requests collection as json
    """
    if not session:
        print('WARN: No session for get_follow_requests_via_server')
        return 6

    domain_full = get_full_domain(domain, port)

    follow_actor = local_actor_url(http_prefix, nickname, domain_full)
    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }

    page_number = max(page_number, 1)
    url = follow_actor + '/followrequests?page=' + str(page_number)
    followers_json = \
        get_json(signing_priv_key_pem, session, url, headers, {}, debug,
                 mitm_servers, project_version, http_prefix, domain, 10, True)
    if not get_json_valid(followers_json):
        if debug:
            print('DEBUG: GET follow requests list failed for c2s to ' + url)
        return 5

    if debug:
        print('DEBUG: c2s GET follow requests list request success')

    return followers_json


def approve_follow_request_via_server(session,
                                      nickname: str, password: str,
                                      domain: str, port: int,
                                      http_prefix: str, approve_handle: int,
                                      debug: bool, project_version: str,
                                      signing_priv_key_pem: str,
                                      mitm_servers: []) -> str:
    """Approves a follow request
    This is not exactly via c2s though. It simulates pressing the Approve
    button on the web interface
    """
    if not session:
        print('WARN: No session for approve_follow_request_via_server')
        return 6

    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'text/html; charset=utf-8',
        'Authorization': auth_header
    }

    url = actor + '/followapprove=' + approve_handle
    approve_html = \
        get_json(signing_priv_key_pem, session, url, headers, {}, debug,
                 mitm_servers, project_version, http_prefix, domain, 10, True)
    if not get_json_valid(approve_html):
        if debug:
            print('DEBUG: GET approve follow request failed for c2s to ' + url)
        return 5

    if debug:
        print('DEBUG: c2s GET approve follow request request success')

    return approve_html


def deny_follow_request_via_server(session,
                                   nickname: str, password: str,
                                   domain: str, port: int,
                                   http_prefix: str, deny_handle: int,
                                   debug: bool, project_version: str,
                                   signing_priv_key_pem: str,
                                   mitm_servers: []) -> str:
    """Denies a follow request
    This is not exactly via c2s though. It simulates pressing the Deny
    button on the web interface
    """
    if not session:
        print('WARN: No session for deny_follow_request_via_server')
        return 6

    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'text/html; charset=utf-8',
        'Authorization': auth_header
    }

    url = actor + '/followdeny=' + deny_handle
    deny_html = \
        get_json(signing_priv_key_pem, session, url, headers, {}, debug,
                 mitm_servers, project_version, http_prefix, domain, 10, True)
    if not get_json_valid(deny_html):
        if debug:
            print('DEBUG: GET deny follow request failed for c2s to ' + url)
        return 5

    if debug:
        print('DEBUG: c2s GET deny follow request request success')

    return deny_html


def get_followers_of_actor(base_dir: str, actor: str, debug: bool) -> {}:
    """In a shared inbox if we receive a post we know who it's from
    and if it's addressed to followers then we need to get a list of those.
    This returns a list of account handles which follow the given actor
    """
    if debug:
        print('DEBUG: getting followers of ' + actor)
    recipients_dict = {}
    if ':' not in actor:
        return recipients_dict
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        if debug:
            print('DEBUG: no nickname found in ' + actor)
        return recipients_dict
    domain, _ = get_domain_from_actor(actor)
    if not domain:
        if debug:
            print('DEBUG: no domain found in ' + actor)
        return recipients_dict
    actor_handle = nickname + '@' + domain
    if debug:
        print('DEBUG: searching for handle ' + actor_handle)
    # for each of the accounts
    dir_str = data_dir(base_dir)
    for subdir, dirs, _ in os.walk(dir_str):
        for account in dirs:
            if '@' not in account:
                continue
            if account.startswith('inbox@'):
                continue
            if account.startswith('Actor@'):
                continue
            following_filename = \
                os.path.join(subdir, account) + '/following.txt'
            if debug:
                print('DEBUG: examining follows of ' + account)
                print(following_filename)
            if os.path.isfile(following_filename):
                # does this account follow the given actor?
                if debug:
                    print('DEBUG: checking if ' + actor_handle +
                          ' in ' + following_filename)
                if text_in_file(actor_handle, following_filename):
                    if debug:
                        print('DEBUG: ' + account +
                              ' follows ' + actor_handle)
                    recipients_dict[account] = None
        break
    return recipients_dict


def outbox_undo_follow(base_dir: str, message_json: {}, debug: bool) -> None:
    """When an unfollow request is received by the outbox from c2s
    This removes the followed handle from the following.txt file
    of the relevant account
    """
    if not message_json.get('type'):
        return
    if not message_json['type'] == 'Undo':
        return
    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'Follow':
        if not message_json['object']['type'] == 'Join':
            return
    if not has_object_string_object(message_json, debug):
        return
    if not message_json['object'].get('actor'):
        return
    if debug:
        print('DEBUG: undo follow arrived in outbox')

    actor_url = get_actor_from_post(message_json['object'])
    nickname_follower = get_nickname_from_actor(actor_url)
    if not nickname_follower:
        print('WARN: unable to find nickname in ' +
              actor_url)
        return
    domain_follower, port_follower = get_domain_from_actor(actor_url)
    if not domain_follower:
        print('WARN: unable to find domain in ' + actor_url)
        return
    domain_follower_full = get_full_domain(domain_follower, port_follower)

    nickname_following = \
        get_nickname_from_actor(message_json['object']['object'])
    if not nickname_following:
        print('WARN: unable to find nickname in ' +
              message_json['object']['object'])
        return
    domain_following, port_following = \
        get_domain_from_actor(message_json['object']['object'])
    if not domain_following:
        print('WARN: unable to find domain in ' +
              message_json['object']['object'])
        return
    domain_following_full = get_full_domain(domain_following, port_following)

    group_account = \
        has_group_type(base_dir, message_json['object']['object'], None)
    if unfollow_account(base_dir, nickname_follower, domain_follower_full,
                        nickname_following, domain_following_full,
                        debug, group_account, 'following.txt'):
        if debug:
            print('DEBUG: ' + nickname_follower + ' unfollowed ' +
                  nickname_following + '@' + domain_following_full)
    else:
        if debug:
            print('WARN: ' + nickname_follower + ' could not unfollow ' +
                  nickname_following + '@' + domain_following_full)


def follower_approval_active(base_dir: str,
                             nickname: str, domain: str) -> bool:
    """Returns true if the given account requires follower approval
    """
    manually_approves_followers = False
    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if os.path.isfile(actor_filename):
        actor_json = load_json(actor_filename)
        if actor_json:
            if 'manuallyApprovesFollowers' in actor_json:
                manually_approves_followers = \
                    actor_json['manuallyApprovesFollowers']
    return manually_approves_followers


def remove_follower(base_dir: str,
                    nickname: str, domain: str,
                    remove_nickname: str, remove_domain: str) -> bool:
    """Removes a follower
    """
    followers_filename = \
        acct_dir(base_dir, nickname, domain) + '/followers.txt'
    if not os.path.isfile(followers_filename):
        return False
    followers_str = ''
    try:
        with open(followers_filename, 'r', encoding='utf-8') as fp_foll:
            followers_str = fp_foll.read()
    except OSError:
        print('EX: remove_follower unable to read followers ' +
              followers_filename)
        return False
    followers_list = followers_str.split('\n')

    handle = remove_nickname + '@' + remove_domain
    handle = handle.lower()
    new_followers_str = ''
    found = False
    for handle2 in followers_list:
        if handle2.lower() != handle:
            new_followers_str += handle2 + '\n'
        else:
            found = True
    if not found:
        return False
    try:
        with open(followers_filename, 'w+', encoding='utf-8') as fp_foll:
            fp_foll.write(new_followers_str)
    except OSError:
        print('EX: remove_follower unable to write followers ' +
              followers_filename)
    return True


def pending_followers_timeline_json(actor: str, base_dir: str,
                                    nickname: str, domain: str) -> {}:
    """Returns pending followers collection for an account
    https://codeberg.org/fediverse/fep/src/branch/main/fep/4ccd/fep-4ccd.md
    """
    result_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        "id": actor,
        "type": "OrderedCollection",
        "name": nickname + "'s Pending Followers",
        "orderedItems": []
    }

    follow_requests_filename = \
        acct_dir(base_dir, nickname, domain) + '/followrequests.txt'
    if os.path.isfile(follow_requests_filename):
        try:
            with open(follow_requests_filename, 'r',
                      encoding='utf-8') as fp_req:
                for follower_handle in fp_req:
                    if len(follower_handle) == 0:
                        continue
                    follower_handle = remove_eol(follower_handle)
                    foll_domain, _ = get_domain_from_actor(follower_handle)
                    if not foll_domain:
                        continue
                    foll_nickname = get_nickname_from_actor(follower_handle)
                    if not foll_nickname:
                        continue
                    follow_activity_filename = \
                        acct_dir(base_dir, nickname, domain) + \
                        '/requests/' + \
                        foll_nickname + '@' + foll_domain + '.follow'
                    if not os.path.isfile(follow_activity_filename):
                        continue
                    follow_json = load_json(follow_activity_filename)
                    if not follow_json:
                        continue
                    result_json['orderedItems'].append(follow_json)
        except OSError as exc:
            print('EX: unable to read follow requests ' +
                  follow_requests_filename + ' ' + str(exc))
    return result_json
