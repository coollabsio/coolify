__filename__ = "webapp_post.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
import time
import urllib.parse
from dateutil.parser import parse
from auth import create_password
from git import is_git_patch
from cache import get_person_from_cache
from bookmarks import bookmark_from_id
from bookmarks import bookmarked_by_person
from announce import announced_by_person
from announce import no_of_announces
from like import liked_by_person
from like import no_of_likes
from follow import is_following_actor
from posts import post_is_muted
from posts import get_person_box
from posts import download_announce
from posts import populate_replies_json
from flags import is_editor
from flags import is_reminder
from flags import is_public_post
from flags import is_followers_post
from flags import is_unlisted_post
from flags import is_blog_post
from flags import is_news_post
from flags import is_recent_post
from flags import is_chat_message
from flags import is_pgp_encrypted
from utils import save_json
from utils import text_mode_removals
from utils import remove_header_tags
from utils import get_actor_from_post_id
from utils import contains_statuses
from utils import data_dir
from utils import get_quote_toot_url
from utils import get_post_attachments
from utils import get_url_from_post
from utils import date_from_string_format
from utils import remove_markup_tag
from utils import ap_proxy_type
from utils import remove_style_within_html
from utils import license_link_from_name
from utils import dont_speak_hashtags
from utils import remove_eol
from utils import disallow_announce
from utils import disallow_reply
from utils import convert_published_to_local_timezone
from utils import remove_hash_from_post_id
from utils import remove_html
from utils import get_actor_languages_list
from utils import get_base_content_from_post
from utils import get_content_from_post
from utils import get_language_from_post
from utils import get_summary_from_post
from utils import has_object_dict
from utils import update_announce_collection
from utils import is_dm
from utils import reject_post_id
from utils import get_config_param
from utils import get_full_domain
from utils import locate_post
from utils import load_json
from utils import get_cached_post_directory
from utils import get_cached_post_filename
from utils import get_protocol_prefixes
from utils import get_display_name
from utils import display_name_is_emoji
from utils import update_recent_posts_cache
from utils import remove_id_ending
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import acct_dir
from utils import local_actor_url
from utils import language_right_to_left
from utils import get_attributed_to
from utils import get_reply_to
from utils import get_actor_from_post
from utils import resembles_url
from content import remove_link_trackers_from_content
from content import format_mixed_right_to_left
from content import replace_remote_hashtags
from content import detect_dogwhistles
from content import create_edits_html
from content import bold_reading_string
from content import limit_repeated_words
from content import replace_emoji_from_tags
from content import html_replace_quote_marks
from content import html_replace_email_quote
from content import remove_text_formatting
from content import remove_long_words
from content import get_mentions_from_html
from content import switch_words
from content import add_auto_cw
from person import is_person_snoozed
from person import get_person_avatar_url
from webapp_utils import mitm_warning_html
from webapp_utils import text_mode_browser
from webapp_utils import get_buy_links
from webapp_utils import get_banner_file
from webapp_utils import get_avatar_image_url
from webapp_utils import update_avatar_image_cache
from webapp_utils import load_individual_post_as_html_from_cache
from webapp_utils import add_emoji_to_display_name
from webapp_utils import post_contains_public
from webapp_utils import get_content_warning_button
from webapp_utils import get_post_attachments_as_html
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_broken_link_substitute
from webapp_media import add_embedded_elements
from webapp_question import insert_question
from webfinger import webfinger_handle
from speaker import update_speaker
from languages import auto_translate_post
from cwlists import add_cw_from_lists
from blocking import is_blocked
from blocking import sending_is_blocked2
from reaction import html_emoji_reactions
from maps import html_open_street_map
from maps import set_map_preferences_coords
from maps import set_map_preferences_url
from maps import geocoords_from_map_link
from maps import get_location_from_post
from session import get_json_valid
from session import get_json

# maximum length for display name within html posts
MAX_DISPLAY_NAME_LENGTH = 42


def _get_instance_software_html(title_str: str, software_name: str) -> str:
    """Returns the html displaying the type of software
    such as mastodon, epicyon or pixelfed
    """
    if not software_name:
        return ''
    if software_name in title_str:
        return ''
    instance_str = \
        '<br><label class="instanceSoftware">' + \
        '<span itemprop="software">' + \
        software_name + '</span></label>\n'
    return instance_str


def get_instance_software(base_dir: str, session,
                          instance_http_prefix: str,
                          instance_domain: str,
                          instance_software: {},
                          signing_priv_key_pem: str,
                          debug: bool,
                          http_prefix: str, domain: str,
                          mitm_servers: [],
                          store: bool) -> str:
    """returns the type of instance software for the given
    instance domain eg. mastodon, epicyon, pixelfed
    """
    instance_domain, _ = get_domain_from_actor(instance_domain)
    if debug:
        print('DEBUG get_instance_software: ' + instance_domain)
    if instance_software.get(instance_domain):
        return instance_domain + ' ' + instance_software[instance_domain]
    # get the initial nodeinfo url
    nodeinfo1_url = \
        instance_http_prefix + '://' + instance_domain + \
        '/.well-known/nodeinfo'
    profile_str = 'https://www.w3.org/ns/activitystreams'
    headers = {
        'Accept': 'application/ld+json; profile="' + profile_str + '"'
    }
    nodeinfo1_json = \
        get_json(signing_priv_key_pem,
                 session, nodeinfo1_url,
                 headers, None, debug, mitm_servers,
                 __version__, http_prefix, domain)
    if not get_json_valid(nodeinfo1_json):
        return ''
    if debug:
        print('DEBUG get_instance_software: ' + str(nodeinfo1_json))
    # get the nodeinfo data
    nodeinfo_url = None
    if nodeinfo1_json.get('links'):
        if isinstance(nodeinfo1_json['links'], list):
            if len(nodeinfo1_json['links']) > 0:
                if nodeinfo1_json['links'][0].get('href'):
                    href = nodeinfo1_json['links'][0]['href']
                    if isinstance(href, str):
                        nodeinfo_url = href
    if not nodeinfo_url:
        return ''
    nodeinfo_json = \
        get_json(signing_priv_key_pem,
                 session, nodeinfo_url,
                 headers, None, debug, mitm_servers,
                 __version__, http_prefix, domain)
    if not get_json_valid(nodeinfo_json):
        return ''
    if debug:
        print('DEBUG get_instance_software: ' + str(nodeinfo_json))
    if not nodeinfo_json.get('software'):
        return ''
    if not isinstance(nodeinfo_json['software'], dict):
        return ''
    if not nodeinfo_json['software'].get('name'):
        return ''
    software_name = nodeinfo_json['software']['name']
    if not isinstance(software_name, str):
        return ''
    software_name = remove_html(software_name)
    instance_software[instance_domain] = software_name
    if store:
        instance_software_filename = \
            data_dir(base_dir) + '/instance_software.json'
        save_json(instance_software, instance_software_filename)
    return instance_domain + ' ' + software_name


def _enforce_max_display_name_length(display_name: str) -> str:
    """Ensures that the display name does not get too long
    """
    # enforce maximum length for the display name
    if len(display_name) <= MAX_DISPLAY_NAME_LENGTH:
        return display_name

    if ':' in display_name:
        display_name_short = display_name.split(':')[0].strip()
        if len(display_name_short) > 2:
            display_name = display_name_short

    if len(display_name) > MAX_DISPLAY_NAME_LENGTH:
        display_name = display_name[:MAX_DISPLAY_NAME_LENGTH]

    return display_name


def _html_post_metadata_open_graph(domain: str, post_json_object: {},
                                   system_language: str) -> str:
    """Returns html OpenGraph metadata for a post
    """
    metadata = \
        "    <link rel=\"schema.DC\" " + \
        "href=\"http://purl.org/dc/elements/1.1/\" />\n"
    metadata += \
        "    <link rel=\"schema.DCTERMS\" " + \
        "href=\"http://purl.org/dc/terms/\" />\n"
    metadata += \
        "    <meta content=\"" + domain + "\" property=\"og:site_name\" />\n"
    metadata += \
        "    <meta content=\"article\" property=\"og:type\" />\n"
    obj_json = post_json_object
    if has_object_dict(post_json_object):
        obj_json = post_json_object['object']
    if obj_json.get('id'):
        metadata += "    <meta name=\"DC.identifier\" " + \
            "scheme=\"DCTERMS.URI\" content=\"" + obj_json['id'] + "\">\n"
    if obj_json.get('summary'):
        metadata += "    <meta name=\"DC.title\" " + \
            "content=\"" + obj_json['summary'] + "\">\n"
    if obj_json.get('attributedTo'):
        attrib_str = get_attributed_to(obj_json['attributedTo'])
        if attrib_str:
            attrib = attrib_str
            actor_nick = get_nickname_from_actor(attrib)
            actor_domain, _ = get_domain_from_actor(attrib)
            if actor_nick and actor_domain:
                actor_handle = actor_nick + '@' + actor_domain
                metadata += \
                    "    <meta name=\"DC.creator\" " + \
                    "scheme=\"DCTERMS.URI\" content=\"" + \
                    attrib + "\">\n"
                metadata += \
                    "    <meta content=\"@" + actor_handle + \
                    "\" property=\"og:title\" />\n"
    if obj_json.get('url'):
        url_str = get_url_from_post(obj_json['url'])
        obj_url = remove_html(url_str)
        metadata += \
            "    <meta content=\"" + obj_url + \
            "\" property=\"og:url\" />\n"
    if obj_json.get('published'):
        metadata += "    <meta name=\"DC.date\" " + \
            "scheme=\"DCTERMS.W3CDTF\" content=\"" + \
            obj_json['published'] + "\">\n"
        metadata += \
            "    <meta content=\"" + obj_json['published'] + \
            "\" property=\"og:published_time\" />\n"
    post_attachments = get_post_attachments(obj_json)
    if not post_attachments or obj_json.get('sensitive'):
        if 'content' in obj_json and not obj_json.get('sensitive'):
            obj_content = obj_json['content']
            if 'contentMap' in obj_json:
                if obj_json['contentMap'].get(system_language):
                    obj_content = obj_json['contentMap'][system_language]
            description = remove_html(obj_content)
            metadata += \
                "    <meta content=\"" + description + \
                "\" name=\"description\">\n"
            metadata += \
                "    <meta content=\"" + description + \
                "\" name=\"og:description\">\n"
        return metadata

    # metadata for attachment
    for attach_json in post_attachments:
        if not isinstance(attach_json, dict):
            continue
        if not attach_json.get('mediaType'):
            continue
        if not attach_json.get('url'):
            continue
        if not attach_json.get('name'):
            continue
        description = None
        if attach_json['mediaType'].startswith('image/'):
            description = 'Attached: 1 image'
        elif attach_json['mediaType'].startswith('video/'):
            description = 'Attached: 1 video'
        elif attach_json['mediaType'].startswith('audio/'):
            description = 'Attached: 1 audio'
        if description:
            if 'content' in obj_json and not obj_json.get('sensitive'):
                obj_content = obj_json['content']
                if 'contentMap' in obj_json:
                    if obj_json['contentMap'].get(system_language):
                        obj_content = obj_json['contentMap'][system_language]
                description += '\n\n' + remove_html(obj_content)
            metadata += \
                "    <meta content=\"" + description + \
                "\" name=\"description\">\n"
            metadata += \
                "    <meta content=\"" + description + \
                "\" name=\"og:description\">\n"
            url_str = get_url_from_post(attach_json['url'])
            attach_url = remove_html(url_str)
            metadata += \
                "    <meta content=\"" + attach_url + \
                "\" property=\"og:image\" />\n"
            metadata += \
                "    <meta content=\"" + attach_json['mediaType'] + \
                "\" property=\"og:image:type\" />\n"
            if attach_json.get('width'):
                metadata += \
                    "    <meta content=\"" + str(attach_json['width']) + \
                    "\" property=\"og:image:width\" />\n"
            if attach_json.get('height'):
                metadata += \
                    "    <meta content=\"" + str(attach_json['height']) + \
                    "\" property=\"og:image:height\" />\n"
            metadata += \
                "    <meta content=\"" + attach_json['name'] + \
                "\" property=\"og:image:alt\" />\n"
            if attach_json['mediaType'].startswith('image/'):
                metadata += \
                    "    <meta content=\"summary_large_image\" " + \
                    "property=\"twitter:card\" />\n"
    return metadata


def _log_post_timing(enable_timing_log: bool, post_start_time,
                     debug_id: str) -> None:
    """Create a log of timings for performance tuning
    """
    if not enable_timing_log:
        return
    time_diff = int((time.time() - post_start_time) * 1000)
    if time_diff > 100:
        print('TIMING INDIV ' + debug_id + ' = ' + str(time_diff))


def prepare_html_post_nickname(nickname: str, post_html: str) -> str:
    """html posts stored in memory are for all accounts on the instance
    and they're indexed by id. However, some incoming posts may be
    destined for multiple accounts (followers). This creates a problem
    where the icon links whose urls begin with href="/users/nickname?
    need to be changed for different nicknames to display correctly
    within their timelines.
    This function changes the nicknames for the icon links.
    """
    # replace the nickname
    users_str = ' href="/users/'
    if users_str not in post_html:
        return post_html

    user_found = True
    post_str = post_html
    new_post_str = ''
    while user_found:
        if users_str not in post_str:
            new_post_str += post_str
            break

        # the next part, after href="/users/nickname?
        next_str = post_str.split(users_str, 1)[1]
        if '?' in next_str:
            next_str = next_str.split('?', 1)[1]
        else:
            new_post_str += post_str
            break

        # append the previous text to the result
        new_post_str += post_str.split(users_str)[0]
        new_post_str += users_str + nickname + '?'

        # post is now the next part
        post_str = next_str
    return new_post_str


def replace_link_variable(link: str, variable_name: str, value: str,
                          separator: str) -> str:
    """Replaces a variable within the given link
    """
    full_var = separator + variable_name + '='
    if full_var not in link:
        return link

    curr_str = link
    result = ''
    while full_var in curr_str:
        prefix = curr_str.split(full_var, 1)[0] + full_var
        next_str = curr_str.split(full_var, 1)[1]
        if separator in next_str:
            next_str = next_str.split(separator, 1)[1]
            result += prefix + value + separator
            curr_str = next_str
        else:
            result += prefix + value
            curr_str = ''
    return result + curr_str


def _prepare_media_post_from_html_cache(post_html: str,
                                        translate: {},
                                        media_type: str) -> str:
    """ replaces the <video> or <audio> tag to make it more friendly for
    text mode browsers, so that Lynx doesn't just say something like
    'this tag is not supported in your browser'
    """
    sections = post_html.split('<' + media_type)
    new_post_html = ''
    for section_str in sections:
        ending_tag = '</' + media_type + '>'
        if ending_tag not in section_str:
            new_post_html += section_str
            continue
        markup = section_str.split(ending_tag)[0]
        ending = section_str.split(ending_tag)[1]
        url = ''
        description = ''
        # get the video/audio url if it exists
        if ' src="' in markup:
            url = markup.split(' src="')[1]
            if '"' in url:
                url = url.split('"')[0]
        if url:
            # get the video/audio description if it exists
            if ' alt="' in markup:
                description = markup.split(' alt="')[1]
                if '"' in description:
                    description = description.split('"')[0]
            if not description:
                description = '[' + translate['Media'].upper() + ']'
            new_post_html += \
                '<a href="' + url + '" target="_blank" ' + \
                'rel="nofollow noopener noreferrer">' + \
                description + '</a><br>'
        else:
            new_post_html += '<' + media_type + markup
        new_post_html += ending
    return new_post_html


