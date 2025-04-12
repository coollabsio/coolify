__filename__ = "webapp_timeline.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
import time
from shutil import copyfile
from flags import is_editor
from flags import is_artist
from flags import is_float
from utils import data_dir
from utils import dangerous_markup
from utils import get_config_param
from utils import get_full_domain
from utils import remove_id_ending
from utils import acct_dir
from utils import local_actor_url
from utils import remove_eol
from utils import get_actor_from_post
from utils import text_mode_removals
from follow import follower_approval_active
from person import is_person_snoozed
from markdown import markdown_to_html
from webapp_utils import text_mode_browser
from webapp_utils import html_keyboard_navigation
from webapp_utils import html_hide_from_screen_reader
from webapp_utils import html_post_separator
from webapp_utils import get_banner_file
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import shares_timeline_json
from webapp_utils import html_highlight_label
from webapp_post import prepare_post_from_html_cache
from webapp_post import individual_post_as_html
from webapp_column_left import get_left_column_content
from webapp_column_right import get_right_column_content
from webapp_headerbuttons import header_buttons_timeline
from posts import is_moderator
from announce import mark_announce_as_seen
from announce import announce_seen
from announce import is_announce
from announce import is_self_announce
from question import is_html_question
from question import is_question


def _log_timeline_timing(enable_timing_log: bool, timeline_start_time,
                         box_name: str, debug_id: str) -> None:
    """Create a log of timings for performance tuning
    """
    if not enable_timing_log:
        return
    time_diff = int((time.time() - timeline_start_time) * 1000)
    if time_diff > 100:
        print('TIMELINE TIMING ' +
              box_name + ' ' + debug_id + ' = ' + str(time_diff))


def _get_help_for_timeline(base_dir: str, box_name: str) -> str:
    """Shows help text for the given timeline
    """
    # get the filename for help for this timeline
    help_filename = data_dir(base_dir) + '/help_' + box_name + '.md'
    if not os.path.isfile(help_filename):
        language = \
            get_config_param(base_dir, 'language')
        if not language:
            language = 'en'
        theme_name = \
            get_config_param(base_dir, 'theme')
        default_filename = None
        if theme_name:
            default_filename = \
                base_dir + '/theme/' + theme_name + '/welcome/' + \
                'help_' + box_name + '_' + language + '.md'
            if not os.path.isfile(default_filename):
                default_filename = None
        if not default_filename:
            default_filename = \
                base_dir + '/defaultwelcome/' + \
                'help_' + box_name + '_' + language + '.md'
        if not os.path.isfile(default_filename):
            default_filename = \
                base_dir + '/defaultwelcome/help_' + box_name + '_en.md'
        if os.path.isfile(default_filename):
            copyfile(default_filename, help_filename)

    # show help text
    if os.path.isfile(help_filename):
        instance_title = \
            get_config_param(base_dir, 'instanceTitle')
        if not instance_title:
            instance_title = 'Epicyon'
        help_text = ''
        try:
            with open(help_filename, 'r', encoding='utf-8') as fp_help:
                help_text = fp_help.read()
        except OSError:
            print('EX: _get_help_for_timeline unable to read ' + help_filename)
        if help_text:
            if dangerous_markup(help_text, False, []):
                return ''
            help_text = help_text.replace('INSTANCE', instance_title)
            return '<div class="container">\n' + \
                markdown_to_html(help_text) + '\n' + \
                '</div>\n'
    return ''


def _html_timeline_new_post(manually_approve_followers: bool,
                            box_name: str, icons_as_buttons: bool,
                            users_path: str, translate: {},
                            access_keys: {}) -> str:
    """Returns html for the new post button
    """
    new_post_button_str = ''
    if box_name == 'dm':
        if not icons_as_buttons:
            new_post_button_str += \
                '<a class="imageAnchor" href="' + users_path + \
                '/newdm?nodropdown" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<img loading="lazy" decoding="async" src="/' + \
                'icons/newpost.png" title="' + \
                translate['Create a new DM'] + \
                '" alt="| ' + translate['Create a new DM'] + \
                '" class="timelineicon"/></a>\n'
        else:
            new_post_button_str += \
                '<a href="' + users_path + \
                '/newdm?nodropdown" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<button class="button"><span>' + \
                translate['Post'] + ' </span></button></a>'
    elif box_name in ('tlblogs', 'tlnews', 'tlfeatures'):
        if not icons_as_buttons:
            new_post_button_str += \
                '<a class="imageAnchor" href="' + users_path + \
                '/newblog" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<img loading="lazy" decoding="async" src="/' + \
                'icons/newpost.png" title="' + \
                translate['Create a new post'] + '" alt="| ' + \
                translate['Create a new post'] + \
                '" class="timelineicon"/></a>\n'
        else:
            new_post_button_str += \
                '<a href="' + users_path + \
                '/newblog" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<button class="button"><span>' + \
                translate['Post'] + '</span></button></a>'
    elif box_name == 'tlshares':
        if not icons_as_buttons:
            new_post_button_str += \
                '<a class="imageAnchor" href="' + users_path + \
                '/newshare?nodropdown" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<img loading="lazy" decoding="async" src="/' + \
                'icons/newpost.png" title="' + \
                translate['Create a new shared item'] + '" alt="| ' + \
                translate['Create a new shared item'] + \
                '" class="timelineicon"/></a>\n'
        else:
            new_post_button_str += \
                '<a href="' + users_path + \
                '/newshare?nodropdown" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<button class="button"><span>' + \
                translate['Post'] + '</span></button></a>'
    elif box_name == 'tlwanted':
        if not icons_as_buttons:
            new_post_button_str += \
                '<a class="imageAnchor" href="' + users_path + \
                '/newwanted?nodropdown" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<img loading="lazy" decoding="async" src="/' + \
                'icons/newpost.png" title="' + \
                translate['Create a new wanted item'] + '" alt="| ' + \
                translate['Create a new wanted item'] + \
                '" class="timelineicon"/></a>\n'
        else:
            new_post_button_str += \
                '<a href="' + users_path + \
                '/newwanted?nodropdown" tabindex="3" accesskey="' + \
                access_keys['menuNewPost'] + '">' + \
                '<button class="button"><span>' + \
                translate['Post'] + '</span></button></a>'
    else:
        if not manually_approve_followers:
            if not icons_as_buttons:
                new_post_button_str += \
                    '<a class="imageAnchor" href="' + users_path + \
                    '/newpost" tabindex="3">' + \
                    '<img loading="lazy" decoding="async" src="/' + \
                    'icons/newpost.png" title="' + \
                    translate['Create a new post'] + '" alt="| ' + \
                    translate['Create a new post'] + \
                    '" class="timelineicon"/></a>\n'
            else:
                new_post_button_str += \
                    '<a href="' + users_path + '/newpost" tabindex="3">' + \
                    '<button class="button"><span>' + \
                    translate['Post'] + '</span></button></a>'
        else:
            if not icons_as_buttons:
                new_post_button_str += \
                    '<a class="imageAnchor" href="' + users_path + \
                    '/newfollowers" tabindex="3" accesskey="' + \
                    access_keys['menuNewPost'] + '">' + \
                    '<img loading="lazy" decoding="async" src="/' + \
                    'icons/newpost.png" title="' + \
                    translate['Create a new post'] + \
                    '" alt="| ' + translate['Create a new post'] + \
                    '" class="timelineicon"/></a>\n'
            else:
                new_post_button_str += \
                    '<a href="' + users_path + \
                    '/newfollowers" tabindex="3" accesskey="' + \
                    access_keys['menuNewPost'] + '">' + \
                    '<button class="button"><span>' + \
                    translate['Post'] + '</span></button></a>'
    return new_post_button_str


