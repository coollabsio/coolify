__filename__ = "blocking.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
import json
import time
from session import get_json_valid
from session import create_session
from flags import is_evil
from flags import is_quote_toot
from utils import get_quote_toot_url
from utils import get_user_paths
from utils import contains_statuses
from utils import data_dir
from utils import string_contains
from utils import date_from_string_format
from utils import date_utcnow
from utils import remove_eol
from utils import has_object_string
from utils import has_object_string_object
from utils import has_object_string_type
from utils import remove_domain_port
from utils import has_object_dict
from utils import is_account_dir
from utils import get_cached_post_filename
from utils import load_json
from utils import save_json
from utils import file_last_modified
from utils import set_config_param
from utils import has_users_path
from utils import get_full_domain
from utils import remove_id_ending
from utils import locate_post
from utils import evil_incarnate
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from utils import acct_dir
from utils import local_actor_url
from utils import has_actor
from utils import text_in_file
from utils import get_actor_from_post
from conversation import mute_conversation
from conversation import unmute_conversation
from auth import create_basic_auth_header
from session import get_json


def get_global_block_reason(search_text: str,
                            blocking_reasons_filename: str) -> str:
    """Returns the reason why a domain was globally blocked
    """
    if not text_in_file(search_text, blocking_reasons_filename):
        return ''

    reasons_str = ''
    try:
        with open(blocking_reasons_filename, 'r',
                  encoding='utf-8') as fp_reas:
            reasons_str = fp_reas.read()
    except OSError:
        print('WARN: Failed to read blocking reasons ' +
              blocking_reasons_filename)
    if not reasons_str:
        return ''

    reasons_lines = reasons_str.split('\n')
    for line in reasons_lines:
        if line.startswith(search_text):
            if ' ' in line:
                return line.split(' ', 1)[1]
    return ''


def get_account_blocks(base_dir: str,
                       nickname: str, domain: str) -> str:
    """Return the text for the textarea for "blocked accounts"
    when editing profile
    """
    account_directory = acct_dir(base_dir, nickname, domain)
    blocking_filename = \
        account_directory + '/blocking.txt'
    blocking_reasons_filename = \
        account_directory + '/blocking_reasons.txt'

    if not os.path.isfile(blocking_filename):
        return ''

    blocked_accounts_textarea = ''
    blocking_file_text = ''
    try:
        with open(blocking_filename, 'r', encoding='utf-8') as fp_block:
            blocking_file_text = fp_block.read()
    except OSError:
        print('EX: Failed to read account blocks ' + blocking_filename)
        return ''

    blocklist = blocking_file_text.split('\n')
    for handle in blocklist:
        handle = handle.strip()
        if not handle:
            continue
        reason = \
            get_global_block_reason(handle,
                                    blocking_reasons_filename)
        if reason:
            blocked_accounts_textarea += \
                handle + ' - ' + reason + '\n'
            continue
        blocked_accounts_textarea += handle + '\n'

    return blocked_accounts_textarea


def blocked_timeline_json(actor: str, page_number: int, items_per_page: int,
                          base_dir: str,
                          nickname: str, domain: str) -> {}:
    """Returns blocked collection for an account
    https://codeberg.org/fediverse/fep/src/branch/main/fep/c648/fep-c648.md
    """
    blocked_accounts_textarea = \
        get_account_blocks(base_dir, nickname, domain)
    blocked_list: list[str] = []
    if blocked_accounts_textarea:
        blocked_list = blocked_accounts_textarea.split('\n')
    start_index = (page_number - 1) * items_per_page
    if start_index >= len(blocked_list):
        start_index = 0
    last_page_number = (len(blocked_list) / items_per_page) + 1

    result_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            "https://purl.archive.org/socialweb/blocked"
        ],
        "id": actor + '?page=' + str(page_number),
        "blocksOf": actor,
        "first": actor + '?page=1',
        "last": actor + '?page=' + str(last_page_number),
        "type": "OrderedCollection",
        "name": nickname + "'s Blocked Collection",
        "orderedItems": []
    }

    index = start_index
    for _ in range(items_per_page):
        if index >= len(blocked_list):
            break
        block_handle = blocked_list[index]
        block_reason = ''
        if ' - ' in block_handle:
            block_reason = block_handle.split(' - ')[1]
            block_handle = block_handle.split(' - ')[0]
        block_type = "Person"
        if block_handle.startswith('*@'):
            block_type = "Application"
            block_handle = block_handle.split('*@', 1)[1]
        block_json = {
            "type": "Block",
            "id": actor + '/' + str(index),
            "object": {
                "type": block_type,
                "id": block_handle
            }
        }
        if block_reason:
            block_json["object"]["name"] = block_reason
        result_json["orderedItems"].append(block_json)
        index += 1
    return result_json


def add_account_blocks(base_dir: str,
                       nickname: str, domain: str,
                       blocked_accounts_textarea: str) -> bool:
    """Update the blockfile for an account after editing their
    profile and changing "blocked accounts"
    """
    if blocked_accounts_textarea is None:
        return False
    blocklist = blocked_accounts_textarea.split('\n')
    blocking_file_text = ''
    blocking_reasons_file_text = ''
    for line in blocklist:
        line = line.strip()
        reason = None
        if ' - ' in line:
            block_id = line.split(' - ', 1)[0]
            reason = line.split(' - ', 1)[1]
            blocking_reasons_file_text += block_id + ' ' + reason + '\n'
        elif ' ' in line:
            block_id = line.split(' ', 1)[0]
            reason = line.split(' ', 1)[1]
            blocking_reasons_file_text += block_id + ' ' + reason + '\n'
        else:
            block_id = line
        blocking_file_text += block_id + '\n'

    account_directory = acct_dir(base_dir, nickname, domain)
    blocking_filename = \
        account_directory + '/blocking.txt'
    blocking_reasons_filename = \
        account_directory + '/blocking_reasons.txt'

    if not blocking_file_text:
        if os.path.isfile(blocking_filename):
            try:
                os.remove(blocking_filename)
            except OSError:
                print('EX: _profile_edit unable to delete  blocking ' +
                      blocking_filename)
        if os.path.isfile(blocking_reasons_filename):
            try:
                os.remove(blocking_reasons_filename)
            except OSError:
                print('EX: _profile_edit unable to delete blocking reasons' +
                      blocking_reasons_filename)
        return True

    try:
        with open(blocking_filename, 'w+', encoding='utf-8') as fp_block:
            fp_block.write(blocking_file_text)
    except OSError:
        print('EX: Failed to write ' + blocking_filename)

    try:
        with open(blocking_reasons_filename, 'w+',
                  encoding='utf-8') as fp_block:
            fp_block.write(blocking_reasons_file_text)
    except OSError:
        print('EX: Failed to write ' + blocking_reasons_filename)
    return True


def _add_global_block_reason(base_dir: str,
                             block_nickname: str, block_domain: str,
                             reason: str) -> bool:
    """Store a global block reason
    """
    if not reason:
        return False

    blocking_reasons_filename = \
        data_dir(base_dir) + '/blocking_reasons.txt'

    if not block_nickname.startswith('#'):
        # is the handle already blocked?
        block_id = block_nickname + '@' + block_domain
    else:
        block_id = block_nickname

    reason = reason.replace('\n', '').strip()
    reason_line = block_id + ' ' + reason + '\n'

    if os.path.isfile(blocking_reasons_filename):
        if not text_in_file(block_id,
                            blocking_reasons_filename):
            try:
                with open(blocking_reasons_filename, 'a+',
                          encoding='utf-8') as fp_reas:
                    fp_reas.write(reason_line)
            except OSError:
                print('EX: unable to add blocking reason ' +
                      block_id)
        else:
            reasons_str = ''
            try:
                with open(blocking_reasons_filename, 'r',
                          encoding='utf-8') as fp_reas:
                    reasons_str = fp_reas.read()
            except OSError:
                print('EX: unable to read blocking reasons')
            reasons_lines = reasons_str.split('\n')
            new_reasons_str = ''
            for line in reasons_lines:
                if not line.startswith(block_id + ' '):
                    new_reasons_str += line + '\n'
                    continue
                new_reasons_str += reason_line
            try:
                with open(blocking_reasons_filename, 'w+',
                          encoding='utf-8') as fp_reas:
                    fp_reas.write(new_reasons_str)
            except OSError:
                print('EX: unable to save blocking reasons' +
                      blocking_reasons_filename)
    else:
        try:
            with open(blocking_reasons_filename, 'w+',
                      encoding='utf-8') as fp_reas:
                fp_reas.write(reason_line)
        except OSError:
            print('EX: unable to save blocking reason ' +
                  block_id + ' ' + blocking_reasons_filename)
    return True


