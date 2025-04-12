__filename__ = "webapp_confirm.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from shutil import copyfile
from utils import data_dir
from utils import get_full_domain
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import locate_post
from utils import load_json
from utils import get_config_param
from utils import get_alt_path
from utils import acct_dir
from utils import get_account_timezone
from blocking import sending_is_blocked2
from webapp_utils import set_custom_background
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_post import individual_post_as_html


def html_confirm_delete(server,
                        recent_posts_cache: {}, max_recent_posts: int,
                        translate, page_number: int,
                        session, base_dir: str, message_id: str,
                        http_prefix: str, project_version: str,
                        cached_webfingers: {}, person_cache: {},
                        calling_domain: str,
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        theme_name: str, system_language: str,
                        max_like_count: int, signing_priv_key_pem: str,
                        cw_lists: {}, lists_enabled: str,
                        dogwhistles: {},
                        min_images_for_accounts: [],
                        buy_sites: {},
                        auto_cw_cache: {},
                        mitm_servers: [],
                        instance_software: {}) -> str:
    """Shows a screen asking to confirm the deletion of a post
    """
    if '/statuses/' not in message_id:
        return None
    actor = message_id.split('/statuses/')[0]
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        return None
    domain, port = get_domain_from_actor(actor)
    if not domain:
        return None
    domain_full = get_full_domain(domain, port)

    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if not post_filename:
        return None

    post_json_object = load_json(post_filename)
    if not post_json_object:
        return None

    delete_post_str = None
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    delete_post_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    timezone = get_account_timezone(base_dir, nickname, domain)
    mitm = False
    if os.path.isfile(post_filename.replace('.json', '') + '.mitm'):
        mitm = True
    bold_reading = False
    if server.bold_reading.get(nickname):
        bold_reading = True
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    delete_post_str += \
        individual_post_as_html(signing_priv_key_pem,
                                True, recent_posts_cache, max_recent_posts,
                                translate, page_number,
                                base_dir, session,
                                cached_webfingers, person_cache,
                                nickname, domain, port, post_json_object,
                                None, True, False,
                                http_prefix, project_version, 'outbox',
                                yt_replace_domain,
                                twitter_replacement_domain,
                                show_published_date_only,
                                peertube_instances, allow_local_network_access,
                                theme_name, system_language, max_like_count,
                                False, False, False, False, False, False,
                                cw_lists, lists_enabled, timezone, mitm,
                                bold_reading, dogwhistles,
                                minimize_all_images, None, buy_sites,
                                auto_cw_cache, mitm_servers,
                                instance_software)
    delete_post_str += '<center>'
    delete_post_str += \
        '  <p class="followText">' + \
        translate['Delete this post?'] + '</p>'

    post_actor = get_alt_path(actor, domain_full, calling_domain)
    delete_post_str += \
        '  <form method="POST" action="' + post_actor + '/rmpost">\n'
    delete_post_str += \
        '    <input type="hidden" name="pageNumber" value="' + \
        str(page_number) + '">\n'
    delete_post_str += \
        '    <input type="hidden" name="messageId" value="' + \
        message_id + '">\n'
    delete_post_str += \
        '    <button type="submit" class="button" name="submitYes">' + \
        translate['Yes'] + '</button>\n'
    delete_post_str += \
        '    <a href="' + actor + '/inbox"><button class="button">' + \
        translate['No'] + '</button></a>\n'
    delete_post_str += '  </form>\n'
    delete_post_str += '</center>\n'
    delete_post_str += html_footer()
    return delete_post_str