def _html_timeline_moderation_buttons(moderator: bool, box_name: str,
                                      nickname: str,
                                      moderation_action_str: str,
                                      translate: {}) -> str:
    """Returns html for the moderation screen buttons
    """
    tl_str = ''
    if moderator and box_name == 'moderation':
        tl_str += \
            '<form id="modtimeline" method="POST" action="/users/' + \
            nickname + '/moderationaction">'
        tl_str += '<div class="container">\n'
        idx = 'Nickname or URL. Block using *@domain or nickname@domain'
        tl_str += \
            '    <b>' + translate[idx] + '</b><br>\n'
        if moderation_action_str:
            tl_str += '    <input type="text" ' + \
                'name="moderationAction" value="' + \
                moderation_action_str + '" autofocus><br>\n'
        else:
            tl_str += '    <input type="text" ' + \
                'name="moderationAction" value="" autofocus><br>\n'

        tl_str += \
            '    <input type="submit" title="' + \
            translate['Information about current blocks/suspensions'] + \
            '" alt="' + \
            translate['Information about current blocks/suspensions'] + \
            ' | " ' + \
            'name="submitInfo" value="' + translate['Info'] + '">\n'
        tl_str += \
            '    <input type="submit" title="' + \
            translate['Remove the above item'] + '" ' + \
            'alt="' + translate['Remove the above item'] + ' | " ' + \
            'name="submitRemove" value="' + \
            translate['Remove'] + '">\n'

        tl_str += \
            '    <input type="submit" title="' + \
            translate['Suspend the above account nickname'] + '" ' + \
            'alt="' + \
            translate['Suspend the above account nickname'] + ' | " ' + \
            'name="submitSuspend" value="' + translate['Suspend'] + '">\n'
        tl_str += \
            '    <input type="submit" title="' + \
            translate['Remove a suspension for an account nickname'] + '" ' + \
            'alt="' + \
            translate['Remove a suspension for an account nickname'] + \
            ' | " ' + \
            'name="submitUnsuspend" value="' + \
            translate['Unsuspend'] + '">\n'

        tl_str += \
            '    <input type="submit" title="' + \
            translate['Block an account on another instance'] + '" ' + \
            'alt="' + \
            translate['Block an account on another instance'] + ' | " ' + \
            'name="submitBlock" value="' + translate['Block'] + '">\n'
        tl_str += \
            '    <input type="submit" title="' + \
            translate['Unblock an account on another instance'] + '" ' + \
            'alt="' + \
            translate['Unblock an account on another instance'] + ' | " ' + \
            'name="submitUnblock" value="' + translate['Unblock'] + '">\n'

        tl_str += \
            '    <input type="submit" title="' + \
            translate['Filter out words'] + '" ' + \
            'alt="' + \
            translate['Filter out words'] + ' | " ' + \
            'name="submitFilter" value="' + translate['Filter'] + '">\n'
        tl_str += \
            '    <input type="submit" title="' + \
            translate['Unfilter words'] + '" ' + \
            'alt="' + \
            translate['Unfilter words'] + ' | " ' + \
            'name="submitUnfilter" value="' + translate['Unfilter'] + '">\n'
        tl_str += \
            '    <input type="submit" title="' + \
            translate['Clear Cache'] + '" ' + \
            'alt="' + \
            translate['Clear Cache'] + ' | " ' + \
            'name="submitClearCache" value="' + \
            translate['Clear Cache'] + '">\n'

        tl_str += '</div>\n</form>\n'
    return tl_str


def _html_timeline_keyboard(moderator: bool, text_mode_banner: str,
                            users_path: str,
                            nickname: str, new_calendar_event: bool,
                            new_dm: bool, new_reply: bool,
                            new_share: bool, new_wanted: bool,
                            follow_approvals: bool,
                            access_keys: {}, translate: {}) -> str:
    """Returns html for timeline keyboard navigation
    """
    calendar_str = translate['Calendar']
    if new_calendar_event:
        calendar_str = '<strong>' + calendar_str + '</strong>'
    dm_str = translate['DM']
    if new_dm:
        dm_str = '<strong>' + dm_str + '</strong>'
    replies_str = translate['Replies']
    if new_reply:
        replies_str = '<strong>' + replies_str + '</strong>'
    shares_str = translate['Shares']
    if new_share:
        shares_str = '<strong>' + shares_str + '</strong>'
    wanted_str = translate['Wanted']
    if new_wanted:
        wanted_str = '<strong>' + wanted_str + '</strong>'
    menu_profile = \
        html_hide_from_screen_reader('👤') + ' ' + \
        translate['Switch to profile view']
    menu_inbox = \
        html_hide_from_screen_reader('📥') + ' ' + translate['Inbox']
    menu_outbox = \
        html_hide_from_screen_reader('📤') + ' ' + translate['Sent']
    menu_search = \
        html_hide_from_screen_reader('🔍') + ' ' + \
        translate['Search and follow']
    menu_calendar = \
        html_hide_from_screen_reader('📅') + ' ' + calendar_str
    menu_dm = \
        html_hide_from_screen_reader('📩') + ' ' + dm_str
    menu_replies = \
        html_hide_from_screen_reader('📨') + ' ' + replies_str
    menu_bookmarks = \
        html_hide_from_screen_reader('🔖') + ' ' + translate['Bookmarks']
    menu_shares = \
        html_hide_from_screen_reader('🤝') + ' ' + shares_str
    menu_wanted = \
        html_hide_from_screen_reader('⛱') + ' ' + wanted_str
    menu_blogs = \
        html_hide_from_screen_reader('📝') + ' ' + translate['Blogs']
    menu_newswire = \
        html_hide_from_screen_reader('📰') + ' ' + translate['Newswire']
    menu_links = \
        html_hide_from_screen_reader('🔗') + ' ' + translate['Links']
    menu_new_post = \
        html_hide_from_screen_reader('➕') + ' ' + \
        translate['Create a new post']
    menu_moderation = \
        html_hide_from_screen_reader('⚡️') + ' ' + translate['Mod']
    nav_links = {
        menu_profile: '/users/' + nickname,
        menu_inbox: users_path + '/inbox#timelineposts',
        menu_search: users_path + '/search',
        menu_new_post: users_path + '/newpost',
        menu_calendar: users_path + '/calendar',
        menu_dm: users_path + '/dm#timelineposts',
        menu_replies: users_path + '/tlreplies#timelineposts',
        menu_outbox: users_path + '/outbox#timelineposts',
        menu_bookmarks: users_path + '/tlbookmarks#timelineposts',
        menu_shares: users_path + '/tlshares#timelineposts',
        menu_wanted: users_path + '/tlwanted#timelineposts',
        menu_blogs: users_path + '/tlblogs#timelineposts',
        menu_newswire: users_path + '/newswiremobile',
        menu_links: users_path + '/linksmobile'
    }
    nav_access_keys = {}
    for variable_name, key in access_keys.items():
        if not locals().get(variable_name):
            continue
        nav_access_keys[locals()[variable_name]] = key
    if moderator:
        nav_links[menu_moderation] = users_path + '/moderation#modtimeline'
    return html_keyboard_navigation(text_mode_banner, nav_links,
                                    nav_access_keys,
                                    None, users_path, translate,
                                    follow_approvals)


def _html_timeline_end(base_dir: str, nickname: str, domain_full: str,
                       translate: {}, moderator: bool, editor: bool,
                       newswire: {}, positive_voting: bool,
                       show_publish_as_icon: bool,
                       rss_icon_at_top: bool, publish_button_at_top: bool,
                       authorized: bool, theme: str,
                       default_timeline: str, access_keys: {},
                       box_name: str,
                       enable_timing_log: bool, timeline_start_time,
                       ua_str: str) -> str:
    """Ending of the timeline, containing the right column
    """
    # end of timeline-posts
    tl_str = '  </div>\n'

    # end of column-center
    tl_str += '  </td>\n'

    # right column
    right_column_str = ''
    if not text_mode_browser(ua_str):
        right_column_str = \
            get_right_column_content(base_dir, nickname, domain_full,
                                     translate,
                                     moderator, editor,
                                     newswire, positive_voting,
                                     False, None, True,
                                     show_publish_as_icon,
                                     rss_icon_at_top,
                                     publish_button_at_top,
                                     authorized, True, theme,
                                     default_timeline, access_keys)
    tl_str += '  <td valign="top" class="col-right" ' + \
        'id="newswire" tabindex="-1">\n' + \
        '  <aside>\n' + \
        right_column_str + \
        '  </aside>\n' + \
        '  </td>\n' + \
        '  </tr>\n'

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '9')

    tl_str += '  </tbody>\n'
    tl_str += '</table>\n'
    tl_str += '</main>\n'
    return tl_str


def page_number_buttons(users_path: str, box_name: str,
                        page_number: int, header: str) -> str:
    """Shows selactable page numbers at the bottom of the screen
    """
    pages_width = 2
    min_page_number = page_number - pages_width
    min_page_number = max(min_page_number, 1)
    max_page_number = min_page_number + 1 + (pages_width * 2)
    num_str = ''
    for page in range(min_page_number, max_page_number):
        if num_str:
            separator_str = \
                '<label class="pageslistDash">────</label>'
            num_str += html_hide_from_screen_reader(separator_str)
        aria_page_str = ''
        page_str = ' ' + str(page) + ' '
        curr_page_str = ''
        if page == page_number:
            page_str = '[<mark>' + str(page) + '</mark>]'
            aria_page_str = ' aria-current="true"'
            curr_page_str = 'Current Page, '
        num_str += \
            '<a href="' + users_path + '/' + box_name + '?page=' + \
            str(page) + '#' + header + '" class="pageslist" ' + \
            'aria-label="' + curr_page_str + 'Page ' + str(page) + \
            '"' + aria_page_str + ' tabindex="11">' + page_str + '</a>'
    return '<center>\n' + \
        '  <nav role="navigation" aria-label="Pagination Navigation">\n' + \
        '    ' + num_str + '\n' + \
        '  </nav>\n' + \
        '</center>\n'


