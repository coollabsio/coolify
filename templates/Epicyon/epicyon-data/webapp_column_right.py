__filename__ = "webapp_column_right.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface Columns"

import os
from content import remove_long_words
from content import limit_repeated_words
from flags import is_editor
from utils import replace_strings
from utils import data_dir
from utils import get_image_extensions
from utils import get_fav_filename_from_url
from utils import get_base_content_from_post
from utils import remove_html
from utils import locate_post
from utils import load_json
from utils import votes_on_newswire_item
from utils import get_nickname_from_actor
from utils import get_config_param
from utils import remove_domain_port
from utils import acct_dir
from utils import date_from_string_format
from posts import is_moderator
from newswire import get_newswire_favicon_url
from webapp_utils import get_right_image_file
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_banner_file
from webapp_utils import html_post_separator
from webapp_utils import header_buttons_front_screen
from webapp_utils import edit_text_field
from webapp_utils import text_mode_browser


def _votes_indicator(total_votes: int, positive_voting: bool) -> str:
    """Returns an indicator of the number of votes on a newswire item
    """
    if total_votes <= 0:
        return ''
    total_votes_str = ' '
    for _ in range(total_votes):
        if positive_voting:
            total_votes_str += '✓'
        else:
            total_votes_str += '✗'
    return total_votes_str


