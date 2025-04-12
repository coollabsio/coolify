__filename__ = "mastoapiv1.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "API"

import os
from utils import string_contains
from utils import get_url_from_post
from utils import load_json
from utils import get_config_param
from utils import acct_dir
from utils import remove_html
from utils import get_attachment_property_value
from utils import no_of_accounts
from utils import get_status_count
from utils import lines_in_file
from utils import data_dir
from utils import account_is_indexable


def _meta_data_instance_v1(show_accounts: bool,
                           instance_title: str,
                           instance_description_short: str,
                           instance_description: str,
                           http_prefix: str, base_dir: str,
                           admin_nickname: str, domain: str, domain_full: str,
                           registration: bool, system_language: str,
                           version: str) -> {}:
    """ /api/v1/instance endpoint
    """
    admin_actor_filename = \
        data_dir(base_dir) + '/' + admin_nickname + '@' + domain + '.json'
    if not os.path.isfile(admin_actor_filename):
        return {}

    admin_actor = load_json(admin_actor_filename)
    if not admin_actor:
        print('WARN: json load exception _meta_data_instance_v1')
        return {}

    rules_list: list[str] = []
    rules_filename = data_dir(base_dir) + '/tos.md'
    if os.path.isfile(rules_filename):
        try:
            with open(rules_filename, 'r', encoding='utf-8') as fp_rules:
                rules_lines = fp_rules.readlines()
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
        except OSError:
            print('EX: _meta_data_instance_v1 unable to read ' +
                  rules_filename)

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
        local_posts = get_status_count(base_dir)
    else:
        active_accounts = 1
        local_posts = 1

    created_at = ''
    if admin_actor.get('published'):
        created_at = admin_actor['published']

    url_str = get_url_from_post(admin_actor['icon']['url'])
    icon_url = remove_html(url_str)
    url_str = get_url_from_post(admin_actor['image']['url'])
    image_url = remove_html(url_str)
    instance = {
        'approval_required': False,
        'invites_enabled': False,
        'registrations': registration,
        'contact_account': {
            'acct': admin_actor['preferredUsername'],
            'created_at': created_at,
            'avatar': icon_url,
            'avatar_static': icon_url,
            'header': image_url,
            'header_static': image_url,
            'bot': is_bot,
            'discoverable': True,
            'group': is_group,
            'display_name': admin_actor['name'],
            'locked': admin_actor['manuallyApprovesFollowers'],
            'note': '<p>Admin of ' + domain + '</p>',
            'url': url,
            'username': admin_actor['preferredUsername']
        },
        'description': instance_description,
        'languages': [system_language],
        'short_description': instance_description_short,
        'stats': {
            'domain_count': 2,
            'status_count': local_posts,
            'user_count': active_accounts
        },
        'thumbnail': http_prefix + '://' + domain_full + '/login.png',
        'title': instance_title,
        'uri': domain_full,
        'urls': {},
        'version': version,
        'rules': rules_list,
        'configuration': {
            'statuses': {
                'max_media_attachments': 1
            },
            'media_attachments': {
                'supported_mime_types': [
                    'image/jpeg',
                    'image/jxl',
                    'image/png',
                    'image/gif',
                    'image/webp',
                    'image/avif',
                    'image/heic',
                    'image/svg+xml',
                    'video/webm',
                    'video/mp4',
                    'video/ogv',
                    'audio/ogg',
                    'audio/wav',
                    'audio/x-wav',
                    'audio/x-pn-wave',
                    'audio/vnd.wave',
                    'audio/opus',
                    'audio/speex',
                    'audio/x-speex',
                    'audio/flac',
                    'audio/mpeg'
                ],
                'image_size_limit': 10485760,
                'image_matrix_limit': 16777216,
                'video_size_limit': 41943040,
                'video_frame_rate_limit': 60,
                'video_matrix_limit': 2304000
            }
        }
    }

    return instance


def _get_mast_api_v1id(path: str) -> int:
    """Extracts the mastodon Id number from the given path
    """
    masto_id = None
    id_path = '/api/v1/accounts/:'
    if not path.startswith(id_path):
        return None
    masto_id_str = path.replace(id_path, '')
    if '/' in masto_id_str:
        masto_id_str = masto_id_str.split('/')[0]
    if masto_id_str.isdigit():
        masto_id = int(masto_id_str)
        return masto_id
    return None


def get_masto_api_v1id_from_nickname(nickname: str) -> int:
    """Given an account nickname return the corresponding mastodon id
    """
    return int.from_bytes(nickname.encode('utf-8'), 'little')


