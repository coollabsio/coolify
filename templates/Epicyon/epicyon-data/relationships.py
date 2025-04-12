__filename__ = "relationships.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
from flags import is_dormant
from utils import data_dir
from utils import get_user_paths
from utils import acct_dir
from utils import valid_nickname
from utils import get_full_domain
from utils import local_actor_url
from utils import remove_domain_port
from utils import remove_eol
from utils import is_account_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import load_json


def get_moved_accounts(base_dir: str, nickname: str, domain: str,
                       filename: str) -> {}:
    """returns a dict of moved accounts
    """
    moved_accounts_filename = data_dir(base_dir) + '/actors_moved.txt'
    if not os.path.isfile(moved_accounts_filename):
        return {}
    refollow_str = ''
    try:
        with open(moved_accounts_filename, 'r',
                  encoding='utf-8') as fp_refollow:
            refollow_str = fp_refollow.read()
    except OSError:
        print('EX: get_moved_accounts unable to read 1 ' +
              moved_accounts_filename)
    refollow_list = refollow_str.split('\n')
    refollow_dict = {}

    follow_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + filename
    follow_str = ''
    try:
        with open(follow_filename, 'r',
                  encoding='utf-8') as fp_follow:
            follow_str = fp_follow.read()
    except OSError:
        print('EX: get_moved_accounts unable to read 2 ' +
              follow_filename)
    follow_list = follow_str.split('\n')

    ctr = 0
    for line in refollow_list:
        if ' ' not in line:
            continue
        prev_handle = line.split(' ')[0]
        new_handle = line.split(' ')[1]
        refollow_dict[prev_handle] = new_handle
        ctr = ctr + 1

    result = {}
    for handle in follow_list:
        if refollow_dict.get(handle):
            if refollow_dict[handle] not in follow_list:
                result[handle] = refollow_dict[handle]
    return result


