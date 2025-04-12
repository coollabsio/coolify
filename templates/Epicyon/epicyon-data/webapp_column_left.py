__filename__ = "webapp_column_left.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface Columns"

import os
from flags import is_editor
from flags import is_artist
from utils import replace_strings
from utils import data_dir
from utils import get_config_param
from utils import get_nickname_from_actor
from utils import remove_domain_port
from utils import local_actor_url
from webapp_utils import shares_timeline_json
from webapp_utils import html_post_separator
from webapp_utils import get_left_image_file
from webapp_utils import header_buttons_front_screen
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_banner_file
from webapp_utils import edit_text_field
from shares import share_category_icon


def _links_exist(base_dir: str) -> bool:
    """Returns true if links have been created
    """
    links_filename = data_dir(base_dir) + '/links.txt'
    return os.path.isfile(links_filename)


def _get_left_column_shares(base_dir: str,
                            http_prefix: str, domain: str, domain_full: str,
                            nickname: str,
                            max_shares_in_left_column: int,
                            translate: {},
                            shared_items_federated_domains: []) -> []:
    """get any shares and turn them into the left column links format
    """
    page_number = 1
    actor = local_actor_url(http_prefix, nickname, domain_full)
    # NOTE: this could potentially be slow if the number of federated
    # shared items is large
    shares_json, _ = \
        shares_timeline_json(actor, page_number, max_shares_in_left_column,
                             base_dir, domain, nickname,
                             max_shares_in_left_column,
                             shared_items_federated_domains, 'shares')
    if not shares_json:
        return []

    links_list: list[str] = []
    ctr = 0
    for _, item in shares_json.items():
        sharedesc = item['displayName']
        if '<' in sharedesc or '?' in sharedesc:
            continue
        share_id = item['shareId']
        # selecting this link calls html_show_share
        share_link = actor + '?showshare=' + share_id
        if item.get('category'):
            share_link += '?category=' + item['category']
            share_category = share_category_icon(item['category'])

        links_list.append(share_category + sharedesc + ' ' + share_link)
        ctr += 1
        if ctr >= max_shares_in_left_column:
            break

    if links_list:
        links_list = ['* ' + translate['Shares']] + links_list
    return links_list


def _get_left_column_wanted(base_dir: str,
                            http_prefix: str, domain: str, domain_full: str,
                            nickname: str,
                            max_shares_in_left_column: int,
                            translate: {},
                            shared_items_federated_domains: []) -> []:
    """get any wanted items and turn them into the left column links format
    """
    page_number = 1
    actor = local_actor_url(http_prefix, nickname, domain_full)
    # NOTE: this could potentially be slow if the number of federated
    # wanted items is large
    shares_json, _ = \
        shares_timeline_json(actor, page_number, max_shares_in_left_column,
                             base_dir, domain, nickname,
                             max_shares_in_left_column,
                             shared_items_federated_domains, 'wanted')
    if not shares_json:
        return []

    links_list: list[str] = []
    ctr = 0
    for _, item in shares_json.items():
        sharedesc = item['displayName']
        if '<' in sharedesc or ';' in sharedesc:
            continue
        share_id = item['shareId']
        # selecting this link calls html_show_share
        share_link = actor + '?showwanted=' + share_id
        links_list.append(sharedesc + ' ' + share_link)
        ctr += 1
        if ctr >= max_shares_in_left_column:
            break

    if links_list:
        links_list = ['* ' + translate['Wanted']] + links_list
    return links_list