def get_right_column_content(base_dir: str, nickname: str, domain_full: str,
                             translate: {}, moderator: bool, editor: bool,
                             newswire: {}, positive_voting: bool,
                             show_back_button: bool, timeline_path: str,
                             show_publish_button: bool,
                             show_publish_as_icon: bool,
                             rss_icon_at_top: bool,
                             publish_button_at_top: bool,
                             authorized: bool,
                             show_header_image: bool,
                             theme: str,
                             default_timeline: str,
                             access_keys: {}) -> str:
    """Returns html content for the right column
    """
    html_str = ''

    domain = remove_domain_port(domain_full)

    if authorized:
        # only show the publish button if logged in, otherwise replace it with
        # a login button
        title_str = translate['Publish a blog article']
        if default_timeline == 'tlfeatures':
            title_str = translate['Publish a news article']
        publish_button_str = \
            '        <a href="' + \
            '/users/' + nickname + '/newblog?nodropdown" ' + \
            'title="' + title_str + '" ' + \
            'accesskey="' + access_keys['menuNewBlog'] + '">' + \
            '<button class="publishbtn" tabindex="4">' + \
            translate['Publish'] + '</button></a>\n'
    else:
        # if not logged in then replace the publish button with
        # a login button
        publish_button_str = \
            '        <a href="/login">' + \
            '<button class="publishbtn" tabindex="4">' + \
            translate['Login'] + '</button></a>\n'

    # show publish button at the top if needed
    if publish_button_at_top:
        html_str += '<center>' + publish_button_str + '</center>'

    # show a column header image, eg. title of the theme or newswire banner
    edit_image_class = ''
    if show_header_image:
        right_image_file, right_column_image_filename = \
            get_right_image_file(base_dir, nickname, domain, theme)

        # show the image at the top of the column
        edit_image_class = 'rightColEdit'
        if os.path.isfile(right_column_image_filename):
            edit_image_class = 'rightColEditImage'
            html_str += \
                '\n      <center>\n' + \
                '          <img class="rightColImg" ' + \
                'alt="" loading="lazy" decoding="async" src="/users/' + \
                nickname + '/' + right_image_file + '" />\n' + \
                '      </center>\n'

    if show_publish_button or editor or rss_icon_at_top:
        if not show_header_image:
            html_str += '<div class="columnIcons">'

    if edit_image_class == 'rightColEdit':
        html_str += '\n      <center>\n'

    # whether to show a back icon
    # This is probably going to be osolete soon
    if show_back_button:
        html_str += \
            '      <a href="' + timeline_path + '">' + \
            '<button class="cancelbtn">' + \
            translate['Go Back'] + '</button></a>\n'

    if show_publish_button and not publish_button_at_top:
        if not show_publish_as_icon:
            html_str += publish_button_str

    # start of newswire column icons
    html_str += '        <div class="headernewswireicons">\n'

    # 1. show publish icon at top
    if show_publish_button:
        if show_publish_as_icon:
            title_str = translate['Publish a blog article']
            if default_timeline == 'tlfeatures':
                title_str = translate['Publish a news article']
            html_str += \
                '        <a href="' + \
                '/users/' + nickname + '/newblog?nodropdown" ' + \
                'accesskey="' + access_keys['menuNewBlog'] + \
                '" class="imageAnchor" tabindex="4">' + \
                '<img class="' + edit_image_class + \
                '" loading="lazy" decoding="async" alt="' + \
                title_str + '" title="' + \
                title_str + '" src="/' + \
                'icons/publish.png" /></a>\n'

    # 2. show the RSS and categories icons
    rss_icon_str = \
        '        <a href="/newswire.xml" tabindex="4" class="imageAnchor">' + \
        '<img class="' + edit_image_class + \
        '" loading="lazy" decoding="async" alt="' + \
        translate['Newswire RSS Feed'] + ' | " title="' + \
        translate['Newswire RSS Feed'] + '" src="/' + \
        'icons/logorss.png" /></a>\n'
    rss_icon_str += \
        '        <a href="/categories.xml" tabindex="4" ' + \
        'class="imageAnchor">' + \
        '<img class="' + edit_image_class + \
        '" loading="lazy" decoding="async" alt="' + \
        translate['Hashtag Categories RSS Feed'] + ' | " title="' + \
        translate['Hashtag Categories RSS Feed'] + '" src="/' + \
        'icons/categoriesrss.png" /></a>\n'
    if rss_icon_at_top:
        html_str += rss_icon_str

    # 3. show the edit icon
    if editor:
        dir_str = data_dir(base_dir)
        if os.path.isfile(dir_str + '/newswiremoderation.txt'):
            # show the edit icon highlighted
            html_str += \
                '        <a href="' + \
                '/users/' + nickname + '/editnewswire" ' + \
                'accesskey="' + access_keys['menuEdit'] + \
                '" tabindex="4" class="imageAnchor">' + \
                '<img class="' + edit_image_class + \
                '" loading="lazy" decoding="async" alt="' + \
                translate['Edit newswire'] + ' | " title="' + \
                translate['Edit newswire'] + '" src="/' + \
                'icons/edit_notify.png" /></a>\n'
        else:
            # show the edit icon
            html_str += \
                '        <a href="' + \
                '/users/' + nickname + '/editnewswire" ' + \
                'accesskey="' + access_keys['menuEdit'] + \
                '" tabindex="4" class="imageAnchor">' + \
                '<img class="' + edit_image_class + \
                '" loading="lazy" decoding="async" alt="' + \
                translate['Edit newswire'] + ' | " title="' + \
                translate['Edit newswire'] + '" src="/' + \
                'icons/edit.png" /></a>\n'

    # end of newswire column icons
    html_str += '        </div>\n'

    if edit_image_class == 'rightColEdit':
        html_str += '      </center>\n'
    else:
        if show_header_image:
            html_str += '      <br>\n'

    if show_publish_button or editor or rss_icon_at_top:
        if not show_header_image:
            html_str += '</div><br>'

    # show the newswire lines
    newswire_content_str = \
        _html_newswire(base_dir, newswire, nickname, moderator, translate,
                       positive_voting)
    html_str += newswire_content_str

    # show the rss icon at the bottom, typically on the right hand side
    if newswire_content_str and not rss_icon_at_top:
        html_str += '<br><div class="columnIcons">' + rss_icon_str + '</div>'
    return html_str


def _get_broken_fav_substitute() -> str:
    """Substitute link used if a favicon is not available
    """
    return " onerror=\"this.onerror=null; this.src='/newswire_favicon.ico'\""


