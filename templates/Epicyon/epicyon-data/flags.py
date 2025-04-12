__filename__ = "flags.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
import re
from utils import acct_dir
from utils import date_utcnow
from utils import date_epoch
from utils import data_dir
from utils import get_config_param
from utils import get_image_extensions
from utils import evil_incarnate
from utils import get_local_network_addresses
from utils import get_attributed_to
from utils import is_dm
from utils import has_object_dict
from utils import locate_post
from utils import load_json
from utils import has_object_string_type
from utils import date_from_string_format
from utils import get_reply_to
from utils import text_in_file
from utils import get_group_paths
from utils import get_quote_toot_url


def is_featured_writer(base_dir: str, nickname: str, domain: str) -> bool:
    """Is the given account a featured writer, appearing in the features
    timeline on news instances?
    """
    features_blocked_filename = \
        acct_dir(base_dir, nickname, domain) + '/.nofeatures'
    return not os.path.isfile(features_blocked_filename)


def is_dormant(base_dir: str, nickname: str, domain: str, actor: str,
               dormant_months: int) -> bool:
    """Is the given followed actor dormant, from the standpoint
    of the given account
    """
    last_seen_filename = acct_dir(base_dir, nickname, domain) + \
        '/lastseen/' + actor.replace('/', '#') + '.txt'

    if not os.path.isfile(last_seen_filename):
        return False

    days_since_epoch_str = None
    try:
        with open(last_seen_filename, 'r',
                  encoding='utf-8') as fp_last_seen:
            days_since_epoch_str = fp_last_seen.read()
    except OSError:
        print('EX: failed to read last seen ' + last_seen_filename)
        return False

    if days_since_epoch_str:
        days_since_epoch = int(days_since_epoch_str)
        curr_time = date_utcnow()
        curr_days_since_epoch = (curr_time - date_epoch()).days
        time_diff_months = \
            int((curr_days_since_epoch - days_since_epoch) / 30)
        if time_diff_months >= dormant_months:
            return True
    return False


def is_editor(base_dir: str, nickname: str) -> bool:
    """Returns true if the given nickname is an editor
    """
    editors_file = data_dir(base_dir) + '/editors.txt'

    if not os.path.isfile(editors_file):
        admin_name = get_config_param(base_dir, 'admin')
        if admin_name:
            if admin_name == nickname:
                return True
        return False

    lines: list[str] = []
    try:
        with open(editors_file, 'r', encoding='utf-8') as fp_editors:
            lines = fp_editors.readlines()
    except OSError:
        print('EX: is_editor unable to read ' + editors_file)

    if len(lines) == 0:
        admin_name = get_config_param(base_dir, 'admin')
        if admin_name:
            if admin_name == nickname:
                return True
    for editor in lines:
        editor = editor.strip('\n').strip('\r')
        if editor == nickname:
            return True
    return False


def is_artist(base_dir: str, nickname: str) -> bool:
    """Returns true if the given nickname is an artist
    """
    artists_file = data_dir(base_dir) + '/artists.txt'

    if not os.path.isfile(artists_file):
        admin_name = get_config_param(base_dir, 'admin')
        if admin_name:
            if admin_name == nickname:
                return True
        return False

    lines: list[str] = []
    try:
        with open(artists_file, 'r', encoding='utf-8') as fp_artists:
            lines = fp_artists.readlines()
    except OSError:
        print('EX: is_artist unable to read ' + artists_file)

    if len(lines) == 0:
        admin_name = get_config_param(base_dir, 'admin')
        if admin_name:
            if admin_name == nickname:
                return True
    for artist in lines:
        artist = artist.strip('\n').strip('\r')
        if artist == nickname:
            return True
    return False


def is_image_file(filename: str) -> bool:
    """Is the given filename an image?
    """
    for ext in get_image_extensions():
        if filename.endswith('.' + ext):
            return True
    return False


def is_system_account(nickname: str) -> bool:
    """Returns true if the given nickname is a system account
    """
    if nickname in ('news', 'inbox'):
        return True
    return False


