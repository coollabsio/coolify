__filename__ = "migrate.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
from flags import has_group_type
from utils import data_dir
from utils import is_account_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import acct_dir
from webfinger import webfinger_handle
from blocking import is_blocked
from posts import get_user_url
from follow import unfollow_account
from person import get_actor_json


def _move_following_handles_for_account(base_dir: str,
                                        nickname: str, domain: str,
                                        session,
                                        http_prefix: str,
                                        cached_webfingers: {},
                                        debug: bool,
                                        signing_priv_key_pem: str,
                                        block_federated: [],
                                        mitm_servers: []) -> int:
    """Goes through all follows for an account and updates any that have moved
    """
    ctr = 0
    following_filename = \
        acct_dir(base_dir, nickname, domain) + '/following.txt'
    if not os.path.isfile(following_filename):
        return ctr
    try:
        with open(following_filename, 'r', encoding='utf-8') as fp_foll:
            following_handles = fp_foll.readlines()
            for follow_handle in following_handles:
                follow_handle = follow_handle.strip("\n").strip("\r")
                ctr += \
                    _update_moved_handle(base_dir, nickname, domain,
                                         follow_handle, session,
                                         http_prefix, cached_webfingers,
                                         debug, signing_priv_key_pem,
                                         block_federated, mitm_servers)
    except OSError:
        print('EX: _move_following_handles_for_account unable to read ' +
              following_filename)
    return ctr


