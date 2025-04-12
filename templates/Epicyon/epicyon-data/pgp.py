__filename__ = "pgp.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"

import os
import base64
import subprocess
from pathlib import Path
from person import get_actor_json
from flags import is_pgp_encrypted
from flags import contains_pgp_public_key
from utils import get_occupation_skills
from utils import get_url_from_post
from utils import safe_system_string
from utils import get_full_domain
from utils import get_status_number
from utils import local_actor_url
from utils import replace_users_with_at
from utils import remove_html
from webfinger import webfinger_handle
from posts import get_person_box
from auth import create_basic_auth_header
from session import post_json
from pronouns import get_pronouns
from pixelfed import get_pixelfed
from discord import get_discord
from art import get_art_site_url
from music import get_music_site_url
from youtube import get_youtube
from peertube import get_peertube
from xmpp import get_xmpp_address
from matrix import get_matrix_address
from briar import get_briar_address
from cwtch import get_cwtch_address
from blog import get_blog_address
from website import get_website


from utils import get_attachment_property_value


def get_email_address(actor_json: {}) -> str:
    """Returns the email address for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    if not isinstance(actor_json['attachment'], list):
        return ''
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        name_value_lower = name_value.lower()
        if 'email' not in name_value_lower:
            if 'e-mail' not in name_value_lower:
                if 'electronic mail' not in name_value_lower:
                    continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        value_str = remove_html(property_value[prop_value_name])
        if '://' in value_str:
            continue
        if '@' not in value_str:
            continue
        if '.' not in value_str:
            continue
        return value_str
    return ''


def _get_deltachat_strings() -> []:
    return ['deltachat', 'delta chat', 'chatmail']


def get_deltachat_invite(actor_json: {}, translate: {}) -> str:
    """Returns the deltachat invite link for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    match_strings = _get_deltachat_strings()
    match_strings.append(translate['DeltaChat'].lower())
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        found = False
        for possible_str in match_strings:
            if possible_str in name_value.lower():
                found = True
                break
        if not found:
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        value_str = remove_html(property_value[prop_value_name])
        if 'https://' not in value_str and \
           'http://' not in value_str:
            continue
        return value_str
    return ''


def get_pgp_pub_key(actor_json: {}) -> str:
    """Returns PGP public key for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not name_value.lower().startswith('pgp'):
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        if not contains_pgp_public_key(property_value[prop_value_name]):
            continue
        return remove_html(property_value[prop_value_name])
    return ''


def get_pgp_fingerprint(actor_json: {}) -> str:
    """Returns PGP fingerprint for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not name_value.lower().startswith('openpgp'):
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        if len(property_value[prop_value_name]) < 10:
            continue
        return remove_html(property_value[prop_value_name])
    return ''


def set_email_address(actor_json: {}, email_address: str) -> None:
    """Sets the email address for the given actor
    """
    not_email_address = False
    if '@' not in email_address:
        not_email_address = True
    if '.' not in email_address:
        not_email_address = True
    if '<' in email_address:
        not_email_address = True
    if email_address.startswith('@'):
        not_email_address = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not name_value.lower().startswith('email'):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_email_address:
        return

    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not name_value.lower().startswith('email'):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = email_address
        return

    new_email_address = {
        "name": "Email",
        "type": "PropertyValue",
        "value": email_address
    }
    actor_json['attachment'].append(new_email_address)


def set_deltachat_invite(actor_json: {}, invite_link: str,
                         translate: {}) -> None:
    """Sets the deltachat invite link for the given actor
    """
    invite_link = invite_link.strip()
    not_url = False
    if '.' not in invite_link:
        not_url = True
    if '://' not in invite_link:
        not_url = True
    if ' ' in invite_link:
        not_url = True
    if '<' in invite_link:
        not_url = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    match_strings = _get_deltachat_strings()
    match_strings.append(translate['DeltaChat'].lower())

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        if not property_value.get('name'):
            continue
        if not property_value.get('type'):
            continue
        if property_value['name'].lower() not in match_strings:
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_url:
        return

    new_entry = {
        "name": 'DeltaChat',
        "type": "PropertyValue",
        "value": invite_link
    }
    actor_json['attachment'].append(new_entry)


