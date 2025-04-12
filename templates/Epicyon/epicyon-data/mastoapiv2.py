__filename__ = "mastoapiv2.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "API"

import os
from utils import get_url_from_post
from utils import load_json
from utils import get_config_param
from utils import acct_dir
from utils import remove_html
from utils import get_attachment_property_value
from utils import no_of_accounts
from utils import get_image_extensions
from utils import get_video_extensions
from utils import get_audio_extensions
from utils import get_image_mime_type
from utils import lines_in_file
from utils import data_dir
from utils import account_is_indexable


def _get_masto_api_v2id_from_nickname(nickname: str) -> int:
    """Given an account nickname return the corresponding mastodon id
    """
    return int.from_bytes(nickname.encode('utf-8'), 'little')


def _meta_data_instance_v2(show_accounts: bool,
                           instance_title: str,
                           instance_description: str,
                           http_prefix: str, base_dir: str,
                           admin_nickname: str, domain: str, domain_full: str,
                           registration: bool, system_language: str,
                           version: str, translate: {}) -> {}:
    """ /api/v2/instance endpoint
    """
    account_dir = data_dir(base_dir) + '/' + admin_nickname + '@' + domain
    admin_actor_filename = account_dir + '.json'
    if not os.path.isfile(admin_actor_filename):
        return {}

    admin_actor = load_json(admin_actor_filename)
    if not admin_actor:
        print('WARN: json load exception _meta_data_instance_v1')
        return {}

    rules_list: list[str] = []
    rules_filename = data_dir(base_dir) + '/tos.md'
    if os.path.isfile(rules_filename):
        rules_lines: list[str] = []
        try:
            with open(rules_filename, 'r', encoding='utf-8') as fp_rules:
                rules_lines = fp_rules.readlines()
        except OSError:
            print('EX: _meta_data_instance_v2 unable to read rules')
        rule_ctr = 1
        for line in rules_lines:
            line = line.strip()
            if not line:
                continue
            if line.startswith('#'):
                continue
            rules_list.append({
                'id': str(rule_ctr),
                'text': line
            })
            rule_ctr += 1

    is_bot = False
    is_group = False
    if admin_actor['type'] == 'Group':
        is_group = True
    elif admin_actor['type'] != 'Person':
        is_bot = True

    url = \
        http_prefix + '://' + domain_full + '/@' + \
        admin_actor['preferredUsername']

    if show_accounts:
        active_accounts = no_of_accounts(base_dir)
    else:
        active_accounts = 1

    created_at = ''
    if admin_actor.get('published'):
        created_at = admin_actor['published']

    url_str = get_url_from_post(admin_actor['icon']['url'])
    icon_url = remove_html(url_str)
    url_str = get_url_from_post(admin_actor['image']['url'])
    image_url = remove_html(url_str)
    thumbnail_url = http_prefix + '://' + domain_full + '/login.png'
    admin_email = None
    noindex = not account_is_indexable(admin_actor)
    discoverable = True
    if 'discoverable' in admin_actor:
        if admin_actor['discoverable'] is False:
            discoverable = False
    no_of_statuses = 0
    no_of_followers = 0
    no_of_following = 0
    if show_accounts:
        no_of_followers = lines_in_file(account_dir + '/followers.txt')
        no_of_following = lines_in_file(account_dir + '/following.txt')
        # count the number of posts
        for _, _, files2 in os.walk(account_dir + '/outbox'):
            no_of_statuses = len(files2)
            break
    published = None
    published_filename = \
        acct_dir(base_dir, admin_nickname, domain) + '/.last_published'
    if os.path.isfile(published_filename):
        try:
            with open(published_filename, 'r',
                      encoding='utf-8') as fp_pub:
                published = fp_pub.read()
        except OSError:
            print('EX: _meta_data_instance_v2 ' +
                  'unable to read last published time 2 ' +
                  published_filename)

    # get all supported mime types
    supported_mime_types: list[str] = []
    image_ext = get_image_extensions()
    for ext in image_ext:
        mime_str = get_image_mime_type('x.' + ext)
        if mime_str not in supported_mime_types:
            supported_mime_types.append(mime_str)
    video_ext = get_video_extensions()
    for ext in video_ext:
        supported_mime_types.append('video/' + ext)
    audio_ext = get_audio_extensions()
    for ext in audio_ext:
        supported_mime_types.append('audio/' + ext)

    fields: list[dict] = []
    # get account fields from attachments
    if admin_actor.get('attachment'):
        if isinstance(admin_actor['attachment'], list):
            translated_email = translate['Email'].lower()
            email_fields = ('email', 'e-mail', translated_email)
            for tag in admin_actor['attachment']:
                if not isinstance(tag, dict):
                    continue
                if not tag.get('name'):
                    continue
                if not isinstance(tag['name'], str):
                    continue
                prop_value_name, _ = \
                    get_attachment_property_value(tag)
                if not prop_value_name:
                    continue
                if not tag.get(prop_value_name):
                    continue
                if not isinstance(tag[prop_value_name], str):
                    continue
                tag_name = tag['name']
                tag_name_lower = tag_name.lower()
                if tag_name_lower in email_fields and \
                   '@' in tag[prop_value_name]:
                    admin_email = tag[prop_value_name]
                fields.append({
                    "name": tag_name,
                    "value": tag[prop_value_name],
                    "verified_at": None
                })

    instance = {
        "domain": domain_full,
        "title": instance_title,
        "version": version,
        "source_url": "https://gitlab.com/bashrc2/epicyon",
        "description": instance_description,
        "usage": {
            "users": {
                "active_month": active_accounts
            }
        },
        "thumbnail": {
            "url": thumbnail_url,
            "blurhash": "UeKUpFxuo~R%0nW;WCnhF6RjaJt757oJodS$",
            "versions": {
                "@1x": thumbnail_url,
                "@2x": thumbnail_url
            }
        },
        "languages": [system_language],
        "configuration": {
            "urls": {
            },
            "accounts": {
                "max_featured_tags": 20
            },
            "statuses": {
                "max_characters": 5000,
                "max_media_attachments": 1,
                "characters_reserved_per_url": 23
            },
            "media_attachments": {
                "supported_mime_types": supported_mime_types,
                "image_size_limit": 10485760,
                "image_matrix_limit": 16777216,
                "video_size_limit": 41943040,
                "video_frame_rate_limit": 60,
                "video_matrix_limit": 2304000
            },
            "polls": {
                "max_options": 4,
                "max_characters_per_option": 50,
                "min_expiration": 300,
                "max_expiration": 2629746
            },
            "translation": {
                "enabled": False
            }
        },
        "registrations": {
            "enabled": registration,
            "approval_required": False,
            "message": None
        },
        "contact": {
            "email": admin_email,
            "account": {
                "id": _get_masto_api_v2id_from_nickname(admin_nickname),
                "username": admin_nickname,
                "acct": admin_nickname,
                "display_name": admin_actor['name'],
                "locked": admin_actor['manuallyApprovesFollowers'],
                "bot": is_bot,
                "discoverable": discoverable,
                "group": is_group,
                "created_at": created_at,
                "note": '<p>Admin of ' + domain + '</p>',
                "url": url,
                "avatar": icon_url,
                "avatar_static": icon_url,
                "header": image_url,
                "header_static": image_url,
                "followers_count": no_of_followers,
                "following_count": no_of_following,
                "statuses_count": no_of_statuses,
                "last_status_at": published,
                "noindex": noindex,
                "emojis": [],
                "fields": fields
            }
        },
        "rules": rules_list
    }

    return instance