def _int_to_bytes(num: int) -> str:
    """Integer conversion
    """
    if num == 0:
        return b""
    return _int_to_bytes(num // 256) + bytes([num % 256])


def get_nickname_from_masto_api_v1id(masto_id: int) -> str:
    """Given the mastodon Id return the nickname
    """
    nickname = _int_to_bytes(masto_id).decode()
    return nickname[::-1]


def _get_masto_api_v1account(base_dir: str, nickname: str, domain: str,
                             show_accounts: bool, broch_mode: bool) -> {}:
    """See https://github.com/McKael/mastodon-documentation/
    blob/master/Using-the-API/API.md#account
    Authorization has already been performed
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    account_filename = account_dir + '.json'
    if not os.path.isfile(account_filename):
        return {}
    account_json = load_json(account_filename)
    if not account_json:
        return {}
    url_str = get_url_from_post(account_json['icon']['url'])
    avatar_url = remove_html(url_str)
    url_str = get_url_from_post(account_json['image']['url'])
    image_url = remove_html(url_str)
    joined_date = "2016-10-05T10:30:00Z"
    if account_json.get('published'):
        joined_date = account_json['published']
    noindex = not account_is_indexable(account_json)
    discoverable = True
    if 'discoverable' in account_json:
        if account_json['discoverable'] is False:
            discoverable = False
    group = False
    bot = False
    if account_json['type'] == 'Group':
        group = True
    elif account_json['type'] != 'Person':
        bot = True
    no_of_statuses = 0
    no_of_followers = 0
    no_of_following = 0
    fields: list[dict] = []
    published = None
    if show_accounts and not broch_mode:
        no_of_followers = lines_in_file(account_dir + '/followers.txt')
        no_of_following = lines_in_file(account_dir + '/following.txt')
        # count the number of posts
        for _, _, files2 in os.walk(account_dir + '/outbox'):
            no_of_statuses = len(files2)
            break
        # get account fields from attachments
        if account_json.get('attachment'):
            if isinstance(account_json['attachment'], list):
                for tag in account_json['attachment']:
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
                    fields.append({
                        "name": tag['name'],
                        "value": tag[prop_value_name],
                        "verified_at": None
                    })
        published_filename = \
            acct_dir(base_dir, nickname, domain) + '/.last_published'
        if os.path.isfile(published_filename):
            try:
                with open(published_filename, 'r',
                          encoding='utf-8') as fp_pub:
                    published = fp_pub.read()
            except OSError:
                print('EX: unable to read last published time 1 ' +
                      published_filename)

    masto_account_json = {
        "id": get_masto_api_v1id_from_nickname(nickname),
        "username": nickname,
        "acct": nickname,
        "display_name": account_json['name'],
        "locked": account_json['manuallyApprovesFollowers'],
        "created_at": joined_date,
        "followers_count": no_of_followers,
        "following_count": no_of_following,
        "statuses_count": no_of_statuses,
        "note": account_json['summary'],
        "url": account_json['id'],
        "avatar": avatar_url,
        "avatar_static": avatar_url,
        "header": image_url,
        "header_static": image_url,
        "noindex": noindex,
        "discoverable": discoverable,
        "group": group,
        "bot": bot,
        "emojis": [],
        "roles": [],
        "fields": fields
    }
    if published:
        masto_account_json['last_status_at'] = published
    return masto_account_json


def masto_api_v1_response(path: str, calling_domain: str,
                          ua_str: str,
                          authorized: bool,
                          http_prefix: str,
                          base_dir: str, nickname: str, domain: str,
                          domain_full: str,
                          onion_domain: str, i2p_domain: str,
                          translate: {},
                          registration: bool,
                          system_language: str,
                          project_version: str,
                          custom_emoji: [],
                          show_node_info_accounts: bool,
                          broch_mode: bool) -> ({}, str):
    """This is a vestigil mastodon API for the purpose
       of returning a result to sites like
       https://mastopeek.app-dist.eu
    """
    send_json = None
    send_json_str = ''
    if not ua_str:
        ua_str = ''

    # parts of the api needing authorization
    if authorized and nickname:
        if path == '/api/v1/accounts/verify_credentials':
            send_json = \
                _get_masto_api_v1account(base_dir, nickname, domain,
                                         show_node_info_accounts,
                                         broch_mode)
            send_json_str = \
                'masto API account sent for ' + nickname + ' ' + ua_str

    # information about where the request is coming from
    calling_info = ' ' + ua_str + ', ' + calling_domain

    # Parts of the api which don't need authorization
    masto_id = _get_mast_api_v1id(path)
    if masto_id is not None:
        path_nickname = get_nickname_from_masto_api_v1id(masto_id)
        if path_nickname:
            original_path = path
            if string_contains(path,
                               ('/followers?', '/following?', '/streaming/',
                                '/search?', '/relationships?', '/statuses?')):
                path = path.split('?')[0]
            if '/streaming/' in path:
                streaming_msg = \
                    "Error: Streaming API not implemented on this instance"
                send_json = {
                    "error": streaming_msg
                }
                send_json_str = 'masto API streaming response'
            if path.endswith('/followers'):
                send_json: list[dict] = []
                send_json_str = \
                    'masto API followers sent for ' + nickname + \
                    calling_info
            elif path.endswith('/following'):
                send_json: list[dict] = []
                send_json_str = \
                    'masto API following sent for ' + nickname + \
                    calling_info
            elif path.endswith('/statuses'):
                send_json: list[dict] = []
                send_json_str = \
                    'masto API statuses sent for ' + nickname + \
                    calling_info
            elif path.endswith('/search'):
                send_json: list[dict] = []
                send_json_str = \
                    'masto API search sent ' + original_path + \
                    calling_info
            elif path.endswith('/relationships'):
                send_json: list[dict] = []
                send_json_str = \
                    'masto API relationships sent ' + original_path + \
                    calling_info
            else:
                send_json = \
                    _get_masto_api_v1account(base_dir, path_nickname, domain,
                                             show_node_info_accounts,
                                             broch_mode)
                send_json_str = \
                    'masto API account sent for ' + nickname + \
                    calling_info

    # NOTE: adding support for '/api/v1/directory seems to create
    # federation problems, so avoid implementing that

    if path.startswith('/api/v1/blocks'):
        send_json: list[dict] = []
        send_json_str = \
            'masto API instance blocks sent ' + path + calling_info
    elif path.startswith('/api/v1/favorites'):
        send_json: list[dict] = []
        send_json_str = 'masto API favorites sent ' + path + calling_info
    elif path.startswith('/api/v1/follow_requests'):
        send_json: list[dict] = []
        send_json_str = \
            'masto API follow requests sent ' + path + calling_info
    elif path.startswith('/api/v1/mutes'):
        send_json: list[dict] = []
        send_json_str = \
            'masto API mutes sent ' + path + calling_info
    elif path.startswith('/api/v1/notifications'):
        send_json: list[dict] = []
        send_json_str = \
            'masto API notifications sent ' + path + calling_info
    elif path.startswith('/api/v1/reports'):
        send_json: list[dict] = []
        send_json_str = 'masto API reports sent ' + path + calling_info
    elif path.startswith('/api/v1/statuses'):
        send_json: list[dict] = []
        send_json_str = 'masto API statuses sent ' + path + calling_info
    elif path.startswith('/api/v1/timelines'):
        send_json = {
            'error': 'This method requires an authenticated user'
        }
        send_json_str = 'masto API timelines sent ' + path + calling_info
    elif path.startswith('/api/v1/custom_emojis'):
        send_json = custom_emoji
        send_json_str = \
            'masto API custom emojis sent ' + path + calling_info

    admin_nickname = get_config_param(base_dir, 'admin')
    if admin_nickname and path == '/api/v1/instance':
        instance_description_short = \
            get_config_param(base_dir, 'instanceDescriptionShort')
        if not instance_description_short:
            instance_description_short = \
                translate['Yet another Epicyon Instance']
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
            _meta_data_instance_v1(show_node_info_accounts,
                                   instance_title,
                                   instance_description_short,
                                   instance_description,
                                   http_prefix,
                                   base_dir,
                                   admin_nickname,
                                   domain,
                                   domain_full,
                                   registration,
                                   system_language,
                                   project_version)
        send_json_str = 'masto API instance metadata sent ' + ua_str
    elif path.startswith('/api/v1/instance/peers'):
        # This is just a dummy result.
        # Showing the full list of peers would have privacy implications.
        # On a large instance you are somewhat lost in the crowd, but on
        # small instances a full list of peers would convey a lot of
        # information about the interests of a small number of accounts
        send_json = ['mastodon.social', domain_full]
        send_json_str = 'masto API peers metadata sent ' + ua_str
    elif path.startswith('/api/v1/instance/activity'):
        send_json: list[dict] = []
        send_json_str = 'masto API activity metadata sent ' + ua_str
    return send_json, send_json_str