def set_pgp_pub_key(actor_json: {}, pgp_pub_key: str) -> None:
    """Sets a PGP public key for the given actor
    """
    remove_key = False
    if not pgp_pub_key:
        remove_key = True
    else:
        if not contains_pgp_public_key(pgp_pub_key):
            remove_key = True
        if '<' in pgp_pub_key:
            remove_key = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not name_value.lower().startswith('pgp'):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if remove_key:
        return

    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not name_value.lower().startswith('pgp'):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = pgp_pub_key
        return

    newpgp_pub_key = {
        "name": "PGP",
        "type": "PropertyValue",
        "value": pgp_pub_key
    }
    actor_json['attachment'].append(newpgp_pub_key)


def set_pgp_fingerprint(actor_json: {}, fingerprint: str) -> None:
    """Sets a PGP fingerprint for the given actor
    """
    remove_fingerprint = False
    if not fingerprint:
        remove_fingerprint = True
    else:
        if len(fingerprint) < 10:
            remove_fingerprint = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not name_value.lower().startswith('openpgp'):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if remove_fingerprint:
        return

    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not name_value.lower().startswith('openpgp'):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = fingerprint.strip()
        return

    newpgp_fingerprint = {
        "name": "OpenPGP",
        "type": "PropertyValue",
        "value": fingerprint
    }
    actor_json['attachment'].append(newpgp_fingerprint)


def extract_pgp_public_key(content: str) -> str:
    """Returns the PGP key from the given text
    """
    start_block = '--BEGIN PGP PUBLIC KEY BLOCK--'
    end_block = '--END PGP PUBLIC KEY BLOCK--'
    if start_block not in content:
        return None
    if end_block not in content:
        return None
    if '\n' not in content:
        return None
    lines_list = content.split('\n')
    extracting = False
    public_key = ''
    for line in lines_list:
        if not extracting:
            if start_block in line:
                extracting = True
        else:
            if end_block in line:
                public_key += line
                break
        if extracting:
            public_key += line + '\n'
    return public_key


def _pgp_import_pub_key(recipient_pub_key: str) -> str:
    """ Import the given public key
    """
    # do a dry run
    cmd_import_pub_key = \
        'echo "' + safe_system_string(recipient_pub_key) + \
        '" | gpg --dry-run --import 2> /dev/null'
    proc = subprocess.Popen([cmd_import_pub_key],
                            stdout=subprocess.PIPE, shell=True)
    (import_result, err) = proc.communicate()
    if err:
        return None

    # this time for real
    cmd_import_pub_key = \
        'echo "' + safe_system_string(recipient_pub_key) + \
        '" | gpg --import 2> /dev/null'
    proc = subprocess.Popen([cmd_import_pub_key],
                            stdout=subprocess.PIPE, shell=True)
    (import_result, err) = proc.communicate()
    if err:
        return None

    # get the key id
    cmd_import_pub_key = \
        'echo "' + safe_system_string(recipient_pub_key) + \
        '" | gpg --show-keys'
    proc = subprocess.Popen([cmd_import_pub_key],
                            stdout=subprocess.PIPE, shell=True)
    (import_result, err) = proc.communicate()
    if not import_result:
        return None
    import_result = import_result.decode('utf-8').split('\n')
    key_id = ''
    for line in import_result:
        if line.startswith('pub'):
            continue
        if line.startswith('uid'):
            continue
        if line.startswith('sub'):
            continue
        key_id = line.strip()
        break
    return key_id


