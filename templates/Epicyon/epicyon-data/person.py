__filename__ = "person.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import time
import os
import subprocess
import shutil
import pyqrcode
from random import randint
from pathlib import Path
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization
from shutil import copyfile
from webfinger import create_webfinger_endpoint
from webfinger import store_webfinger_endpoint
from posts import get_user_url
from posts import create_dm_timeline
from posts import create_replies_timeline
from posts import create_media_timeline
from posts import create_news_timeline
from posts import create_blogs_timeline
from posts import create_features_timeline
from posts import create_bookmarks_timeline
from posts import create_inbox
from posts import create_outbox
from posts import create_moderation
from auth import store_basic_credentials
from auth import remove_password
from roles import set_role
from roles import actor_roles_from_list
from roles import get_actor_roles_list
from media import process_meta_data
from flags import is_image_file
from utils import get_person_icon
from utils import account_is_indexable
from utils import get_image_mime_type
from utils import get_instance_url
from utils import get_url_from_post
from utils import date_utcnow
from utils import get_memorials
from utils import is_account_dir
from utils import valid_hash_tag
from utils import acct_handle_dir
from utils import safe_system_string
from utils import get_attachment_property_value
from utils import get_nickname_from_actor
from utils import remove_html
from utils import contains_invalid_chars
from utils import contains_invalid_actor_url_chars
from utils import replace_users_with_at
from utils import remove_eol
from utils import remove_domain_port
from utils import get_status_number
from utils import get_full_domain
from utils import valid_nickname
from utils import load_json
from utils import save_json
from utils import set_config_param
from utils import get_config_param
from utils import refresh_newswire
from utils import get_protocol_prefixes
from utils import has_users_path
from utils import get_image_extensions
from utils import acct_dir
from utils import get_user_paths
from utils import get_group_paths
from utils import local_actor_url
from utils import dangerous_svg
from utils import text_in_file
from utils import contains_statuses
from utils import get_actor_from_post
from utils import data_dir
from session import get_json_valid
from session import create_session
from session import get_json
from webfinger import webfinger_handle
from pprint import pprint
from cache import get_actor_public_key_from_id
from cache import get_person_from_cache
from cache import store_person_in_cache
from cache import remove_person_from_cache
from filters import is_filtered_bio
from follow import is_following_actor


def generate_rsa_key() -> (str, str):
    """Creates an RSA key for signing
    """
    key = rsa.generate_private_key(
        public_exponent=65537,
        key_size=2048,
        backend=default_backend()
    )
    private_key_pem = key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.TraditionalOpenSSL,
        encryption_algorithm=serialization.NoEncryption()
    )
    pubkey = key.public_key()
    public_key_pem = pubkey.public_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PublicFormat.SubjectPublicKeyInfo,
    )
    private_key_pem = private_key_pem.decode("utf-8")
    public_key_pem = public_key_pem.decode("utf-8")
    return private_key_pem, public_key_pem


def set_profile_image(base_dir: str, http_prefix: str,
                      nickname: str, domain: str,
                      port: int, image_filename: str, image_type: str,
                      resolution: str, city: str,
                      content_license_url: str) -> bool:
    """Saves the given image file as an avatar or background
    image for the given person
    """
    image_filename = remove_eol(image_filename)
    if not is_image_file(image_filename):
        print('Profile image must be png, jpg, gif or svg format')
        return False

    if image_filename.startswith('~/'):
        image_filename = image_filename.replace('~/', str(Path.home()) + '/')

    domain = remove_domain_port(domain)
    full_domain = get_full_domain(domain, port)

    handle = nickname + '@' + domain
    person_filename = acct_handle_dir(base_dir, handle) + '.json'
    if not os.path.isfile(person_filename):
        print('person definition not found: ' + person_filename)
        return False
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('Account not found: ' + handle_dir)
        return False

    icon_filename_base = 'icon'
    if image_type in ('avatar', 'icon'):
        icon_filename_base = 'icon'
    else:
        icon_filename_base = 'image'

    media_type = 'image/png'
    icon_filename = icon_filename_base + '.png'
    extensions = get_image_extensions()
    for ext in extensions:
        if image_filename.endswith('.' + ext):
            media_type = get_image_mime_type(image_filename)
            icon_filename = icon_filename_base + '.' + ext

    profile_filename = acct_handle_dir(base_dir, handle) + '/' + icon_filename

    person_json = load_json(person_filename)
    if person_json:
        person_json[icon_filename_base]['mediaType'] = media_type
        person_json[icon_filename_base]['url'] = \
            local_actor_url(http_prefix, nickname, full_domain) + \
            '/' + icon_filename
        save_json(person_json, person_filename)

        cmd = \
            '/usr/bin/convert ' + safe_system_string(image_filename) + \
            ' -size ' + resolution + ' -quality 50 ' + \
            safe_system_string(profile_filename)
        subprocess.call(cmd, shell=True)
        process_meta_data(base_dir, nickname, domain,
                          profile_filename, profile_filename, city,
                          content_license_url)
        return True
    return False


def _account_exists(base_dir: str, nickname: str, domain: str) -> bool:
    """Returns true if the given account exists
    """
    domain = remove_domain_port(domain)
    account_dir = acct_dir(base_dir, nickname, domain)
    return os.path.isdir(account_dir) or \
        os.path.isdir(base_dir + '/deactivated/' + nickname + '@' + domain)


def randomize_actor_images(person_json: {}) -> None:
    """Randomizes the filenames for avatar image and background
    This causes other instances to update their cached avatar image
    """
    person_id = person_json['id']
    url_str = get_url_from_post(person_json['icon']['url'])
    last_part_of_filename = url_str.split('/')[-1]
    existing_extension = last_part_of_filename.split('.')[1]
    # NOTE: these files don't need to have cryptographically
    # secure names
    rand_str = str(randint(10000000000000, 99999999999999))  # nosec
    base_url = person_id.split('/users/')[0]
    nickname = person_json['preferredUsername']
    person_json['icon']['url'] = \
        base_url + '/system/accounts/avatars/' + nickname + \
        '/avatar' + rand_str + '.' + existing_extension
    url_str = get_url_from_post(person_json['image']['url'])
    last_part_of_filename = url_str.split('/')[-1]
    existing_extension = last_part_of_filename.split('.')[1]
    rand_str = str(randint(10000000000000, 99999999999999))  # nosec
    person_json['image']['url'] = \
        base_url + '/system/accounts/headers/' + nickname + \
        '/image' + rand_str + '.' + existing_extension


def get_actor_update_json(actor_json: {}) -> {}:
    """Returns the json for an Person Update
    """
    pub_number, _ = get_status_number()
    manually_approves_followers = actor_json['manuallyApprovesFollowers']
    memorial = False
    if actor_json.get('memorial'):
        memorial = True
    indexable = account_is_indexable(actor_json)
    searchable_by: list[str] = []
    if actor_json.get('searchableBy'):
        if isinstance(actor_json['searchableBy'], list):
            searchable_by = actor_json['searchableBy']
    actor_url = get_url_from_post(actor_json['url'])
    icon_url = get_url_from_post(actor_json['icon']['url'])
    image_url = get_url_from_post(actor_json['image']['url'])
    return {
        '@context': [
            "https://www.w3.org/ns/activitystreams",
            "https://w3id.org/security/v1",
            {
                "manuallyApprovesFollowers": "as:manuallyApprovesFollowers",
                "indexable": "toot:indexable",
                "searchableBy": {
                    "@id": "fedibird:searchableBy",
                    "@type": "@id"
                },
                "memorial": "toot:memorial",
                "toot": "http://joinmastodon.org/ns#",
                "featured":
                {
                    "@id": "toot:featured",
                    "@type": "@id"
                },
                "featuredTags":
                {
                    "@id": "toot:featuredTags",
                    "@type": "@id"
                },
                "alsoKnownAs":
                {
                    "@id": "as:alsoKnownAs",
                    "@type": "@id"
                },
                "movedTo":
                {
                    "@id": "as:movedTo",
                    "@type": "@id"
                },
                "schema": "http://schema.org/",
                "PropertyValue": "schema:PropertyValue",
                "value": "schema:value",
                "IdentityProof": "toot:IdentityProof",
                "discoverable": "toot:discoverable",
                "Device": "toot:Device",
                "Ed25519Signature": "toot:Ed25519Signature",
                "Ed25519Key": "toot:Ed25519Key",
                "Curve25519Key": "toot:Curve25519Key",
                "EncryptedMessage": "toot:EncryptedMessage",
                "publicKeyBase64": "toot:publicKeyBase64",
                "deviceId": "toot:deviceId",
                "claim":
                {
                    "@type": "@id",
                    "@id": "toot:claim"
                },
                "fingerprintKey":
                {
                    "@type": "@id",
                    "@id": "toot:fingerprintKey"
                },
                "identityKey":
                {
                    "@type": "@id",
                    "@id": "toot:identityKey"
                },
                "devices":
                {
                    "@type": "@id",
                    "@id": "toot:devices"
                },
                "messageFranking": "toot:messageFranking",
                "messageType": "toot:messageType",
                "cipherText": "toot:cipherText",
                "suspended": "toot:suspended",
                "focalPoint":
                {
                    "@container": "@list",
                    "@id": "toot:focalPoint"
                }
            }
        ],
        'id': actor_json['id'] + '#updates/' + pub_number,
        'type': 'Update',
        'actor': actor_json['id'],
        'to': ['https://www.w3.org/ns/activitystreams#Public'],
        'cc': [actor_json['id'] + '/followers'],
        'object': {
            'id': actor_json['id'],
            'type': actor_json['type'],
            'icon': {
                'type': 'Image',
                'url': icon_url
            },
            'image': {
                'type': 'Image',
                'url': image_url
            },
            'attachment': actor_json['attachment'],
            'following': actor_json['id'] + '/following',
            'followers': actor_json['id'] + '/followers',
            'inbox': actor_json['id'] + '/inbox',
            'outbox': actor_json['id'] + '/outbox',
            'featured': actor_json['id'] + '/collections/featured',
            'featuredTags': actor_json['id'] + '/collections/tags',
            'preferredUsername': actor_json['preferredUsername'],
            'name': actor_json['name'],
            'summary': actor_json['summary'],
            'url': actor_url,
            'vcard:Address': '',
            'vcard:bday': '',
            'manuallyApprovesFollowers': manually_approves_followers,
            'discoverable': actor_json['discoverable'],
            'memorial': memorial,
            'indexable': indexable,
            'published': actor_json['published'],
            'searchableBy': searchable_by,
            'devices': actor_json['devices'],
            "publicKey": actor_json['publicKey']
        }
    }


