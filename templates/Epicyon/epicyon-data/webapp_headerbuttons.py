__filename__ = "webapp_headerbuttons.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"


import os
import time
from utils import acct_dir
from datetime import datetime
from datetime import timedelta
from happening import day_events_check
from webapp_utils import html_highlight_label


def header_buttons_timeline(default_timeline: str,
                            box_name: str,
                            page_number: int,
                            translate: {},
                            users_path: str,
                            media_button: str,
                            blogs_button: str,
                            features_button: str,
                            inbox_button: str,
                            dm_button: str,
                            new_dm: str,
                            replies_button: str,
                            new_reply: str,
                            minimal: bool,
                            sent_button: str,
                            shares_button_str: str,
                            wanted_button_str: str,
                            bookmarks_button_str: str,
                            events_button_str: str,
                            moderation_button_str: str,
                            header_icons_str: str,
                            new_post_button_str: str,
                            base_dir: str,
                            nickname: str, domain: str,
                            timeline_start_time,
                            new_calendar_event: bool,
                            calendar_path: str,
                            calendar_image: str,
                            follow_approvals: str,
                            icons_as_buttons: bool,
                            access_keys: {},
                            is_text_browser: str,
                            show_announces: bool) -> str:
    """Returns the header at the top of the timeline, containing
    buttons for inbox, outbox, search, calendar, etc
    """
    # start of the button header with inbox, outbox, etc
    tl_str = '<div id="containerHeader" class="containerHeader"><nav>\n'

    # if this is a news instance and we are viewing the news timeline
    features_header = False
    if default_timeline == 'tlfeatures' and box_name == 'tlfeatures':
        features_header = True

    if not is_text_browser:

        # first button
        if default_timeline == 'tlmedia':
            tl_str += \
                '<a href="' + users_path + '/tlmedia" tabindex="2" ' + \
                'accesskey="' + access_keys['menuMedia'] + '"'
            if box_name == 'tlmedia':
                tl_str += ' aria-current="location"'
            tl_str += \
                '><button class="' + \
                media_button + '"><span>' + translate['Media'] + \
                '</span></button></a>'
        elif default_timeline == 'tlblogs':
            tl_str += \
                '<a href="' + users_path + \
                '/tlblogs" tabindex="2"'
            if box_name == 'tlblogs':
                tl_str += ' aria-current="location"'
            tl_str += \
                '><button class="' + \
                blogs_button + '" accesskey="' + access_keys['menuBlogs'] + \
                '"><span>' + translate['Blogs'] + '</span></button></a>'
        elif default_timeline == 'tlfeatures':
            tl_str += \
                '<a href="' + users_path + \
                '/tlfeatures" tabindex="2"'
            if box_name == 'tlfeatures':
                tl_str += ' aria-current="location"'
            tl_str += \
                '><button class="' + \
                features_button + '"><span>' + translate['Features'] + \
                '</span></button></a>'
        else:
            tl_str += \
                '<a href="' + users_path + \
                '/inbox" tabindex="2"><button class="' + \
                inbox_button + '"'
            if box_name == 'inbox':
                tl_str += ' aria-current="location"'
            tl_str += \
                ' accesskey="' + access_keys['menuInbox'] + '">' + \
                '<span>' + translate['Inbox'] + '</span></button></a>'

        if not features_header:
            tl_str += \
                '<a href="' + users_path + '/dm" tabindex="2"'
            if box_name == 'dm':
                tl_str += ' aria-current="location"'
            tl_str += \
                '><button class="' + dm_button + \
                '" accesskey="' + access_keys['menuDM'] + '">' + \
                '<span>' + html_highlight_label(translate['DM'], new_dm) + \
                '</span></button></a>'

            replies_index_filename = \
                acct_dir(base_dir, nickname, domain) + '/tlreplies.index'
            if os.path.isfile(replies_index_filename):
                tl_str += \
                    '<a href="' + users_path + '/tlreplies" tabindex="2"'
                if box_name == 'tlreplies':
                    tl_str += ' aria-current="location"'
                tl_str += \
                    '><button class="' + replies_button + '" ' + \
                    'accesskey="' + access_keys['menuReplies'] + '"><span>' + \
                    html_highlight_label(translate['Replies'], new_reply) + \
                    '</span></button></a>'

        # typically the media button
        if default_timeline != 'tlmedia':
            if not minimal and not features_header:
                tl_str += \
                    '<a href="' + users_path + '/tlmedia" tabindex="2" ' + \
                    'accesskey="' + access_keys['menuMedia'] + '"'
                if box_name == 'tlmedia':
                    tl_str += ' aria-current="location"'
                tl_str += \
                    '><button class="' + \
                    media_button + '"><span>' + translate['Media'] + \
                    '</span></button></a>'
        else:
            if not minimal:
                tl_str += \
                    '<a href="' + users_path + \
                    '/inbox" tabindex="2"'
                if box_name == 'inbox':
                    tl_str += ' aria-current="location"'
                tl_str += \
                    '><button class="' + \
                    inbox_button + '"><span>' + translate['Inbox'] + \
                    '</span></button></a>'

        if not features_header:
            # typically the blogs button
            # but may change if this is a blogging oriented instance
            if default_timeline != 'tlblogs':
                if not minimal:
                    title_str = translate['Blogs']
                    if default_timeline == 'tlfeatures':
                        title_str = translate['Article']
                    tl_str += \
                        '<a href="' + users_path + \
                        '/tlblogs" tabindex="2"'
                    if box_name == 'tlblogs':
                        tl_str += ' aria-current="location"'
                    tl_str += \
                        '><button class="' + blogs_button + '" accesskey="' + \
                        access_keys['menuBlogs'] + '"><span>' + title_str + \
                        '</span></button></a>'
            else:
                if not minimal:
                    tl_str += \
                        '<a href="' + users_path + \
                        '/inbox" tabindex="2"'
                    if box_name == 'inbox':
                        tl_str += ' aria-current="location"'
                    tl_str += \
                        '><button class="' + \
                        inbox_button + '"><span>' + translate['Inbox'] + \
                        '</span></button></a>'

        # typically the news button
        # but may change if this is a news oriented instance
        if default_timeline == 'tlfeatures':
            if not features_header:
                tl_str += \
                    '<a href="' + users_path + \
                    '/inbox" tabindex="2"'
                if box_name == 'inbox':
                    tl_str += ' aria-current="location"'
                tl_str += \
                    '><button class="' + \
                    inbox_button + '" accesskey="' + \
                    access_keys['menuInbox'] + '"><span>' + \
                    translate['Inbox'] + '</span></button></a>'

    # show todays events buttons on the first inbox page
    happening_str = ''
    if box_name == 'inbox' and page_number == 1:
        now = datetime.now()
        tomorrow = datetime.now() + timedelta(1)
        twodays = datetime.now() + timedelta(2)
        if day_events_check(base_dir, nickname, domain, now):
            # happening today button
            if not icons_as_buttons:
                happening_str += \
                    '<a href="' + users_path + '/calendar?year=' + \
                    str(now.year) + '?month=' + str(now.month) + \
                    '?day=' + str(now.day) + '" tabindex="2">' + \
                    '<button class="buttonevent">' + \
                    translate['Happening Today'] + '</button></a>'
            else:
                happening_str += \
                    '<a href="' + users_path + '/calendar?year=' + \
                    str(now.year) + '?month=' + str(now.month) + \
                    '?day=' + str(now.day) + '" tabindex="2">' + \
                    '<button class="button">' + \
                    translate['Happening Today'] + '</button></a>'

        elif day_events_check(base_dir, nickname, domain, tomorrow):
            # happening tomorrow button
            if not icons_as_buttons:
                happening_str += \
                    '<a href="' + users_path + '/calendar?year=' + \
                    str(tomorrow.year) + '?month=' + str(tomorrow.month) + \
                    '?day=' + str(tomorrow.day) + '" tabindex="2">' + \
                    '<button class="buttonevent">' + \
                    translate['Happening Tomorrow'] + '</button></a>'
            else:
                happening_str += \
                    '<a href="' + users_path + '/calendar?year=' + \
                    str(tomorrow.year) + '?month=' + str(tomorrow.month) + \
                    '?day=' + str(tomorrow.day) + '" tabindex="2">' + \
                    '<button class="button">' + \
                    translate['Happening Tomorrow'] + '</button></a>'
        elif day_events_check(base_dir, nickname, domain, twodays):
            if not icons_as_buttons:
                happening_str += \
                    '<a href="' + users_path + \
                    '/calendar" tabindex="2">' + \
                    '<button class="buttonevent">' + \
                    translate['Happening This Week'] + '</button></a>'
            else:
                happening_str += \
                    '<a href="' + users_path + \
                    '/calendar" tabindex="2">' + \
                    '<button class="button">' + \
                    translate['Happening This Week'] + '</button></a>'

    if not is_text_browser:
        if not features_header:
            # button for the outbox
            tl_str += \
                '<a href="' + users_path + '/outbox"'
            if box_name == 'outbox':
                tl_str += ' aria-current="location"'
            tl_str += \
                '><button class="' + \
                sent_button + '" tabindex="2" accesskey="' + \
                access_keys['menuOutbox'] + '">' + \
                '<span>' + translate['Sent'] + '</span></button></a>'

            # add other buttons
            tl_str += \
                shares_button_str + wanted_button_str + \
                bookmarks_button_str + events_button_str + \
                moderation_button_str + happening_str
    else:
        tl_str += happening_str

    # start of headericons div
    if not is_text_browser:
        if not features_header:
            tl_str += header_icons_str

    # 1. follow request icon
    if not features_header:
        tl_str += follow_approvals

    # 2. edit profile on features header
    if features_header:
        tl_str += \
            '<a href="' + users_path + '/editprofile" tabindex="2">' + \
            '<button class="buttonDesktop">' + \
            '<span>' + translate['Settings'] + '</span></button></a>'

    # 3. the newswire and links button to show right column links
    if not is_text_browser:
        # the links button to show left column links
        if not icons_as_buttons:
            tl_str += \
                '<a class="imageAnchorMobile" href="' + \
                users_path + '/linksmobile">' + \
                '<img loading="lazy" decoding="async" src="/icons' + \
                '/links.png" title="' + translate['Edit Links'] + \
                '" alt="| ' + translate['Edit Links'] + \
                '" class="timelineicon"/></a>'
        else:
            # NOTE: deliberately no \n at end of line
            tl_str += \
                '<a href="' + \
                users_path + '/linksmobile' + \
                '" tabindex="2"><button class="buttonMobile">' + \
                '<span>' + translate['Links'] + \
                '</span></button></a>'

        # the newswire button to show left column links
        if not icons_as_buttons:
            tl_str += \
                '<a class="imageAnchorMobile" href="' + \
                users_path + '/newswiremobile">' + \
                '<img loading="lazy" decoding="async" src="/icons' + \
                '/newswire.png" title="' + translate['News'] + \
                '" alt="| ' + translate['News'] + \
                '" class="timelineicon"/></a>'
        else:
            # NOTE: deliberately no \n at end of line
            tl_str += \
                '<a href="' + \
                users_path + '/newswiremobile' + \
                '" tabindex="2"><button class="buttonMobile">' + \
                '<span>' + translate['Newswire'] + \
                '</span></button></a>'

    if not is_text_browser:
        if not features_header:
            # 4. the show/hide button, for a simpler header appearance
            if not icons_as_buttons:
                tl_str += \
                    '      <a class="imageAnchor" href="' + \
                    users_path + '/minimal" tabindex="3">' + \
                    '<img loading="lazy" decoding="async" src="/icons' + \
                    '/showhide.png" title="' + \
                    translate['Show/Hide Buttons'] + \
                    '" alt="| ' + translate['Show/Hide Buttons'] + \
                    '" class="timelineicon"/></a>\n'
            else:
                tl_str += \
                    '<a href="' + users_path + '/minimal' + \
                    '" tabindex="3"><button class="button">' + \
                    '<span>' + translate['Show/Hide Buttons'] + \
                    '</span></button></a>'

            # 5. the hide announces button
            if show_announces:
                hide_announces_icon = 'repeat_hide.png'
                hide_announces_text = translate['Hide Announces']
            else:
                hide_announces_icon = 'repeat_show.png'
                hide_announces_text = translate['Show Announces']

            if not icons_as_buttons:
                tl_str += \
                    '      <a class="imageAnchor" href="' + \
                    users_path + '/hideannounces" tabindex="3">' + \
                    '<img loading="lazy" decoding="async" src="/icons' + \
                    '/' + hide_announces_icon + '" title="' + \
                    hide_announces_text + \
                    '" alt="| ' + translate['Hide Announces'] + \
                    '" class="timelineicon"/></a>\n'
            else:
                tl_str += \
                    '<a href="' + users_path + '/hideannounces' + \
                    '" tabindex="3"><button class="button">' + \
                    '<span>' + hide_announces_text + \
                    '</span></button></a>'

        # 6. calendar button
        if not features_header:
            calendar_alt_text = translate['Calendar']
            if new_calendar_event:
                # indicate that the calendar icon is highlighted
                calendar_alt_text = '*' + calendar_alt_text + '*'
            if not icons_as_buttons:
                tl_str += \
                    '      <a class="imageAnchor" href="' + \
                    users_path + calendar_path + \
                    '" accesskey="' + access_keys['menuCalendar'] + \
                    '" tabindex="3">' + \
                    '<img loading="lazy" decoding="async" src="/icons/' + \
                    calendar_image + '" title="' + translate['Calendar'] + \
                    '" alt="| ' + calendar_alt_text + \
                    '" class="timelineicon"/></a>\n'
            else:
                tl_str += \
                    '<a href="' + users_path + calendar_path + \
                    '" tabindex="3"><button class="button" accesskey="' + \
                    access_keys['menuCalendar'] + '">' + \
                    '<span>' + translate['Calendar'] + \
                    '</span></button></a>'

    if features_header:
        tl_str += \
            '<a href="' + users_path + '/inbox" tabindex="2"'
        if box_name == 'inbox':
            tl_str += ' aria-current="location"'
        tl_str += \
            '><button class="button">' + \
            '<span>' + translate['User'] + '</span></button></a>'

    # 7. search button
    if not is_text_browser:
        if not features_header:
            if not icons_as_buttons:
                # the search icon
                tl_str += \
                    '<a class="imageAnchor" href="' + users_path + \
                    '/search" accesskey="' + access_keys['menuSearch'] + \
                    '" tabindex="3">' + \
                    '<img loading="lazy" decoding="async" src="/' + \
                    'icons/search.png" title="' + \
                    translate['Search and follow'] + '" alt="| ' + \
                    translate['Search and follow'] + \
                    '" class="timelineicon"/></a>'
            else:
                # the search button
                tl_str += \
                    '<a href="' + users_path + \
                    '/search" tabindex="3">' + \
                    '<button class="button" ' + \
                    'accesskey="' + access_keys['menuSearch'] + '>' + \
                    '<span>' + translate['Search'] + \
                    '</span></button></a>'

    # 8. new post
    if not is_text_browser:
        if not features_header:
            tl_str += new_post_button_str

    # benchmark 5
    time_diff = int((time.time() - timeline_start_time) * 1000)
    if time_diff > 100:
        print('TIMELINE TIMING ' + box_name + ' 5 = ' + str(time_diff))

    # end of headericons div
    if not icons_as_buttons:
        tl_str += '</div>'

    # end of the button header with inbox, outbox, etc
    tl_str += '    </nav></div>\n'
    return tl_str