def prepare_post_from_html_cache(nickname: str, post_html: str, box_name: str,
                                 page_number: int, first_post_id: str,
                                 ua_str: str, translate: {}) -> str:
    """Sets the page number on a cached html post
    """

    # ensure that media is playable from a text mode browser
    if text_mode_browser(ua_str):
        if '<video' in post_html:
            post_html = _prepare_media_post_from_html_cache(post_html,
                                                            translate,
                                                            'video')
        if '<audio' in post_html:
            post_html = _prepare_media_post_from_html_cache(post_html,
                                                            translate,
                                                            'audio')
        # replace MITM text with an eye icon
        post_html = text_mode_removals(post_html, translate)

    # if on the bookmarks timeline then remain there
    if box_name in ('tlbookmarks', 'bookmarks'):
        post_html = post_html.replace('?tl=inbox', '?tl=tlbookmarks')
        if '?page=' in post_html:
            page_number_str = post_html.split('?page=')[1]
            if '?' in page_number_str:
                page_number_str = page_number_str.split('?')[0]
            post_html = \
                post_html.replace('?page=' + page_number_str, '?page=-999')

    # add the page number
    with_page_number = \
        post_html.replace(';-999;', ';' + str(page_number) + ';')
    with_page_number = \
        with_page_number.replace('?page=-999', '?page=' + str(page_number))

    # add first post in the timeline
    if first_post_id is None:
        first_post_id = ''

    first_post_id = first_post_id.replace('#', '/')
    if '?firstpost=' in with_page_number:
        with_page_number = \
            replace_link_variable(with_page_number,
                                  'firstpost', first_post_id, '?')
    elif ';firstpost=' in with_page_number:
        with_page_number = \
            replace_link_variable(with_page_number,
                                  'firstpost', first_post_id, ';')
    else:
        with_page_number = \
            with_page_number.replace('?page=',
                                     '?firstpost=' + first_post_id +
                                     '?page=')
    return prepare_html_post_nickname(nickname, with_page_number)


def _save_individual_post_as_html_to_cache(base_dir: str,
                                           nickname: str, domain: str,
                                           post_json_object: {},
                                           post_html: str) -> bool:
    """Saves the given html for a post to a cache file
    This is so that it can be quickly reloaded on subsequent
    refresh of the timeline
    """
    html_post_cache_dir = \
        get_cached_post_directory(base_dir, nickname, domain)
    cached_post_filename = \
        get_cached_post_filename(base_dir, nickname, domain, post_json_object)
    if not cached_post_filename:
        return False

    # create the cache directory if needed
    if not os.path.isdir(html_post_cache_dir):
        os.mkdir(html_post_cache_dir)

    try:
        with open(cached_post_filename, 'w+', encoding='utf-8') as fp_cache:
            fp_cache.write(post_html)
            return True
    except OSError as ex:
        print('ERROR: saving post to cache, ' + str(ex))
    return False


def _get_post_from_recent_cache(session,
                                base_dir: str,
                                http_prefix: str,
                                nickname: str, domain: str,
                                post_json_object: {},
                                post_actor: str,
                                person_cache: {},
                                allow_downloads: bool,
                                show_public_only: bool,
                                store_to_cache: bool,
                                box_name: str,
                                avatar_url: str,
                                enable_timing_log: bool,
                                post_start_time,
                                page_number: int,
                                recent_posts_cache: {},
                                max_recent_posts: int,
                                signing_priv_key_pem: str,
                                first_post_id: str,
                                ua_str: str,
                                translate: {},
                                mitm_servers: []) -> str:
    """Attempts to get the html post from the recent posts cache in memory
    """
    if box_name == 'tlmedia':
        return None

    if show_public_only:
        return None

    try_cache = False
    bm_timeline = box_name in ('bookmarks', 'tlbookmarks')
    if store_to_cache or bm_timeline:
        try_cache = True

    if not try_cache:
        return None

    # update avatar if needed
    if not avatar_url:
        avatar_url = \
            get_person_avatar_url(base_dir, post_actor, person_cache)

        _log_post_timing(enable_timing_log, post_start_time, '2.1')

    update_avatar_image_cache(signing_priv_key_pem,
                              session, base_dir, http_prefix,
                              post_actor, avatar_url, person_cache,
                              allow_downloads, mitm_servers)

    _log_post_timing(enable_timing_log, post_start_time, '2.2')

    post_html = \
        load_individual_post_as_html_from_cache(base_dir, nickname, domain,
                                                post_json_object)
    if not post_html:
        return None

    post_html = \
        prepare_post_from_html_cache(nickname, post_html,
                                     box_name, page_number, first_post_id,
                                     ua_str, translate)
    update_recent_posts_cache(recent_posts_cache, max_recent_posts,
                              post_json_object, post_html)
    _log_post_timing(enable_timing_log, post_start_time, '3')
    return post_html


def _get_avatar_image_html(show_avatar_options: bool,
                           nickname: str, domain_full: str,
                           avatar_url: str, post_actor: str,
                           translate: {}, avatar_position: str,
                           page_number: int, message_id_str: str) -> str:
    """Get html for the avatar image
    """
    # don't use svg images
    if avatar_url.endswith('.svg'):
        avatar_url = '/icons/avatar_default.png'

    avatar_link = ''
    if '/users/news/' not in avatar_url:
        avatar_link = \
            '        <a class="imageAnchor" href="' + \
            post_actor + '" tabindex="10">'
        show_profile_str = 'Show profile'
        if translate.get(show_profile_str):
            show_profile_str = translate[show_profile_str]
        avatar_link += \
            '<img loading="lazy" decoding="async" ' + \
            'src="' + avatar_url + '" title="' + \
            show_profile_str + '" alt=" "' + avatar_position + \
            get_broken_link_substitute() + '/></a>\n'

    if show_avatar_options and \
       domain_full + '/users/' + nickname not in post_actor:
        show_options_for_this_person_str = 'Show options for this person'
        if translate.get(show_options_for_this_person_str):
            show_options_for_this_person_str = \
                translate[show_options_for_this_person_str]
        if '/users/news/' not in avatar_url:
            avatar_link = \
                '        <a class="imageAnchor" href="/users/' + \
                nickname + '?options=' + post_actor + \
                ';' + str(page_number) + ';' + \
                avatar_url + message_id_str + '" tabindex="10">\n'
            avatar_link += \
                '        <img loading="lazy" decoding="async" title="' + \
                show_options_for_this_person_str + '" ' + \
                'alt="👤 ' + \
                show_options_for_this_person_str + '" ' + \
                'src="' + avatar_url + '" ' + avatar_position + \
                get_broken_link_substitute() + '/></a>\n'
        else:
            # don't link to the person options for the news account
            avatar_link += \
                '        <img loading="lazy" decoding="async" title="' + \
                show_options_for_this_person_str + '" ' + \
                'alt="👤 ' + \
                show_options_for_this_person_str + '" ' + \
                'src="' + avatar_url + '" ' + avatar_position + \
                get_broken_link_substitute() + '/>\n'
    return avatar_link.strip()


def _get_reply_icon_html(base_dir: str, nickname: str, domain: str,
                         is_public_reply: bool, is_unlisted_reply: bool,
                         show_icons: bool, comments_enabled: bool,
                         post_json_object: {}, page_number_param: str,
                         translate: {}, system_language: str,
                         conversation_id: str, convthread_id: str) -> str:
    """Returns html for the reply icon/button
    """
    reply_str = ''
    if not (show_icons and comments_enabled):
        return reply_str

    # reply is permitted - create reply icon
    reply_to_link = remove_hash_from_post_id(post_json_object['object']['id'])
    reply_to_link = remove_id_ending(reply_to_link)

    # see Mike MacGirvin's replyTo suggestion
    if post_json_object['object'].get('replyTo'):
        # check that the alternative replyTo url is not blocked
        block_nickname = \
            get_nickname_from_actor(post_json_object['object']['replyTo'])
        if not block_nickname:
            return reply_str
        block_domain, _ = \
            get_domain_from_actor(post_json_object['object']['replyTo'])
        if block_domain:
            if not is_blocked(base_dir, nickname, domain,
                              block_nickname, block_domain, {}, None):
                reply_to_link = post_json_object['object']['replyTo']

    if post_json_object['object'].get('attributedTo'):
        attrib = get_attributed_to(post_json_object['object']['attributedTo'])
        if attrib:
            reply_to_link += '?mention=' + attrib
    content = get_base_content_from_post(post_json_object, system_language)
    if content:
        mentioned_actors = \
            get_mentions_from_html(content,
                                   "<span class=\"h-card\"><a href=\"")
        if mentioned_actors:
            for actor_url in mentioned_actors:
                if '?mention=' + actor_url not in reply_to_link:
                    reply_to_link += '?mention=' + actor_url
                    if len(reply_to_link) > 500:
                        break

    # Is this domain or account blocking you?
    reply_to_domain, _ = get_domain_from_actor(reply_to_link)
    reply_to_actor = get_actor_from_post(post_json_object)
    if sending_is_blocked2(base_dir, nickname, domain,
                           reply_to_domain, reply_to_actor):
        # You are blocked. Replies are unlikely to be received
        # so don't show the reply icon
        return ''

    reply_to_link += page_number_param

    reply_str = ''
    reply_to_this_post_str = 'Reply to this post'
    if translate.get(reply_to_this_post_str):
        reply_to_this_post_str = translate[reply_to_this_post_str]
    conversation_str = ''
    if conversation_id:
        if isinstance(conversation_id, str):
            conversation_str = '?conversationId=' + conversation_id
    if convthread_id:
        if isinstance(convthread_id, str):
            conversation_str = '?convthreadId=' + convthread_id
    if is_public_reply:
        reply_str += \
            '        <a class="imageAnchor" href="/users/' + \
            nickname + '?replyto=' + reply_to_link + \
            '?actor=' + reply_to_actor + \
            conversation_str + \
            '" title="' + reply_to_this_post_str + '" tabindex="10">\n'
    elif is_unlisted_reply:
        reply_str += \
            '        <a class="imageAnchor" href="/users/' + \
            nickname + '?replyunlisted=' + reply_to_link + \
            '?actor=' + reply_to_actor + \
            conversation_str + \
            '" title="' + reply_to_this_post_str + '" tabindex="10">\n'
    else:
        if is_dm(post_json_object):
            reply_type = 'replydm'
            if is_chat_message(post_json_object):
                reply_type = 'replychat'
            reply_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + nickname + \
                '?' + reply_type + '=' + reply_to_link + \
                '?actor=' + reply_to_actor + \
                conversation_str + \
                '" title="' + reply_to_this_post_str + '" tabindex="10">\n'
        else:
            reply_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + nickname + \
                '?replyfollowers=' + reply_to_link + \
                '?actor=' + reply_to_actor + \
                conversation_str + \
                '" title="' + reply_to_this_post_str + '" tabindex="10">\n'

    reply_str += \
        '        ' + \
        '<img loading="lazy" decoding="async" title="' + \
        reply_to_this_post_str + '" alt="' + reply_to_this_post_str + \
        ' |" src="/icons/reply.png"/></a>\n'
    return reply_str


def _get_edit_icon_html(base_dir: str, nickname: str, domain_full: str,
                        post_json_object: {}, actor_nickname: str,
                        translate: {}, is_event: bool,
                        first_post_id: str) -> str:
    """Returns html for the edit icon/button
    """
    edit_str = ''
    actor = get_actor_from_post(post_json_object)
    # This should either be a post which you created,
    # or it could be generated from the newswire (see
    # _add_blogs_to_newswire) in which case anyone with
    # editor status should be able to alter it
    if (actor.endswith('/' + domain_full + '/users/' + nickname) or
        (is_editor(base_dir, nickname) and
         actor.endswith('/' + domain_full + '/users/news'))):

        post_id = remove_id_ending(post_json_object['object']['id'])

        if '/statuses/' not in post_id:
            return edit_str

        reply_to = ''
        reply_id = get_reply_to(post_json_object['object'])
        if reply_id:
            reply_to = ';replyTo=' + reply_id

        first_post_str = ''
        if first_post_id:
            first_post_str = ';firstpost=' + first_post_id

        if is_blog_post(post_json_object):
            edit_blog_post_str = 'Edit blog post'
            if translate.get(edit_blog_post_str):
                edit_blog_post_str = translate[edit_blog_post_str]
            if not is_news_post(post_json_object):
                edit_str += \
                    '        ' + \
                    '<a class="imageAnchor" href="/users/' + \
                    nickname + '/tlblogs?editblogpost=' + \
                    post_id.split('/statuses/')[1] + \
                    ';actor=' + actor_nickname + first_post_str + \
                    '" title="' + edit_blog_post_str + '" tabindex="10">' + \
                    '<img loading="lazy" decoding="async" title="' + \
                    edit_blog_post_str + '" alt="' + edit_blog_post_str + \
                    ' |" src="/icons/edit.png"/></a>\n'
            else:
                edit_str += \
                    '        ' + \
                    '<a class="imageAnchor" href="/users/' + \
                    nickname + '/editnewspost=' + \
                    post_id.split('/statuses/')[1] + \
                    '?actor=' + actor_nickname + first_post_str + \
                    '" title="' + edit_blog_post_str + '" tabindex="10">' + \
                    '<img loading="lazy" decoding="async" title="' + \
                    edit_blog_post_str + '" alt="' + edit_blog_post_str + \
                    ' |" src="/icons/edit.png"/></a>\n'
        elif is_event:
            edit_event_str = 'Edit event'
            if translate.get(edit_event_str):
                edit_event_str = translate[edit_event_str]
            edit_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + nickname + \
                '/tlblogs?editeventpost=' + \
                post_id.split('/statuses/')[1] + \
                '?actor=' + actor_nickname + first_post_str + \
                '" title="' + edit_event_str + '" tabindex="10">' + \
                '<img loading="lazy" decoding="async" title="' + \
                edit_event_str + '" alt="' + edit_event_str + \
                ' |" src="/icons/edit.png"/></a>\n'
        elif is_public_post(post_json_object):
            # Edit a public post
            edit_post_str = 'Edit post'
            if translate.get(edit_post_str):
                edit_post_str = translate[edit_post_str]
            edit_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + \
                nickname + '?postedit=' + \
                post_id.split('/statuses/')[1] + ';scope=public' + \
                ';actor=' + actor_nickname + first_post_str + reply_to + \
                '" title="' + edit_post_str + '" tabindex="10">' + \
                '<img loading="lazy" decoding="async" title="' + \
                edit_post_str + '" alt="' + edit_post_str + \
                ' |" src="/icons/edit.png"/></a>\n'
        elif is_reminder(post_json_object):
            # Edit a reminder
            edit_post_str = 'Edit reminder'
            if translate.get(edit_post_str):
                edit_post_str = translate[edit_post_str]
            edit_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + \
                nickname + '?postedit=' + \
                post_id.split('/statuses/')[1] + ';scope=reminder' + \
                ';actor=' + actor_nickname + first_post_str + reply_to + \
                '" title="' + edit_post_str + '" tabindex="10">' + \
                '<img loading="lazy" decoding="async" title="' + \
                edit_post_str + '" alt="' + edit_post_str + \
                ' |" src="/icons/edit.png"/></a>\n'
        elif is_dm(post_json_object):
            # Edit a DM
            edit_post_str = 'Edit post'
            if translate.get(edit_post_str):
                edit_post_str = translate[edit_post_str]
            edit_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + \
                nickname + '?postedit=' + \
                post_id.split('/statuses/')[1] + ';scope=dm' + \
                ';actor=' + actor_nickname + first_post_str + reply_to + \
                '" title="' + edit_post_str + '" tabindex="10">' + \
                '<img loading="lazy" decoding="async" title="' + \
                edit_post_str + '" alt="' + edit_post_str + \
                ' |" src="/icons/edit.png"/></a>\n'
        elif is_unlisted_post(post_json_object):
            # Edit an unlisted post
            edit_post_str = 'Edit post'
            if translate.get(edit_post_str):
                edit_post_str = translate[edit_post_str]
            edit_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + \
                nickname + '?postedit=' + \
                post_id.split('/statuses/')[1] + ';scope=unlisted' + \
                ';actor=' + actor_nickname + first_post_str + reply_to + \
                '" title="' + edit_post_str + '" tabindex="10">' + \
                '<img loading="lazy" decoding="async" title="' + \
                edit_post_str + '" alt="' + edit_post_str + \
                ' |" src="/icons/edit.png"/></a>\n'
        elif is_followers_post(post_json_object):
            # Edit a followers only post
            edit_post_str = 'Edit post'
            if translate.get(edit_post_str):
                edit_post_str = translate[edit_post_str]
            edit_str += \
                '        ' + \
                '<a class="imageAnchor" href="/users/' + \
                nickname + '?postedit=' + \
                post_id.split('/statuses/')[1] + ';scope=followers' + \
                ';actor=' + actor_nickname + first_post_str + reply_to + \
                '" title="' + edit_post_str + '" tabindex="10">' + \
                '<img loading="lazy" decoding="async" title="' + \
                edit_post_str + '" alt="' + edit_post_str + \
                ' |" src="/icons/edit.png"/></a>\n'

    return edit_str