def get_actor_move_json(actor_json: {}) -> {}:
    """Returns the json for a Move activity after movedTo has been set
    within the actor
    https://codeberg.org/fediverse/fep/src/branch/main/fep/7628/fep-7628.md
    """
    if not actor_json.get('movedTo'):
        return None
    if '://' not in actor_json['movedTo'] or \
       '.' not in actor_json['movedTo']:
        return None
    if actor_json['movedTo'] == actor_json['id']:
        return None
    pub_number, _ = get_status_number()
    return {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        "id": actor_json['id'] + '#moved/' + pub_number,
        "type": "Move",
        "actor": actor_json['id'],
        "object": actor_json['id'],
        "target": actor_json['movedTo'],
        "to": ['https://www.w3.org/ns/activitystreams#Public'],
        "cc": [actor_json['id'] + '/followers']
    }


def get_default_person_context() -> str:
    """Gets the default actor context
    """
    return {
        'Curve25519Key': 'toot:Curve25519Key',
        'Device': 'toot:Device',
        'Ed25519Key': 'toot:Ed25519Key',
        'Ed25519Signature': 'toot:Ed25519Signature',
        'EncryptedMessage': 'toot:EncryptedMessage',
        'IdentityProof': 'toot:IdentityProof',
        'PropertyValue': 'schema:PropertyValue',
        'alsoKnownAs': {'@id': 'as:alsoKnownAs', '@type': '@id'},
        'cipherText': 'toot:cipherText',
        'claim': {'@id': 'toot:claim', '@type': '@id'},
        'deviceId': 'toot:deviceId',
        'devices': {'@id': 'toot:devices', '@type': '@id'},
        'discoverable': 'toot:discoverable',
        'featured': {'@id': 'toot:featured', '@type': '@id'},
        'featuredTags': {'@id': 'toot:featuredTags', '@type': '@id'},
        'fingerprintKey': {'@id': 'toot:fingerprintKey', '@type': '@id'},
        'focalPoint': {'@container': '@list', '@id': 'toot:focalPoint'},
        'identityKey': {'@id': 'toot:identityKey', '@type': '@id'},
        'manuallyApprovesFollowers': 'as:manuallyApprovesFollowers',
        'indexable': 'toot:indexable',
        'searchableBy': {
            '@id': 'fedibird:searchableBy',
            '@type': '@id'
        },
        'memorial': 'toot:memorial',
        'messageFranking': 'toot:messageFranking',
        'messageType': 'toot:messageType',
        'movedTo': {'@id': 'as:movedTo', '@type': '@id'},
        'publicKeyBase64': 'toot:publicKeyBase64',
        'schema': 'http://schema.org/',
        'suspended': 'toot:suspended',
        'toot': 'http://joinmastodon.org/ns#',
        'value': 'schema:value',
        'hasOccupation': 'schema:hasOccupation',
        'Occupation': 'schema:Occupation',
        'occupationalCategory': 'schema:occupationalCategory',
        'Role': 'schema:Role',
        'WebSite': 'schema:Project',
        'CategoryCode': 'schema:CategoryCode',
        'CategoryCodeSet': 'schema:CategoryCodeSet'
    }


def _create_person_base(base_dir: str, nickname: str, domain: str, port: int,
                        http_prefix: str, save_to_file: bool,
                        manual_follower_approval: bool,
                        group_account: bool,
                        password: str) -> (str, str, {}, {}):
    """Returns the private key, public key, actor and webfinger endpoint
    """
    private_key_pem, public_key_pem = generate_rsa_key()
    webfinger_endpoint = \
        create_webfinger_endpoint(nickname, domain, port,
                                  http_prefix)
    if save_to_file:
        store_webfinger_endpoint(nickname, domain, port,
                                 base_dir, webfinger_endpoint)

    handle = nickname + '@' + domain
    original_domain = domain
    domain = get_full_domain(domain, port)

    person_type = 'Person'
    if group_account:
        person_type = 'Group'
    # Enable follower approval by default
    approve_followers = manual_follower_approval
    person_name = nickname
    person_id = local_actor_url(http_prefix, nickname, domain)
    inbox_str = person_id + '/inbox'
    person_url = http_prefix + '://' + domain + '/@' + person_name
    if nickname == 'inbox':
        # shared inbox
        inbox_str = http_prefix + '://' + domain + '/actor/inbox'
        person_id = http_prefix + '://' + domain + '/actor'
        person_url = http_prefix + '://' + domain + \
            '/about/more?instance_actor=true'
        person_name = original_domain
        approve_followers = True
        person_type = 'Application'
    elif nickname == 'news':
        person_url = http_prefix + '://' + domain + \
            '/about/more?news_actor=true'
        approve_followers = True
        person_type = 'Application'

    # NOTE: these image files don't need to have
    # cryptographically secure names

    image_url = \
        person_id + '/image' + \
        str(randint(10000000000000, 99999999999999)) + '.png'  # nosec

    icon_url = \
        person_id + '/avatar' + \
        str(randint(10000000000000, 99999999999999)) + '.png'  # nosec

    _, published = get_status_number()
    new_person = {
        '@context': [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            get_default_person_context()
        ],
        'published': published,
        'alsoKnownAs': [],
        'attachment': [],
        'devices': person_id + '/collections/devices',
        'endpoints': {
            'id': person_id + '/endpoints',
            'sharedInbox': http_prefix + '://' + domain + '/inbox',
            'offers': person_id + '/offers',
            'wanted': person_id + '/wanted',
            'blocked': person_id + '/blocked',
            'pendingFollowers': person_id + '/pendingFollowers'
        },
        'featured': person_id + '/collections/featured',
        'featuredTags': person_id + '/collections/tags',
        'followers': person_id + '/followers',
        'following': person_id + '/following',
        'tts': person_id + '/speaker',
        'shares': person_id + '/catalog',
        'hasOccupation': [
            {
                '@type': 'Occupation',
                'name': "",
                "occupationLocation": {
                    "@type": "City",
                    "name": "Fediverse"
                },
                'skills': []
            }
        ],
        'availability': None,
        'icon': {
            'mediaType': 'image/png',
            'type': 'Image',
            'url': icon_url
        },
        'id': person_id,
        'image': {
            'mediaType': 'image/png',
            'type': 'Image',
            'url': image_url
        },
        'inbox': inbox_str,
        'manuallyApprovesFollowers': approve_followers,
        'capabilities': {
            'acceptsChatMessages': False,
            'supportsFriendRequests': False
        },
        'discoverable': True,
        'indexable': False,
        'searchableBy': [],
        'memorial': False,
        'hideFollows': False,
        'name': person_name,
        'outbox': person_id + '/outbox',
        'preferredUsername': person_name,
        'summary': '',
        'publicKey': {
            'id': person_id + '#main-key',
            'owner': person_id,
            'publicKeyPem': public_key_pem
        },
        'tag': [],
        'type': person_type,
        'url': person_url,
        'vcard:Address': '',
        'vcard:bday': ''
    }

    # extra fields used only by groups
    if group_account:
        new_person['postingRestrictedToMods'] = False
        new_person['moderators'] = person_id + '/moderators'

    if nickname == 'inbox':
        # fields not needed by the shared inbox
        del new_person['outbox']
        del new_person['icon']
        del new_person['image']
        if new_person.get('skills'):
            del new_person['skills']
        del new_person['shares']
        if new_person.get('roles'):
            del new_person['roles']
        del new_person['tag']
        del new_person['availability']
        del new_person['followers']
        del new_person['following']
        del new_person['attachment']

    if save_to_file:
        # save person to file
        if not os.path.isdir(base_dir):
            os.mkdir(base_dir)
        people_subdir = data_dir(base_dir)
        if not os.path.isdir(people_subdir):
            os.mkdir(people_subdir)
        if not os.path.isdir(people_subdir + '/' + handle):
            os.mkdir(people_subdir + '/' + handle)
        if not os.path.isdir(people_subdir + '/' +
                             handle + '/inbox'):
            os.mkdir(people_subdir + '/' + handle + '/inbox')
        if not os.path.isdir(people_subdir + '/' +
                             handle + '/outbox'):
            os.mkdir(people_subdir + '/' + handle + '/outbox')
        if not os.path.isdir(people_subdir + '/' +
                             handle + '/queue'):
            os.mkdir(people_subdir + '/' + handle + '/queue')
        filename = people_subdir + '/' + handle + '.json'
        save_json(new_person, filename)

        # save to cache
        if not os.path.isdir(base_dir + '/cache'):
            os.mkdir(base_dir + '/cache')
        if not os.path.isdir(base_dir + '/cache/actors'):
            os.mkdir(base_dir + '/cache/actors')
        cache_filename = base_dir + '/cache/actors/' + \
            new_person['id'].replace('/', '#') + '.json'
        save_json(new_person, cache_filename)

        # save the private key
        private_keys_subdir = '/keys/private'
        if not os.path.isdir(base_dir + '/keys'):
            os.mkdir(base_dir + '/keys')
        if not os.path.isdir(base_dir + private_keys_subdir):
            os.mkdir(base_dir + private_keys_subdir)
        filename = base_dir + private_keys_subdir + '/' + handle + '.key'
        try:
            with open(filename, 'w+', encoding='utf-8') as fp_text:
                print(private_key_pem, file=fp_text)
        except OSError:
            print('EX: _create_person_base unable to save ' + filename)

        # save the public key
        public_keys_subdir = '/keys/public'
        if not os.path.isdir(base_dir + public_keys_subdir):
            os.mkdir(base_dir + public_keys_subdir)
        filename = base_dir + public_keys_subdir + '/' + handle + '.pem'
        try:
            with open(filename, 'w+', encoding='utf-8') as fp_text:
                print(public_key_pem, file=fp_text)
        except OSError:
            print('EX: _create_person_base unable to save 2 ' + filename)

        if password:
            password = remove_eol(password).strip()
            store_basic_credentials(base_dir, nickname, password)

    return private_key_pem, public_key_pem, new_person, webfinger_endpoint