def is_memorial_account(base_dir: str, nickname: str) -> bool:
    """Returns true if the given nickname is a memorial account
    """
    memorial_file = data_dir(base_dir) + '/memorial'
    if not os.path.isfile(memorial_file):
        return False
    memorial_list: list[str] = []
    try:
        with open(memorial_file, 'r', encoding='utf-8') as fp_memorial:
            memorial_list = fp_memorial.read().split('\n')
    except OSError:
        print('EX: unable to read ' + memorial_file)
    if nickname in memorial_list:
        return True
    return False


def is_suspended(base_dir: str, nickname: str) -> bool:
    """Returns true if the given nickname is suspended
    """
    admin_nickname = get_config_param(base_dir, 'admin')
    if not admin_nickname:
        return False
    if nickname == admin_nickname:
        return False

    suspended_filename = data_dir(base_dir) + '/suspended.txt'
    if os.path.isfile(suspended_filename):
        lines: list[str] = []
        try:
            with open(suspended_filename, 'r', encoding='utf-8') as fp_susp:
                lines = fp_susp.readlines()
        except OSError:
            print('EX: is_suspended unable to read ' + suspended_filename)

        for suspended in lines:
            if suspended.strip('\n').strip('\r') == nickname:
                return True
    return False


def is_evil(domain: str) -> bool:
    """ https://www.youtube.com/watch?v=5qw1hcevmdU
    """
    if not isinstance(domain, str):
        print('WARN: Malformed domain ' + str(domain))
        return True
    # if a domain contains any of these strings then it is
    # declaring itself to be hostile
    evil_emporium = (
        'nazi', 'extremis', 'extreemis', 'gendercritic',
        'kiwifarm', 'illegal', 'raplst', 'rapist',
        'rapl.st', 'rapi.st', 'antivax', 'plandemic', 'terror'
    )
    for hostile_str in evil_emporium:
        if hostile_str in domain:
            return True
    evil_domains = evil_incarnate()
    for concentrated_evil in evil_domains:
        if domain.endswith(concentrated_evil):
            return True
    return False


def is_local_network_address(ip_address: str) -> bool:
    """Is the given ip address local?
    """
    local_ips = get_local_network_addresses()
    for ip_addr in local_ips:
        if ip_address.startswith(ip_addr):
            return True
    return False


def is_reminder(post_json_object: {}) -> bool:
    """Returns true if the given post is a reminder
    """
    if not is_dm(post_json_object):
        return False
    if not post_json_object['object'].get('to'):
        return False
    if not post_json_object['object'].get('attributedTo'):
        return False
    if not post_json_object['object'].get('tag'):
        return False
    if not isinstance(post_json_object['object']['to'], list):
        return False
    if len(post_json_object['object']['to']) != 1:
        return False
    if post_json_object['object']['to'][0] != \
       get_attributed_to(post_json_object['object']['attributedTo']):
        return False
    for tag in post_json_object['object']['tag']:
        if tag['type'] == 'Event':
            return True
    return False


def is_public_post_from_url(base_dir: str, nickname: str, domain: str,
                            post_url: str) -> bool:
    """Returns whether the given url is a public post
    """
    post_filename = locate_post(base_dir, nickname, domain, post_url)
    if not post_filename:
        return False
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return False
    return is_public_post(post_json_object)


def is_public_post(post_json_object: {}) -> bool:
    """Returns true if the given post is public
    """
    if not post_json_object.get('type'):
        return False
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('to'):
        return False
    if isinstance(post_json_object['object']['to'], list):
        for recipient in post_json_object['object']['to']:
            if recipient.endswith('#Public') or \
               recipient == 'as:Public' or \
               recipient == 'Public':
                return True
    elif isinstance(post_json_object['object']['to'], str):
        if post_json_object['object']['to'].endswith('#Public'):
            return True
    return False


def is_followers_post(post_json_object: {}) -> bool:
    """Returns true if the given post is to followers
    """
    if not post_json_object.get('type'):
        return False
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('to'):
        return False
    if isinstance(post_json_object['object']['to'], list):
        for recipient in post_json_object['object']['to']:
            if recipient.endswith('/followers'):
                return True
    elif isinstance(post_json_object['object']['to'], str):
        if post_json_object['object']['to'].endswith('/followers'):
            return True
    return False