def get_left_column_content(base_dir: str, nickname: str, domain_full: str,
                            http_prefix: str, translate: {},
                            editor: bool, artist: bool,
                            show_back_button: bool, timeline_path: str,
                            rss_icon_at_top: bool, show_header_image: bool,
                            front_page: bool, theme: str,
                            access_keys: {},
                            shared_items_federated_domains: [],
                            known_epicyon_instances: []) -> str:
    """Returns html content for the left column
    """
    html_str = ''

    separator_str = html_post_separator(base_dir, 'left')
    domain = remove_domain_port(domain_full)

    edit_image_class = ''
    if show_header_image:
        left_image_file, left_column_image_filename = \
            get_left_image_file(base_dir, nickname, domain, theme)

        # show the image at the top of the column
        edit_image_class = 'leftColEdit'
        if os.path.isfile(left_column_image_filename):
            edit_image_class = 'leftColEditImage'
            html_str += \
                '\n      <center>\n        <img class="leftColImg" ' + \
                'alt="" loading="lazy" decoding="async" src="/users/' + \
                nickname + '/' + left_image_file + '" />\n' + \
                '      </center>\n'

    if show_back_button:
        html_str += \
            '      <div>      <a href="' + timeline_path + '">' + \
            '<button class="cancelbtn">' + \
            translate['Go Back'] + '</button></a>\n'

    if (editor or rss_icon_at_top) and not show_header_image:
        html_str += '<div class="columnIcons">'

    if edit_image_class == 'leftColEdit':
        html_str += '\n      <center>\n'

    # start of links column icons
    html_str += '      <div class="leftColIcons">\n'

    # 1. RSS icon
    if nickname != 'news':
        # rss feed for this account
        rss_url = http_prefix + '://' + domain_full + \
            '/blog/' + nickname + '/rss.xml'
    else:
        # rss feed for all accounts on the instance
        rss_url = http_prefix + '://' + domain_full + '/blog/rss.xml'
    if not front_page:
        rss_title = translate['RSS feed for your blog']
    else:
        rss_title = translate['RSS feed for this site']
    rss_icon_str = \
        '      <a href="' + rss_url + '" tabindex="5" class="imageAnchor">' + \
        '<img class="' + edit_image_class + \
        '" loading="lazy" decoding="async" alt="' + \
        rss_title + '" title="' + rss_title + \
        '" src="/icons/logorss.png" /></a>\n'
    if rss_icon_at_top:
        html_str += rss_icon_str

    if artist:
        # 2. show the theme designer icon
        html_str += \
            '      <a href="/users/' + nickname + '/themedesigner" ' + \
            'accesskey="' + access_keys['menuThemeDesigner'] + \
            '" tabindex="5" class="imageAnchor">' + \
            '<img class="' + edit_image_class + \
            '" loading="lazy" decoding="async" alt="' + \
            translate['Theme Designer'] + ' | " title="' + \
            translate['Theme Designer'] + '" src="/icons/theme.png" /></a>\n'

    if editor:
        # 3. show the edit icon
        html_str += \
            '      <a href="/users/' + nickname + '/editlinks" ' + \
            'accesskey="' + access_keys['menuEdit'] + '" tabindex="5" ' + \
            'class="imageAnchor">' + \
            '<img class="' + edit_image_class + \
            '" loading="lazy" decoding="async" alt="' + \
            translate['Edit Links'] + ' | " title="' + \
            translate['Edit Links'] + '" src="/icons/edit.png" /></a>\n'

    # end of links column icons
    html_str += '      </div>\n'

    if edit_image_class == 'leftColEdit':
        html_str += '      </center>\n'

    if (editor or rss_icon_at_top) and not show_header_image:
        html_str += '</div><br>'

    # if show_header_image:
    #     html_str += '<br>'

    # flag used not to show the first separator
    first_separator_added = False

    links_filename = data_dir(base_dir) + '/links.txt'
    links_file_contains_entries = False
    links_list = None
    if os.path.isfile(links_filename):
        try:
            with open(links_filename, 'r', encoding='utf-8') as fp_links:
                links_list = fp_links.readlines()
        except OSError:
            print('EX: get_left_column_content unable to read ' +
                  links_filename)

    if not front_page:
        # show a number of shares
        max_shares_in_left_column = 3
        shares_list = \
            _get_left_column_shares(base_dir,
                                    http_prefix, domain, domain_full, nickname,
                                    max_shares_in_left_column, translate,
                                    shared_items_federated_domains)
        if links_list and shares_list:
            links_list = shares_list + links_list

        wanted_list = \
            _get_left_column_wanted(base_dir,
                                    http_prefix, domain, domain_full, nickname,
                                    max_shares_in_left_column, translate,
                                    shared_items_federated_domains)
        if links_list and wanted_list:
            links_list = wanted_list + links_list

    new_tab_str = ' target="_blank" rel="nofollow noopener noreferrer"'
    if links_list:
        html_str += '<nav itemscope itemtype="http://schema.org/Collection">\n'
        for line_str in links_list:
            if ' ' not in line_str:
                if '#' not in line_str:
                    if '*' not in line_str:
                        if not line_str.startswith('['):
                            if not line_str.startswith('=> '):
                                continue
            line_str = line_str.strip()
            link_str = None
            if not line_str.startswith('['):
                words = line_str.split(' ')
                # get the link
                for word in words:
                    if word in ('#', '*', '=>'):
                        continue
                    if '://' in word:
                        link_str = word
                        break
            else:
                # markdown link
                if ']' not in line_str:
                    continue
                if '(' not in line_str:
                    continue
                if ')' not in line_str:
                    continue
                link_str = line_str.split('(')[1]
                if ')' not in link_str:
                    continue
                link_str = link_str.split(')')[0]
                if '://' not in link_str:
                    continue
                line_str = line_str.split('[')[1]
                if ']' not in line_str:
                    continue
                line_str = line_str.split(']')[0]
            if link_str:
                line_str = line_str.replace(link_str, '').strip()
                # avoid any dubious scripts being added
                if '<' not in line_str:
                    # remove trailing comma if present
                    if line_str.endswith(','):
                        line_str = line_str[:len(line_str)-1]
                    # add link to the returned html
                    if '?showshare=' not in link_str and \
                       '?showwarning=' not in link_str:
                        html_str += \
                            '      <p><a href="' + link_str + \
                            '"' + new_tab_str + '>' + \
                            line_str + '</a></p>\n'
                    else:
                        html_str += \
                            '      <p><a href="' + link_str + \
                            '">' + line_str + '</a></p>\n'
                    links_file_contains_entries = True
                elif line_str.startswith('=> '):
                    # gemini style link
                    line_str = line_str.replace('=> ', '')
                    line_str = line_str.replace(link_str, '')
                    # add link to the returned html
                    if '?showshare=' not in link_str and \
                       '?showwarning=' not in link_str:
                        html_str += \
                            '      <p><a href="' + link_str + \
                            '"' + new_tab_str + '>' + \
                            line_str.strip() + '</a></p>\n'
                    else:
                        html_str += \
                            '      <p><a href="' + link_str + \
                            '">' + line_str.strip() + '</a></p>\n'
                    links_file_contains_entries = True
            else:
                if line_str.startswith('#') or line_str.startswith('*'):
                    line_str = line_str[1:].strip()
                    if first_separator_added:
                        html_str += separator_str
                    first_separator_added = True
                    html_str += \
                        '      <h3 class="linksHeader">' + \
                        line_str + '</h3>\n'
                else:
                    html_str += \
                        '      <p>' + line_str + '</p>\n'
                links_file_contains_entries = True
        html_str += '</nav>\n'

    if first_separator_added:
        html_str += separator_str
    html_str += \
        '<p class="login-text"><a href="/users/' + nickname + \
        '/catalog.csv">' + translate['Shares Catalog'] + '</a></p>'
    html_str += \
        '<p class="login-text"><a href="/users/' + \
        nickname + '/accesskeys" accesskey="' + \
        access_keys['menuKeys'] + '">' + \
        translate['Key Shortcuts'] + '</a></p>'
    html_str += \
        '<p class="login-text"><a href="/about">' + \
        translate['About this Instance'] + '</a></p>'
    if known_epicyon_instances:
        html_str += \
            '<p class="login-text"><a href="/users/' + \
            nickname + '/knowninstances">' + \
            translate['Epicyon Instances'] + '</a></p>'
    html_str += \
        '<p class="login-text"><a href="/manual">' + \
        translate['User Manual'] + '</a></p>'
    html_str += \
        '<p class="login-text"><a href="/activitypub">' + \
        translate['ActivityPub Specification'] + '</a></p>'
    html_str += \
        '<p class="login-text"><a href="/terms">' + \
        translate['Terms of Service'] + '</a></p>'

    if links_file_contains_entries and not rss_icon_at_top:
        html_str += '<br><div class="columnIcons">' + rss_icon_str + '</div>'

    return html_str