def html_timeline(default_timeline: str,
                  recent_posts_cache: {}, max_recent_posts: int,
                  translate: {}, page_number: int,
                  items_per_page: int, session, base_dir: str,
                  cached_webfingers: {}, person_cache: {},
                  nickname: str, domain: str, port: int, timeline_json: {},
                  box_name: str, allow_deletion: bool,
                  http_prefix: str, project_version: str,
                  manually_approve_followers: bool,
                  minimal: bool,
                  yt_replace_domain: str,
                  twitter_replacement_domain: str,
                  show_published_date_only: bool,
                  newswire: {}, moderator: bool,
                  editor: bool, artist: bool,
                  positive_voting: bool,
                  show_publish_as_icon: bool,
                  full_width_tl_button_header: bool,
                  icons_as_buttons: bool,
                  rss_icon_at_top: bool,
                  publish_button_at_top: bool,
                  authorized: bool,
                  moderation_action_str: str,
                  theme: str,
                  peertube_instances: [],
                  allow_local_network_access: bool,
                  text_mode_banner: str,
                  access_keys: {}, system_language: str,
                  max_like_count: int,
                  shared_items_federated_domains: [],
                  signing_priv_key_pem: str,
                  cw_lists: {}, lists_enabled: str,
                  timezone: str, bold_reading: bool,
                  dogwhistles: {}, ua_str: str,
                  min_images_for_accounts: [],
                  reverse_sequence: bool,
                  last_post_id: str,
                  buy_sites: {},
                  auto_cw_cache: {},
                  show_announces: bool,
                  known_epicyon_instances: [],
                  mitm_servers: [],
                  instance_software: {}) -> str:
    """Show the timeline as html
    """
    enable_timing_log = False

    timeline_start_time = time.time()

    account_dir = acct_dir(base_dir, nickname, domain)

    # should the calendar icon be highlighted?
    new_calendar_event = False
    calendar_image = 'calendar.png'
    calendar_path = '/calendar'
    calendar_file = account_dir + '/.newCalendar'
    if os.path.isfile(calendar_file):
        new_calendar_event = True
        calendar_image = 'calendar_notify.png'
        try:
            with open(calendar_file, 'r', encoding='utf-8') as fp_cal:
                calendar_path = fp_cal.read().replace('##sent##', '')
                calendar_path = remove_eol(calendar_path)
                if '/calendar' not in calendar_path:
                    calendar_path = '/calendar'
        except OSError:
            print('EX: html_timeline unable to read ' + calendar_file)

    # should the DM button be highlighted?
    new_dm = False
    dm_file = account_dir + '/.newDM'
    if os.path.isfile(dm_file):
        new_dm = True
        if box_name == 'dm':
            try:
                os.remove(dm_file)
            except OSError:
                print('EX: html_timeline unable to delete ' + dm_file)

    # should the Replies button be highlighted?
    new_reply = False
    reply_file = account_dir + '/.newReply'
    if os.path.isfile(reply_file):
        new_reply = True
        if box_name == 'tlreplies':
            try:
                os.remove(reply_file)
            except OSError:
                print('EX: html_timeline unable to delete ' + reply_file)

    # should the Shares button be highlighted?
    new_share = False
    new_share_file = account_dir + '/.newShare'
    if os.path.isfile(new_share_file):
        new_share = True
        if box_name == 'tlshares':
            try:
                os.remove(new_share_file)
            except OSError:
                print('EX: html_timeline unable to delete ' + new_share_file)

    # should the Wanted button be highlighted?
    new_wanted = False
    new_wanted_file = account_dir + '/.newWanted'
    if os.path.isfile(new_wanted_file):
        new_wanted = True
        if box_name == 'tlwanted':
            try:
                os.remove(new_wanted_file)
            except OSError:
                print('EX: html_timeline unable to delete ' + new_wanted_file)

    # should the Moderation/reports button be highlighted?
    new_report = False
    new_report_file = account_dir + '/.newReport'
    if os.path.isfile(new_report_file):
        new_report = True
        if box_name == 'moderation':
            try:
                os.remove(new_report_file)
            except OSError:
                print('EX: html_timeline unable to delete ' + new_report_file)

    # show polls/votes?
    show_vote_posts = True
    show_vote_file = account_dir + '/.noVotes'
    if os.path.isfile(show_vote_file):
        show_vote_posts = False

    separator_str = ''
    if box_name != 'tlmedia':
        separator_str = html_post_separator(base_dir, None)

    # the css filename
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '1')

    # is the user a moderator?
    if not moderator:
        moderator = is_moderator(base_dir, nickname)

    # is the user a site editor?
    if not editor:
        editor = is_editor(base_dir, nickname)

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '2')

    # the appearance of buttons - highlighted or not
    inbox_button = 'button'
    blogs_button = 'button'
    features_button = 'button'
    dm_button = 'button'
    if new_dm:
        dm_button = 'buttonhighlighted'
    replies_button = 'button'
    if new_reply:
        replies_button = 'buttonhighlighted'
    media_button = 'button'
    bookmarks_button = 'button'