def register_account(base_dir: str, http_prefix: str, domain: str, port: int,
                     nickname: str, password: str,
                     manual_follower_approval: bool) -> bool:
    """Registers a new account from the web interface
    """
    if _account_exists(base_dir, nickname, domain):
        return False
    if not valid_nickname(domain, nickname):
        print('REGISTER: Nickname ' + nickname + ' is invalid')
        return False
    if len(password) < 8:
        print('REGISTER: Password should be at least 8 characters')
        return False
    (private_key_pem, _,
     _, _) = create_person(base_dir, nickname,
                           domain, port,
                           http_prefix, True,
                           manual_follower_approval,
                           password)
    if private_key_pem:
        return True
    return False


def create_group(base_dir: str, nickname: str, domain: str, port: int,
                 http_prefix: str, save_to_file: bool,
                 password: str) -> (str, str, {}, {}):
    """Returns a group
    """
    (private_key_pem, public_key_pem,
     new_person, webfinger_endpoint) = create_person(base_dir, nickname,
                                                     domain, port,
                                                     http_prefix, save_to_file,
                                                     False, password, True)

    return private_key_pem, public_key_pem, new_person, webfinger_endpoint


def clear_person_qrcodes(base_dir: str) -> None:
    """Clears qrcodes for all accounts
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if '@' not in handle:
                continue
            nickname = handle.split('@')[0]
            domain = handle.split('@')[1]
            qrcode_filename = \
                acct_dir(base_dir, nickname, domain) + '/qrcode.png'
            if os.path.isfile(qrcode_filename):
                try:
                    os.remove(qrcode_filename)
                except OSError:
                    pass
            if os.path.isfile(qrcode_filename + '.etag'):
                try:
                    os.remove(qrcode_filename + '.etag')
                except OSError:
                    pass
        break


def save_person_qrcode(base_dir: str,
                       nickname: str, domain: str, qrcode_domain: str,
                       port: int, scale=6) -> None:
    """Saves a qrcode image for the handle of the person
    This helps to transfer onion or i2p handles to a mobile device
    """
    qrcode_filename = acct_dir(base_dir, nickname, domain) + '/qrcode.png'
    if os.path.isfile(qrcode_filename):
        return
    handle = get_full_domain('@' + nickname + '@' + qrcode_domain, port)
    url = pyqrcode.create(handle)
    try:
        url.png(qrcode_filename, scale)
    except ModuleNotFoundError:
        print('EX: pyqrcode png module not found')


def create_person(base_dir: str, nickname: str, domain: str, port: int,
                  http_prefix: str, save_to_file: bool,
                  manual_follower_approval: bool,
                  password: str,
                  group_account: bool = False) -> (str, str, {}, {}):
    """Returns the private key, public key, actor and webfinger endpoint
    """
    if not valid_nickname(domain, nickname):
        return None, None, None, None

    # If a config.json file doesn't exist then don't decrement
    # remaining registrations counter
    if nickname != 'news':
        remaining_config_exists = \
            get_config_param(base_dir, 'registrationsRemaining')
        if remaining_config_exists:
            registrations_remaining = int(remaining_config_exists)
            if registrations_remaining <= 0:
                return None, None, None, None
    else:
        dir_str = data_dir(base_dir)
        if os.path.isdir(dir_str + '/news@' + domain):
            # news account already exists
            return None, None, None, None

    manual_follower = manual_follower_approval

    (private_key_pem, public_key_pem,
     new_person, webfinger_endpoint) = _create_person_base(base_dir, nickname,
                                                           domain, port,
                                                           http_prefix,
                                                           save_to_file,
                                                           manual_follower,
                                                           group_account,
                                                           password)
    if not get_config_param(base_dir, 'admin'):
        if nickname != 'news':
            # print(nickname+' becomes the instance admin and a moderator')
            set_config_param(base_dir, 'admin', nickname)
            set_role(base_dir, nickname, domain, 'admin')
            set_role(base_dir, nickname, domain, 'moderator')
            set_role(base_dir, nickname, domain, 'editor')

    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str):
        os.mkdir(dir_str)
    account_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(account_dir):
        os.mkdir(account_dir)

    if manual_follower_approval:
        follow_dms_filename = \
            acct_dir(base_dir, nickname, domain) + '/.followDMs'
        try:
            with open(follow_dms_filename, 'w+', encoding='utf-8') as fp_foll:
                fp_foll.write('\n')
        except OSError:
            print('EX: create_person unable to write ' + follow_dms_filename)

    # notify when posts are liked
    if nickname != 'news':
        notify_likes_filename = \
            acct_dir(base_dir, nickname, domain) + '/.notifyLikes'
        try:
            with open(notify_likes_filename, 'w+', encoding='utf-8') as fp_lik:
                fp_lik.write('\n')
        except OSError:
            print('EX: create_person unable to write 2 ' +
                  notify_likes_filename)

    # notify when posts have emoji reactions
    if nickname != 'news':
        notify_reactions_filename = \
            acct_dir(base_dir, nickname, domain) + '/.notifyReactions'
        try:
            with open(notify_reactions_filename, 'w+',
                      encoding='utf-8') as fp_notify:
                fp_notify.write('\n')
        except OSError:
            print('EX: create_person unable to write 3 ' +
                  notify_reactions_filename)

    theme = get_config_param(base_dir, 'theme')
    if not theme:
        theme = 'default'

    if nickname != 'news':
        if os.path.isfile(base_dir + '/img/default-avatar.png'):
            account_dir = acct_dir(base_dir, nickname, domain)
            copyfile(base_dir + '/img/default-avatar.png',
                     account_dir + '/avatar.png')
    else:
        news_avatar = base_dir + '/theme/' + theme + '/icons/avatar_news.png'
        if os.path.isfile(news_avatar):
            account_dir = acct_dir(base_dir, nickname, domain)
            copyfile(news_avatar, account_dir + '/avatar.png')

    default_profile_image_filename = base_dir + '/theme/default/image.png'
    if theme:
        if os.path.isfile(base_dir + '/theme/' + theme + '/image.png'):
            default_profile_image_filename = \
                base_dir + '/theme/' + theme + '/image.png'
    if os.path.isfile(default_profile_image_filename):
        account_dir = acct_dir(base_dir, nickname, domain)
        copyfile(default_profile_image_filename, account_dir + '/image.png')
    default_banner_filename = base_dir + '/theme/default/banner.png'
    if theme:
        if os.path.isfile(base_dir + '/theme/' + theme + '/banner.png'):
            default_banner_filename = \
                base_dir + '/theme/' + theme + '/banner.png'
    if os.path.isfile(default_banner_filename):
        account_dir = acct_dir(base_dir, nickname, domain)
        copyfile(default_banner_filename, account_dir + '/banner.png')
    if nickname != 'news' and remaining_config_exists:
        registrations_remaining -= 1
        set_config_param(base_dir, 'registrationsRemaining',
                         str(registrations_remaining))
    save_person_qrcode(base_dir, nickname, domain, domain, port)
    return private_key_pem, public_key_pem, new_person, webfinger_endpoint


def create_shared_inbox(base_dir: str, nickname: str, domain: str, port: int,
                        http_prefix: str) -> (str, str, {}, {}):
    """Generates the shared inbox
    """
    return _create_person_base(base_dir, nickname, domain, port, http_prefix,
                               True, True, False, None)


def create_news_inbox(base_dir: str, domain: str, port: int,
                      http_prefix: str) -> (str, str, {}, {}):
    """Generates the news inbox
    """
    return create_person(base_dir, 'news', domain, port,
                         http_prefix, True, True, None)


def person_upgrade_actor(base_dir: str, person_json: {},
                         filename: str) -> None:
    """Alter the actor to add any new properties
    """
    update_actor = False
    if not os.path.isfile(filename):
        print('WARN: actor file not found ' + filename)
        return
    if not person_json:
        person_json = load_json(filename)

    # add extra group fields
    if person_json.get('type') and person_json.get('id'):
        if person_json['type'] == 'Group':
            person_json['postingRestrictedToMods'] = False
            person_id = person_json['id']
            person_json['moderators'] = person_id + '/moderators'
            update_actor = True

    if 'capabilities' not in person_json:
        person_json['capabilities'] = {
            'acceptsChatMessages': False,
            'supportsFriendRequests': False
        }
        update_actor = True
    else:
        if 'acceptsChatMessages' not in person_json['capabilities']:
            person_json['capabilities']['acceptsChatMessages'] = False
            update_actor = True
        if 'supportsFriendRequests' not in person_json['capabilities']:
            person_json['capabilities']['supportsFriendRequests'] = False
            update_actor = True

    if 'memorial' not in person_json:
        person_json['memorial'] = False
        update_actor = True

    if 'hideFollows' not in person_json:
        person_json['hideFollows'] = False
        update_actor = True

    if 'indexable' not in person_json:
        person_json['indexable'] = False
        update_actor = True

    if 'vcard:Address' not in person_json:
        person_json['vcard:Address'] = ''
        update_actor = True

    if 'vcard:bday' not in person_json:
        person_json['vcard:bday'] = ''
        update_actor = True

    if 'searchableBy' not in person_json:
        person_json['searchableBy']: list[str] = []
        update_actor = True

    # add a speaker endpoint
    if not person_json.get('tts'):
        person_json['tts'] = person_json['id'] + '/speaker'
        update_actor = True

    if not person_json.get('published'):
        _, published = get_status_number()
        person_json['published'] = published
        update_actor = True

    if person_json.get('endpoints'):
        if not person_json['endpoints'].get('pendingFollowers'):
            person_json['endpoints']['pendingFollowers'] = \
                person_json['id'] + '/pendingFollowers'
            update_actor = True
        if not person_json['endpoints'].get('blocked'):
            person_json['endpoints']['blocked'] = \
                person_json['id'] + '/blocked'
            update_actor = True
        if not person_json['endpoints'].get('offers'):
            person_json['endpoints']['offers'] = person_json['id'] + '/offers'
            update_actor = True
        if not person_json['endpoints'].get('wanted'):
            person_json['endpoints']['wanted'] = person_json['id'] + '/wanted'
            update_actor = True

    if person_json.get('shares'):
        if person_json['shares'].endswith('/shares'):
            person_json['shares'] = person_json['id'] + '/catalog'
            update_actor = True

    occupation_name = ''
    if person_json.get('occupationName'):
        occupation_name = person_json['occupationName']
        del person_json['occupationName']
        update_actor = True
    if person_json.get('occupation'):
        occupation_name = person_json['occupation']
        del person_json['occupation']
        update_actor = True

    # if the older skills format is being used then switch
    # to the new one
    if not person_json.get('hasOccupation'):
        person_json['hasOccupation'] = [{
            '@type': 'Occupation',
            'name': occupation_name,
            "occupationLocation": {
                "@type": "City",
                "name": "Fediverse"
            },
            'skills': []
        }]
        update_actor = True

    # remove the old skills format
    if person_json.get('skills'):
        del person_json['skills']
        update_actor = True

    # if the older roles format is being used then switch
    # to the new one
    if person_json.get('affiliation'):
        del person_json['affiliation']
        update_actor = True

    if not isinstance(person_json['hasOccupation'], list):
        person_json['hasOccupation'] = [{
            '@type': 'Occupation',
            'name': occupation_name,
            'occupationLocation': {
                '@type': 'City',
                'name': 'Fediverse'
            },
            'skills': []
        }]
        update_actor = True
    else:
        # add location if it is missing
        for index, _ in enumerate(person_json['hasOccupation']):
            oc_item = person_json['hasOccupation'][index]
            if oc_item.get('hasOccupation'):
                oc_item = oc_item['hasOccupation']
            if oc_item.get('location'):
                del oc_item['location']
                update_actor = True
            if not oc_item.get('occupationLocation'):
                oc_item['occupationLocation'] = {
                    "@type": "City",
                    "name": "Fediverse"
                }
                update_actor = True
            else:
                if oc_item['occupationLocation']['@type'] != 'City':
                    oc_item['occupationLocation'] = {
                        "@type": "City",
                        "name": "Fediverse"
                    }
                    update_actor = True

    # if no roles are defined then ensure that the admin
    # roles are configured
    roles_list = get_actor_roles_list(person_json)
    if not roles_list:
        admin_name = get_config_param(base_dir, 'admin')
        if person_json['id'].endswith('/users/' + admin_name):
            roles_list = ["admin", "moderator", "editor"]
            actor_roles_from_list(person_json, roles_list)
            update_actor = True

    # remove the old roles format
    if person_json.get('roles'):
        del person_json['roles']
        update_actor = True

    if update_actor:
        person_json['@context'] = [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            get_default_person_context()
        ]

        save_json(person_json, filename)

        # also update the actor within the cache
        actor_cache_filename = \
            data_dir(base_dir) + '/cache/actors/' + \
            person_json['id'].replace('/', '#') + '.json'
        if os.path.isfile(actor_cache_filename):
            save_json(person_json, actor_cache_filename)

        # update domain/@nickname in actors cache
        actor_cache_filename = \
            data_dir(base_dir) + '/cache/actors/' + \
            replace_users_with_at(person_json['id']).replace('/', '#') + \
            '.json'
        if os.path.isfile(actor_cache_filename):
            save_json(person_json, actor_cache_filename)


def add_alternate_domains(actor_json: {}, domain: str,
                          onion_domain: str, i2p_domain: str) -> None:
    """Adds alternate onion and/or i2p domains to alsoKnownAs
    """
    if not onion_domain and not i2p_domain:
        return
    if not actor_json.get('id'):
        return
    if domain not in actor_json['id']:
        return
    nickname = get_nickname_from_actor(actor_json['id'])
    if not nickname:
        return
    if 'alsoKnownAs' not in actor_json:
        actor_json['alsoKnownAs']: list[str] = []
    if onion_domain:
        onion_actor = 'http://' + onion_domain + '/users/' + nickname
        if onion_actor not in actor_json['alsoKnownAs']:
            actor_json['alsoKnownAs'].append(onion_actor)
    if i2p_domain:
        i2p_actor = 'http://' + i2p_domain + '/users/' + nickname
        if i2p_actor not in actor_json['alsoKnownAs']:
            actor_json['alsoKnownAs'].append(i2p_actor)


def person_lookup(domain: str, path: str, base_dir: str) -> {}:
    """Lookup the person for an given nickname
    """
    if path.endswith('#/publicKey'):
        path = path.replace('#/publicKey', '')
    elif path.endswith('/main-key'):
        path = path.replace('/main-key', '')
    elif path.endswith('#main-key'):
        path = path.replace('#main-key', '')
    # is this a shared inbox lookup?
    is_shared_inbox = False
    if path in ('/inbox', '/users/inbox', '/sharedInbox'):
        # shared inbox actor on @domain@domain
        path = '/users/inbox'
        is_shared_inbox = True
    else:
        not_person_lookup = ('/inbox', '/outbox', '/outboxarchive',
                             '/followers', '/following', '/featured',
                             '.png', '.jpg', '.gif', '.svg', '.mpv')
        for ending in not_person_lookup:
            if path.endswith(ending):
                return None
    nickname = None
    if path.startswith('/users/'):
        nickname = path.replace('/users/', '', 1)
    if path.startswith('/@'):
        if '/@/' not in path:
            nickname = path.replace('/@', '', 1)
    if not nickname:
        return None
    if not is_shared_inbox and not valid_nickname(domain, nickname):
        return None
    domain = remove_domain_port(domain)
    handle = nickname + '@' + domain
    filename = acct_handle_dir(base_dir, handle) + '.json'
    if not os.path.isfile(filename):
        return None
    person_json = load_json(filename)
    if not is_shared_inbox:
        person_upgrade_actor(base_dir, person_json, filename)
    # if not person_json:
    #     person_json={"user": "unknown"}
    return person_json


def person_box_json(recent_posts_cache: {},
                    base_dir: str, domain: str, port: int, path: str,
                    http_prefix: str, no_of_items: int, boxname: str,
                    authorized: bool,
                    newswire_votes_threshold: int, positive_voting: bool,
                    voting_time_mins: int) -> {}:
    """Obtain the inbox/outbox/moderation feed for the given person
    """
    if boxname not in ('inbox', 'dm', 'tlreplies', 'tlmedia', 'tlblogs',
                       'tlnews', 'tlfeatures', 'outbox', 'moderation',
                       'tlbookmarks', 'bookmarks'):
        print('ERROR: person_box_json invalid box name ' + boxname)
        return None

    if not '/' + boxname in path:
        return None

    # Only show the header by default
    header_only = True

    # first post in the timeline
    first_post_id = ''
    if ';firstpost=' in path:
        first_post_id = \
            path.split(';firstpost=')[1]
        if ';' in first_post_id:
            first_post_id = first_post_id.split(';')[0]
        first_post_id = \
            first_post_id.replace('--', '/')

    # handle page numbers
    page_number = None
    if '?page=' in path:
        page_number = path.split('?page=')[1]
        if ';' in page_number:
            page_number = page_number.split(';')[0]
        if len(page_number) > 5:
            page_number = 1
        if page_number == 'true':
            page_number = 1
        else:
            try:
                page_number = int(page_number)
            except BaseException:
                print('EX: person_box_json unable to convert to int ' +
                      str(page_number))
        path = path.split('?page=')[0]
        header_only = False

    if not path.endswith('/' + boxname):
        return None
    nickname = None
    if path.startswith('/users/'):
        nickname = path.replace('/users/', '', 1).replace('/' + boxname, '')
    if path.startswith('/@'):
        if '/@/' not in path:
            nickname = path.replace('/@', '', 1).replace('/' + boxname, '')
    if not nickname:
        return None
    if not valid_nickname(domain, nickname):
        return None
    if boxname == 'inbox':
        return create_inbox(recent_posts_cache,
                            base_dir, nickname, domain, port,
                            http_prefix,
                            no_of_items, header_only, page_number,
                            first_post_id)
    if boxname == 'dm':
        return create_dm_timeline(recent_posts_cache,
                                  base_dir, nickname, domain, port,
                                  http_prefix,
                                  no_of_items, header_only, page_number,
                                  first_post_id)
    if boxname in ('tlbookmarks', 'bookmarks'):
        return create_bookmarks_timeline(base_dir, nickname, domain,
                                         port, http_prefix,
                                         no_of_items, header_only,
                                         page_number)
    if boxname == 'tlreplies':
        return create_replies_timeline(recent_posts_cache,
                                       base_dir, nickname, domain,
                                       port, http_prefix,
                                       no_of_items, header_only,
                                       page_number,
                                       first_post_id)
    if boxname == 'tlmedia':
        return create_media_timeline(base_dir, nickname, domain, port,
                                     http_prefix, no_of_items, header_only,
                                     page_number)
    if boxname == 'tlnews':
        return create_news_timeline(base_dir, domain, port,
                                    http_prefix, no_of_items, header_only,
                                    newswire_votes_threshold, positive_voting,
                                    voting_time_mins, page_number)
    if boxname == 'tlfeatures':
        return create_features_timeline(base_dir, nickname, domain, port,
                                        http_prefix, no_of_items, header_only,
                                        page_number)
    if boxname == 'tlblogs':
        return create_blogs_timeline(base_dir, nickname, domain, port,
                                     http_prefix, no_of_items, header_only,
                                     page_number)
    if boxname == 'outbox':
        return create_outbox(base_dir, nickname, domain, port,
                             http_prefix,
                             no_of_items, header_only, authorized,
                             page_number)
    if boxname == 'moderation':
        return create_moderation(base_dir, nickname, domain, port,
                                 http_prefix,
                                 no_of_items, header_only,
                                 page_number)
    return None


def set_display_nickname(base_dir: str, nickname: str, domain: str,
                         display_name: str) -> bool:
    """Sets the display name for an account
    """
    if len(display_name) > 32:
        return False
    handle = nickname + '@' + domain
    filename = acct_handle_dir(base_dir, handle) + '.json'
    if not os.path.isfile(filename):
        return False

    person_json = load_json(filename)
    if not person_json:
        return False
    person_json['name'] = display_name
    save_json(person_json, filename)
    return True


def set_bio(base_dir: str, nickname: str, domain: str, bio: str) -> bool:
    """Only used within tests
    """
    if len(bio) > 32:
        return False
    handle = nickname + '@' + domain
    filename = acct_handle_dir(base_dir, handle) + '.json'
    if not os.path.isfile(filename):
        return False

    person_json = load_json(filename)
    if not person_json:
        return False
    if not person_json.get('summary'):
        return False
    person_json['summary'] = bio

    save_json(person_json, filename)
    return True


def _unsuspend_media_for_account(base_dir: str, account_dir: str) -> None:
    """Unsuspends all media for an account
    """
    account_media_log_filename = account_dir + '/media_log.txt'
    if not os.path.isfile(account_media_log_filename):
        return

    media_log = []
    try:
        with open(account_media_log_filename, 'r',
                  encoding='utf-8') as fp_log:
            media_log = fp_log.read().split('\n')
    except OSError:
        print('EX: suspend unable to read media log for ' + account_dir)

    for filename in media_log:
        media_filename = base_dir + filename
        if not os.path.isfile(media_filename + '.suspended'):
            continue
        try:
            os.rename(media_filename + '.suspended', media_filename)
        except OSError:
            print('EX: unable to unsuspend media ' + media_filename)


def reenable_account(base_dir: str, nickname: str, domain: str) -> None:
    """Removes an account suspension
    """
    suspended_filename = data_dir(base_dir) + '/suspended.txt'
    if os.path.isfile(suspended_filename):
        lines: list[str] = []
        try:
            with open(suspended_filename, 'r', encoding='utf-8') as fp_sus:
                lines = fp_sus.readlines()
        except OSError:
            print('EX: reenable_account unable to read ' + suspended_filename)
        try:
            with open(suspended_filename, 'w+', encoding='utf-8') as fp_sus:
                for suspended in lines:
                    if suspended.strip('\n').strip('\r') != nickname:
                        fp_sus.write(suspended)
        except OSError as ex:
            print('EX: reenable_account unable to save ' +
                  suspended_filename + ' ' + str(ex))
        account_dir = acct_dir(base_dir, nickname, domain)
        _unsuspend_media_for_account(base_dir, account_dir)


def _suspend_media_for_account(base_dir: str, account_dir: str) -> None:
    """Suspends all media for an account
    """
    account_media_log_filename = account_dir + '/media_log.txt'
    if not os.path.isfile(account_media_log_filename):
        return

    media_log = []
    try:
        with open(account_media_log_filename, 'r',
                  encoding='utf-8') as fp_log:
            media_log = fp_log.read().split('\n')
    except OSError:
        print('EX: suspend unable to read media log for ' + account_dir)

    for filename in media_log:
        media_filename = base_dir + filename
        if not os.path.isfile(media_filename):
            continue
        try:
            os.rename(media_filename, media_filename + '.suspended')
        except OSError:
            print('EX: unable to suspend media ' + media_filename)


def suspend_account(base_dir: str, nickname: str, domain: str) -> None:
    """Suspends the given account
    """
    # Don't suspend the admin
    admin_nickname = get_config_param(base_dir, 'admin')
    if not admin_nickname:
        return
    if nickname == admin_nickname:
        return

    # Don't suspend moderators
    moderators_file = data_dir(base_dir) + '/moderators.txt'
    if os.path.isfile(moderators_file):
        try:
            with open(moderators_file, 'r', encoding='utf-8') as fp_mod:
                lines = fp_mod.readlines()
        except OSError:
            print('EX: suspend_account unable too read ' + moderators_file)
        for moderator in lines:
            if moderator.strip('\n').strip('\r') == nickname:
                return

    account_dir = acct_dir(base_dir, nickname, domain)
    salt_filename = account_dir + '/.salt'
    if os.path.isfile(salt_filename):
        try:
            os.remove(salt_filename)
        except OSError:
            print('EX: suspend_account unable to delete ' + salt_filename)
    token_filename = acct_dir(base_dir, nickname, domain) + '/.token'
    if os.path.isfile(token_filename):
        try:
            os.remove(token_filename)
        except OSError:
            print('EX: suspend_account unable to delete 2 ' + token_filename)

    suspended_filename = data_dir(base_dir) + '/suspended.txt'
    if os.path.isfile(suspended_filename):
        try:
            with open(suspended_filename, 'r', encoding='utf-8') as fp_sus:
                lines = fp_sus.readlines()
        except OSError:
            print('EX: suspend_account unable to read 2 ' + suspended_filename)
        for suspended in lines:
            if suspended.strip('\n').strip('\r') == nickname:
                return
        try:
            with open(suspended_filename, 'a+', encoding='utf-8') as fp_sus:
                fp_sus.write(nickname + '\n')
        except OSError:
            print('EX: suspend_account unable to append ' + suspended_filename)
    else:
        try:
            with open(suspended_filename, 'w+', encoding='utf-8') as fp_sus:
                fp_sus.write(nickname + '\n')
        except OSError:
            print('EX: suspend_account unable to write ' + suspended_filename)
    _suspend_media_for_account(base_dir, account_dir)


def can_remove_post(base_dir: str,
                    domain: str, port: int, post_id: str) -> bool:
    """Returns true if the given post can be removed
    """
    if not contains_statuses(post_id):
        return False

    domain_full = get_full_domain(domain, port)

    # is the post by the admin?
    admin_nickname = get_config_param(base_dir, 'admin')
    if not admin_nickname:
        return False
    if domain_full + '/users/' + admin_nickname + '/' in post_id:
        return False

    # is the post by a moderator?
    moderators_file = data_dir(base_dir) + '/moderators.txt'
    if os.path.isfile(moderators_file):
        lines: list[str] = []
        try:
            with open(moderators_file, 'r', encoding='utf-8') as fp_mod:
                lines = fp_mod.readlines()
        except OSError:
            print('EX: can_remove_post unable to read ' + moderators_file)
        for moderator in lines:
            if domain_full + '/users/' + \
               moderator.strip('\n') + '/' in post_id:
                return False
    return True


def _remove_tags_for_nickname(base_dir: str, nickname: str,
                              domain: str, port: int) -> None:
    """Removes tags for a nickname
    """
    if not os.path.isdir(base_dir + '/tags'):
        return
    domain_full = get_full_domain(domain, port)
    match_str = domain_full + '/users/' + nickname + '/'
    directory = os.fsencode(base_dir + '/tags/')
    for fname in os.scandir(directory):
        filename = os.fsdecode(fname.name)
        if not filename.endswith(".txt"):
            continue
        try:
            tag_filename = os.path.join(base_dir + '/tags/', filename)
        except OSError:
            print('EX: _remove_tags_for_nickname unable to join ' +
                  base_dir + '/tags/ ' + str(filename))
            continue
        if not os.path.isfile(tag_filename):
            continue
        if not text_in_file(match_str, tag_filename):
            continue
        lines: list[str] = []
        try:
            with open(tag_filename, 'r', encoding='utf-8') as fp_tag:
                lines = fp_tag.readlines()
        except OSError:
            print('EX: _remove_tags_for_nickname unable to read ' +
                  tag_filename)
        try:
            with open(tag_filename, 'w+', encoding='utf-8') as fp_tag:
                for tagline in lines:
                    if match_str not in tagline:
                        fp_tag.write(tagline)
        except OSError:
            print('EX: _remove_tags_for_nickname unable to write ' +
                  tag_filename)


def _remove_account_media(base_dir: str, nickname: str, domain: str) -> None:
    """Removes media for an account
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    account_media_log_filename = account_dir + '/media_log.txt'

    media_log = []
    if os.path.isfile(account_media_log_filename):
        try:
            with open(account_media_log_filename, 'r',
                      encoding='utf-8') as fp_log:
                media_log = fp_log.read().split('\n')
        except OSError:
            print('EX: remove unable to read media log for ' + nickname)

    for filename in media_log:
        media_filename = base_dir + filename
        if not os.path.isfile(media_filename):
            continue
        try:
            os.remove(media_filename)
        except OSError:
            print('EX: unable to remove media ' + media_filename)