def _html_newswire(base_dir: str, newswire: {}, nickname: str, moderator: bool,
                   translate: {}, positive_voting: bool) -> str:
    """Converts a newswire dict into html
    """
    separator_str = html_post_separator(base_dir, 'right')
    html_str = ''
    replacements1 = {
        'T': ' ',
        'Z': ''
    }
    replacements2 = {
        ' ': '__',
        ':': 'aa'
    }
    for date_str, item in newswire.items():
        item[0] = remove_html(item[0]).strip()
        if not item[0]:
            continue
        # remove any CDATA
        if 'CDATA[' in item[0]:
            item[0] = item[0].split('CDATA[')[1]
            if ']' in item[0]:
                item[0] = item[0].split(']')[0]

        published_date = \
            date_from_string_format(date_str, ["%Y-%m-%d %H:%M:%S%z"])
        if not published_date:
            print('EX: _html_newswire bad date format ' + date_str)
            continue
        date_shown = published_date.strftime("%Y-%m-%d %H:%M")

        date_str_link = replace_strings(date_str, replacements1)
        url = item[1]
        favicon_url = get_newswire_favicon_url(url)
        favicon_link = ''
        if favicon_url:
            cached_favicon_filename = \
                get_fav_filename_from_url(base_dir, favicon_url)
            if os.path.isfile(cached_favicon_filename):
                favicon_url = \
                    cached_favicon_filename.replace(base_dir, '')
            else:
                extensions = get_image_extensions()
                for ext in extensions:
                    cached_favicon_filename = \
                        get_fav_filename_from_url(base_dir, favicon_url)
                    cached_favicon_filename = \
                        cached_favicon_filename.replace('.ico', '.' + ext)
                    if os.path.isfile(cached_favicon_filename):
                        favicon_url = \
                            cached_favicon_filename.replace(base_dir, '')

            favicon_link = \
                '<img loading="lazy" decoding="async" ' + \
                'src="' + favicon_url + '" ' + \
                'alt="" ' + _get_broken_fav_substitute() + '/>'
        moderated_item = item[5]
        link_url = url

        # is this a podcast episode?
        if len(item) > 8:
            # change the link url to a podcast episode screen
            podcast_properties = item[8]
            if podcast_properties:
                if podcast_properties.get('image'):
                    episode_id = replace_strings(date_str, replacements2)
                    link_url = \
                        '/users/' + nickname + '/?podepisode=' + episode_id

        html_str += separator_str
        if moderated_item and 'vote:' + nickname in item[2]:
            total_votes_str = ''
            total_votes = 0
            if moderator:
                total_votes = votes_on_newswire_item(item[2])
                total_votes_str = \
                    _votes_indicator(total_votes, positive_voting)

            title = remove_long_words(item[0], 16, []).replace('\n', '<br>')
            title = limit_repeated_words(title, 6)
            html_str += '<p class="newswireItemVotedOn">' + \
                '<a href="' + link_url + '" target="_blank" ' + \
                'rel="nofollow noopener noreferrer">' + \
                '<span class="newswireItemVotedOn">' + \
                favicon_link + title + '</span></a>' + total_votes_str
            if moderator:
                html_str += \
                    ' ' + date_shown + '<a href="/users/' + nickname + \
                    '/newswireunvote=' + date_str_link + '" ' + \
                    'title="' + translate['Remove Vote'] + \
                    '" class="imageAnchor">'
                html_str += '<img loading="lazy" decoding="async" ' + \
                    'class="voteicon" src="/' + \
                    'alt="' + translate['Remove Vote'] + '" ' + \
                    'icons/vote.png" /></a></p>\n'
            else:
                html_str += ' <span class="newswireDateVotedOn">'
                html_str += date_shown + '</span></p>\n'
        else:
            total_votes_str = ''
            total_votes = 0
            if moderator:
                if moderated_item:
                    total_votes = votes_on_newswire_item(item[2])
                    # show a number of ticks or crosses for how many
                    # votes for or against
                    total_votes_str = \
                        _votes_indicator(total_votes, positive_voting)

            title = remove_long_words(item[0], 16, []).replace('\n', '<br>')
            title = limit_repeated_words(title, 6)
            if moderator and moderated_item:
                html_str += '<p class="newswireItemModerated">' + \
                    '<a href="' + link_url + '" target="_blank" ' + \
                    'rel="nofollow noopener noreferrer">' + \
                    favicon_link + title + '</a>' + total_votes_str
                html_str += ' ' + date_shown
                html_str += '<a href="/users/' + nickname + \
                    '/newswirevote=' + date_str_link + '" ' + \
                    'title="' + translate['Vote'] + '" class="imageAnchor">'
                html_str += '<img class="voteicon" ' + \
                    'alt="' + translate['Vote'] + '" ' + \
                    'src="/icons/vote.png" /></a>'
                html_str += '</p>\n'
            else:
                html_str += '<p class="newswireItem">' + \
                    '<a href="' + link_url + '" target="_blank" ' + \
                    'rel="nofollow noopener noreferrer">' + \
                    favicon_link + title + '</a>' + total_votes_str
                html_str += ' <span class="newswireDate">'
                html_str += date_shown + '</span></p>\n'

    if html_str:
        html_str = \
            '<nav itemscope itemtype="http://schema.org/webFeed">\n' + \
            html_str + '</nav>\n'
    return html_str