def html_links_mobile(base_dir: str,
                      nickname: str, domain_full: str,
                      http_prefix: str, translate,
                      timeline_path: str, authorized: bool,
                      rss_icon_at_top: bool,
                      icons_as_buttons: bool,
                      default_timeline: str,
                      theme: str, access_keys: {},
                      shared_items_federated_domains: [],
                      known_epicyon_instances: []) -> str:
    """Show the left column links within mobile view
    """
    html_str = ''

    # the css filename
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    # is the user a site editor?
    if nickname == 'news':
        editor = False
        artist = False
    else:
        editor = is_editor(base_dir, nickname)
        artist = is_artist(base_dir, nickname)

    domain = remove_domain_port(domain_full)

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    html_str += \
        '<a href="/users/' + nickname + '/' + default_timeline + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '">' + \
        '<img loading="lazy" decoding="async" class="timeline-banner" ' + \
        'alt="' + translate['Switch to timeline view'] + '" ' + \
        'src="/users/' + nickname + '/' + banner_file + '" /></a>\n'

    html_str += '<div class="col-left-mobile">\n'
    html_str += '<center>' + \
        header_buttons_front_screen(translate, nickname,
                                    'links', authorized,
                                    icons_as_buttons) + '</center>'
    html_str += \
        get_left_column_content(base_dir, nickname, domain_full,
                                http_prefix, translate,
                                editor, artist,
                                False, timeline_path,
                                rss_icon_at_top, False, False,
                                theme, access_keys,
                                shared_items_federated_domains,
                                known_epicyon_instances)
    if editor and not _links_exist(base_dir):
        html_str += '<br><br><br>\n<center>\n  '
        html_str += translate['Select the edit icon to add web links']
        html_str += '\n</center>\n'

    # end of col-left-mobile
    html_str += '</div>\n'

    html_str += '</div>\n' + html_footer()
    return html_str


