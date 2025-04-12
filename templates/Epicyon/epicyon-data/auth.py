__filename__ = "auth.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Security"

import base64
import hashlib
import binascii
import os
import secrets
from flags import is_system_account
from flags import is_memorial_account
from utils import data_dir
from utils import has_users_path
from utils import text_in_file
from utils import remove_eol
from utils import date_utcnow


def _hash_password(password: str) -> str:
    """Hash a password for storing
    """
    salt = hashlib.sha256(os.urandom(60)).hexdigest().encode('ascii')
    pwdhash = hashlib.pbkdf2_hmac('sha512',
                                  password.encode('utf-8'),
                                  salt, 100000)
    pwdhash = binascii.hexlify(pwdhash)
    return (salt + pwdhash).decode('ascii')


def _get_password_hash(salt: str, provided_password: str) -> str:
    """Returns the hash of a password
    """
    pwdhash = hashlib.pbkdf2_hmac('sha512',
                                  provided_password.encode('utf-8'),
                                  salt.encode('ascii'),
                                  100000)
    return binascii.hexlify(pwdhash).decode('ascii')


def constant_time_string_check(string1: str, string2: str) -> bool:
    """Compares two string and returns if they are the same
    using a constant amount of time
    See https://sqreen.github.io/DevelopersSecurityBestPractices/
    timing-attack/python
    """
    # strings must be of equal length
    if len(string1) != len(string2):
        return False
    ctr = 0
    matched = True
    for char in string1:
        if char != string2[ctr]:
            matched = False
        else:
            # this is to make the timing more even
            # and not provide clues
            matched = matched
        ctr += 1
    return matched


def _verify_password(stored_password: str, provided_password: str) -> bool:
    """Verify a stored password against one provided by user
    """
    if not stored_password:
        return False
    if not provided_password:
        return False
    salt = stored_password[:64]
    stored_password = stored_password[64:]
    pw_hash = _get_password_hash(salt, provided_password)
    return constant_time_string_check(pw_hash, stored_password)


def create_basic_auth_header(nickname: str, password: str) -> str:
    """This is only used by tests
    """
    auth_str = \
        remove_eol(nickname) + \
        ':' + \
        remove_eol(password)
    return 'Basic ' + \
        base64.b64encode(auth_str.encode('utf-8')).decode('utf-8')


def authorize_basic(base_dir: str, path: str, auth_header: str,
                    debug: bool) -> bool:
    """HTTP basic auth
    """
    if ' ' not in auth_header:
        if debug:
            print('DEBUG: basic auth - Authorisation header does not ' +
                  'contain a space character')
        return False
    if not has_users_path(path):
        if not path.startswith('/calendars/'):
            if debug:
                print('DEBUG: basic auth - ' +
                      'path for Authorization does not contain a user')
            return False
    if path.startswith('/calendars/'):
        path_users_section = path.split('/calendars/')[1]
        nickname_from_path = path_users_section
        if '/' in nickname_from_path:
            nickname_from_path = nickname_from_path.split('/')[0]
        if '?' in nickname_from_path:
            nickname_from_path = nickname_from_path.split('?')[0]
    else:
        path_users_section = path.split('/users/')[1]
        if '/' not in path_users_section:
            if debug:
                print('DEBUG: basic auth - this is not a users endpoint')
            return False
        nickname_from_path = path_users_section.split('/')[0]
    if is_system_account(nickname_from_path):
        print('basic auth - attempted login using system account ' +
              nickname_from_path + ' in path')
        return False
    base64_str1 = auth_header.split(' ')[1]
    base64_str = remove_eol(base64_str1)
    plain = base64.b64decode(base64_str).decode('utf-8')
    if ':' not in plain:
        if debug:
            print('DEBUG: basic auth header does not contain a ":" ' +
                  'separator for username:password')
        return False
    nickname = plain.split(':')[0]
    if is_system_account(nickname):
        print('basic auth - attempted login using system account ' + nickname +
              ' in Auth header')
        return False
    if nickname != nickname_from_path:
        if debug:
            print('DEBUG: Nickname given in the path (' + nickname_from_path +
                  ') does not match the one in the Authorization header (' +
                  nickname + ')')
        return False
    if is_memorial_account(base_dir, nickname):
        print('basic auth - attempted login using memorial account ' +
              nickname + ' in Auth header')
        return False
    password_file = data_dir(base_dir) + '/passwords'
    if not os.path.isfile(password_file):
        if debug:
            print('DEBUG: passwords file missing')
        return False
    provided_password = plain.split(':')[1]
    try:
        with open(password_file, 'r', encoding='utf-8') as fp_pass:
            for line in fp_pass:
                if not line.startswith(nickname + ':'):
                    continue
                stored_password_base = line.split(':')[1]
                stored_password = remove_eol(stored_password_base)
                success = _verify_password(stored_password, provided_password)
                if not success:
                    if debug:
                        print('DEBUG: Password check failed for ' + nickname)
                return success
    except OSError:
        print('EX: failed to open password file')
        return False
    print('DEBUG: Did not find credentials for ' + nickname +
          ' in ' + password_file)
    return False