def _pgp_encrypt(content: str, recipient_pub_key: str) -> str:
    """ Encrypt using your default pgp key to the given recipient
    """
    key_id = _pgp_import_pub_key(recipient_pub_key)
    if not key_id:
        return None

    cmd_encrypt = \
        'echo "' + safe_system_string(content) + \
        '" | gpg --encrypt --armor --recipient ' + \
        safe_system_string(key_id) + ' 2> /dev/null'
    proc = subprocess.Popen([cmd_encrypt],
                            stdout=subprocess.PIPE, shell=True)
    (encrypt_result, _) = proc.communicate()
    if not encrypt_result:
        return None
    encrypt_result = encrypt_result.decode('utf-8')
    if not is_pgp_encrypted(encrypt_result):
        return None
    return encrypt_result


def has_local_pg_pkey() -> bool:
    """Returns true if there is a local .gnupg directory
    """
    home_dir = str(Path.home())
    gpg_dir = home_dir + '/.gnupg'
    if os.path.isdir(gpg_dir):
        key_id = pgp_local_public_key()
        if key_id:
            return True
    return False


def pgp_encrypt_to_actor(domain: str, content: str, to_handle: str,
                         signing_priv_key_pem: str,
                         mitm_servers: []) -> str:
    """PGP encrypt a message to the given actor or handle
    """
    # get the actor and extract the pgp public key from it
    recipient_pub_key = \
        _get_pgp_public_key_from_actor(signing_priv_key_pem, domain, to_handle,
                                       mitm_servers)
    if not recipient_pub_key:
        return None
    # encrypt using the recipient public key
    return _pgp_encrypt(content, recipient_pub_key)


def pgp_decrypt(domain: str, content: str, from_handle: str,
                signing_priv_key_pem: str,
                mitm_servers: []) -> str:
    """ Encrypt using your default pgp key to the given recipient
    fromHandle can be a handle or actor url
    """
    if not is_pgp_encrypted(content):
        return content

    # if the public key is also included within the message then import it
    if contains_pgp_public_key(content):
        pub_key = extract_pgp_public_key(content)
    else:
        pub_key = \
            _get_pgp_public_key_from_actor(signing_priv_key_pem,
                                           domain, content, from_handle,
                                           mitm_servers)
    if pub_key:
        _pgp_import_pub_key(pub_key)

    cmd_decrypt = \
        'echo "' + safe_system_string(content) + \
        '" | gpg --decrypt --armor 2> /dev/null'
    proc = subprocess.Popen([cmd_decrypt],
                            stdout=subprocess.PIPE, shell=True)
    (decrypt_result, _) = proc.communicate()
    if not decrypt_result:
        return content
    decrypt_result = decrypt_result.decode('utf-8').strip()
    return decrypt_result


def _pgp_local_public_key_id() -> str:
    """Gets the local pgp public key ID
    """
    cmd_str = \
        "gpgconf --list-options gpg | " + \
        "awk -F: '$1 == \"default-key\" {print $10}'"
    proc = subprocess.Popen([cmd_str],
                            stdout=subprocess.PIPE, shell=True)
    (result, err) = proc.communicate()
    if err:
        return None
    if not result:
        return None
    if len(result) < 5:
        return None
    return result.decode('utf-8').replace('"', '').strip()


def pgp_local_public_key() -> str:
    """Gets the local pgp public key
    """
    key_id = _pgp_local_public_key_id()
    if not key_id:
        key_id = ''
    cmd_str = "gpg --armor --export " + safe_system_string(key_id)
    proc = subprocess.Popen([cmd_str],
                            stdout=subprocess.PIPE, shell=True)
    (result, err) = proc.communicate()
    if err:
        return None
    if not result:
        return None
    return extract_pgp_public_key(result.decode('utf-8'))


