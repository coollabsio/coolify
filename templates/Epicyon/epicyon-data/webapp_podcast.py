__filename__ = "webapp_podcast.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface Columns"

import os
import html
import datetime
import urllib.parse
from shutil import copyfile
from utils import resembles_url
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import data_dir
from utils import get_url_from_post
from utils import get_config_param
from utils import remove_html
from media import path_is_audio
from content import safe_web_text
from webapp_utils import get_broken_link_substitute
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import html_keyboard_navigation
from session import get_json_valid
from session import get_json

MAX_LINK_LENGTH = 40


def _html_podcast_chapters(link_url: str,
                           session, session_onion, session_i2p,
                           http_prefix: str, domain: str,
                           podcast_properties: {},
                           debug: bool,
                           mitm_servers: []) -> str:
    """Returns html for chapters of a podcast
    """
    if not podcast_properties:
        return ''
    key = 'chapters'
    if not podcast_properties.get(key):
        return ''
    if not isinstance(podcast_properties[key], dict):
        return ''
    if podcast_properties[key].get('url'):
        url_str = get_url_from_post(podcast_properties[key]['url'])
        chapters_url = remove_html(url_str)
    elif podcast_properties[key].get('uri'):
        chapters_url = podcast_properties[key]['uri']
    else:
        return ''
    html_str = ''
    if podcast_properties[key].get('type'):
        url_type = podcast_properties[key]['type']

        curr_session = session
        if chapters_url.endswith('.onion'):
            curr_session = session_onion
        elif chapters_url.endswith('.i2p'):
            curr_session = session_i2p

        as_header = {
            'Accept': url_type
        }

        if 'json' in url_type:
            chapters_json = \
                get_json(None, curr_session, chapters_url,
                         as_header, None, debug, mitm_servers, __version__,
                         http_prefix, domain)
            if not get_json_valid(chapters_json):
                return ''
            if not chapters_json.get('chapters'):
                return ''
            if not isinstance(chapters_json['chapters'], list):
                return ''
            chapters_html = ''
            for chapter in chapters_json['chapters']:
                if not isinstance(chapter, dict):
                    continue
                if not chapter.get('title'):
                    continue
                if not chapter.get('startTime'):
                    continue
                chapter_title = chapter['title']
                chapter_url = ''
                if chapter.get('url'):
                    url_str = get_url_from_post(chapter['url'])
                    chapter_url = remove_html(url_str)
                    chapter_title = \
                        '<a href="' + chapter_url + '">' + \
                        chapter['title'] + '<\a>'
                start_sec = chapter['startTime']
                skip_url = link_url + '#t=' + str(start_sec)
                start_time_str = \
                    '<a href="' + skip_url + '">' + \
                    str(datetime.timedelta(seconds=start_sec)) + \
                    '</a>'
                if chapter.get('img'):
                    chapters_html += \
                        '    <li>\n' + \
                        '      ' + start_time_str + '\n' + \
                        '      <img loading="lazy" ' + \
                        'decoding="async" ' + \
                        'src="' + chapter['img'] + \
                        '" alt="" />\n' + \
                        '      ' + chapter_title + '\n' + \
                        '    </li>\n'
            if chapters_html:
                html_str = \
                    '<div class="chapters">\n' + \
                    '  <u>\n' + chapters_html + '  </u>\n</div>\n'
    return html_str


def _html_podcast_transcripts(podcast_properties: {}, translate: {}) -> str:
    """Returns html for transcripts of a podcast
    """
    if not podcast_properties:
        return ''
    key = 'transcripts'
    if not podcast_properties.get(key):
        return ''
    if not isinstance(podcast_properties[key], list):
        return ''
    ctr = 1
    html_str = ''
    for _ in podcast_properties[key]:
        transcript_url = None
        if podcast_properties[key].get('url'):
            url_str = get_url_from_post(podcast_properties[key]['url'])
            transcript_url = remove_html(url_str)
        elif podcast_properties[key].get('uri'):
            transcript_url = podcast_properties[key]['uri']
        if not transcript_url:
            continue
        if ctr > 1:
            html_str += '<br>'
        html_str += '<a href="' + transcript_url + '">'
        html_str += translate['Transcript']
        if ctr > 1:
            html_str += ' ' + str(ctr)
        html_str += '</a>\n'
        ctr += 1
    return html_str