def store_basic_credentials(base_dir: str,
                            nickname: str, password: str) -> bool:
    """Stores login credentials to a file
    """
    if ':' in nickname or ':' in password:
        return False
    nickname = remove_eol(nickname).strip()
    password = remove_eol(password).strip()

    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str):
        os.mkdir(dir_str)

    password_file = dir_str + '/passwords'
    store_str = nickname + ':' + _hash_password(password)
    if os.path.isfile(password_file):
        if text_in_file(nickname + ':', password_file):
            try:
                with open(password_file, 'r', encoding='utf-8') as fp_in:
                    with open(password_file + '.new', 'w+',
                              encoding='utf-8') as fout:
                        for line in fp_in:
                            if not line.startswith(nickname + ':'):
                                fout.write(line)
                            else:
                                fout.write(store_str + '\n')
            except OSError as ex:
                print('EX: unable to save password ' + password_file +
                      ' ' + str(ex))
                return False

            try:
                os.rename(password_file + '.new', password_file)
            except OSError:
                print('EX: unable to save password 2')
                return False
        else:
            # append to password file
            try:
                with open(password_file, 'a+', encoding='utf-8') as fp_pass:
                    fp_pass.write(store_str + '\n')
            except OSError:
                print('EX: unable to append password')
                return False
    else:
        try:
            with open(password_file, 'w+', encoding='utf-8') as fp_pass:
                fp_pass.write(store_str + '\n')
        except OSError:
            print('EX: unable to create password file')
            return False
    return True


def remove_password(base_dir: str, nickname: str) -> None:
    """Removes the password entry for the given nickname
    This is called during account removal
    """
    password_file = data_dir(base_dir) + '/passwords'
    if os.path.isfile(password_file):
        try:
            with open(password_file, 'r', encoding='utf-8') as fp_in:
                with open(password_file + '.new', 'w+',
                          encoding='utf-8') as fp_out:
                    for line in fp_in:
                        if not line.startswith(nickname + ':'):
                            fp_out.write(line)
        except OSError as ex:
            print('EX: unable to remove password from file ' + str(ex))
            return

        try:
            os.rename(password_file + '.new', password_file)
        except OSError:
            print('EX: unable to remove password from file 2')
            return


def authorize(base_dir: str, path: str, auth_header: str, debug: bool) -> bool:
    """Authorize using http header
    """
    if auth_header.lower().startswith('basic '):
        return authorize_basic(base_dir, path, auth_header, debug)
    return False


def create_password(length: int):
    valid_chars = 'abcdefghijklmnopqrstuvwxyz' + \
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
    return ''.join((secrets.choice(valid_chars) for i in range(length)))


def record_login_failure(base_dir: str, ip_address: str,
                         count_dict: {}, fail_time: int,
                         log_to_file: bool) -> None:
    """Keeps ip addresses and the number of times login failures
    occured for them in a dict
    """
    if not count_dict.get(ip_address):
        while len(count_dict.items()) > 100:
            oldest_time = 0
            oldest_ip = None
            for ip_addr, ip_item in count_dict.items():
                if oldest_time == 0 or ip_item['time'] < oldest_time:
                    oldest_time = ip_item['time']
                    oldest_ip = ip_addr
            if oldest_ip:
                del count_dict[oldest_ip]
        count_dict[ip_address] = {
            "count": 1,
            "time": fail_time
        }
    else:
        count_dict[ip_address]['count'] += 1
        count_dict[ip_address]['time'] = fail_time
        fail_count = count_dict[ip_address]['count']
        if fail_count > 4:
            print('WARN: ' + str(ip_address) + ' failed to log in ' +
                  str(fail_count) + ' times')

    if not log_to_file:
        return

    failure_log = data_dir(base_dir) + '/loginfailures.log'
    write_type = 'a+'
    if not os.path.isfile(failure_log):
        write_type = 'w+'
    curr_time = date_utcnow()
    curr_time_str = curr_time.strftime("%Y-%m-%d %H:%M:%SZ")
    try:
        with open(failure_log, write_type, encoding='utf-8') as fp_fail:
            # here we use a similar format to an ssh log, so that
            # systems such as fail2ban can parse it
            fp_fail.write(curr_time_str + ' ' +
                          'ip-127-0-0-1 sshd[20710]: ' +
                          'Disconnecting invalid user epicyon ' +
                          ip_address + ' port 443: ' +
                          'Too many authentication failures [preauth]\n')
    except OSError:
        print('EX: record_login_failure failed ' + str(failure_log))