def remove_account(base_dir: str, nickname: str,
                   domain: str, port: int) -> bool:
    """Removes an account
    """
    # Don't remove the admin
    admin_nickname = get_config_param(base_dir, 'admin')
    if not admin_nickname:
        return False
    if nickname == admin_nickname:
        return False

    # Don't remove moderators
    moderators_file = data_dir(base_dir) + '/moderators.txt'
    if os.path.isfile(moderators_file):
        lines: list[str] = []
        try:
            with open(moderators_file, 'r', encoding='utf-8') as fp_mod:
                lines = fp_mod.readlines()
        except OSError:
            print('EX: remove_account unable to read ' + moderators_file)
        for moderator in lines:
            if moderator.strip('\n') == nickname:
                return False

    reenable_account(base_dir, nickname, domain)
    _remove_account_media(base_dir, nickname, domain)
    handle = nickname + '@' + domain
    remove_password(base_dir, nickname)
    _remove_tags_for_nickname(base_dir, nickname, domain, port)
    if os.path.isdir(base_dir + '/deactivated/' + handle):
        shutil.rmtree(base_dir + '/deactivated/' + handle,
                      ignore_errors=False)
    handle_dir = acct_handle_dir(base_dir, handle)
    if os.path.isdir(handle_dir):
        shutil.rmtree(handle_dir, ignore_errors=False)
    if os.path.isfile(handle_dir + '.json'):
        try:
            os.remove(handle_dir + '.json')
        except OSError:
            print('EX: remove_account unable to delete ' +
                  handle_dir + '.json')
    if os.path.isfile(base_dir + '/wfendpoints/' + handle + '.json'):
        try:
            os.remove(base_dir + '/wfendpoints/' + handle + '.json')
        except OSError:
            print('EX: remove_account unable to delete ' +
                  base_dir + '/wfendpoints/' + handle + '.json')
    if os.path.isfile(base_dir + '/keys/private/' + handle + '.key'):
        try:
            os.remove(base_dir + '/keys/private/' + handle + '.key')
        except OSError:
            print('EX: remove_account unable to delete ' +
                  base_dir + '/keys/private/' + handle + '.key')
    if os.path.isfile(base_dir + '/keys/public/' + handle + '.pem'):
        try:
            os.remove(base_dir + '/keys/public/' + handle + '.pem')
        except OSError:
            print('EX: remove_account unable to delete ' +
                  base_dir + '/keys/public/' + handle + '.pem')
    if os.path.isdir(base_dir + '/sharefiles/' + nickname):
        shutil.rmtree(base_dir + '/sharefiles/' + nickname,
                      ignore_errors=False)
    if os.path.isfile(base_dir + '/wfdeactivated/' + handle + '.json'):
        try:
            os.remove(base_dir + '/wfdeactivated/' + handle + '.json')
        except OSError:
            print('EX: remove_account unable to delete ' +
                  base_dir + '/wfdeactivated/' + handle + '.json')
    if os.path.isdir(base_dir + '/sharefilesdeactivated/' + nickname):
        shutil.rmtree(base_dir + '/sharefilesdeactivated/' + nickname,
                      ignore_errors=False)

    refresh_newswire(base_dir)

    return True