def _get_announce_icon_html(is_announced: bool,
                            post_actor: str,
                            nickname: str, domain_full: str,
                            announce_json_object: {},
                            post_json_object: {},
                            is_public_repeat: bool,
                            is_moderation_post: bool,
                            show_repeat_icon: bool,
                            translate: {},
                            page_number_param: str,
                            timeline_post_bookmark: str,
                            box_name: str,
                            max_announce_count: int,
                            first_post_id: str) -> str:
    """Returns html for announce icon/button at the bottom of the post
    """
    announce_str = ''

    if not show_repeat_icon:
        return announce_str

    if is_moderation_post:
        return announce_str

    # don't allow announce/repeat of your own posts
    announce_icon = 'repeat_inactive.png'
    announce_link = 'repeat'
    announce_emoji = ''
    if not is_public_repeat:
        announce_link = 'repeatprivate'
    repeat_this_post_str = 'Repeat this post'
    if translate.get(repeat_this_post_str):
        repeat_this_post_str = translate[repeat_this_post_str]
    announce_title = repeat_this_post_str
    unannounce_link_str = ''
    announce_count = no_of_announces(post_json_object)

    announce_count_str = ''
    if announce_count > 0:
        if announce_count <= max_announce_count:
            announce_count_str = ' (' + str(announce_count) + ')'
        else:
            announce_count_str = ' (' + str(max_announce_count) + '+)'
    if announced_by_person(is_announced,
                           post_actor, nickname, domain_full):
        if announce_count == 1:
            # announced by the reader only
            announce_count_str = ''
        announce_icon = 'repeat.png'
        announce_emoji = '🔁 '
        announce_link = 'unrepeat'
        if not is_public_repeat:
            announce_link = 'unrepeatprivate'
        undo_the_repeat_str = 'Undo the repeat'
        if translate.get(undo_the_repeat_str):
            undo_the_repeat_str = translate[undo_the_repeat_str]
        announce_title = undo_the_repeat_str
        if announce_json_object:
            unannounce_link_str = '?unannounce=' + \
                remove_id_ending(announce_json_object['id'])

    announce_post_id = \
        remove_hash_from_post_id(post_json_object['object']['id'])
    announce_post_id = remove_id_ending(announce_post_id)

    announce_str = ''
    if announce_count_str:
        announcers_post_id = announce_post_id.replace('/', '--')
        announcers_screen_link = \
            '/users/' + nickname + '?announcers=' + announcers_post_id

        # show the number of announces next to icon
        announce_str += '<label class="likesCount">'
        announce_str += '<a href="' + announcers_screen_link + '" ' + \
            'title="' + translate['Show who repeated this post'] + \
            '" tabindex="10">'
        announce_str += \
            announce_count_str.replace('(', '').replace(')', '').strip()
        announce_str += '</a></label>\n'

    first_post_str = ''
    if first_post_id:
        first_post_str = '?firstpost=' + first_post_id.replace('#', '/')

    announce_link_str = '?' + \
        announce_link + '=' + announce_post_id + page_number_param
    actor_url = get_actor_from_post(post_json_object)
    announce_str += \
        '        <a class="imageAnchor" href="/users/' + \
        nickname + announce_link_str + unannounce_link_str + \
        '?actor=' + actor_url + \
        '?bm=' + timeline_post_bookmark + first_post_str + \
        '?tl=' + box_name + '" title="' + announce_title + '" tabindex="10">\n'

    announce_str += \
        '          ' + \
        '<img loading="lazy" decoding="async" title="' + announce_title + \
        '" alt="' + announce_emoji + announce_title + \
        ' |" src="/icons/' + announce_icon + '"/></a>\n'
    return announce_str


def _get_like_icon_html(nickname: str, domain_full: str,
                        is_moderation_post: bool,
                        show_like_button: bool,
                        post_json_object: {},
                        enable_timing_log: bool,
                        post_start_time,
                        translate: {}, page_number_param: str,
                        timeline_post_bookmark: str,
                        box_name: str,
                        max_like_count: int,
                        first_post_id: str) -> str:
    """Returns html for like icon/button
    """
    if not show_like_button or is_moderation_post:
        return ''
    like_str = ''
    like_icon = 'like_inactive.png'
    like_link = 'like'
    like_title = 'Like this post'
    if translate.get(like_title):
        like_title = translate[like_title]
    like_emoji = ''
    like_count = no_of_likes(post_json_object)

    _log_post_timing(enable_timing_log, post_start_time, '12.1')

    like_count_str = ''
    if like_count > 0:
        if like_count <= max_like_count:
            like_count_str = ' (' + str(like_count) + ')'
        else:
            like_count_str = ' (' + str(max_like_count) + '+)'
        if liked_by_person(post_json_object, nickname, domain_full):
            if like_count == 1:
                # liked by the reader only
                like_count_str = ''
            like_icon = 'like.png'
            like_link = 'unlike'
            like_title = 'Undo the like'
            if translate.get(like_title):
                like_title = translate[like_title]
            like_emoji = '👍 '

    _log_post_timing(enable_timing_log, post_start_time, '12.2')

    like_post_id = remove_hash_from_post_id(post_json_object['id'])
    like_post_id = remove_id_ending(like_post_id)

    like_str = ''
    if like_count_str:
        likers_post_id = like_post_id.replace('/', '--')
        likers_screen_link = \
            '/users/' + nickname + '?likers=' + likers_post_id

        # show the number of likes next to icon
        show_liked_str = 'Show who liked this post'
        if translate.get(show_liked_str):
            show_liked_str = translate[show_liked_str]
        like_str += '<label class="likesCount">'
        like_str += '<a href="' + likers_screen_link + '" ' + \
            'title="' + show_liked_str + \
            '" tabindex="10">'
        like_str += like_count_str.replace('(', '').replace(')', '').strip()
        like_str += '</a></label>\n'

    first_post_str = ''
    if first_post_id:
        first_post_str = '?firstpost=' + first_post_id.replace('#', '/')

    actor_url = get_actor_from_post(post_json_object)
    like_str += \
        '        <a class="imageAnchor" href="/users/' + nickname + '?' + \
        like_link + '=' + like_post_id + \
        page_number_param + \
        '?actor=' + actor_url + \
        '?bm=' + timeline_post_bookmark + first_post_str + \
        '?tl=' + box_name + '" title="' + like_title + like_count_str + \
        '" tabindex="10">\n'
    like_str += \
        '          ' + \
        '<img loading="lazy" decoding="async" title="' + \
        like_title + like_count_str + \
        '" alt="' + like_emoji + like_title + \
        ' |" src="/icons/' + like_icon + '"/></a>\n'
    return like_str


def _get_bookmark_icon_html(base_dir: str,
                            nickname: str, domain: str,
                            domain_full: str,
                            post_json_object: {},
                            is_moderation_post: bool,
                            translate: {},
                            enable_timing_log: bool,
                            post_start_time, box_name: str,
                            page_number_param: str,
                            timeline_post_bookmark: str,
                            first_post_id: str,
                            post_url: str) -> str:
    """Returns html for bookmark icon/button
    """
    bookmark_str = ''

    if is_moderation_post:
        return bookmark_str

    if not locate_post(base_dir, nickname, domain, post_url):
        return bookmark_str

    bookmark_icon = 'bookmark_inactive.png'
    bookmark_link = 'bookmark'
    bookmark_emoji = ''
    bookmark_title = 'Bookmark this post'
    if translate.get(bookmark_title):
        bookmark_title = translate[bookmark_title]
    if bookmarked_by_person(post_json_object, nickname, domain_full):
        bookmark_icon = 'bookmark.png'
        bookmark_link = 'unbookmark'
        bookmark_emoji = '🔖 '
        bookmark_title = 'Undo the bookmark'
        if translate.get(bookmark_title):
            bookmark_title = translate[bookmark_title]
    _log_post_timing(enable_timing_log, post_start_time, '12.6')
    bookmark_post_id = \
        remove_hash_from_post_id(post_json_object['object']['id'])
    bookmark_post_id = remove_id_ending(bookmark_post_id)

    first_post_str = ''
    if first_post_id:
        first_post_str = '?firstpost=' + first_post_id.replace('#', '/')

    actor_url = get_actor_from_post(post_json_object)
    bookmark_str = \
        '        <a class="imageAnchor" href="/users/' + nickname + '?' + \
        bookmark_link + '=' + bookmark_post_id + \
        page_number_param + \
        '?actor=' + actor_url + \
        '?bm=' + timeline_post_bookmark + first_post_str + \
        '?tl=' + box_name + '" title="' + bookmark_title + \
        '" tabindex="10">\n'
    bookmark_str += \
        '        ' + \
        '<img loading="lazy" decoding="async" title="' + \
        bookmark_title + '" alt="' + \
        bookmark_emoji + bookmark_title + ' |" src="/icons' + \
        '/' + bookmark_icon + '"/></a>\n'
    return bookmark_str


def _get_reaction_icon_html(nickname: str, post_json_object: {},
                            is_moderation_post: bool,
                            show_reaction_button: bool,
                            translate: {},
                            enable_timing_log: bool,
                            post_start_time, box_name: str,
                            page_number_param: str,
                            timeline_post_reaction: str,
                            first_post_id: str) -> str:
    """Returns html for reaction icon/button
    """
    reaction_str = ''

    if not show_reaction_button or is_moderation_post:
        return reaction_str

    reaction_icon = 'reaction.png'
    reaction_title = 'Select reaction'
    if translate.get(reaction_title):
        reaction_title = translate[reaction_title]
    _log_post_timing(enable_timing_log, post_start_time, '12.65')
    reaction_post_id = \
        remove_hash_from_post_id(post_json_object['object']['id'])
    reaction_post_id = remove_id_ending(reaction_post_id)

    first_post_str = ''
    if first_post_id:
        first_post_str = '?firstpost=' + first_post_id.replace('#', '/')

    actor_url = get_actor_from_post(post_json_object)
    reaction_str = \
        '        <a class="imageAnchor" href="/users/' + nickname + \
        '?selreact=' + reaction_post_id + page_number_param + \
        '?actor=' + actor_url + \
        '?bm=' + timeline_post_reaction + first_post_str + \
        '?tl=' + box_name + '" title="' + reaction_title + \
        '" tabindex="10">\n'
    reaction_str += \
        '        ' + \
        '<img loading="lazy" decoding="async" title="' + \
        reaction_title + '" alt="' + \
        reaction_title + ' |" src="/icons' + \
        '/' + reaction_icon + '"/></a>\n'
    return reaction_str


def _get_mute_icon_html(is_muted: bool,
                        post_actor: str,
                        message_id: str,
                        nickname: str, domain_full: str,
                        allow_deletion: bool,
                        page_number_param: str,
                        box_name: str,
                        timeline_post_bookmark: str,
                        translate: {},
                        first_post_id: str) -> str:
    """Returns html for mute icon/button
    """
    mute_str = ''
    if (allow_deletion or
        ('/' + domain_full + '/' in post_actor and
         message_id.startswith(post_actor))):
        return mute_str

    first_post_str = ''
    if first_post_id:
        first_post_str = '?firstpost=' + first_post_id.replace('#', '/')

    if not is_muted:
        mute_this_post_str = 'Mute this post'
        if translate.get('Mute this post'):
            mute_this_post_str = translate[mute_this_post_str]
        mute_str = \
            '        <a class="imageAnchor" href="/users/' + nickname + \
            '?mute=' + message_id + page_number_param + '?tl=' + box_name + \
            '?bm=' + timeline_post_bookmark + first_post_str + \
            '" title="' + mute_this_post_str + '" tabindex="10">\n'
        mute_str += \
            '          ' + \
            '<img loading="lazy" decoding="async" alt="' + \
            mute_this_post_str + \
            ' |" title="' + mute_this_post_str + \
            '" src="/icons/mute.png"/></a>\n'
    else:
        undo_mute_str = 'Undo mute'
        if translate.get(undo_mute_str):
            undo_mute_str = translate[undo_mute_str]
        mute_str = \
            '        <a class="imageAnchor" href="/users/' + \
            nickname + '?unmute=' + message_id + \
            page_number_param + '?tl=' + box_name + '?bm=' + \
            timeline_post_bookmark + first_post_str + \
            '" title="' + undo_mute_str + \
            '" tabindex="10">\n'
        mute_str += \
            '          ' + \
            '<img loading="lazy" decoding="async" ' + \
            'alt="🔇 ' + undo_mute_str + \
            ' |" title="' + undo_mute_str + \
            '" src="/icons/unmute.png"/></a>\n'
    return mute_str


def _get_delete_icon_html(nickname: str, domain_full: str,
                          allow_deletion: bool,
                          post_actor: str,
                          message_id: str,
                          post_json_object: {},
                          page_number_param: str,
                          translate: {},
                          first_post_id: str) -> str:
    """Returns html for delete icon/button
    """
    delete_str = ''
    if (allow_deletion or
        ('/' + domain_full + '/' in post_actor and
         message_id.startswith(post_actor))):
        if '/users/' + nickname + '/' in message_id:
            if not is_news_post(post_json_object):
                delete_this_post_str = 'Delete this post'
                if translate.get(delete_this_post_str):
                    delete_this_post_str = translate[delete_this_post_str]

                first_post_str = ''
                if first_post_id:
                    first_post_str = \
                        '?firstpost=' + first_post_id.replace('#', '/')

                delete_str = \
                    '        <a class="imageAnchor" href="/users/' + \
                    nickname + '?delete=' + message_id + \
                    page_number_param + first_post_str + \
                    '" title="' + delete_this_post_str + '" tabindex="10">\n'
                delete_str += \
                    '          ' + \
                    '<img loading="lazy" decoding="async" alt="' + \
                    delete_this_post_str + \
                    ' |" title="' + delete_this_post_str + \
                    '" src="/icons/delete.png"/></a>\n'
    return delete_str