def html_confirm_remove_shared_item(translate: {},
                                    base_dir: str,
                                    actor: str, item_id: str,
                                    calling_domain: str,
                                    shares_file_type: str) -> str:
    """Shows a screen asking to confirm the removal of a shared item
    """
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        return None
    domain, port = get_domain_from_actor(actor)
    if not domain:
        return None
    domain_full = get_full_domain(domain, port)
    shares_file = \
        acct_dir(base_dir, nickname, domain) + '/' + shares_file_type + '.json'
    if not os.path.isfile(shares_file):
        print('ERROR: no ' + shares_file_type + ' file ' + shares_file)
        return None
    shares_json = load_json(shares_file)
    if not shares_json:
        print('ERROR: unable to load ' + shares_file_type + '.json')
        return None
    if not shares_json.get(item_id):
        print('ERROR: share named "' + item_id + '" is not in ' + shares_file)
        return None
    shared_item_display_name = shares_json[item_id]['displayName']
    shared_item_image_url = None
    if shares_json[item_id].get('imageUrl'):
        shared_item_image_url = shares_json[item_id]['imageUrl']

    set_custom_background(base_dir, 'shares-background', 'follow-background')

    css_filename = base_dir + '/epicyon-follow.css'
    if os.path.isfile(base_dir + '/follow.css'):
        css_filename = base_dir + '/follow.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    shares_str = html_header_with_external_style(css_filename,
                                                 instance_title, None,
                                                 preload_images)
    shares_str += '<div class="follow">\n'
    shares_str += '  <div class="followAvatar">\n'
    shares_str += '  <center>\n'
    if shared_item_image_url:
        shares_str += '  <img loading="lazy" decoding="async" src="' + \
            shared_item_image_url + '"/>\n'
    shares_str += \
        '  <p class="followText">' + translate['Remove'] + \
        ' ' + shared_item_display_name + ' ?</p>\n'
    post_actor = get_alt_path(actor, domain_full, calling_domain)
    if shares_file_type == 'shares':
        endpoint = 'rmshare'
    else:
        endpoint = 'rmwanted'
    shares_str += \
        '  <form method="POST" action="' + post_actor + '/' + endpoint + '">\n'
    shares_str += \
        '    <input type="hidden" name="actor" value="' + actor + '">\n'
    shares_str += '    <input type="hidden" name="itemID" value="' + \
        item_id + '">\n'
    shares_str += \
        '    <button type="submit" class="button" name="submitYes">' + \
        translate['Yes'] + '</button>\n'
    shares_str += \
        '    <a href="' + actor + '/inbox' + '"><button class="button">' + \
        translate['No'] + '</button></a>\n'
    shares_str += '  </form>\n'
    shares_str += '  </center>\n'
    shares_str += '  </div>\n'
    shares_str += '</div>\n'
    shares_str += html_footer()
    return shares_str


def html_confirm_follow(translate: {}, base_dir: str,
                        origin_path_str: str,
                        follow_actor: str,
                        follow_profile_url: str,
                        nickname: str, domain: str) -> str:
    """Asks to confirm a follow
    """
    follow_domain, _ = get_domain_from_actor(follow_actor)

    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/follow-background-custom.jpg'):
        if not os.path.isfile(dir_str + '/follow-background.jpg'):
            copyfile(dir_str + '/follow-background-custom.jpg',
                     dir_str + '/follow-background.jpg')

    css_filename = base_dir + '/epicyon-follow.css'
    if os.path.isfile(base_dir + '/follow.css'):
        css_filename = base_dir + '/follow.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    follow_str = html_header_with_external_style(css_filename,
                                                 instance_title, None,
                                                 preload_images)
    follow_str += '<div class="follow">\n'
    follow_str += '  <div class="followAvatar">\n'
    follow_str += '  <center>\n'
    follow_str += '  <a href="' + follow_actor + '">\n'
    follow_str += \
        '  <img loading="lazy" decoding="async" ' + \
        'src="' + follow_profile_url + '"/></a>\n'
    follow_actor_nick = get_nickname_from_actor(follow_actor)
    if follow_actor_nick and follow_domain:
        if sending_is_blocked2(base_dir, nickname, domain,
                               follow_domain, follow_actor):
            follow_str += \
                '  <p class="followText"><b>' + \
                translate['FollowWarning'] + '</b></p>\n'
        follow_str += \
            '  <p class="followText">' + translate['Follow'] + ' ' + \
            follow_actor_nick + '@' + follow_domain + ' ?</p>\n'
    follow_str += '  <form method="POST" action="' + \
        origin_path_str + '/followconfirm">\n'
    follow_str += '    <input type="hidden" name="actor" value="' + \
        follow_actor + '">\n'
    follow_str += \
        '    <button type="submit" class="button" name="submitYes">' + \
        translate['Yes'] + '</button>\n'
    follow_str += \
        '    <a href="' + origin_path_str + '"><button class="button">' + \
        translate['No'] + '</button></a>\n'
    follow_str += '  </form>\n'
    follow_str += '</center>\n'
    follow_str += '</div>\n'
    follow_str += '</div>\n'
    follow_str += html_footer()
    return follow_str


def html_confirm_unfollow(translate: {}, base_dir: str,
                          origin_path_str: str,
                          follow_actor: str,
                          follow_profile_url: str) -> str:
    """Asks to confirm unfollowing an actor
    """
    follow_domain, _ = get_domain_from_actor(follow_actor)

    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/follow-background-custom.jpg'):
        if not os.path.isfile(dir_str + '/follow-background.jpg'):
            copyfile(dir_str + '/follow-background-custom.jpg',
                     dir_str + '/follow-background.jpg')

    css_filename = base_dir + '/epicyon-follow.css'
    if os.path.isfile(base_dir + '/follow.css'):
        css_filename = base_dir + '/follow.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    follow_str = html_header_with_external_style(css_filename,
                                                 instance_title, None,
                                                 preload_images)
    follow_str += '<div class="follow">\n'
    follow_str += '  <div class="followAvatar">\n'
    follow_str += '  <center>\n'
    follow_str += '  <a href="' + follow_actor + '">\n'
    follow_str += \
        '  <img loading="lazy" decoding="async" src="' + \
        follow_profile_url + '"/></a>\n'
    follow_actor_nick = get_nickname_from_actor(follow_actor)
    if follow_actor_nick and follow_domain:
        follow_str += \
            '  <p class="followText">' + translate['Stop following'] + \
            ' ' + follow_actor_nick + '@' + follow_domain + ' ?</p>\n'
    follow_str += '  <form method="POST" action="' + \
        origin_path_str + '/unfollowconfirm">\n'
    follow_str += '    <input type="hidden" name="actor" value="' + \
        follow_actor + '">\n'
    follow_str += \
        '    <button type="submit" class="button" name="submitYes">' + \
        translate['Yes'] + '</button>\n'
    follow_str += \
        '    <a href="' + origin_path_str + '"><button class="button">' + \
        translate['No'] + '</button></a>\n'
    follow_str += '  </form>\n'
    follow_str += '</center>\n'
    follow_str += '</div>\n'
    follow_str += '</div>\n'
    follow_str += html_footer()
    return follow_str