def deactivate_account(base_dir: str, nickname: str, domain: str) -> bool:
    """Makes an account temporarily unavailable
    """
    handle = nickname + '@' + domain

    account_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(account_dir):
        return False
    deactivated_dir = base_dir + '/deactivated'
    if not os.path.isdir(deactivated_dir):
        os.mkdir(deactivated_dir)
    shutil.move(account_dir, deactivated_dir + '/' + handle)

    if os.path.isfile(base_dir + '/wfendpoints/' + handle + '.json'):
        deactivated_webfinger_dir = base_dir + '/wfdeactivated'
        if not os.path.isdir(deactivated_webfinger_dir):
            os.mkdir(deactivated_webfinger_dir)
        shutil.move(base_dir + '/wfendpoints/' + handle + '.json',
                    deactivated_webfinger_dir + '/' + handle + '.json')

    if os.path.isdir(base_dir + '/sharefiles/' + nickname):
        deactivated_sharefiles_dir = base_dir + '/sharefilesdeactivated'
        if not os.path.isdir(deactivated_sharefiles_dir):
            os.mkdir(deactivated_sharefiles_dir)
        shutil.move(base_dir + '/sharefiles/' + nickname,
                    deactivated_sharefiles_dir + '/' + nickname)

    refresh_newswire(base_dir)

    return os.path.isdir(deactivated_dir + '/' + nickname + '@' + domain)