def is_unlisted_post(post_json_object: {}) -> bool:
    """Returns true if the given post is unlisted
    """
    if not post_json_object.get('type'):
        return False
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('to'):
        return False
    if not post_json_object['object'].get('cc'):
        return False
    has_followers = False
    if isinstance(post_json_object['object']['to'], list):
        for recipient in post_json_object['object']['to']:
            if recipient.endswith('/followers'):
                has_followers = True
                break
    elif isinstance(post_json_object['object']['to'], str):
        if post_json_object['object']['to'].endswith('/followers'):
            has_followers = True
    if not has_followers:
        return False
    if isinstance(post_json_object['object']['cc'], list):
        for recipient in post_json_object['object']['cc']:
            if recipient.endswith('#Public') or \
               recipient == 'as:Public' or \
               recipient == 'Public':
                return True
    elif isinstance(post_json_object['object']['cc'], str):
        if post_json_object['object']['cc'].endswith('#Public'):
            return True
    return False


def is_blog_post(post_json_object: {}) -> bool:
    """Is the given post a blog post?
    """
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if not has_object_string_type(post_json_object, False):
        return False
    if 'content' not in post_json_object['object']:
        return False
    if post_json_object['object']['type'] != 'Article':
        return False
    return True


def is_news_post(post_json_object: {}) -> bool:
    """Is the given post a blog post?
    """
    return post_json_object.get('news')


def is_recent_post(post_json_object: {}, max_days: int) -> bool:
    """ Is the given post recent?
    """
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('published'):
        return False
    if not isinstance(post_json_object['object']['published'], str):
        return False
    curr_time = date_utcnow()
    days_since_epoch = (curr_time - date_epoch()).days
    recently = days_since_epoch - max_days

    published_date_str = post_json_object['object']['published']
    if '.' in published_date_str:
        published_date_str = published_date_str.split('.')[0] + 'Z'

    published_date = \
        date_from_string_format(published_date_str,
                                ["%Y-%m-%dT%H:%M:%S%z"])
    if not published_date:
        print('EX: is_recent_post unrecognized published date ' +
              str(published_date_str))
        return False

    published_days_since_epoch = \
        (published_date - date_epoch()).days
    if published_days_since_epoch < recently:
        return False
    return True


def is_chat_message(post_json_object: {}) -> bool:
    """Returns true if the given post is a chat message
    Note that is_dm should be checked before calling this
    """
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if post_json_object['object']['type'] != 'ChatMessage':
        return False
    return True


def is_reply(post_json_object: {}, actor: str) -> bool:
    """Returns true if the given post is a reply to the given actor
    """
    if post_json_object['type'] != 'Create':
        return False
    if not has_object_dict(post_json_object):
        return False
    if post_json_object['object'].get('moderationStatus'):
        return False
    if post_json_object['object']['type'] not in ('Note', 'Event', 'Page',
                                                  'EncryptedMessage',
                                                  'ChatMessage', 'Article'):
        return False
    reply_id = get_reply_to(post_json_object['object'])
    if reply_id:
        if isinstance(reply_id, str):
            if reply_id.startswith(actor):
                return True
    if not post_json_object['object'].get('tag'):
        return False
    if not isinstance(post_json_object['object']['tag'], list):
        return False
    for tag in post_json_object['object']['tag']:
        if not tag.get('type'):
            continue
        if tag['type'] == 'Mention':
            if not tag.get('href'):
                continue
            if actor in tag['href']:
                return True
    return False


def is_pgp_encrypted(content: str) -> bool:
    """Returns true if the given content is PGP encrypted
    """
    if '--BEGIN PGP MESSAGE--' in content:
        if '--END PGP MESSAGE--' in content:
            return True
    return False


def invalid_ciphertext(content: str) -> bool:
    """Returns true if the given content contains an invalid key
    """
    if '----BEGIN ' in content or '----END ' in content:
        if not contains_pgp_public_key(content) and \
           not is_pgp_encrypted(content):
            return True
    return False


def contains_pgp_public_key(content: str) -> bool:
    """Returns true if the given content contains a PGP public key
    """
    if '--BEGIN PGP PUBLIC KEY BLOCK--' in content:
        if '--END PGP PUBLIC KEY BLOCK--' in content:
            return True
    return False