def get_moved_feed(base_dir: str, domain: str, port: int, path: str,
                   http_prefix: str, authorized: bool,
                   follows_per_page=12) -> {}:
    """Returns the moved accounts feed from GET requests.
    """
    # Don't show moved accounts to non-authorized viewers
    if not authorized:
        follows_per_page = 0

    if '/moved' not in path:
        return None
    if '?page=' not in path:
        path = path.replace('/moved', '/moved?page=true')
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
                print('EX: get_moved_feed unable to convert to int ' +
                      str(page_number))
        path = path.split('?page=')[0]
        header_only = False

    if not path.endswith('/moved'):
        return None
    nickname = None
    if path.startswith('/users/'):
        nickname = \
            path.replace('/users/', '', 1).replace('/moved', '')
    if path.startswith('/@'):
        if '/@/' not in path:
            nickname = path.replace('/@', '', 1).replace('/moved', '')
    if not nickname:
        return None
    if not valid_nickname(domain, nickname):
        return None

    domain = get_full_domain(domain, port)

    lines = get_moved_accounts(base_dir, nickname, domain,
                               'following.txt')

    actor = local_actor_url(http_prefix, nickname, domain)
    if header_only:
        first_str = actor + '/moved?page=1'
        id_str = actor + '/moved'
        total_str = str(len(lines.items()))
        following = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'first': first_str,
            'id': id_str,
            'followingOf': actor,
            'orderedItems': [],
            'totalItems': total_str,
            'type': 'OrderedCollection'
        }
        return following

    if not page_number:
        page_number = 1

    next_page_number = int(page_number + 1)
    id_str = \
        actor + '/moved?page=' + str(page_number)
    part_of_str = actor + '/moved'
    following = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': id_str,
        'followingOf': actor,
        'orderedItems': [],
        'partOf': part_of_str,
        'totalItems': 0,
        'type': 'OrderedCollectionPage'
    }

    handle_domain = domain
    handle_domain = remove_domain_port(handle_domain)
    curr_page = 1
    page_ctr = 0
    total_ctr = 0
    for handle, _ in lines.items():
        # nickname@domain
        page_ctr += 1
        total_ctr += 1
        if curr_page == page_number:
            line2_lower = handle.lower()
            line2 = remove_eol(line2_lower)
            url = None
            if '@' in line2:
                nick = line2.split('@')[0]
                dom = line2.split('@')[1]
                if not nick.startswith('!'):
                    # person actor
                    url = local_actor_url(http_prefix, nick, dom)
                else:
                    # group actor
                    url = http_prefix + '://' + dom + '/c/' + nick
            else:
                if '://' in line2:
                    url = remove_eol(handle)
            if url:
                following['orderedItems'].append(url)
        if page_ctr >= follows_per_page:
            page_ctr = 0
            curr_page += 1
    following['totalItems'] = total_ctr
    last_page = int(total_ctr / follows_per_page)
    last_page = max(last_page, 1)
    if next_page_number > last_page:
        following['next'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/moved?page=' + str(last_page)
    return following


def update_moved_actors(base_dir: str, debug: bool) -> None:
    """Updates the file containing moved actors
    """
    actors_cache_dir = base_dir + '/cache/actors'
    if not os.path.isdir(actors_cache_dir):
        if debug:
            print('No cached actors')
        return

    if debug:
        print('Updating moved actors')
    actors_dict = {}
    ctr = 0
    for _, _, files in os.walk(actors_cache_dir):
        for actor_str in files:
            if not actor_str.endswith('.json'):
                continue
            orig_str = actor_str
            actor_str = actor_str.replace('.json', '').replace('#', '/')
            nickname = get_nickname_from_actor(actor_str)
            domain, port = get_domain_from_actor(actor_str)
            if not domain:
                continue
            domain_full = get_full_domain(domain, port)
            handle = nickname + '@' + domain_full
            actors_dict[handle] = orig_str
            ctr += 1
        break

    if actors_dict:
        print('Actors dict created ' + str(ctr))
    else:
        print('No cached actors found')

    # get the handles to be checked for movedTo attribute
    handles_to_check: list[str] = []
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for account in dirs:
            if not is_account_dir(account):
                continue
            following_filename = dir_str + '/' + account + '/following.txt'
            if not os.path.isfile(following_filename):
                continue
            following_str = ''
            try:
                with open(following_filename, 'r',
                          encoding='utf-8') as fp_foll:
                    following_str = fp_foll.read()
            except OSError:
                print('EX: update_moved_actors unable to read ' +
                      following_filename)
                continue
            following_list = following_str.split('\n')
            for handle in following_list:
                if handle not in handles_to_check:
                    handles_to_check.append(handle)
        break

    if handles_to_check:
        print('All accounts handles list generated ' +
              str(len(handles_to_check)))
    else:
        print('No accounts are following')

    moved_str = ''
    ctr = 0
    for handle in handles_to_check:
        if not actors_dict.get(handle):
            continue
        actor_filename = base_dir + '/cache/actors/' + actors_dict[handle]
        if not os.path.isfile(actor_filename):
            continue
        actor_json = load_json(actor_filename)
        if not actor_json:
            continue
        if not actor_json.get('movedTo'):
            continue
        nickname = get_nickname_from_actor(actor_json['movedTo'])
        if not nickname:
            continue
        domain, port = get_domain_from_actor(actor_json['movedTo'])
        if not domain:
            continue
        domain_full = get_full_domain(domain, port)
        new_handle = nickname + '@' + domain_full
        moved_str += handle + ' ' + new_handle + '\n'
        ctr = ctr + 1

    if moved_str:
        print('Moved accounts detected ' + str(ctr))
    else:
        print('No moved accounts detected')

    moved_accounts_filename = data_dir(base_dir) + '/actors_moved.txt'
    if not moved_str:
        if os.path.isfile(moved_accounts_filename):
            try:
                os.remove(moved_accounts_filename)
            except OSError:
                print('EX: update_moved_actors unable to remove ' +
                      moved_accounts_filename)
        return

    try:
        with open(moved_accounts_filename, 'w+',
                  encoding='utf-8') as fp_moved:
            fp_moved.write(moved_str)
    except OSError:
        print('EX: update_moved_actors unable to save ' +
              moved_accounts_filename)


def _get_inactive_accounts(base_dir: str, nickname: str, domain: str,
                           dormant_months: int,
                           sites_unavailable: []) -> []:
    """returns a list of inactive accounts
    """
    # get the list of followers
    followers_filename = \
        acct_dir(base_dir, nickname, domain) + '/followers.txt'
    followers_str = ''
    try:
        with open(followers_filename, 'r',
                  encoding='utf-8') as fp_follow:
            followers_str = fp_follow.read()
    except OSError:
        print('EX: get_moved_accounts unable to read ' +
              followers_filename)
    followers_list = followers_str.split('\n')

    result: list[str] = []
    users_list = get_user_paths()
    for handle in followers_list:
        if handle in result:
            continue
        if '@' in handle:
            follower_nickname = handle.split('@')[0]
            follower_domain = handle.split('@')[1]
            if follower_domain in sites_unavailable:
                result.append(handle)
                continue
            found = False
            for http_prefix in ('https://', 'http://'):
                for users_str in users_list:
                    actor = \
                        http_prefix + follower_domain + users_str + \
                        follower_nickname
                    if is_dormant(base_dir, nickname, domain, actor,
                                  dormant_months):
                        result.append(handle)
                        found = True
                        break
                if not found:
                    actor = \
                        http_prefix + follower_domain + '/' + \
                        follower_nickname
                    if is_dormant(base_dir, nickname, domain, actor,
                                  dormant_months):
                        result.append(handle)
                        found = True
                if found:
                    break
        elif '://' in handle:
            actor = handle
            follower_domain = actor.split('://')[1]
            if '/' in follower_domain:
                follower_domain = follower_domain.split('/')[0]
            if follower_domain in sites_unavailable:
                result.append(actor)
                continue
            if is_dormant(base_dir, nickname, domain, actor,
                          dormant_months):
                result.append(actor)
    return result


def get_inactive_feed(base_dir: str, domain: str, port: int, path: str,
                      http_prefix: str, authorized: bool,
                      dormant_months: int,
                      follows_per_page: int, sites_unavailable: []) -> {}:
    """Returns the inactive accounts feed from GET requests.
    """
    # Don't show inactive accounts to non-authorized viewers
    if not authorized:
        follows_per_page = 0

    if '/inactive' not in path:
        return None
    if '?page=' not in path:
        path = path.replace('/inactive', '/inactive?page=true')
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
                print('EX: get_inactive_feed unable to convert to int ' +
                      str(page_number))
        path = path.split('?page=')[0]
        header_only = False

    if not path.endswith('/inactive'):
        return None
    nickname = None
    if path.startswith('/users/'):
        nickname = \
            path.replace('/users/', '', 1).replace('/inactive', '')
    if path.startswith('/@'):
        if '/@/' not in path:
            nickname = path.replace('/@', '', 1).replace('/inactive', '')
    if not nickname:
        return None
    if not valid_nickname(domain, nickname):
        return None

    domain = get_full_domain(domain, port)

    lines = _get_inactive_accounts(base_dir, nickname, domain,
                                   dormant_months,
                                   sites_unavailable)

    actor = local_actor_url(http_prefix, nickname, domain)
    if header_only:
        first_str = actor + '/moved?page=1'
        id_str = actor + '/inactive'
        total_str = str(len(lines))
        following = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'first': first_str,
            'id': id_str,
            'followingOf': actor,
            'orderedItems': [],
            'totalItems': total_str,
            'type': 'OrderedCollection'
        }
        return following

    if not page_number:
        page_number = 1

    next_page_number = int(page_number + 1)
    id_str = actor + '/inactive?page=' + str(page_number)
    part_of_str = actor + '/inactive'
    following = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': id_str,
        'orderedItems': [],
        'partOf': part_of_str,
        'followingOf': actor,
        'totalItems': 0,
        'type': 'OrderedCollectionPage'
    }

    handle_domain = domain
    handle_domain = remove_domain_port(handle_domain)
    curr_page = 1
    page_ctr = 0
    total_ctr = 0
    for handle in lines:
        # nickname@domain
        page_ctr += 1
        total_ctr += 1
        if curr_page == page_number:
            line2_lower = handle.lower()
            line2 = remove_eol(line2_lower)
            url = None
            if '@' in line2:
                nick = line2.split('@')[0]
                dom = line2.split('@')[1]
                if not nick.startswith('!'):
                    # person actor
                    url = local_actor_url(http_prefix, nick, dom)
                else:
                    # group actor
                    url = http_prefix + '://' + dom + '/c/' + nick
            else:
                if '://' in line2:
                    url = remove_eol(handle)
            if url:
                following['orderedItems'].append(url)
        if page_ctr >= follows_per_page:
            page_ctr = 0
            curr_page += 1
    following['totalItems'] = total_ctr
    last_page = int(total_ctr / follows_per_page)
    last_page = max(last_page, 1)
    if next_page_number > last_page:
        following['next'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/inactive?page=' + str(last_page)
    return following