def _get_pgp_public_key_from_actor(signing_priv_key_pem: str,
                                   domain: str, handle: str,
                                   mitm_servers: [],
                                   actor_json: {} = None) -> str:
    """Searches tags on the actor to see if there is any PGP
    public key specified
    """
    if not actor_json:
        actor_json, _ = \
            get_actor_json(domain, handle, False, False, False, False,
                           False, True, signing_priv_key_pem, None,
                           mitm_servers)
    if not actor_json:
        return None
    if not actor_json.get('attachment'):
        return None
    if not isinstance(actor_json['attachment'], list):
        return None
    # search through the tags on the actor
    for tag in actor_json['attachment']:
        if not isinstance(tag, dict):
            continue
        prop_value_name, _ = get_attachment_property_value(tag)
        if not prop_value_name:
            continue
        if not isinstance(tag[prop_value_name], str):
            continue
        if contains_pgp_public_key(tag[prop_value_name]):
            return tag[prop_value_name]
    return None


def pgp_public_key_upload(base_dir: str, session,
                          nickname: str, password: str,
                          domain: str, port: int,
                          http_prefix: str,
                          cached_webfingers: {}, person_cache: {},
                          debug: bool, test: str,
                          signing_priv_key_pem: str,
                          system_language: str,
                          mitm_servers: []) -> {}:
    if debug:
        print('pgp_public_key_upload')

    if not session:
        if debug:
            print('WARN: No session for pgp_public_key_upload')
        return None

    if not test:
        if debug:
            print('Getting PGP public key')
        pgp_pub_key = pgp_local_public_key()
        if not pgp_pub_key:
            return None
        pgp_pub_key_id = _pgp_local_public_key_id()
    else:
        if debug:
            print('Testing with PGP public key ' + test)
        pgp_pub_key = test
        pgp_pub_key_id = None

    domain_full = get_full_domain(domain, port)
    if debug:
        print('PGP test domain: ' + domain_full)

    handle = nickname + '@' + domain_full

    if debug:
        print('Getting actor for ' + handle)

    actor_json, _ = \
        get_actor_json(domain_full, handle, False, False, False, False,
                       debug, True, signing_priv_key_pem, session,
                       mitm_servers)
    if not actor_json:
        if debug:
            print('No actor returned for ' + handle)
        return None

    if debug:
        print('Actor for ' + handle + ' obtained')

    actor = local_actor_url(http_prefix, nickname, domain_full)
    handle = replace_users_with_at(actor)

    # check that this looks like the correct actor
    if not actor_json.get('id'):
        if debug:
            print('Actor has no id')
        return None
    if not actor_json.get('url'):
        if debug:
            print('Actor has no url')
        return None
    if not actor_json.get('type'):
        if debug:
            print('Actor has no type')
        return None
    if actor_json['id'] != actor:
        if debug:
            print('Actor id is not ' + actor +
                  ' instead is ' + actor_json['id'])
        return None
    if actor_json['url'] != handle:
        if debug:
            print('Actor url is not ' + handle)
        return None
    if actor_json['type'] != 'Person':
        if debug:
            print('Actor type is not Person')
        return None

    # set the pgp details
    if pgp_pub_key_id:
        set_pgp_fingerprint(actor_json, pgp_pub_key_id)
    else:
        if debug:
            print('No PGP key Id. Continuing anyway.')

    if debug:
        print('Setting PGP key within ' + actor)
    set_pgp_pub_key(actor_json, pgp_pub_key)

    # create an actor update
    status_number, _ = get_status_number()
    actor_update = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': actor + '#updates/' + status_number,
        'type': 'Update',
        'actor': actor,
        'to': [actor],
        'cc': [],
        'object': actor_json
    }
    if debug:
        print('actor update is ' + str(actor_update))

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         domain, __version__, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: pgp actor update webfinger failed for ' +
                  handle)
        return None
    if not isinstance(wf_request, dict):
        if debug:
            print('WARN: Webfinger for ' + handle +
                  ' did not return a dict. ' + str(wf_request))
        return None

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem, origin_domain,
                            base_dir, session, wf_request,
                            person_cache,
                            __version__, http_prefix, nickname,
                            domain, post_to_box, 35725,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: No ' + post_to_box + ' was found for ' + handle)
        return None
    if not from_person_id:
        if debug:
            print('DEBUG: No actor was found for ' + handle)
        return None

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    quiet = not debug
    tries = 0
    while tries < 4:
        post_result = \
            post_json(http_prefix, domain_full,
                      session, actor_update, [], inbox_url,
                      headers, 5, quiet)
        if post_result:
            break
        tries += 1

    if post_result is None:
        if debug:
            print('DEBUG: POST pgp actor update failed for c2s to ' +
                  inbox_url)
        return None

    if debug:
        print('DEBUG: c2s POST pgp actor update success')

    return actor_update