def _html_podcast_social_interactions(podcast_properties: {},
                                      translate: {},
                                      nickname: str) -> str:
    """Returns html for social interactions with a podcast
    """
    if not podcast_properties:
        return ''
    key = 'discussion'
    if not podcast_properties.get(key):
        key = 'socialInteract'
        if not podcast_properties.get(key):
            return ''
    if not isinstance(podcast_properties[key], dict):
        return ''
    if podcast_properties[key].get('uri'):
        episode_post_url = podcast_properties[key]['uri']
    elif podcast_properties[key].get('url'):
        url_str = get_url_from_post(podcast_properties[key]['url'])
        episode_post_url = remove_html(url_str)
    elif podcast_properties[key].get('text'):
        episode_post_url = podcast_properties[key]['text']
    else:
        return ''
    actor_str = ''
    podcast_account_id = None
    if podcast_properties[key].get('accountId'):
        podcast_account_id = podcast_properties[key]['accountId']
    elif podcast_properties[key].get('podcastAccountUrl'):
        podcast_account_id = \
            podcast_properties[key]['podcastAccountUrl']
    if podcast_account_id:
        actor_handle = podcast_account_id
        if actor_handle.startswith('@'):
            actor_handle = actor_handle[1:]
        actor_str = '?actor=' + actor_handle

    podcast_str = \
        '<center>\n' + \
        '  <a href="/users/' + nickname + \
        '?replyto=' + episode_post_url + actor_str + '" target="_blank" ' + \
        'rel="nofollow noopener noreferrer">💬 ' + \
        translate['Leave a comment'] + '</a>\n' + \
        '  <span itemprop="comment">\n' + \
        '  <a href="' + episode_post_url + '" target="_blank" ' + \
        'rel="nofollow noopener noreferrer">' + \
        translate['View comments'] + '</a>\n  </span>\n' + \
        '</center>\n'
    return podcast_str


def _html_podcast_performers(podcast_properties: {}) -> str:
    """Returns html for performers of a podcast
    """
    if not podcast_properties:
        return ''
    key = 'persons'
    if not podcast_properties.get(key):
        return ''
    if not isinstance(podcast_properties[key], list):
        return ''

    # list of performers
    podcast_str = '<div class="performers">\n'
    podcast_str += '  <center>\n'
    podcast_str += '<ul>\n'
    for performer in podcast_properties[key]:
        if not performer.get('text'):
            continue
        performer_name = \
            '<span itemprop="name">' + performer['text'] + '</span>'
        performer_title = performer_name

        if performer.get('role'):
            performer_title += \
                ' (<span itemprop="hasOccupation">' + \
                performer['role'] + '</span>)'
        if performer.get('group'):
            performer_title += ', <i>' + performer['group'] + '</i>'
        performer_title = remove_html(performer_title)

        performer_url = ''
        if performer.get('href'):
            performer_url = remove_html(performer['href'])

        performer_img = ''
        if performer.get('img'):
            performer_img = performer['img']

        podcast_str += '  <li>\n'
        podcast_str += '    <figure>\n'
        podcast_str += '    <span itemprop="creator" ' + \
            'itemscope itemtype="https://schema.org/Person">\n'
        podcast_str += \
            '      <a href="' + performer_url + '" itemprop="url">\n'
        podcast_str += \
            '        <img loading="lazy" decoding="async" ' + \
            'src="' + performer_img + '" alt="" itemprop="image" />\n'
        podcast_str += \
            '      <figcaption>' + performer_title + '</figcaption>\n'
        podcast_str += '      </a>\n'
        podcast_str += '    </span></figure>\n'
        podcast_str += '  </li>\n'

    podcast_str += '</ul>\n'
    podcast_str += '</div>\n'
    return podcast_str


def _html_podcast_soundbites(link_url: str, extension: str,
                             podcast_properties: {},
                             translate: {}) -> str:
    """Returns html for podcast soundbites
    """
    if not podcast_properties:
        return ''
    if not podcast_properties.get('soundbites'):
        return ''

    podcast_str = '<div class="performers">\n'
    podcast_str += '  <center>\n'
    podcast_str += '<ul>\n'
    ctr = 1
    for performer in podcast_properties['soundbites']:
        if not performer.get('startTime'):
            continue
        if not performer['startTime'].isdigit():
            continue
        if not performer.get('duration'):
            continue
        if not performer['duration'].isdigit():
            continue
        end_time = str(float(performer['startTime']) +
                       float(performer['duration']))

        podcast_str += '  <li>\n'
        preview_url = \
            link_url + '#t=' + performer['startTime'] + ',' + end_time
        soundbite_title = translate['Preview']
        if ctr > 0:
            soundbite_title += ' ' + str(ctr)
        podcast_str += \
            '    <span itemprop="trailer">\n' + \
            '    <audio controls tabindex="10">\n' + \
            '    <p>' + soundbite_title + '</p>\n' + \
            '    <source src="' + preview_url + '" type="audio/' + \
            extension.replace('.', '') + '">' + \
            translate['Your browser does not support the audio element.'] + \
            '</audio>\n    </span>\n'
        podcast_str += '  </li>\n'
        ctr += 1

    podcast_str += '</ul>\n'
    podcast_str += '</div>\n'
    return podcast_str