def add_global_block(base_dir: str,
                     block_nickname: str, block_domain: str,
                     reason: str) -> bool:
    """Global block which applies to all accounts
    """
    _add_global_block_reason(base_dir,
                             block_nickname, block_domain,
                             reason)

    blocking_filename = data_dir(base_dir) + '/blocking.txt'
    if not block_nickname.startswith('#'):
        # is the handle already blocked?
        block_handle = block_nickname + '@' + block_domain
        if os.path.isfile(blocking_filename):
            if text_in_file(block_handle, blocking_filename):
                return False
        # block an account handle or domain
        try:
            with open(blocking_filename, 'a+', encoding='utf-8') as fp_block:
                fp_block.write(block_handle + '\n')
        except OSError:
            print('EX: unable to save blocked handle ' + block_handle)
            return False
    else:
        block_hashtag = block_nickname
        # is the hashtag already blocked?
        if os.path.isfile(blocking_filename):
            if text_in_file(block_hashtag + '\n', blocking_filename):
                return False
        # block a hashtag
        try:
            with open(blocking_filename, 'a+', encoding='utf-8') as fp_block:
                fp_block.write(block_hashtag + '\n')
        except OSError:
            print('EX: unable to save blocked hashtag ' + block_hashtag)
            return False
    return True


def _add_block_reason(base_dir: str,
                      nickname: str, domain: str,
                      block_nickname: str, block_domain: str,
                      reason: str) -> bool:
    """Store an account level block reason
    """
    if not reason:
        return False

    domain = remove_domain_port(domain)
    blocking_reasons_filename = \
        acct_dir(base_dir, nickname, domain) + '/blocking_reasons.txt'

    if not block_nickname.startswith('#'):
        # is the handle already blocked?
        block_id = block_nickname + '@' + block_domain
    else:
        block_id = block_nickname

    reason = reason.replace('\n', '').strip()
    reason_line = block_id + ' ' + reason + '\n'

    if os.path.isfile(blocking_reasons_filename):
        if not text_in_file(block_id,
                            blocking_reasons_filename):
            try:
                with open(blocking_reasons_filename, 'a+',
                          encoding='utf-8') as fp_reas:
                    fp_reas.write(reason_line)
            except OSError:
                print('EX: unable to add blocking reason 2 ' +
                      block_id)
        else:
            reasons_str = ''
            try:
                with open(blocking_reasons_filename, 'r',
                          encoding='utf-8') as fp_reas:
                    reasons_str = fp_reas.read()
            except OSError:
                print('EX: unable to read blocking reasons 2')
            reasons_lines = reasons_str.split('\n')
            new_reasons_str = ''
            for line in reasons_lines:
                if not line.startswith(block_id + ' '):
                    new_reasons_str += line + '\n'
                    continue
                new_reasons_str += reason_line
            try:
                with open(blocking_reasons_filename, 'w+',
                          encoding='utf-8') as fp_reas:
                    fp_reas.write(new_reasons_str)
            except OSError:
                print('EX: unable to save blocking reasons 2' +
                      blocking_reasons_filename)
    else:
        try:
            with open(blocking_reasons_filename, 'w+',
                      encoding='utf-8') as fp_reas:
                fp_reas.write(reason_line)
        except OSError:
            print('EX: unable to save blocking reason 2 ' +
                  block_id + ' ' + blocking_reasons_filename)
    return True


def add_block(base_dir: str, nickname: str, domain: str,
              block_nickname: str, block_domain: str,
              reason: str) -> bool:
    """Block the given account
    """
    if block_domain.startswith(domain) and nickname == block_nickname:
        # don't block self
        return False

    domain = remove_domain_port(domain)
    blocking_filename = acct_dir(base_dir, nickname, domain) + '/blocking.txt'
    block_handle = block_nickname + '@' + block_domain
    if os.path.isfile(blocking_filename):
        if text_in_file(block_handle + '\n', blocking_filename):
            return False

    # if we are following then unfollow
    following_filename = \
        acct_dir(base_dir, nickname, domain) + '/following.txt'
    if os.path.isfile(following_filename):
        if text_in_file(block_handle + '\n', following_filename):
            following_str = ''
            try:
                with open(following_filename, 'r',
                          encoding='utf-8') as fp_foll:
                    following_str = fp_foll.read()
            except OSError:
                print('EX: Unable to read following ' + following_filename)
                return False

            if following_str:
                following_str = following_str.replace(block_handle + '\n', '')

            try:
                with open(following_filename, 'w+',
                          encoding='utf-8') as fp_foll:
                    fp_foll.write(following_str)
            except OSError:
                print('EX: Unable to write following ' + following_str)
                return False

    # if they are a follower then remove them
    followers_filename = \
        acct_dir(base_dir, nickname, domain) + '/followers.txt'
    if os.path.isfile(followers_filename):
        if text_in_file(block_handle + '\n', followers_filename):
            followers_str = ''
            try:
                with open(followers_filename, 'r',
                          encoding='utf-8') as fp_foll:
                    followers_str = fp_foll.read()
            except OSError:
                print('EX: Unable to read followers ' + followers_filename)
                return False

            if followers_str:
                followers_str = followers_str.replace(block_handle + '\n', '')

            try:
                with open(followers_filename, 'w+',
                          encoding='utf-8') as fp_foll:
                    fp_foll.write(followers_str)
            except OSError:
                print('EX: Unable to write followers ' + followers_str)
                return False

    try:
        with open(blocking_filename, 'a+', encoding='utf-8') as fp_block:
            fp_block.write(block_handle + '\n')
    except OSError:
        print('EX: unable to append block handle ' + block_handle)
        return False

    if reason:
        _add_block_reason(base_dir, nickname, domain,
                          block_nickname, block_domain, reason)

    return True


def _remove_global_block_reason(base_dir: str,
                                unblock_nickname: str,
                                unblock_domain: str) -> bool:
    """Remove a globla block reason
    """
    unblocking_filename = data_dir(base_dir) + '/blocking_reasons.txt'
    if not os.path.isfile(unblocking_filename):
        return False

    if not unblock_nickname.startswith('#'):
        unblock_id = unblock_nickname + '@' + unblock_domain
    else:
        unblock_id = unblock_nickname

    if not text_in_file(unblock_id + ' ', unblocking_filename):
        return False

    reasons_str = ''
    try:
        with open(unblocking_filename, 'r',
                  encoding='utf-8') as fp_reas:
            reasons_str = fp_reas.read()
    except OSError:
        print('EX: unable to read blocking reasons 3')
    reasons_lines = reasons_str.split('\n')
    new_reasons_str = ''
    for line in reasons_lines:
        if line.startswith(unblock_id + ' '):
            continue
        new_reasons_str += line + '\n'
    try:
        with open(unblocking_filename, 'w+',
                  encoding='utf-8') as fp_reas:
            fp_reas.write(new_reasons_str)
    except OSError:
        print('EX: unable to save blocking reasons 2' +
              unblocking_filename)
    return True


def remove_global_block(base_dir: str,
                        unblock_nickname: str,
                        unblock_domain: str) -> bool:
    """Unblock the given global block
    """
    _remove_global_block_reason(base_dir,
                                unblock_nickname,
                                unblock_domain)

    unblocking_filename = data_dir(base_dir) + '/blocking.txt'
    if not unblock_nickname.startswith('#'):
        unblock_handle = unblock_nickname + '@' + unblock_domain
        if os.path.isfile(unblocking_filename):
            if text_in_file(unblock_handle, unblocking_filename):
                try:
                    with open(unblocking_filename, 'r',
                              encoding='utf-8') as fp_unblock:
                        with open(unblocking_filename + '.new', 'w+',
                                  encoding='utf-8') as fpnew:
                            for line in fp_unblock:
                                handle = remove_eol(line)
                                if unblock_handle not in line:
                                    fpnew.write(handle + '\n')
                except OSError as ex:
                    print('EX: failed to remove global block ' +
                          unblocking_filename + ' ' + str(ex))
                    return False

                if os.path.isfile(unblocking_filename + '.new'):
                    try:
                        os.rename(unblocking_filename + '.new',
                                  unblocking_filename)
                    except OSError:
                        print('EX: remove_global_block unable to rename ' +
                              unblocking_filename)
                        return False
                    return True
    else:
        unblock_hashtag = unblock_nickname
        if os.path.isfile(unblocking_filename):
            if text_in_file(unblock_hashtag + '\n', unblocking_filename):
                try:
                    with open(unblocking_filename, 'r',
                              encoding='utf-8') as fp_unblock:
                        with open(unblocking_filename + '.new', 'w+',
                                  encoding='utf-8') as fpnew:
                            for line in fp_unblock:
                                block_line = remove_eol(line)
                                if unblock_hashtag not in line:
                                    fpnew.write(block_line + '\n')
                except OSError as ex:
                    print('EX: failed to remove global hashtag block ' +
                          unblocking_filename + ' ' + str(ex))
                    return False

                if os.path.isfile(unblocking_filename + '.new'):
                    try:
                        os.rename(unblocking_filename + '.new',
                                  unblocking_filename)
                    except OSError:
                        print('EX: remove_global_block unable to rename 2 ' +
                              unblocking_filename)
                        return False
                    return True
    return False