def html_edit_links(translate: {}, base_dir: str, path: str,
                    domain: str,
                    default_timeline: str, theme: str,
                    access_keys: {}) -> str:
    """Shows the edit links screen
    """
    if '/users/' not in path:
        return ''

    replacements = {
        '/inbox': '',
        '/outbox': '',
        '/shares': '',
        '/wanted': ''
    }
    path = replace_strings(path, replacements)

    nickname = get_nickname_from_actor(path)
    if not nickname:
        return ''

    # is the user a moderator?
    if not is_editor(base_dir, nickname):
        return ''

    css_filename = base_dir + '/epicyon-links.css'
    if os.path.isfile(base_dir + '/links.css'):
        css_filename = base_dir + '/links.css'

    # filename of the banner shown at the top
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    edit_links_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # top banner
    edit_links_form += \
        '<header>\n' + \
        '<a href="/users/' + nickname + '/' + default_timeline + \
        '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '">\n'
    edit_links_form += \
        '<img loading="lazy" decoding="async" class="timeline-banner" ' + \
        'alt = "" src="' + \
        '/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
        '</header>\n'

    edit_links_form += \
        '<form enctype="multipart/form-data" method="POST" ' + \
        'accept-charset="UTF-8" action="' + path + '/linksdata">\n'
    edit_links_form += \
        '  <div class="vertical-center">\n'
    edit_links_form += \
        '    <div class="containerSubmitNewPost">\n'
    edit_links_form += \
        '      <h1>' + translate['Edit Links'] + '</h1>'
    edit_links_form += \
        '      <input type="submit" name="submitLinks" value="' + \
        translate['Publish'] + '" ' + \
        'accesskey="' + access_keys['submitButton'] + '">\n'
    edit_links_form += \
        '    </div>\n'

    links_filename = data_dir(base_dir) + '/links.txt'
    links_str = ''
    if os.path.isfile(links_filename):
        try:
            with open(links_filename, 'r', encoding='utf-8') as fp_links:
                links_str = fp_links.read()
        except OSError:
            print('EX: html_edit_links unable to read ' +
                  links_filename)

    edit_links_form += \
        '<div class="container">'
    edit_links_form += \
        '  ' + \
        translate['One link per line. Description followed by the link.'] + \
        '<br>'
    new_col_link_str = translate['New link title and URL']
    edit_links_form += \
        edit_text_field(None, 'newColLink', '', new_col_link_str)
    edit_links_form += \
        '  <textarea id="message" name="editedLinks" ' + \
        'style="height:80vh" spellcheck="false">' + links_str + '</textarea>'
    edit_links_form += \
        '</div>'

    # the admin can edit terms of service, about and specification text
    admin_nickname = get_config_param(base_dir, 'admin')
    if admin_nickname:
        if nickname == admin_nickname:
            about_filename = data_dir(base_dir) + '/about.md'
            about_str = ''
            if os.path.isfile(about_filename):
                try:
                    with open(about_filename, 'r',
                              encoding='utf-8') as fp_about:
                        about_str = fp_about.read()
                except OSError:
                    print('EX: html_edit_links unable to read 2 ' +
                          about_filename)

            edit_links_form += \
                '<div class="container">'
            edit_links_form += \
                '  ' + \
                translate['About this Instance'] + \
                '<br>'
            edit_links_form += \
                '  <textarea id="message" name="editedAbout" ' + \
                'style="height:100vh" spellcheck="true" autocomplete="on">' + \
                about_str + '</textarea>'
            edit_links_form += \
                '</div>'

            tos_filename = data_dir(base_dir) + '/tos.md'
            tos_str = ''
            if os.path.isfile(tos_filename):
                try:
                    with open(tos_filename, 'r', encoding='utf-8') as fp_tos:
                        tos_str = fp_tos.read()
                except OSError:
                    print('EX: html_edit_links unable to read 3 ' +
                          tos_filename)

            edit_links_form += \
                '<div class="container">'
            edit_links_form += \
                '  ' + \
                translate['Terms of Service'] + \
                '<br>'
            edit_links_form += \
                '  <textarea id="message" name="editedTOS" ' + \
                'style="height:100vh" spellcheck="true" autocomplete="on">' + \
                tos_str + '</textarea>'
            edit_links_form += \
                '</div>'

            specification_filename = data_dir(base_dir) + '/activitypub.md'
            specification_str = ''
            if os.path.isfile(specification_filename):
                try:
                    with open(specification_filename, 'r',
                              encoding='utf-8') as fp_specification:
                        specification_str = fp_specification.read()
                except OSError:
                    print('EX: html_edit_links unable to read 4 ' +
                          specification_filename)

            edit_links_form += \
                '<div class="container">'
            edit_links_form += \
                '  ' + \
                translate['ActivityPub Specification'] + \
                '<br>'
            edit_links_form += \
                '  <textarea id="message" name="editedSpecification" ' + \
                'style="height:1000vh" spellcheck="true" ' + \
                'autocomplete="on">' + specification_str + '</textarea>'
            edit_links_form += \
                '</div>'

    edit_links_form += html_footer()
    return edit_links_form