def html_podcast_episode(translate: {},
                         base_dir: str, nickname: str, domain: str,
                         newswire_item: [],
                         text_mode_banner: str,
                         session, session_onion, session_i2p,
                         http_prefix: str, debug: bool,
                         mitm_servers: []) -> str:
    """Returns html for a podcast episode, an item from the newswire
    """
    css_filename = base_dir + '/epicyon-podcast.css'
    if os.path.isfile(base_dir + '/podcast.css'):
        css_filename = base_dir + '/podcast.css'

    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/podcast-background-custom.jpg'):
        if not os.path.isfile(dir_str + '/podcast-background.jpg'):
            copyfile(dir_str + '/podcast-background.jpg',
                     dir_str + '/podcast-background.jpg')

    instance_title = get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    podcast_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    podcast_properties = newswire_item[8]
    image_url = ''
    image_src = 'src'
    if podcast_properties.get('images'):
        if podcast_properties['images'].get('srcset'):
            image_url = podcast_properties['images']['srcset']
            image_src = 'srcset'
    if not image_url and podcast_properties.get('image'):
        image_url = podcast_properties['image']

    link_url = newswire_item[1]

    podcast_str += html_keyboard_navigation(text_mode_banner, {}, {},
                                            None, None, None, False)
    podcast_str += '<br><br>\n'
    podcast_str += \
        '<div class="options" itemscope ' + \
        'itemtype="http://schema.org/PodcastEpisode">\n'
    podcast_str += '  <div class="optionsAvatar">\n'
    podcast_str += '    <center>\n'
    podcast_str += '    <a href="' + link_url + '" itemprop="url">\n'
    podcast_str += '    <span itemprop="image">\n'
    if image_src == 'srcset':
        podcast_str += '    <img loading="lazy" decoding="async" ' + \
            'srcset="' + image_url + \
            '" alt="" ' + get_broken_link_substitute() + '/>\n'
    else:
        podcast_str += '    <img loading="lazy" decoding="async" ' + \
            'src="' + image_url + \
            '" alt="" ' + get_broken_link_substitute() + '/>\n'
    podcast_str += '    </span></a>\n'
    podcast_str += '    </center>\n'
    podcast_str += '  </div>\n'

    podcast_str += '  <center>\n'
    audio_extension = None
    if path_is_audio(link_url):
        if '.mp3' in link_url:
            audio_extension = 'mpeg'
        elif '.opus' in link_url:
            audio_extension = 'opus'
        elif '.spx' in link_url:
            audio_extension = 'spx'
        elif '.flac' in link_url:
            audio_extension = 'flac'
        elif '.wav' in link_url:
            audio_extension = 'wav'
        else:
            audio_extension = 'ogg'
    else:
        if podcast_properties.get('linkMimeType'):
            if 'audio' in podcast_properties['linkMimeType']:
                audio_extension = \
                    podcast_properties['linkMimeType'].split('/')[1]
    # show widgets for soundbites
    if audio_extension:
        podcast_str += _html_podcast_soundbites(link_url, audio_extension,
                                                podcast_properties,
                                                translate)

        # podcast player widget
        podcast_str += \
            '  <span itemprop="audio">\n' + \
            '  <audio controls tabindex="10">\n' + \
            '    <source src="' + link_url + '" type="audio/' + \
            audio_extension.replace('.', '') + '">' + \
            translate['Your browser does not support the audio element.'] + \
            '\n  </audio>\n  </span>\n'
    elif podcast_properties.get('linkMimeType'):
        if '/youtube' in podcast_properties['linkMimeType']:
            url = link_url.replace('/watch?v=', '/embed/')
            if '&' in url:
                url = url.split('&')[0]
            if '?utm_' in url:
                url = url.split('?utm_')[0]
            podcast_str += \
                '  <span itemprop="video">\n' + \
                "  <iframe loading=\"lazy\" decoding=\"async\" src=\"" + \
                url + "\" width=\"400\" height=\"300\" " + \
                "frameborder=\"0\" allow=\"fullscreen\" " + \
                "allowfullscreen " + \
                "sandbox=\"allow-scripts allow-same-origin\">\n" + \
                "  </iframe>\n  </span>\n"
        elif 'video' in podcast_properties['linkMimeType']:
            video_mime_type = podcast_properties['linkMimeType']
            video_msg = 'Your browser does not support the video element.'
            podcast_str += \
                '  <span itemprop="video">\n' + \
                '  <figure id="videoContainer" ' + \
                'data-fullscreen="false">\n' + \
                '    <video id="video" controls preload="metadata" ' + \
                'tabindex="10">\n' + \
                '<source src="' + link_url + '" ' + \
                'type="' + video_mime_type + '">' + \
                translate[video_msg] + \
                '</video>\n  </figure>\n  </span>\n'

    podcast_title = \
        remove_html(html.unescape(urllib.parse.unquote_plus(newswire_item[0])))
    if podcast_title:
        podcast_str += \
            '<p><label class="podcast-title">' + \
            '<span itemprop="headline">' + \
            podcast_title + \
            '</span></label></p>\n'

    if podcast_properties.get('author'):
        author = podcast_properties['author']
        podcast_str += '<p>' + author + '</p>\n'

    transcripts = _html_podcast_transcripts(podcast_properties, translate)
    if transcripts:
        podcast_str += '<p>' + transcripts + '</p>\n'
    if newswire_item[4]:
        podcast_description = \
            html.unescape(urllib.parse.unquote_plus(newswire_item[4]))
        podcast_description = safe_web_text(podcast_description)
        if podcast_description:
            podcast_str += \
                '<p><span itemprop="description">' + \
                podcast_description + '</span></p>\n'

    # donate button
    if podcast_properties.get('funding'):
        if podcast_properties['funding'].get('url'):
            url_str = get_url_from_post(podcast_properties['funding']['url'])
            donate_url = remove_html(url_str)
            podcast_str += \
                '<p><span itemprop="funding"><a href="' + donate_url + \
                '" rel="donation"><button class="donateButton">' + \
                translate['Donate'] + '</button></a></span></p>\n'

    fediverse_handle = ''
    if len(newswire_item) > 9:
        fediverse_handle = newswire_item[9]
        podcast_nickname = get_nickname_from_actor(fediverse_handle)
        podcast_domain, _ = get_domain_from_actor(fediverse_handle)
        if podcast_nickname and podcast_domain:
            podcast_str += \
                '<p><a href="' + fediverse_handle + '">@' + \
                podcast_nickname + '@' + podcast_domain + '</a></p>\n'

    extra_links: list[str] = []
    if len(newswire_item) > 10:
        extra_links = newswire_item[10]
        if extra_links:
            links_text = ''
            for link_str in extra_links:
                link_str = remove_html(link_str)
                if not resembles_url(link_str):
                    continue
                if link_str in podcast_str:
                    continue
                if not links_text:
                    links_text = '<p>\n'
                link_url = link_str
                # check that the link is not too long so that it does not
                # mess up display on mobile
                if len(link_str) > MAX_LINK_LENGTH:
                    link_str = link_str[:MAX_LINK_LENGTH-1]
                links_text += \
                    '<a href="' + link_url + '">' + link_str + '</a><br>\n'
            if links_text:
                links_text += '</p>\n'
                podcast_str += links_text

    if podcast_properties['categories']:
        tags_str = ''
        for tag in podcast_properties['categories']:
            tag = tag.replace('#', '')
            tag_link = '/users/' + nickname + '/tags/' + tag
            tags_str += \
                '#<a href="' + tag_link + '">' + \
                '<span itemprop="keywords">' + tag + '</span>' + \
                '</a> '
        podcast_str += '<p>' + tags_str.strip() + '</p>\n'

    podcast_str += _html_podcast_performers(podcast_properties)
    podcast_str += \
        _html_podcast_social_interactions(podcast_properties, translate,
                                          nickname)
    podcast_str += \
        _html_podcast_chapters(link_url,
                               session, session_onion, session_i2p,
                               http_prefix, domain,
                               podcast_properties, debug, mitm_servers)

    podcast_str += '  </center>\n'
    podcast_str += '</div>\n'

    podcast_str += html_footer()
    return podcast_str