def _get_published_date_str(post_json_object: {},
                            show_published_date_only: bool,
                            timezone: str) -> str:
    """Return the html for the published date on a post
    """
    published_str = ''

    if not post_json_object['object'].get('published'):
        return published_str

    published_str = post_json_object['object']['published']
    if '.' not in published_str:
        if '+' not in published_str:
            datetime_object = \
                date_from_string_format(published_str, ["%Y-%m-%dT%H:%M:%S%z"])
        else:
            pub_str = published_str.split('+')[0] + 'Z'
            datetime_object = \
                date_from_string_format(pub_str,
                                        ["%Y-%m-%dT%H:%M:%S%z"])
    else:
        published_str = \
            published_str.replace('T', ' ').split('.')[0]
        datetime_object = parse(published_str)

    # convert to local time
    datetime_object = \
        convert_published_to_local_timezone(datetime_object, timezone)

    if not show_published_date_only:
        published_str = datetime_object.strftime("%a %b %d, %H:%M")
    else:
        published_str = datetime_object.strftime("%a %b %d")

    # if the post has replies then append a symbol to indicate this
    if post_json_object.get('hasReplies'):
        if post_json_object['hasReplies'] is True:
            published_str = '[' + published_str + ']'
    return published_str


def _get_blog_citations_html(box_name: str,
                             post_json_object: {},
                             translate: {}) -> str:
    """Returns blog citations as html
    """
    # show blog citations
    citations_str = ''
    if box_name not in ('tlblogs', 'tlfeatures'):
        return citations_str

    if not post_json_object['object'].get('tag'):
        return citations_str

    for tag_json in post_json_object['object']['tag']:
        if not isinstance(tag_json, dict):
            continue
        if not tag_json.get('type'):
            continue
        if tag_json['type'] != 'Article':
            continue
        if not tag_json.get('name'):
            continue
        if not tag_json.get('url'):
            continue
        url_str = get_url_from_post(tag_json['url'])
        citation_url = remove_html(url_str)
        citation_name = remove_html(tag_json['name'])
        citations_str += \
            '<li><a href="' + citation_url + '" tabindex="10">' + \
            '<cite>' + citation_name + '</cite></a></li>\n'

    if citations_str:
        translated_citations_str = 'Citations'
        if translate.get(translated_citations_str):
            translated_citations_str = translate[translated_citations_str]
        citations_str = '<p><b>' + translated_citations_str + ':</b></p>' + \
            '<u>\n' + citations_str + '</u>\n'
    return citations_str


def _boost_own_post_html(translate: {}) -> str:
    """The html title for announcing your own post
    """
    announces_str = 'announces'
    if translate.get(announces_str):
        announces_str = translate[announces_str]
    return '        <img loading="lazy" decoding="async" title="' + \
        announces_str + \
        '" alt="' + announces_str + \
        '" src="/icons' + \
        '/repeat_inactive.png" class="announceOrReply"/>\n'


def _announce_unattributed_html(translate: {},
                                post_json_object: {},
                                nickname: str) -> str:
    """Returns the html for an announce title where there
    is no attribution on the announced post
    """
    announces_str = 'announces'
    if translate.get(announces_str):
        announces_str = translate[announces_str]
    post_id = remove_id_ending(post_json_object['object']['id'])
    post_bookmark = '#' + bookmark_from_id(post_id)
    post_link = '/users/' + nickname + '?convthread=' + \
        post_id.replace('--', '/') + post_bookmark
    return '    <img loading="lazy" decoding="async" title="' + \
        announces_str + '" alt="' + \
        announces_str + '" src="/icons' + \
        '/repeat_inactive.png" ' + \
        'class="announceOrReply"/>\n' + \
        '      <a href="' + post_link + \
        '" class="announceOrReply" tabindex="10">@unattributed</a>\n'


def _announce_with_display_name_html(translate: {},
                                     post_json_object: {},
                                     announce_display_name: str,
                                     nickname: str,
                                     announce_handle: str) -> str:
    """Returns html for an announce having a display name
    """
    announces_str = 'announces'
    if translate.get(announces_str):
        announces_str = translate[announces_str]
    post_id = remove_id_ending(post_json_object['object']['id'])
    post_bookmark = '#' + bookmark_from_id(post_id)
    post_link = '/users/' + nickname + '?convthread=' + \
        post_id.replace('--', '/') + post_bookmark
    return '          <img loading="lazy" decoding="async" title="' + \
        announces_str + '" alt="' + \
        announces_str + '" src="/' + \
        'icons/repeat_inactive.png" ' + \
        'class="announceOrReply"/>\n' + \
        '        <a href="' + post_link + '" ' + \
        'class="announceOrReply" tabindex="10" title="' + \
        announce_handle + '">' + '<span itemprop="author">' + \
        announce_display_name + '</span></a>\n'


def _get_post_title_announce_html(base_dir: str,
                                  http_prefix: str,
                                  nickname: str, domain: str,
                                  post_json_object: {},
                                  post_actor: str,
                                  translate: {},
                                  enable_timing_log: bool,
                                  post_start_time,
                                  person_cache: {},
                                  avatar_position: str,
                                  page_number: int,
                                  message_id_str: str,
                                  container_class_icons: str,
                                  container_class: str,
                                  mitm: bool,
                                  mitm_servers: [],
                                  software_name: str) -> (str, str, str, str):
    """Returns the announce title of a post containing names of participants
    x announces y
    """
    title_str = ''
    reply_avatar_image_in_post = ''
    obj_json = post_json_object['object']

    # has no attribution
    if not obj_json.get('attributedTo'):
        title_str += \
            _announce_unattributed_html(translate, post_json_object, nickname)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    attributed_to = get_attributed_to(obj_json['attributedTo'])
    if attributed_to is None:
        attributed_to = ''

    # boosting your own post
    if attributed_to.startswith(post_actor):
        title_str += _boost_own_post_html(translate)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    # boosting another person's post
    _log_post_timing(enable_timing_log, post_start_time, '13.2')
    announce_nickname = None
    if attributed_to:
        announce_nickname = get_nickname_from_actor(attributed_to)
    if not announce_nickname:
        title_str += \
            _announce_unattributed_html(translate, post_json_object, nickname)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    announce_domain, _ = get_domain_from_actor(attributed_to)
    get_person_from_cache(base_dir, attributed_to, person_cache)
    announce_handle = ''
    if announce_nickname and announce_domain:
        announce_handle = announce_nickname + '@' + announce_domain
    announce_display_name = \
        get_display_name(base_dir, attributed_to, person_cache)
    if announce_display_name:
        if len(announce_display_name) < 2 or \
           display_name_is_emoji(announce_display_name):
            announce_display_name = None
    if announce_display_name:
        # enforce maximum length for display name
        announce_display_name = \
            _enforce_max_display_name_length(announce_display_name)
    if not announce_display_name and announce_domain:
        announce_display_name = announce_handle

    _log_post_timing(enable_timing_log, post_start_time, '13.3')

    # add any emoji to the display name
    if ':' in announce_display_name:
        announce_display_name = \
            add_emoji_to_display_name(None, base_dir, http_prefix,
                                      nickname, domain,
                                      announce_display_name, False,
                                      translate)
    _log_post_timing(enable_timing_log, post_start_time, '13.3.1')
    title_str += \
        _announce_with_display_name_html(translate, post_json_object,
                                         announce_display_name,
                                         nickname, announce_handle)

    if mitm or announce_domain in mitm_servers:
        title_str += mitm_warning_html(translate)

    title_str += _get_instance_software_html(title_str, software_name)

    # show avatar of person replied to
    announce_actor = attributed_to
    announce_avatar_url = \
        get_person_avatar_url(base_dir, announce_actor, person_cache)

    _log_post_timing(enable_timing_log, post_start_time, '13.4')

    if not announce_avatar_url:
        announce_avatar_url = ''

    idx = 'Show options for this person'
    if '/users/news/' not in announce_avatar_url:
        show_options_for_this_person_str = idx
        if translate.get(idx):
            show_options_for_this_person_str = translate[idx]
        reply_avatar_image_in_post = \
            '        <div class="timeline-avatar-reply">\n' \
            '            <a class="imageAnchor" ' + \
            'href="/users/' + nickname + '?options=' + \
            announce_actor + ';' + str(page_number) + \
            ';' + announce_avatar_url + message_id_str + \
            '" tabindex="10">' \
            '<img loading="lazy" decoding="async" src="' + \
            announce_avatar_url + '" ' + \
            'title="' + show_options_for_this_person_str + \
            '" alt=" "' + avatar_position + \
            get_broken_link_substitute() + '/></a>\n    </div>\n'

    return (title_str, reply_avatar_image_in_post,
            container_class_icons, container_class)


def _reply_to_yourself_html(translate: {}, software_name: str) -> str:
    """Returns html for a title which is a reply to yourself
    """
    replying_to_themselves_str = 'replying to themselves'
    if translate.get(replying_to_themselves_str):
        replying_to_themselves_str = translate[replying_to_themselves_str]
    title_str = \
        '    <img loading="lazy" decoding="async" title="' + \
        replying_to_themselves_str + \
        '" alt="' + replying_to_themselves_str + \
        '" src="/icons' + \
        '/reply.png" class="announceOrReply"/>\n'

    title_str += _get_instance_software_html(title_str, software_name)
    return title_str


def _replying_to_with_scope(post_json_object: {}, translate: {}) -> str:
    """Returns the replying to string
    """
    replying_to_str = 'replying to'
    if is_followers_post(post_json_object):
        replying_to_str = 'replying to followers'
    elif is_public_post(post_json_object):
        replying_to_str = 'publicly replying to'
    elif is_unlisted_post(post_json_object):
        replying_to_str = 'replying unlisted'
    if translate.get(replying_to_str):
        replying_to_str = translate[replying_to_str]
    return replying_to_str


def _reply_to_unknown_html(translate: {},
                           post_json_object: {},
                           nickname: str,
                           software_name: str) -> str:
    """Returns the html title for a reply to an unknown handle
    """
    replying_to_str = _replying_to_with_scope(post_json_object, translate)
    post_id = get_reply_to(post_json_object['object'])
    post_bookmark = '#' + bookmark_from_id(post_id)
    post_link = '/users/' + nickname + '?convthread=' + \
        post_id.replace('--', '/') + post_bookmark
    title_str = \
        '        <img loading="lazy" decoding="async" title="' + \
        replying_to_str + '" alt="' + \
        replying_to_str + '" src="/icons' + \
        '/reply.png" class="announceOrReply"/>\n' + \
        '        <a href="' + \
        post_link + \
        '" class="announceOrReply" tabindex="10">@unknown</a>\n'

    title_str += _get_instance_software_html(title_str, software_name)
    return title_str


def _reply_with_unknown_path_html(translate: {},
                                  post_json_object: {},
                                  post_domain: str,
                                  nickname: str,
                                  mitm_servers: [],
                                  software_name: str) -> str:
    """Returns html title for a reply with an unknown path
    eg. does not contain /statuses/ or an equivalent separator
    """
    replying_to_str = _replying_to_with_scope(post_json_object, translate)
    post_id = get_reply_to(post_json_object['object'])
    post_bookmark = '#' + bookmark_from_id(post_id)
    post_link = '/users/' + nickname + '?convthread=' + \
        post_id.replace('--', '/') + post_bookmark
    mitm_str = ''
    if post_domain in mitm_servers:
        mitm_str = ' ' + mitm_warning_html(translate)
    title_str = \
        '        <img loading="lazy" decoding="async" title="' + \
        replying_to_str + \
        '" alt="' + replying_to_str + \
        '" src="/icons/reply.png" ' + \
        'class="announceOrReply"/>\n' + \
        '        <a href="' + post_link + \
        '" class="announceOrReply" tabindex="10">' + \
        post_domain + mitm_str + '</a>\n'

    title_str += _get_instance_software_html(title_str, software_name)
    return title_str


def _get_reply_html(translate: {},
                    in_reply_to: str, reply_display_name: str,
                    nickname: str,
                    post_json_object: {},
                    reply_handle: str,
                    software_name: str) -> str:
    """Returns html title for a reply
    """
    replying_to_str = _replying_to_with_scope(post_json_object, translate)
    post_bookmark = '#' + bookmark_from_id(in_reply_to)
    post_link = '/users/' + nickname + '?convthread=' + \
        in_reply_to.replace('--', '/') + post_bookmark
    title_str = \
        '        ' + \
        '<img loading="lazy" decoding="async" title="' + \
        replying_to_str + '" alt="' + \
        replying_to_str + '" src="/' + \
        'icons/reply.png" ' + \
        'class="announceOrReply"/>\n' + \
        '        <a href="' + post_link + \
        '" class="announceOrReply" tabindex="10" title="' + \
        reply_handle + '">' + '<span itemprop="audience">' + \
        reply_display_name + '</span></a>\n'

    title_str += _get_instance_software_html(title_str, software_name)
    return title_str


