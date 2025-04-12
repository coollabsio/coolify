__filename__ = "webapp_utils.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from shutil import copyfile
from collections import OrderedDict
from session import get_json
from session import get_json_valid
from flags import is_float
from utils import media_file_mime_type
from utils import replace_strings
from utils import get_image_file
from utils import data_dir
from utils import string_contains
from utils import get_post_attachments
from utils import image_mime_types_dict
from utils import get_url_from_post
from utils import get_media_url_from_video
from utils import get_attributed_to
from utils import local_network_host
from utils import dangerous_markup
from utils import acct_handle_dir
from utils import remove_id_ending
from utils import get_attachment_property_value
from utils import is_account_dir
from utils import remove_html
from utils import get_protocol_prefixes
from utils import load_json
from utils import get_cached_post_filename
from utils import get_config_param
from utils import acct_dir
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_audio_extensions
from utils import get_video_extensions
from utils import get_image_extensions
from utils import local_actor_url
from utils import text_in_file
from utils import remove_eol
from utils import binary_is_image
from utils import resembles_url
from filters import is_filtered
from cache import get_actor_public_key_from_id
from cache import store_person_in_cache
from content import add_html_tags
from content import replace_emoji_from_tags
from person import get_person_avatar_url
from person import get_person_notes
from posts import is_moderator
from blocking import is_blocked
from blocking import allowed_announce
from shares import vf_proposal_from_share
from webapp_pwa import get_pwa_theme_colors


def minimizing_attached_images(base_dir: str, nickname: str, domain: str,
                               following_nickname: str,
                               following_domain: str) -> bool:
    """Returns true if images from the account being followed should be
    minimized by default
    """
    if following_nickname == nickname and following_domain == domain:
        # reminder post
        return False
    minimize_filename = \
        acct_dir(base_dir, nickname, domain) + '/followingMinimizeImages.txt'
    handle = following_nickname + '@' + following_domain
    if not os.path.isfile(minimize_filename):
        following_filename = \
            acct_dir(base_dir, nickname, domain) + '/following.txt'
        if not os.path.isfile(following_filename):
            return False
        # create a new minimize file from the following file
        try:
            with open(minimize_filename, 'w+',
                      encoding='utf-8') as fp_min:
                fp_min.write('')
        except OSError:
            print('EX: minimizing_attached_images 2 ' + minimize_filename)
    return text_in_file(handle + '\n', minimize_filename, False)


def get_broken_link_substitute() -> str:
    """Returns html used to show a default image if the link to
    an image is broken
    """
    return " onerror=\"this.onerror=null; this.src='" + \
        "/icons/avatar_default.png'\""


def html_following_list(base_dir: str, following_filename: str) -> str:
    """Returns a list of handles being followed
    """
    msg = ''
    try:
        with open(following_filename, 'r', encoding='utf-8') as fp_following:
            msg = fp_following.read()
    except OSError:
        print('EX: html_following_list unable to read ' + following_filename)
    if msg:
        following_list = msg.split('\n')
        following_list.sort()
        if following_list:
            css_filename = base_dir + '/epicyon-profile.css'
            if os.path.isfile(base_dir + '/epicyon.css'):
                css_filename = base_dir + '/epicyon.css'

            instance_title = \
                get_config_param(base_dir, 'instanceTitle')
            preload_images: list[str] = []
            following_list_html = \
                html_header_with_external_style(css_filename,
                                                instance_title, None,
                                                preload_images)
            for following_address in following_list:
                if following_address:
                    following_list_html += \
                        '<h3>@' + following_address + '</h3>'
            following_list_html += html_footer()
            msg = following_list_html
        return msg
    return ''


def csv_following_list(following_filename: str,
                       base_dir: str, nickname: str, domain: str) -> str:
    """Returns a csv of handles being followed
    """
    msg = ''
    try:
        with open(following_filename, 'r', encoding='utf-8') as fp_following:
            msg = fp_following.read()
    except OSError:
        print('EX: csv_following_list unable to read ' + following_filename)
    if msg:
        following_list = msg.split('\n')
        following_list.sort()
        if following_list:
            following_list_csv = ''
            for following_address in following_list:
                if not following_address:
                    continue

                following_nickname = \
                    get_nickname_from_actor(following_address)
                following_domain, _ = \
                    get_domain_from_actor(following_address)

                announce_is_allowed = \
                    allowed_announce(base_dir, nickname, domain,
                                     following_nickname,
                                     following_domain)
                notify_on_new = 'false'
                languages = ''
                person_notes = \
                    get_person_notes(base_dir, nickname, domain,
                                     following_address)
                if person_notes:
                    # make notes suitable for csv file
                    replacements = {
                        ',': ' ',
                        '"': "'",
                        '\n': '<br>',
                        '  ': ' '
                    }
                    person_notes = replace_strings(person_notes, replacements)
                if not following_list_csv:
                    following_list_csv = \
                        'Account address,Show boosts,' + \
                        'Notify on new posts,Languages,Notes\n'
                following_list_csv += \
                    following_address + ',' + \
                    str(announce_is_allowed).lower() + ',' + \
                    notify_on_new + ',' + \
                    languages + ',' + \
                    person_notes + '\n'
            msg = following_list_csv
        return msg
    return ''


def html_hashtag_blocked(base_dir: str, translate: {}) -> str:
    """Show the screen for a blocked hashtag
    """
    blocked_hashtag_form = ''
    css_filename = base_dir + '/epicyon-suspended.css'
    if os.path.isfile(base_dir + '/suspended.css'):
        css_filename = base_dir + '/suspended.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    blocked_hashtag_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    blocked_hashtag_form += '<div><center>\n'
    blocked_hashtag_form += \
        '  <p class="screentitle">' + \
        translate['Hashtag Blocked'] + '</p>\n'
    blocked_hashtag_form += \
        '  <p>See <a href="/terms">' + \
        translate['Terms of Service'] + '</a></p>\n'
    blocked_hashtag_form += '</center></div>\n'
    blocked_hashtag_form += html_footer()
    return blocked_hashtag_form


def header_buttons_front_screen(translate: {},
                                nickname: str, box_name: str,
                                authorized: bool,
                                icons_as_buttons: bool) -> str:
    """Returns the header buttons for the front page of a news instance
    """
    header_str = ''
    if nickname == 'news':
        button_features = 'buttonMobile'
        button_newswire = 'buttonMobile'
        button_links = 'buttonMobile'
        if box_name == 'features':
            button_features = 'buttonselected'
        elif box_name == 'newswire':
            button_newswire = 'buttonselected'
        elif box_name == 'links':
            button_links = 'buttonselected'

        header_str += \
            '        <a href="/">' + \
            '<button class="' + button_features + '">' + \
            '<span>' + translate['Features'] + \
            '</span></button></a>'
        if not authorized:
            header_str += \
                '        <a href="/login">' + \
                '<button class="buttonMobile">' + \
                '<span>' + translate['Login'] + \
                '</span></button></a>'
        if icons_as_buttons:
            header_str += \
                '        <a href="/users/news/newswiremobile">' + \
                '<button class="' + button_newswire + '">' + \
                '<span>' + translate['Newswire'] + \
                '</span></button></a>'
            header_str += \
                '        <a href="/users/news/linksmobile">' + \
                '<button class="' + button_links + '">' + \
                '<span>' + translate['Links'] + \
                '</span></button></a>'
        else:
            header_str += \
                '        <a href="' + \
                '/users/news/newswiremobile">' + \
                '<img loading="lazy" decoding="async" src="/icons' + \
                '/newswire.png" title="' + translate['Newswire'] + \
                '" alt="| ' + translate['Newswire'] + '"/></a>\n'
            header_str += \
                '        <a href="' + \
                '/users/news/linksmobile">' + \
                '<img loading="lazy" decoding="async" src="/icons' + \
                '/links.png" title="' + translate['Links'] + \
                '" alt="| ' + translate['Links'] + '"/></a>\n'
    else:
        if not authorized:
            header_str += \
                '        <a href="/login">' + \
                '<button class="buttonMobile">' + \
                '<span>' + translate['Login'] + \
                '</span></button></a>'

    if header_str:
        header_str = \
            '\n      <div class="frontPageMobileButtons">\n' + \
            header_str + \
            '      </div>\n'
    return header_str


def get_content_warning_button(post_id: str, translate: {},
                               content: str) -> str:
    """Returns the markup for a content warning button
    """
    return '       <details><summary class="cw" tabindex="10">' + \
        translate['SHOW MORE'] + '</summary>' + \
        '<div id="' + post_id + '">' + content + \
        '</div></details>\n'


def open_content_warning(text: str, translate: {}) -> str:
    """Opens content warning when replying to a post with a cw
    so that you can see what you are replying to
    """
    text = text.replace('<details>', '').replace('</details>', '')
    return text.replace(translate['SHOW MORE'], '', 1)


def _set_actor_property_url(actor_json: {},
                            property_name: str, url: str) -> None:
    """Sets a url for the given actor property
    """
    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    property_name_lower = property_name.lower()

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
        if not name_value.lower().startswith(property_name_lower):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)

    prefixes = get_protocol_prefixes()
    prefix_found = False
    for prefix in prefixes:
        if url.startswith(prefix):
            prefix_found = True
            break
    if not prefix_found:
        return
    if '.' not in url:
        return
    if ' ' in url:
        return
    if ',' in url:
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
        if not name_value.lower().startswith(property_name_lower):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = url
        return

    new_address = {
        "name": property_name,
        "type": "PropertyValue",
        "value": url
    }
    actor_json['attachment'].append(new_address)


def set_blog_address(actor_json: {}, blog_address: str) -> None:
    """Sets an blog address for the given actor
    """
    _set_actor_property_url(actor_json, 'Blog', remove_html(blog_address))


