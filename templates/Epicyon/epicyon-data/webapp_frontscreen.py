__filename__ = "webapp_frontscreen.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
from flags import is_system_account
from utils import get_domain_from_actor
from utils import get_config_param
from utils import get_account_timezone
from person import person_box_json
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_banner_file
from webapp_utils import html_post_separator
from webapp_utils import header_buttons_front_screen
from webapp_column_left import get_left_column_content
from webapp_column_right import get_right_column_content
from webapp_post import individual_post_as_html


def _html_front_screen_posts(recent_posts_cache: {}, max_recent_posts: int,
                             translate: {},
                             base_dir: str, http_prefix: str,
                             nickname: str, domain: str, port: int,
                             session, cached_webfingers: {}, person_cache: {},
                             project_version: str,
                             yt_replace_domain: str,
                             twitter_replacement_domain: str,
                             show_published_date_only: bool,
                             peertube_instances: [],
                             allow_local_network_access: bool,
                             theme_name: str, system_language: str,
                             max_like_count: int,
                             signing_priv_key_pem: str, cw_lists: {},
                             lists_enabled: str,
                             bold_reading: bool,
                             dogwhistles: {},
                             min_images_for_accounts: [],
                             buy_sites: {},
                             auto_cw_cache: {},
                             mitm_servers: [],
                             instance_software: {}) -> str:
    """Shows posts on the front screen of a news instance
    These should only be public blog posts from the features timeline
    which is the blog timeline of the news actor
    """
    separator_str = html_post_separator(base_dir, None)
    profile_str = ''
    max_items = 4
    ctr = 0
    curr_page = 1
    box_name = 'tlfeatures'
    authorized = True
    while ctr < max_items and curr_page < 4:
        outbox_feed_path_str = \
            '/users/' + nickname + '/' + box_name + \
            '?page=' + str(curr_page)
        outbox_feed = \
            person_box_json({}, base_dir, domain, port,
                            outbox_feed_path_str,
                            http_prefix, 10, box_name,
                            authorized, 0, False, 0)
        if not outbox_feed:
            break
        if len(outbox_feed['orderedItems']) == 0:
            break
        for item in outbox_feed['orderedItems']:
            if item['type'] != 'Create':
                continue
            timezone = get_account_timezone(base_dir, nickname, domain)
            minimize_all_images = False
            if nickname in min_images_for_accounts:
                minimize_all_images = True
            post_str = \
                individual_post_as_html(signing_priv_key_pem,
                                        True, recent_posts_cache,
                                        max_recent_posts,
                                        translate, None,
                                        base_dir, session,
                                        cached_webfingers,
                                        person_cache,
                                        nickname, domain, port, item,
                                        None, True, False,
                                        http_prefix,
                                        project_version, 'inbox',
                                        yt_replace_domain,
                                        twitter_replacement_domain,
                                        show_published_date_only,
                                        peertube_instances,
                                        allow_local_network_access,
                                        theme_name, system_language,
                                        max_like_count,
                                        False, False, False,
                                        True, False, False,
                                        cw_lists, lists_enabled,
                                        timezone, False,
                                        bold_reading, dogwhistles,
                                        minimize_all_images, None,
                                        buy_sites, auto_cw_cache,
                                        mitm_servers,
                                        instance_software)
            if post_str:
                profile_str += post_str + separator_str
                ctr += 1
                if ctr >= max_items:
                    break
        curr_page += 1
    return profile_str


def html_front_screen(signing_priv_key_pem: str,
                      rss_icon_at_top: bool,
                      icons_as_buttons: bool,
                      default_timeline: str,
                      recent_posts_cache: {}, max_recent_posts: int,
                      translate: {}, project_version: str,
                      base_dir: str, http_prefix: str, authorized: bool,
                      profile_json: {},
                      session, cached_webfingers: {}, person_cache: {},
                      yt_replace_domain: str,
                      twitter_replacement_domain: str,
                      show_published_date_only: bool,
                      newswire: {}, theme: str,
                      peertube_instances: [],
                      allow_local_network_access: bool,
                      access_keys: {},
                      system_language: str, max_like_count: int,
                      shared_items_federated_domains: [],
                      cw_lists: {}, lists_enabled: str,
                      dogwhistles: {},
                      min_images_for_accounts: [],
                      buy_sites: {},
                      auto_cw_cache: {},
                      known_epicyon_instances: [],
                      mitm_servers: [],
                      instance_software: {}) -> str:
    """Show the news instance front screen
    """
    bold_reading = False
    nickname = profile_json['preferredUsername']
    if not nickname:
        return ""
    if not is_system_account(nickname):
        return ""
    domain, port = get_domain_from_actor(profile_json['id'])
    if not domain:
        return ""
    domain_full = domain
    if port:
        domain_full = domain + ':' + str(port)

    login_button = header_buttons_front_screen(translate, nickname,
                                               'features', authorized,
                                               icons_as_buttons)

    # If this is the news account then show a different banner
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    profile_header_str = \
        '<img loading="lazy" decoding="async" class="timeline-banner" ' + \
        'src="/users/' + nickname + '/' + banner_file + '" />\n'
    if login_button:
        profile_header_str += '<center>' + login_button + '</center>\n'

    profile_header_str += \
        '<table class="timeline">\n' + \
        '  <colgroup>\n' + \
        '    <col span="1" class="column-left">\n' + \
        '    <col span="1" class="column-center">\n' + \
        '    <col span="1" class="column-right">\n' + \
        '  </colgroup>\n' + \
        '  <tbody>\n' + \
        '    <tr>\n' + \
        '      <td valign="top" class="col-left" tabindex="-1">\n'
    profile_header_str += \
        get_left_column_content(base_dir, 'news', domain_full,
                                http_prefix, translate,
                                False, False,
                                False, None, rss_icon_at_top, True,
                                True, theme, access_keys,
                                shared_items_federated_domains,
                                known_epicyon_instances)
    profile_header_str += \
        '      </td>\n' + \
        '      <td valign="top" class="col-center" tabindex="-1">\n'

    profile_str = profile_header_str

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    license_str = ''
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    profile_str += \
        _html_front_screen_posts(recent_posts_cache, max_recent_posts,
                                 translate,
                                 base_dir, http_prefix,
                                 nickname, domain, port,
                                 session, cached_webfingers, person_cache,
                                 project_version,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 peertube_instances,
                                 allow_local_network_access,
                                 theme, system_language,
                                 max_like_count,
                                 signing_priv_key_pem,
                                 cw_lists, lists_enabled,
                                 bold_reading, dogwhistles,
                                 min_images_for_accounts,
                                 buy_sites,
                                 auto_cw_cache,
                                 mitm_servers,
                                 instance_software) + license_str

    # Footer which is only used for system accounts
    profile_footer_str = '      </td>\n'
    profile_footer_str += \
        '      <td valign="top" class="col-right" tabindex="-1">\n'
    profile_footer_str += \
        get_right_column_content(base_dir, 'news', domain_full,
                                 translate,
                                 False, False, newswire, False,
                                 False, None, False, False,
                                 False, True, authorized, True, theme,
                                 default_timeline, access_keys)
    profile_footer_str += \
        '      </td>\n' + \
        '  </tr>\n' + \
        '  </tbody>\n' + \
        '</table>\n'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    profile_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images) + \
        profile_str + profile_footer_str + html_footer()
    return profile_str