def _get_post_title_reply_html(base_dir: str,
                               http_prefix: str,
                               nickname: str, domain: str,
                               post_json_object: {},
                               post_actor: str,
                               translate: {},
                               enable_timing_log: bool,
                               post_start_time,
                               person_cache: {},
                               avatar_position: str,
                               page_number: int,
                               message_id_str: str,
                               container_class_icons: str,
                               container_class: str,
                               mitm: bool,
                               signing_priv_key_pem: str,
                               session, debug: bool,
                               mitm_servers: [],
                               software_name: str) -> (str, str, str, str):
    """Returns the reply title of a post containing names of participants
    x replies to y
    """
    title_str = ''
    reply_avatar_image_in_post = ''
    obj_json = post_json_object['object']

    # not a reply
    reply_id = get_reply_to(obj_json)
    if not reply_id:
        title_str += _get_instance_software_html(title_str, software_name)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    container_class_icons = 'containericons darker'
    container_class = 'container darker'

    # reply to self
    if reply_id.startswith(post_actor):
        title_str += _reply_to_yourself_html(translate, software_name)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    # has a reply
    reply_actor = None
    in_reply_to = None
    if contains_statuses(reply_id):
        reply_url = reply_id
        post_domain = reply_url
        prefixes = get_protocol_prefixes()
        for prefix in prefixes:
            post_domain = post_domain.replace(prefix, '')
        if '/' in post_domain:
            post_domain = post_domain.split('/', 1)[0]
        # resolve inReplyTo to obtain attributedTo
        profile_str = 'https://www.w3.org/ns/activitystreams'
        headers = {
            'Accept': 'application/ld+json; profile="' + profile_str + '"'
        }
        reply_post_json = \
            get_json(signing_priv_key_pem,
                     session, reply_url,
                     headers, None, debug, mitm_servers,
                     __version__, http_prefix, domain)
        if get_json_valid(reply_post_json):
            if isinstance(reply_post_json, dict):
                obj_json = reply_post_json
                if has_object_dict(reply_post_json):
                    obj_json = reply_post_json['object']
                if obj_json.get('attributedTo'):
                    attrib = get_attributed_to(obj_json['attributedTo'])
                    if attrib:
                        reply_actor = attrib
                        in_reply_to = reply_actor
                        if obj_json.get('id'):
                            if isinstance(obj_json['id'], str):
                                in_reply_to = obj_json['id']
                elif obj_json != reply_post_json:
                    obj_json = reply_post_json
                    if obj_json.get('attributedTo'):
                        attrib = get_attributed_to(obj_json['attributedTo'])
                        if attrib:
                            reply_actor = attrib
                            in_reply_to = reply_actor
                            if obj_json.get('id'):
                                if isinstance(obj_json['id'], str):
                                    in_reply_to = obj_json['id']

        if post_domain and not reply_actor:
            title_str += \
                _reply_with_unknown_path_html(translate,
                                              post_json_object, post_domain,
                                              nickname, mitm_servers,
                                              software_name)
            return (title_str, reply_avatar_image_in_post,
                    container_class_icons, container_class)

    reply_id = get_reply_to(obj_json)
    if reply_id:
        if isinstance(reply_id, str):
            in_reply_to = reply_id
    if in_reply_to and not reply_actor:
        reply_actor = get_actor_from_post_id(in_reply_to)
    reply_nickname = get_nickname_from_actor(reply_actor)
    if not reply_nickname or not in_reply_to:
        title_str += \
            _reply_to_unknown_html(translate, post_json_object, nickname,
                                   software_name)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    reply_domain, _ = get_domain_from_actor(reply_actor)
    if not (reply_nickname and reply_domain):
        title_str += \
            _reply_to_unknown_html(translate, post_json_object, nickname,
                                   software_name)
        return (title_str, reply_avatar_image_in_post,
                container_class_icons, container_class)

    reply_handle = ''
    if reply_nickname and reply_domain:
        reply_handle = reply_nickname + '@' + reply_domain
    get_person_from_cache(base_dir, reply_actor, person_cache)
    reply_display_name = \
        get_display_name(base_dir, reply_actor, person_cache)
    if reply_display_name:
        if len(reply_display_name) < 2 or \
           display_name_is_emoji(reply_display_name):
            reply_display_name = None
    if not reply_display_name:
        reply_display_name = reply_handle

    # add emoji to the display name
    if ':' in reply_display_name:
        _log_post_timing(enable_timing_log, post_start_time, '13.5')

        reply_display_name = \
            add_emoji_to_display_name(None, base_dir, http_prefix,
                                      nickname, domain,
                                      reply_display_name, False, translate)
        _log_post_timing(enable_timing_log, post_start_time, '13.6')

    if not in_reply_to:
        title_str += _reply_to_unknown_html(translate, post_json_object,
                                            nickname, software_name)
    else:
        title_str += \
            _get_reply_html(translate, in_reply_to, reply_display_name,
                            nickname, post_json_object, reply_handle,
                            software_name)

    if mitm or reply_domain in mitm_servers:
        title_str += mitm_warning_html(translate)

    title_str += _get_instance_software_html(title_str, software_name)

    _log_post_timing(enable_timing_log, post_start_time, '13.7')

    # show avatar of person replied to
    reply_avatar_url = \
        get_person_avatar_url(base_dir, reply_actor, person_cache)

    _log_post_timing(enable_timing_log, post_start_time, '13.8')

    if reply_avatar_url:
        show_profile_str = 'Show profile'
        if translate.get(show_profile_str):
            show_profile_str = translate[show_profile_str]
        reply_avatar_image_in_post = \
            '        <div class="timeline-avatar-reply">\n' + \
            '          <a class="imageAnchor" ' + \
            'href="/users/' + nickname + '?options=' + reply_actor + \
            ';' + str(page_number) + ';' + reply_avatar_url + \
            message_id_str + '" tabindex="10">\n' + \
            '          <img loading="lazy" decoding="async" ' + \
            'src="' + reply_avatar_url + '" ' + \
            'title="' + show_profile_str + \
            '" alt=" "' + avatar_position + get_broken_link_substitute() + \
            '/></a>\n        </div>\n'

    return (title_str, reply_avatar_image_in_post,
            container_class_icons, container_class)


def _get_post_title_html(base_dir: str,
                         http_prefix: str,
                         nickname: str, domain: str,
                         is_announced: bool,
                         post_json_object: {},
                         post_actor: str,
                         translate: {},
                         enable_timing_log: bool,
                         post_start_time,
                         box_name: str,
                         person_cache: {},
                         avatar_position: str,
                         page_number: int,
                         message_id_str: str,
                         container_class_icons: str,
                         container_class: str,
                         mitm: bool,
                         signing_priv_key_pem: str,
                         session,
                         debug: bool,
                         mitm_servers: [],
                         software_name: str) -> (str, str, str, str):
    """Returns the title of a post containing names of participants
    x replies to y, x announces y, etc
    """
    if not is_announced and box_name == 'search' and \
       post_json_object.get('object'):
        if post_json_object['object'].get('attributedTo'):
            attrib = \
                get_attributed_to(post_json_object['object']['attributedTo'])
            if attrib != post_actor:
                is_announced = True

    if is_announced:
        return _get_post_title_announce_html(base_dir,
                                             http_prefix,
                                             nickname, domain,
                                             post_json_object,
                                             post_actor,
                                             translate,
                                             enable_timing_log,
                                             post_start_time,
                                             person_cache,
                                             avatar_position,
                                             page_number,
                                             message_id_str,
                                             container_class_icons,
                                             container_class, mitm,
                                             mitm_servers,
                                             software_name)

    return _get_post_title_reply_html(base_dir,
                                      http_prefix,
                                      nickname, domain,
                                      post_json_object,
                                      post_actor,
                                      translate,
                                      enable_timing_log,
                                      post_start_time,
                                      person_cache,
                                      avatar_position,
                                      page_number,
                                      message_id_str,
                                      container_class_icons,
                                      container_class, mitm,
                                      signing_priv_key_pem,
                                      session, debug,
                                      mitm_servers,
                                      software_name)


def _get_footer_with_icons(show_icons: bool,
                           container_class_icons: str,
                           reply_str: str, announce_str: str,
                           like_str: str, reaction_str: str,
                           bookmark_str: str,
                           delete_str: str, mute_str: str, edit_str: str,
                           buy_str: str,
                           post_json_object: {}, published_link: str,
                           time_class: str, published_str: str,
                           nickname: str, content_license_url: str,
                           translate: {}) -> str:
    """Returns the html for a post footer containing icons
    """
    if not show_icons:
        return None

    footer_str = '\n      <nav>\n'
    footer_str += '      <div class="' + container_class_icons + '">\n'
    footer_str += \
        reply_str + announce_str + like_str + bookmark_str + reaction_str
    footer_str += delete_str + mute_str + edit_str + buy_str
    if not is_news_post(post_json_object):
        footer_str += '        '
        if content_license_url and not is_reminder(post_json_object):
            footer_str += _get_copyright_footer(content_license_url,
                                                translate)
        # show the date
        post_bookmark = '#' + bookmark_from_id(published_link)
        date_link = '/users/' + nickname + '?convthread=' + \
            published_link.replace('--', '/') + post_bookmark
        footer_str += '<a href="' + date_link + '" class="' + \
            time_class + '" tabindex="10"><span itemprop="datePublished">' + \
            published_str + '</span></a>\n'
    else:
        # show the date
        footer_str += '        <a href="' + \
            published_link.replace('/news/', '/news/statuses/') + \
            '" class="' + time_class + '" tabindex="10">' + \
            '<span itemprop="datePublished">' + published_str + '</span></a>\n'
    footer_str += '      </div>\n'
    footer_str += '      </nav>\n'
    return footer_str


def _substitute_onion_domains(base_dir: str, content: str) -> str:
    """Replace clearnet domains with onion domains
    """
    # any common sites which have onion equivalents
    bbc_onion = \
        'bbcweb3hytmzhn5d532owbu6oqadra5z3ar726vq5kgwwn6aucdccrad.onion'
    ddg_onion = \
        'duckduckgogg42xjoc72x3sjasowoarfbgcmvfimaftt6twagswzczad.onion'
    guardian_onion = \
        'guardian2zotagl6tmjucg3lrhxdk4dw3lhbqnkvvkywawy3oqfoprid.onion'
    propublica_onion = \
        'p53lf57qovyuvwsc6xnrppyply3vtqm7l6pcobkmyqsiofyeznfu5uqd.onion'
    # woe betide anyone following a facebook link, but if you must
    # then do it safely
    facebook_onion = \
        'facebookwkhpilnemxj7asaniu7vnjjbiltxjqhye3mhbshg7kx5tfyd.onion'
    protonmail_onion = \
        'protonmailrmez3lotccipshtkleegetolb73fuirgj7r4o4vfu7ozyd.onion'
    riseup_onion = \
        'vww6ybal4bd7szmgncyruucpgfkqahzddi37ktceo3ah7ngmcopnpyyd.onion'
    keybase_onion = \
        'keybase5wmilwokqirssclfnsqrjdsi7jdir5wy7y7iu3tanwmtp6oid.onion'
    zerobin_onion = \
        'zerobinftagjpeeebbvyzjcqyjpmjvynj5qlexwyxe7l3vqejxnqv5qd.onion'
    securedrop_onion = \
        'sdolvtfhatvsysc6l34d65ymdwxcujausv7k5jk4cy5ttzhjoi6fzvyd.onion'
    # the hell site 🔥
    twitter_onion = \
        'twitter3e4tixl4xyajtrzo62zg5vztmjuricljdp2c5kshju4avyoid.onion'
    onion_domains = {
        "bbc.com": bbc_onion,
        "bbc.co.uk": bbc_onion,
        "theguardian.com": guardian_onion,
        "theguardian.co.uk": guardian_onion,
        "duckduckgo.com": ddg_onion,
        "propublica.org": propublica_onion,
        "facebook.com": facebook_onion,
        "protonmail.ch": protonmail_onion,
        "proton.me": protonmail_onion,
        "riseup.net": riseup_onion,
        "keybase.io": keybase_onion,
        "zerobin.net": zerobin_onion,
        "securedrop.org": securedrop_onion,
        "twitter.com": twitter_onion
    }

    onion_domains_filename = data_dir(base_dir) + '/onion_domains.txt'
    if os.path.isfile(onion_domains_filename):
        onion_domains_list: list[str] = []
        try:
            with open(onion_domains_filename, 'r',
                      encoding='utf-8') as fp_onions:
                onion_domains_list = fp_onions.readlines()
        except OSError:
            print('EX: unable to load onion domains file ' +
                  onion_domains_filename)
        if onion_domains_list:
            onion_domains = {}
            separators = (' ', ',', '->')
            for line in onion_domains_list:
                line = line.strip()
                if line.startswith('#'):
                    continue
                for sep in separators:
                    if sep not in line:
                        continue
                    clearnet = line.split(sep, 1)[0].strip()
                    onion1 = line.split(sep, 1)[1].strip()
                    onion = remove_eol(onion1)
                    if clearnet and onion:
                        onion_domains[clearnet] = onion
                    break

    for clearnet, onion in onion_domains.items():
        if clearnet in content:
            content = content.replace(clearnet, onion)
    return content


def _add_dogwhistle_warnings(summary: str, content: str,
                             dogwhistles: {}, translate: {}) -> {}:
    """Adds dogwhistle warnings for the given content
    """
    if not dogwhistles:
        return summary
    content_str = str(summary) + ' ' + content
    detected = detect_dogwhistles(content_str, dogwhistles)
    if not detected:
        return summary

    for _, item in detected.items():
        if not item.get('category'):
            continue
        whistle_str = item['category']
        if translate.get(whistle_str):
            whistle_str = translate[whistle_str]
        if summary:
            if whistle_str not in summary:
                summary += ', ' + whistle_str
        else:
            summary = whistle_str
    return summary


def _get_content_license(post_json_object: {}) -> str:
    """Returns the content license for the given post
    """
    if not post_json_object['object'].get('attachment'):
        if not post_json_object['object'].get('schema:license'):
            if not post_json_object['object'].get('license'):
                return None

    if post_json_object['object'].get('schema:license'):
        value = post_json_object['object']['schema:license']
        if '://' not in value:
            value = license_link_from_name(value)
        return value

    if post_json_object['object'].get('license'):
        value = post_json_object['object']['license']
        if '://' not in value:
            value = license_link_from_name(value)
        return value

    post_attachments = get_post_attachments(post_json_object)
    for item in post_attachments:
        if not item.get('name'):
            continue
        name_lower = item['name'].lower()
        if 'license' not in name_lower and \
           'copyright' not in name_lower and \
           'licence' not in name_lower:
            continue
        if item.get('value'):
            value = remove_html(item['value'])
        elif item.get('href'):
            value = remove_html(item['href'])
        else:
            continue
        if '://' not in value:
            value = license_link_from_name(value)
        return value
    return None


def _get_copyright_footer(content_license_url: str,
                          translate: {}) -> str:
    """Returns the footer copyright link
    """
    # show the CC symbol
    icon_filename = 'license_cc.png'
    if '/zero/' in content_license_url:
        icon_filename = 'license_cc0.png'
    elif 'unlicense' in content_license_url:
        icon_filename = 'license_un.png'
    elif 'wtfpl' in content_license_url:
        icon_filename = 'license_wtf.png'
    elif '/fdl' in content_license_url:
        icon_filename = 'license_fdl.png'

    description = 'Content License'
    if translate.get('Content License'):
        description = translate['Content License']
    copyright_str = \
        '        ' + \
        '<a class="imageAnchor" href="' + content_license_url + \
        '" title="' + description + '" tabindex="10" rel="license">' + \
        '<img loading="lazy" decoding="async" title="' + \
        description + '" alt="' + description + \
        ' |" src="/icons/' + icon_filename + '"/></a>\n'

    return copyright_str


def _get_buy_footer(buy_links: {}, translate: {}) -> str:
    """Returns the footer buy link
    """
    if not buy_links:
        return ''
    icon_filename = 'buy.png'
    description = translate['Buy']
    buy_str = ''
    for _, buy_url in buy_links.items():
        buy_str = \
            '        ' + \
            '<a class="imageAnchor" href="' + buy_url + \
            '" title="' + description + '" tabindex="10">' + \
            '<img loading="lazy" decoding="async" title="' + \
            description + '" alt="' + description + \
            ' |" src="/icons/' + icon_filename + '"/></a>\n'
        break
    return buy_str


def remove_incomplete_code_tags(content: str) -> str:
    """Remove any uncompleted code tags
    """
    tags = ('code', 'pre')
    for tag_name in tags:
        if '<' + tag_name not in content and \
           '</' + tag_name not in content:
            continue
        if '<' + tag_name not in content or \
           '</' + tag_name not in content:
            content = remove_markup_tag(content, tag_name)
            continue
        if content.count('<' + tag_name) > 1 or \
           content.count('</' + tag_name) > 1:
            content = remove_markup_tag(content, tag_name)
    return content


def _mentions_to_person_options(html_str: str, nickname: str,
                                session, base_dir: str, http_prefix: str,
                                person_cache: {},
                                allow_downloads: bool,
                                signing_priv_key_pem: str,
                                mitm_servers: []) -> str:
    """Converts mentions within html into local person option links.
    This enables following of mentions in browsers which don't
    support javascript
    """
    if '"u-url mention"' not in html_str:
        return html_str

    sections = html_str.split('<')
    for markup_str in sections:
        if '>' not in markup_str:
            continue
        markup_str = markup_str.split('>')[0]
        if 'href="' not in markup_str or \
           '"u-url mention"' not in markup_str:
            continue
        link = markup_str.split('href="')[1]
        if '"' not in link:
            continue
        post_actor = link.split('"')[0]
        # look up the avatar image for the mention
        avatar_url = \
            get_avatar_image_url(session, base_dir, http_prefix,
                                 post_actor, person_cache,
                                 None, allow_downloads,
                                 signing_priv_key_pem,
                                 mitm_servers)

        replace_link = \
            "/users/" + nickname + "?options=" + post_actor + \
            ";2;" + avatar_url
        html_str = html_str.replace('href="' + post_actor + '"',
                                    'href="' + replace_link + '"')
    return html_str