def remove_block(base_dir: str, nickname: str, domain: str,
                 unblock_nickname: str, unblock_domain: str) -> bool:
    """Unblock the given account
    """
    domain = remove_domain_port(domain)
    unblocking_filename = \
        acct_dir(base_dir, nickname, domain) + '/blocking.txt'
    unblock_handle = unblock_nickname + '@' + unblock_domain
    if os.path.isfile(unblocking_filename):
        if text_in_file(unblock_handle, unblocking_filename):
            try:
                with open(unblocking_filename, 'r',
                          encoding='utf-8') as fp_unblock:
                    with open(unblocking_filename + '.new', 'w+',
                              encoding='utf-8') as fpnew:
                        for line in fp_unblock:
                            handle = remove_eol(line)
                            if unblock_handle not in line:
                                fpnew.write(handle + '\n')
            except OSError as ex:
                print('EX: failed to remove block ' +
                      unblocking_filename + ' ' + str(ex))
                return False

            if os.path.isfile(unblocking_filename + '.new'):
                try:
                    os.rename(unblocking_filename + '.new',
                              unblocking_filename)
                except OSError:
                    print('EX: remove_block unable to rename 3 ' +
                          unblocking_filename)
                    return False
                return True
    return False


def is_blocked_hashtag(base_dir: str, hashtag: str) -> bool:
    """Is the given hashtag blocked?
    """
    # avoid very long hashtags
    if len(hashtag) > 32:
        return True
    global_blocking_filename = data_dir(base_dir) + '/blocking.txt'
    if os.path.isfile(global_blocking_filename):
        hashtag = hashtag.strip('\n').strip('\r')
        if not hashtag.startswith('#'):
            hashtag = '#' + hashtag
        if text_in_file(hashtag + '\n', global_blocking_filename):
            return True
    return False


def get_domain_blocklist(base_dir: str) -> str:
    """Returns all globally blocked domains as a string
    This can be used for fast matching to mitigate flooding
    """
    blocked_str = ''

    evil_domains = evil_incarnate()
    for evil in evil_domains:
        blocked_str += evil + '\n'

    global_blocking_filename = data_dir(base_dir) + '/blocking.txt'
    if not os.path.isfile(global_blocking_filename):
        return blocked_str
    try:
        with open(global_blocking_filename, 'r',
                  encoding='utf-8') as fp_blocked:
            blocked_str += fp_blocked.read()
    except OSError:
        print('EX: get_domain_blocklist unable to read ' +
              global_blocking_filename)
    return blocked_str


def update_blocked_cache(base_dir: str,
                         blocked_cache: [],
                         blocked_cache_last_updated: int,
                         blocked_cache_update_secs: int) -> int:
    """Updates the cache of globally blocked domains held in memory
    """
    curr_time = int(time.time())
    if blocked_cache_last_updated > curr_time:
        print('WARN: Cache updated in the future')
        blocked_cache_last_updated = 0
    seconds_since_last_update = curr_time - blocked_cache_last_updated
    if seconds_since_last_update < blocked_cache_update_secs:
        return blocked_cache_last_updated
    global_blocking_filename = data_dir(base_dir) + '/blocking.txt'
    if not os.path.isfile(global_blocking_filename):
        return blocked_cache_last_updated
    try:
        with open(global_blocking_filename, 'r',
                  encoding='utf-8') as fp_blocked:
            blocked_lines = fp_blocked.readlines()
            # remove newlines
            for index, _ in enumerate(blocked_lines):
                blocked_lines[index] = remove_eol(blocked_lines[index])
            # update the cache
            blocked_cache.clear()
            blocked_cache += blocked_lines
    except OSError as ex:
        print('EX: update_blocked_cache unable to read ' +
              global_blocking_filename + ' ' + str(ex))
    return curr_time


def _get_short_domain(domain: str) -> str:
    """ by checking a shorter version we can thwart adversaries
    who constantly change their subdomain
    e.g. subdomain123.mydomain.com becomes mydomain.com
    """
    sections = domain.split('.')
    no_of_sections = len(sections)
    if no_of_sections > 2:
        return sections[no_of_sections-2] + '.' + sections[-1]
    return None


def is_blocked_domain(base_dir: str, domain: str,
                      blocked_cache: [],
                      block_federated: []) -> bool:
    """Is the given domain blocked?
    """
    if '.' not in domain:
        return False

    if is_evil(domain):
        return True

    short_domain = _get_short_domain(domain)

    search_str = '*@' + domain
    if not broch_mode_is_active(base_dir):
        if block_federated:
            if domain in block_federated:
                return True

        if blocked_cache:
            for blocked_str in blocked_cache:
                if blocked_str == search_str:
                    return True
                if short_domain:
                    if blocked_str == '*@' + short_domain:
                        return True
        else:
            # instance block list
            global_blocking_filename = data_dir(base_dir) + '/blocking.txt'
            if os.path.isfile(global_blocking_filename):
                search_str += '\n'
                search_str_short = None
                if short_domain:
                    search_str_short = '*@' + short_domain + '\n'
                try:
                    with open(global_blocking_filename, 'r',
                              encoding='utf-8') as fp_blocked:
                        blocked_str = fp_blocked.read()
                        if search_str in blocked_str:
                            return True
                        if short_domain:
                            if search_str_short in blocked_str:
                                return True
                except OSError as ex:
                    print('EX: is_blocked_domain unable to read ' +
                          global_blocking_filename + ' ' + str(ex))
    else:
        allow_filename = data_dir(base_dir) + '/allowedinstances.txt'
        # instance allow list
        if not short_domain:
            if not text_in_file(domain, allow_filename):
                return True
        else:
            if not text_in_file(short_domain, allow_filename):
                return True

    return False


def is_blocked_nickname(base_dir: str, nickname: str,
                        blocked_cache: [] = None) -> bool:
    """Is the given nickname blocked?
    """
    search_str = nickname + '@*'
    if blocked_cache:
        for blocked_str in blocked_cache:
            if blocked_str == search_str:
                return True
    else:
        # instance-wide block list
        global_blocking_filename = data_dir(base_dir) + '/blocking.txt'
        if os.path.isfile(global_blocking_filename):
            search_str += '\n'
            try:
                with open(global_blocking_filename, 'r',
                          encoding='utf-8') as fp_blocked:
                    blocked_str = fp_blocked.read()
                    if search_str in blocked_str:
                        return True
            except OSError as ex:
                print('EX: is_blocked_nickname unable to read ' +
                      global_blocking_filename + ' ' + str(ex))

    return False


def is_blocked(base_dir: str, nickname: str, domain: str,
               block_nickname: str, block_domain: str,
               blocked_cache: [],
               block_federated: []) -> bool:
    """Is the given account blocked?
    """
    if is_evil(block_domain):
        return True

    block_handle = None
    if block_nickname and block_domain:
        block_handle = block_nickname + '@' + block_domain

    if not broch_mode_is_active(base_dir):
        # instance level block list
        if block_federated:
            for blocked_str in block_federated:
                if '@' in blocked_str or '://' in blocked_str:
                    if block_handle:
                        if blocked_str == block_handle:
                            return True
                elif blocked_str == block_domain:
                    return True

        if blocked_cache:
            for blocked_str in blocked_cache:
                if block_nickname:
                    if block_nickname + '@*' in blocked_str:
                        return True
                if block_domain:
                    if '*@' + block_domain in blocked_str:
                        return True
                if block_handle:
                    if blocked_str == block_handle:
                        return True
        else:
            global_blocks_filename = data_dir(base_dir) + '/blocking.txt'
            if os.path.isfile(global_blocks_filename):
                if block_nickname:
                    if text_in_file(block_nickname + '@*\n',
                                    global_blocks_filename):
                        return True
                if text_in_file('*@' + block_domain, global_blocks_filename):
                    return True
                if block_handle:
                    block_str = block_handle + '\n'
                    if text_in_file(block_str, global_blocks_filename):
                        return True
            if not block_federated:
                federated_blocks_filename = \
                    data_dir(base_dir) + '/block_api.txt'
                if os.path.isfile(federated_blocks_filename):
                    block_federated: list[str] = []
                    try:
                        with open(federated_blocks_filename, 'r',
                                  encoding='utf-8') as fp_fed:
                            block_federated = fp_fed.read().split('\n')
                    except OSError:
                        print('EX: is_blocked unable to load ' +
                              federated_blocks_filename)
                    if block_domain in block_federated:
                        return True
                    if block_handle:
                        if block_handle in block_federated:
                            return True
    else:
        # instance allow list
        allow_filename = data_dir(base_dir) + '/allowedinstances.txt'
        short_domain = _get_short_domain(block_domain)
        if not short_domain and block_domain:
            if not text_in_file(block_domain + '\n', allow_filename):
                return True
        else:
            if not text_in_file(short_domain + '\n', allow_filename):
                return True

    # account level allow list
    account_dir = acct_dir(base_dir, nickname, domain)
    allow_filename = account_dir + '/allowedinstances.txt'
    if block_domain and os.path.isfile(allow_filename):
        if not text_in_file(block_domain + '\n', allow_filename):
            return True

    # account level block list
    blocking_filename = account_dir + '/blocking.txt'
    if os.path.isfile(blocking_filename):
        if block_nickname:
            if text_in_file(block_nickname + '@*\n', blocking_filename):
                return True
        if block_domain:
            if text_in_file('*@' + block_domain + '\n', blocking_filename):
                return True
        if block_handle:
            if text_in_file(block_handle + '\n', blocking_filename):
                return True
    return False


