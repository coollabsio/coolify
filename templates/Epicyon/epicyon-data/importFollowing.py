__filename__ = "importFollowing.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
import time
import random
from utils import data_dir
from utils import get_full_domain
from utils import is_account_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from follow import is_following_actor
from follow import send_follow_request
from session import create_session
from session import set_session_for_sender
from threads import begin_thread
from person import set_person_notes


def _establish_import_session(httpd,
                              calling_function: str,
                              curr_session,
                              proxy_type: str):
    """Recreates session if needed
    """
    if curr_session:
        return curr_session
    print('DEBUG: creating new import session during ' + calling_function)
    curr_session = create_session(proxy_type)
    if curr_session:
        set_session_for_sender(httpd, proxy_type, curr_session)
        return curr_session
    print('ERROR: failed to create import session during ' +
          calling_function)
    return None


def _update_import_following(base_dir: str,
                             handle: str, httpd,
                             import_filename: str) -> bool:
    """Send out follow requests from the import csv file
    """
    following_str = ''
    try:
        with open(import_filename, 'r', encoding='utf-8') as fp_import:
            following_str = fp_import.read()
    except OSError:
        print('Ex: failed to load import file ' + import_filename)
        return False
    if following_str:
        main_session = None
        lines = following_str.split('\n')
        random.shuffle(lines)
        print('FOLLOW: ' + handle + ' attempting to follow ' + str(lines))
        nickname = handle.split('@')[0]
        domain = handle.split('@')[1]
        for line in lines:
            if '://' not in line and '@' not in line:
                continue
            if ',' not in line:
                continue
            orig_line = line
            notes = None
            line = line.strip()
            fields = line.split(',')
            line = fields[0].strip()
            if len(fields) >= 5:
                notes = fields[4]
            if line.startswith('#'):
                # comment
                continue
            following_nickname = get_nickname_from_actor(line)
            if not following_nickname:
                continue
            following_domain, following_port = get_domain_from_actor(line)
            if not following_domain:
                continue
            if following_nickname == nickname and \
               following_domain == domain:
                # don't follow yourself
                continue
            following_handle = following_nickname + '@' + following_domain
            following_handle_full = following_nickname + '@' + \
                get_full_domain(following_domain, following_port)
            if notes:
                notes = notes.replace('<br>', '\n')
                set_person_notes(base_dir, nickname, domain,
                                 following_handle, notes)
            if is_following_actor(base_dir, nickname, domain,
                                  following_handle_full):
                # remove the followed handle from the import list
                following_str = following_str.replace(orig_line + '\n', '')
                try:
                    with open(import_filename, 'w+',
                              encoding='utf-8') as fp_import:
                        fp_import.write(following_str)
                except OSError:
                    print('EX: unable to remove import 1 ' + line +
                          ' from ' + import_filename)
                continue

            # send follow request
            curr_domain = domain
            curr_port = httpd.port
            curr_http_prefix = httpd.http_prefix
            following_actor = following_handle

            # get the appropriate session
            curr_session = main_session
            curr_proxy_type = httpd.proxy_type
            use_onion_session = False
            use_i2p_session = False
            if '.onion' not in domain and \
               httpd.onion_domain and '.onion' in following_domain:
                curr_session = httpd.session_onion
                curr_domain = httpd.onion_domain
                curr_port = 80
                following_port = 80
                curr_http_prefix = 'http'
                curr_proxy_type = 'tor'
                use_onion_session = True
            if '.i2p' not in domain and \
               httpd.i2p_domain and '.i2p' in following_domain:
                curr_session = httpd.session_i2p
                curr_domain = httpd.i2p_domain
                curr_port = 80
                following_port = 80
                curr_http_prefix = 'http'
                curr_proxy_type = 'i2p'
                use_i2p_session = True

            curr_session = \
                _establish_import_session(httpd, "import follow",
                                          curr_session, curr_proxy_type)
            if curr_session:
                if use_onion_session:
                    httpd.session_onion = curr_session
                elif use_i2p_session:
                    httpd.session_i2p = curr_session
                else:
                    main_session = curr_session

            send_follow_request(curr_session,
                                base_dir, nickname,
                                domain, curr_domain, curr_port,
                                curr_http_prefix,
                                following_nickname,
                                following_domain,
                                following_actor,
                                following_port, curr_http_prefix,
                                False, httpd.federation_list,
                                httpd.send_threads,
                                httpd.post_log,
                                httpd.cached_webfingers,
                                httpd.person_cache, httpd.debug,
                                httpd.project_version,
                                httpd.signing_priv_key_pem,
                                httpd.domain,
                                httpd.onion_domain,
                                httpd.i2p_domain,
                                httpd.sites_unavailable,
                                httpd.system_language,
                                httpd.mitm_servers)

            # remove the followed handle from the import list
            following_str = following_str.replace(orig_line + '\n', '')
            try:
                with open(import_filename, 'w+',
                          encoding='utf-8') as fp_import:
                    fp_import.write(following_str)
            except OSError:
                print('EX: unable to remove import 2 ' + line +
                      ' from ' + import_filename)
            print('FOLLOW: import sent follow to ' + line +
                  ' from ' + import_filename)
            return True
    return False


def run_import_following(base_dir: str, httpd):
    """Sends out follow requests for imported following csv files
    """
    dir_str = data_dir(base_dir)
    while True:
        time.sleep(20)

        # get a list of accounts on the instance, in random sequence
        accounts_list: list[str] = []
        for _, dirs, _ in os.walk(dir_str):
            for account in dirs:
                if '@' not in account:
                    continue
                if not is_account_dir(account):
                    continue
                accounts_list.append(account)
            break
        if not accounts_list:
            continue

        # check if each accounts has an import csv
        random.shuffle(accounts_list)
        for account in accounts_list:
            account_dir = dir_str + '/' + account
            import_filename = account_dir + '/import_following.csv'

            if not os.path.isfile(import_filename):
                continue
            if not _update_import_following(base_dir, account, httpd,
                                            import_filename):
                try:
                    os.remove(import_filename)
                except OSError:
                    print('EX: unable to remove import file ' +
                          import_filename)
            else:
                break


def run_import_following_watchdog(project_version: str, httpd) -> None:
    """Imports following lists from csv for every account on the instance
    """
    print('THREAD: Starting import following watchdog ' + project_version)
    import_following_original = \
        httpd.thrImportFollowing.clone(run_import_following)
    begin_thread(httpd.thrImportFollowing,
                 'run_import_following_watchdog')
    while True:
        time.sleep(50)
        if httpd.thrImportFollowing.is_alive():
            continue
        httpd.thrImportFollowing.kill()
        print('THREAD: restarting import following watchdog')
        httpd.thrImportFollowing = \
            import_following_original.clone(run_import_following)
        begin_thread(httpd.thrImportFollowing,
                     'run_import_following_watchdog 2')
        print('Restarting import following...')