def individual_post_as_html(signing_priv_key_pem: str,
                            allow_downloads: bool,
                            recent_posts_cache: {}, max_recent_posts: int,
                            translate: {},
                            page_number: int, base_dir: str,
                            session, cached_webfingers: {}, person_cache: {},
                            nickname: str, domain: str, port: int,
                            post_json_object: {},
                            avatar_url: str, show_avatar_options: bool,
                            allow_deletion: bool,
                            http_prefix: str, project_version: str,
                            box_name: str,
                            yt_replace_domain: str,
                            twitter_replacement_domain: str,
                            show_published_date_only: bool,
                            peertube_instances: [],
                            allow_local_network_access: bool,
                            theme_name: str, system_language: str,
                            max_like_count: int,
                            show_repeats: bool,
                            show_icons: bool,
                            manually_approves_followers: bool,
                            show_public_only: bool,
                            store_to_cache: bool,
                            use_cache_only: bool,
                            cw_lists: {},
                            lists_enabled: str,
                            timezone: str,
                            mitm: bool, bold_reading: bool,
                            dogwhistles: {},
                            minimize_all_images: bool,
                            first_post_id: str,
                            buy_sites: {},
                            auto_cw_cache: {},
                            mitm_servers: [],
                            instance_software: {}) -> str:
    """ Shows a single post as html
    """
    if not post_json_object:
        return ''

    # maximum number of different emoji reactions which can be added to a post
    max_reaction_types = 5

    # if downloads of avatar images aren't enabled then we can do more
    # accurate timing of different parts of the code
    enable_timing_log = not allow_downloads

    # benchmark
    post_start_time = time.time()

    post_actor = get_actor_from_post(post_json_object)

    _log_post_timing(enable_timing_log, post_start_time, '0')

    # ZZZzzz
    if is_person_snoozed(base_dir, nickname, domain, post_actor):
        return ''

    _log_post_timing(enable_timing_log, post_start_time, '1')

    avatar_position = ''
    message_id = ''
    if post_json_object.get('id'):
        message_id = remove_hash_from_post_id(post_json_object['id'])
        message_id = remove_id_ending(message_id)

    _log_post_timing(enable_timing_log, post_start_time, '2')

    # does this post have edits?
    edits_post_url = \
        remove_id_ending(message_id.strip()).replace('/', '#') + '.edits'
    account_dir = acct_dir(base_dir, nickname, domain) + '/'

    message_id_str = ''
    if message_id:
        message_id_str = ';' + message_id

    domain_full = get_full_domain(domain, port)

    page_number_param = ''
    if page_number:
        page_number_param = '?page=' + str(page_number)

    # NOTE at the time when the post is generated we don't know what
    # browser will be used to access it
    ua_str = ''

    # get the html post from the recent posts cache if it exists there
    post_html = \
        _get_post_from_recent_cache(session, base_dir,
                                    http_prefix, nickname, domain,
                                    post_json_object,
                                    post_actor,
                                    person_cache,
                                    allow_downloads,
                                    show_public_only,
                                    store_to_cache,
                                    box_name,
                                    avatar_url,
                                    enable_timing_log,
                                    post_start_time,
                                    page_number,
                                    recent_posts_cache,
                                    max_recent_posts,
                                    signing_priv_key_pem,
                                    first_post_id, ua_str,
                                    translate,
                                    mitm_servers)
    if post_html:
        return post_html
    if use_cache_only and post_json_object['type'] != 'Announce':
        return ''

    _log_post_timing(enable_timing_log, post_start_time, '4')

    avatar_url = \
        get_avatar_image_url(session,
                             base_dir, http_prefix,
                             post_actor, person_cache,
                             avatar_url, allow_downloads,
                             signing_priv_key_pem,
                             mitm_servers)

    _log_post_timing(enable_timing_log, post_start_time, '5')

    # get the display name
    if domain_full not in post_actor:
        # lookup the correct webfinger for the post_actor
        post_actor_nickname = get_nickname_from_actor(post_actor)
        if not post_actor_nickname:
            return ''
        post_actor_domain, post_actor_port = get_domain_from_actor(post_actor)
        if not post_actor_domain:
            return ''
        post_actor_domain_full = \
            get_full_domain(post_actor_domain, post_actor_port)
        post_actor_handle = post_actor_nickname + '@' + post_actor_domain_full
        post_actor_wf = \
            webfinger_handle(session, post_actor_handle, http_prefix,
                             cached_webfingers,
                             domain, __version__, False, False,
                             signing_priv_key_pem, mitm_servers)

        avatar_url2 = None
        display_name = None
        if post_actor_wf:
            origin_domain = domain
            (_, _, _, _, _, avatar_url2,
             display_name, _) = get_person_box(signing_priv_key_pem,
                                               origin_domain,
                                               base_dir, session,
                                               post_actor_wf,
                                               person_cache,
                                               project_version,
                                               http_prefix,
                                               nickname, domain,
                                               'outbox', 72367,
                                               system_language,
                                               mitm_servers)

        _log_post_timing(enable_timing_log, post_start_time, '6')

        if avatar_url2:
            avatar_url = avatar_url2
        if display_name:
            # add any emoji to the display name
            if ':' in display_name:
                display_name = \
                    add_emoji_to_display_name(session, base_dir, http_prefix,
                                              nickname, domain,
                                              display_name, False, translate)

    _log_post_timing(enable_timing_log, post_start_time, '7')

    avatar_link = \
        _get_avatar_image_html(show_avatar_options,
                               nickname, domain_full,
                               avatar_url, post_actor,
                               translate, avatar_position,
                               page_number, message_id_str)

    avatar_image_in_post = \
        '      <div class="timeline-avatar">' + avatar_link + '</div>\n'

    timeline_post_bookmark = bookmark_from_id(post_json_object['id'])

    # If this is the inbox timeline then don't show the repeat icon on any DMs
    show_repeat_icon = show_repeats
    is_public_repeat = False
    post_is_dm = is_dm(post_json_object)
    if show_repeats:
        if post_is_dm:
            show_repeat_icon = False
        else:
            if is_public_post(post_json_object):
                is_public_repeat = True

    person_url = local_actor_url(http_prefix, nickname, domain_full)
    actor_json = \
        get_person_from_cache(base_dir, person_url, person_cache)
    languages_understood: list[str] = []
    if actor_json:
        languages_understood = get_actor_languages_list(actor_json)

    title_str = ''
    gallery_str = ''
    is_announced = False
    announce_json_object = None
    if post_json_object['type'] == 'Announce':
        announce_json_object = post_json_object.copy()
        blocked_cache = {}
        block_federated: list[str] = []
        show_vote_posts = True
        show_vote_file = acct_dir(base_dir, nickname, domain) + '/.noVotes'
        if os.path.isfile(show_vote_file):
            show_vote_posts = False
        post_json_announce = \
            download_announce(session, base_dir, http_prefix,
                              nickname, domain, post_json_object,
                              project_version,
                              yt_replace_domain,
                              twitter_replacement_domain,
                              allow_local_network_access,
                              recent_posts_cache, False,
                              system_language,
                              domain_full, person_cache,
                              signing_priv_key_pem,
                              blocked_cache, block_federated,
                              bold_reading,
                              show_vote_posts,
                              languages_understood,
                              mitm_servers)
        if not post_json_announce:
            # if the announce could not be downloaded then mark it as rejected
            announced_post_id = remove_id_ending(post_json_object['id'])
            reject_post_id(base_dir, nickname, domain, announced_post_id,
                           recent_posts_cache, False)
            return ''
        post_json_object = post_json_announce

        # is the announced post in the html cache?
        post_html = \
            _get_post_from_recent_cache(session, base_dir,
                                        http_prefix, nickname, domain,
                                        post_json_object,
                                        post_actor,
                                        person_cache,
                                        allow_downloads,
                                        show_public_only,
                                        store_to_cache,
                                        box_name,
                                        avatar_url,
                                        enable_timing_log,
                                        post_start_time,
                                        page_number,
                                        recent_posts_cache,
                                        max_recent_posts,
                                        signing_priv_key_pem,
                                        first_post_id, ua_str,
                                        translate,
                                        mitm_servers)
        if post_html:
            return post_html

        announce_filename = \
            locate_post(base_dir, nickname, domain, post_json_object['id'])
        if announce_filename:
            update_announce_collection(recent_posts_cache,
                                       base_dir, announce_filename,
                                       post_actor, nickname,
                                       domain_full, False)

            # create a file for use by text-to-speech
            if is_recent_post(post_json_object, 3):
                if post_json_object.get('actor'):
                    if not os.path.isfile(announce_filename + '.tts'):
                        actor_url = get_actor_from_post(post_json_object)
                        update_speaker(base_dir, http_prefix,
                                       nickname, domain, domain_full,
                                       post_json_object, person_cache,
                                       translate, actor_url,
                                       theme_name, system_language,
                                       box_name)
                        try:
                            with open(announce_filename + '.tts', 'w+',
                                      encoding='utf-8') as fp_tts:
                                fp_tts.write('\n')
                        except OSError:
                            print('EX: unable to write tts ' +
                                  announce_filename + '.tts')

        is_announced = True

    _log_post_timing(enable_timing_log, post_start_time, '8')

    if not has_object_dict(post_json_object):
        return ''

    # if this post should be public then check its recipients
    if show_public_only:
        if not post_contains_public(post_json_object):
            return ''

    is_moderation_post = False
    if post_json_object['object'].get('moderationStatus'):
        is_moderation_post = True
    container_class = 'container'
    container_class_icons = 'containericons'
    time_class = 'time-right'
    actor_nickname = get_nickname_from_actor(post_actor)
    if not actor_nickname:
        # single user instance
        actor_nickname = 'dev'
    actor_domain, _ = get_domain_from_actor(post_actor)

    # indicate if the post has been proxied from a different protocol
    # (eg. diaspora/nostr)
    post_proxied = ap_proxy_type(post_json_object['object'])
    if post_proxied:
        post_proxied = remove_html(post_proxied)
        if resembles_url(post_proxied):
            proxy_str = 'Proxy'
            if translate.get(proxy_str):
                proxy_str = translate[proxy_str]
            post_proxied = '<a href="' + post_proxied + \
                '" target="_blank" rel="nofollow noopener noreferrer">' + \
                proxy_str + '</a>'
        elif '/' in post_proxied:
            post_proxied = post_proxied.split('/')[-1]
        title_str += '[' + post_proxied + '] '

    # scope icon before display name
    if is_followers_post(post_json_object):
        title_str += \
            '        <img loading="lazy" decoding="async" src="/' + \
            'icons/scope_followers.png" class="postScopeIcon" title="' + \
            translate['Only to followers'] + ':" alt="' + \
            translate['Only to followers'] + ':"/>\n'
    elif is_unlisted_post(post_json_object):
        title_str += \
            '        <img loading="lazy" decoding="async" src="/' + \
            'icons/scope_unlisted.png" class="postScopeIcon" title="' + \
            translate['Not on public timeline'] + ':" alt="' + \
            translate['Not on public timeline'] + ':"/>\n'
    elif is_reminder(post_json_object):
        title_str += \
            '        <img loading="lazy" decoding="async" src="/' + \
            'icons/scope_reminder.png" class="postScopeIcon" title="' + \
            translate['Scheduled note to yourself'] + ':" alt="' + \
            translate['Scheduled note to yourself'] + ':"/>\n'

    # show the display name or handle
    display_name = get_display_name(base_dir, post_actor, person_cache)
    if display_name:
        if len(display_name) < 2 or \
           display_name_is_emoji(display_name):
            display_name = None
    actor_handle = actor_nickname + '@' + actor_domain

    # MITM warning icon
    mitm_str = ''
    if mitm or actor_domain in mitm_servers:
        mitm_str = ' ' + mitm_warning_html(translate)

    if display_name:
        display_name = _enforce_max_display_name_length(display_name)
        # add emojis
        if ':' in display_name:
            display_name = \
                add_emoji_to_display_name(session, base_dir, http_prefix,
                                          nickname, domain,
                                          display_name, False, translate)
        title_str += \
            '        <a class="imageAnchor" href="/users/' + \
            nickname + '?options=' + post_actor + \
            ';' + str(page_number) + ';' + avatar_url + message_id_str + \
            '" tabindex="10" title="' + actor_handle + '">' + \
            '<span itemprop="author">' + display_name + \
            mitm_str + '</span></a>\n'
    else:
        if not message_id:
            # pprint(post_json_object)
            print('ERROR: no message_id')
        if not actor_nickname:
            # pprint(post_json_object)
            print('ERROR: no actor_nickname')
        if not actor_domain:
            # pprint(post_json_object)
            print('ERROR: no actor_domain')
        title_str += \
            '        <a class="imageAnchor" href="/users/' + \
            nickname + '?options=' + post_actor + \
            ';' + str(page_number) + ';' + avatar_url + message_id_str + \
            '" tabindex="10">' + \
            '@<span itemprop="author">' + actor_handle + \
            mitm_str + '</span></a>\n'

    # benchmark 9
    _log_post_timing(enable_timing_log, post_start_time, '9')

    # Show a DM icon for DMs in the inbox timeline
    if post_is_dm:
        title_str = \
            title_str + ' <img loading="lazy" decoding="async" src="/' + \
            'icons/dm.png" class="DMicon"/>\n'

    # check if replying is permitted
    comments_enabled = True
    if isinstance(post_json_object['object'], dict) and \
       'commentsEnabled' in post_json_object['object']:
        if post_json_object['object']['commentsEnabled'] is False:
            comments_enabled = False
        elif 'rejectReplies' in post_json_object['object']:
            if post_json_object['object']['rejectReplies']:
                comments_enabled = False

    conversation_id = None
    convthread_id = None
    if isinstance(post_json_object['object'], dict):
        # Due to lack of AP specification maintenance, a conversation can also
        # be referred to as a thread or (confusingly) "context"
        if 'conversation' in post_json_object['object']:
            if post_json_object['object']['conversation']:
                conversation_id = post_json_object['object']['conversation']
        elif 'context' in post_json_object['object']:
            if post_json_object['object']['context']:
                conversation_id = post_json_object['object']['context']
        if 'thread' in post_json_object['object']:
            if post_json_object['object']['thread']:
                convthread_id = post_json_object['object']['thread']

    public_reply = False
    unlisted_reply = False
    if is_public_post(post_json_object):
        public_reply = True
    if is_unlisted_post(post_json_object):
        public_reply = False
        unlisted_reply = True
    reply_str = _get_reply_icon_html(base_dir, nickname, domain,
                                     public_reply, unlisted_reply,
                                     show_icons, comments_enabled,
                                     post_json_object, page_number_param,
                                     translate, system_language,
                                     conversation_id, convthread_id)

    _log_post_timing(enable_timing_log, post_start_time, '10')

    edit_str = _get_edit_icon_html(base_dir, nickname, domain_full,
                                   post_json_object, actor_nickname,
                                   translate, False, first_post_id)

    _log_post_timing(enable_timing_log, post_start_time, '11')

    announce_str = \
        _get_announce_icon_html(is_announced,
                                post_actor,
                                nickname, domain_full,
                                announce_json_object,
                                post_json_object,
                                is_public_repeat,
                                is_moderation_post,
                                show_repeat_icon,
                                translate,
                                page_number_param,
                                timeline_post_bookmark,
                                box_name, max_like_count,
                                first_post_id)

    _log_post_timing(enable_timing_log, post_start_time, '12')

    # whether to show a like button
    hide_like_button_file = \
        acct_dir(base_dir, nickname, domain) + '/.hideLikeButton'
    show_like_button = True
    if os.path.isfile(hide_like_button_file):
        show_like_button = False

    # whether to show a reaction button
    hide_reaction_button_file = \
        acct_dir(base_dir, nickname, domain) + '/.hideReactionButton'
    show_reaction_button = True
    if os.path.isfile(hide_reaction_button_file):
        show_reaction_button = False

    like_json_object = post_json_object
    if announce_json_object:
        like_json_object = announce_json_object
    like_str = _get_like_icon_html(nickname, domain_full,
                                   is_moderation_post,
                                   show_like_button,
                                   like_json_object,
                                   enable_timing_log,
                                   post_start_time,
                                   translate, page_number_param,
                                   timeline_post_bookmark,
                                   box_name, max_like_count,
                                   first_post_id)

    _log_post_timing(enable_timing_log, post_start_time, '12.5')

    bookmark_str = \
        _get_bookmark_icon_html(base_dir, nickname, domain,
                                domain_full, post_json_object,
                                is_moderation_post, translate,
                                enable_timing_log,
                                post_start_time, box_name,
                                page_number_param,
                                timeline_post_bookmark,
                                first_post_id, message_id)

    _log_post_timing(enable_timing_log, post_start_time, '12.9')

    reaction_str = \
        _get_reaction_icon_html(nickname, post_json_object,
                                is_moderation_post,
                                show_reaction_button,
                                translate,
                                enable_timing_log,
                                post_start_time, box_name,
                                page_number_param,
                                timeline_post_bookmark,
                                first_post_id)

    _log_post_timing(enable_timing_log, post_start_time, '12.10')

    is_muted = post_is_muted(base_dir, nickname, domain,
                             post_json_object, message_id)

    _log_post_timing(enable_timing_log, post_start_time, '13')

    mute_str = \
        _get_mute_icon_html(is_muted,
                            post_actor,
                            message_id,
                            nickname, domain_full,
                            allow_deletion,
                            page_number_param,
                            box_name,
                            timeline_post_bookmark,
                            translate, first_post_id)

    delete_str = \
        _get_delete_icon_html(nickname, domain_full,
                              allow_deletion,
                              post_actor,
                              message_id,
                              post_json_object,
                              page_number_param,
                              translate, first_post_id)

    _log_post_timing(enable_timing_log, post_start_time, '13.1')

    # get the software instance type, such as "mastodon"
    instance_actor = post_actor
    if is_announced and post_json_object:
        announce_obj = post_json_object
        if has_object_dict(post_json_object):
            announce_obj = post_json_object['object']
        if announce_obj.get('attributedTo'):
            instance_actor = get_attributed_to(announce_obj['attributedTo'])
    instance_http_prefix = http_prefix
    if '://' in instance_actor:
        instance_http_prefix = instance_actor.split('://')[0]
    software_name = \
        get_instance_software(base_dir, session,
                              instance_http_prefix,
                              instance_actor,
                              instance_software,
                              signing_priv_key_pem,
                              False,
                              http_prefix, domain,
                              mitm_servers, True)

    # get the title: x replies to y, x announces y, etc
    (title_str2,
     reply_avatar_image_in_post,
     container_class_icons,
     container_class) = _get_post_title_html(base_dir,
                                             http_prefix,
                                             nickname, domain,
                                             is_announced,
                                             post_json_object,
                                             post_actor,
                                             translate,
                                             enable_timing_log,
                                             post_start_time,
                                             box_name,
                                             person_cache,
                                             avatar_position,
                                             page_number,
                                             message_id_str,
                                             container_class_icons,
                                             container_class, mitm,
                                             signing_priv_key_pem,
                                             session, False,
                                             mitm_servers,
                                             software_name)
    title_str += title_str2

    _log_post_timing(enable_timing_log, post_start_time, '14')

    edits_filename = account_dir + box_name + '/' + edits_post_url
    edits_str = ''
    if os.path.isfile(edits_filename):
        edits_json = load_json(edits_filename)
        if edits_json:
            edits_str = create_edits_html(edits_json, post_json_object,
                                          translate, timezone, system_language,
                                          languages_understood)

    content_str = get_content_from_post(post_json_object, system_language,
                                        languages_understood, "content")

    # remove any css styling within the post itself
    content_str = remove_style_within_html(content_str)

    content_language = \
        get_language_from_post(post_json_object, system_language,
                               languages_understood, "content")
    content_str = dont_speak_hashtags(content_str)

    attachment_str, gallery_str = \
        get_post_attachments_as_html(base_dir, nickname, domain,
                                     domain_full,
                                     post_json_object,
                                     box_name, translate,
                                     is_muted, avatar_link,
                                     reply_str, announce_str, like_str,
                                     bookmark_str, delete_str, mute_str,
                                     content_str,
                                     minimize_all_images,
                                     system_language)

    published_str = \
        _get_published_date_str(post_json_object, show_published_date_only,
                                timezone)

    _log_post_timing(enable_timing_log, post_start_time, '15')

    published_link = message_id
    # blog posts should have no /statuses/ in their link
    post_is_blog = False
    if is_blog_post(post_json_object):
        post_is_blog = True
        # is this a post to the local domain?
        if '://' + domain in message_id:
            published_link = message_id.replace('/statuses/', '/')
    # if this is a local link then make it relative so that it works
    # on clearnet or onion address
    if domain + '/users/' in published_link or \
       domain + ':' + str(port) + '/users/' in published_link:
        published_link = '/users/' + published_link.split('/users/')[1]

    content_license_url = _get_content_license(post_json_object)
    if not is_news_post(post_json_object):
        if show_icons:
            footer_str = ''
        else:
            footer_str = '<div class="' + container_class_icons + '">\n'
        if content_license_url and not is_reminder(post_json_object):
            footer_str += _get_copyright_footer(content_license_url,
                                                translate)
        post_bookmark = '#' + bookmark_from_id(published_link)
        conv_link = '/users/' + nickname + '?convthread=' + \
            published_link.replace('--', '/') + post_bookmark
        footer_str += '<a href="' + conv_link + \
            '" class="' + time_class + '" tabindex="10">' + \
            published_str + '</a>\n'
        if not show_icons:
            footer_str += '</div>\n'
    else:
        footer_str = '<a href="' + \
            published_link.replace('/news/', '/news/statuses/') + \
            '" class="' + time_class + '" tabindex="10">' + \
            published_str + '</a>\n'

    # change the background color for DMs in inbox timeline
    if post_is_dm:
        container_class_icons = 'containericons dm'
        container_class = 'container dm'

    # add any content warning from the cwlists directory
    add_cw_from_lists(post_json_object, cw_lists, translate, lists_enabled,
                      system_language, languages_understood)

    post_is_sensitive = False
    if post_json_object['object'].get('sensitive'):
        if isinstance(post_json_object['object']['sensitive'], bool):
            # sensitive posts should have a summary
            if post_json_object['object'].get('summary'):
                possible_summary = \
                    get_summary_from_post(post_json_object,
                                          system_language,
                                          languages_understood)
                if possible_summary:
                    post_is_sensitive = \
                        post_json_object['object']['sensitive']
                else:
                    # clear the summary field if it is invalid
                    post_json_object['object']['summary'] = ''

    if not post_json_object['object'].get('summary'):
        post_json_object['object']['summary'] = ''
        post_json_object['object']['summaryMap'] = {
            system_language: ''
        }

    domain_full = get_full_domain(domain, port)
    if not content_str:
        content_str = get_content_from_post(post_json_object, system_language,
                                            languages_understood, "content")
        # remove any css styling within the post itself
        content_str = remove_style_within_html(content_str)
        content_language = \
            get_language_from_post(post_json_object, system_language,
                                   languages_understood, "content")
        content_str = dont_speak_hashtags(content_str)
    if not content_str:
        content_str = \
            auto_translate_post(base_dir, post_json_object,
                                system_language, translate)
        if not content_str:
            return ''
    content_str = \
        replace_remote_hashtags(content_str, nickname, domain)

    summary_str = ''
    if content_str:
        summary_str = get_summary_from_post(post_json_object, system_language,
                                            languages_understood)
        if summary_str == 'null':
            summary_str = ''

        # add dogwhistle warnings to summary
        summary_str = _add_dogwhistle_warnings(summary_str, content_str,
                                               dogwhistles, translate)
        # add automatic content warnings
        summary_str = add_auto_cw(base_dir, nickname, domain,
                                  summary_str, content_str,
                                  auto_cw_cache)

        content_all_str = str(summary_str) + ' ' + content_str
        # does an emoji or lack of alt text on an image indicate a
        # no boost preference? if so then don't show the repeat/announce icon
        attachment = get_post_attachments(post_json_object)
        capabilities = {}
        if post_json_object['object'].get('capabilities'):
            capabilities = post_json_object['object']['capabilities']
        if disallow_announce(content_all_str, attachment, capabilities):
            announce_str = ''
        # does an emoji indicate a no replies preference?
        # if so then don't show the reply icon
        if disallow_reply(content_all_str):
            reply_str = ''

    is_patch = is_git_patch(base_dir, nickname, domain,
                            post_json_object['object']['type'],
                            summary_str, content_str)

    # html for the buy icon
    buy_str = ''
    post_attachments = get_post_attachments(post_json_object['object'])
    if not post_attachments:
        post_json_object['object']['attachment']: list[dict] = []
    if not is_patch:
        buy_links = get_buy_links(post_json_object, translate, buy_sites)
        buy_str = _get_buy_footer(buy_links, translate)

    new_footer_str = \
        _get_footer_with_icons(show_icons,
                               container_class_icons,
                               reply_str, announce_str,
                               like_str, reaction_str, bookmark_str,
                               delete_str, mute_str, edit_str, buy_str,
                               post_json_object, published_link,
                               time_class, published_str, nickname,
                               content_license_url, translate)
    if new_footer_str:
        footer_str = new_footer_str

    # add an extra line if there is a content warning,
    # for better vertical spacing on mobile
    if post_is_sensitive:
        footer_str = '<br>' + footer_str

    if not summary_str:
        summary_str = get_summary_from_post(post_json_object, system_language,
                                            languages_understood)
        if summary_str == 'null':
            summary_str = ''
        if content_str:
            # add dogwhistle warnings to summary
            summary_str = _add_dogwhistle_warnings(summary_str, content_str,
                                                   dogwhistles, translate)
            # add automatic content warnings
            summary_str = add_auto_cw(base_dir, nickname, domain,
                                      summary_str, content_str,
                                      auto_cw_cache)

    if summary_str and not post_is_sensitive:
        post_is_sensitive = True

    _log_post_timing(enable_timing_log, post_start_time, '16')

    if not is_pgp_encrypted(content_str):
        # if we are on an onion instance then substitute any common clearnet
        # domains with their onion version
        if '.onion' in domain and '://' in content_str:
            content_str = \
                _substitute_onion_domains(base_dir, content_str)
        if not is_patch:
            # remove any tabs
            content_str = \
                content_str.replace('\t', '').replace('\r', '')
            # Add bold text
            if bold_reading and \
               not post_is_blog:
                content_str = bold_reading_string(content_str)

            object_content = remove_header_tags(content_str)
            object_content = \
                remove_link_trackers_from_content(object_content)
            object_content = \
                remove_long_words(object_content, 40, [])
            object_content = \
                remove_text_formatting(object_content, bold_reading)
            object_content = limit_repeated_words(object_content, 6)
            object_content = \
                switch_words(base_dir, nickname, domain, object_content)
            object_content = html_replace_email_quote(object_content)
            object_content = html_replace_quote_marks(object_content)
            object_content = \
                format_mixed_right_to_left(object_content, system_language)
            # show quote toot link
            quote_url = get_quote_toot_url(post_json_object)
            if quote_url:
                quote_url = quote_url.replace('.json', '')
                quote_url_shown = quote_url
                if len(quote_url_shown) > 40:
                    quote_url_shown = quote_url_shown[:40]
                object_content += '<p>💬 <a href="' + quote_url + \
                    '" target="_blank" ' + \
                    'rel="nofollow noopener noreferrer">' + \
                    quote_url_shown + '</a></p>\n'
            # append any edits
            object_content += edits_str
        else:
            object_content = content_str
    else:
        encrypted_str = 'Encrypted'
        if translate.get(encrypted_str):
            encrypted_str = translate[encrypted_str]
        object_content = '🔒 ' + encrypted_str

    object_content = remove_incomplete_code_tags(object_content)

    object_content = \
        '<article><span itemprop="articleBody">' + \
        object_content + '</span></article>'

    if not post_is_sensitive:
        content_str = object_content + attachment_str
        content_str = add_embedded_elements(translate, content_str,
                                            peertube_instances, domain)
        content_str = insert_question(base_dir, translate,
                                      nickname, domain,
                                      content_str, post_json_object,
                                      page_number)
    else:
        post_id = 'post' + str(create_password(8))
        content_str = ''
        if summary_str:
            # set the content warning
            cw_str = \
                add_emoji_to_display_name(session, base_dir, http_prefix,
                                          nickname, domain,
                                          summary_str, False, translate)
            content_str += \
                '<label class="cw"><span itemprop="description">' + \
                cw_str + '</span></label>\n'
            if is_moderation_post:
                container_class = 'container report'
        # get the content warning text
        cw_content_str = object_content + attachment_str
        if not is_patch:
            cw_content_str = add_embedded_elements(translate, cw_content_str,
                                                   peertube_instances,
                                                   domain_full)
            cw_content_str = \
                insert_question(base_dir, translate, nickname, domain,
                                cw_content_str, post_json_object, page_number)
            cw_content_str = \
                switch_words(base_dir, nickname, domain, cw_content_str)
        if not is_blog_post(post_json_object):
            # get the content warning button
            content_str += \
                get_content_warning_button(post_id, translate, cw_content_str)
        else:
            content_str += cw_content_str

    _log_post_timing(enable_timing_log, post_start_time, '17')

    map_str = ''
    buy_links = {}
    if post_json_object['object'].get('tag'):
        if not is_patch:
            content_str = \
                replace_emoji_from_tags(session, base_dir, content_str,
                                        post_json_object['object']['tag'],
                                        'content', False, True)
            buy_links = get_buy_links(post_json_object, translate, buy_sites)
        # show embedded map if the location contains a map url
        location_str = get_location_from_post(post_json_object)
        if location_str:
            if resembles_url(location_str):
                bounding_box_degrees = 0.001
                map_str = \
                    html_open_street_map(location_str,
                                         bounding_box_degrees,
                                         translate, session,
                                         session, session)
                if map_str:
                    map_str = '<center>\n' + map_str + '</center>\n'
        attrib = None
        if post_json_object['object'].get('attributedTo'):
            attrib = \
                get_attributed_to(post_json_object['object']['attributedTo'])
        if map_str and attrib:
            # is this being sent by the author?
            if '://' + domain_full + '/users/' + nickname in attrib:
                location_domain = location_str
                if '://' in location_str:
                    location_domain = location_str.split('://')[1]
                    if '/' in location_domain:
                        location_domain = location_domain.split('/')[0]
                    location_domain = \
                        location_str.split('://')[0] + '://' + location_domain
                else:
                    if '/' in location_domain:
                        location_domain = location_domain.split('/')[0]
                    location_domain = 'https://' + location_domain
                # remember the map site used
                set_map_preferences_url(base_dir, nickname, domain,
                                        location_domain)
                # remember the coordinates
                map_zoom, map_latitude, map_longitude = \
                    geocoords_from_map_link(location_str,
                                            'openstreetmap.org',
                                            session)
                if map_zoom and map_latitude and map_longitude:
                    set_map_preferences_coords(base_dir, nickname, domain,
                                               map_latitude, map_longitude,
                                               map_zoom)

    if is_muted:
        content_str = ''
    else:
        if not is_patch:
            message_class = 'message'
            if language_right_to_left(content_language):
                message_class = 'message_rtl'
            content_str = '      <div class="' + message_class + '">' + \
                content_str + \
                '      </div>\n'
        else:
            content_str = \
                '<div class="gitpatch"><pre><code>' + content_str + \
                '</code></pre></div>\n'

    # show blog citations
    citations_str = \
        _get_blog_citations_html(box_name, post_json_object, translate)

    post_html = ''
    if box_name != 'tlmedia':
        reaction_str = ''
        if show_icons:
            reaction_str = \
                html_emoji_reactions(post_json_object, True, person_url,
                                     max_reaction_types,
                                     box_name, page_number)
            if post_is_sensitive and reaction_str:
                reaction_str = '<br>' + reaction_str
        post_html = '    <div ' + \
            'itemprop="hasPart" ' + \
            'itemscope itemtype="http://schema.org/SocialMediaPosting" ' + \
            'id="' + timeline_post_bookmark + \
            '" class="' + container_class + '">\n'
        post_html += avatar_image_in_post
        post_html += '      <div class="post-title">\n' + \
            '        ' + title_str + \
            reply_avatar_image_in_post + '      </div>\n'
        post_html += \
            content_str + citations_str + map_str + \
            reaction_str + footer_str + '\n'
        post_html += '    </div>\n'
    else:
        post_html = gallery_str

    _log_post_timing(enable_timing_log, post_start_time, '18')

    # convert mentions into local person options links
    # This enables following of mentions in browsers which don't
    # support javascript
    post_html = \
        _mentions_to_person_options(post_html, nickname,
                                    session, base_dir, http_prefix,
                                    person_cache,
                                    allow_downloads,
                                    signing_priv_key_pem,
                                    mitm_servers)

    # save the created html to the recent posts cache
    if not show_public_only and store_to_cache and \
       box_name != 'tlmedia' and box_name != 'tlbookmarks' and \
       box_name != 'bookmarks':
        cached_json = post_json_object
        if announce_json_object:
            cached_json = announce_json_object
        _save_individual_post_as_html_to_cache(base_dir, nickname, domain,
                                               cached_json, post_html)
        update_recent_posts_cache(recent_posts_cache, max_recent_posts,
                                  cached_json, post_html)

    _log_post_timing(enable_timing_log, post_start_time, '19')

    return post_html


