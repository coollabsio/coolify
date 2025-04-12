__filename__ = "webapp_minimalbutton.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
from utils import acct_dir


def is_minimal(base_dir: str, domain: str, nickname: str) -> bool:
    """Returns true if minimal buttons should be shown
       for the given account
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(account_dir):
        return True
    minimal_filename = account_dir + '/.notminimal'
    if os.path.isfile(minimal_filename):
        return False
    return True


def set_minimal(base_dir: str, domain: str, nickname: str,
                minimal: bool) -> None:
    """Sets whether an account should display minimal buttons
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(account_dir):
        return
    minimal_filename = account_dir + '/.notminimal'
    minimal_file_exists = os.path.isfile(minimal_filename)
    if minimal and minimal_file_exists:
        try:
            os.remove(minimal_filename)
        except OSError:
            print('EX: set_minimal unable to delete ' + minimal_filename)
    elif not minimal and not minimal_file_exists:
        try:
            with open(minimal_filename, 'w+', encoding='utf-8') as fp_min:
                fp_min.write('\n')
        except OSError:
            print('EX: unable to write minimal ' + minimal_filename)