def activate_account2(base_dir: str, nickname: str, domain: str) -> None:
    """Makes a deactivated account available
    """
    handle = nickname + '@' + domain

    deactivated_dir = base_dir + '/deactivated'
    deactivated_account_dir = deactivated_dir + '/' + handle
    if os.path.isdir(deactivated_account_dir):
        account_dir = acct_handle_dir(base_dir, handle)
        if not os.path.isdir(account_dir):
            shutil.move(deactivated_account_dir, account_dir)

    deactivated_webfinger_dir = base_dir + '/wfdeactivated'
    if os.path.isfile(deactivated_webfinger_dir + '/' + handle + '.json'):
        shutil.move(deactivated_webfinger_dir + '/' + handle + '.json',
                    base_dir + '/wfendpoints/' + handle + '.json')

    deactivated_sharefiles_dir = base_dir + '/sharefilesdeactivated'
    if os.path.isdir(deactivated_sharefiles_dir + '/' + nickname):
        if not os.path.isdir(base_dir + '/sharefiles/' + nickname):
            shutil.move(deactivated_sharefiles_dir + '/' + nickname,
                        base_dir + '/sharefiles/' + nickname)

    refresh_newswire(base_dir)


def is_person_snoozed(base_dir: str, nickname: str, domain: str,
                      snooze_actor: str) -> bool:
    """Returns true if the given actor is snoozed
    """
    snoozed_filename = acct_dir(base_dir, nickname, domain) + '/snoozed.txt'
    if not os.path.isfile(snoozed_filename):
        return False
    if not text_in_file(snooze_actor + ' ', snoozed_filename):
        return False
    # remove the snooze entry if it has timed out
    replace_str = None
    try:
        with open(snoozed_filename, 'r', encoding='utf-8') as fp_snoozed:
            for line in fp_snoozed:
                # is this the entry for the actor?
                if line.startswith(snooze_actor + ' '):
                    snoozed_time_str1 = line.split(' ')[1]
                    snoozed_time_str = remove_eol(snoozed_time_str1)
                    # is there a time appended?
                    if snoozed_time_str.isdigit():
                        snoozed_time = int(snoozed_time_str)
                        curr_time = int(time.time())
                        # has the snooze timed out?
                        if int(curr_time - snoozed_time) > 60 * 60 * 24:
                            replace_str = line
                    else:
                        replace_str = line
                    break
    except OSError:
        print('EX: is_person_snoozed unable to read ' + snoozed_filename)
    if replace_str:
        content = None
        try:
            with open(snoozed_filename, 'r', encoding='utf-8') as fp_snoozed:
                content = fp_snoozed.read().replace(replace_str, '')
        except OSError:
            print('EX: is_person_snoozed unable to read 2 ' + snoozed_filename)
        if content:
            try:
                with open(snoozed_filename, 'w+',
                          encoding='utf-8') as fp_snooze:
                    fp_snooze.write(content)
            except OSError:
                print('EX: is_person_snoozed unable to write ' +
                      snoozed_filename)

    if text_in_file(snooze_actor + ' ', snoozed_filename):
        return True
    return False


def person_snooze(base_dir: str, nickname: str, domain: str,
                  snooze_actor: str) -> None:
    """Temporarily ignores the given actor
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(account_dir):
        print('ERROR: unknown account ' + account_dir)
        return
    snoozed_filename = account_dir + '/snoozed.txt'
    if os.path.isfile(snoozed_filename):
        if text_in_file(snooze_actor + ' ', snoozed_filename):
            return
    try:
        with open(snoozed_filename, 'a+', encoding='utf-8') as fp_snoozed:
            fp_snoozed.write(snooze_actor + ' ' +
                             str(int(time.time())) + '\n')
    except OSError:
        print('EX: person_snooze unable to append ' + snoozed_filename)


def person_unsnooze(base_dir: str, nickname: str, domain: str,
                    snooze_actor: str) -> None:
    """Undoes a temporarily ignore of the given actor
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    if not os.path.isdir(account_dir):
        print('ERROR: unknown account ' + account_dir)
        return
    snoozed_filename = account_dir + '/snoozed.txt'
    if not os.path.isfile(snoozed_filename):
        return
    if not text_in_file(snooze_actor + ' ', snoozed_filename):
        return
    replace_str = None
    try:
        with open(snoozed_filename, 'r', encoding='utf-8') as fp_snoozed:
            for line in fp_snoozed:
                if line.startswith(snooze_actor + ' '):
                    replace_str = line
                    break
    except OSError:
        print('EX: person_unsnooze unable to read ' + snoozed_filename)
    if replace_str:
        content = None
        try:
            with open(snoozed_filename, 'r', encoding='utf-8') as fp_snoozed:
                content = fp_snoozed.read().replace(replace_str, '')
        except OSError:
            print('EX: person_unsnooze unable to read 2 ' + snoozed_filename)
        if content is not None:
            try:
                with open(snoozed_filename, 'w+',
                          encoding='utf-8') as fp_snooze:
                    fp_snooze.write(content)
            except OSError:
                print('EX: unable to write ' + snoozed_filename)


def set_person_notes(base_dir: str, nickname: str, domain: str,
                     handle: str, notes: str) -> bool:
    """Adds notes about a person
    """
    if '@' not in handle:
        return False
    if handle.startswith('@'):
        handle = handle[1:]
    notes_dir = acct_dir(base_dir, nickname, domain) + '/notes'
    if not os.path.isdir(notes_dir):
        os.mkdir(notes_dir)
    notes_filename = notes_dir + '/' + handle + '.txt'
    try:
        with open(notes_filename, 'w+', encoding='utf-8') as fp_notes:
            fp_notes.write(notes)
    except OSError:
        print('EX: unable to write ' + notes_filename)
        return False
    return True


def get_person_notes(base_dir: str, nickname: str, domain: str,
                     handle: str) -> str:
    """Returns notes about a person
    """
    person_notes = ''
    person_notes_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/notes/' + handle + '.txt'
    if os.path.isfile(person_notes_filename):
        try:
            with open(person_notes_filename, 'r',
                      encoding='utf-8') as fp_notes:
                person_notes = fp_notes.read()
        except OSError:
            print('EX: get_person_notes unable to read ' +
                  person_notes_filename)
    return person_notes