def html_individual_post(recent_posts_cache: {}, max_recent_posts: int,
                         translate: {},
                         base_dir: str, session, cached_webfingers: {},
                         person_cache: {},
                         nickname: str, domain: str, port: int,
                         authorized: bool,
                         post_json_object: {}, http_prefix: str,
                         project_version: str, liked_by: str,
                         react_by: str, react_emoji: str,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         show_published_date_only: bool,
                         peertube_instances: [],
                         allow_local_network_access: bool,
                         theme_name: str, system_language: str,
                         max_like_count: int, signing_priv_key_pem: str,
                         cw_lists: {}, lists_enabled: str,
                         timezone: str, mitm: bool,
                         bold_reading: bool, dogwhistles: {},
                         min_images_for_accounts: [],
                         buy_sites: {},
                         auto_cw_cache: {}, mitm_servers: [],
                         instance_software: {}) -> str:
    """Show an individual post as html
    """
    original_post_json = post_json_object
    post_str = ''
    by_str = ''
    by_text = ''
    by_text_extra = ''
    if liked_by:
        by_str = liked_by
        by_text = 'Liked by'
    elif react_by and react_emoji:
        by_str = react_by
        by_text = 'Reaction by'
        by_text_extra = ' ' + react_emoji

    if by_str:
        by_str_nickname = get_nickname_from_actor(by_str)
        if not by_str_nickname:
            return ''
        by_str_domain, by_str_port = get_domain_from_actor(by_str)
        if not by_str_domain:
            return ''
        by_str_domain = get_full_domain(by_str_domain, by_str_port)
        by_str_handle = by_str_nickname + '@' + by_str_domain
        if translate.get(by_text):
            by_text = translate[by_text]
        # Liked by handle
        domain_full = get_full_domain(domain, port)
        actor = '/users/' + nickname
        post_str += \
            '<p>' + by_text + ' '
        post_str += \
            '<form method="POST" accept-charset="UTF-8" action="' + \
            actor + '/searchhandle">\n' + \
            '<input type="hidden" ' + \
            'name="actor" value="' + actor + '">' + \
            '<input type="hidden" ' + \
            'name="searchtext" value="' + by_str + \
            '"><button type="submit" ' + \
            'class="followApproveHandle" ' + \
            'name="submitSearch" tabindex="10">' + \
            by_str_handle + '</button></form>'
        post_str += by_text_extra + '\n'
        follow_str = '  <form method="POST" ' + \
            'accept-charset="UTF-8" action="' + actor + '/searchhandle">\n'
        follow_str += \
            '    <input type="hidden" name="actor" value="' + actor + '">\n'
        follow_str += \
            '    <input type="hidden" name="searchtext" value="' + \
            by_str_handle + '">\n'
        if not is_following_actor(base_dir, nickname, domain_full, by_str):
            translate_follow_str = 'Follow'
            if translate.get(translate_follow_str):
                translate_follow_str = translate[translate_follow_str]
            follow_str += '    <button type="submit" class="button" ' + \
                'name="submitSearch">' + translate_follow_str + '</button>\n'
        go_back_str = 'Go Back'
        if translate.get(go_back_str):
            go_back_str = translate[go_back_str]
        follow_str += '    <button type="submit" class="button" ' + \
            'name="submitBack">' + go_back_str + '</button>\n'
        follow_str += '  </form>\n'
        post_str += follow_str + '</p>\n'

    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    post_str += \
        individual_post_as_html(signing_priv_key_pem,
                                True, recent_posts_cache, max_recent_posts,
                                translate, None,
                                base_dir, session,
                                cached_webfingers, person_cache,
                                nickname, domain, port, post_json_object,
                                None, True, False,
                                http_prefix, project_version, 'inbox',
                                yt_replace_domain,
                                twitter_replacement_domain,
                                show_published_date_only,
                                peertube_instances,
                                allow_local_network_access, theme_name,
                                system_language, max_like_count,
                                False, authorized, False, False, False, False,
                                cw_lists, lists_enabled, timezone, mitm,
                                bold_reading, dogwhistles,
                                minimize_all_images, None, buy_sites,
                                auto_cw_cache, mitm_servers,
                                instance_software)
    message_id = remove_id_ending(post_json_object['id'])

    # show the previous posts
    obj = post_json_object
    if has_object_dict(post_json_object):
        obj = post_json_object['object']
        post_id = True
        while post_id:
            if not post_json_object:
                break
            post_id = get_reply_to(post_json_object['object'])
            if not post_id:
                break
            post_filename = \
                locate_post(base_dir, nickname, domain, post_id)
            if not post_filename:
                break
            post_json_object = load_json(post_filename)
            if post_json_object:
                mitm = False
                if os.path.isfile(post_filename.replace('.json', '') +
                                  '.mitm'):
                    mitm = True
                post_str = \
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
                                            http_prefix, project_version,
                                            'inbox',
                                            yt_replace_domain,
                                            twitter_replacement_domain,
                                            show_published_date_only,
                                            peertube_instances,
                                            allow_local_network_access,
                                            theme_name, system_language,
                                            max_like_count,
                                            False, authorized,
                                            False, False, False, False,
                                            cw_lists, lists_enabled,
                                            timezone, mitm,
                                            bold_reading,
                                            dogwhistles,
                                            minimize_all_images,
                                            None, buy_sites,
                                            auto_cw_cache,
                                            mitm_servers,
                                            instance_software) + post_str

    # show the following posts
    post_filename = locate_post(base_dir, nickname, domain, message_id)
    if post_filename:
        # is there a replies file for this post?
        replies_filename = post_filename.replace('.json', '.replies')
        if os.path.isfile(replies_filename):
            # get items from the replies file
            replies_json = {
                'orderedItems': []
            }
            populate_replies_json(base_dir, nickname, domain,
                                  replies_filename, authorized, replies_json)
            # add items to the html output
            for item in replies_json['orderedItems']:
                post_str += \
                    individual_post_as_html(signing_priv_key_pem,
                                            True, recent_posts_cache,
                                            max_recent_posts,
                                            translate, None,
                                            base_dir, session,
                                            cached_webfingers,
                                            person_cache,
                                            nickname, domain, port, item,
                                            None, True, False,
                                            http_prefix, project_version,
                                            'inbox',
                                            yt_replace_domain,
                                            twitter_replacement_domain,
                                            show_published_date_only,
                                            peertube_instances,
                                            allow_local_network_access,
                                            theme_name, system_language,
                                            max_like_count,
                                            False, authorized,
                                            False, False, False, False,
                                            cw_lists, lists_enabled,
                                            timezone, False,
                                            bold_reading, dogwhistles,
                                            minimize_all_images, None,
                                            buy_sites, auto_cw_cache,
                                            mitm_servers, instance_software)
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    metadata_str = _html_post_metadata_open_graph(domain, original_post_json,
                                                  system_language)
    if post_json_object.get('id'):
        # https://swicg.github.io/activitypub-html-discovery/#html-link-element
        # link to the activitypub post
        post_id = remove_id_ending(post_json_object['id'])
        metadata_str += \
            '    <link rel="alternate" type="application/activity+json" ' + \
            'href="' + post_id + '" />\n'
        # https://swicg.github.io/
        # activitypub-html-discovery/#discovering-author-html
        # link to the author's actor
        if obj.get('attributedTo'):
            actor = get_attributed_to(obj['attributedTo'])
            if actor:
                metadata_str += \
                    '    <link rel="author" ' + \
                    'type="application/activity+json" ' + \
                    'href="' + actor + '" />\n'
    preload_images: list[str] = []
    header_str = html_header_with_external_style(css_filename,
                                                 instance_title, metadata_str,
                                                 preload_images)

    return header_str + post_str + html_footer()


