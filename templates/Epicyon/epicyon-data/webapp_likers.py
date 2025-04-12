__filename__ = "webapp_likers.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "ActivityPub"

import os
from utils import locate_post
from utils import get_config_param
from utils import get_account_timezone
from utils import get_display_name
from utils import get_nickname_from_actor
from utils import has_object_dict
from utils import load_json
from utils import get_actor_from_post
from person import get_person_avatar_url
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_banner_file
from webapp_utils import add_emoji_to_display_name
from webapp_post import individual_post_as_html


def html_likers_of_post(base_dir: str, nickname: str,
                        domain: str, port: int,
                        post_url: str, translate: {},
                        http_prefix: str,
                        theme: str, access_keys: {},
                        recent_posts_cache: {}, max_recent_posts: int,
                        session, cached_webfingers: {},
                        person_cache: {},
                        project_version: str,
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        system_language: str,
                        max_like_count: int, signing_priv_key_pem: str,
                        cw_lists: {}, lists_enabled: str,
                        box_name: str, default_timeline: str,
                        bold_reading: bool, dogwhistles: {},
                        min_images_for_accounts: [],
                        buy_sites: {}, auto_cw_cache: {},
                        dict_name: str,
                        mitm_servers: [],
                        instance_software: {}) -> str:
    """Returns html for a screen showing who liked a post
    """
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # get the post which was liked
    filename = locate_post(base_dir, nickname, domain, post_url)
    if not filename:
        return None
    post_json_object = load_json(filename)
    if not post_json_object:
        return None
    if not post_json_object.get('actor') or not post_json_object.get('object'):
        return None

    # show the top banner
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    html_str += \
        '<header>\n' + \
        '<a href="/users/' + nickname + '/' + default_timeline + \
        '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '">\n'
    html_str += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" src="' + \
        '/users/' + nickname + '/' + banner_file + '" alt="" /></a>\n' + \
        '</header>\n'

    # show the post which was liked
    timezone = get_account_timezone(base_dir, nickname, domain)
    mitm = False
    if os.path.isfile(filename.replace('.json', '') + '.mitm'):
        mitm = True
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    html_str += \
        individual_post_as_html(signing_priv_key_pem,
                                True, recent_posts_cache,
                                max_recent_posts,
                                translate, None,
                                base_dir, session,
                                cached_webfingers,
                                person_cache,
                                nickname, domain, port,
                                post_json_object,
                                None, True, False,
                                http_prefix,
                                project_version,
                                box_name,
                                yt_replace_domain,
                                twitter_replacement_domain,
                                show_published_date_only,
                                peertube_instances,
                                allow_local_network_access,
                                theme, system_language,
                                max_like_count,
                                False, False, False,
                                False, False, False,
                                cw_lists, lists_enabled,
                                timezone, mitm, bold_reading,
                                dogwhistles,
                                minimize_all_images, None,
                                buy_sites, auto_cw_cache,
                                mitm_servers,
                                instance_software)

    # show likers beneath the post
    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']
    if not obj.get(dict_name):
        return None
    if not isinstance(obj[dict_name], dict):
        return None
    if not obj[dict_name].get('items'):
        return None

    if dict_name == 'likes':
        html_str += \
            '<center><h2>' + translate['Liked by'] + '</h2></center>\n'
    else:
        html_str += \
            '<center><h2>' + translate['Repeated by'] + '</h2></center>\n'

    likers_list = ''
    for like_item in obj[dict_name]['items']:
        if not like_item.get('actor'):
            continue
        actor_url = get_actor_from_post(like_item)
        liker_actor = actor_url
        liker_display_name = \
            get_display_name(base_dir, liker_actor, person_cache)
        if liker_display_name:
            liker_name = liker_display_name
            if ':' in liker_name:
                liker_name = \
                    add_emoji_to_display_name(session, base_dir,
                                              http_prefix,
                                              nickname, domain,
                                              liker_name, False,
                                              translate)
        else:
            liker_name = get_nickname_from_actor(liker_actor)
            if not liker_name:
                liker_name = 'unknown'
        if likers_list:
            likers_list += ' '
        liker_avatar_url = \
            get_person_avatar_url(base_dir, liker_actor, person_cache)
        if not liker_avatar_url:
            liker_avatar_url = ''
        else:
            liker_avatar_url = ';' + liker_avatar_url
        liker_options_link = \
            '/users/' + nickname + '?options=' + \
            liker_actor + ';1' + liker_avatar_url
        likers_list += \
            '<label class="likerNames">' + \
            '<a href="' + liker_options_link + '">' + liker_name + '</a>' + \
            '</label>'
    html_str += '<center>\n' + likers_list + '\n</center>\n'

    return html_str + html_footer()