def _update_moved_handle(base_dir: str, nickname: str, domain: str,
                         handle: str, session,
                         http_prefix: str, cached_webfingers: {},
                         debug: bool, signing_priv_key_pem: str,
                         block_federated: [],
                         mitm_servers: []) -> int:
    """Check if an account has moved, and if so then alter following.txt
    for each account.
    Returns 1 if moved, 0 otherwise
    """
    ctr = 0
    if '@' not in handle:
        return ctr
    if len(handle) < 5:
        return ctr
    if handle.startswith('@'):
        handle = handle[1:]
    wf_request = webfinger_handle(session, handle,
                                  http_prefix, cached_webfingers,
                                  domain, __version__, debug, False,
                                  signing_priv_key_pem,
                                  mitm_servers)
    if not wf_request:
        print('updateMovedHandle unable to webfinger ' + handle)
        return ctr

    if not isinstance(wf_request, dict):
        print('updateMovedHandle webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return ctr

    person_url = None
    if wf_request.get('errors'):
        print('wf_request error: ' + str(wf_request['errors']))
        return ctr

    if not person_url:
        person_url = get_user_url(wf_request, 0, debug)
        if not person_url:
            return ctr

    gnunet = False
    if http_prefix == 'gnunet':
        gnunet = True
    ipfs = False
    if http_prefix == 'ipfs':
        ipfs = True
    ipns = False
    if http_prefix == 'ipns':
        ipns = True
    mitm_servers: list[str] = []
    person_json = \
        get_actor_json(domain, person_url, http_prefix, gnunet, ipfs, ipns,
                       debug, False,
                       signing_priv_key_pem, None, mitm_servers)
    if not person_json:
        return ctr
    if not person_json.get('movedTo'):
        return ctr
    moved_to_url = person_json['movedTo']
    if '://' not in moved_to_url:
        return ctr
    if '.' not in moved_to_url:
        return ctr
    moved_to_nickname = get_nickname_from_actor(moved_to_url)
    if not moved_to_nickname:
        return ctr
    moved_to_domain, moved_to_port = get_domain_from_actor(moved_to_url)
    if not moved_to_domain:
        return ctr
    moved_to_domain_full = moved_to_domain
    if moved_to_port:
        if moved_to_port not in (80, 443):
            moved_to_domain_full = moved_to_domain + ':' + str(moved_to_port)
    group_account = has_group_type(base_dir, moved_to_url, None)
    if is_blocked(base_dir, nickname, domain,
                  moved_to_nickname, moved_to_domain,
                  None, block_federated):
        # someone that you follow has moved to a blocked domain
        # so just unfollow them
        unfollow_account(base_dir, nickname, domain,
                         moved_to_nickname, moved_to_domain_full,
                         debug, group_account, 'following.txt')
        return ctr

    following_filename = \
        acct_dir(base_dir, nickname, domain) + '/following.txt'
    if os.path.isfile(following_filename):
        following_handles: list[str] = []
        try:
            with open(following_filename, 'r', encoding='utf-8') as fp_foll1:
                following_handles = fp_foll1.readlines()
        except OSError:
            print('EX: _update_moved_handle unable to read ' +
                  following_filename)

        moved_to_handle = moved_to_nickname + '@' + moved_to_domain_full
        handle_lower = handle.lower()

        refollow_filename = \
            acct_dir(base_dir, nickname, domain) + '/refollow.txt'

        # unfollow the old handle
        with open(following_filename, 'w+', encoding='utf-8') as fp_foll2:
            for follow_handle in following_handles:
                if follow_handle.strip("\n").strip("\r").lower() != \
                   handle_lower:
                    fp_foll2.write(follow_handle)
                    continue
                handle_nickname = handle.split('@')[0]
                handle_domain = handle.split('@')[1]
                unfollow_account(base_dir, nickname, domain,
                                 handle_nickname,
                                 handle_domain,
                                 debug, group_account, 'following.txt')
                ctr += 1
                print('Unfollowed ' + handle + ' who has moved to ' +
                      moved_to_handle)

                # save the new handles to the refollow list
                if os.path.isfile(refollow_filename):
                    try:
                        with open(refollow_filename, 'a+',
                                  encoding='utf-8') as fp_refoll:
                            fp_refoll.write(moved_to_handle + '\n')
                    except OSError:
                        print('EX: ' +
                              '_update_moved_handle unable to append ' +
                              refollow_filename)
                else:
                    try:
                        with open(refollow_filename, 'w+',
                                  encoding='utf-8') as fp_refoll:
                            fp_refoll.write(moved_to_handle + '\n')
                    except OSError:
                        print('EX: _update_moved_handle unable to write ' +
                              refollow_filename)

    followers_filename = \
        acct_dir(base_dir, nickname, domain) + '/followers.txt'
    if os.path.isfile(followers_filename):
        follower_handles: list[str] = []
        try:
            with open(followers_filename, 'r', encoding='utf-8') as fp_foll3:
                follower_handles = fp_foll3.readlines()
        except OSError:
            print('EX: _update_moved_handle unable to read ' +
                  followers_filename)

        handle_lower = handle.lower()

        # remove followers who have moved
        try:
            with open(followers_filename, 'w+', encoding='utf-8') as fp_foll4:
                for follower_handle in follower_handles:
                    if follower_handle.strip("\n").strip("\r").lower() != \
                       handle_lower:
                        fp_foll4.write(follower_handle)
                    else:
                        ctr += 1
                        print('Removed follower who has moved ' + handle)
        except OSError:
            print('EX: _update_moved_handle unable to remove moved follower ' +
                  handle)

    return ctr


def migrate_accounts(base_dir: str, session,
                     http_prefix: str, cached_webfingers: {},
                     debug: bool, signing_priv_key_pem: str,
                     block_federated: [],
                     mitm_servers: []) -> int:
    """If followed accounts change then this modifies the
    following lists for each account accordingly.
    Returns the number of accounts migrated
    """
    # update followers and following lists for each account
    ctr = 0
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if not is_account_dir(handle):
                continue
            nickname = handle.split('@')[0]
            domain = handle.split('@')[1]
            ctr += \
                _move_following_handles_for_account(base_dir, nickname, domain,
                                                    session, http_prefix,
                                                    cached_webfingers, debug,
                                                    signing_priv_key_pem,
                                                    block_federated,
                                                    mitm_servers)
        break
    return ctr
