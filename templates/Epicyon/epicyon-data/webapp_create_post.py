__filename__ = "webapp_create_post.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from flags import is_public_post_from_url
from flags import is_premium_account
from utils import data_dir
from utils import dangerous_markup
from utils import remove_html
from utils import get_content_from_post
from utils import has_object_dict
from utils import load_json
from utils import locate_post
from utils import get_new_post_endpoints
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_media_formats
from utils import get_config_param
from utils import acct_dir
from utils import get_currencies
from utils import get_category_types
from utils import get_account_timezone
from utils import get_supported_languages
from utils import get_attributed_to
from utils import get_full_domain
from blocking import sending_is_blocked2
from webapp_utils import open_content_warning
from webapp_utils import edit_check_box
from webapp_utils import get_buy_links
from webapp_utils import html_following_data_list
from webapp_utils import html_common_emoji
from webapp_utils import begin_edit_section
from webapp_utils import end_edit_section
from webapp_utils import get_banner_file
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import edit_text_field
from webapp_utils import edit_number_field
from webapp_utils import edit_currency_field
from webapp_post import individual_post_as_html
from maps import get_map_preferences_url
from maps import get_map_preferences_coords
from maps import get_location_from_post
from cache import get_person_from_cache
from person import get_person_notes


def _html_new_post_drop_down(scope_icon: str, scope_description: str,
                             reply_str: str,
                             translate: {},
                             show_public_on_dropdown: bool,
                             default_timeline: str,
                             path_base: str,
                             dropdown_new_post_suffix: str,
                             dropdown_new_blog_suffix: str,
                             dropdown_unlisted_suffix: str,
                             dropdown_followers_suffix: str,
                             dropdown_dm_suffix: str,
                             dropdown_reminder_suffix: str,
                             dropdown_report_suffix: str,
                             no_drop_down: bool,
                             access_keys: {},
                             account_dir: str,
                             premium: bool) -> str:
    """Returns the html for a drop down list of new post types
    """
    drop_down_content = '<nav><div class="newPostDropdown">\n'
    if not no_drop_down:
        drop_down_content += '  <input type="checkbox" ' + \
            'id="my-newPostDropdown" value="" name="my-checkbox">\n'
    drop_down_content += '  <label for="my-newPostDropdown"\n'
    drop_down_content += '     data-toggle="newPostDropdown">\n'
    drop_down_content += '  <img loading="lazy" decoding="async" ' + \
        'alt="" title="" src="/' + \
        'icons/' + scope_icon + '"/><b>' + scope_description + '</b></label>\n'

    if no_drop_down:
        drop_down_content += '</div></nav>\n'
        return drop_down_content

    drop_down_content += '  <ul>\n'
    if show_public_on_dropdown:
        drop_down_content += \
            '<li><a href="' + path_base + dropdown_new_post_suffix + \
            '" accesskey="' + access_keys['Public'] + '">' + \
            '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
            'icons/scope_public.png"/><b>' + \
            translate['Public'] + '</b><br>' + \
            translate['Visible to anyone'] + '</a></li>\n'
        if default_timeline == 'tlfeatures':
            drop_down_content += \
                '<li><a href="' + path_base + dropdown_new_blog_suffix + \
                '" accesskey="' + access_keys['menuBlogs'] + '">' + \
                '<img loading="lazy" decoding="async" ' + \
                'alt="" title="" src="/' + \
                'icons/scope_blog.png"/><b>' + \
                translate['Article'] + '</b><br>' + \
                translate['Create an article'] + '</a></li>\n'
        else:
            drop_down_content += \
                '<li><a href="' + path_base + dropdown_new_blog_suffix + \
                '" accesskey="' + access_keys['menuBlogs'] + '">' + \
                '<img loading="lazy" decoding="async" ' + \
                'alt="" title="" src="/' + \
                'icons/scope_blog.png"/><b>' + \
                translate['Blog'] + '</b><br>' + \
                translate['Publicly visible post'] + '</a></li>\n'
    drop_down_content += \
        '<li><a href="' + path_base + dropdown_unlisted_suffix + \
        '"><img loading="lazy" decoding="async" alt="" title="" src="/' + \
        'icons/scope_unlisted.png"/><b>' + \
        translate['Unlisted'] + '</b><br>' + \
        translate['Not on public timeline'] + '</a></li>\n'
    followers_str = translate['Followers']
    followers_desc_str = translate['Only to followers']
    if premium:
        followers_str = translate['Fans']
        followers_desc_str = translate['Only to fans']
    drop_down_content += \
        '<li><a href="' + path_base + dropdown_followers_suffix + \
        '" accesskey="' + access_keys['menuFollowers'] + '">' + \
        '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
        'icons/scope_followers.png"/><b>' + \
        followers_str + '</b><br>' + \
        followers_desc_str + '</a></li>\n'
    drop_down_content += \
        '<li><a href="' + path_base + dropdown_dm_suffix + \
        '" accesskey="' + access_keys['menuDM'] + '">' + \
        '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
        'icons/scope_dm.png"/><b>' + \
        translate['DM'] + '</b><br>' + \
        translate['Only to mentioned people'] + '</a></li>\n'

    drop_down_content += \
        '<li><a href="' + path_base + dropdown_reminder_suffix + \
        '" accesskey="' + access_keys['Reminder'] + '">' + \
        '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
        'icons/scope_reminder.png"/><b>' + \
        translate['Reminder'] + '</b><br>' + \
        translate['Scheduled note to yourself'] + '</a></li>\n'
    drop_down_content += \
        '<li><a href="' + path_base + dropdown_report_suffix + \
        '" accesskey="' + access_keys['reportButton'] + '">' + \
        '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
        'icons/scope_report.png"/><b>' + \
        translate['Report'] + '</b><br>' + \
        translate['Send to moderators'] + '</a></li>\n'

    if not reply_str:
        drop_down_content += \
            '<li><a href="' + path_base + \
            '/newshare" accesskey="' + access_keys['menuShares'] + '">' + \
            '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
            'icons/scope_share.png"/><b>' + \
            translate['Shares'] + '</b><br>' + \
            translate['Describe a shared item'] + '</a></li>\n'
        drop_down_content += \
            '<li><a href="' + path_base + \
            '/newwanted" accesskey="' + access_keys['menuWanted'] + '">' + \
            '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
            'icons/scope_wanted.png"/><b>' + \
            translate['Wanted'] + '</b><br>' + \
            translate['Describe something wanted'] + '</a></li>\n'
        drop_down_content += \
            '<li><a href="' + path_base + \
            '/newreadingstatus" accesskey="' + \
            access_keys['menuReadingStatus'] + '">' + \
            '<img loading="lazy" decoding="async" alt="" title="" src="/' + \
            'icons/scope_readingstatus.png"/><b>' + \
            translate['Reading Status'] + '</b><br>' + \
            translate['Book reading updates'] + '</a></li>\n'

    # whether to show votes
    show_vote_file = account_dir + '/.noVotes'
    if not os.path.isfile(show_vote_file):
        drop_down_content += \
            '<li><a href="' + path_base + \
            '/newquestion"><img loading="lazy" decoding="async" ' + \
            'alt="" title="" src="/' + \
            'icons/scope_question.png"/><b>' + \
            translate['Question'] + '</b><br>' + \
            translate['Ask a question'] + '</a></li>\n'
    drop_down_content += '  </ul>\n'

    drop_down_content += '</div></nav>\n'
    return drop_down_content