def html_citations(base_dir: str, nickname: str, domain: str,
                   translate: {}, newswire: {},
                   blog_title: str, blog_content: str,
                   theme: str) -> str:
    """Show the citations screen when creating a blog
    """
    html_str = ''

    # create a list of dates for citations
    # these can then be used to re-select checkboxes later
    citations_filename = \
        acct_dir(base_dir, nickname, domain) + '/.citations.txt'
    citations_selected: list[str] = []
    if os.path.isfile(citations_filename):
        citations_separator = '#####'
        citations: list[str] = []
        try:
            with open(citations_filename, 'r', encoding='utf-8') as fp_cit:
                citations = fp_cit.readlines()
        except OSError as exc:
            print('EX: html_citations unable to read ' +
                  citations_filename + ' ' + str(exc))
        for line in citations:
            if citations_separator not in line:
                continue
            sections = line.strip().split(citations_separator)
            if len(sections) != 3:
                continue
            date_str = sections[0]
            citations_selected.append(date_str)

    # the css filename
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # top banner
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    html_str += \
        '<a href="/users/' + nickname + '/newblog" title="' + \
        translate['Go Back'] + '" alt="' + \
        translate['Go Back'] + '" class="imageAnchor">\n'
    html_str += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" alt="" src="' + \
        '/users/' + nickname + '/' + banner_file + '" /></a>\n'

    html_str += \
        '<form enctype="multipart/form-data" method="POST" ' + \
        'accept-charset="UTF-8" action="/users/' + nickname + \
        '/citationsdata">\n'
    html_str += '  <center>\n'
    html_str += translate['Choose newswire items ' +
                          'referenced in your article'] + '<br>'
    if blog_title is None:
        blog_title = ''
    html_str += \
        '    <input type="hidden" name="blogTitle" value="' + \
        blog_title + '">\n'
    if blog_content is None:
        blog_content = ''
    html_str += \
        '    <input type="hidden" name="blogContent" value="' + \
        blog_content + '">\n'
    # submit button
    html_str += \
        '    <input type="submit" name="submitCitations" value="' + \
        translate['Publish'] + '">\n'
    html_str += '  </center>\n'

    citations_separator = '#####'

    # list of newswire items
    if newswire:
        ctr = 0
        for date_str, item in newswire.items():
            item[0] = remove_html(item[0]).strip()
            if not item[0]:
                continue
            # remove any CDATA
            if 'CDATA[' in item[0]:
                item[0] = item[0].split('CDATA[')[1]
                if ']' in item[0]:
                    item[0] = item[0].split(']')[0]
            # should this checkbox be selected?
            selected_str = ''
            if date_str in citations_selected:
                selected_str = ' checked'

            published_date = \
                date_from_string_format(date_str, ["%Y-%m-%d %H:%M:%S%z"])
            date_shown = published_date.strftime("%Y-%m-%d %H:%M")

            title = remove_long_words(item[0], 16, []).replace('\n', '<br>')
            title = limit_repeated_words(title, 6)
            link = item[1]

            citation_value = \
                date_str + citations_separator + \
                title + citations_separator + \
                link
            html_str += \
                '<input type="checkbox" name="newswire' + str(ctr) + \
                '" value="' + citation_value + '"' + selected_str + '/>' + \
                '<a href="' + link + '"><cite>' + title + '</cite></a> '
            html_str += '<span class="newswireDate">' + \
                date_shown + '</span><br>\n'
            ctr += 1

    html_str += '</form>\n'
    return html_str + html_footer()