def contains_private_key(content: str) -> bool:
    """Returns true if the given content contains a PGP private key
    """
    if '--BEGIN PGP PRIVATE KEY BLOCK--' in content:
        if '--END PGP PRIVATE KEY BLOCK--' in content:
            return True
    if '--BEGIN RSA PRIVATE KEY--' in content:
        if '--END RSA PRIVATE KEY--' in content:
            return True
    return False


def is_float(value) -> bool:
    """Is the given value a float?
    """
    try:
        float(value)
        return True
    except ValueError:
        return False


def is_group_actor(base_dir: str, actor: str, person_cache: {},
                   debug: bool = False) -> bool:
    """Is the given actor a group?
    """
    person_cache_actor = None
    if person_cache:
        if person_cache.get(actor):
            if person_cache[actor].get('actor'):
                person_cache_actor = person_cache[actor]['actor']

    if person_cache_actor:
        if person_cache_actor.get('type'):
            if person_cache_actor['type'] == 'Group':
                if debug:
                    print('Cached actor ' + actor + ' has Group type')
                return True
        return False

    if debug:
        print('Actor ' + actor + ' not in cache')
    cached_actor_filename = \
        base_dir + '/cache/actors/' + (actor.replace('/', '#')) + '.json'
    if not os.path.isfile(cached_actor_filename):
        if debug:
            print('Cached actor file not found ' + cached_actor_filename)
        return False
    if text_in_file('"type": "Group"', cached_actor_filename):
        if debug:
            print('Group type found in ' + cached_actor_filename)
        return True
    return False


def is_group_account(base_dir: str, nickname: str, domain: str) -> bool:
    """Returns true if the given account is a group
    """
    account_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if not os.path.isfile(account_filename):
        return False
    if text_in_file('"type": "Group"', account_filename):
        return True
    return False


def has_group_type(base_dir: str, actor: str, person_cache: {},
                   debug: bool = False) -> bool:
    """Does the given actor url have a group type?
    """
    # does the actor path clearly indicate that this is a group?
    # eg. https://lemmy/c/groupname
    group_paths = get_group_paths()
    for grp_path in group_paths:
        if grp_path in actor:
            if debug:
                print('grpPath ' + grp_path + ' in ' + actor)
            return True
    # is there a cached actor which can be examined for Group type?
    return is_group_actor(base_dir, actor, person_cache, debug)


def is_quote_toot(post_json_object: str, content: str) -> bool:
    """Returns true if the given post is a quote toot / quote tweet
    """
    if get_quote_toot_url(post_json_object):
        return True
    # Twitter-style indicator
    if content:
        if 'QT: ' in content:
            return True
    return False


def is_right_to_left_text(text: str) -> bool:
    """Is the given text right to left?
    Persian \u0600-\u06FF
    Arabic \u0627-\u064a
    Hebrew/Yiddish \u0590-\u05FF\uFB2A-\uFB4E
    """
    unicode_str = '[\u0627-\u064a]|[\u0600-\u06FF]|' + \
        '[\u0590-\u05FF\uFB2A-\uFB4E]'
    pattern = re.compile(unicode_str)

    return len(re.findall(pattern, text)) > (len(text)/2)


def is_valid_date(date_str: str) -> bool:
    """is the given date valid?
    """
    if not isinstance(date_str, str):
        return False
    if '-' not in date_str:
        return False
    date_sections = date_str.split('-')
    if len(date_sections) != 3:
        return False
    date_sect_ctr = 0
    for section_str in date_sections:
        if not section_str.isdigit():
            return False
        if date_sect_ctr == 0:
            date_year = int(section_str)
            if date_year < 1920 or date_year > 3000:
                return False
        elif date_sect_ctr == 1:
            date_month = int(section_str)
            if date_month < 1 or date_month > 12:
                return False
        elif date_sect_ctr == 2:
            date_day = int(section_str)
            if date_day < 1 or date_day > 31:
                return False
        date_sect_ctr += 1
    return True


def is_premium_account(base_dir: str, nickname: str, domain: str) -> bool:
    """ Is the given account a premium one?
    """
    premium_filename = acct_dir(base_dir, nickname, domain) + '/.premium'
    return os.path.isfile(premium_filename)


def url_permitted(url: str, federation_list: []) -> bool:
    """is the given url permitted?
    """
    if is_evil(url):
        return False
    if not federation_list:
        return True
    for domain in federation_list:
        if domain in url:
            return True
    return False