#    eventsButton = 'button'
    sent_button = 'button'
    shares_button = 'button'
    if new_share:
        shares_button = 'buttonhighlighted'
    wanted_button = 'button'
    if new_wanted:
        wanted_button = 'buttonhighlighted'
    moderation_button = 'button'
    if new_report:
        moderation_button = 'buttonhighlighted'
    if box_name == 'inbox':
        inbox_button = 'buttonselected'
    elif box_name == 'tlblogs':
        blogs_button = 'buttonselected'
    elif box_name == 'tlfeatures':
        features_button = 'buttonselected'
    elif box_name == 'dm':
        dm_button = 'buttonselected'
        if new_dm:
            dm_button = 'buttonselectedhighlighted'
    elif box_name == 'tlreplies':
        replies_button = 'buttonselected'
        if new_reply:
            replies_button = 'buttonselectedhighlighted'
    elif box_name == 'tlmedia':
        media_button = 'buttonselected'
    elif box_name == 'outbox':
        sent_button = 'buttonselected'
    elif box_name == 'moderation':
        moderation_button = 'buttonselected'
        if new_report:
            moderation_button = 'buttonselectedhighlighted'
    elif box_name == 'tlshares':
        shares_button = 'buttonselected'
        if new_share:
            shares_button = 'buttonselectedhighlighted'
    elif box_name == 'tlwanted':
        wanted_button = 'buttonselected'
        if new_wanted:
            wanted_button = 'buttonselectedhighlighted'
    elif box_name in ('tlbookmarks', 'bookmarks'):
        bookmarks_button = 'buttonselected'

    # get the full domain, including any port number
    full_domain = get_full_domain(domain, port)

    users_path = '/users/' + nickname
    actor = http_prefix + '://' + full_domain + users_path

    show_individual_post_icons = True

    # show an icon for new follow approvals
    follow_approvals = ''
    follow_requests_filename = \
        acct_dir(base_dir, nickname, domain) + '/followrequests.txt'
    if os.path.isfile(follow_requests_filename):
        try:
            with open(follow_requests_filename, 'r',
                      encoding='utf-8') as fp_foll:
                for line in fp_foll:
                    if len(line) > 0:
                        # show follow approvals icon
                        follow_approvals = \
                            '<a href="' + users_path + \
                            '/followers#buttonheader" ' + \
                            'accesskey="' + \
                            access_keys['followButton'] + '">' + \
                            '<img loading="lazy" decoding="async" ' + \
                            'class="timelineicon" alt="' + \
                            translate['Approve follow requests'] + \
                            '" title="' + \
                            translate['Approve follow requests'] + \
                            '" src="/icons/person.png"/></a>\n'
                        break
        except OSError:
            print('EX: html_timeline unable to read ' +
                  follow_requests_filename)

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '3')

    # moderation / reports button
    moderation_button_str = ''
    if moderator and not minimal:
        moderation_button_str = \
            '<a href="' + users_path + '/moderation"'
        if box_name == 'moderation':
            moderation_button_str += ' aria-current="location"'
        moderation_button_str += \
            '><button class="' + \
            moderation_button + '" tabindex="2"><span>' + \
            html_highlight_label(translate['Mod'], new_report) + \
            ' </span></button></a>'

    # shares, bookmarks and events buttons
    shares_button_str = ''
    wanted_button_str = ''
    bookmarks_button_str = ''
    events_button_str = ''
    if not minimal:
        shares_button_str = \
            '<a href="' + users_path + '/tlshares"'
        if box_name == 'tlshares':
            shares_button_str += ' aria-current="location"'
        shares_button_str += \
            '><button class="' + shares_button + '" tabindex="2"><span>' + \
            html_highlight_label(translate['Shares'], new_share) + \
            '</span></button></a>'

        wanted_button_str = \
            '<a href="' + users_path + '/tlwanted"><button class="' + \
            wanted_button + '" tabindex="2"'
        if box_name == 'tlwanted':
            wanted_button_str += ' aria-current="location"'
        wanted_button_str += \
            '><span>' + \
            html_highlight_label(translate['Wanted'], new_wanted) + \
            '</span></button></a>'

        bookmarks_button_str = \
            '<a href="' + users_path + '/tlbookmarks"'
        if box_name == 'tlbookmarks':
            bookmarks_button_str += ' aria-current="location"'
        bookmarks_button_str += \
            '><button class="' + \
            bookmarks_button + '" tabindex="2">' + \
            '<span>' + translate['Bookmarks'] + '</span></button></a>'

    # filename of the banner shown at the top
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    banner_path = users_path + '/' + banner_file

    # these images are pre-loaded to prevent the web page from
    # jumping around when rendering
    preload_images = [
        banner_path,
        '/icons/showhide.png',
        '/icons/repeat_hide.png',
        '/icons/repeat_show.png',
        '/icons/calendar.png',
        '/icons/search.png',
        '/icons/newpost.png',
        '/icons/mute.png',
        '/icons/unmute.png',
        '/icons/reaction.png',
        '/icons/bookmark_inactive.png',
        '/icons/bookmark.png',
        '/icons/like.png',
        '/icons/like_inactive.png',
        '/icons/repeat_inactive.png',
        '/icons/repeat.png',
        '/icons/reply.png',
        '/icons/theme.png',
        '/icons/logorss.png',
        '/icons/edit.png',
        '/icons/publish.png',
        '/icons/categoriesrss.png'
    ]

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    tl_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '4')

    # if this is a news instance and we are viewing the news timeline
    news_header = False
    if default_timeline == 'tlfeatures' and box_name == 'tlfeatures':
        news_header = True

    header_icons_str = ''
    # start of headericons div
    if not news_header:
        if not icons_as_buttons:
            header_icons_str = '<div class="headericons">'

    # what screen to go to when a new post is created
    new_post_button_str = \
        _html_timeline_new_post(manually_approve_followers, box_name,
                                icons_as_buttons, users_path, translate,
                                access_keys)

    # keyboard navigation
    tl_str += \
        _html_timeline_keyboard(moderator, text_mode_banner,
                                users_path, nickname,
                                new_calendar_event, new_dm, new_reply,
                                new_share, new_wanted,
                                follow_approvals, access_keys, translate)

    # banner and row of buttons
    tl_str += \
        '<header>\n' + \
        '  <a href="/users/' + nickname + '" title="' + \
        translate['Switch to profile view'] + '" alt="' + \
        translate['Switch to profile view'] + '" ' + \
        'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
        access_keys['menuProfile'] + '">\n'
    tl_str += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" alt="" ' + \
        'src="' + banner_path + '" /></a>\n' + \
        '</header>\n'

    is_text_browser = text_mode_browser(ua_str)
    if full_width_tl_button_header:
        tl_str += \
            header_buttons_timeline(default_timeline, box_name, page_number,
                                    translate, users_path, media_button,
                                    blogs_button, features_button,
                                    inbox_button,
                                    dm_button, new_dm, replies_button,
                                    new_reply, minimal, sent_button,
                                    shares_button_str, wanted_button_str,
                                    bookmarks_button_str,
                                    events_button_str, moderation_button_str,
                                    header_icons_str,
                                    new_post_button_str, base_dir, nickname,
                                    domain, timeline_start_time,
                                    new_calendar_event, calendar_path,
                                    calendar_image, follow_approvals,
                                    icons_as_buttons, access_keys,
                                    is_text_browser, show_announces)

    # start the timeline
    tl_str += \
        '<main>\n' + \
        '<table class="timeline">\n' + \
        '  <colgroup>\n' + \
        '    <col span="1" class="column-left">\n' + \
        '    <col span="1" class="column-center">\n' + \
        '    <col span="1" class="column-right">\n' + \
        '  </colgroup>\n' + \
        '  <tbody>\n' + \
        '    <tr>\n'

    domain_full = get_full_domain(domain, port)

    # left column
    left_column_str = ''
    if not is_text_browser:
        left_column_str = \
            get_left_column_content(base_dir, nickname, domain_full,
                                    http_prefix, translate,
                                    editor, artist, False, None,
                                    rss_icon_at_top,
                                    True, False, theme, access_keys,
                                    shared_items_federated_domains,
                                    known_epicyon_instances)
    tl_str += '  <td valign="top" class="col-left" ' + \
        'id="links" tabindex="-1">\n' + \
        '  <aside>\n' + \
        left_column_str + \
        '  </aside>\n' + \
        '  </td>\n'

    # center column containing posts
    tl_str += '  <td valign="top" class="col-center" tabindex="-1">\n'

    if not full_width_tl_button_header:
        tl_str += \
            header_buttons_timeline(default_timeline, box_name, page_number,
                                    translate, users_path, media_button,
                                    blogs_button, features_button,
                                    inbox_button,
                                    dm_button, new_dm, replies_button,
                                    new_reply, minimal, sent_button,
                                    shares_button_str, wanted_button_str,
                                    bookmarks_button_str,
                                    events_button_str, moderation_button_str,
                                    header_icons_str,
                                    new_post_button_str, base_dir, nickname,
                                    domain, timeline_start_time,
                                    new_calendar_event, calendar_path,
                                    calendar_image, follow_approvals,
                                    icons_as_buttons, access_keys,
                                    is_text_browser, show_announces)

    tl_str += \
        '  <div id="timelineposts" class="timeline-posts" ' + \
        'itemscope itemtype="http://schema.org/Collection">\n'

    # second row of buttons for moderator actions
    tl_str += \
        _html_timeline_moderation_buttons(moderator, box_name, nickname,
                                          moderation_action_str, translate)

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '6')

    if box_name == 'tlshares':
        max_shares_per_account = items_per_page

        tl_str += \
            _html_shares_timeline(translate, page_number, items_per_page,
                                  base_dir, actor, nickname, domain, port,
                                  max_shares_per_account, http_prefix,
                                  shared_items_federated_domains,
                                  'shares')

        # if using a text mode browser then don't show SHOW MORE because there
        # is no way to hide/expand sections.
        # Also replace MITM text with an eye icon
        if is_text_browser:
            tl_str = text_mode_removals(tl_str, translate)

        tl_str += \
            _html_timeline_end(base_dir, nickname, domain_full,
                               translate,
                               moderator, editor,
                               newswire, positive_voting,
                               show_publish_as_icon,
                               rss_icon_at_top, publish_button_at_top,
                               authorized, theme,
                               default_timeline, access_keys,
                               box_name,
                               enable_timing_log, timeline_start_time,
                               ua_str) + html_footer()

        return tl_str
    elif box_name == 'tlwanted':
        max_shares_per_account = items_per_page

        tl_str += \
            _html_shares_timeline(translate, page_number, items_per_page,
                                  base_dir, actor, nickname, domain, port,
                                  max_shares_per_account, http_prefix,
                                  shared_items_federated_domains,
                                  'wanted')

        # if using a text mode browser then don't show SHOW MORE because there
        # is no way to hide/expand sections.
        # Also replace MITM text with an eye icon
        if is_text_browser:
            tl_str = text_mode_removals(tl_str, translate)

        tl_str += \
            _html_timeline_end(base_dir, nickname, domain_full,
                               translate,
                               moderator, editor,
                               newswire, positive_voting,
                               show_publish_as_icon,
                               rss_icon_at_top, publish_button_at_top,
                               authorized, theme,
                               default_timeline, access_keys,
                               box_name,
                               enable_timing_log, timeline_start_time,
                               ua_str) + html_footer()
        return tl_str

    _log_timeline_timing(enable_timing_log, timeline_start_time, box_name, '7')

    # separator between posts which only appears in shell browsers
    # such as Lynx and is not read by screen readers
    if box_name != 'tlmedia':
        text_mode_separator = \
            '<div class="transparent"><hr></div>'
    else:
        text_mode_separator = ''

    # page up arrow
    if page_number > 1:
        tl_str += text_mode_separator
        tl_str += '<br>' + \
            page_number_buttons(users_path, box_name, page_number,
                                'timelineposts')
        first_post_str = ''
        if page_number > 2:
            if last_post_id:
                first_post_str = ';firstpost=' + last_post_id
        tl_str += \
            '  <center>\n' + \
            '    <a href="' + users_path + '/' + box_name + \
            '?page=' + str(page_number - 1) + first_post_str + \
            '#timelineposts" accesskey="' + access_keys['Page up'] + '" ' + \
            'class="imageAnchor" tabindex="9">' + \
            '<img loading="lazy" decoding="async" class="pageicon" src="/' + \
            'icons/pageup.png" title="' + \
            translate['Page up'] + '" alt="' + \
            translate['Page up'] + '"></a>\n' + \
            '  </center>\n'

    # show the posts
    item_ctr = 0
    if timeline_json:
        if 'orderedItems' not in timeline_json:
            print('ERROR: no orderedItems in timeline for '
                  + box_name + ' ' + str(timeline_json))
            return ''

    use_cache_only = False
    if box_name == 'inbox':
        use_cache_only = True

    first_post_id = ''
    last_post_id = ''

    last_item_str = ''
    if timeline_json:
        # if this is the media timeline then add an extra gallery container
        if box_name == 'tlmedia':
            if page_number > 1:
                tl_str += '<br>'
            tl_str += '<div class="galleryContainer">\n'

        minimize_all_images = False
        if nickname in min_images_for_accounts:
            minimize_all_images = True

        no_seen_posts_filename = account_dir + '/.noSeenPosts'
        no_seen_posts = False
        if os.path.isfile(no_seen_posts_filename):
            no_seen_posts = True

        # show each post in the timeline
        tl_items_str = ''
        for item in timeline_json['orderedItems']:
            if item['type'] in ('Create', 'Announce'):
                # is the actor who sent this post snoozed?
                if is_person_snoozed(base_dir, nickname, domain,
                                     item['actor']):
                    continue
                if is_announce(item):
                    if not show_announces:
                        continue
                    if is_self_announce(item):
                        continue
                    if no_seen_posts:
                        if announce_seen(base_dir, nickname, domain, item):
                            continue
                        mark_announce_as_seen(base_dir, nickname, domain, item)
                # is this a poll/vote/question?
                if not show_vote_posts:
                    if is_question(item):
                        continue
                # is the post in the memory cache of recent ones?
                curr_tl_str = None
                if box_name != 'tlmedia' and recent_posts_cache.get('html'):
                    post_id = remove_id_ending(item['id']).replace('/', '#')
                    if recent_posts_cache['html'].get(post_id):
                        if not first_post_id:
                            first_post_id = post_id
                        last_post_id = post_id
                        curr_tl_str = recent_posts_cache['html'][post_id]
                        curr_tl_str = \
                            prepare_post_from_html_cache(nickname,
                                                         curr_tl_str,
                                                         box_name,
                                                         page_number,
                                                         first_post_id,
                                                         ua_str,
                                                         translate)
                        _log_timeline_timing(enable_timing_log,
                                             timeline_start_time,
                                             box_name, '10')

                if not curr_tl_str:
                    _log_timeline_timing(enable_timing_log,
                                         timeline_start_time,
                                         box_name, '11')

                    mitm = False
                    if item.get('mitm'):
                        mitm = True
                    # read the post from disk
                    curr_tl_str = \
                        individual_post_as_html(signing_priv_key_pem,
                                                False, recent_posts_cache,
                                                max_recent_posts,
                                                translate, page_number,
                                                base_dir, session,
                                                cached_webfingers,
                                                person_cache,
                                                nickname, domain, port,
                                                item, None, True,
                                                allow_deletion,
                                                http_prefix, project_version,
                                                box_name,
                                                yt_replace_domain,
                                                twitter_replacement_domain,
                                                show_published_date_only,
                                                peertube_instances,
                                                allow_local_network_access,
                                                theme, system_language,
                                                max_like_count,
                                                box_name != 'dm',
                                                show_individual_post_icons,
                                                manually_approve_followers,
                                                False, True, use_cache_only,
                                                cw_lists, lists_enabled,
                                                timezone, mitm,
                                                bold_reading, dogwhistles,
                                                minimize_all_images,
                                                first_post_id, buy_sites,
                                                auto_cw_cache,
                                                mitm_servers,
                                                instance_software)
                    _log_timeline_timing(enable_timing_log,
                                         timeline_start_time, box_name, '12')

                if curr_tl_str:
                    if curr_tl_str not in tl_items_str:
                        # is this a poll/vote/question?
                        if not show_vote_posts:
                            if is_html_question(curr_tl_str):
                                continue
                        last_item_str = text_mode_separator + curr_tl_str
                        last_post_id = \
                            remove_id_ending(item['id']).replace('/', '#')
                        if not first_post_id:
                            first_post_id = last_post_id
                        item_ctr += 1
                        if not reverse_sequence:
                            tl_items_str += last_item_str
                            if separator_str:
                                tl_items_str += separator_str
                        else:
                            tl_items_str = last_item_str + tl_items_str
                            if separator_str:
                                tl_items_str = \
                                    last_item_str + \
                                    separator_str + tl_items_str
        tl_str += tl_items_str

        if box_name == 'tlmedia':
            tl_str += '</div>\n'

    if item_ctr < 3:
        print('Items added to html timeline ' + box_name + ': ' +
              str(item_ctr) + ' ' + str(timeline_json['orderedItems']))

    # page down arrow
    if item_ctr > 0:
        # if showing the page down icon then remove the last item so that
        # firstpost does not overlap on the next timeline
        if last_item_str and first_post_id != last_post_id:
            if item_ctr > items_per_page / 2:
                tl_str = tl_str.replace(last_item_str, '')
        tl_str += text_mode_separator
        first_post = ''
        if last_post_id:
            first_post = ';firstpost=' + last_post_id.replace('#', '--')
        last_post = ''
        if first_post_id:
            last_post = ';lastpost=' + first_post_id.replace('#', '--')
        tl_str += \
            '      <br>\n' + \
            '      <center>\n' + \
            '        <a href="' + users_path + '/' + box_name + '?page=' + \
            str(page_number + 1) + last_post + first_post + \
            '#timelineposts" accesskey="' + access_keys['Page down'] + '" ' + \
            'class="imageAnchor" tabindex="9">' + \
            '<img loading="lazy" decoding="async" class="pageicon" src="/' + \
            'icons/pagedown.png" title="' + \
            translate['Page down'] + '" alt="' + \
            translate['Page down'] + '"></a>\n' + \
            '      </center>\n'
        tl_str += page_number_buttons(users_path, box_name, page_number,
                                      'timelineposts')
        tl_str += '<br>'
        tl_str += text_mode_separator
    elif item_ctr == 0:
        tl_str += _get_help_for_timeline(base_dir, box_name)

    # if using a text mode browser then don't show SHOW MORE because there
    # is no way to hide/expand sections.
    # Also replace MITM text with an eye icon
    if is_text_browser:
        tl_str = text_mode_removals(tl_str, translate)

    tl_str += \
        _html_timeline_end(base_dir, nickname, domain_full,
                           translate,
                           moderator, editor,
                           newswire, positive_voting,
                           show_publish_as_icon,
                           rss_icon_at_top, publish_button_at_top,
                           authorized, theme,
                           default_timeline, access_keys,
                           box_name,
                           enable_timing_log, timeline_start_time, ua_str)

    tl_str += html_footer()
    return tl_str