def html_newswire_mobile(base_dir: str, nickname: str,
                         domain: str, domain_full: str,
                         translate: {}, newswire: {},
                         positive_voting: bool,
                         timeline_path: str,
                         show_publish_as_icon: bool,
                         authorized: bool,
                         rss_icon_at_top: bool,
                         icons_as_buttons: bool,
                         default_timeline: str,
                         theme: str,
                         access_keys: {},
                         ua_str: str) -> str:
    """Shows the mobile version of the newswire right column
    """
    html_str = ''

    # the css filename
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    if nickname == 'news':
        editor = False
        moderator = False
    else:
        # is the user a moderator?
        moderator = is_moderator(base_dir, nickname)

        # is the user a site editor?
        editor = is_editor(base_dir, nickname)

    show_publish_button = editor

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    metadata = None
    if text_mode_browser(ua_str):
        metadata = '<meta http-equiv="refresh" content="1800" >\n'
    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title, metadata,
                                        preload_images)

    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    html_str += \
        '<a href="/users/' + nickname + '/' + default_timeline + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '" ' + \
        'class="imageAnchor">' + \
        '<img loading="lazy" decoding="async" class="timeline-banner" ' + \
        'alt="' + translate['Timeline banner image'] + '" ' + \
        'src="/users/' + nickname + '/' + banner_file + '" /></a>\n'

    html_str += '<div class="col-right-mobile">\n'

    html_str += '<center>' + \
        header_buttons_front_screen(translate, nickname,
                                    'newswire', authorized,
                                    icons_as_buttons) + '</center>'
    html_str += \
        get_right_column_content(base_dir, nickname, domain_full,
                                 translate,
                                 moderator, editor,
                                 newswire, positive_voting,
                                 False, timeline_path, show_publish_button,
                                 show_publish_as_icon, rss_icon_at_top, False,
                                 authorized, False, theme,
                                 default_timeline, access_keys)
    if editor and not newswire:
        html_str += '<br><br><br>\n'
        html_str += '<center>\n  '
        html_str += translate['Select the edit icon to add RSS feeds']
        html_str += '\n</center>\n'
    # end of col-right-mobile
    html_str += '</div\n>'

    html_str += html_footer()
    return html_str