def actor_to_vcard(actor: {}, domain: str, translate: {}) -> str:
    """Returns a vcard for a given actor
    """
    actor_url_str = get_url_from_post(actor['url'])
    vcard_str = 'BEGIN:VCARD\n'
    vcard_str += 'VERSION:4.0\n'
    vcard_str += 'REV:' + actor['published'] + '\n'
    vcard_str += 'FN:' + remove_html(actor['name']) + '\n'
    vcard_str += 'NICKNAME:' + actor['preferredUsername'] + '\n'
    vcard_str += 'NOTE:' + remove_html(actor['summary']) + '\n'
    url_str = get_url_from_post(actor['icon']['url'])
    if url_str:
        vcard_str += 'PHOTO:' + url_str + '\n'
    pgp_key = get_pgp_pub_key(actor)
    if pgp_key:
        vcard_str += 'KEY:data:application/pgp-keys;base64,' + \
            base64.b64encode(pgp_key.encode('utf-8')).decode('utf-8') + '\n'
    email_address = get_email_address(actor)
    if email_address:
        vcard_str += 'EMAIL;TYPE=internet:' + email_address + '\n'
    vcard_str += 'IMPP:fediverse:' + \
        actor['preferredUsername'] + '@' + domain + '\n'
    if actor.get('vcard:bday'):
        birthday_str = actor['vcard:bday']
        if '-' in birthday_str:
            birthday = birthday_str.split('-')
            if len(birthday) == 3:
                vcard_str += \
                    'BDAY:' + birthday[0] + birthday[1] + birthday[2] + '\n'
    pronouns = get_pronouns(actor)
    if pronouns:
        vcard_str += 'PRONOUNS:' + pronouns + '\n'
    vcard_str += 'SOCIALPROFILE;SERVICE-TYPE=Mastodon:' + actor_url_str + '\n'
    blog_address = get_blog_address(actor)
    if blog_address:
        vcard_str += 'SOCIALPROFILE;SERVICE-TYPE=Blog:' + blog_address + '\n'
    discord = get_discord(actor)
    if discord:
        vcard_str += 'SOCIALPROFILE;SERVICE-TYPE=Discord:' + discord + '\n'
    pixelfed = get_pixelfed(actor)
    if pixelfed:
        vcard_str += 'SOCIALPROFILE;SERVICE-TYPE=Pixelfed:' + pixelfed + '\n'
    art_site_url = get_art_site_url(actor)
    if art_site_url:
        vcard_str += \
            'SOCIALPROFILE;SERVICE-TYPE=Art:' + art_site_url + '\n'
    music_site_url = get_music_site_url(actor)
    if music_site_url:
        vcard_str += \
            'SOCIALPROFILE;SERVICE-TYPE=Music:' + music_site_url + '\n'
    youtube = get_youtube(actor)
    if youtube:
        vcard_str += 'SOCIALPROFILE;SERVICE-TYPE=YouTube:' + youtube + '\n'
    peertube = get_peertube(actor)
    if peertube:
        vcard_str += 'SOCIALPROFILE;SERVICE-TYPE=PeerTube:' + peertube + '\n'
    website = get_website(actor, translate)
    if website:
        vcard_str += 'URL:' + website + '\n'
    deltachat_invite = get_deltachat_invite(actor, translate)
    if deltachat_invite:
        vcard_str += 'IMPP:deltachat:' + deltachat_invite + '\n'
    xmpp_address = get_xmpp_address(actor)
    if xmpp_address:
        vcard_str += 'IMPP:xmpp:' + xmpp_address + '\n'
    matrix_address = get_matrix_address(actor)
    if matrix_address:
        vcard_str += 'IMPP:matrix:' + matrix_address + '\n'
    briar_address = get_briar_address(actor)
    if briar_address:
        if briar_address.startswith('briar://'):
            briar_address = briar_address.split('briar://')[1]
        vcard_str += 'IMPP:briar:' + briar_address + '\n'
    cwtch_address = get_cwtch_address(actor)
    if cwtch_address:
        vcard_str += 'IMPP:cwtch:' + cwtch_address + '\n'
    oc_skills_list = get_occupation_skills(actor)
    if oc_skills_list:
        for skill_name in oc_skills_list:
            if ':' not in skill_name:
                continue
            skill_level = skill_name.split(':')[1]
            if not skill_level.isdigit():
                continue
            skill_level = int(skill_level)
            skill_name = skill_name.split(':')[0].strip().lower()
            if not skill_name:
                continue
            level_str = None
            if skill_level < 33:
                level_str = 'beginner'
            elif skill_level < 66:
                level_str = 'average'
            else:
                level_str = 'expert'
            vcard_str += \
                'EXPERTISE;LEVEL=' + level_str + ':' + skill_name + '\n'
    if actor.get('hasOccupation'):
        if len(actor['hasOccupation']) > 0:
            if actor['hasOccupation'][0].get('name'):
                vcard_str += \
                    'ROLE:' + \
                    actor['hasOccupation'][0]['name'] + '\n'
            if actor['hasOccupation'][0].get('occupationLocation'):
                city_name = \
                    actor['hasOccupation'][0]['occupationLocation']['name']
                vcard_str += \
                    'ADR:;;;' + city_name + ';;;\n'
    vcard_str += 'END:VCARD\n'
    return vcard_str