def update_avatar_image_cache(signing_priv_key_pem: str,
                              session, base_dir: str, http_prefix: str,
                              actor: str, avatar_url: str,
                              person_cache: {}, allow_downloads: bool,
                              mitm_servers: [],
                              force: bool = False, debug: bool = False) -> str:
    """Updates the cached avatar for the given actor
    """
    if not avatar_url:
        return None
    actor_str = actor.replace('/', '-')
    avatar_image_path = base_dir + '/cache/avatars/' + actor_str

    # try different image types
    image_formats = image_mime_types_dict()
    avatar_image_filename = None
    session_headers = None
    for im_format, mime_type in image_formats.items():
        if avatar_url.endswith('.' + im_format) or \
           '.' + im_format + '?' in avatar_url:
            session_headers = {
                'Accept': 'image/' + mime_type
            }
            avatar_image_filename = avatar_image_path + '.' + im_format

    if not avatar_image_filename or not session_headers:
        return None

    if (not os.path.isfile(avatar_image_filename) or force) and \
       allow_downloads:
        try:
            if debug:
                print('avatar image url: ' + avatar_url)
            result = session.get(avatar_url,
                                 headers=session_headers,
                                 params=None,
                                 allow_redirects=True)
            if result.status_code < 200 or \
               result.status_code > 202:
                if debug:
                    print('Avatar image download failed with status ' +
                          str(result.status_code))
                # remove partial download
                if os.path.isfile(avatar_image_filename):
                    try:
                        os.remove(avatar_image_filename)
                    except OSError:
                        print('EX: ' +
                              'update_avatar_image_cache unable to delete ' +
                              avatar_image_filename)
            else:
                media_binary = result.content
                if binary_is_image(avatar_image_filename, media_binary):
                    with open(avatar_image_filename, 'wb') as fp_av:
                        fp_av.write(media_binary)
                        if debug:
                            print('avatar image downloaded for ' + actor)
                        return avatar_image_filename.replace(base_dir +
                                                             '/cache', '')
                else:
                    print('WARN: update_avatar_image_cache ' +
                          'avatar image binary not recognized ' +
                          actor + ' ' + str(media_binary[0:20]))
        except BaseException as ex:
            print('EX: Failed to download avatar image: ' +
                  str(avatar_url) + ' ' + str(ex))
        prof = 'https://www.w3.org/ns/activitystreams'
        if '/channel/' not in actor or '/accounts/' not in actor:
            session_headers = {
                'Accept': 'application/activity+json; profile="' + prof + '"'
            }
        else:
            session_headers = {
                'Accept': 'application/ld+json; profile="' + prof + '"'
            }
        person_json = \
            get_json(signing_priv_key_pem, session, actor,
                     session_headers, None,
                     debug, mitm_servers, __version__, http_prefix, None)
        if get_json_valid(person_json):
            if not person_json.get('id'):
                return None
            pub_key, _ = get_actor_public_key_from_id(person_json, None)
            if not pub_key:
                return None
            if person_json['id'] != actor:
                return None
            if not person_cache.get(actor):
                return None
            cache_key, _ = \
                get_actor_public_key_from_id(person_cache[actor]['actor'],
                                             None)
            if cache_key != pub_key:
                print("ERROR: " +
                      "public keys don't match when downloading actor for " +
                      actor)
                return None
            store_person_in_cache(base_dir, actor, person_json, person_cache,
                                  allow_downloads)
            return get_person_avatar_url(base_dir, actor, person_cache)
        return None
    return avatar_image_filename.replace(base_dir + '/cache', '')


def scheduled_posts_exist(base_dir: str, nickname: str, domain: str) -> bool:
    """Returns true if there are posts scheduled to be delivered
    """
    schedule_index_filename = \
        acct_dir(base_dir, nickname, domain) + '/schedule.index'
    if not os.path.isfile(schedule_index_filename):
        return False
    if text_in_file('#users#', schedule_index_filename):
        return True
    return False


def shares_timeline_json(actor: str, page_number: int, items_per_page: int,
                         base_dir: str, domain: str, nickname: str,
                         max_shares_per_account: int,
                         shared_items_federated_domains: [],
                         shares_file_type: str) -> ({}, bool):
    """Get a page on the shared items timeline as json
    max_shares_per_account helps to avoid one person dominating the timeline
    by sharing a large number of things
    """
    all_shares_json = {}
    dir_str = data_dir(base_dir)
    for _, dirs, files in os.walk(dir_str):
        for handle in dirs:
            if not is_account_dir(handle):
                continue
            account_dir = acct_handle_dir(base_dir, handle)
            shares_filename = account_dir + '/' + shares_file_type + '.json'
            if not os.path.isfile(shares_filename):
                continue
            shares_json = load_json(shares_filename)
            if not shares_json:
                continue
            account_nickname = handle.split('@')[0]
            # Don't include shared items from blocked accounts
            if account_nickname != nickname:
                if is_blocked(base_dir, nickname, domain,
                              account_nickname, domain, None, None):
                    continue
            # actor who owns this share
            owner = actor.split('/users/')[0] + '/users/' + account_nickname
            ctr = 0
            for item_id, item in shares_json.items():
                # assign owner to the item
                item['actor'] = owner
                item['shareId'] = item_id
                all_shares_json[str(item['published'])] = item
                ctr += 1
                if ctr >= max_shares_per_account:
                    break
        break
    if shared_items_federated_domains:
        if shares_file_type == 'shares':
            catalogs_dir = base_dir + '/cache/catalogs'
        else:
            catalogs_dir = base_dir + '/cache/wantedItems'
        if os.path.isdir(catalogs_dir):
            for _, dirs, files in os.walk(catalogs_dir):
                for fname in files:
                    if '#' in fname:
                        continue
                    if not fname.endswith('.' + shares_file_type + '.json'):
                        continue
                    federated_domain = fname.split('.')[0]
                    if federated_domain not in shared_items_federated_domains:
                        continue
                    shares_filename = catalogs_dir + '/' + fname
                    shares_json = load_json(shares_filename)
                    if not shares_json:
                        continue
                    ctr = 0
                    for item_id, item in shares_json.items():
                        # assign owner to the item
                        if '--shareditems--' not in item_id:
                            continue
                        share_actor = item_id.split('--shareditems--')[0]
                        replacements = {
                            '___': '://',
                            '--': '/'
                        }
                        share_actor = \
                            replace_strings(share_actor, replacements)
                        share_nickname = get_nickname_from_actor(share_actor)
                        if not share_nickname:
                            continue
                        if is_blocked(base_dir, nickname, domain,
                                      share_nickname, federated_domain,
                                      None, None):
                            continue
                        item['actor'] = share_actor
                        item['shareId'] = item_id
                        all_shares_json[str(item['published'])] = item
                        ctr += 1
                        if ctr >= max_shares_per_account:
                            break
                break
    # sort the shared items in descending order of publication date
    shares_json = OrderedDict(sorted(all_shares_json.items(), reverse=True))
    last_page = False
    start_index = items_per_page * page_number
    max_index = len(shares_json.items())
    if max_index < items_per_page:
        last_page = True
    if start_index >= max_index - items_per_page:
        last_page = True
        start_index = max_index - items_per_page
        start_index = max(start_index, 0)
    ctr = 0
    result_json = {}
    for published, item in shares_json.items():
        if ctr >= start_index + items_per_page:
            break
        if ctr < start_index:
            ctr += 1
            continue
        result_json[published] = item
        ctr += 1
    return result_json, last_page


def get_shares_collection(actor: str, page_number: int, items_per_page: int,
                          base_dir: str, domain: str, nickname: str,
                          max_shares_per_account: int,
                          shared_items_federated_domains: [],
                          shares_file_type: str) -> {}:
    """Returns an ActivityStreams collection of ValueFlows Proposal objects
    https://codeberg.org/fediverse/fep/src/branch/main/fep/0837/fep-0837.md
    """
    shares_collection: list[dict] = []
    shares_json, _ = \
        shares_timeline_json(actor, page_number, items_per_page,
                             base_dir, domain, nickname,
                             max_shares_per_account,
                             shared_items_federated_domains, shares_file_type)

    if shares_file_type == 'shares':
        share_type = 'offer'
        collection_name = nickname + "'s Shared Items"
    else:
        share_type = 'request'
        collection_name = nickname + "'s Wanted Items"

    for share_id, shared_item in shares_json.items():
        shared_item['shareId'] = share_id
        shared_item['actor'] = actor
        offer_item = vf_proposal_from_share(shared_item, share_type)
        if offer_item:
            shares_collection.append(offer_item)

    result_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        "id": actor + '/' + share_type + 's?page=' + str(page_number),
        "sharedItemsOf": actor,
        "type": "OrderedCollection",
        "name": collection_name,
        "orderedItems": shares_collection
    }

    return result_json


def post_contains_public(post_json_object: {}) -> bool:
    """Does the given post contain #Public
    """
    contains_public = False
    if not post_json_object['object'].get('to'):
        return contains_public

    for to_address in post_json_object['object']['to']:
        if to_address.endswith('#Public') or \
           to_address == 'as:Public' or \
           to_address == 'Public':
            contains_public = True
            break
        if not contains_public:
            if post_json_object['object'].get('cc'):
                for to_address2 in post_json_object['object']['cc']:
                    if to_address2.endswith('#Public') or \
                       to_address2 == 'as:Public' or \
                       to_address2 == 'Public':
                        contains_public = True
                        break
    return contains_public


def get_banner_file(base_dir: str,
                    nickname: str, domain: str, theme: str) -> (str, str):
    """Gets the image for the timeline banner
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    banner_file, banner_filename = \
        get_image_file(base_dir, 'banner', account_dir, theme)
    return banner_file, banner_filename


def get_profile_background_file(base_dir: str,
                                nickname: str, domain: str,
                                theme: str) -> (str, str):
    """Gets the image for the profile background
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    banner_file, banner_filename = \
        get_image_file(base_dir, 'image', account_dir, theme)
    return banner_file, banner_filename


def get_search_banner_file(base_dir: str,
                           nickname: str, domain: str,
                           theme: str) -> (str, str):
    """Gets the image for the search banner
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    banner_file, banner_filename = \
        get_image_file(base_dir, 'search_banner', account_dir, theme)
    return banner_file, banner_filename


def get_left_image_file(base_dir: str,
                        nickname: str, domain: str, theme: str) -> (str, str):
    """Gets the image for the left column
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    banner_file, banner_filename = \
        get_image_file(base_dir, 'left_col_image', account_dir, theme)
    return banner_file, banner_filename