def html_edit_newswire(translate: {}, base_dir: str, path: str,
                       domain: str, default_timeline: str, theme: str,
                       access_keys: {}, dogwhistles: {}) -> str:
    """Shows the edit newswire screen
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
    if not is_moderator(base_dir, nickname):
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
    edit_newswire_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # top banner
    edit_newswire_form += \
        '<header>' + \
        '<a href="/users/' + nickname + '/' + default_timeline + \
        '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '">\n'
    edit_newswire_form += \
        '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" src="' + \
        '/users/' + nickname + '/' + banner_file + '" ' + \
        'alt="" /></a>\n</header>'

    edit_newswire_form += \
        '<form enctype="multipart/form-data" method="POST" ' + \
        'accept-charset="UTF-8" action="' + path + '/newswiredata">\n'
    edit_newswire_form += \
        '  <div class="vertical-center">\n'
    edit_newswire_form += \
        '    <h1>' + translate['Edit newswire'] + '</h1>'
    edit_newswire_form += \
        '    <div class="containerSubmitNewPost">\n'
    edit_newswire_form += \
        '      <input type="submit" name="submitNewswire" value="' + \
        translate['Publish'] + '" ' + \
        'accesskey="' + access_keys['submitButton'] + '">\n'
    edit_newswire_form += \
        '    </div>\n'

    newswire_filename = data_dir(base_dir) + '/newswire.txt'
    newswire_str = ''
    if os.path.isfile(newswire_filename):
        try:
            with open(newswire_filename, 'r', encoding='utf-8') as fp_news:
                newswire_str = fp_news.read()
        except OSError:
            print('EX: html_edit_newswire unable to read ' +
                  newswire_filename)

    edit_newswire_form += \
        '<div class="container">'

    edit_newswire_form += \
        '  ' + \
        translate['Add RSS feed links below.'] + \
        '<br>'
    new_feed_str = translate['New feed URL']
    edit_newswire_form += \
        edit_text_field(None, 'newNewswireFeed', '', new_feed_str)
    edit_newswire_form += \
        '  <textarea id="message" name="editedNewswire" ' + \
        'style="height:80vh" spellcheck="false">' + \
        newswire_str + '</textarea>'

    filter_str = ''
    filter_filename = \
        data_dir(base_dir) + '/news@' + domain + '/filters.txt'
    if os.path.isfile(filter_filename):
        try:
            with open(filter_filename, 'r', encoding='utf-8') as fp_filter:
                filter_str = fp_filter.read()
        except OSError:
            print('EX: html_edit_newswire unable to read 2 ' +
                  filter_filename)

    edit_newswire_form += \
        '      <br><b><label class="labels">' + \
        translate['Filtered words'] + '</label></b>\n'
    edit_newswire_form += '      <br><label class="labels">' + \
        translate['One per line'] + '</label>'
    edit_newswire_form += '      <textarea id="message" ' + \
        'name="filteredWordsNewswire" style="height:50vh" ' + \
        'spellcheck="true">' + filter_str + '</textarea>\n'

    dogwhistle_str = ''
    for whistle, category in dogwhistles.items():
        if not category:
            continue
        dogwhistle_str += whistle + ' -> ' + category + '\n'

    edit_newswire_form += \
        '      <br><b><label class="labels">' + \
        translate['Dogwhistle words'] + '</label></b>\n'
    edit_newswire_form += '      <br><label class="labels">' + \
        translate['Content warnings will be added for the following'] + \
        ':</label>'
    edit_newswire_form += '      <textarea id="message" ' + \
        'name="dogwhistleWords" style="height:50vh" ' + \
        'spellcheck="true">' + dogwhistle_str + '</textarea>\n'

    hashtag_rules_str = ''
    hashtag_rules_filename = data_dir(base_dir) + '/hashtagrules.txt'
    if os.path.isfile(hashtag_rules_filename):
        try:
            with open(hashtag_rules_filename, 'r',
                      encoding='utf-8') as fp_rules:
                hashtag_rules_str = fp_rules.read()
        except OSError:
            print('EX: html_edit_newswire unable to read 3 ' +
                  hashtag_rules_filename)

    edit_newswire_form += \
        '      <br><b><label class="labels">' + \
        translate['News tagging rules'] + '</label></b>\n'
    edit_newswire_form += '      <br><label class="labels">' + \
        translate['One per line'] + '.</label>\n'
    edit_newswire_form += \
        '      <a href="' + \
        'https://gitlab.com/bashrc2/epicyon/-/raw/main/hashtagrules.txt' + \
        '">' + translate['See instructions'] + '</a>\n'
    edit_newswire_form += '      <textarea id="message" ' + \
        'name="hashtagRulesList" style="height:80vh" spellcheck="false">' + \
        hashtag_rules_str + '</textarea>\n'

    edit_newswire_form += \
        '</div>'

    edit_newswire_form += html_footer()
    return edit_newswire_form


def html_edit_news_post(translate: {}, base_dir: str, path: str,
                        domain: str, post_url: str,
                        system_language: str) -> str:
    """Edits a news post on the news/features timeline
    """
    if '/users/' not in path:
        return ''
    path_original = path

    nickname = get_nickname_from_actor(path)
    if not nickname:
        return ''

    # is the user an editor?
    if not is_editor(base_dir, nickname):
        return ''

    post_url = post_url.replace('/', '#')
    post_filename = locate_post(base_dir, nickname, domain, post_url)
    if not post_filename:
        return ''
    post_json_object = load_json(post_filename)
    if not post_json_object:
        return ''

    css_filename = base_dir + '/epicyon-links.css'
    if os.path.isfile(base_dir + '/links.css'):
        css_filename = base_dir + '/links.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    edit_news_post_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    edit_news_post_form += \
        '<form enctype="multipart/form-data" method="POST" ' + \
        'accept-charset="UTF-8" action="' + path + '/newseditdata">\n'
    edit_news_post_form += \
        '  <div class="vertical-center">\n'
    edit_news_post_form += \
        '    <h1>' + translate['Edit News Post'] + '</h1>'
    edit_news_post_form += \
        '    <div class="container">\n'
    edit_news_post_form += \
        '      <a href="' + path_original + '/tlnews">' + \
        '<button class="cancelbtn">' + translate['Go Back'] + '</button></a>\n'
    edit_news_post_form += \
        '      <input type="submit" name="submitEditedNewsPost" value="' + \
        translate['Publish'] + '">\n'
    edit_news_post_form += \
        '    </div>\n'

    edit_news_post_form += \
        '<div class="container">'

    edit_news_post_form += \
        '  <input type="hidden" name="newsPostUrl" value="' + \
        post_url + '">\n'

    news_post_title = post_json_object['object']['summary']
    edit_news_post_form += \
        '  <input type="text" name="newsPostTitle" value="' + \
        news_post_title + '"><br>\n'

    news_post_content = get_base_content_from_post(post_json_object,
                                                   system_language)
    edit_news_post_form += \
        '  <textarea id="message" name="editedNewsPost" ' + \
        'style="height:600px" spellcheck="true">' + \
        news_post_content + '</textarea>'

    edit_news_post_form += \
        '</div>'

    edit_news_post_form += html_footer()
    return edit_news_post_form