def actor_to_vcard_xml(actor: {}, domain: str, translate: {}) -> str:
    """Returns a xml formatted vcard for a given actor
    """
    vcard_str = '<?xml version="1.0" encoding="UTF-8"?>\n'
    vcard_str += '<vcards xmlns="urn:ietf:params:xml:ns:vcard-4.0">\n'
    vcard_str += '  <vcard>\n'
    vcard_str += '    <fn><text>' + \
        remove_html(actor['name']) + '</text></fn>\n'
    vcard_str += '    <nickname><text>' + \
        actor['preferredUsername'] + '</text></nickname>\n'
    vcard_str += '    <note><text>' + \
        remove_html(actor['summary']) + '</text></note>\n'
    email_address = get_email_address(actor)
    if email_address:
        vcard_str += '    <email><text>' + email_address + '</text></email>\n'
    vcard_str += '    <impp>' + \
        '<parameters><type><text>fediverse</text></type></parameters>' + \
        '<text>' + actor['preferredUsername'] + '@' + domain + \
        '</text></impp>\n'
    if actor.get('vcard:bday'):
        birthday_str = actor['vcard:bday']
        if '-' in birthday_str:
            birthday = birthday_str.split('-')
            if len(birthday) == 3:
                vcard_str += \
                    '    <bday><text>' + \
                    birthday[0] + birthday[1] + birthday[2] + \
                    '</text></bday>\n'
    pronouns = get_pronouns(actor)
    if pronouns:
        vcard_str += '    <pronouns><text>' + pronouns + '</text></pronouns>\n'
    pixelfed = get_pixelfed(actor)
    if pixelfed:
        vcard_str += '    <url>' + \
            '<parameters><type><text>pixelfed</text></type></parameters>' + \
            '<uri>' + pixelfed + '</uri></url>\n'
    discord = get_discord(actor)
    if discord:
        vcard_str += '    <url>' + \
            '<parameters><type><text>discord</text></type></parameters>' + \
            '<uri>' + discord + '</uri></url>\n'
    youtube = get_youtube(actor)
    if youtube:
        vcard_str += '    <url>' + \
            '<parameters><type><text>youtube</text></type></parameters>' + \
            '<uri>' + youtube + '</uri></url>\n'
    art_site_url = get_art_site_url(actor)
    if art_site_url:
        vcard_str += '    <url>' + \
            '<parameters><type><text>art</text></type></parameters>' + \
            '<uri>' + art_site_url + '</uri></url>\n'
    music_site_url = get_music_site_url(actor)
    if music_site_url:
        vcard_str += '    <url>' + \
            '<parameters><type><text>music</text></type></parameters>' + \
            '<uri>' + music_site_url + '</uri></url>\n'
    peertube = get_peertube(actor)
    if peertube:
        vcard_str += '    <url>' + \
            '<parameters><type><text>peertube</text></type></parameters>' + \
            '<uri>' + peertube + '</uri></url>\n'
    deltachat_invite = get_deltachat_invite(actor, translate)
    if deltachat_invite:
        vcard_str += '    <impp>' + \
            '<parameters><type><text>deltachat</text></type></parameters>' + \
            '<text>' + deltachat_invite + '</text></impp>\n'
    xmpp_address = get_xmpp_address(actor)
    if xmpp_address:
        vcard_str += '    <impp>' + \
            '<parameters><type><text>xmpp</text></type></parameters>' + \
            '<text>' + xmpp_address + '</text></impp>\n'
    matrix_address = get_matrix_address(actor)
    if matrix_address:
        vcard_str += '    <impp>' + \
            '<parameters><type><text>matrix</text></type></parameters>' + \
            '<text>' + matrix_address + '</text></impp>\n'
    briar_address = get_briar_address(actor)
    if briar_address:
        vcard_str += '    <impp>' + \
            '<parameters><type><text>briar</text></type></parameters>' + \
            '<uri>' + briar_address + '</uri></impp>\n'
    cwtch_address = get_cwtch_address(actor)
    if cwtch_address:
        vcard_str += '    <impp>' + \
            '<parameters><type><text>cwtch</text></type></parameters>' + \
            '<text>' + cwtch_address + '</text></impp>\n'
    url_str = get_url_from_post(actor['url'])
    vcard_str += '    <url>' + \
        '<parameters><type><text>profile</text></type></parameters>' + \
        '<uri>' + url_str + '</uri></url>\n'
    blog_address = get_blog_address(actor)
    if blog_address:
        vcard_str += '    <url>' + \
            '<parameters><type><text>blog</text></type></parameters>' + \
            '<uri>' + blog_address + '</uri></url>\n'
    website = get_website(actor, translate)
    if website:
        vcard_str += '    <url>' + \
            '<parameters><type><text>website</text></type></parameters>' + \
            '<uri>' + website + '</uri></url>\n'
    vcard_str += '    <rev>' + actor['published'] + '</rev>\n'
    url_str = get_url_from_post(actor['icon']['url'])
    if url_str:
        vcard_str += \
            '    <photo><uri>' + url_str + '</uri></photo>\n'
    pgp_key = get_pgp_pub_key(actor)
    if pgp_key:
        pgp_key_encoded = \
            base64.b64encode(pgp_key.encode('utf-8')).decode('utf-8')
        vcard_str += \
            '    <key>' + \
            '<parameters>' + \
            '<type><text>data</text></type>' + \
            '<mediatype>application/pgp-keys;base64</mediatype>' + \
            '</parameters>' + \
            '<text>' + pgp_key_encoded + '</text></key>\n'
    if actor.get('hasOccupation'):
        if len(actor['hasOccupation']) > 0:
            if actor['hasOccupation'][0].get('name'):
                vcard_str += \
                    '    <role><text>' + \
                    actor['hasOccupation'][0]['name'] + '</text></role>\n'
            if actor['hasOccupation'][0].get('occupationLocation'):
                city_name = \
                    actor['hasOccupation'][0]['occupationLocation']['name']
                vcard_str += \
                    '    <adr><locality>' + city_name + '</locality></adr>\n'

    vcard_str += '  </vcard>\n'
    vcard_str += '</vcards>\n'
    return vcard_str