def allowed_announce(base_dir: str, nickname: str, domain: str,
                     block_nickname: str, block_domain: str,
                     announce_blocked_cache: [] = None) -> bool:
    """Is the given nickname allowed to send announces?
    """
    block_handle = None
    if block_nickname and block_domain:
        block_handle = block_nickname + '@' + block_domain

    # cached announce blocks
    if announce_blocked_cache:
        for blocked_str in announce_blocked_cache:
            if block_nickname:
                if block_nickname + '@*' in blocked_str:
                    return False
            if block_domain:
                if '*@' + block_domain in blocked_str:
                    return False
            if block_handle:
                if blocked_str == block_handle:
                    return False

    # non-cached instance level announce blocks
    global_announce_blocks_filename = \
        data_dir(base_dir) + '/noannounce.txt'
    if os.path.isfile(global_announce_blocks_filename):
        if block_nickname:
            if text_in_file(block_nickname + '@*',
                            global_announce_blocks_filename, False):
                return False
        if block_domain:
            if text_in_file('*@' + block_domain,
                            global_announce_blocks_filename, False):
                return False
        if block_handle:
            block_str = block_handle + '\n'
            if text_in_file(block_str,
                            global_announce_blocks_filename, False):
                return False

    # non-cached account level announce blocks
    account_dir = acct_dir(base_dir, nickname, domain)
    blocking_filename = account_dir + '/noannounce.txt'
    if os.path.isfile(blocking_filename):
        if block_nickname:
            if text_in_file(block_nickname + '@*\n',
                            blocking_filename, False):
                return False
        if block_domain:
            if text_in_file('*@' + block_domain + '\n',
                            blocking_filename, False):
                return False
        if block_handle:
            if text_in_file(block_handle + '\n', blocking_filename, False):
                return False
    return True


def allowed_announce_add(base_dir: str, nickname: str, domain: str,
                         following_nickname: str,
                         following_domain: str) -> None:
    """Allow announces for a handle
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    blocking_filename = account_dir + '/noannounce.txt'

    # if the noannounce.txt file doesn't yet exist
    if not os.path.isfile(blocking_filename):
        return

    handle = following_nickname + '@' + following_domain
    if text_in_file(handle + '\n', blocking_filename, False):
        file_text = ''
        try:
            with open(blocking_filename, 'r',
                      encoding='utf-8') as fp_noannounce:
                file_text = fp_noannounce.read()
        except OSError:
            print('EX: unable to read noannounce add: ' +
                  blocking_filename + ' ' + handle)

        new_file_text = ''
        file_text_list = file_text.split('\n')
        handle_lower = handle.lower()
        for allowed in file_text_list:
            if allowed.lower() != handle_lower:
                new_file_text += allowed + '\n'
        file_text = new_file_text

        try:
            with open(blocking_filename, 'w+',
                      encoding='utf-8') as fp_noannounce:
                fp_noannounce.write(file_text)
        except OSError:
            print('EX: unable to write noannounce add: ' +
                  blocking_filename + ' ' + handle)


def allowed_announce_remove(base_dir: str, nickname: str, domain: str,
                            following_nickname: str,
                            following_domain: str) -> None:
    """Don't allow announces from a handle
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    blocking_filename = account_dir + '/noannounce.txt'
    handle = following_nickname + '@' + following_domain

    # if the noannounce.txt file doesn't yet exist
    if not os.path.isfile(blocking_filename):
        file_text = handle + '\n'
        try:
            with open(blocking_filename, 'w+',
                      encoding='utf-8') as fp_noannounce:
                fp_noannounce.write(file_text)
        except OSError:
            print('EX: unable to write initial noannounce remove: ' +
                  blocking_filename + ' ' + handle)
        return

    file_text = ''
    if not text_in_file(handle + '\n', blocking_filename, False):
        try:
            with open(blocking_filename, 'r',
                      encoding='utf-8') as fp_noannounce:
                file_text = fp_noannounce.read()
        except OSError:
            print('EX: unable to read noannounce remove: ' +
                  blocking_filename + ' ' + handle)
        file_text += handle + '\n'
        try:
            with open(blocking_filename, 'w+',
                      encoding='utf-8') as fp_noannounce:
                fp_noannounce.write(file_text)
        except OSError:
            print('EX: unable to write noannounce: ' +
                  blocking_filename + ' ' + handle)


def blocked_quote_toots_add(base_dir: str, nickname: str, domain: str,
                            following_nickname: str,
                            following_domain: str) -> None:
    """Block quote toots for a handle
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    blocking_filename = account_dir + '/quotesblocked.txt'

    # if the quotesblocked.txt file doesn't yet exist
    if os.path.isfile(blocking_filename):
        return

    handle = following_nickname + '@' + following_domain
    if not text_in_file(handle + '\n', blocking_filename, False):
        file_text = ''
        try:
            with open(blocking_filename, 'r',
                      encoding='utf-8') as fp_quotes:
                file_text = fp_quotes.read()
        except OSError:
            print('EX: unable to read quotesblocked add: ' +
                  blocking_filename + ' ' + handle)
        file_text += handle + '\n'

        try:
            with open(blocking_filename, 'w+',
                      encoding='utf-8') as fp_quotes:
                fp_quotes.write(file_text)
        except OSError:
            print('EX: unable to write quotesblocked add: ' +
                  blocking_filename + ' ' + handle)


def blocked_quote_toots_remove(base_dir: str, nickname: str, domain: str,
                               following_nickname: str,
                               following_domain: str) -> None:
    """allow quote toots from a handle
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    blocking_filename = account_dir + '/quotesblocked.txt'
    handle = following_nickname + '@' + following_domain

    # if the quotesblocked.txt file doesn't yet exist
    if not os.path.isfile(blocking_filename):
        return

    file_text = ''
    if text_in_file(handle + '\n', blocking_filename, False):
        try:
            with open(blocking_filename, 'r',
                      encoding='utf-8') as fp_quotes:
                file_text = fp_quotes.read()
        except OSError:
            print('EX: unable to read quotesblocked remove: ' +
                  blocking_filename + ' ' + handle)
        file_text = file_text.replace(handle + '\n', '')
        try:
            with open(blocking_filename, 'w+',
                      encoding='utf-8') as fp_quotes:
                fp_quotes.write(file_text)
        except OSError:
            print('EX: unable to write quotesblocked remove: ' +
                  blocking_filename + ' ' + handle)