def masto_api_v2_response(path: str, calling_domain: str,
                          ua_str: str,
                          http_prefix: str,
                          base_dir: str, domain: str,
                          domain_full: str,
                          onion_domain: str, i2p_domain: str,
                          translate: {},
                          registration: bool,
                          system_language: str,
                          project_version: str,
                          show_node_info_accounts: bool,
                          broch_mode: bool) -> ({}, str):
    """This is a vestigil mastodon API for the purpose
       of returning a result
    """
    send_json = None
    send_json_str = ''
    if not ua_str:
        ua_str = ''

    admin_nickname = get_config_param(base_dir, 'admin')
    if admin_nickname and path == '/api/v2/instance':
        instance_description = \
            get_config_param(base_dir, 'instanceDescription')
        instance_title = get_config_param(base_dir, 'instanceTitle')

        if calling_domain.endswith('.onion') and onion_domain:
            domain_full = onion_domain
            http_prefix = 'http'
        elif (calling_domain.endswith('.i2p') and i2p_domain):
            domain_full = i2p_domain
            http_prefix = 'http'

        if broch_mode:
            show_node_info_accounts = False

        send_json = \
            _meta_data_instance_v2(show_node_info_accounts,
                                   instance_title,
                                   instance_description,
                                   http_prefix,
                                   base_dir,
                                   admin_nickname,
                                   domain,
                                   domain_full,
                                   registration,
                                   system_language,
                                   project_version,
                                   translate)
        send_json_str = 'masto API instance metadata sent ' + ua_str
    return send_json, send_json_str