def html_individual_share(domain: str, share_id: str,
                          actor: str, shared_item: {}, translate: {},
                          show_contact: bool, remove_button: bool,
                          shares_file_type: str) -> str:
    """Returns an individual shared item as html
    """
    profile_str = '<div class="container">\n'
    profile_str += \
        '<p class="share-title">' + shared_item['displayName'] + '</p>\n'
    if shared_item.get('imageUrl'):
        profile_str += '<a href="' + shared_item['imageUrl'] + '">\n'
        profile_str += \
            '<img loading="lazy" decoding="async" ' + \
            'src="' + shared_item['imageUrl'] + \
            '" alt="' + translate['Item image'] + '">\n</a>\n'
    profile_str += '<p>' + shared_item['summary'] + '</p>\n<p>'
    if shared_item.get('itemQty'):
        if shared_item['itemQty'] > 1:
            profile_str += \
                '<b>' + translate['Quantity'] + ':</b> ' + \
                str(shared_item['itemQty']) + '<br>'
    profile_str += \
        '<b>' + translate['Type'] + ':</b> ' + shared_item['itemType'] + '<br>'
    profile_str += \
        '<b>' + translate['Category'] + ':</b> ' + \
        shared_item['category'] + '<br>'
    if shared_item.get('location'):
        profile_str += \
            '<b>' + translate['Location'] + ':</b> ' + \
            shared_item['location'] + '<br>'
    contact_title_str = translate['Contact']
    if shared_item.get('itemPrice') and shared_item.get('itemCurrency'):
        if is_float(shared_item['itemPrice']):
            if float(shared_item['itemPrice']) > 0:
                profile_str += ' ' + \
                    '<b>' + translate['Price'] + ':</b> ' + \
                    shared_item['itemPrice'] + ' ' + \
                    shared_item['itemCurrency']
                contact_title_str = translate['Buy']
    profile_str += '</p>\n'
    sharedesc = shared_item['displayName']
    if '<' not in sharedesc and ';' not in sharedesc:
        if show_contact:
            button_style_str = 'button'
            if shared_item['category'] == 'accommodation':
                contact_title_str = translate['Request to stay']
                button_style_str = 'contactbutton'

            contact_actor = get_actor_from_post(shared_item)
            profile_str += \
                '<p>' + \
                '<a href="' + actor + \
                '?replydm=sharedesc:' + sharedesc + \
                '?mention=' + contact_actor + '">' + \
                '<button class="' + button_style_str + '">' + \
                contact_title_str + '</button></a>\n'
            profile_str += \
                '<a href="' + contact_actor + '"><button class="button">' + \
                translate['Profile'] + '</button></a>\n'
        if remove_button and domain in share_id:
            if shares_file_type == 'shares':
                profile_str += \
                    ' <a href="' + actor + '?rmshare=' + share_id + \
                    '"><button class="button">' + \
                    translate['Remove'] + '</button></a>\n'
            else:
                profile_str += \
                    ' <a href="' + actor + '?rmwanted=' + share_id + \
                    '"><button class="button">' + \
                    translate['Remove'] + '</button></a>\n'
    profile_str += '</div>\n'
    return profile_str