def html_confirm_unblock(translate: {}, base_dir: str,
                         origin_path_str: str,
                         block_actor: str,
                         block_profile_url: str) -> str:
    """Asks to confirm unblocking an actor
    """
    block_domain, _ = get_domain_from_actor(block_actor)

    set_custom_background(base_dir, 'block-background', 'follow-background')

    css_filename = base_dir + '/epicyon-follow.css'
    if os.path.isfile(base_dir + '/follow.css'):
        css_filename = base_dir + '/follow.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    block_str = html_header_with_external_style(css_filename,
                                                instance_title, None,
                                                preload_images)
    block_str += '<div class="block">\n'
    block_str += '  <div class="blockAvatar">\n'
    block_str += '  <center>\n'
    block_str += '  <a href="' + block_actor + '">\n'
    block_str += \
        '  <img loading="lazy" decoding="async" src="' + \
        block_profile_url + '"/></a>\n'
    block_actor_nick = get_nickname_from_actor(block_actor)
    if block_actor_nick and block_domain:
        block_str += \
            '  <p class="blockText">' + translate['Stop blocking'] + ' ' + \
            block_actor_nick + '@' + block_domain + ' ?</p>\n'
    block_str += '  <form method="POST" action="' + \
        origin_path_str + '/unblockconfirm">\n'
    block_str += '    <input type="hidden" name="actor" value="' + \
        block_actor + '">\n'
    block_str += \
        '    <button type="submit" class="button" name="submitYes">' + \
        translate['Yes'] + '</button>\n'
    block_str += \
        '    <a href="' + origin_path_str + '"><button class="button">' + \
        translate['No'] + '</button></a>\n'
    block_str += '  </form>\n'
    block_str += '</center>\n'
    block_str += '</div>\n'
    block_str += '</div>\n'
    block_str += html_footer()
    return block_str


def html_confirm_block(translate: {}, base_dir: str,
                       origin_path_str: str,
                       block_actor: str,
                       block_profile_url: str) -> str:
    """Asks to confirm blocking an actor
    """
    block_domain, _ = get_domain_from_actor(block_actor)

    set_custom_background(base_dir, 'block-background', 'follow-background')

    css_filename = base_dir + '/epicyon-follow.css'
    if os.path.isfile(base_dir + '/follow.css'):
        css_filename = base_dir + '/follow.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    block_str = html_header_with_external_style(css_filename,
                                                instance_title, None,
                                                preload_images)
    block_str += '<div class="block">\n'
    block_str += '  <div class="blockAvatar">\n'
    block_str += '  <center>\n'
    block_str += '  <a href="' + block_actor + '">\n'
    block_str += \
        '  <img loading="lazy" decoding="async" src="' + \
        block_profile_url + '"/></a>\n'
    block_actor_nick = get_nickname_from_actor(block_actor)
    if block_actor_nick and block_domain:
        block_str += \
            '  <p class="blockText">' + translate['Block'] + ' ' + \
            block_actor_nick + '@' + block_domain + ' ?</p>\n'
    block_str += '  <form method="POST" action="' + \
        origin_path_str + '/blockconfirm">\n'
    block_str += '    <input type="hidden" name="actor" value="' + \
        block_actor + '">\n'
    block_str += \
        '    <button type="submit" class="button" name="submitYes">' + \
        translate['Yes'] + '</button>\n'
    block_str += \
        '    <a href="' + origin_path_str + '"><button class="button">' + \
        translate['No'] + '</button></a><br>\n'
    block_str += \
        '      <b><label class="labels">' + \
        translate['Reason'] + '</label></b><br>\n' + \
        '      <textarea id="message" ' + \
        'name="blockReason" style="width:90%;height:200px" ' + \
        'spellcheck="true"></textarea>\n'
    block_str += '  </form>\n'
    block_str += '</center>\n'
    block_str += '</div>\n'
    block_str += '</div>\n'
    block_str += html_footer()
    return block_str