def get_person_notes_endpoint(base_dir: str, nickname: str, domain: str,
                              handle: str,
                              http_prefix: str, domain_full: str) -> {}:
    """Returns a json endpoint for account notes, for use by c2s
    """
    actor = local_actor_url(http_prefix, nickname, domain_full)
    notes_json = {
        "@context": "http://www.w3.org/ns/anno.jsonld",
        "id": actor + "/private_account_notes",
        "type": "AnnotationCollection",
        "items": []
    }
    dir_str = acct_dir(base_dir, nickname, domain) + '/notes'
    if not os.path.isdir(dir_str):
        return notes_json
    handle_txt = ''
    if handle:
        handle_txt = handle + '.txt'
    for _, _, files in os.walk(dir_str):
        for filename in files:
            if not filename.endswith('.txt'):
                continue
            if handle:
                if filename != handle_txt:
                    continue
            handle2 = filename.replace('.txt', '')
            notes_text = get_person_notes(base_dir, nickname, domain, handle2)
            if not notes_text:
                continue
            notes_json['items'].append({
                "id": actor + "/private_account_notes/" + handle2,
                "type": "Annotation",
                "bodyValue": notes_text,
                "target": handle2
            })
        break
    return notes_json


def _detect_users_path(url: str) -> str:
    """Tries to detect the /users/ path
    """
    if '/' not in url:
        return '/users/'
    users_paths = get_user_paths()
    for possible_users_path in users_paths:
        if possible_users_path in url:
            return possible_users_path
    return '/users/'


def get_actor_json(host_domain: str, handle: str, http: bool, gnunet: bool,
                   ipfs: bool, ipns: bool,
                   debug: bool, quiet: bool,
                   signing_priv_key_pem: str,
                   existing_session, mitm_servers: []) -> ({}, {}):
    """Returns the actor json
    """
    if debug:
        print('get_actor_json for ' + handle)
    original_actor = handle
    group_account = False

    # try to determine the users path
    detected_users_path = _detect_users_path(handle)
    if '/@' in handle or \
       detected_users_path in handle or \
       handle.startswith('http') or \
       handle.startswith('ipfs') or \
       handle.startswith('ipns') or \
       handle.startswith('hyper'):
        group_paths = get_group_paths()
        if detected_users_path in group_paths:
            group_account = True
        # format: https://domain/@nick
        original_handle = handle
        if not has_users_path(original_handle):
            if not quiet or debug:
                print('get_actor_json: Expected actor format: ' +
                      'https://domain/@nick or https://domain' +
                      detected_users_path + 'nick')
            return None, None
        prefixes = get_protocol_prefixes()
        for prefix in prefixes:
            handle = handle.replace(prefix, '')
        if '/@/' not in handle:
            handle = handle.replace('/@', detected_users_path)
        paths = get_user_paths()
        user_path_found = False
        for user_path in paths:
            if user_path in handle:
                nickname = handle.split(user_path)[1]
                nickname = remove_eol(nickname)
                domain = handle.split(user_path)[0]
                user_path_found = True
                break
        if not user_path_found and '://' in original_handle:
            domain = original_handle.split('://')[1]
            if '/' in domain:
                domain = domain.split('/')[0]
            if '://' + domain + '/' not in original_handle:
                return None, None
            nickname = original_handle.split('://' + domain + '/')[1]
            if '/' in nickname or '.' in nickname:
                return None, None
    else:
        # format: @nick@domain
        if '@' not in handle:
            if not quiet:
                print('get_actor_json Syntax: --actor nickname@domain')
            return None, None
        if handle.startswith('@'):
            handle = handle[1:]
        elif handle.startswith('!'):
            # handle for a group
            handle = handle[1:]
            group_account = True
        if '@' not in handle:
            if not quiet:
                print('get_actor_jsonSyntax: --actor nickname@domain')
            return None, None
        nickname = handle.split('@')[0]
        domain = handle.split('@')[1]
        domain = remove_eol(domain)

    cached_webfingers = {}
    proxy_type = None
    if http or domain.endswith('.onion'):
        http_prefix = 'http'
        proxy_type = 'tor'
    elif domain.endswith('.i2p'):
        http_prefix = 'http'
        proxy_type = 'i2p'
    elif gnunet:
        http_prefix = 'gnunet'
        proxy_type = 'gnunet'
    elif ipfs:
        http_prefix = 'ipfs'
        proxy_type = 'ipfs'
    elif ipns:
        http_prefix = 'ipns'
        proxy_type = 'ipfs'
    else:
        if '127.0.' not in domain and '192.168.' not in domain:
            http_prefix = 'https'
        else:
            http_prefix = 'http'
    if existing_session:
        session = existing_session
        if debug:
            print('DEBUG: get_actor_json using existing session ' +
                  str(proxy_type) + ' ' + domain)
    else:
        session = create_session(proxy_type)
        if debug:
            print('DEBUG: get_actor_json using session ' +
                  str(proxy_type) + ' ' + domain)
    if nickname == 'inbox':
        nickname = domain

    person_url = None
    wf_request = None

    original_actor_lower = original_actor.lower()
    ends_with_instance_actor = False
    if original_actor_lower.endswith('/actor') or \
       original_actor_lower.endswith('/instance.actor'):
        ends_with_instance_actor = True

    if '://' in original_actor and ends_with_instance_actor:
        if debug:
            print(original_actor + ' is an instance actor')
        person_url = original_actor
    elif '://' in original_actor and group_account:
        if debug:
            print(original_actor + ' is a group actor')
        person_url = original_actor
    else:
        handle = nickname + '@' + domain
        if debug:
            print('get_actor_json webfinger: ' + handle)
        wf_request = webfinger_handle(session, handle,
                                      http_prefix, cached_webfingers,
                                      host_domain, __version__, debug,
                                      group_account, signing_priv_key_pem,
                                      mitm_servers)
        if not wf_request:
            if not quiet:
                print('get_actor_json Unable to webfinger ' + handle +
                      ' ' + http_prefix + ' proxy: ' + str(proxy_type))
            return None, None
        if not isinstance(wf_request, dict):
            if not quiet:
                print('get_actor_json Webfinger for ' + handle +
                      ' did not return a dict. ' + str(wf_request))
            return None, None

        if not quiet:
            pprint(wf_request)

        if wf_request.get('errors'):
            if not quiet or debug:
                print('get_actor_json wf_request error: ' +
                      str(wf_request['errors']))
            if has_users_path(handle):
                person_url = original_actor
            else:
                if debug:
                    print('No users path in ' + handle)
                return None, None

    profile_str = 'https://www.w3.org/ns/activitystreams'
    headers_list = (
        "activity+json", "ld+json", "jrd+json"
    )
    if not person_url and wf_request:
        person_url = get_user_url(wf_request, 0, debug)
    if debug and person_url:
        print('\nget_actor_json getting json for ' + person_url)
    if nickname == domain:
        paths = get_user_paths()
        for user_path in paths:
            if user_path != '/@':
                person_url = person_url.replace(user_path, '/actor/')
    if not person_url and group_account:
        person_url = http_prefix + '://' + domain + '/c/' + nickname
    if not person_url:
        # try single user instance
        person_url = http_prefix + '://' + domain + '/' + nickname
        headers_list = (
            "ld+json", "jrd+json", "activity+json"
        )
        if debug:
            print('Trying single user instance ' + person_url)
    if '/channel/' in person_url or '/accounts/' in person_url:
        headers_list = (
            "ld+json", "jrd+json", "activity+json"
        )
    if debug:
        print('person_url: ' + person_url)
    for header_type in headers_list:
        header_mime_type = 'application/' + header_type
        as_header = {
            'Accept': header_mime_type + '; profile="' + profile_str + '"'
        }
        person_json = \
            get_json(signing_priv_key_pem, session, person_url, as_header,
                     None, debug, mitm_servers, __version__, http_prefix,
                     host_domain, 20, quiet)
        if get_json_valid(person_json):
            if not quiet:
                pprint(person_json)
            return person_json, as_header
    return None, None


def get_person_avatar_url(base_dir: str, person_url: str,
                          person_cache: {}) -> str:
    """Returns the avatar url for the person
    """
    person_json = \
        get_person_from_cache(base_dir, person_url, person_cache)
    if not person_json:
        return None

    # get from locally stored image
    if not person_json.get('id'):
        return None
    actor_str = person_json['id'].replace('/', '-')
    avatar_image_path = base_dir + '/cache/avatars/' + actor_str

    image_extension = get_image_extensions()
    for ext in image_extension:
        im_filename = avatar_image_path + '.' + ext
        im_path = '/avatars/' + actor_str + '.' + ext
        if not os.path.isfile(im_filename):
            im_filename = avatar_image_path.lower() + '.' + ext
            im_path = '/avatars/' + actor_str.lower() + '.' + ext
            if not os.path.isfile(im_filename):
                continue
        if ext != 'svg':
            return im_path
        content = ''
        try:
            with open(im_filename, 'r', encoding='utf-8') as fp_im:
                content = fp_im.read()
        except OSError:
            print('EX: get_person_avatar_url unable to read ' + im_filename)
        if not dangerous_svg(content, False):
            return im_path

    if person_json.get('icon'):
        person_icon_url = get_person_icon(person_json)
        if person_icon_url:
            return person_icon_url
    return None


def add_actor_update_timestamp(actor_json: {}) -> None:
    """Adds 'updated' fields with a timestamp
    """
    updated_time = date_utcnow()
    curr_date_str = updated_time.strftime("%Y-%m-%dT%H:%M:%SZ")
    actor_json['updated'] = curr_date_str
    # add updated timestamp to avatar and banner
    actor_json['icon']['updated'] = curr_date_str
    actor_json['image']['updated'] = curr_date_str