def _html_shares_timeline(translate: {}, page_number: int, items_per_page: int,
                          base_dir: str, actor: str,
                          nickname: str, domain: str, port: int,
                          max_shares_per_account: int, http_prefix: str,
                          shared_items_federated_domains: [],
                          shares_file_type: str) -> str:
    """Show shared items timeline as html
    """
    shares_json, last_page = \
        shares_timeline_json(actor, page_number, items_per_page,
                             base_dir, domain, nickname,
                             max_shares_per_account,
                             shared_items_federated_domains, shares_file_type)
    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)
    admin_nickname = get_config_param(base_dir, 'admin')
    admin_actor = ''
    if admin_nickname:
        admin_actor = \
            local_actor_url(http_prefix, admin_nickname, domain_full)
    timeline_str = ''

    if page_number > 1:
        timeline_str += '<br>' + \
            page_number_buttons(actor, 'tl' + shares_file_type, page_number,
                                'timelineposts')
        timeline_str += \
            '  <center>\n' + \
            '    <a href="' + actor + '/tl' + shares_file_type + '?page=' + \
            str(page_number - 1) + \
            '#timelineposts" class="imageAnchor" tabindex="9">' + \
            '<img loading="lazy" decoding="async" ' + \
            'class="pageicon" src="/' + \
            'icons/pageup.png" title="' + translate['Page up'] + \
            '" alt="' + translate['Page up'] + '"></a>\n' + \
            '  </center>\n'

    separator_str = html_post_separator(base_dir, None)
    ctr = 0

    is_admin_account = False
    if admin_actor and actor == admin_actor:
        is_admin_account = True
    is_moderator_account = False
    if is_moderator(base_dir, nickname):
        is_moderator_account = True

    for _, shared_item in shares_json.items():
        show_contact_button = False
        actor_url = get_actor_from_post(shared_item)
        if actor_url != actor:
            show_contact_button = True
        show_remove_button = False
        if '___' + domain in shared_item['shareId']:
            if actor_url == actor or \
               is_admin_account or is_moderator_account:
                show_remove_button = True
        timeline_str += \
            html_individual_share(domain, shared_item['shareId'],
                                  actor, shared_item, translate,
                                  show_contact_button, show_remove_button,
                                  shares_file_type)
        timeline_str += separator_str
        ctr += 1

    if ctr == 0:
        timeline_str += \
            _get_help_for_timeline(base_dir, 'tl' + shares_file_type)

    if not last_page:
        timeline_str += \
            '  <center>\n' + \
            '    <a href="' + actor + '/tl' + shares_file_type + '?page=' + \
            str(page_number + 1) + \
            '#timelineposts" class="imageAnchor" tabindex="9">' + \
            '<img loading="lazy" decoding="async" ' + \
            'class="pageicon" src="/' + \
            'icons/pagedown.png" title="' + translate['Page down'] + \
            '" alt="' + translate['Page down'] + '"></a>\n' + \
            '  </center>\n'
        timeline_str += \
            page_number_buttons(actor, 'tl' + shares_file_type, page_number,
                                'timelineposts')
        timeline_str += '<br>'

    return timeline_str


def html_shares(default_timeline: str,
                recent_posts_cache: {}, max_recent_posts: int,
                translate: {}, page_number: int, items_per_page: int,
                session, base_dir: str,
                cached_webfingers: {}, person_cache: {},
                nickname: str, domain: str, port: int,
                allow_deletion: bool,
                http_prefix: str, project_version: str,
                yt_replace_domain: str,
                twitter_replacement_domain: str,
                show_published_date_only: bool,
                newswire: {}, positive_voting: bool,
                show_publish_as_icon: bool,
                full_width_tl_button_header: bool,
                icons_as_buttons: bool,
                rss_icon_at_top: bool,
                publish_button_at_top: bool,
                authorized: bool, theme: str,
                peertube_instances: [],
                allow_local_network_access: bool,
                text_mode_banner: str,
                access_keys: {}, system_language: str,
                max_like_count: int,
                shared_items_federated_domains: [],
                signing_priv_key_pem: str,
                cw_lists: {}, lists_enabled: str,
                timezone: str, bold_reading: bool,
                dogwhistles: {}, ua_str: str,
                min_images_for_accounts: [],
                reverse_sequence: bool,
                buy_sites: {},
                auto_cw_cache: {},
                known_epicyon_instances: [],
                mitm_servers: [],
                instance_software: {}) -> str:
    """Show the shares timeline as html
    """
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    artist = is_artist(base_dir, nickname)
    show_announces = True

    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, None,
                         'tlshares', allow_deletion,
                         http_prefix, project_version,
                         manually_approve_followers,
                         False,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains,
                         signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone,
                         bold_reading, dogwhistles, ua_str,
                         min_images_for_accounts,
                         reverse_sequence, None, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances,
                         mitm_servers, instance_software)


def html_wanted(default_timeline: str,
                recent_posts_cache: {}, max_recent_posts: int,
                translate: {}, page_number: int, items_per_page: int,
                session, base_dir: str,
                cached_webfingers: {}, person_cache: {},
                nickname: str, domain: str, port: int,
                allow_deletion: bool,
                http_prefix: str, project_version: str,
                yt_replace_domain: str,
                twitter_replacement_domain: str,
                show_published_date_only: bool,
                newswire: {}, positive_voting: bool,
                show_publish_as_icon: bool,
                full_width_tl_button_header: bool,
                icons_as_buttons: bool,
                rss_icon_at_top: bool,
                publish_button_at_top: bool,
                authorized: bool, theme: str,
                peertube_instances: [],
                allow_local_network_access: bool,
                text_mode_banner: str,
                access_keys: {}, system_language: str,
                max_like_count: int,
                shared_items_federated_domains: [],
                signing_priv_key_pem: str,
                cw_lists: {}, lists_enabled: str,
                timezone: str, bold_reading: bool,
                dogwhistles: {}, ua_str: str,
                min_images_for_accounts: [],
                reverse_sequence: bool,
                buy_sites: {},
                auto_cw_cache: {},
                known_epicyon_instances: [],
                mitm_servers: [],
                instance_software: {}) -> str:
    """Show the wanted timeline as html
    """
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    artist = is_artist(base_dir, nickname)
    show_announces = True

    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, None,
                         'tlwanted', allow_deletion,
                         http_prefix, project_version,
                         manually_approve_followers,
                         False,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains,
                         signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone,
                         bold_reading, dogwhistles, ua_str,
                         min_images_for_accounts,
                         reverse_sequence, None, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances,
                         mitm_servers, instance_software)


