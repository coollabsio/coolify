__filename__ = "conversation.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"


import os
from conversation import download_conversation_posts
from flags import is_public_post
from utils import remove_id_ending
from utils import get_config_param
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_attributed_to
from utils import text_mode_removals
from blocking import is_blocked
from webapp_utils import text_mode_browser
from webapp_utils import html_header_with_external_style
from webapp_utils import html_post_separator
from webapp_utils import html_footer
from webapp_utils import get_banner_file
from webapp_post import individual_post_as_html


def html_conversation_view(authorized: bool, post_id: str,
                           translate: {}, base_dir: str,
                           http_prefix: str,
                           nickname: str, domain: str,
                           project_version: str,
                           recent_posts_cache: {},
                           max_recent_posts: int,
                           session,
                           cached_webfingers,
                           person_cache: {},
                           port: int,
                           yt_replace_domain: str,
                           twitter_replacement_domain: str,
                           show_published_date_only: bool,
                           peertube_instances: [],
                           allow_local_network_access: bool,
                           theme_name: str,
                           system_language: str,
                           max_like_count: int,
                           signing_priv_key_pem: str,
                           cw_lists: {},
                           lists_enabled: str,
                           timezone: str, bold_reading: bool,
                           dogwhistles: {}, access_keys: {},
                           min_images_for_accounts: [],
                           debug: bool, buy_sites: {},
                           blocked_cache: [],
                           block_federated: [],
                           auto_cw_cache: {},
                           ua_str: str,
                           default_timeline: str,
                           mitm_servers: [],
                           instance_software: {}) -> str:
    """Show a page containing a conversation thread
    """
    conv_posts = \
        download_conversation_posts(authorized,
                                    session, http_prefix, base_dir,
                                    nickname, domain,
                                    post_id, debug, mitm_servers)

    if not conv_posts:
        return None

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []

    metadata_str = ''
    if post_id:
        # https://swicg.github.io/activitypub-html-discovery/#html-link-element
        # link to the activitypub post
        metadata_str += \
            '    <link rel="alternate" type="application/activity+json" ' + \
            'href="' + post_id + '" />\n'

    conv_str = \
        html_header_with_external_style(css_filename, instance_title,
                                        metadata_str, preload_images)

    # banner and row of buttons
    users_path = '/users/' + nickname
    banner_file, _ = get_banner_file(base_dir, nickname, domain, theme_name)
    conv_str += \
        '<header>\n' + \
        '  <a href="/users/' + nickname + '/' + \
        default_timeline + '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '" ' + \
        'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
        access_keys['menuTimeline'] + '">\n'
    conv_str += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" alt="" ' + \
        'src="' + users_path + '/' + banner_file + '" /></a>\n' + \
        '</header>\n'

    separator_str = html_post_separator(base_dir, None)
    text_mode_separator = '<div class="transparent"><hr></div>\n'

    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    current_reading_str = ''
    for post_json_object in conv_posts:
        show_individual_post_icons = True
        # if not authorized then only show public posts
        if not authorized:
            show_individual_post_icons = False
            if not is_public_post(post_json_object):
                continue
        from_actor = \
            get_attributed_to(post_json_object['object']['attributedTo'])
        from_nickname = get_nickname_from_actor(from_actor)
        from_domain, _ = get_domain_from_actor(from_actor)
        # don't show icons on posts from blocked accounts/instances
        if from_nickname and from_domain:
            if is_blocked(base_dir, nickname, domain,
                          from_nickname, from_domain,
                          blocked_cache, block_federated):
                show_individual_post_icons = False
        allow_deletion = False
        post_str = \
            individual_post_as_html(signing_priv_key_pem,
                                    True, recent_posts_cache,
                                    max_recent_posts,
                                    translate, None,
                                    base_dir, session, cached_webfingers,
                                    person_cache,
                                    nickname, domain, port,
                                    post_json_object,
                                    None, True, allow_deletion,
                                    http_prefix, project_version,
                                    'search',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count,
                                    show_individual_post_icons,
                                    show_individual_post_icons,
                                    False, False, False, False,
                                    cw_lists, lists_enabled,
                                    timezone, False, bold_reading,
                                    dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites, auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
        if post_str:
            conv_str += \
                current_reading_str + text_mode_separator + \
                separator_str + post_str

        # show separator at the current reading point
        current_reading_str = ''
        if post_json_object.get('id'):
            if isinstance(post_json_object['id'], str):
                id_str = remove_id_ending(post_json_object['id'])
                if post_id in id_str:
                    current_reading_str = '<br><hr><br>\n'

    # if using a text mode browser then don't show SHOW MORE because there
    # is no way to hide/expand sections.
    # Also replace MITM text with an eye icon
    if text_mode_browser(ua_str):
        conv_str = text_mode_removals(conv_str, translate)

    conv_str += text_mode_separator + html_footer()
    return conv_str