def _get_date_from_tags(tags: []) -> (str, str):
    """Returns the date from the tags list
    """
    for tag_item in tags:
        if not tag_item.get('type'):
            continue
        if tag_item['type'] != 'Event':
            continue
        if not tag_item.get('startTime'):
            continue
        if not isinstance(tag_item['startTime'], str):
            continue
        if 'T' not in tag_item['startTime']:
            continue
        start_time = tag_item['startTime']
        if not tag_item.get('endTime'):
            return start_time, ''
        if not isinstance(tag_item['endTime'], str):
            return start_time, ''
        if 'T' not in tag_item['endTime']:
            return start_time, ''
        end_time = tag_item['endTime']
        return start_time, end_time
    return '', ''


def _remove_initial_mentions_from_content(content: str) -> str:
    """ Removes initial @mentions from content
    This happens when a the html content is converted back to plain text
    """
    if not content.startswith('@'):
        return content
    words = content.split(' ')
    new_content = ''
    for wrd in words:
        if wrd.startswith('@'):
            continue
        if new_content:
            new_content += ' ' + wrd
        else:
            new_content += wrd
    return new_content


def html_new_post(edit_post_params: {},
                  media_instance: bool, translate: {},
                  base_dir: str, http_prefix: str,
                  path: str, in_reply_to: str,
                  mentions: [],
                  share_description: str,
                  report_url: str, page_number: int,
                  category: str,
                  nickname: str, domain: str,
                  domain_full: str,
                  default_timeline: str, newswire: {},
                  theme: str, no_drop_down: bool,
                  access_keys: {}, custom_submit_text: str,
                  conversation_id: str, convthread_id: str,
                  recent_posts_cache: {}, max_recent_posts: int,
                  session, cached_webfingers: {},
                  person_cache: {}, port: int,
                  post_json_object: {},
                  project_version: str,
                  yt_replace_domain: str,
                  twitter_replacement_domain: str,
                  show_published_date_only: bool,
                  peertube_instances: [],
                  allow_local_network_access: bool,
                  system_language: str,
                  languages_understood: [],
                  max_like_count: int, signing_priv_key_pem: str,
                  cw_lists: {}, lists_enabled: str,
                  box_name: str,
                  reply_is_chat: bool, bold_reading: bool,
                  dogwhistles: {},
                  min_images_for_accounts: [],
                  default_month: int, default_year: int,
                  default_post_language: str,
                  buy_sites: {},
                  default_buy_site: str,
                  auto_cw_cache: {},
                  searchable_by_default: str,
                  mitm_servers: [],
                  instance_software: {}) -> str:
    """New post screen
    """
    # get the json if this is an edited post
    edited_post_json = None
    edited_published = ''
    if edit_post_params:
        if edit_post_params.get('post_url'):
            edited_post_filename = \
                locate_post(base_dir, nickname,
                            domain, edit_post_params['post_url'])
            if edited_post_filename:
                edited_post_json = load_json(edited_post_filename)
                if not has_object_dict(edited_post_json):
                    return ''
        if not edited_post_json:
            return ''
        buy_links = \
            get_buy_links(edited_post_json, translate, buy_sites)
        if buy_links:
            for _, buy_url in buy_links.items():
                default_buy_site = buy_url
                break

        # Due to lack of AP specification maintenance, a conversation can also
        # be referred to as a thread or (confusingly) "context"
        if edited_post_json['object'].get('conversation'):
            conversation_id = edited_post_json['object']['conversation']
        elif edited_post_json['object'].get('context'):
            conversation_id = edited_post_json['object']['context']

        if edited_post_json['object'].get('thread'):
            convthread_id = edited_post_json['object']['thread']

        if edit_post_params.get('replyTo'):
            in_reply_to = edit_post_params['replyTo']
        if edit_post_params['scope'] == 'dm':
            mentions = edited_post_json['object']['to']
        edited_published = \
            edited_post_json['object']['published']

    # default subject line or content warning
    default_subject = ''
    if share_description:
        default_subject = share_description

    default_location = ''
    default_start_time = ''
    default_end_time = ''
    if default_month and default_year:
        default_month_str = str(default_month)
        if default_month < 10:
            default_month_str = '0' + default_month_str
        default_start_time = \
            str(default_year) + '-' + default_month_str + '-01T09:00:00'
    if edited_post_json:
        # if this is an edited post then get the subject line or
        # content warning
        summary_str = get_content_from_post(edited_post_json, system_language,
                                            languages_understood, "summary")
        if summary_str:
            default_subject = remove_html(summary_str)

        if edited_post_json['object'].get('tag'):
            # if this is an edited post then get the location
            location_str = get_location_from_post(edited_post_json)
            if location_str:
                default_location = location_str
            # if this is an edited post then get the start and end time
            default_start_time, default_end_time = \
                _get_date_from_tags(edited_post_json['object']['tag'])

    reply_str = ''

    is_new_reminder = False
    if path.endswith('/newreminder'):
        is_new_reminder = True

    # the date and time
    date_and_time_str = '<p>\n'
    if not is_new_reminder:
        date_and_time_str += \
            '<img loading="lazy" decoding="async" alt="" title="" ' + \
            'class="emojicalendar" src="/icons/calendar.png"/>\n'
    # select a date and time for this post
    date_and_time_str += '<label class="labels">' + \
        translate['Date'] + ': </label>\n'
    date_default = ''
    time_default = ''
    if default_start_time:
        date_default = ' value="' + default_start_time.split('T')[0] + '"'
        time_default = ' value="' + default_start_time.split('T')[1] + '"'
    end_time_default = ''
    if default_end_time:
        end_time_default = ' value="' + default_end_time.split('T')[1] + '"'
    date_and_time_str += \
        '<input type="date" name="eventDate"' + date_default + '>\n'
    date_and_time_str += '<label class="labelsright">' + \
        translate['Start Time'] + ': '
    date_and_time_str += \
        '<input type="time" name="eventTime"' + \
        time_default + '></label>\n<br>\n'
    date_and_time_str += '<label class="labelsright">' + \
        translate['End Time'] + ': '
    date_and_time_str += \
        '<input type="time" name="eventEndTime"' + \
        end_time_default + '></label>\n</p>\n'

    show_public_on_dropdown = True
    message_box_height = 400
    image_description_height = 150
    transcript_height = 1000

    # filename of the banner shown at the top
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    banner_path = '/users/' + nickname + '/' + banner_file

    if not path.endswith('/newshare') and not path.endswith('/newwanted'):
        if not path.endswith('/newreport'):
            if not in_reply_to or is_new_reminder:
                new_post_text = '<h1>' + \
                    translate['Write your post text below.'] + '</h1>\n'
            else:
                new_post_text = ''
                if category != 'accommodation':
                    new_post_text = \
                        '<p class="new-post-text">' + \
                        translate['Write your reply to'] + \
                        ' <a href="' + in_reply_to + \
                        '" rel="nofollow noopener noreferrer" ' + \
                        'target="_blank">' + \
                        translate['this post'] + '</a></p>\n'

                    # is sending posts to this account blocked?
                    reply_actor = in_reply_to
                    reply_nickname = get_nickname_from_actor(in_reply_to)
                    if reply_nickname:
                        reply_actor = \
                            in_reply_to.split('/' + reply_nickname)[0] + \
                            '/' + reply_nickname
                    reply_domain, _ = get_domain_from_actor(reply_actor)
                    if sending_is_blocked2(base_dir, nickname, domain,
                                           reply_domain, reply_actor):
                        new_post_text += \
                            '  <p class="new-post-text"><b>' + \
                            translate['FollowAccountWarning'] + \
                            '</b></p>\n'

                    if post_json_object:
                        timezone = \
                            get_account_timezone(base_dir, nickname, domain)
                        minimize_all_images = False
                        if nickname in min_images_for_accounts:
                            minimize_all_images = True
                        replied_to_post = \
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
                                                    timezone, False,
                                                    bold_reading, dogwhistles,
                                                    minimize_all_images, None,
                                                    buy_sites, auto_cw_cache,
                                                    mitm_servers,
                                                    instance_software)
                        new_post_text += \
                            open_content_warning(replied_to_post, translate)
                        # about the author
                        if has_object_dict(post_json_object):
                            if post_json_object['object'].get('attributedTo'):
                                attrib_field = \
                                    post_json_object['object']['attributedTo']
                                attrib_url = get_attributed_to(attrib_field)
                                domain_full = get_full_domain(domain, port)
                                this_account_url = \
                                    '://' + domain_full + '/users/' + nickname
                                if attrib_url and \
                                   not attrib_url.endswith(this_account_url):
                                    reply_to_actor = \
                                        get_person_from_cache(base_dir,
                                                              attrib_url,
                                                              person_cache)
                                    if reply_to_actor:
                                        summary = None
                                        if reply_to_actor.get('summary'):
                                            summary = reply_to_actor['summary']
                                        attrib_nickname = \
                                            get_nickname_from_actor(attrib_url)
                                        attrib_domain, attrib_port = \
                                            get_domain_from_actor(attrib_url)
                                        if attrib_nickname and attrib_domain:
                                            attrib_domain_full = \
                                                get_full_domain(attrib_domain,
                                                                attrib_port)
                                            attrib_handle = \
                                                attrib_nickname + '@' + \
                                                attrib_domain_full
                                            person_notes = \
                                                get_person_notes(base_dir,
                                                                 nickname,
                                                                 domain,
                                                                 attrib_handle)
                                            if person_notes:
                                                if summary:
                                                    summary = \
                                                        '<b>' + \
                                                        person_notes + \
                                                        '</b>' + \
                                                        '<br><br>' + summary
                                                else:
                                                    summary = \
                                                        '<b>' + \
                                                        person_notes + \
                                                        '</b>'
                                        if summary:
                                            if not dangerous_markup(summary,
                                                                    False, []):
                                                reply_to_description = \
                                                    summary
                                            else:
                                                reply_to_description = \
                                                    remove_html(summary)
                                            about_author_str = \
                                                translate['About the author']
                                            new_post_text += \
                                                '<div class="container">\n' + \
                                                '  <div class=' + \
                                                '"post-title">\n' + \
                                                '    ' + about_author_str + \
                                                '\n  </div>\n' + \
                                                '  ' + reply_to_description + \
                                                '\n</div>\n'

                reply_str = '<input type="hidden" ' + \
                    'name="replyTo" value="' + in_reply_to + '">\n'

                # if replying to a non-public post then also make
                # this post non-public
                if not is_public_post_from_url(base_dir, nickname, domain,
                                               in_reply_to):
                    new_post_path = path
                    if '?' in new_post_path:
                        new_post_path = new_post_path.split('?')[0]
                    if new_post_path.endswith('/newpost'):
                        path = path.replace('/newpost', '/newfollowers')
                    show_public_on_dropdown = False
        else:
            new_post_text = \
                '<h1>' + translate['Write your report below.'] + '</h1>\n'

            # custom report header with any additional instructions
            dir_str = data_dir(base_dir)
            if os.path.isfile(dir_str + '/report.txt'):
                try:
                    with open(dir_str + '/report.txt', 'r',
                              encoding='utf-8') as fp_report:
                        custom_report_text = fp_report.read()
                        if '</p>' not in custom_report_text:
                            custom_report_text = \
                                '<p class="login-subtext">' + \
                                custom_report_text + '</p>\n'
                            rep_str = '<p class="login-subtext">'
                            custom_report_text = \
                                custom_report_text.replace('<p>', rep_str)
                            new_post_text += custom_report_text
                except OSError as exc:
                    print('EX: html_new_post unable to read ' +
                          dir_str + '/report.txt ' + str(exc))

            idx = 'This message only goes to moderators, even if it ' + \
                'mentions other fediverse addresses.'
            new_post_text += \
                '<p class="new-post-subtext">' + translate[idx] + '</p>\n' + \
                '<p class="new-post-subtext">' + translate['Also see'] + \
                ' <a href="/terms">' + \
                translate['Terms of Service'] + '</a></p>\n'
    else:
        if path.endswith('/newshare'):
            new_post_text = \
                '<h1>' + \
                translate['Enter the details for your shared item below.'] + \
                '</h1>\n'
        else:
            new_post_text = \
                '<h1>' + \
                translate['Enter the details for your wanted item below.'] + \
                '</h1>\n'

    if path.endswith('/newquestion'):
        new_post_text = \
            '<h1>' + \
            translate['Enter the choices for your question below.'] + \
            '</h1>\n'

    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/newpost.txt'):
        try:
            with open(dir_str + '/newpost.txt', 'r',
                      encoding='utf-8') as fp_new:
                new_post_text = '<p>' + fp_new.read() + '</p>\n'
        except OSError:
            print('EX: html_new_post unable to read ' +
                  dir_str + '/newpost.txt')

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    if '?' in path:
        path = path.split('?')[0]
    new_post_endpoints = get_new_post_endpoints()
    path_base = path
    for curr_post_type in new_post_endpoints:
        path_base = path_base.replace('/' + curr_post_type, '')

    attach_str = 'Attach an image, video or audio file'
    new_post_image_section = begin_edit_section('📷 ' + translate[attach_str])
    new_post_image_section += \
        '      <input type="file" id="attachpic" name="attachpic"'
    formats_string = get_media_formats()
    new_post_image_section += \
        '            accept="' + formats_string + '">\n'
    new_post_image_section += \
        '    <label class="labels">' + \
        translate['Describe your attachment'] + '</label>\n'
    new_post_image_section += \
        '    <textarea id="imageDescription" name="imageDescription" ' + \
        'style="height:' + str(image_description_height) + \
        'px" spellcheck="true" autocomplete="on"></textarea>\n'
    media_creator_str = translate['Media creator']
    new_post_image_section += \
        edit_text_field(media_creator_str, 'mediaCreator', '', '')
    media_license_str = translate['Media license']
    new_post_image_section += \
        edit_text_field(media_license_str, 'mediaLicense',
                        '', 'CC-BY-NC')
    new_post_image_section += \
        '    <label class="labels">' + \
        translate['Transcript'] + ' (WebVTT)</label>\n'
    new_post_image_section += \
        '    <textarea id="videoTranscript" name="videoTranscript" ' + \
        'style="height:' + str(transcript_height) + \
        'px" spellcheck="true" autocomplete="on"></textarea>\n'
    new_post_image_section += end_edit_section()

    new_post_emoji_section = ''
    if not path.endswith('/newreadingstatus'):
        common_emoji_str = html_common_emoji(base_dir, 16)
        if common_emoji_str:
            new_post_emoji_section = \
                begin_edit_section('😀 ' + translate['Common emoji'])
            new_post_emoji_section += \
                '<label class="labels">' + \
                translate['Copy and paste into your text'] + '</label><br>\n'
            new_post_emoji_section += common_emoji_str
            new_post_emoji_section += end_edit_section()

    scope_icon = 'scope_public.png'
    scope_description = translate['Public']
    if share_description:
        if category == 'accommodation':
            placeholder_subject = translate['Request to stay']
        else:
            placeholder_subject = translate['Ask about a shared item.'] + '..'
    else:
        placeholder_subject = \
            translate['Subject or Content Warning (optional)'] + '...'
    placeholder_mentions = ''
    if in_reply_to:
        placeholder_mentions = \
            translate['Replying to'] + '...'
    placeholder_message = ''
    if category != 'accommodation':
        if default_timeline == 'tlfeatures':
            placeholder_message = translate['Write your news report'] + '...'
        else:
            placeholder_message = translate['Write something'] + '...'
    else:
        idx = 'Introduce yourself and specify the date ' + \
            'and time when you wish to stay'
        placeholder_message = translate[idx]
    extra_fields = ''
    premium = is_premium_account(base_dir, nickname, domain)
    endpoint = 'newpost'
    if path.endswith('/newblog'):
        placeholder_subject = translate['Title']
        scope_icon = 'scope_blog.png'
        if default_timeline != 'tlfeatures':
            scope_description = translate['Blog']
        else:
            scope_description = translate['Article']
        endpoint = 'newblog'
    elif path.endswith('/newunlisted'):
        scope_icon = 'scope_unlisted.png'
        scope_description = translate['Unlisted']
        endpoint = 'newunlisted'
    elif path.endswith('/newfollowers'):
        scope_icon = 'scope_followers.png'
        scope_description = translate['Followers']
        if premium:
            scope_description = translate['Fans']
        endpoint = 'newfollowers'
    elif path.endswith('/newdm'):
        scope_icon = 'scope_dm.png'
        scope_description = translate['DM']
        endpoint = 'newdm'
        placeholder_message = '⚠️ ' + translate['DM warning']
    elif is_new_reminder:
        scope_icon = 'scope_reminder.png'
        scope_description = translate['Reminder']
        endpoint = 'newreminder'
    elif path.endswith('/newreport'):
        scope_icon = 'scope_report.png'
        scope_description = translate['Report']
        endpoint = 'newreport'
    elif path.endswith('/newquestion'):
        scope_icon = 'scope_question.png'
        scope_description = translate['Question']
        placeholder_message = translate['Enter your question'] + '...'
        endpoint = 'newquestion'
        extra_fields = '<div class="container">\n'
        extra_fields += '  <label class="labels">' + \
            translate['Possible answers'] + ':</label><br>\n'
        for question_ctr in range(8):
            extra_fields += \
                '  <input type="text" class="questionOption" placeholder="' + \
                str(question_ctr + 1) + \
                '" name="questionOption' + str(question_ctr) + '"><br>\n'
        extra_fields += \
            '  <label class="labels">' + \
            translate['Duration of listing in days'] + \
            ':</label> <input type="number" name="duration" ' + \
            'min="1" max="365" step="1" value="14"><br>\n'
        extra_fields += '</div>'
    elif path.endswith('/newshare'):
        scope_icon = 'scope_share.png'
        scope_description = translate['Shared Item']
        placeholder_subject = translate['Name of the shared item'] + '...'
        placeholder_message = \
            translate['Description of the item being shared'] + '...'
        endpoint = 'newshare'
        extra_fields = '<div class="container">\n'
        extra_fields += \
            edit_number_field(translate['Quantity'],
                              'itemQty', 1, 1, 999999, 1)
        extra_fields += '<br>' + \
            edit_text_field(translate['Type of shared item. eg. hat'] + ':',
                            'itemType', '', '', True)
        category_types = get_category_types(base_dir)
        cat_str = translate['Category of shared item. eg. clothing']
        extra_fields += '<label class="labels">' + cat_str + '</label><br>\n'

        extra_fields += '  <select id="themeDropdown" ' + \
            'name="category" class="theme">\n'
        for cat in category_types:
            translated_category = "food"
            if translate.get(cat):
                translated_category = translate[cat]
            extra_fields += '    <option value="' + \
                translated_category + '">' + \
                translated_category + '</option>\n'

        extra_fields += '  </select><br>\n'
        extra_fields += \
            edit_number_field(translate['Duration of listing in days'],
                              'duration', 14, 1, 365, 1)
        extra_fields += '  <br>\n'
        extra_fields += \
            edit_check_box(translate['Display on your public profile'],
                           'shareOnProfile', False)
        extra_fields += '</div>\n'
        extra_fields += '<div class="container">\n'
        city_or_loc_str = translate['City or location of the shared item']
        extra_fields += edit_text_field(city_or_loc_str + ':', 'location',
                                        default_location,
                                        'https://www.openstreetmap.org/#map=')
        extra_fields += '</div>\n'
        extra_fields += '<div class="container">\n'
        extra_fields += \
            edit_currency_field(translate['Price'] + ':', 'itemPrice', '0.00',
                                '0.00', True)
        extra_fields += '<br>'
        extra_fields += \
            '<label class="labels">' + translate['Currency'] + '</label><br>\n'
        currencies = get_currencies()
        extra_fields += '  <select id="themeDropdown" ' + \
            'name="itemCurrency" class="theme">\n'
        currency_list: list[str] = []
        for symbol, curr_name in currencies.items():
            currency_list.append(curr_name + ' ' + symbol)
        currency_list.sort()
        default_currency = get_config_param(base_dir, 'defaultCurrency')
        if not default_currency:
            default_currency = "EUR"
        for curr_name in currency_list:
            if default_currency not in curr_name:
                extra_fields += '    <option value="' + \
                    curr_name + '">' + curr_name + '</option>\n'
            else:
                extra_fields += '    <option value="' + \
                    curr_name + '" selected="selected">' + \
                    curr_name + '</option>\n'
        extra_fields += '  </select>\n'

        extra_fields += '</div>\n'
    elif path.endswith('/newwanted'):
        scope_icon = 'scope_wanted.png'
        scope_description = translate['Wanted']
        placeholder_subject = translate['Name of the wanted item'] + '...'
        placeholder_message = \
            translate['Description of the item wanted'] + '...'
        endpoint = 'newwanted'
        extra_fields = '<div class="container">\n'
        extra_fields += \
            edit_number_field(translate['Quantity'],
                              'itemQty', 1, 1, 999999, 1)
        extra_fields += '<br>' + \
            edit_text_field(translate['Type of wanted item. eg. hat'] + ':',
                            'itemType', '', '', True)
        category_types = get_category_types(base_dir)
        cat_str = translate['Category of wanted item. eg. clothes']
        extra_fields += '<label class="labels">' + cat_str + '</label><br>\n'

        extra_fields += '  <select id="themeDropdown" ' + \
            'name="category" class="theme">\n'
        for cat in category_types:
            translated_category = "food"
            if translate.get(cat):
                translated_category = translate[cat]
            extra_fields += '    <option value="' + \
                translated_category + '">' + \
                translated_category + '</option>\n'

        extra_fields += '  </select><br>\n'
        extra_fields += \
            edit_number_field(translate['Duration of listing in days'],
                              'duration', 14, 1, 365, 1)
        extra_fields += '</div>\n'
        extra_fields += '<div class="container">\n'
        city_or_loc_str = translate['City or location of the wanted item']
        extra_fields += edit_text_field(city_or_loc_str + ':', 'location',
                                        default_location,
                                        'https://www.openstreetmap.org/#map=')
        extra_fields += '</div>\n'
        extra_fields += '<div class="container">\n'
        extra_fields += \
            edit_currency_field(translate['Maximum Price'] + ':',
                                'itemPrice', '0.00', '0.00', True)
        extra_fields += '<br>'
        extra_fields += \
            '<label class="labels">' + translate['Currency'] + '</label><br>\n'
        currencies = get_currencies()
        extra_fields += '  <select id="themeDropdown" ' + \
            'name="itemCurrency" class="theme">\n'
        currency_list: list[str] = []
        for symbol, curr_name in currencies.items():
            currency_list.append(curr_name + ' ' + symbol)
        currency_list.sort()
        default_currency = get_config_param(base_dir, 'defaultCurrency')
        if not default_currency:
            default_currency = "EUR"
        for curr_name in currency_list:
            if default_currency not in curr_name:
                extra_fields += '    <option value="' + \
                    curr_name + '">' + curr_name + '</option>\n'
            else:
                extra_fields += '    <option value="' + \
                    curr_name + '" selected="selected">' + \
                    curr_name + '</option>\n'
        extra_fields += '  </select>\n'

        extra_fields += '</div>\n'
    elif path.endswith('/newreadingstatus'):
        scope_icon = 'scope_readingstatus.png'
        scope_description = translate['Reading Status']
        endpoint = 'newreadingstatus'

        extra_fields = '<div class="container">\n'
        cat_str = translate['Update type']
        extra_fields += '<label class="labels">' + cat_str + '</label><br>\n'

        extra_fields += '  <select id="readingUpdateTypeDropdown" ' + \
            'name="readingupdatetype" class="theme">\n'
        extra_fields += '    <option value="readingupdatewant">' + \
            translate['want to read'] + '</option>\n'
        extra_fields += '    <option value="readingupdateread" ' + \
            'selected="selected">' + \
            translate['am reading'] + '</option>\n'
        extra_fields += '    <option value="readingupdatefinished">' + \
            translate['finished reading'] + '</option>\n'
        extra_fields += '    <option value="readingupdaterating">' + \
            translate['add a rating'] + '</option>\n'
        extra_fields += '  </select><br>\n'

        extra_fields += '<br>' + \
            edit_text_field(translate['Title'] + ':',
                            'booktitle', '', '', True)
        books_url = 'https://en.wikipedia.org/wiki/Lists_of_books'
        extra_fields += '<br>' + \
            edit_text_field('<a href="' + books_url +
                            '" target="_blank" ' +
                            'rel="nofollow noopener noreferrer">URL</a>:',
                            'bookurl', '', 'https://...', True)
        extra_fields += '<br>' + \
            edit_number_field(translate['Rating'],
                              'bookrating', '', 1, 5, None)
        extra_fields += '</div>\n'

    citations_str = ''
    if endpoint == 'newblog':
        citations_filename = \
            acct_dir(base_dir, nickname, domain) + '/.citations.txt'
        if os.path.isfile(citations_filename):
            citations_str = '<div class="container">\n'
            citations_str += '<p><label class="labels">' + \
                translate['Citations'] + ':</label></p>\n'
            citations_str += '  <ul>\n'
            citations_separator = '#####'
            citations: list[str] = []
            try:
                with open(citations_filename, 'r',
                          encoding='utf-8') as fp_cit:
                    citations = fp_cit.readlines()
            except OSError as exc:
                print('EX: html_new_post unable to read ' +
                      citations_filename + ' ' + str(exc))
            for line in citations:
                if citations_separator not in line:
                    continue
                sections = line.strip().split(citations_separator)
                if len(sections) != 3:
                    continue
                title = sections[1]
                link = sections[2]
                citations_str += \
                    '    <li><a href="' + link + '"><cite>' + \
                    title + '</cite></a></li>'
            citations_str += '  </ul>\n'
            citations_str += '</div>\n'

    replies_section = ''
    date_and_location = ''
    if endpoint not in ('newshare', 'newwanted', 'newreport',
                        'newquestion', 'newreadingstatus'):

        if not is_new_reminder:
            replies_section = \
                '<div class="container">\n'
            if category != 'accommodation':
                replies_section += \
                    '<p><input type="checkbox" class="profilecheckbox" ' + \
                    'name="commentsEnabled" ' + \
                    'checked><label class="labels"> ' + \
                    translate['Allow replies.'] + '</label></p>\n'
                if endpoint == 'newpost':
                    replies_section += \
                        '<p><input type="checkbox" ' + \
                        'class="profilecheckbox" ' + \
                        'name="pinToProfile"><label class="labels"> ' + \
                        translate['Pin this post to your profile.'] + \
                        '</label></p>\n'
            else:
                replies_section += \
                    '<input type="hidden" name="commentsEnabled" ' + \
                    'value="true">\n'

            # Language used dropdown
            supported_languages = get_supported_languages(base_dir)
            languages_dropdown = '<br>\n<select id="themeDropdown" ' + \
                'name="languagesDropdown" class="theme">'
            for lang_name in supported_languages:
                translated_lang_name = lang_name
                if translate.get('lang_' + lang_name):
                    translated_lang_name = translate['lang_' + lang_name]
                languages_dropdown += '    <option value="' + \
                    lang_name.lower() + '">' + \
                    translated_lang_name + '</option>\n'
            languages_dropdown += '  </select>'
            languages_dropdown = \
                languages_dropdown.replace('<option value="' +
                                           default_post_language + '">',
                                           '<option value="' +
                                           default_post_language +
                                           '" selected>')
            replies_section += '<br>\n' + \
                '      <label class="labels">' + \
                translate['Language used'] + '</label>\n'
            replies_section += languages_dropdown

            # searchable by dropdown
            if endpoint != 'newdm':
                searchables = {
                    'yourself': translate['Yourself'],
                    'public': translate['Public'],
                    'followers': translate['Followers'],
                    'mutuals': translate['Mutuals']
                }
                searchable_by_dropdown = '<select id="themeDropdown" ' + \
                    'name="searchableByDropdown" class="theme">\n'
                if not searchable_by_default:
                    searchable_by_default = 'yourself'
                for srch, srch_text in searchables.items():
                    if srch != searchable_by_default:
                        searchable_by_dropdown += \
                            '    <option value="' + srch + '">' + \
                            srch_text + '</option>\n'
                    else:
                        searchable_by_dropdown += \
                            '    <option value="' + srch + '" selected="">' + \
                            srch_text + '</option>\n'
                replies_section += '<br>\n' + \
                    '      <label class="labels">🔎 ' + \
                    translate['Searchable by'] + '</label>\n'
                replies_section += \
                    searchable_by_dropdown + '</select>\n'

            # buy link
            buy_link_str = translate['Buy link']
            replies_section += \
                '<br>\n' + edit_text_field(buy_link_str, 'buyUrl',
                                           default_buy_site, 'https://...')
            chat_link_str = '💬 ' + translate['Chat link']
            replies_section += \
                '<br>\n' + edit_text_field(chat_link_str, 'chatUrl',
                                           '', 'https://...')
            replies_section += '</div>\n'

            date_and_location = \
                begin_edit_section('🗓️ ' + translate['Set a place and time'])

            if not in_reply_to:
                date_and_location += \
                    '<p><input type="checkbox" class="profilecheckbox" ' + \
                    'name="schedulePost"><label class="labels"> ' + \
                    translate['This is a scheduled post.'] + '</label></p>\n'

            date_and_location += date_and_time_str

        maps_url = get_map_preferences_url(base_dir, nickname, domain)
        if not maps_url:
            maps_url = 'https://www.openstreetmap.org'
        if '://' not in maps_url:
            maps_url = 'https://' + maps_url
        maps_latitude, maps_longitude, maps_zoom = \
            get_map_preferences_coords(base_dir, nickname, domain)
        if maps_latitude and maps_longitude and maps_zoom:
            if 'openstreetmap.org' in maps_url:
                maps_url = \
                    'https://www.openstreetmap.org/#map=' + \
                    str(maps_zoom) + '/' + \
                    str(maps_latitude) + '/' + \
                    str(maps_longitude)
            elif '.google.co' in maps_url:
                maps_url = \
                    'https://www.google.com/maps/@' + \
                    str(maps_latitude) + ',' + \
                    str(maps_longitude) + ',' + \
                    str(maps_zoom) + 'z'
            elif '.bing.co' in maps_url:
                maps_url = \
                    'https://www.bing.com/maps?cp=' + \
                    str(maps_latitude) + '~' + \
                    str(maps_longitude) + '&amp;lvl=' + \
                    str(maps_zoom)
            elif '.waze.co' in maps_url:
                maps_url = \
                    'https://ul.waze.com/ul?ll=' + \
                    str(maps_latitude) + '%2C' + \
                    str(maps_longitude) + '&zoom=' + \
                    str(maps_zoom)
            elif 'wego.here.co' in maps_url:
                maps_url = \
                    'https://wego.here.com/?x=ep&map=' + \
                    str(maps_latitude) + ',' + \
                    str(maps_longitude) + ',' + \
                    str(maps_zoom) + ',normal'
        location_label_with_link = \
            '<a href="' + maps_url + '" ' + \
            'rel="nofollow noopener noreferrer" target="_blank">🗺️ ' + \
            translate['Location'] + '</a>'
        date_and_location += '<br><p>\n' + \
            edit_text_field(location_label_with_link, 'location',
                            default_location,
                            'https://www.openstreetmap.org/#map=') + '</p>\n'
        date_and_location += end_edit_section()

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images = [banner_path]
    new_post_form = html_header_with_external_style(css_filename,
                                                    instance_title, None,
                                                    preload_images)

    new_post_form += \
        '<header>\n' + \
        '<a href="/users/' + nickname + '/' + default_timeline + \
        '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '">\n'
    new_post_form += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" src="' + \
        banner_path + '" alt="" /></a>\n' + \
        '</header>\n'

    mentions_str = ''
    for ment in mentions:
        mention_nickname = get_nickname_from_actor(ment)
        if not mention_nickname:
            continue
        mention_domain, mention_port = get_domain_from_actor(ment)
        if not mention_domain:
            continue
        if mention_port:
            mentions_handle = \
                '@' + mention_nickname + '@' + \
                mention_domain + ':' + str(mention_port)
        else:
            mentions_handle = '@' + mention_nickname + '@' + mention_domain
        if mentions_handle not in mentions_str:
            mentions_str += mentions_handle + ' '

    # build suffixes so that any replies or mentions are
    # preserved when switching between scopes
    dropdown_new_post_suffix = '/newpost'
    dropdown_new_blog_suffix = '/newblog'
    dropdown_unlisted_suffix = '/newunlisted'
    dropdown_followers_suffix = '/newfollowers'
    dropdown_dm_suffix = '/newdm'
    dropdown_reminder_suffix = '/newreminder'
    dropdown_report_suffix = '/newreport'
    if in_reply_to or mentions:
        dropdown_new_post_suffix = ''
        dropdown_new_blog_suffix = ''
        dropdown_unlisted_suffix = ''
        dropdown_followers_suffix = ''
        dropdown_dm_suffix = ''
        dropdown_reminder_suffix = ''
        dropdown_report_suffix = ''
    if in_reply_to:
        dropdown_new_post_suffix += '?replyto=' + in_reply_to
        dropdown_new_blog_suffix += '?replyto=' + in_reply_to
        dropdown_unlisted_suffix += '?replyunlisted=' + in_reply_to
        dropdown_followers_suffix += '?replyfollowers=' + in_reply_to
        if reply_is_chat:
            dropdown_dm_suffix += '?replychat=' + in_reply_to
        else:
            dropdown_dm_suffix += '?replydm=' + in_reply_to
    for mentioned_actor in mentions:
        dropdown_new_post_suffix += '?mention=' + mentioned_actor
        dropdown_new_blog_suffix += '?mention=' + mentioned_actor
        dropdown_unlisted_suffix += '?mention=' + mentioned_actor
        dropdown_followers_suffix += '?mention=' + mentioned_actor
        dropdown_dm_suffix += '?mention=' + mentioned_actor
        dropdown_report_suffix += '?mention=' + mentioned_actor
    if conversation_id and in_reply_to:
        if isinstance(conversation_id, str):
            dropdown_new_post_suffix += '?conversationId=' + conversation_id
            dropdown_new_blog_suffix += '?conversationId=' + conversation_id
            dropdown_unlisted_suffix += '?conversationId=' + conversation_id
            dropdown_followers_suffix += '?conversationId=' + conversation_id
            dropdown_dm_suffix += '?conversationId=' + conversation_id
    if convthread_id and in_reply_to:
        if isinstance(convthread_id, str):
            dropdown_new_post_suffix += '?convthreadId=' + convthread_id
            dropdown_new_blog_suffix += '?convthreadId=' + convthread_id
            dropdown_unlisted_suffix += '?convthreadId=' + convthread_id
            dropdown_followers_suffix += '?convthreadId=' + convthread_id
            dropdown_dm_suffix += '?convthreadId=' + convthread_id

    drop_down_content = ''
    if not report_url and not share_description:
        account_dir = acct_dir(base_dir, nickname, domain)
        drop_down_content = \
            _html_new_post_drop_down(scope_icon, scope_description,
                                     reply_str,
                                     translate,
                                     show_public_on_dropdown,
                                     default_timeline,
                                     path_base,
                                     dropdown_new_post_suffix,
                                     dropdown_new_blog_suffix,
                                     dropdown_unlisted_suffix,
                                     dropdown_followers_suffix,
                                     dropdown_dm_suffix,
                                     dropdown_reminder_suffix,
                                     dropdown_report_suffix,
                                     no_drop_down, access_keys,
                                     account_dir, premium)
    else:
        if not share_description:
            # reporting a post to moderator
            mentions_str = 'Re: ' + report_url + '\n\n' + mentions_str

    if edited_post_json:
        new_post_form += \
            '<form enctype="multipart/form-data" method="POST" ' + \
            'accept-charset="UTF-8" action="' + \
            path + '?' + endpoint + '?page=' + str(page_number) + \
            '?editid=' + edit_post_params['post_url'] + \
            '?editpub=' + edited_published + '">\n'
    else:
        new_post_form += \
            '<form enctype="multipart/form-data" method="POST" ' + \
            'accept-charset="UTF-8" action="' + \
            path + '?' + endpoint + '?page=' + str(page_number) + '">\n'
    if reply_is_chat:
        new_post_form += \
            '    <input type="hidden" name="replychatmsg" value="yes">\n'
    if conversation_id:
        if isinstance(conversation_id, str):
            new_post_form += \
                '    <input type="hidden" name="conversationId" value="' + \
                conversation_id + '">\n'
    new_post_form += '  <div class="vertical-center">\n'
    new_post_form += \
        '    <label for="nickname"><b>' + new_post_text + '</b></label>\n'
    new_post_form += '    <div class="containerNewPost">\n'
    new_post_form += '      <table style="width:100%" border="0">\n'
    new_post_form += '        <colgroup>\n'
    new_post_form += '          <col span="1" style="width:70%">\n'
    new_post_form += '          <col span="1" style="width:10%">\n'
    if newswire and path.endswith('/newblog'):
        new_post_form += '          <col span="1" style="width:10%">\n'
        new_post_form += '          <col span="1" style="width:10%">\n'
    else:
        new_post_form += '          <col span="1" style="width:20%">\n'
    new_post_form += '        </colgroup>\n'
    new_post_form += '<tr>\n'
    new_post_form += '<td>' + drop_down_content + '</td>\n'

    new_post_form += \
        '      <td><a href="' + path_base + \
        '/searchemoji"><img loading="lazy" decoding="async" ' + \
        'class="emojisearch" src="/emoji/1F601.png" title="' + \
        translate['Search for emoji'] + '" alt="' + \
        translate['Search for emoji'] + '"/></a></td>\n'

    # for a new blog if newswire items exist then add a citations button
    if newswire and path.endswith('/newblog'):
        new_post_form += \
            '      <td><input type="submit" name="submitCitations" value="' + \
            translate['Citations'] + '"></td>\n'

    if not path.endswith('/newdm') and \
       not path.endswith('/newreport'):
        submit_text = translate['Publish']
    else:
        submit_text = translate['Send']
    if custom_submit_text:
        submit_text = custom_submit_text
    new_post_form += \
        '      <td><input type="submit" name="submitPost" value="' + \
        submit_text + '" ' + \
        'accesskey="' + access_keys['submitButton'] + '"></td>\n'

    new_post_form += '      </tr>\n</table>\n'
    new_post_form += '    </div>\n'

    new_post_form += '    <div class="containerSubmitNewPost"><center>\n'

    new_post_form += '    </center></div>\n'

    new_post_form += reply_str
    if media_instance and not reply_str:
        new_post_form += new_post_image_section

    # for reminders show the date and time at the top
    if is_new_reminder:
        new_post_form += '<div class="containerNoOverflow">\n'
        new_post_form += date_and_time_str
        new_post_form += '</div>\n'

    if endpoint != 'newreadingstatus':
        new_post_form += \
            edit_text_field(placeholder_subject, 'subject', default_subject)
        new_post_form += ''

    selected_str = ' selected'
    if in_reply_to or endpoint == 'newdm':
        if in_reply_to:
            new_post_form += \
                '    <br><label class="labels">' + placeholder_mentions + \
                '</label><br>\n'
        else:
            new_post_form += \
                '    <br><a href="/users/' + nickname + \
                '/followingaccounts" title="' + \
                translate['Show a list of addresses to send to'] + '">' \
                '<label class="labels">' + \
                translate['Send to'] + ':' + '</label> 📄</a><br>\n'
        new_post_form += \
            '    <input type="text" name="mentions" ' + \
            'list="followingHandles" value="' + mentions_str + '" selected>\n'
        new_post_form += \
            html_following_data_list(base_dir, nickname, domain, domain_full,
                                     'following', True)
        new_post_form += ''
        selected_str = ''

    if endpoint != 'newreadingstatus':
        new_post_form += \
            '    <br><label class="labels">' + placeholder_message + '</label>'
    if media_instance:
        message_box_height = 200

    if endpoint == 'newquestion':
        message_box_height = 100
    elif endpoint == 'newblog':
        message_box_height = 800

    # get the default message text
    default_message = ''
    if edited_post_json:
        content_str = \
            get_content_from_post(edited_post_json, system_language,
                                  languages_understood, "content")
        if content_str:
            default_message = remove_html(content_str)
            default_message = \
                _remove_initial_mentions_from_content(default_message)

    if endpoint != 'newreadingstatus':
        new_post_form += \
            '    <textarea id="message" name="message" style="height:' + \
            str(message_box_height) + 'px"' + selected_str + \
            ' spellcheck="true" autocomplete="on">' + \
            default_message + '</textarea>\n'
    new_post_form += \
        extra_fields + citations_str + replies_section + date_and_location
    if not media_instance or reply_str:
        new_post_form += new_post_image_section
    new_post_form += new_post_emoji_section

    new_post_form += \
        '    <div class="container">\n' + \
        '      <input type="submit" name="submitPost" value="' + \
        submit_text + '">\n' + \
        '    </div>\n' + \
        '  </div>\n' + \
        '</form>\n'

    new_post_form += html_footer()
    return new_post_form
