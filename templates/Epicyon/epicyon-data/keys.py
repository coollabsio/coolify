__filename__ = "keys.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os


def _get_local_private_key(base_dir: str, nickname: str, domain: str) -> str:
    """Returns the private key for a local account
    """
    if not domain or not nickname:
        return None
    handle = nickname + '@' + domain
    key_filename = base_dir + '/keys/private/' + handle.lower() + '.key'
    if not os.path.isfile(key_filename):
        return None
    try:
        with open(key_filename, 'r', encoding='utf-8') as fp_pem:
            return fp_pem.read()
    except OSError:
        print('EX: _get_local_private_key unable to read ' + key_filename)
    return None


def _get_local_public_key(base_dir: str, nickname: str, domain: str) -> str:
    """Returns the public key for a local account
    """
    if not domain or not nickname:
        return None
    handle = nickname + '@' + domain
    key_filename = base_dir + '/keys/public/' + handle.lower() + '.key'
    if not os.path.isfile(key_filename):
        return None
    try:
        with open(key_filename, 'r', encoding='utf-8') as fp_pem:
            return fp_pem.read()
    except OSError:
        print('EX: _get_local_public_key unable to read ' + key_filename)
    return None


def get_instance_actor_key(base_dir: str, domain: str) -> str:
    """Returns the private key for the instance actor used for
    signing GET posts
    """
    return _get_local_private_key(base_dir, 'inbox', domain)


def get_person_key(nickname: str, domain: str, base_dir: str,
                   key_type: str, debug: bool):
    """Returns the public or private key of a person
    key_type can be private or public
    """
    if key_type == 'private':
        key_pem = _get_local_private_key(base_dir, nickname, domain)
    else:
        key_pem = _get_local_public_key(base_dir, nickname, domain)
    if not key_pem:
        if debug:
            print('DEBUG: ' + key_type + ' key file not found')
        return ''
    if len(key_pem) < 20:
        if debug:
            print('DEBUG: private key was too short: ' + key_pem)
        return ''
    return key_pem