def html_post_replies(recent_posts_cache: {}, max_recent_posts: int,
                      translate: {}, base_dir: str,
                      session, cached_webfingers: {}, person_cache: {},
                      nickname: str, domain: str, port: int, replies_json: {},
                      http_prefix: str, project_version: str,
                      yt_replace_domain: str,
                      twitter_replacement_domain: str,
                      show_published_date_only: bool,
                      peertube_instances: [],
                      allow_local_network_access: bool,
                      theme_name: str, system_language: str,
                      max_like_count: int,
                      signing_priv_key_pem: str, cw_lists: {},
                      lists_enabled: str,
                      timezone: str, bold_reading: bool,
                      dogwhistles: {},
                      min_images_for_accounts: [],
                      buy_sites: {},
                      auto_cw_cache: {},
                      mitm_servers: [],
                      instance_software: {}) -> str:
    """Show the replies to an individual post as html
    """
    replies_str = ''
    if replies_json.get('orderedItems'):
        minimize_all_images = False
        if nickname in min_images_for_accounts:
            minimize_all_images = True
        for item in replies_json['orderedItems']:
            replies_str += \
                individual_post_as_html(signing_priv_key_pem,
                                        True, recent_posts_cache,
                                        max_recent_posts,
                                        translate, None,
                                        base_dir, session, cached_webfingers,
                                        person_cache,
                                        nickname, domain, port, item,
                                        None, True, False,
                                        http_prefix, project_version, 'inbox',
                                        yt_replace_domain,
                                        twitter_replacement_domain,
                                        show_published_date_only,
                                        peertube_instances,
                                        allow_local_network_access,
                                        theme_name, system_language,
                                        max_like_count,
                                        False, False, False, False,
                                        False, False,
                                        cw_lists, lists_enabled,
                                        timezone, False,
                                        bold_reading, dogwhistles,
                                        minimize_all_images, None,
                                        buy_sites, auto_cw_cache,
                                        mitm_servers,
                                        instance_software)

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = get_config_param(base_dir, 'instanceTitle')
    metadata = ''
    preload_images: list[str] = []
    header_str = \
        html_header_with_external_style(css_filename, instance_title, metadata,
                                        preload_images)
    return header_str + replies_str + html_footer()


def html_emoji_reaction_picker(recent_posts_cache: {}, max_recent_posts: int,
                               translate: {},
                               base_dir: str, session, cached_webfingers: {},
                               person_cache: {},
                               nickname: str, domain: str, port: int,
                               post_json_object: {}, http_prefix: str,
                               project_version: str,
                               yt_replace_domain: str,
                               twitter_replacement_domain: str,
                               show_published_date_only: bool,
                               peertube_instances: [],
                               allow_local_network_access: bool,
                               theme_name: str, system_language: str,
                               max_like_count: int, signing_priv_key_pem: str,
                               cw_lists: {}, lists_enabled: str,
                               box_name: str, page_number: int,
                               timezone: str, bold_reading: bool,
                               dogwhistles: {},
                               min_images_for_accounts: [],
                               buy_sites: {},
                               auto_cw_cache: {},
                               mitm_servers: [],
                               instance_software: {}) -> str:
    """Returns the emoji picker screen
    """
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    reacted_to_post_str = \
        '<br><center><label class="followText">' + \
        translate['Select reaction'].title() + '</label></center>\n' + \
        individual_post_as_html(signing_priv_key_pem,
                                True, recent_posts_cache,
                                max_recent_posts,
                                translate, None,
                                base_dir, session, cached_webfingers,
                                person_cache,
                                nickname, domain, port, post_json_object,
                                None, True, False,
                                http_prefix, project_version, 'inbox',
                                yt_replace_domain,
                                twitter_replacement_domain,
                                show_published_date_only,
                                peertube_instances,
                                allow_local_network_access,
                                theme_name, system_language,
                                max_like_count,
                                False, False, False, False, False, False,
                                cw_lists, lists_enabled, timezone, False,
                                bold_reading, dogwhistles,
                                minimize_all_images, None, buy_sites,
                                auto_cw_cache, mitm_servers,
                                instance_software)

    reactions_filename = base_dir + '/emoji/reactions.json'
    if not os.path.isfile(reactions_filename):
        reactions_filename = base_dir + '/emoji/default_reactions.json'
    reactions_json = load_json(reactions_filename)
    emoji_picks_str = ''
    base_url = '/users/' + nickname
    post_id = remove_id_ending(post_json_object['id'])
    actor_url = get_actor_from_post(post_json_object)
    for _, item in reactions_json.items():
        emoji_picks_str += '<div class="container">\n'
        for emoji_content in item:
            emoji_content_encoded = urllib.parse.quote_plus(emoji_content)
            emoji_url = \
                base_url + '?react=' + post_id + \
                '?actor=' + actor_url + \
                '?tl=' + box_name + \
                '?page=' + str(page_number) + \
                '?emojreact=' + emoji_content_encoded
            emoji_label = '<label class="rlab">' + emoji_content + '</label>'
            emoji_picks_str += \
                '  <a href="' + emoji_url + '" tabindex="10">' + \
                emoji_label + '</a>\n'
        emoji_picks_str += '</div>\n'

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    # filename of the banner shown at the top
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme_name)

    instance_title = get_config_param(base_dir, 'instanceTitle')
    metadata = ''
    preload_images: list[str] = []
    header_str = \
        html_header_with_external_style(css_filename, instance_title, metadata,
                                        preload_images)

    # banner
    header_str += \
        '<header>\n' + \
        '<a href="/users/' + nickname + '/' + box_name + \
        '?page=' + str(page_number) + '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '" tabindex="10">\n'
    header_str += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" alt="" ' + \
        'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
        '</header>\n'

    return header_str + reacted_to_post_str + emoji_picks_str + html_footer()