def get_right_image_file(base_dir: str,
                         nickname: str, domain: str, theme: str) -> (str, str):
    """Gets the image for the right column
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    banner_file, banner_filename = \
        get_image_file(base_dir, 'right_col_image', account_dir, theme)
    return banner_file, banner_filename


def html_header_with_external_style(css_filename: str, instance_title: str,
                                    metadata: str, preload_images: [],
                                    lang='en') -> str:
    if metadata is None:
        metadata = ''
    css_file = '/' + css_filename.split('/')[-1]
    pwa_theme_color, pwa_theme_background_color = \
        get_pwa_theme_colors(css_filename)
    preload_images_str = ''
    if preload_images:
        for image_path in preload_images:
            preload_images_str += \
                '    <link rel="preload" as="image" href="' + \
                image_path + '">\n'
    html_str = \
        '<!DOCTYPE html>\n' + \
        '<!--\n' + \
        'Thankyou for using Epicyon. If you are reading this message then ' + \
        'consider joining the development at ' + \
        'https://gitlab.com/bashrc2/epicyon\n' + \
        '-->\n' + \
        '<html lang="' + lang + '">\n' + \
        '  <head>\n' + \
        '    <meta charset="utf-8">\n' + \
        '    <link rel="preload" href="' + css_file + '" as="style">\n' + \
        '    <link rel="stylesheet" media="all" ' + \
        'href="' + css_file + '">\n' + \
        '    <link rel="manifest" href="/manifest.json">\n' + \
        '    <link href="/favicon.ico" rel="icon" type="image/x-icon">\n' + \
        '    <meta content="/browserconfig.xml" ' + \
        'name="msapplication-config">\n' + \
        '    <meta content="yes" name="apple-mobile-web-app-capable">\n' + \
        '    <link href="/apple-touch-icon.png" rel="apple-touch-icon" ' + \
        'sizes="180x180">\n' + preload_images_str + \
        '    <meta name="theme-color" content="' + pwa_theme_color + '">\n' + \
        metadata + \
        '    <meta name="apple-mobile-web-app-status-bar-style" ' + \
        'content="' + pwa_theme_background_color + '">\n' + \
        '    <title>' + instance_title + '</title>\n' + \
        '  </head>\n' + \
        '  <body>\n'
    return html_str


def html_header_with_person_markup(css_filename: str, instance_title: str,
                                   actor_json: {}, city: str,
                                   content_license_url: str,
                                   lang='en') -> str:
    """html header which includes person markup
    https://schema.org/Person
    """
    if not actor_json:
        preload_images: list[str] = []
        html_str = \
            html_header_with_external_style(css_filename,
                                            instance_title, None,
                                            preload_images, lang)
        return html_str

    city_markup = ''
    if city:
        city = city.lower().title()
        add_comma = ''
        country_markup = ''
        if ',' in city:
            country = city.split(',', 1)[1].strip().title()
            city = city.split(',', 1)[0]
            country_markup = \
                '          "addressCountry": "' + country + '"\n'
            add_comma = ','
        city_markup = \
            '        "address": {\n' + \
            '          "@type": "PostalAddress",\n' + \
            '          "addressLocality": "' + city + '"' + \
            add_comma + '\n' + country_markup + '        },\n'

    skills_markup = ''
    if actor_json.get('hasOccupation'):
        if isinstance(actor_json['hasOccupation'], list):
            skills_markup = '        "hasOccupation": [\n'
            first_entry = True
            for skill_dict in actor_json['hasOccupation']:
                if skill_dict['@type'] == 'Role':
                    if not first_entry:
                        skills_markup += ',\n'
                    skl = skill_dict['hasOccupation']
                    role_name = skl['name']
                    if not role_name:
                        role_name = 'member'
                    category = \
                        skl['occupationalCategory']['codeValue']
                    category_url = \
                        'https://www.onetonline.org/link/summary/' + category
                    skills_markup += \
                        '        {\n' + \
                        '          "@type": "Role",\n' + \
                        '          "hasOccupation": {\n' + \
                        '            "@type": "Occupation",\n' + \
                        '            "name": "' + role_name + '",\n' + \
                        '            "description": ' + \
                        '"Fediverse instance role",\n' + \
                        '            "occupationLocation": {\n' + \
                        '              "@type": "City",\n' + \
                        '              "name": "' + city + '"\n' + \
                        '            },\n' + \
                        '            "occupationalCategory": {\n' + \
                        '              "@type": "CategoryCode",\n' + \
                        '              "inCodeSet": {\n' + \
                        '                "@type": "CategoryCodeSet",\n' + \
                        '                "name": "O*Net-SOC",\n' + \
                        '                "dateModified": "2019",\n' + \
                        '                ' + \
                        '"url": "https://www.onetonline.org/"\n' + \
                        '              },\n' + \
                        '              "codeValue": "' + category + '",\n' + \
                        '              "url": "' + category_url + '"\n' + \
                        '            }\n' + \
                        '          }\n' + \
                        '        }'
                elif skill_dict['@type'] == 'Occupation':
                    if not first_entry:
                        skills_markup += ',\n'
                    oc_name = skill_dict['name']
                    if not oc_name:
                        oc_name = 'member'
                    skills_list = skill_dict['skills']
                    skills_list_str = '['
                    for skill_str in skills_list:
                        if skills_list_str != '[':
                            skills_list_str += ', '
                        skills_list_str += '"' + skill_str + '"'
                    skills_list_str += ']'
                    skills_markup += \
                        '        {\n' + \
                        '          "@type": "Occupation",\n' + \
                        '          "name": "' + oc_name + '",\n' + \
                        '          "description": ' + \
                        '"Fediverse instance occupation",\n' + \
                        '          "occupationLocation": {\n' + \
                        '            "@type": "City",\n' + \
                        '            "name": "' + city + '"\n' + \
                        '          },\n' + \
                        '          "skills": ' + skills_list_str + '\n' + \
                        '        }'
                first_entry = False
            skills_markup += '\n        ],\n'

    description = ''
    if actor_json.get('summary'):
        description = remove_html(actor_json['summary'])
    name_str = remove_html(actor_json['name'])
    domain_full = actor_json['id'].split('://')[1].split('/')[0]
    handle = actor_json['preferredUsername'] + '@' + domain_full

    url_str = get_url_from_post(actor_json['icon']['url'])
    icon_url = remove_html(url_str)
    person_markup = \
        '      "about": {\n' + \
        '        "@type" : "Person",\n' + \
        '        "name": "' + name_str + '",\n' + \
        '        "image": "' + icon_url + '",\n' + \
        '        "description": "' + description + '",\n' + \
        city_markup + skills_markup + \
        '        "url": "' + actor_json['id'] + '"\n' + \
        '      },\n'

    profile_markup = \
        '    <script id="initial-state" type="application/ld+json">\n' + \
        '    {\n' + \
        '      "@context":"https://schema.org",\n' + \
        '      "@type": "ProfilePage",\n' + \
        '      "mainEntityOfPage": {\n' + \
        '        "@type": "WebPage",\n' + \
        "        \"@id\": \"" + actor_json['id'] + "\"\n" + \
        '      },\n' + person_markup + \
        '      "accountablePerson": {\n' + \
        '        "@type": "Person",\n' + \
        '        "name": "' + name_str + '"\n' + \
        '      },\n' + \
        '      "copyrightHolder": {\n' + \
        '        "@type": "Person",\n' + \
        '        "name": "' + name_str + '"\n' + \
        '      },\n' + \
        '      "name": "' + name_str + '",\n' + \
        '      "image": "' + icon_url + '",\n' + \
        '      "description": "' + description + '",\n' + \
        '      "license": "' + content_license_url + '"\n' + \
        '    }\n' + \
        '    </script>\n'

    description = remove_html(description)
    url_str = get_url_from_post(actor_json['url'])
    actor2_url = remove_html(url_str)
    og_metadata = \
        "    <meta content=\"profile\" property=\"og:type\" />\n" + \
        "    <meta content=\"" + description + \
        "\" name='description'>\n" + \
        "    <meta content=\"" + actor2_url + \
        "\" property=\"og:url\" />\n" + \
        "    <meta content=\"" + domain_full + \
        "\" property=\"og:site_name\" />\n" + \
        "    <meta content=\"" + name_str + " (@" + handle + \
        ")\" property=\"og:title\" />\n" + \
        "    <meta content=\"" + description + \
        "\" property=\"og:description\" />\n" + \
        "    <meta content=\"" + icon_url + \
        "\" property=\"og:image\" />\n" + \
        "    <meta content=\"400\" property=\"og:image:width\" />\n" + \
        "    <meta content=\"400\" property=\"og:image:height\" />\n" + \
        "    <meta content=\"summary\" property=\"twitter:card\" />\n" + \
        "    <meta content=\"" + handle + \
        "\" property=\"profile:username\" />\n"
    if actor_json.get('attachment'):
        og_tags = (
            'email', 'openpgp', 'blog', 'xmpp', 'matrix', 'briar',
            'cwtch', 'languages'
        )
        for attach_json in actor_json['attachment']:
            if not attach_json.get('name'):
                if not attach_json.get('schema:name'):
                    continue
            prop_value_name, _ = get_attachment_property_value(attach_json)
            if not prop_value_name:
                continue
            if attach_json.get('name'):
                name = attach_json['name'].lower()
            else:
                name = attach_json['schema:name'].lower()
            value = attach_json[prop_value_name]
            for og_tag in og_tags:
                if name != og_tag:
                    continue
                og_metadata += \
                    "    <meta content=\"" + value + \
                    "\" property=\"og:" + og_tag + "\" />\n"

    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title,
                                        og_metadata + profile_markup,
                                        preload_images, lang)
    return html_str


def html_header_with_website_markup(css_filename: str, instance_title: str,
                                    http_prefix: str, domain: str,
                                    system_language: str) -> str:
    """html header which includes website markup
    https://schema.org/WebSite
    """
    license_url = 'https://www.gnu.org/licenses/agpl-3.0.rdf'

    # social networking category
    genre_url = 'http://vocab.getty.edu/aat/300312270'

    website_markup = \
        '    <script id="initial-state" type="application/ld+json">\n' + \
        '    {\n' + \
        '      "@context" : "http://schema.org",\n' + \
        '      "@type" : "WebSite",\n' + \
        '      "name": "' + instance_title + '",\n' + \
        '      "url": "' + http_prefix + '://' + domain + '",\n' + \
        '      "license": "' + license_url + '",\n' + \
        '      "inLanguage": "' + system_language + '",\n' + \
        '      "isAccessibleForFree": true,\n' + \
        '      "genre": "' + genre_url + '",\n' + \
        '      "accessMode": ["textual", "visual"],\n' + \
        '      "accessModeSufficient": ["textual"],\n' + \
        '      "accessibilityAPI" : ["ARIA"],\n' + \
        '      "accessibilityControl" : [\n' + \
        '        "fullKeyboardControl",\n' + \
        '        "fullTouchControl",\n' + \
        '        "fullMouseControl"\n' + \
        '      ],\n' + \
        '      "encodingFormat" : [\n' + \
        '        "text/html", "image/png", "image/webp",\n' + \
        '        "image/jpeg", "image/gif", "text/css"\n' + \
        '      ]\n' + \
        '    }\n' + \
        '    </script>\n'

    og_metadata = \
        '    <meta content="Epicyon hosted on ' + domain + \
        '" property="og:site_name" />\n' + \
        '    <meta content="' + http_prefix + '://' + domain + \
        '/about" property="og:url" />\n' + \
        '    <meta content="website" property="og:type" />\n' + \
        '    <meta content="' + instance_title + \
        '" property="og:title" />\n' + \
        '    <meta content="' + http_prefix + '://' + domain + \
        '/logo.png" property="og:image" />\n' + \
        '    <meta content="' + system_language + \
        '" property="og:locale" />\n' + \
        '    <meta content="summary_large_image" property="twitter:card" />\n'

    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title,
                                        og_metadata + website_markup,
                                        preload_images, system_language)
    return html_str


def html_header_with_blog_markup(css_filename: str, instance_title: str,
                                 http_prefix: str, domain: str, nickname: str,
                                 system_language: str,
                                 published: str, modified: str,
                                 title: str, snippet: str, url: str,
                                 content_license_url: str) -> str:
    """html header which includes blog post markup
    https://schema.org/BlogPosting
    """
    author_url = local_actor_url(http_prefix, nickname, domain)
    about_url = http_prefix + '://' + domain + '/about.html'

    # license for content on the site may be different from
    # the software license

    blog_markup = \
        '    <script id="initial-state" type="application/ld+json">\n' + \
        '    {\n' + \
        '      "@context" : "http://schema.org",\n' + \
        '      "@type" : "BlogPosting",\n' + \
        '      "headline": "' + title + '",\n' + \
        '      "datePublished": "' + published + '",\n' + \
        '      "dateModified": "' + modified + '",\n' + \
        '      "author": {\n' + \
        '        "@type": "Person",\n' + \
        '        "name": "' + nickname + '",\n' + \
        '        "sameAs": "' + author_url + '"\n' + \
        '      },\n' + \
        '      "publisher": {\n' + \
        '        "@type": "WebSite",\n' + \
        '        "name": "' + instance_title + '",\n' + \
        '        "sameAs": "' + about_url + '"\n' + \
        '      },\n' + \
        '      "license": "' + content_license_url + '",\n' + \
        '      "description": "' + snippet + '"\n' + \
        '    }\n' + \
        '    </script>\n'

    og_metadata = \
        '    <meta property="og:locale" content="' + \
        system_language + '" />\n' + \
        '    <meta property="og:type" content="article" />\n' + \
        '    <meta property="og:title" content="' + title + '" />\n' + \
        '    <meta property="og:url" content="' + url + '" />\n' + \
        '    <meta content="Epicyon hosted on ' + domain + \
        '" property="og:site_name" />\n' + \
        '    <meta property="article:published_time" content="' + \
        published + '" />\n' + \
        '    <meta property="article:modified_time" content="' + \
        modified + '" />\n'

    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title,
                                        og_metadata + blog_markup,
                                        preload_images, system_language)
    return html_str


def html_footer() -> str:
    html_str = '  </body>\n'
    html_str += '</html>\n'
    return html_str


def load_individual_post_as_html_from_cache(base_dir: str,
                                            nickname: str, domain: str,
                                            post_json_object: {}) -> str:
    """If a cached html version of the given post exists then load it and
    return the html text
    This is much quicker than generating the html from the json object
    """
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, post_json_object)

    post_html = ''
    if not cached_post_filename:
        return post_html

    if not os.path.isfile(cached_post_filename):
        return post_html

    tries = 0
    while tries < 3:
        try:
            with open(cached_post_filename, 'r',
                      encoding='utf-8') as fp_cached:
                post_html = fp_cached.read()
                break
        except OSError as ex:
            print('ERROR: load_individual_post_as_html_from_cache ' +
                  str(tries) + ' ' + str(ex))
            # no sleep
            tries += 1
    if post_html:
        return post_html


def add_emoji_to_display_name(session, base_dir: str, http_prefix: str,
                              nickname: str, domain: str,
                              display_name: str, in_profile_name: bool,
                              translate: {}) -> str:
    """Adds emoji icons to display names or CW on individual posts
    """
    if ':' not in display_name:
        return display_name

    replacements = {
        '<p>': '',
        '</p>': ''
    }
    display_name = replace_strings(display_name, replacements)
    emoji_tags = {}
#    print('TAG: display_name before tags: ' + display_name)
    display_name = \
        add_html_tags(base_dir, http_prefix,
                      nickname, domain, display_name, [],
                      emoji_tags, translate)
    display_name = replace_strings(display_name, replacements)
#    print('TAG: display_name after tags: ' + display_name)
    # convert the emoji dictionary to a list
    emoji_tags_list: list[str] = []
    for _, tag in emoji_tags.items():
        emoji_tags_list.append(tag)
#    print('TAG: emoji tags list: ' + str(emoji_tags_list))
    if not in_profile_name:
        display_name = \
            replace_emoji_from_tags(session, base_dir,
                                    display_name, emoji_tags_list,
                                    'post header', False, False)
    else:
        display_name = \
            replace_emoji_from_tags(session, base_dir,
                                    display_name, emoji_tags_list, 'profile',
                                    False, False)
#    print('TAG: display_name after tags 2: ' + display_name)

    # remove any stray emoji
    while ':' in display_name:
        if '://' in display_name:
            break
        emoji_str = display_name.split(':')[1]
        prev_display_name = display_name
        display_name = display_name.replace(':' + emoji_str + ':', '').strip()
        if prev_display_name == display_name:
            break
#        print('TAG: display_name after tags 3: ' + display_name)
#    print('TAG: display_name after tag replacements: ' + display_name)

    return display_name


def _is_image_mime_type(mime_type: str) -> bool:
    """Is the given mime type an image?
    """
    if mime_type == 'image/svg+xml':
        return True
    if not mime_type.startswith('image/'):
        return False
    extensions = get_image_extensions()
    ext = mime_type.split('/')[1]
    if ext in extensions:
        return True
    return False


def _is_video_mime_type(mime_type: str) -> bool:
    """Is the given mime type a video?
    """
    if not mime_type.startswith('video/'):
        return False
    extensions = get_video_extensions()
    ext = mime_type.split('/')[1]
    if ext in extensions:
        return True
    return False


def _is_audio_mime_type(mime_type: str) -> bool:
    """Is the given mime type an audio file?
    """
    if mime_type == 'audio/mpeg':
        return True
    if not mime_type.startswith('audio/'):
        return False
    extensions = get_audio_extensions()
    ext = mime_type.split('/')[1]
    if ext in extensions:
        return True
    return False


def _is_attached_image(attachment_url: str) -> bool:
    """Is the given attachment url an image?
    """
    if '.' not in attachment_url:
        return False
    image_ext = get_image_extensions()
    ext = attachment_url.split('.')[-1]
    if ext in image_ext:
        return True
    if '/' in attachment_url:
        # this might still be an image, but without a file extension
        last_part = attachment_url.split('/')[-1]
        if '.' not in last_part:
            return True
    return False


def _is_attached_video(attachment_filename: str) -> bool:
    """Is the given attachment filename a video?
    """
    if '.' not in attachment_filename:
        return False
    video_ext = (
        'mp4', 'webm', 'ogv'
    )
    ext = attachment_filename.split('.')[-1]
    if ext in video_ext:
        return True
    return False


def _is_nsfw(content: str) -> bool:
    """Does the given content indicate nsfw?
    """
    content_lower = content.lower()
    nsfw_tags = (
        'nsfw', 'porn', 'pr0n', 'explicit', 'lewd',
        'nude', 'boob', 'erotic', 'sex'
    )
    for tag_name in nsfw_tags:
        if tag_name in content_lower:
            return True
    return False


def get_post_attachments_as_html(base_dir: str,
                                 nickname: str, domain: str,
                                 domain_full: str,
                                 post_json_object: {}, box_name: str,
                                 translate: {},
                                 is_muted: bool, avatar_link: str,
                                 reply_str: str, announce_str: str,
                                 like_str: str,
                                 bookmark_str: str, delete_str: str,
                                 mute_str: str,
                                 content: str,
                                 minimize_all_images: bool,
                                 system_language: str) -> (str, str):
    """Returns a string representing any attachments
    """
    attachment_str = ''
    attachment_ctr = 0
    gallery_str = ''
    attachment_dict: list[dict] = []

    # handle peertube-style video posts, where the media links
    # are stored in the url field
    if post_json_object.get('object'):
        media_type, media_url, _, _ = \
            get_media_url_from_video(post_json_object['object'])
    else:
        media_type, media_url, _, _ = \
            get_media_url_from_video(post_json_object)
    if media_url and media_type:
        attachment_dict = [{
            'mediaType': media_type,
            'name': content,
            'type': 'Document',
            'url': media_url
        }]
        post_attachments = get_post_attachments(post_json_object)
        if not post_attachments:
            post_json_object['object']['attachment'] = \
                attachment_dict

    post_attachments = get_post_attachments(post_json_object)
    if not post_attachments:
        return attachment_str, gallery_str

    attachment_dict += post_attachments

    media_style_added = False
    post_id = None
    if post_json_object['object'].get('id'):
        post_id = post_json_object['object']['id']
        post_id = remove_id_ending(post_id).replace('/', '--')

    # chat links
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/1970/fep-1970.md
    attached_urls: list[str] = []
    for attach in attachment_dict:
        url = None
        if attach.get('url'):
            url = get_url_from_post(attach['url'])
        elif attach.get('href'):
            url = attach['href']

        if not attach.get('type') or \
           not attach.get('name') or \
           not url or \
           not attach.get('rel'):
            continue
        if not isinstance(attach['type'], str) or \
           not isinstance(attach['name'], str) or \
           not isinstance(url, str) or \
           not isinstance(attach['rel'], str):
            continue
        if attach['type'] != 'Link' or \
           attach['name'] != 'Chat' or \
           attach['rel'] != 'discussion' or \
           '://' not in attach['href'] or \
           '.' not in url:
            continue
        # get the domain for the chat link
        chat_domain_str = ''
        attach_url = remove_html(url)
        if attach_url in attached_urls:
            continue
        attached_urls.append(attach_url)
        chat_domain, _ = get_domain_from_actor(attach_url)
        if chat_domain:
            if local_network_host(chat_domain):
                print('REJECT: local network chat link ' + url)
                continue
            chat_domain_str = ' (' + chat_domain + ')'
            # avoid displaying very long domains
            if len(chat_domain_str) > 50:
                chat_domain_str = ''
        chat_url = remove_html(url)
        attachment_str += \
            '<p><a href="' + chat_url + \
            '" target="_blank" rel="nofollow noopener noreferrer">' + \
            '💬 ' + translate['Chat'] + chat_domain_str + '</a></p>'

    # obtain transcripts
    transcripts = {}
    for attach in attachment_dict:
        if not attach.get('mediaType'):
            continue
        if attach['mediaType'] != 'text/vtt':
            continue
        name = None
        if attach.get('name'):
            name = attach['name']
        if attach.get('nameMap'):
            for name_lang, name_value in attach['nameMap'].items():
                if not isinstance(name_value, str):
                    continue
                if name_lang.startswith(system_language):
                    name = name_value
        if not name and attach.get('hreflang'):
            name = attach['hreflang']
        url = None
        if attach.get('url'):
            url = get_url_from_post(attach['url'])
        elif attach.get('href'):
            url = attach['href']
        if name and url:
            transcripts[name] = remove_html(url)

    for attach in attachment_dict:
        # get the image/video/audio url
        url = None
        if attach.get('url'):
            url = get_url_from_post(attach['url'])
        elif attach.get('href'):
            url = attach['href']

        if not url:
            # this is not an image/video/audio attachment
            continue
        if not isinstance(url, str):
            continue

        # get the media type
        media_type = None
        if attach.get('mediaType'):
            media_type = attach['mediaType']
        else:
            # See https://data.funfedi.dev/0.1.12/mastodon__v4.3.2/
            # image_attachments/#example-13
            if url and attach.get('type'):
                if attach['type'] == 'Image':
                    if attach.get('url'):
                        if isinstance(attach['url'], dict):
                            if attach['url'].get('mediaType'):
                                media_type = attach['url']['mediaType']
                    if not media_type:
                        url_ending = url
                        if '/' in url:
                            url_ending = url.split('/')[-1]
                        if '.' in url_ending:
                            media_type = media_file_mime_type(url)

        if not media_type:
            # this is not an image/video/audio attachment
            continue
        if not isinstance(media_type, str):
            continue

        media_license = ''
        if attach.get('schema:license'):
            if not dangerous_markup(attach['schema:license'], False, []):
                if not is_filtered(base_dir, nickname, domain,
                                   attach['schema:license'],
                                   system_language):
                    if '://' not in attach['schema:license']:
                        if len(attach['schema:license']) < 60:
                            media_license = attach['schema:license']
                    else:
                        media_license = attach['schema:license']
        elif attach.get('license'):
            if not dangerous_markup(attach['license'], False, []):
                if not is_filtered(base_dir, nickname, domain,
                                   attach['license'],
                                   system_language):
                    if '://' not in attach['license']:
                        if len(attach['license']) < 60:
                            media_license = attach['license']
                    else:
                        media_license = attach['license']
        media_creator = ''
        if attach.get('schema:creator'):
            if len(attach['schema:creator']) < 120:
                if not dangerous_markup(attach['schema:creator'], False, []):
                    if not is_filtered(base_dir, nickname, domain,
                                       attach['schema:creator'],
                                       system_language):
                        media_creator = attach['schema:creator']
        elif attach.get('attribution'):
            if isinstance(attach['attribution'], list):
                if len(attach['attribution']) > 0:
                    attrib_str = attach['attribution'][0]
                    if not dangerous_markup(attrib_str, False, []):
                        if not is_filtered(base_dir, nickname, domain,
                                           attrib_str, system_language):
                            media_creator = attrib_str

        image_description = ''
        if attach.get('name'):
            image_description = attach['name'].replace('"', "'")
            image_description = remove_html(image_description)
        if _is_image_mime_type(media_type):
            image_url = remove_html(url)
            if image_url in attached_urls:
                continue
            attached_urls.append(image_url)

            # display svg images if they have first been rendered harmless
            svg_harmless = True
            if 'svg' in media_type:
                svg_harmless = False
                if '://' + domain_full + '/' in image_url:
                    svg_harmless = True
                else:
                    if post_id:
                        if '/' in image_url:
                            im_filename = image_url.split('/')[-1]
                        else:
                            im_filename = image_url
                        cached_svg_filename = \
                            base_dir + '/media/' + post_id + '_' + im_filename
                        if os.path.isfile(cached_svg_filename):
                            svg_harmless = True

            if _is_attached_image(image_url) and svg_harmless:
                if not attachment_str:
                    attachment_str += '<div class="media">\n'
                    media_style_added = True

                if attachment_ctr > 0:
                    attachment_str += '<br>'
                if box_name == 'tlmedia':
                    gallery_str += '<div class="gallery">\n'
                    if not is_muted:
                        gallery_str += '  <a href="' + image_url + '">\n'
                        if media_license and media_creator:
                            gallery_str += '  <figure>\n'
                        gallery_str += \
                            '    <img loading="lazy" ' + \
                            'decoding="async" src="' + \
                            image_url + '" alt="" title="">\n'
                        gallery_str += '  </a>\n'
                        license_str = ''
                        if media_license and media_creator:
                            media_license = remove_html(media_license)
                            if resembles_url(media_license):
                                license_str += \
                                    '<a href="' + media_license + \
                                    '" target="_blank" ' + \
                                    'rel="nofollow noopener noreferrer">©</a>'
                            else:
                                license_str += media_license
                            license_str += ' ' + media_creator
                            gallery_str += \
                                '   ' + license_str + \
                                '</figcaption></figure>\n'
                    if post_json_object['object'].get('url'):
                        url_str = post_json_object['object']['url']
                        image_post_url = get_url_from_post(url_str)
                    else:
                        image_post_url = post_json_object['object']['id']
                    image_post_url = remove_html(image_post_url)
                    if image_description and not is_muted:
                        gallery_str += \
                            '  <a href="' + image_post_url + \
                            '" class="gallerytext"><div ' + \
                            'class="gallerytext">' + \
                            image_description + '</div></a>\n'
                    else:
                        gallery_str += \
                            '<label class="transparent">---</label><br>'
                    gallery_str += '  <div class="mediaicons">\n'
                    # don't show the announce icon if there is no image
                    # description
                    if not image_description:
                        announce_str = ''
                    gallery_str += \
                        '    ' + reply_str + announce_str + like_str + \
                        bookmark_str + delete_str + mute_str + '\n'
                    gallery_str += '  </div>\n'
                    gallery_str += '  <div class="mediaavatar">\n'
                    gallery_str += '    ' + avatar_link + '\n'
                    gallery_str += '  </div>\n'
                    gallery_str += '</div>\n'

                # optionally hide the image
                attributed_actor = None
                minimize_images = False
                if minimize_all_images:
                    minimize_images = True
                if post_json_object['object'].get('attributedTo'):
                    attrib_field = post_json_object['object']['attributedTo']
                    attributed_actor = get_attributed_to(attrib_field)
                if attributed_actor:
                    following_nickname = \
                        get_nickname_from_actor(attributed_actor)
                    following_domain, _ = \
                        get_domain_from_actor(attributed_actor)
                    if minimize_all_images:
                        minimize_images = True
                    else:
                        minimize_images = \
                            minimizing_attached_images(base_dir,
                                                       nickname, domain,
                                                       following_nickname,
                                                       following_domain)

                # minimize any NSFW images
                if not minimize_images and content:
                    if _is_nsfw(content):
                        minimize_images = True

                if minimize_images:
                    show_img_str = 'SHOW MEDIA'
                    if translate:
                        show_img_str = translate['SHOW MEDIA']
                    attachment_str += \
                        '<details><summary class="cw" tabindex="10">' + \
                        show_img_str + '</summary>' + \
                        '<div id="' + post_id + '">\n'

                attachment_str += \
                    '<a href="' + image_url + '" tabindex="10">'
                if media_license and media_creator:
                    attachment_str += '<figure>'
                attachment_str += \
                    '<img loading="lazy" decoding="async" ' + \
                    'src="' + image_url + \
                    '" alt="' + image_description + '" title="' + \
                    image_description + '" class="attachment"></a>\n'
                if media_license and media_creator:
                    license_str = ''
                    attachment_str += '<figcaption>'
                    media_license = remove_html(media_license)
                    if resembles_url(media_license):
                        license_str += \
                            '<a href="' + media_license + \
                            '" target="_blank" ' + \
                            'rel="nofollow noopener noreferrer">©</a>'
                    else:
                        license_str += media_license
                    license_str += ' ' + media_creator
                    attachment_str += license_str + '</figcaption></figure>'

                if minimize_images:
                    attachment_str += '</div></details>\n'

                attachment_ctr += 1
        elif _is_video_mime_type(media_type):
            video_url = remove_html(url)
            if video_url in attached_urls:
                continue
            attached_urls.append(video_url)
            if _is_attached_video(video_url):
                extension = video_url.split('.')[-1]
                if attachment_ctr > 0:
                    attachment_str += '<br>'
                if box_name == 'tlmedia':
                    gallery_str += '<div class="gallery">\n'
                    if post_json_object['object'].get('url'):
                        url_str = post_json_object['object']['url']
                        video_post_url = get_url_from_post(url_str)
                    else:
                        video_post_url = post_json_object['object']['id']
                    video_post_url = remove_html(video_post_url)
                    if not is_muted:
                        gallery_str += \
                            '  <a href="' + video_url + \
                            '" tabindex="10">\n'
                        gallery_str += \
                            '    <figure id="videoContainer" ' + \
                            'data-fullscreen="false">\n' + \
                            '    <video id="video" controls ' + \
                            'preload="metadata" tabindex="10">\n'
                        gallery_str += \
                            '      <source src="' + video_url + \
                            '" alt="' + image_description + \
                            '" title="' + image_description + \
                            '" class="attachment" type="video/' + \
                            extension + '">\n'
                        if transcripts:
                            for transcript_name, transcript_url in \
                              transcripts.items():
                                gallery_str += \
                                    '<track src=”' + transcript_url + '" ' + \
                                    'label=”' + transcript_name + '" ' + \
                                    'srclang=”' + transcript_name + '" ' + \
                                    'kind=”captions” >\n'
                        idx = 'Your browser does not support the video tag.'
                        gallery_str += translate[idx] + '\n'
                        gallery_str += '    </video>\n'
                        gallery_str += '    </figure>\n'
                        gallery_str += '  </a>\n'
                    if image_description and not is_muted:
                        gallery_str += \
                            '  <a href="' + video_post_url + \
                            '" class="gallerytext" tabindex="10"><div ' + \
                            'class="gallerytext">' + \
                            image_description + '</div></a>\n'
                    else:
                        gallery_str += \
                            '<label class="transparent">---</label><br>'
                    gallery_str += '  <div class="mediaicons">\n'
                    gallery_str += \
                        '    ' + reply_str + announce_str + like_str + \
                        bookmark_str + delete_str + mute_str + '\n'
                    gallery_str += '  </div>\n'
                    gallery_str += '  <div class="mediaavatar">\n'
                    gallery_str += '    ' + avatar_link + '\n'
                    gallery_str += '  </div>\n'
                    gallery_str += '</div>\n'

                attachment_str += \
                    '<center><figure id="videoContainer" ' + \
                    'data-fullscreen="false">\n' + \
                    '    <video id="video" controls ' + \
                    'preload="metadata" tabindex="10">\n'
                attachment_str += \
                    '      <source src="' + video_url + '" alt="' + \
                    image_description + '" title="' + image_description + \
                    '" class="attachment" type="video/' + \
                    extension + '">\n'
                if transcripts:
                    for transcript_name, transcript_url in \
                      transcripts.items():
                        attachment_str += \
                            '      <track src=”' + transcript_url + '" ' + \
                            'label=”' + transcript_name + '" ' + \
                            'srclang=”' + transcript_name + '" ' + \
                            'kind=”captions” >\n'
                attachment_str += \
                    translate['Your browser does not support the video tag.']
                attachment_str += '\n    </video></figure></center>'
                attachment_ctr += 1
        elif _is_audio_mime_type(media_type):
            extension = '.mp3'
            audio_url = remove_html(url)
            if audio_url in attached_urls:
                continue
            attached_urls.append(audio_url)
            if audio_url.endswith('.ogg'):
                extension = '.ogg'
            elif audio_url.endswith('.wav'):
                extension = '.wav'
            elif audio_url.endswith('.opus'):
                extension = '.opus'
            elif audio_url.endswith('.spx'):
                extension = '.spx'
            elif audio_url.endswith('.flac'):
                extension = '.flac'
            if audio_url.endswith(extension):
                if attachment_ctr > 0:
                    attachment_str += '<br>'
                if box_name == 'tlmedia':
                    gallery_str += '<div class="gallery">\n'
                    if not is_muted:
                        gallery_str += \
                            '  <a href="' + audio_url + \
                            '" tabindex="10">\n'
                        gallery_str += '    <audio controls tabindex="10">\n'
                        gallery_str += \
                            '      <source src="' + audio_url + \
                            '" alt="' + image_description + \
                            '" title="' + image_description + \
                            '" class="attachment" type="audio/' + \
                            extension.replace('.', '') + '">'
                        idx = 'Your browser does not support the audio tag.'
                        gallery_str += translate[idx]
                        gallery_str += '    </audio>\n'
                        gallery_str += '  </a>\n'
                    if post_json_object['object'].get('url'):
                        url_str = post_json_object['object']['url']
                        audio_post_url = get_url_from_post(url_str)
                    else:
                        audio_post_url = post_json_object['object']['id']
                    audio_post_url = remove_html(audio_post_url)
                    if image_description and not is_muted:
                        gallery_str += \
                            '  <a href="' + audio_post_url + \
                            '" class="gallerytext"><div ' + \
                            'class="gallerytext">' + \
                            image_description + '</div></a>\n'
                    else:
                        gallery_str += \
                            '<label class="transparent">---</label><br>'
                    gallery_str += '  <div class="mediaicons">\n'
                    gallery_str += \
                        '    ' + reply_str + announce_str + \
                        like_str + bookmark_str + \
                        delete_str + mute_str + '\n'
                    gallery_str += '  </div>\n'
                    gallery_str += '  <div class="mediaavatar">\n'
                    gallery_str += '    ' + avatar_link + '\n'
                    gallery_str += '  </div>\n'
                    gallery_str += '</div>\n'

                attachment_str += '<center>\n<audio controls tabindex="10">\n'
                attachment_str += \
                    '<source src="' + audio_url + '" alt="' + \
                    image_description + '" title="' + image_description + \
                    '" class="attachment" type="audio/' + \
                    extension.replace('.', '') + '">'
                attachment_str += \
                    translate['Your browser does not support the audio tag.']
                attachment_str += '</audio>\n</center>\n'
                attachment_ctr += 1
    if media_style_added:
        attachment_str += '</div><br>'
    return attachment_str, gallery_str


def html_post_separator(base_dir: str, column: str) -> str:
    """Returns the html for a timeline post separator image
    """
    theme = get_config_param(base_dir, 'theme')
    if not theme:
        theme = 'default'
    filename = 'separator.png'
    separator_class = "postSeparatorImage"
    if column:
        separator_class = "postSeparatorImage" + column.title()
        filename = 'separator_' + column + '.png'
    separator_image_filename = \
        base_dir + '/theme/' + theme + '/icons/' + filename
    separator_str = ''
    if os.path.isfile(separator_image_filename):
        separator_str = \
            '<div class="' + separator_class + '"><center>' + \
            '<img src="/icons/' + filename + '" ' + \
            'alt="" /></center></div>\n'
    return separator_str


def html_highlight_label(label: str, highlight: bool) -> str:
    """If the given text should be highlighted then return
    the appropriate markup.
    This is so that in shell browsers, like lynx, it's possible
    to see if the replies or DM button are highlighted.
    """
    if not highlight:
        return label
    return '*' + str(label) + '*'


def get_avatar_image_url(session, base_dir: str, http_prefix: str,
                         post_actor: str, person_cache: {},
                         avatar_url: str, allow_downloads: bool,
                         signing_priv_key_pem: str,
                         mitm_servers: []) -> str:
    """Returns the avatar image url if it exists in the cache
    """
    # get the avatar image url for the post actor
    if not avatar_url:
        avatar_url = \
            get_person_avatar_url(base_dir, post_actor, person_cache)
        avatar_url = \
            update_avatar_image_cache(signing_priv_key_pem,
                                      session, base_dir, http_prefix,
                                      post_actor, avatar_url, person_cache,
                                      allow_downloads, mitm_servers)
    else:
        update_avatar_image_cache(signing_priv_key_pem,
                                  session, base_dir, http_prefix,
                                  post_actor, avatar_url, person_cache,
                                  allow_downloads, mitm_servers)

    if not avatar_url:
        avatar_url = post_actor + '/avatar.png'

    return avatar_url


def html_hide_from_screen_reader(html_str: str) -> str:
    """Returns html which is hidden from screen readers
    """
    return '<span aria-hidden="true">' + html_str + '</span>'


def html_keyboard_navigation(banner: str, links: {}, access_keys: {},
                             sub_heading: str,
                             users_path: str, translate: {},
                             follow_approvals: bool) -> str:
    """Given a set of links return the html for keyboard navigation
    """
    html_str = '<div class="transparent"><ul>\n'

    if banner:
        html_str += '<pre aria-label="">\n' + banner + '\n<br><br></pre>\n'

    if sub_heading:
        html_str += '<strong><label class="transparent">' + \
            sub_heading + '</label></strong><br>\n'

    # show new follower approvals
    if users_path and translate and follow_approvals:
        html_str += '<strong><label class="transparent">' + \
            '<a href="' + users_path + '/followers#timeline" ' + \
            'tabindex="-1">' + \
            translate['Approve follow requests'] + '</a>' + \
            '</label></strong><br><br>\n'

    # show the list of links
    for title, url in links.items():
        access_key_str = ''
        if access_keys.get(title):
            access_key_str = 'accesskey="' + access_keys[title] + '"'

        html_str += '<li><label class="transparent">' + \
            '<a href="' + str(url) + '" ' + access_key_str + \
            ' tabindex="-1">' + \
            str(title) + '</a></label></li>\n'
    html_str += '</ul></div>\n'
    return html_str


def begin_edit_section(label: str) -> str:
    """returns the html for begining a dropdown section on edit profile screen
    """
    return \
        '    <details><summary class="cw">' + label + '</summary>\n' + \
        '<div class="container">'


def end_edit_section() -> str:
    """returns the html for ending a dropdown section on edit profile screen
    """
    return '    </div></details>\n'


def edit_text_field(label: str, name: str, value: str = "",
                    placeholder: str = "", required: bool = False) -> str:
    """Returns html for editing a text field
    """
    if value is None:
        value = ''
    placeholder_str = ''
    if placeholder:
        placeholder_str = ' placeholder="' + placeholder + '"'
    required_str = ''
    if required:
        required_str = ' required'
    text_field_str = ''
    if label:
        text_field_str = \
            '<label class="labels">' + label + '</label><br>\n'
    text_field_str += \
        '      <input type="text" name="' + name + '" value="' + \
        value + '"' + placeholder_str + required_str + '>\n'
    return text_field_str


def edit_number_field(label: str, name: str, value: int,
                      min_value: int, max_value: int,
                      placeholder: int) -> str:
    """Returns html for editing an integer number field
    """
    if value is None:
        value = ''
    placeholder_str = ''
    if placeholder:
        placeholder_str = ' placeholder="' + str(placeholder) + '"'
    return \
        '<label class="labels">' + label + '</label><br>\n' + \
        '      <input type="number" name="' + name + '" value="' + \
        str(value) + '"' + placeholder_str + ' ' + \
        'min="' + str(min_value) + '" max="' + str(max_value) + '" step="1">\n'


def edit_currency_field(label: str, name: str, value: str,
                        placeholder: str, required: bool) -> str:
    """Returns html for editing a currency field
    """
    if value is None:
        value = '0.00'
    placeholder_str = ''
    if placeholder:
        if placeholder.isdigit():
            placeholder_str = ' placeholder="' + str(placeholder) + '"'
    required_str = ''
    if required:
        required_str = ' required'
    return \
        '<label class="labels">' + label + '</label><br>\n' + \
        '      <input type="text" name="' + name + '" value="' + \
        str(value) + '"' + placeholder_str + ' ' + \
        ' pattern="^\\d{1,3}(,\\d{3})*(\\.\\d+)?" data-type="currency"' + \
        required_str + '>\n'


def edit_check_box(label: str, name: str, checked: bool) -> str:
    """Returns html for editing a checkbox field
    """
    checked_str = ''
    if checked:
        checked_str = ' checked'

    return \
        '      <input type="checkbox" class="profilecheckbox" ' + \
        'name="' + name + '"' + checked_str + '> ' + label + '<br>\n'


def edit_text_area(label: str, subtitle: str, name: str, value: str,
                   height: int, placeholder: str, spellcheck: bool) -> str:
    """Returns html for editing a textarea field
    """
    if value is None:
        value = ''
    text = ''
    if label:
        text = '<label class="labels">' + label + '</label><br>\n'
        if subtitle:
            text += subtitle + '<br>\n'
    text += \
        '      <textarea id="message" placeholder=' + \
        '"' + placeholder + '" '
    text += 'name="' + name + '" '
    text += 'style="height:' + str(height) + 'px" '
    text += 'spellcheck="' + str(spellcheck).lower() + '">'
    text += value + '</textarea>\n'
    return text


def html_search_result_share(base_dir: str, shared_item: {}, translate: {},
                             http_prefix: str, domain_full: str,
                             contact_nickname: str, item_id: str,
                             actor: str, shares_file_type: str,
                             category: str,
                             publicly_visible: bool) -> str:
    """Returns the html for an individual shared item
    """
    shared_items_form = '<div class="container">\n'
    shared_items_form += \
        '<p class="share-title">' + shared_item['displayName'] + '</p>\n'
    if shared_item.get('imageUrl'):
        shared_items_form += \
            '<a href="' + shared_item['imageUrl'] + '">\n'
        shared_items_form += \
            '<img loading="lazy" decoding="async" ' + \
            'src="' + shared_item['imageUrl'] + \
            '" alt="Item image"></a>\n'
    shared_items_form += '<p>' + shared_item['summary'] + '</p>\n<p>'
    if shared_item.get('itemQty'):
        if shared_item['itemQty'] > 1:
            shared_items_form += \
                '<b>' + translate['Quantity'] + \
                ':</b> ' + str(shared_item['itemQty']) + '<br>'
    shared_items_form += \
        '<b>' + translate['Type'] + ':</b> ' + shared_item['itemType'] + '<br>'
    shared_items_form += \
        '<b>' + translate['Category'] + ':</b> ' + \
        shared_item['category'] + '<br>'
    if shared_item.get('location'):
        shared_items_form += \
            '<b>' + translate['Location'] + ':</b> ' + \
            shared_item['location'] + '<br>'
    contact_title_str = translate['Contact']
    if shared_item.get('itemPrice') and \
       shared_item.get('itemCurrency'):
        if is_float(shared_item['itemPrice']):
            if float(shared_item['itemPrice']) > 0:
                shared_items_form += \
                    ' <b>' + translate['Price'] + \
                    ':</b> ' + shared_item['itemPrice'] + \
                    ' ' + shared_item['itemCurrency']
                contact_title_str = translate['Buy']
    shared_items_form += '</p>\n'
    contact_actor = \
        local_actor_url(http_prefix, contact_nickname, domain_full)
    button_style_str = 'button'
    if category == 'accommodation':
        contact_title_str = translate['Request to stay']
        button_style_str = 'contactbutton'

    if not publicly_visible:
        shared_items_form += \
            '<p>' + \
            '<a href="' + actor + '?replydm=sharedesc:' + \
            shared_item['displayName'] + '?mention=' + contact_actor + \
            '?category=' + category + '">' + \
            '<button class="' + button_style_str + '">' + contact_title_str + \
            '</button></a>\n' + \
            '<a href="' + contact_actor + '"><button class="button">' + \
            translate['Profile'] + '</button></a>\n'
    else:
        shared_items_form += \
            '<a href="' + contact_actor + '"><button class="button">' + \
            translate['Contact'] + '</button></a>\n'

    # should the remove button be shown?
    show_remove_button = False
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        return ''
    if actor.endswith('/users/' + contact_nickname):
        show_remove_button = True
    elif is_moderator(base_dir, nickname):
        show_remove_button = True
    else:
        admin_nickname = get_config_param(base_dir, 'admin')
        if admin_nickname:
            if actor.endswith('/users/' + admin_nickname):
                show_remove_button = True

    if show_remove_button and not publicly_visible:
        if shares_file_type == 'shares':
            shared_items_form += \
                ' <a href="' + actor + '?rmshare=' + \
                item_id + '"><button class="button">' + \
                translate['Remove'] + '</button></a>\n'
        else:
            shared_items_form += \
                ' <a href="' + actor + '?rmwanted=' + \
                item_id + '"><button class="button">' + \
                translate['Remove'] + '</button></a>\n'
    shared_items_form += '</p></div>\n'
    return shared_items_form


def html_show_share(base_dir: str, domain: str, nickname: str,
                    http_prefix: str, domain_full: str,
                    item_id: str, translate: {},
                    shared_items_federated_domains: [],
                    default_timeline: str, theme: str,
                    shares_file_type: str, category: str,
                    publicly_visible: bool) -> str:
    """Shows an individual shared item after selecting it from the left column
    """
    shares_json = None

    replacements = {
        '___': '://',
        '--': '/'
    }
    share_url = replace_strings(item_id, replacements)
    contact_nickname = get_nickname_from_actor(share_url)
    if not contact_nickname:
        return None

    if '://' + domain_full + '/' in share_url:
        # shared item on this instance
        shares_filename = \
            acct_dir(base_dir, contact_nickname, domain) + '/' + \
            shares_file_type + '.json'
        if not os.path.isfile(shares_filename):
            return None
        shares_json = load_json(shares_filename)
    else:
        # federated shared item
        if shares_file_type == 'shares':
            catalogs_dir = base_dir + '/cache/catalogs'
        else:
            catalogs_dir = base_dir + '/cache/wantedItems'
        if not os.path.isdir(catalogs_dir):
            return None
        for _, _, files in os.walk(catalogs_dir):
            for fname in files:
                if '#' in fname:
                    continue
                if not fname.endswith('.' + shares_file_type + '.json'):
                    continue
                federated_domain = fname.split('.')[0]
                if federated_domain not in shared_items_federated_domains:
                    continue
                shares_filename = catalogs_dir + '/' + fname
                shares_json = load_json(shares_filename)
                if not shares_json:
                    continue
                if shares_json.get(item_id):
                    break
            break

    if not shares_json:
        return None
    if not shares_json.get(item_id):
        return None
    shared_item = shares_json[item_id]
    actor = local_actor_url(http_prefix, nickname, domain_full)

    # filename of the banner shown at the top
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)

    share_str = \
        '<header>\n' + \
        '<a href="/users/' + nickname + '/' + \
        default_timeline + '" title="" alt="">\n'
    share_str += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" alt="" ' + \
        'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
        '</header><br>\n'
    share_str += \
        html_search_result_share(base_dir, shared_item, translate, http_prefix,
                                 domain_full, contact_nickname, item_id,
                                 actor, shares_file_type, category,
                                 publicly_visible)

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'
    instance_title = \
        get_config_param(base_dir, 'instanceTitle')

    preload_images: list[str] = []
    return html_header_with_external_style(css_filename,
                                           instance_title, None,
                                           preload_images) + \
        share_str + html_footer()


def set_custom_background(base_dir: str, background: str,
                          new_background: str) -> str:
    """Sets a custom background
    Returns the extension, if found
    """
    ext = 'jpg'
    if os.path.isfile(base_dir + '/img/' + background + '.' + ext):
        if not new_background:
            new_background = background
        dir_str = data_dir(base_dir)
        if not os.path.isfile(dir_str + '/' +
                              new_background + '.' + ext):
            copyfile(base_dir + '/img/' + background + '.' + ext,
                     dir_str + '/' + new_background + '.' + ext)
        return ext
    return None


def html_common_emoji(base_dir: str, no_of_emoji: int) -> str:
    """Shows common emoji
    """
    emojis_filename = base_dir + '/emoji/emoji.json'
    if not os.path.isfile(emojis_filename):
        emojis_filename = base_dir + '/emoji/default_emoji.json'
    emojis_json = load_json(emojis_filename)

    common_emoji_filename = data_dir(base_dir) + '/common_emoji.txt'
    if not os.path.isfile(common_emoji_filename):
        return ''
    common_emoji = None
    try:
        with open(common_emoji_filename, 'r', encoding='utf-8') as fp_emoji:
            common_emoji = fp_emoji.readlines()
    except OSError:
        print('EX: html_common_emoji unable to load file')
        return ''
    if not common_emoji:
        return ''
    line_ctr = 0
    ctr = 0
    html_str = ''
    while ctr < no_of_emoji and line_ctr < len(common_emoji):
        name_initial = common_emoji[line_ctr].split(' ')
        if len(name_initial) < 2:
            line_ctr += 1
            continue
        emoji_name1 = name_initial[1]
        emoji_name = remove_eol(emoji_name1)
        emoji_icon_name = emoji_name
        emoji_filename = base_dir + '/emoji/' + emoji_name + '.png'
        if not os.path.isfile(emoji_filename):
            emoji_filename = base_dir + '/customemoji/' + emoji_name + '.png'
            if not os.path.isfile(emoji_filename):
                # load the emojis index
                if not emojis_json:
                    emojis_json = load_json(emojis_filename)
                # lookup the name within the index to get the hex code
                if emojis_json:
                    for emoji_tag, emoji_code in emojis_json.items():
                        if emoji_tag == emoji_name:
                            # get the filename based on the hex code
                            emoji_filename = \
                                base_dir + '/emoji/' + emoji_code + '.png'
                            emoji_icon_name = emoji_code
                            break
        if os.path.isfile(emoji_filename):
            # NOTE: deliberately no alt text, so that without graphics only
            # the emoji name shows
            html_str += \
                '<label class="hashtagswarm">' + \
                '<img id="commonemojilabel" ' + \
                'loading="lazy" decoding="async" ' + \
                'src="/emoji/' + emoji_icon_name + '.png" ' + \
                'alt="" title="">' + \
                ':' + emoji_name + ':</label>\n'
            ctr += 1
        line_ctr += 1
    return html_str


def text_mode_browser(ua_str: str) -> bool:
    """Does the user agent indicate a text mode browser?
    """
    if ua_str:
        text_mode_agents = ('Lynx/', 'w3m/', 'Links (', 'Emacs/', 'ELinks')
        for agent in text_mode_agents:
            if agent in ua_str:
                return True
    return False


def get_default_path(media_instance: bool, blogs_instance: bool,
                     nickname: str) -> str:
    """Returns the default timeline
    """
    if blogs_instance:
        path = '/users/' + nickname + '/tlblogs'
    elif media_instance:
        path = '/users/' + nickname + '/tlmedia'
    else:
        path = '/users/' + nickname + '/inbox'
    return path


def html_following_data_list(base_dir: str, nickname: str,
                             domain: str, domain_full: str,
                             following_type: str,
                             use_petnames: bool) -> str:
    """Returns a datalist of handles being followed
    followingHandles, followersHandles
    """
    list_str = '<datalist id="' + following_type + 'Handles">\n'
    following_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + following_type + '.txt'
    msg = ''
    if os.path.isfile(following_filename):
        try:
            with open(following_filename, 'r',
                      encoding='utf-8') as fp_following:
                msg = fp_following.read()
                # add your own handle, so that you can send DMs
                # to yourself as reminders
                msg += nickname + '@' + domain_full + '\n'
        except OSError:
            print('EX: html_following_data_list unable to read ' +
                  following_filename)
    if msg:
        # include petnames
        petnames_filename = \
            acct_dir(base_dir, nickname, domain) + '/petnames.txt'
        if use_petnames and os.path.isfile(petnames_filename):
            following_list: list[str] = []
            try:
                with open(petnames_filename, 'r',
                          encoding='utf-8') as fp_petnames:
                    pet_str = fp_petnames.read()
                    # extract each petname and append it
                    petnames_list = pet_str.split('\n')
                    for pet in petnames_list:
                        following_list.append(pet.split(' ')[0])
            except OSError:
                print('EX: html_following_data_list unable to read ' +
                      petnames_filename)
            # add the following.txt entries
            following_list += msg.split('\n')
        else:
            # no petnames list exists - just use following.txt
            following_list = msg.split('\n')
        following_list.sort()
        if following_list:
            for following_address in following_list:
                if not following_address:
                    continue
                if '@' not in following_address and \
                   '://' not in following_address:
                    continue
                list_str += '<option>@' + following_address + '</option>\n'
    list_str += '</datalist>\n'
    return list_str


def html_following_dropdown(base_dir: str, nickname: str,
                            domain: str, domain_full: str,
                            following_type: str,
                            use_petnames: bool) -> str:
    """Returns a select list of handles being followed or of followers
    """
    list_str = '<select name="searchtext">\n'
    following_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + following_type + '.txt'
    msg = ''
    if os.path.isfile(following_filename):
        try:
            with open(following_filename, 'r',
                      encoding='utf-8') as fp_following:
                msg = fp_following.read()
                # add your own handle, so that you can send DMs
                # to yourself as reminders
                msg += nickname + '@' + domain_full + '\n'
        except OSError:
            print('EX: html_following_dropdown unable to read ' +
                  following_filename)
    if msg:
        # include petnames
        petnames_filename = \
            acct_dir(base_dir, nickname, domain) + '/petnames.txt'
        if use_petnames and os.path.isfile(petnames_filename):
            following_list: list[str] = []
            try:
                with open(petnames_filename, 'r',
                          encoding='utf-8') as fp_petnames:
                    pet_str = fp_petnames.read()
                    # extract each petname and append it
                    petnames_list = pet_str.split('\n')
                    for pet in petnames_list:
                        following_list.append(pet.split(' ')[0])
            except OSError:
                print('EX: html_following_dropdown unable to read ' +
                      petnames_filename)
            # add the following.txt entries
            following_list += msg.split('\n')
        else:
            # no petnames list exists - just use following.txt
            following_list = msg.split('\n')
        list_str += '<option value="" selected></option>\n'
        if following_list:
            domain_sorted_list: list[str] = []
            for following_address in following_list:
                if '@' not in following_address and \
                   '://' not in following_address:
                    continue
                foll_nick = get_nickname_from_actor(following_address)
                foll_domain, _ = get_domain_from_actor(following_address)
                if not foll_domain or not foll_nick:
                    continue
                domain_sorted_list.append(foll_domain + ' ' +
                                          foll_nick + '@' + foll_domain)
            domain_sorted_list.sort()

            prev_foll_domain = ''
            for following_line in domain_sorted_list:
                following_address = following_line.split(' ')[1]
                foll_domain, _ = get_domain_from_actor(following_address)
                if prev_foll_domain and prev_foll_domain != foll_domain:
                    list_str += '<option value="" disabled></option>\n'
                prev_foll_domain = foll_domain
                list_str += '<option value="' + following_address + '">' + \
                    following_address + '</option>\n'
    list_str += '</select>\n'
    return list_str


def get_buy_links(post_json_object: str, translate: {}, buy_sites: {}) -> {}:
    """Returns any links to buy something from an external site
    """
    post_attachments = get_post_attachments(post_json_object)
    if not post_attachments:
        return {}
    links = {}
    buy_strings: list[str] = []
    for buy_str in ('Buy', 'Purchase', 'Subscribe'):
        if translate.get(buy_str):
            buy_str = translate[buy_str]
        buy_strings += buy_str.lower()
    buy_strings += ('Paypal', 'Stripe', 'Cashapp', 'Venmo')
    for item in post_attachments:
        if not isinstance(item, dict):
            continue
        if not item.get('name'):
            continue
        if not isinstance(item['name'], str):
            continue
        if not item.get('type'):
            continue
        if not item.get('href'):
            continue
        if not isinstance(item['type'], str):
            continue
        if not isinstance(item['href'], str):
            continue
        if item['type'] != 'Link':
            continue
        if not item.get('mediaType'):
            continue
        if not isinstance(item['mediaType'], str):
            continue
        if 'html' not in item['mediaType']:
            continue
        item_name = item['name']
        # The name should not be excessively long
        if len(item_name) > 32:
            continue
        # there should be no html in the name
        if remove_html(item_name) != item_name:
            continue
        # there should be no html in the link
        if string_contains(item['href'], ('<', '://', ' ')):
            continue
        if item.get('rel'):
            if isinstance(item['rel'], str):
                if item['rel'] in ('payment', 'pay', 'donate', 'donation',
                                   'buy', 'purchase', 'support'):
                    links[item_name] = remove_html(item['href'])
                    continue
        if buy_sites:
            # limited to an allowlist of buying sites
            for site, buy_domain in buy_sites.items():
                if buy_domain in item['href']:
                    links[site.title()] = remove_html(item['href'])
                    continue
        else:
            # The name only needs to indicate that this is a buy link
            for buy_str in buy_strings:
                if buy_str in item_name.lower():
                    links[item_name] = remove_html(item['href'])
                    continue
    return links


def load_buy_sites(base_dir: str) -> {}:
    """Loads domains from which buying is permitted
    """
    buy_sites_filename = data_dir(base_dir) + '/buy_sites.json'
    if os.path.isfile(buy_sites_filename):
        buy_sites_json = load_json(buy_sites_filename)
        if buy_sites_json:
            return buy_sites_json
    return {}


def html_known_epicyon_instances(base_dir: str, http_prefix: str,
                                 domain_full: str,
                                 system_language: str,
                                 known_epicyon_instances: [],
                                 translate: {}) -> str:
    """Show a list of known epicyon instances
    """
    html_str = ''
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    html_str = \
        html_header_with_website_markup(css_filename, instance_title,
                                        http_prefix, domain_full,
                                        system_language)
    if known_epicyon_instances:
        instances_text = ''
        newswire_str = translate['Newswire RSS Feed']
        for instance in known_epicyon_instances:
            http_prefix = 'https'
            if instance.endswith('.onion') or \
               instance.endswith('.i2p') or \
               local_network_host(instance):
                http_prefix = 'http'
            instances_text += \
                '<a href="' + http_prefix + '://' + instance + \
                '" target="_blank" rel="nofollow noopener noreferrer">' + \
                instance + '</a>' + \
                ' <a href="' + http_prefix + '://' + instance + \
                '/newswire.xml" ' + \
                'target="_blank" rel="nofollow noopener noreferrer">' + \
                '<img class="newswire_rss_image" ' + \
                'loading="lazy" decoding="async" alt="' + newswire_str + \
                '" title="' + newswire_str + \
                '" src="/icons/logorss.png"></a><br>\n'
        html_str += \
            '<div class="container">' + instances_text + '</div>\n'
    html_str += html_footer()
    return html_str


def mitm_warning_html(translate: {}) -> str:
    """Returns html for a MITM warning icon
    """
    mitm_warning_str = translate['mitm']
    return '        <img loading="lazy" decoding="async" title="' + \
        mitm_warning_str + '" alt="' + \
        mitm_warning_str + '" src="/icons' + \
        '/mitm.png" class="mitm"/>\n'