def html_inbox(default_timeline: str,
               recent_posts_cache: {}, max_recent_posts: int,
               translate: {}, page_number: int, items_per_page: int,
               session, base_dir: str,
               cached_webfingers: {}, person_cache: {},
               nickname: str, domain: str, port: int, inbox_json: {},
               allow_deletion: bool,
               http_prefix: str, project_version: str,
               minimal: bool,
               yt_replace_domain: str,
               twitter_replacement_domain: str,
               show_published_date_only: bool,
               newswire: {}, positive_voting: bool,
               show_publish_as_icon: bool,
               full_width_tl_button_header: bool,
               icons_as_buttons: bool,
               rss_icon_at_top: bool,
               publish_button_at_top: bool,
               authorized: bool, theme: str,
               peertube_instances: [],
               allow_local_network_access: bool,
               text_mode_banner: str,
               access_keys: {}, system_language: str,
               max_like_count: int,
               shared_items_federated_domains: [],
               signing_priv_key_pem: str,
               cw_lists: {}, lists_enabled: str,
               timezone: str, bold_reading: bool,
               dogwhistles: {}, ua_str: str,
               min_images_for_accounts: [],
               reverse_sequence: bool,
               last_post_id: str,
               buy_sites: {},
               auto_cw_cache: {},
               show_announces: bool,
               known_epicyon_instances: [],
               mitm_servers: [],
               instance_software: {}) -> str:
    """Show the inbox as html
    """
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    artist = is_artist(base_dir, nickname)

    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json,
                         'inbox', allow_deletion,
                         http_prefix, project_version,
                         manually_approve_followers,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains,
                         signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone,
                         bold_reading, dogwhistles, ua_str,
                         min_images_for_accounts,
                         reverse_sequence, last_post_id,
                         buy_sites, auto_cw_cache, show_announces,
                         known_epicyon_instances,
                         mitm_servers, instance_software)


def html_bookmarks(default_timeline: str,
                   recent_posts_cache: {}, max_recent_posts: int,
                   translate: {}, page_number: int, items_per_page: int,
                   session, base_dir: str,
                   cached_webfingers: {}, person_cache: {},
                   nickname: str, domain: str, port: int, bookmarks_json: {},
                   allow_deletion: bool,
                   http_prefix: str, project_version: str,
                   minimal: bool,
                   yt_replace_domain: str,
                   twitter_replacement_domain: str,
                   show_published_date_only: bool,
                   newswire: {}, positive_voting: bool,
                   show_publish_as_icon: bool,
                   full_width_tl_button_header: bool,
                   icons_as_buttons: bool,
                   rss_icon_at_top: bool,
                   publish_button_at_top: bool,
                   authorized: bool, theme: str,
                   peertube_instances: [],
                   allow_local_network_access: bool,
                   text_mode_banner: str,
                   access_keys: {}, system_language: str,
                   max_like_count: int,
                   shared_items_federated_domains: [],
                   signing_priv_key_pem: str,
                   cw_lists: {}, lists_enabled: str,
                   timezone: str, bold_reading: bool,
                   dogwhistles: {}, ua_str: str,
                   min_images_for_accounts: [],
                   reverse_sequence: bool,
                   buy_sites: {},
                   auto_cw_cache: {},
                   known_epicyon_instances: [],
                   mitm_servers: [],
                   instance_software: {}) -> str:
    """Show the bookmarks as html
    """
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    artist = is_artist(base_dir, nickname)
    show_announces = True

    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, bookmarks_json,
                         'tlbookmarks', allow_deletion,
                         http_prefix, project_version,
                         manually_approve_followers,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone,
                         bold_reading, dogwhistles, ua_str,
                         min_images_for_accounts,
                         reverse_sequence, None, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances,
                         mitm_servers, instance_software)


def html_inbox_dms(default_timeline: str,
                   recent_posts_cache: {}, max_recent_posts: int,
                   translate: {}, page_number: int, items_per_page: int,
                   session, base_dir: str,
                   cached_webfingers: {}, person_cache: {},
                   nickname: str, domain: str, port: int, inbox_json: {},
                   allow_deletion: bool,
                   http_prefix: str, project_version: str,
                   minimal: bool,
                   yt_replace_domain: str,
                   twitter_replacement_domain: str,
                   show_published_date_only: bool,
                   newswire: {}, positive_voting: bool,
                   show_publish_as_icon: bool,
                   full_width_tl_button_header: bool,
                   icons_as_buttons: bool,
                   rss_icon_at_top: bool,
                   publish_button_at_top: bool,
                   authorized: bool, theme: str,
                   peertube_instances: [],
                   allow_local_network_access: bool,
                   text_mode_banner: str,
                   access_keys: {}, system_language: str,
                   max_like_count: int,
                   shared_items_federated_domains: [],
                   signing_priv_key_pem: str,
                   cw_lists: {}, lists_enabled: str,
                   timezone: str, bold_reading: bool,
                   dogwhistles: {}, ua_str: str,
                   min_images_for_accounts: [],
                   reverse_sequence: bool,
                   last_post_id: str,
                   buy_sites: {},
                   auto_cw_cache: {},
                   known_epicyon_instances: [],
                   mitm_servers: [],
                   instance_software: {}) -> str:
    """Show the DM timeline as html
    """
    artist = is_artist(base_dir, nickname)
    show_announces = False
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json,
                         'dm', allow_deletion,
                         http_prefix, project_version, False, minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist, positive_voting,
                         show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains,
                         signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone,
                         bold_reading, dogwhistles, ua_str,
                         min_images_for_accounts,
                         reverse_sequence, last_post_id,
                         buy_sites, auto_cw_cache, show_announces,
                         known_epicyon_instances,
                         mitm_servers, instance_software)


def html_inbox_replies(default_timeline: str,
                       recent_posts_cache: {}, max_recent_posts: int,
                       translate: {}, page_number: int, items_per_page: int,
                       session, base_dir: str,
                       cached_webfingers: {}, person_cache: {},
                       nickname: str, domain: str, port: int, inbox_json: {},
                       allow_deletion: bool,
                       http_prefix: str, project_version: str,
                       minimal: bool,
                       yt_replace_domain: str,
                       twitter_replacement_domain: str,
                       show_published_date_only: bool,
                       newswire: {}, positive_voting: bool,
                       show_publish_as_icon: bool,
                       full_width_tl_button_header: bool,
                       icons_as_buttons: bool,
                       rss_icon_at_top: bool,
                       publish_button_at_top: bool,
                       authorized: bool, theme: str,
                       peertube_instances: [],
                       allow_local_network_access: bool,
                       text_mode_banner: str,
                       access_keys: {}, system_language: str,
                       max_like_count: int,
                       shared_items_federated_domains: [],
                       signing_priv_key_pem: str,
                       cw_lists: {}, lists_enabled: str,
                       timezone: str, bold_reading: bool,
                       dogwhistles: {}, ua_str: str,
                       min_images_for_accounts: [],
                       reverse_sequence: bool,
                       last_post_id: str,
                       buy_sites: {},
                       auto_cw_cache: {},
                       known_epicyon_instances: [],
                       mitm_servers: [],
                       instance_software: {}) -> str:
    """Show the replies timeline as html
    """
    artist = is_artist(base_dir, nickname)
    show_announces = True
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json, 'tlreplies',
                         allow_deletion, http_prefix, project_version, False,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone, bold_reading,
                         dogwhistles, ua_str, min_images_for_accounts,
                         reverse_sequence, last_post_id, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances, mitm_servers,
                         instance_software)


def html_inbox_media(default_timeline: str,
                     recent_posts_cache: {}, max_recent_posts: int,
                     translate: {}, page_number: int, items_per_page: int,
                     session, base_dir: str,
                     cached_webfingers: {}, person_cache: {},
                     nickname: str, domain: str, port: int, inbox_json: {},
                     allow_deletion: bool,
                     http_prefix: str, project_version: str,
                     minimal: bool,
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     show_published_date_only: bool,
                     newswire: {}, positive_voting: bool,
                     show_publish_as_icon: bool,
                     full_width_tl_button_header: bool,
                     icons_as_buttons: bool,
                     rss_icon_at_top: bool,
                     publish_button_at_top: bool,
                     authorized: bool, theme: str,
                     peertube_instances: [],
                     allow_local_network_access: bool,
                     text_mode_banner: str,
                     access_keys: {}, system_language: str,
                     max_like_count: int,
                     shared_items_federated_domains: [],
                     signing_priv_key_pem: str,
                     cw_lists: {}, lists_enabled: str,
                     timezone: str, bold_reading: bool,
                     dogwhistles: {}, ua_str: str,
                     min_images_for_accounts: [],
                     reverse_sequence: bool,
                     last_post_id: str,
                     buy_sites: {},
                     auto_cw_cache: {},
                     show_announces: bool,
                     known_epicyon_instances: [],
                     mitm_servers: [],
                     instance_software: {}) -> str:
    """Show the media timeline as html
    """
    artist = is_artist(base_dir, nickname)
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json, 'tlmedia',
                         allow_deletion, http_prefix, project_version, False,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone, bold_reading,
                         dogwhistles, ua_str, min_images_for_accounts,
                         reverse_sequence, last_post_id, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances, mitm_servers,
                         instance_software)