def outbox_block(base_dir: str, nickname: str, domain: str,
                 message_json: {}, debug: bool) -> bool:
    """ When a block request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        if debug:
            print('DEBUG: block - no type')
        return False
    if not message_json['type'] == 'Block':
        if debug:
            print('DEBUG: not a block')
        return False
    if not has_object_string(message_json, debug):
        return False
    if debug:
        print('DEBUG: c2s block request arrived in outbox')

    message_id = remove_id_ending(message_json['object'])
    if '/statuses/' not in message_id:
        if debug:
            print('DEBUG: c2s block object is not a status')
        return False
    if not has_users_path(message_id):
        if debug:
            print('DEBUG: c2s block object has no nickname')
        return False
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s block post not found in inbox or outbox')
            print(message_id)
        return False
    nickname_blocked = get_nickname_from_actor(message_json['object'])
    if not nickname_blocked:
        print('WARN: outbox_block unable to find nickname in ' +
              message_json['object'])
        return False
    domain_blocked, port_blocked = \
        get_domain_from_actor(message_json['object'])
    if not domain_blocked:
        print('WARN: outbox_block unable to find domain in ' +
              message_json['object'])
        return False
    domain_blocked_full = get_full_domain(domain_blocked, port_blocked)

    add_block(base_dir, nickname, domain,
              nickname_blocked, domain_blocked_full, '')

    if debug:
        print('DEBUG: post blocked via c2s - ' + post_filename)
    return True


def outbox_undo_block(base_dir: str, nickname: str, domain: str,
                      message_json: {}, debug: bool) -> None:
    """ When an undo block request is received by the outbox from c2s
    """
    if not message_json.get('type'):
        if debug:
            print('DEBUG: undo block - no type')
        return
    if not message_json['type'] == 'Undo':
        if debug:
            print('DEBUG: not an undo block')
        return

    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'Block':
        if debug:
            print('DEBUG: not an undo block')
        return
    if not has_object_string_object(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s undo block request arrived in outbox')

    message_id = remove_id_ending(message_json['object']['object'])
    if '/statuses/' not in message_id:
        if debug:
            print('DEBUG: c2s undo block object is not a status')
        return
    if not has_users_path(message_id):
        if debug:
            print('DEBUG: c2s undo block object has no nickname')
        return
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s undo block post not found in inbox or outbox')
            print(message_id)
        return
    nickname_blocked = \
        get_nickname_from_actor(message_json['object']['object'])
    if not nickname_blocked:
        print('WARN: outbox_undo_block unable to find nickname in ' +
              message_json['object']['object'])
        return
    domain_object = message_json['object']['object']
    domain_blocked, port_blocked = get_domain_from_actor(domain_object)
    if not domain_blocked:
        print('WARN: outbox_undo_block unable to find domain in ' +
              message_json['object']['object'])
        return
    domain_blocked_full = get_full_domain(domain_blocked, port_blocked)

    remove_block(base_dir, nickname, domain,
                 nickname_blocked, domain_blocked_full)
    if debug:
        print('DEBUG: post undo blocked via c2s - ' + post_filename)


def mute_post(base_dir: str, nickname: str, domain: str, port: int,
              http_prefix: str, post_id: str, recent_posts_cache: {},
              debug: bool) -> None:
    """ Mutes the given post
    """
    print('mute_post: post_id ' + post_id)
    post_filename = locate_post(base_dir, nickname, domain, post_id)
    if not post_filename:
        print('mute_post: file not found ' + post_id)
        return
    post_json_object = load_json(post_filename)
    if not post_json_object:
        print('mute_post: object not loaded ' + post_id)
        return
    print('mute_post: ' + str(post_json_object))

    post_json_obj = post_json_object
    also_update_post_id = None
    if has_object_dict(post_json_object):
        post_json_obj = post_json_object['object']
    else:
        if has_object_string(post_json_object, debug):
            also_update_post_id = remove_id_ending(post_json_object['object'])
        elif is_quote_toot(post_json_object, ''):
            also_update_post_id = get_quote_toot_url(post_json_object)
            also_update_post_id = remove_id_ending(also_update_post_id)

    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)

    # Due to lack of AP specification maintenance, a conversation can also be
    # referred to as a thread or (confusingly) "context"
    if post_json_obj.get('conversation'):
        mute_conversation(base_dir, nickname, domain,
                          post_json_obj['conversation'])
    elif post_json_obj.get('context'):
        mute_conversation(base_dir, nickname, domain,
                          post_json_obj['context'])
    elif post_json_obj.get('thread'):
        mute_conversation(base_dir, nickname, domain,
                          post_json_obj['thread'])

    # does this post have ignores on it from differenent actors?
    if not post_json_obj.get('ignores'):
        if debug:
            print('DEBUG: Adding initial mute to ' + post_id)
        ignores_json = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'id': post_id,
            'type': 'Collection',
            "totalItems": 1,
            'items': [{
                'type': 'Ignore',
                'actor': actor
            }]
        }
        post_json_obj['ignores'] = ignores_json
    else:
        if not post_json_obj['ignores'].get('items'):
            post_json_obj['ignores']['items']: list[dict] = []
        items_list = post_json_obj['ignores']['items']
        for ignores_item in items_list:
            if ignores_item.get('actor'):
                if ignores_item['actor'] == actor:
                    return
        new_ignore = {
            'type': 'Ignore',
            'actor': actor
        }
        ig_it = len(items_list)
        items_list.append(new_ignore)
        post_json_obj['ignores']['totalItems'] = ig_it
    post_json_obj['muted'] = True
    if save_json(post_json_object, post_filename):
        print('mute_post: saved ' + post_filename)

    # remove cached post so that the muted version gets recreated
    # without its content text and/or image
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, post_json_object)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
                print('MUTE: cached post removed ' + cached_post_filename)
            except OSError:
                print('EX: MUTE cached post not removed ' +
                      cached_post_filename)
        else:
            print('MUTE: cached post not found ' + cached_post_filename)

    try:
        with open(post_filename + '.muted', 'w+',
                  encoding='utf-8') as fp_mute:
            fp_mute.write('\n')
    except OSError:
        print('EX: Failed to save mute file ' + post_filename + '.muted')
        return
    print('MUTE: ' + post_filename + '.muted file added')

    # if the post is in the recent posts cache then mark it as muted
    if recent_posts_cache.get('index'):
        post_id = \
            remove_id_ending(post_json_object['id']).replace('/', '#')
        if post_id in recent_posts_cache['index']:
            print('MUTE: ' + post_id + ' is in recent posts cache')
        if recent_posts_cache.get('json'):
            recent_posts_cache['json'][post_id] = json.dumps(post_json_object)
            print('MUTE: ' + post_id +
                  ' marked as muted in recent posts memory cache')
        if recent_posts_cache.get('html'):
            if recent_posts_cache['html'].get(post_id):
                del recent_posts_cache['html'][post_id]
                print('MUTE: ' + post_id + ' removed cached html')

    if also_update_post_id:
        post_filename = locate_post(base_dir, nickname, domain,
                                    also_update_post_id)
        if post_filename:
            if os.path.isfile(post_filename):
                post_json_obj = load_json(post_filename)
                cached_post_filename = \
                    get_cached_post_filename(base_dir, nickname, domain,
                                             post_json_obj)
                if cached_post_filename:
                    if os.path.isfile(cached_post_filename):
                        try:
                            os.remove(cached_post_filename)
                            print('MUTE: cached referenced post removed ' +
                                  cached_post_filename)
                        except OSError:
                            print('EX: ' +
                                  'MUTE cached referenced post not removed ' +
                                  cached_post_filename)

        if recent_posts_cache.get('json'):
            if recent_posts_cache['json'].get(also_update_post_id):
                del recent_posts_cache['json'][also_update_post_id]
                print('MUTE: ' + also_update_post_id +
                      ' removed referenced json')
        if recent_posts_cache.get('html'):
            if recent_posts_cache['html'].get(also_update_post_id):
                del recent_posts_cache['html'][also_update_post_id]
                print('MUTE: ' + also_update_post_id +
                      ' removed referenced html')


def unmute_post(base_dir: str, nickname: str, domain: str, port: int,
                http_prefix: str, post_id: str, recent_posts_cache: {},
                debug: bool) -> None:
    """ Unmutes the given post
    """
    post_filename = locate_post(base_dir, nickname, domain, post_id)
    if not post_filename:
        return
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return

    mute_filename = post_filename + '.muted'
    if os.path.isfile(mute_filename):
        try:
            os.remove(mute_filename)
        except OSError:
            if debug:
                print('EX: unmute_post mute filename not deleted ' +
                      str(mute_filename))
        print('UNMUTE: ' + mute_filename + ' file removed')

    post_json_obj = post_json_object
    also_update_post_id = None
    if has_object_dict(post_json_object):
        post_json_obj = post_json_object['object']
    else:
        if has_object_string(post_json_object, debug):
            also_update_post_id = remove_id_ending(post_json_object['object'])
        elif is_quote_toot(post_json_object, ''):
            also_update_post_id = get_quote_toot_url(post_json_object)
            also_update_post_id = remove_id_ending(also_update_post_id)

    # Due to lack of AP specification maintenance, a conversation can also be
    # referred to as a thread or (confusingly) "context"
    if post_json_obj.get('conversation'):
        unmute_conversation(base_dir, nickname, domain,
                            post_json_obj['conversation'])
    elif post_json_obj.get('context'):
        unmute_conversation(base_dir, nickname, domain,
                            post_json_obj['context'])
    elif post_json_obj.get('thread'):
        unmute_conversation(base_dir, nickname, domain,
                            post_json_obj['thread'])

    if post_json_obj.get('ignores'):
        domain_full = get_full_domain(domain, port)
        actor = local_actor_url(http_prefix, nickname, domain_full)
        total_items = 0
        if post_json_obj['ignores'].get('totalItems'):
            total_items = post_json_obj['ignores']['totalItems']
        items_list = post_json_obj['ignores']['items']
        for ignores_item in items_list:
            if ignores_item.get('actor'):
                if ignores_item['actor'] == actor:
                    if debug:
                        print('DEBUG: mute was removed for ' + actor)
                    items_list.remove(ignores_item)
                    break
        if total_items == 1:
            if debug:
                print('DEBUG: mute was removed from post')
            del post_json_obj['ignores']
        else:
            ig_it_len = len(post_json_obj['ignores']['items'])
            post_json_obj['ignores']['totalItems'] = ig_it_len
    post_json_obj['muted'] = False
    save_json(post_json_object, post_filename)

    # remove cached post so that the muted version gets recreated
    # with its content text and/or image
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, post_json_object)
    if cached_post_filename:
        if os.path.isfile(cached_post_filename):
            try:
                os.remove(cached_post_filename)
            except OSError:
                if debug:
                    print('EX: unmute_post cached post not deleted ' +
                          str(cached_post_filename))

    # if the post is in the recent posts cache then mark it as unmuted
    if recent_posts_cache.get('index'):
        post_id = \
            remove_id_ending(post_json_object['id']).replace('/', '#')
        if post_id in recent_posts_cache['index']:
            print('UNMUTE: ' + post_id + ' is in recent posts cache')
        if recent_posts_cache.get('json'):
            recent_posts_cache['json'][post_id] = json.dumps(post_json_object)
            print('UNMUTE: ' + post_id +
                  ' marked as unmuted in recent posts cache')
        if recent_posts_cache.get('html'):
            if recent_posts_cache['html'].get(post_id):
                del recent_posts_cache['html'][post_id]
                print('UNMUTE: ' + post_id + ' removed cached html')
    if also_update_post_id:
        post_filename = locate_post(base_dir, nickname, domain,
                                    also_update_post_id)
        if os.path.isfile(post_filename):
            post_json_obj = load_json(post_filename)
            cached_post_filename = \
                get_cached_post_filename(base_dir, nickname, domain,
                                         post_json_obj)
            if cached_post_filename:
                if os.path.isfile(cached_post_filename):
                    try:
                        os.remove(cached_post_filename)
                        print('MUTE: cached referenced post removed ' +
                              cached_post_filename)
                    except OSError:
                        if debug:
                            print('EX: ' +
                                  'unmute_post cached ref post not removed ' +
                                  str(cached_post_filename))

        if recent_posts_cache.get('json'):
            if recent_posts_cache['json'].get(also_update_post_id):
                del recent_posts_cache['json'][also_update_post_id]
                print('UNMUTE: ' +
                      also_update_post_id + ' removed referenced json')
        if recent_posts_cache.get('html'):
            if recent_posts_cache['html'].get(also_update_post_id):
                del recent_posts_cache['html'][also_update_post_id]
                print('UNMUTE: ' +
                      also_update_post_id + ' removed referenced html')


def outbox_mute(base_dir: str, http_prefix: str,
                nickname: str, domain: str, port: int,
                message_json: {}, debug: bool,
                recent_posts_cache: {}) -> None:
    """When a mute is received by the outbox from c2s
    """
    if not message_json.get('type'):
        return
    if not has_actor(message_json, debug):
        return
    domain_full = get_full_domain(domain, port)
    actor_url = get_actor_from_post(message_json)

    actor_found = False
    users_paths = get_user_paths()
    for possible_path in users_paths:
        if actor_url.endswith(domain_full + possible_path + nickname):
            actor_found = True
            break

    if not actor_found:
        return
    if not message_json['type'] == 'Ignore':
        return
    if not has_object_string(message_json, debug):
        return
    if debug:
        print('DEBUG: c2s mute request arrived in outbox')

    message_id = remove_id_ending(message_json['object'])
    if not contains_statuses(message_id):
        if debug:
            print('DEBUG: c2s mute object is not a status')
        return
    if not has_users_path(message_id):
        if debug:
            print('DEBUG: c2s mute object has no nickname')
        return
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s mute post not found in inbox or outbox')
            print(message_id)
        return
    nickname_muted = get_nickname_from_actor(message_json['object'])
    if not nickname_muted:
        print('WARN: outbox_mute unable to find nickname in ' +
              message_json['object'])
        return

    mute_post(base_dir, nickname, domain, port,
              http_prefix, message_json['object'], recent_posts_cache,
              debug)

    if debug:
        print('DEBUG: post muted via c2s - ' + post_filename)


def outbox_undo_mute(base_dir: str, http_prefix: str,
                     nickname: str, domain: str, port: int,
                     message_json: {}, debug: bool,
                     recent_posts_cache: {}) -> None:
    """When an undo mute is received by the outbox from c2s
    """
    if not message_json.get('type'):
        return
    if not has_actor(message_json, debug):
        return
    domain_full = get_full_domain(domain, port)
    actor_url = get_actor_from_post(message_json)

    actor_found = False
    users_paths = get_user_paths()
    for possible_path in users_paths:
        if actor_url.endswith(domain_full + possible_path + nickname):
            actor_found = True
            break

    if not actor_found:
        return
    if not message_json['type'] == 'Undo':
        return
    if not has_object_string_type(message_json, debug):
        return
    if message_json['object']['type'] != 'Ignore':
        return
    if not isinstance(message_json['object']['object'], str):
        if debug:
            print('DEBUG: undo mute object is not a string')
        return
    if debug:
        print('DEBUG: c2s undo mute request arrived in outbox')

    message_id = remove_id_ending(message_json['object']['object'])
    if not contains_statuses(message_id):
        if debug:
            print('DEBUG: c2s undo mute object is not a status')
        return
    if not has_users_path(message_id):
        if debug:
            print('DEBUG: c2s undo mute object has no nickname')
        return
    domain = remove_domain_port(domain)
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        if debug:
            print('DEBUG: c2s undo mute post not found in inbox or outbox')
            print(message_id)
        return
    nickname_muted = get_nickname_from_actor(message_json['object']['object'])
    if not nickname_muted:
        print('WARN: outbox_undo_mute unable to find nickname in ' +
              message_json['object']['object'])
        return

    unmute_post(base_dir, nickname, domain, port,
                http_prefix, message_json['object']['object'],
                recent_posts_cache, debug)

    if debug:
        print('DEBUG: post undo mute via c2s - ' + post_filename)


def broch_mode_is_active(base_dir: str) -> bool:
    """Returns true if broch mode is active
    """
    allow_filename = data_dir(base_dir) + '/allowedinstances.txt'
    return os.path.isfile(allow_filename)


def set_broch_mode(base_dir: str, domain_full: str, enabled: bool) -> None:
    """Broch mode can be used to lock down the instance during
    a period of time when it is temporarily under attack.
    For example, where an adversary is constantly spinning up new
    instances.
    It surveys the following lists of all accounts and uses that
    to construct an instance level allow list. Anything arriving
    which is then not from one of the allowed domains will be dropped
    """
    allow_filename = data_dir(base_dir) + '/allowedinstances.txt'

    if not enabled:
        # remove instance allow list
        if os.path.isfile(allow_filename):
            try:
                os.remove(allow_filename)
            except OSError:
                print('EX: set_broch_mode allow file not deleted ' +
                      str(allow_filename))
            print('Broch mode turned off')
    else:
        if os.path.isfile(allow_filename):
            last_modified = file_last_modified(allow_filename)
            print('Broch mode already activated ' + last_modified)
            return
        # generate instance allow list
        allowed_domains = [domain_full]
        follow_files = ('following.txt', 'followers.txt')
        dir_str = data_dir(base_dir)
        for _, dirs, _ in os.walk(dir_str):
            for acct in dirs:
                if not is_account_dir(acct):
                    continue
                account_dir = os.path.join(dir_str, acct)
                for follow_file_type in follow_files:
                    following_filename = account_dir + '/' + follow_file_type
                    if not os.path.isfile(following_filename):
                        continue
                    try:
                        with open(following_filename, 'r',
                                  encoding='utf-8') as fp_foll:
                            follow_list = fp_foll.readlines()
                            for handle in follow_list:
                                if '@' not in handle:
                                    continue
                                handle = remove_eol(handle)
                                handle_domain = handle.split('@')[1]
                                if handle_domain not in allowed_domains:
                                    allowed_domains.append(handle_domain)
                    except OSError as ex:
                        print('EX: set_broch_mode failed to read ' +
                              following_filename + ' ' + str(ex))
            break

        # write the allow file
        try:
            with open(allow_filename, 'w+',
                      encoding='utf-8') as fp_allow:
                fp_allow.write(domain_full + '\n')
                for allowed in allowed_domains:
                    fp_allow.write(allowed + '\n')
                print('Broch mode enabled')
        except OSError as ex:
            print('EX: Broch mode not enabled due to file write ' + str(ex))
            return

    set_config_param(base_dir, "brochMode", enabled)


def broch_modeLapses(base_dir: str, lapse_days: int) -> bool:
    """After broch mode is enabled it automatically
    elapses after a period of time
    """
    allow_filename = data_dir(base_dir) + '/allowedinstances.txt'
    if not os.path.isfile(allow_filename):
        return False
    last_modified = file_last_modified(allow_filename)
    modified_date = \
        date_from_string_format(last_modified, ["%Y-%m-%dT%H:%M:%S%z"])
    if not modified_date:
        print('EX: broch_modeLapses date not parsed ' + str(last_modified))
        return False
    curr_time = date_utcnow()
    days_since_broch = (curr_time - modified_date).days
    if days_since_broch >= lapse_days:
        removed = False
        try:
            os.remove(allow_filename)
            removed = True
        except OSError:
            print('EX: broch_modeLapses allow file not deleted ' +
                  str(allow_filename))
        if removed:
            set_config_param(base_dir, "brochMode", False)
            print('Broch mode has elapsed')
            return True
    return False


def import_blocking_file(base_dir: str, nickname: str, domain: str,
                         lines: []) -> bool:
    """Imports blocked domains for a given account
    """
    if not lines:
        return False
    if len(lines) < 2:
        return False
    if not lines[0].startswith('#domain,#') or \
       'comment' not in lines[0]:
        return False
    fieldnames = lines[0].split(',')
    comment_field_index = 0
    for field_str in fieldnames:
        if 'comment' in field_str:
            break
        comment_field_index += 1
    if comment_field_index >= len(fieldnames):
        return False

    account_directory = acct_dir(base_dir, nickname, domain)
    blocking_filename = \
        account_directory + '/blocking.txt'
    blocking_reasons_filename = \
        account_directory + '/blocking_reasons.txt'

    existing_lines: list[str] = []
    if os.path.isfile(blocking_filename):
        try:
            with open(blocking_filename, 'r', encoding='utf-8') as fp_blocks:
                existing_lines = fp_blocks.read().splitlines()
        except OSError:
            print('EX: ' +
                  'unable to import existing blocked instances from file ' +
                  blocking_filename)
    existing_reasons: list[str] = []
    if os.path.isfile(blocking_reasons_filename):
        try:
            with open(blocking_reasons_filename,
                      'r', encoding='utf-8') as fp_blocks:
                existing_reasons = fp_blocks.read().splitlines()
        except OSError:
            print('EX: ' +
                  'unable to import existing ' +
                  'blocked instance reasons from file ' +
                  blocking_reasons_filename)

    append_blocks: list[str] = []
    append_reasons: list[str] = []
    for line_str in lines:
        if line_str.startswith('#'):
            continue
        block_fields = line_str.split(',')
        blocked_domain_name = block_fields[0].strip()
        if ' ' in blocked_domain_name or \
           '.' not in blocked_domain_name:
            continue
        if blocked_domain_name in existing_lines:
            # already blocked
            continue
        append_blocks.append(blocked_domain_name)
        blocked_comment = ''
        if '"' in line_str:
            quote_section = line_str.split('"')
            if len(quote_section) > 1:
                blocked_comment = quote_section[1]
                append_reasons.append(blocked_domain_name + ' ' +
                                      blocked_comment)
        if not blocked_comment:
            if len(block_fields) > comment_field_index:
                blocked_comment = block_fields[comment_field_index].strip()
                if blocked_comment:
                    if blocked_comment.startswith('"'):
                        blocked_comment = blocked_comment.replace('"', '')
                    if blocked_comment not in existing_reasons:
                        append_reasons.append(blocked_domain_name + ' ' +
                                              blocked_comment)
    if not append_blocks:
        return True

    try:
        with open(blocking_filename, 'a+', encoding='utf-8') as fp_blocks:
            for new_block in append_blocks:
                fp_blocks.write(new_block + '\n')
    except OSError:
        print('EX: ' +
              'unable to append imported blocks to ' +
              blocking_filename)

    try:
        with open(blocking_reasons_filename, 'a+',
                  encoding='utf-8') as fp_blocks:
            for new_reason in append_reasons:
                fp_blocks.write(new_reason + '\n')
    except OSError:
        print('EX: ' +
              'unable to append imported block reasons to ' +
              blocking_reasons_filename)

    return True


def export_blocking_file(base_dir: str, nickname: str, domain: str) -> str:
    """exports account level blocks in a csv format
    """
    account_directory = acct_dir(base_dir, nickname, domain)
    blocking_filename = \
        account_directory + '/blocking.txt'
    blocking_reasons_filename = \
        account_directory + '/blocking_reasons.txt'

    blocks_header = \
        '#domain,#severity,#reject_media,#reject_reports,' + \
        '#public_comment,#obfuscate\n'

    if not os.path.isfile(blocking_filename):
        return blocks_header

    blocking_lines: list[str] = []
    if os.path.isfile(blocking_filename):
        try:
            with open(blocking_filename, 'r', encoding='utf-8') as fp_block:
                blocking_lines = fp_block.read().splitlines()
        except OSError:
            print('EX: export_blocks failed to read ' + blocking_filename)

    blocking_reasons: list[str] = []
    if os.path.isfile(blocking_reasons_filename):
        try:
            with open(blocking_reasons_filename, 'r',
                      encoding='utf-8') as fp_block:
                blocking_reasons = fp_block.read().splitlines()
        except OSError:
            print('EX: export_blocks failed to read ' +
                  blocking_reasons_filename)

    blocks_str = blocks_header
    for blocked_domain in blocking_lines:
        blocked_domain = blocked_domain.strip()
        if blocked_domain.startswith('#'):
            continue
        reason_str = ''
        for reason_line in blocking_reasons:
            if reason_line.startswith(blocked_domain + ' '):
                reason_str = reason_line.split(' ', 1)[1]
                break
        blocks_str += \
            blocked_domain + ',suspend,false,false,"' + \
            reason_str + '",false\n'
    return blocks_str


def get_blocks_via_server(session, nickname: str, password: str,
                          domain: str, port: int,
                          http_prefix: str, page_number: int, debug: bool,
                          version: str,
                          signing_priv_key_pem: str,
                          mitm_servers: []) -> {}:
    """Returns the blocked collection for shared items via c2s
    https://codeberg.org/fediverse/fep/src/branch/main/fep/c648/fep-c648.md
    """
    if not session:
        print('WARN: No session for get_blocks_via_server')
        return 6

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header,
        'Accept': 'application/json'
    }
    domain_full = get_full_domain(domain, port)
    url = local_actor_url(http_prefix, nickname, domain_full) + \
        '/blocked?page=' + str(page_number)
    if debug:
        print('Blocked collection request to: ' + url)
    blocked_json = get_json(signing_priv_key_pem, session, url, headers, None,
                            debug, mitm_servers, version, http_prefix, None)
    if not get_json_valid(blocked_json):
        if debug:
            print('DEBUG: GET blocked collection failed for c2s to ' + url)
#        return 5

    if debug:
        print('DEBUG: c2s GET blocked collection success')

    return blocked_json


def load_blocked_military(base_dir: str) -> {}:
    """Loads a list of nicknames for accounts which block military instances
    """
    block_military_filename = data_dir(base_dir) + '/block_military.txt'
    nicknames_list: list[str] = []
    if os.path.isfile(block_military_filename):
        try:
            with open(block_military_filename, 'r',
                      encoding='utf-8') as fp_mil:
                nicknames_list = fp_mil.read()
        except OSError:
            print('EX: error while reading block military file')
    if not nicknames_list:
        return {}
    nicknames_list = nicknames_list.split('\n')
    nicknames_dict = {}
    for nickname in nicknames_list:
        nicknames_dict[nickname] = True
    return nicknames_dict


def load_blocked_government(base_dir: str) -> {}:
    """Loads a list of nicknames for accounts which block government instances
    """
    block_government_filename = data_dir(base_dir) + '/block_government.txt'
    nicknames_list: list[str] = []
    if os.path.isfile(block_government_filename):
        try:
            with open(block_government_filename, 'r',
                      encoding='utf-8') as fp_gov:
                nicknames_list = fp_gov.read()
        except OSError:
            print('EX: error while reading block government file')
    if not nicknames_list:
        return {}
    nicknames_list = nicknames_list.split('\n')
    nicknames_dict = {}
    for nickname in nicknames_list:
        nicknames_dict[nickname] = True
    return nicknames_dict


def load_blocked_bluesky(base_dir: str) -> {}:
    """Loads a list of nicknames for accounts which block bluesky bridges
    """
    block_bluesky_filename = data_dir(base_dir) + '/block_bluesky.txt'
    nicknames_list: list[str] = []
    if os.path.isfile(block_bluesky_filename):
        try:
            with open(block_bluesky_filename, 'r',
                      encoding='utf-8') as fp_bsky:
                nicknames_list = fp_bsky.read()
        except OSError:
            print('EX: error while reading block bluesky file')
    if not nicknames_list:
        return {}
    nicknames_list = nicknames_list.split('\n')
    nicknames_dict = {}
    for nickname in nicknames_list:
        nicknames_dict[nickname] = True
    return nicknames_dict


def load_blocked_nostr(base_dir: str) -> {}:
    """Loads a list of nicknames for accounts which block nostr bridges
    """
    block_nostr_filename = data_dir(base_dir) + '/block_nostr.txt'
    nicknames_list: list[str] = []
    if os.path.isfile(block_nostr_filename):
        try:
            with open(block_nostr_filename, 'r',
                      encoding='utf-8') as fp_nostr:
                nicknames_list = fp_nostr.read()
        except OSError:
            print('EX: error while reading block nostr file')
    if not nicknames_list:
        return {}
    nicknames_list = nicknames_list.split('\n')
    nicknames_dict = {}
    for nickname in nicknames_list:
        nicknames_dict[nickname] = True
    return nicknames_dict


def save_blocked_military(base_dir: str, block_military: {}) -> None:
    """Saves a list of nicknames for accounts which block military instances
    """
    nicknames_str = ''
    for nickname, _ in block_military.items():
        nicknames_str += nickname + '\n'

    block_military_filename = data_dir(base_dir) + '/block_military.txt'
    try:
        with open(block_military_filename, 'w+',
                  encoding='utf-8') as fp_mil:
            fp_mil.write(nicknames_str)
    except OSError:
        print('EX: error while saving block military file')


def save_blocked_government(base_dir: str, block_government: {}) -> None:
    """Saves a list of nicknames for accounts which block government instances
    """
    nicknames_str = ''
    for nickname, _ in block_government.items():
        nicknames_str += nickname + '\n'

    block_government_filename = data_dir(base_dir) + '/block_government.txt'
    try:
        with open(block_government_filename, 'w+',
                  encoding='utf-8') as fp_gov:
            fp_gov.write(nicknames_str)
    except OSError:
        print('EX: error while saving block government file')


def save_blocked_bluesky(base_dir: str, block_bluesky: {}) -> None:
    """Saves a list of nicknames for accounts which block bluesky bridges
    """
    nicknames_str = ''
    for nickname, _ in block_bluesky.items():
        nicknames_str += nickname + '\n'

    block_bluesky_filename = data_dir(base_dir) + '/block_bluesky.txt'
    try:
        with open(block_bluesky_filename, 'w+',
                  encoding='utf-8') as fp_bsky:
            fp_bsky.write(nicknames_str)
    except OSError:
        print('EX: error while saving block bluesky file')


def save_blocked_nostr(base_dir: str, block_nostr: {}) -> None:
    """Saves a list of nicknames for accounts which block nostr bridges
    """
    nicknames_str = ''
    for nickname, _ in block_nostr.items():
        nicknames_str += nickname + '\n'

    block_nostr_filename = data_dir(base_dir) + '/block_nostr.txt'
    try:
        with open(block_nostr_filename, 'w+',
                  encoding='utf-8') as fp_nostr:
            fp_nostr.write(nicknames_str)
    except OSError:
        print('EX: error while saving block nostr file')


def get_mil_domains_list() -> []:
    """returns a list of military domains
    """
    return ('army', 'navy', 'airforce', 'mil',
            'sncorp.com', 'sierranevadacorp.us', 'ncontext.com')


def get_gov_domains_list() -> []:
    """returns a list of government domains
    """
    return ('.gov', '.overheid.nl', '.bund.de')


def get_bsky_domains_list() -> []:
    """returns a list of bluesky bridges
    """
    return ['brid.gy']


def get_nostr_domains_list() -> []:
    """returns a list of nostr bridges
    """
    return ['mostr.pub']


def contains_military_domain(message_str: str) -> bool:
    """Returns true if the given string contains a military domain
    """
    mil_domains = get_mil_domains_list()
    for domain_str in mil_domains:
        if '.' not in domain_str:
            tld = domain_str
            if '.' + tld + '"' in message_str or \
               '.' + tld + '/' in message_str:
                return True
        else:
            if domain_str + '"' in message_str or \
               domain_str + '/' in message_str:
                return True
    return False


def contains_government_domain(message_str: str) -> bool:
    """Returns true if the given string contains a government domain
    """
    if '.gov.' in message_str:
        return True
    gov_domains = get_gov_domains_list()
    for domain_str in gov_domains:
        if domain_str + '"' in message_str or \
           domain_str + '/' in message_str:
            return True
    return False


def contains_bluesky_domain(message_str: str) -> bool:
    """Returns true if the given string contains a bluesky bridge domain
    """
    bsky_domains = get_bsky_domains_list()
    for domain_str in bsky_domains:
        if domain_str + '"' in message_str or \
           domain_str + '/' in message_str:
            return True
    return False


def contains_nostr_domain(message_str: str) -> bool:
    """Returns true if the given string contains a nostr bridge domain
    """
    if '.nostr.' in message_str or \
       '/nostr.' in message_str:
        return True
    nostr_domains = get_nostr_domains_list()
    for domain_str in nostr_domains:
        if domain_str + '"' in message_str or \
           domain_str + '/' in message_str:
            return True
    return False


def load_federated_blocks_endpoints(base_dir: str) -> []:
    """Loads endpoint urls for federated blocklists
    """
    block_federated_endpoints: list[str] = []
    block_api_endpoints_filename = \
        data_dir(base_dir) + '/block_api_endpoints.txt'
    if os.path.isfile(block_api_endpoints_filename):
        new_block_federated_endpoints: list[str] = []
        try:
            with open(block_api_endpoints_filename, 'r',
                      encoding='utf-8') as fp_ep:
                new_block_federated_endpoints = fp_ep.read().split('\n')
        except OSError:
            print('EX: unable to load block_api_endpoints.txt')
        for endpoint in new_block_federated_endpoints:
            if endpoint:
                if '#' not in endpoint:
                    block_federated_endpoints.append(endpoint)
    return block_federated_endpoints


def _valid_federated_blocklist_entry(text: str, domain: str) -> bool:
    """is the given blocklist entry valid?
    """
    if ' ' in text or \
       ',' in text or \
       ';' in text or \
       '.' not in text or \
       '<' in text:
        return False
    if text == domain:
        return False
    if text.endswith('@' + domain) or \
       text.endswith('://' + domain):
        return False
    return True


def _update_federated_blocks(session, base_dir: str,
                             http_prefix: str,
                             domain: str,
                             debug: bool, version: str,
                             signing_priv_key_pem: str,
                             max_api_blocks: int,
                             mitm_servers: []) -> []:
    """Creates block_api.txt
    """
    block_federated: list[str] = []
    debug = True

    if not session:
        print('WARN: federated blocklist ' +
              'no session for update_federated_blocks')
        return block_federated

    headers = {
        'Accept': 'application/json'
    }

    block_federated_endpoints = load_federated_blocks_endpoints(base_dir)
    if debug:
        print('DEBUG: federated blocklist endpoints: ' +
              str(block_federated_endpoints))

    new_block_api_str = ''
    for endpoint in block_federated_endpoints:
        if not endpoint:
            continue
        url = endpoint.strip()

        if debug:
            print('federated blocklist Block API endpoint: ' + url)
        blocked_json = get_json(signing_priv_key_pem, session, url, headers,
                                None, debug, mitm_servers,
                                version, http_prefix, domain)
        if not get_json_valid(blocked_json):
            print('DEBUG: federated blocklist ' +
                  'GET blocked json failed ' + url)
            continue
        if debug:
            print('DEBUG: federated blocklist: ' + str(blocked_json))
        if isinstance(blocked_json, list):
            # ensure that the size of the list does not become a form of denial
            # of service
            if len(blocked_json) < max_api_blocks:
                for block_dict in blocked_json:
                    if isinstance(block_dict, str):
                        # a simple list of strings containing handles
                        # or domains
                        handle = block_dict
                        if handle.startswith('@'):
                            handle = handle[1:]
                        if _valid_federated_blocklist_entry(handle,
                                                            domain):
                            if handle not in new_block_api_str:
                                new_block_api_str += handle + '\n'
                            if handle not in block_federated:
                                block_federated.append(handle)
                        continue

                    if not isinstance(block_dict, dict):
                        continue
                    for block_fieldname in ('username', 'domain'):
                        if not block_dict.get(block_fieldname):
                            continue
                        if not isinstance(block_dict[block_fieldname], str):
                            continue
                        handle = block_dict[block_fieldname]
                        if handle.startswith('@'):
                            handle = handle[1:]
                        if not _valid_federated_blocklist_entry(handle,
                                                                domain):
                            continue
                        if handle not in new_block_api_str:
                            new_block_api_str += handle + '\n'
                        if handle not in block_federated:
                            block_federated.append(handle)

    block_api_filename = \
        data_dir(base_dir) + '/block_api.txt'
    if not new_block_api_str:
        print('DEBUG: federated blocklist not loaded: ' + block_api_filename)
        if os.path.isfile(block_api_filename):
            try:
                os.remove(block_api_filename)
            except OSError:
                print('EX: unable to remove block api: ' + block_api_filename)
    else:
        print('DEBUG: federated blocklist loaded: ' + str(block_federated))
        try:
            with open(block_api_filename, 'w+', encoding='utf-8') as fp_api:
                fp_api.write(new_block_api_str)
        except OSError:
            print('EX: unable to write block_api.txt')

    return block_federated


def save_block_federated_endpoints(base_dir: str,
                                   block_federated_endpoints: []) -> []:
    """Saves a list of blocking API endpoints
    """
    block_api_endpoints_filename = \
        data_dir(base_dir) + '/block_api_endpoints.txt'
    result: list[str] = []
    block_federated_endpoints_str = ''
    for endpoint in block_federated_endpoints:
        if not endpoint:
            continue
        if '.' not in endpoint or \
           string_contains(endpoint, (' ', '<', ',', ';')):
            continue
        if endpoint.startswith('@'):
            endpoint = endpoint[1:]
        if not endpoint:
            continue
        block_federated_endpoints_str += endpoint.strip() + '\n'
        result.append(endpoint)
    if not block_federated_endpoints_str:
        if os.path.isfile(block_api_endpoints_filename):
            try:
                os.remove(block_api_endpoints_filename)
            except OSError:
                print('EX: unable to delete block_api_endpoints.txt')
        block_api_filename = \
            data_dir(base_dir) + '/block_api.txt'
        if os.path.isfile(block_api_filename):
            try:
                os.remove(block_api_filename)
            except OSError:
                print('EX: unable to delete block_api.txt')
    else:
        try:
            with open(block_api_endpoints_filename, 'w+',
                      encoding='utf-8') as fp_api:
                fp_api.write(block_federated_endpoints_str)
        except OSError:
            print('EX: unable to write block_api_endpoints.txt')
    return result


def run_federated_blocks_daemon(base_dir: str, httpd, debug: bool) -> None:
    """Runs the daemon used to update federated blocks
    """
    if debug:
        print('DEBUG: federated blocklist 0')
    seconds_per_hour = 60 * 60
    time.sleep(60)

    session = None
    while True:
        if debug:
            print('DEBUG: federated blocklist 1')
        if httpd.session:
            session = httpd.session
        else:
            session = create_session(httpd.proxy_type)

        if session:
            if debug:
                print('DEBUG: federated blocklist 2')
            httpd.block_federated = \
                _update_federated_blocks(httpd.session, base_dir,
                                         httpd.http_prefix,
                                         httpd.domain,
                                         debug, httpd.project_version,
                                         httpd.signing_priv_key_pem,
                                         httpd.max_api_blocks,
                                         httpd.mitm_servers)
        time.sleep(seconds_per_hour * 6)


def sending_is_blocked2(base_dir: str, nickname: str, domain: str,
                        to_domain: str, to_actor: str) -> bool:
    """is sending to the given actor blocked?
    """
    if not to_domain:
        return False

    send_block_filename = \
        acct_dir(base_dir, nickname, domain) + '/send_blocks.txt'
    if not os.path.isfile(send_block_filename):
        return False

    send_blocked = False
    if text_in_file(to_actor, send_block_filename, False):
        send_blocked = True
    elif text_in_file('://' + to_domain + '\n', send_block_filename, False):
        send_blocked = True

    return send_blocked