def valid_sending_actor(session, base_dir: str,
                        nickname: str, domain: str,
                        person_cache: {},
                        post_json_object: {},
                        signing_priv_key_pem: str,
                        debug: bool, unit_test: bool,
                        system_language: str,
                        mitm_servers: []) -> bool:
    """When a post arrives in the inbox this is used to check that
    the sending actor is valid
    """
    # who sent this post?
    sending_actor = get_actor_from_post(post_json_object)

    if not isinstance(sending_actor, str):
        return False

    if contains_invalid_actor_url_chars(sending_actor):
        return False

    # If you are following them then allow their posts
    if is_following_actor(base_dir, nickname, domain, sending_actor):
        return True

    # sending to yourself (reminder)
    if sending_actor.endswith(domain + '/users/' + nickname):
        return True

    # download the actor
    # NOTE: the actor should not be obtained from the local cache,
    # because they may have changed fields which are being tested here,
    # such as the bio length
    gnunet = False
    ipfs = False
    ipns = False
    actor_json, _ = get_actor_json(domain, sending_actor,
                                   True, gnunet, ipfs, ipns,
                                   debug, True,
                                   signing_priv_key_pem, session,
                                   mitm_servers)
    if not actor_json:
        # if the actor couldn't be obtained then proceed anyway
        return True
    if not actor_json.get('preferredUsername'):
        print('REJECT: no preferredUsername within actor ' + str(actor_json))
        return False

    # is this a known spam actor?
    actor_spam_filter_filename = \
        acct_dir(base_dir, nickname, domain) + '/.reject_spam_actors'
    if not os.path.isfile(actor_spam_filter_filename):
        return True

    # does the actor have a bio ?
    if not unit_test:
        bio_str = ''
        if actor_json.get('summary'):
            bio_str = remove_html(actor_json['summary']).strip()
        if not bio_str:
            # allow no bio if it's an actor in this instance
            if domain not in sending_actor:
                # probably a spam actor with no bio
                print('REJECT: spam actor ' + sending_actor)
                return False
        if len(bio_str) < 10:
            print('REJECT: actor bio is not long enough ' +
                  sending_actor + ' ' + bio_str)
            return False
        bio_str += ' ' + remove_html(actor_json['preferredUsername'])

        if actor_json.get('attachment'):
            if isinstance(actor_json['attachment'], list):
                for tag in actor_json['attachment']:
                    if not isinstance(tag, dict):
                        continue
                    if not tag.get('name'):
                        continue
                    if isinstance(tag['name'], str):
                        bio_str += ' ' + tag['name']
                    prop_value_name, _ = \
                        get_attachment_property_value(tag)
                    if not prop_value_name:
                        continue
                    if tag.get(prop_value_name):
                        continue
                    if isinstance(tag[prop_value_name], str):
                        bio_str += ' ' + tag[prop_value_name]

        if actor_json.get('name'):
            bio_str += ' ' + remove_html(actor_json['name'])
        if contains_invalid_chars(bio_str):
            print('REJECT: post actor bio contains invalid characters')
            return False
        if is_filtered_bio(base_dir, nickname, domain, bio_str,
                           system_language):
            print('REJECT: post actor bio contains filtered text')
            return False
    else:
        print('Skipping check for missing bio in ' + sending_actor)

    # Check any attached fields for the actor.
    # Spam actors will sometimes have attached fields which are all empty
    if actor_json.get('attachment'):
        if isinstance(actor_json['attachment'], list):
            no_of_tags = 0
            tags_without_value = 0
            for tag in actor_json['attachment']:
                if not isinstance(tag, dict):
                    continue
                if not tag.get('name') and not tag.get('schema:name'):
                    continue
                no_of_tags += 1
                prop_value_name, _ = get_attachment_property_value(tag)
                if not prop_value_name:
                    tags_without_value += 1
                    continue
                if not isinstance(tag[prop_value_name], str):
                    tags_without_value += 1
                    continue
                if not tag[prop_value_name].strip():
                    tags_without_value += 1
                    continue
                if len(tag[prop_value_name]) < 2:
                    tags_without_value += 1
                    continue
            if no_of_tags > 0:
                if int(tags_without_value * 100 / no_of_tags) > 50:
                    print('REJECT: actor has empty attachments ' +
                          sending_actor)
                    return False

    # if the actor is valid and was downloaded then
    # store it in the cache, but don't write it to file
    store_person_in_cache(base_dir, sending_actor, actor_json,
                          person_cache, False)
    return True


def get_featured_hashtags(actor_json: {}) -> str:
    """returns a string containing featured hashtags
    """
    result = ''
    if not actor_json.get('tag'):
        return result
    if not isinstance(actor_json['tag'], list):
        return result
    ctr = 0
    for tag_dict in actor_json['tag']:
        if not tag_dict.get('type'):
            continue
        if not isinstance(tag_dict['type'], str):
            continue
        if not tag_dict['type'].endswith('Hashtag'):
            continue
        if not tag_dict.get('name'):
            continue
        if not isinstance(tag_dict['name'], str):
            continue
        if not tag_dict.get('href'):
            continue
        if not isinstance(tag_dict['href'], str):
            continue
        tag_name = tag_dict['name']
        if not tag_name:
            continue
        if tag_name.startswith('#'):
            tag_name = tag_name[1:]
        if not tag_name:
            continue
        tag_url = remove_html(tag_dict['href'])
        if '://' not in tag_url:
            continue
        if not valid_hash_tag(tag_name):
            continue
        result += '#' + tag_name + ' '
        ctr += 1
        if ctr >= 10:
            break
    return result.strip()


def get_featured_hashtags_as_html(actor_json: {},
                                  profile_description: str) -> str:
    """returns a html string containing featured hashtags
    """
    result = ''
    if not actor_json.get('tag'):
        return result
    if not isinstance(actor_json['tag'], list):
        return result
    ctr = 0
    for tag_dict in actor_json['tag']:
        if not tag_dict.get('type'):
            continue
        if not isinstance(tag_dict['type'], str):
            continue
        if not tag_dict['type'].endswith('Hashtag'):
            continue
        if not tag_dict.get('name'):
            continue
        if not isinstance(tag_dict['name'], str):
            continue
        if not tag_dict.get('href'):
            continue
        if not isinstance(tag_dict['href'], str):
            continue
        tag_name = tag_dict['name']
        if not tag_name:
            continue
        if tag_name.startswith('#'):
            tag_name = tag_name[1:]
        if not tag_name:
            continue
        if '/tags/' + tag_name + '"' in profile_description:
            continue
        if ' #' + tag_name in profile_description:
            continue
        tag_url = remove_html(tag_dict['href'])
        if '://' not in tag_url:
            continue
        if not valid_hash_tag(tag_name):
            continue
        result += \
            '<a href="' + tag_url + '" ' + \
            'class="mention hashtag" rel="tag" ' + \
            'tabindex="10">#' + tag_name + '</a> '
        ctr += 1
        if ctr >= 10:
            break
    result = result.strip()
    if result:
        result = '<p>' + result + '</p>'
    return result


def set_featured_hashtags(actor_json: {}, hashtags: str,
                          append: bool = False) -> None:
    """sets featured hashtags
    """
    separator_str = ' '
    separators = (',', ' ')
    for separator_str in separators:
        if separator_str in hashtags:
            break
    tag_list = hashtags.split(separator_str)
    result: list[str] = []
    tags_used: list[str] = []
    actor_id = actor_json['id']
    actor_domain = actor_id.split('://')[1]
    if '/' in actor_domain:
        actor_domain = actor_domain.split('/')[0]
    actor_url = \
        actor_id.split('://')[0] + '://' + actor_domain
    for tag_str in tag_list:
        if not tag_str:
            continue
        if not tag_str.startswith('#'):
            tag_str = '#' + tag_str
        if tag_str in tags_used:
            continue
        url = actor_url + '/tags/' + tag_str.replace('#', '')
        result.append({
            "name": tag_str,
            "type": "Hashtag",
            "href": url
        })
        tags_used.append(tag_str)
        if len(result) >= 10:
            break
    # add any non-hashtags to the result
    if actor_json.get('tag'):
        for tag_dict in actor_json['tag']:
            if not tag_dict.get('type'):
                continue
            if not isinstance(tag_dict['type'], str):
                continue
            if tag_dict['type'] != 'Hashtag':
                result.append(tag_dict)
    if not append:
        actor_json['tag'] = result
    else:
        actor_json['tag'] += result


def update_memorial_flags(base_dir: str, person_cache: {}) -> None:
    """Sets or clears the memorial flags based upon the content of
    accounts/memorial file
    """
    memorials = get_memorials(base_dir).split('\n')

    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for account in dirs:
            if not is_account_dir(account):
                continue
            actor_filename = data_dir(base_dir) + '/' + account + '.json'
            if not os.path.isfile(actor_filename):
                continue
            actor_json = load_json(actor_filename)
            if not actor_json:
                continue
            if not actor_json.get('id'):
                continue
            nickname = account.split('@')[0]
            actor_changed = False
            if not actor_json.get('memorial'):
                if nickname in memorials:
                    actor_json['memorial'] = True
                    actor_changed = True
            else:
                if nickname not in memorials:
                    actor_json['memorial'] = False
                    actor_changed = True
            if not actor_changed:
                continue
            save_json(actor_json, actor_filename)
            actor = actor_json['id']
            remove_person_from_cache(base_dir, actor, person_cache)
            store_person_in_cache(base_dir, actor, actor_json,
                                  person_cache, True)
        break


def get_account_pub_key(path: str, person_cache: {},
                        base_dir: str, domain: str,
                        calling_domain: str,
                        http_prefix: str,
                        domain_full: str,
                        onion_domain: str,
                        i2p_domain: str) -> str:
    """Returns the public key for an account
    """
    if '/users/' not in path:
        return None
    nickname = path.split('/users/')[1]
    if '#main-key' in nickname:
        nickname = nickname.split('#main-key')[0]
    elif '/main-key' in nickname:
        nickname = nickname.split('/main-key')[0]
    elif '#/publicKey' in nickname:
        nickname = nickname.split('#/publicKey')[0]
    else:
        return None

    actor = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        '/users/' + nickname
    actor_json = get_person_from_cache(base_dir, actor, person_cache)
    if not actor_json:
        actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
        if not os.path.isfile(actor_filename):
            return None
        actor_json = load_json(actor_filename)
        if not actor_json:
            return None
        store_person_in_cache(base_dir, actor, actor_json,
                              person_cache, False)
    if not actor_json.get('publicKey') and \
       not actor_json.get('assertionMethod'):
        return None
    original_person_url = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        path
    pub_key, _ = \
        get_actor_public_key_from_id(actor_json, original_person_url)
    return pub_key