def html_inbox_blogs(default_timeline: str,
                     recent_posts_cache: {}, max_recent_posts: int,
                     translate: {}, page_number: int, items_per_page: int,
                     session, base_dir: str,
                     cached_webfingers: {}, person_cache: {},
                     nickname: str, domain: str, port: int, inbox_json: {},
                     allow_deletion: bool,
                     http_prefix: str, project_version: str,
                     minimal: bool,
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     show_published_date_only: bool,
                     newswire: {}, positive_voting: bool,
                     show_publish_as_icon: bool,
                     full_width_tl_button_header: bool,
                     icons_as_buttons: bool,
                     rss_icon_at_top: bool,
                     publish_button_at_top: bool,
                     authorized: bool, theme: str,
                     peertube_instances: [],
                     allow_local_network_access: bool,
                     text_mode_banner: str,
                     access_keys: {}, system_language: str,
                     max_like_count: int,
                     shared_items_federated_domains: [],
                     signing_priv_key_pem: str,
                     cw_lists: {}, lists_enabled: str,
                     timezone: str, bold_reading: bool,
                     dogwhistles: {}, ua_str: str,
                     min_images_for_accounts: [],
                     reverse_sequence: bool,
                     last_post_id: str,
                     buy_sites: {},
                     auto_cw_cache: {},
                     known_epicyon_instances: [],
                     mitm_servers: [],
                     instance_software: {}) -> str:
    """Show the blogs timeline as html
    """
    artist = is_artist(base_dir, nickname)
    show_announces = True
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json, 'tlblogs',
                         allow_deletion, http_prefix, project_version, False,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone, bold_reading,
                         dogwhistles, ua_str, min_images_for_accounts,
                         reverse_sequence, last_post_id, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances, mitm_servers,
                         instance_software)


def html_inbox_features(default_timeline: str,
                        recent_posts_cache: {}, max_recent_posts: int,
                        translate: {}, page_number: int, items_per_page: int,
                        session, base_dir: str,
                        cached_webfingers: {}, person_cache: {},
                        nickname: str, domain: str, port: int, inbox_json: {},
                        allow_deletion: bool,
                        http_prefix: str, project_version: str,
                        minimal: bool,
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        newswire: {}, positive_voting: bool,
                        show_publish_as_icon: bool,
                        full_width_tl_button_header: bool,
                        icons_as_buttons: bool,
                        rss_icon_at_top: bool,
                        publish_button_at_top: bool,
                        authorized: bool,
                        theme: str,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        text_mode_banner: str,
                        access_keys: {}, system_language: str,
                        max_like_count: int,
                        shared_items_federated_domains: [],
                        signing_priv_key_pem: str,
                        cw_lists: {}, lists_enabled: str,
                        timezone: str, bold_reading: bool,
                        dogwhistles: {}, ua_str: str,
                        min_images_for_accounts: [],
                        reverse_sequence: bool,
                        buy_sites: {},
                        auto_cw_cache: {},
                        known_epicyon_instances: [],
                        mitm_servers: [],
                        instance_software: {}) -> str:
    """Show the features timeline as html
    """
    show_announces = True
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json, 'tlfeatures',
                         allow_deletion, http_prefix, project_version, False,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, False,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone, bold_reading,
                         dogwhistles, ua_str, min_images_for_accounts,
                         reverse_sequence, None, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances, mitm_servers,
                         instance_software)


def html_inbox_news(default_timeline: str,
                    recent_posts_cache: {}, max_recent_posts: int,
                    translate: {}, page_number: int, items_per_page: int,
                    session, base_dir: str,
                    cached_webfingers: {}, person_cache: {},
                    nickname: str, domain: str, port: int, inbox_json: {},
                    allow_deletion: bool,
                    http_prefix: str, project_version: str,
                    minimal: bool,
                    yt_replace_domain: str,
                    twitter_replacement_domain: str,
                    show_published_date_only: bool,
                    newswire: {}, moderator: bool, editor: bool, artist: bool,
                    positive_voting: bool, show_publish_as_icon: bool,
                    full_width_tl_button_header: bool,
                    icons_as_buttons: bool,
                    rss_icon_at_top: bool,
                    publish_button_at_top: bool,
                    authorized: bool, theme: str,
                    peertube_instances: [],
                    allow_local_network_access: bool,
                    text_mode_banner: str,
                    access_keys: {}, system_language: str,
                    max_like_count: int,
                    shared_items_federated_domains: [],
                    signing_priv_key_pem: str,
                    cw_lists: {}, lists_enabled: str,
                    timezone: str, bold_reading: bool,
                    dogwhistles: {}, ua_str: str,
                    min_images_for_accounts: [],
                    reverse_sequence: bool,
                    buy_sites: {},
                    auto_cw_cache: {},
                    known_epicyon_instances: [],
                    mitm_servers: [],
                    instance_software: {}) -> str:
    """Show the news timeline as html
    """
    show_announces = True
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, inbox_json, 'tlnews',
                         allow_deletion, http_prefix, project_version, False,
                         minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, moderator, editor, artist,
                         positive_voting, show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone, bold_reading,
                         dogwhistles, ua_str, min_images_for_accounts,
                         reverse_sequence, None, buy_sites,
                         auto_cw_cache, show_announces,
                         known_epicyon_instances, mitm_servers,
                         instance_software)


def html_outbox(default_timeline: str,
                recent_posts_cache: {}, max_recent_posts: int,
                translate: {}, page_number: int, items_per_page: int,
                session, base_dir: str,
                cached_webfingers: {}, person_cache: {},
                nickname: str, domain: str, port: int, outbox_json: {},
                allow_deletion: bool,
                http_prefix: str, project_version: str,
                minimal: bool,
                yt_replace_domain: str,
                twitter_replacement_domain: str,
                show_published_date_only: bool,
                newswire: {}, positive_voting: bool,
                show_publish_as_icon: bool,
                full_width_tl_button_header: bool,
                icons_as_buttons: bool,
                rss_icon_at_top: bool,
                publish_button_at_top: bool,
                authorized: bool, theme: str,
                peertube_instances: [],
                allow_local_network_access: bool,
                text_mode_banner: str,
                access_keys: {}, system_language: str,
                max_like_count: int,
                shared_items_federated_domains: [],
                signing_priv_key_pem: str,
                cw_lists: {}, lists_enabled: str,
                timezone: str, bold_reading: bool,
                dogwhistles: {}, ua_str: str,
                min_images_for_accounts: [],
                reverse_sequence: bool,
                buy_sites: {},
                auto_cw_cache: {},
                show_announces: bool,
                known_epicyon_instances: [],
                mitm_servers: [],
                instance_software: {}) -> str:
    """Show the Outbox as html
    """
    manually_approve_followers = \
        follower_approval_active(base_dir, nickname, domain)
    artist = is_artist(base_dir, nickname)
    return html_timeline(default_timeline,
                         recent_posts_cache, max_recent_posts,
                         translate, page_number,
                         items_per_page, session, base_dir,
                         cached_webfingers, person_cache,
                         nickname, domain, port, outbox_json, 'outbox',
                         allow_deletion, http_prefix, project_version,
                         manually_approve_followers, minimal,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire, False, False, artist, positive_voting,
                         show_publish_as_icon,
                         full_width_tl_button_header,
                         icons_as_buttons, rss_icon_at_top,
                         publish_button_at_top,
                         authorized, None, theme, peertube_instances,
                         allow_local_network_access, text_mode_banner,
                         access_keys, system_language, max_like_count,
                         shared_items_federated_domains, signing_priv_key_pem,
                         cw_lists, lists_enabled, timezone, bold_reading,
                         dogwhistles, ua_str, min_images_for_accounts,
                         reverse_sequence, None, buy_sites, auto_cw_cache,
                         show_announces, known_epicyon_instances,
                         mitm_servers, instance_software)
