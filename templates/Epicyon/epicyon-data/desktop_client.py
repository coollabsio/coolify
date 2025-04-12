__filename__ = "desktop_client.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Client"

import os
import html
import time
import sys
import select
import webbrowser
import urllib.parse
from pathlib import Path
from random import randint
from flags import is_pgp_encrypted
from utils import replace_strings
from utils import get_post_attachments
from utils import get_url_from_post
from utils import get_actor_languages_list
from utils import get_attributed_to
from utils import remove_html
from utils import safe_system_string
from utils import text_in_file
from utils import disallow_announce
from utils import disallow_reply
from utils import get_base_content_from_post
from utils import has_object_dict
from utils import get_full_domain
from utils import is_dm
from utils import load_translations_from_file
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import local_actor_url
from utils import get_reply_to
from utils import get_actor_from_post
from session import create_session
from speaker import speakable_text
from speaker import get_speaker_pitch
from speaker import get_speaker_rate
from speaker import get_speaker_range
from like import send_like_via_server
from like import send_undo_like_via_server
from follow import approve_follow_request_via_server
from follow import deny_follow_request_via_server
from follow import get_follow_requests_via_server
from follow import get_following_via_server
from follow import get_followers_via_server
from follow import send_follow_request_via_server
from follow import send_unfollow_request_via_server
from posts import send_block_via_server
from posts import send_undo_block_via_server
from posts import send_mute_via_server
from posts import send_undo_mute_via_server
from posts import send_post_via_server
from posts import c2s_box_json
from posts import download_announce
from announce import send_announce_via_server
from announce import send_undo_announce_via_server
from pgp import pgp_local_public_key
from pgp import pgp_decrypt
from pgp import has_local_pg_pkey
from pgp import pgp_encrypt_to_actor
from pgp import pgp_public_key_upload
from like import no_of_likes
from bookmarks import send_bookmark_via_server
from bookmarks import send_undo_bookmark_via_server
from delete import send_delete_via_server
from person import get_actor_json
from cache import get_person_from_cache


def _desktop_help() -> None:
    """Shows help
    """
    _desktop_clear_screen()
    indent = '   '
    print('')
    print(indent + _highlight_text('Help Commands:'))
    print('')
    print(indent + 'quit                                  ' +
          'Exit from the desktop client')
    print(indent + 'show dm|sent|inbox|replies|bookmarks  ' +
          'Show a timeline')
    print(indent + 'mute                                  ' +
          'Turn off the screen reader')
    print(indent + 'speak                                 ' +
          'Turn on the screen reader')
    print(indent + 'sounds on                             ' +
          'Turn on notification sounds')
    print(indent + 'sounds off                            ' +
          'Turn off notification sounds')
    print(indent + 'rp                                    ' +
          'Repeat the last post')
    print(indent + 'like                                  ' +
          'Like the last post')
    print(indent + 'unlike                                ' +
          'Unlike the last post')
    print(indent + 'bookmark                              ' +
          'Bookmark the last post')
    print(indent + 'unbookmark                            ' +
          'Unbookmark the last post')
    print(indent + 'block [post number|handle]            ' +
          'Block someone via post number or handle')
    print(indent + 'unblock [handle]                      ' +
          'Unblock someone')
    print(indent + 'mute                                  ' +
          'Mute the last post')
    print(indent + 'unmute                                ' +
          'Unmute the last post')
    print(indent + 'reply                                 ' +
          'Reply to the last post')
    print(indent + 'post                                  ' +
          'Create a new post')
    print(indent + 'post to [handle]                      ' +
          'Create a new direct message')
    print(indent + 'announce/boost                        ' +
          'Boost the last post')
    print(indent + 'follow [handle]                       ' +
          'Make a follow request')
    print(indent + 'unfollow [handle]                     ' +
          'Stop following the give handle')
    print(indent + 'next                                  ' +
          'Next page in the timeline')
    print(indent + 'prev                                  ' +
          'Previous page in the timeline')
    print(indent + 'read [post number]                    ' +
          'Read a post from a timeline')
    print(indent + 'show [post number]                    ' +
          'Read a post from a timeline')
    print(indent + 'open [post number]                    ' +
          'Open web links within a timeline post')
    print(indent + 'profile [post number or handle]       ' +
          'Show profile for the person who made the given post')
    print(indent + 'following [page number]               ' +
          'Show accounts that you are following')
    print(indent + 'followers [page number]               ' +
          'Show accounts that are following you')
    print(indent + 'approve [handle]                      ' +
          'Approve a follow request')
    print(indent + 'deny [handle]                         ' +
          'Deny a follow request')
    print(indent + 'pgp                                   ' +
          'Show your PGP public key')
    print('')


def _create_desktop_config(actor: str) -> None:
    """Sets up directories for desktop client configuration
    """
    home_dir = str(Path.home())
    if not os.path.isdir(home_dir + '/.config'):
        os.mkdir(home_dir + '/.config')
    if not os.path.isdir(home_dir + '/.config/epicyon'):
        os.mkdir(home_dir + '/.config/epicyon')
    nickname = get_nickname_from_actor(actor)
    domain, port = get_domain_from_actor(actor)
    handle = nickname + '@' + domain
    if port not in (443, 80):
        handle += '_' + str(port)
    read_posts_dir = home_dir + '/.config/epicyon/' + handle
    if not os.path.isdir(read_posts_dir):
        os.mkdir(read_posts_dir)


def _mark_post_as_read(actor: str, post_id: str, post_category: str) -> None:
    """Marks the given post as read by the given actor
    """
    home_dir = str(Path.home())
    _create_desktop_config(actor)
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        return
    domain, port = get_domain_from_actor(actor)
    if not domain:
        return
    handle = nickname + '@' + domain
    if port not in (443, 80):
        handle += '_' + str(port)
    read_posts_dir = home_dir + '/.config/epicyon/' + handle
    read_posts_filename = read_posts_dir + '/' + post_category + '.txt'
    if os.path.isfile(read_posts_filename):
        if text_in_file(post_id, read_posts_filename):
            return
        try:
            # prepend to read posts file
            post_id += '\n'
            with open(read_posts_filename, 'r+',
                      encoding='utf-8') as fp_read:
                content = fp_read.read()
                if post_id not in content:
                    fp_read.seek(0, 0)
                    fp_read.write(post_id + content)
        except OSError as ex:
            print('EX: Failed to mark post as read 1 ' + str(ex))
    else:
        try:
            with open(read_posts_filename, 'w+',
                      encoding='utf-8') as fp_read:
                fp_read.write(post_id + '\n')
        except OSError as ex:
            print('EX: Failed to mark post as read 2 ' + str(ex))


def _has_read_post(actor: str, post_id: str, post_category: str) -> bool:
    """Returns true if the given post has been read by the actor
    """
    home_dir = str(Path.home())
    _create_desktop_config(actor)
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        return True
    domain, port = get_domain_from_actor(actor)
    if not domain:
        return True
    handle = nickname + '@' + domain
    if port not in (443, 80):
        handle += '_' + str(port)
    read_posts_dir = home_dir + '/.config/epicyon/' + handle
    read_posts_filename = read_posts_dir + '/' + post_category + '.txt'
    if os.path.isfile(read_posts_filename):
        if text_in_file(post_id, read_posts_filename):
            return True
    return False


def _post_is_to_you(actor: str, post_json_object: {}) -> bool:
    """Returns true if the post is to the actor
    """
    to_your_actor = False
    if post_json_object.get('to'):
        if isinstance(post_json_object['to'], list):
            if actor in post_json_object['to']:
                to_your_actor = True
        elif isinstance(post_json_object['to'], str):
            if actor == post_json_object['to']:
                to_your_actor = True
    if not to_your_actor and post_json_object.get('cc'):
        if isinstance(post_json_object['cc'], list):
            if actor in post_json_object['cc']:
                to_your_actor = True
        elif isinstance(post_json_object['cc'], str):
            if actor == post_json_object['cc']:
                to_your_actor = True
    if not to_your_actor and has_object_dict(post_json_object):
        if post_json_object['object'].get('to'):
            if isinstance(post_json_object['to'], list):
                if actor in post_json_object['object']['to']:
                    to_your_actor = True
            elif isinstance(post_json_object['to'], str):
                if actor == post_json_object['object']['to']:
                    to_your_actor = True
        if not to_your_actor and post_json_object['object'].get('cc'):
            if isinstance(post_json_object['cc'], list):
                if actor in post_json_object['object']['cc']:
                    to_your_actor = True
            elif isinstance(post_json_object['cc'], str):
                if actor == post_json_object['object']['cc']:
                    to_your_actor = True
    return to_your_actor


def _new_desktop_notifications(actor: str, inbox_json: {},
                               notify_json: {}) -> None:
    """Looks for changes in the inbox and adds notifications
    """
    notify_json['dmNotifyChanged'] = False
    notify_json['repliesNotifyChanged'] = False
    if not inbox_json:
        return
    if not inbox_json.get('orderedItems'):
        return
    dm_done = False
    reply_done = False
    for post_json_object in inbox_json['orderedItems']:
        if not post_json_object.get('id'):
            continue
        if not post_json_object.get('type'):
            continue
        if post_json_object['type'] == 'Announce':
            continue
        if not _post_is_to_you(actor, post_json_object):
            continue
        if is_dm(post_json_object):
            if not dm_done:
                if not _has_read_post(actor, post_json_object['id'], 'dm'):
                    changed = False
                    if not notify_json.get('dmPostId'):
                        changed = True
                    else:
                        if notify_json['dmPostId'] != post_json_object['id']:
                            changed = True
                    if changed:
                        notify_json['dmNotify'] = True
                        notify_json['dmNotifyChanged'] = True
                        notify_json['dmPostId'] = post_json_object['id']
                    dm_done = True
        else:
            if not reply_done:
                if not _has_read_post(actor, post_json_object['id'],
                                      'replies'):
                    changed = False
                    if not notify_json.get('repliesPostId'):
                        changed = True
                    else:
                        if notify_json['repliesPostId'] != \
                           post_json_object['id']:
                            changed = True
                    if changed:
                        notify_json['repliesNotify'] = True
                        notify_json['repliesNotifyChanged'] = True
                        notify_json['repliesPostId'] = post_json_object['id']
                    reply_done = True


def _desktop_clear_screen() -> None:
    """Clears the screen
    """
    os.system('cls' if os.name == 'nt' else 'clear')


def _desktop_show_banner() -> None:
    """Shows the banner at the top
    """
    banner_filename = 'banner.txt'
    if not os.path.isfile(banner_filename):
        banner_theme = 'starlight'
        banner_filename = 'theme/' + banner_theme + '/banner.txt'
        if not os.path.isfile(banner_filename):
            return
    try:
        with open(banner_filename, 'r', encoding='utf-8') as fp_banner:
            banner = fp_banner.read()
            if banner:
                print(banner + '\n')
    except OSError:
        print('EX: unable to read banner file ' + banner_filename)


def _desktop_wait_for_cmd(timeout: int, debug: bool) -> str:
    """Waits for a command to be entered with a timeout
    Returns the command, or None on timeout
    """
    inp, _, _ = select.select([sys.stdin], [], [], timeout)

    if inp:
        text = sys.stdin.readline().strip()
        if debug:
            print("Text entered: " + text)
        return text
    if debug:
        print("Timeout")
    return None


def _play_sound(sound_filename: str,
                player: str = 'ffplay') -> None:
    """Plays a sound
    """
    if not os.path.isfile(sound_filename):
        return

    if player == 'ffplay':
        cmd = \
            'ffplay ' + safe_system_string(sound_filename) + \
            ' -autoexit -hide_banner -nodisp 2> /dev/null'
        os.system(cmd)


def _speaker_espeak(espeak, pitch: int, rate: int, srange: int,
                    say_text: str) -> None:
    """Speaks the given text with espeak
    """
    espeak.set_parameter(espeak.Parameter.Pitch, pitch)
    espeak.set_parameter(espeak.Parameter.Rate, rate)
    espeak.set_parameter(espeak.Parameter.Range, srange)
    espeak.synth(html.unescape(say_text))


def _speaker_mimic3(pitch: int, rate: int, srange: int,
                    say_text: str) -> None:
    """Speaks the given text with mimic3
    """
    voice = 'en_UK/apope_low'
    if pitch > 20:
        voice = 'en_US/m-ailabs_low'
    if pitch > 40:
        voice = 'en_US/hifi-tts_low'
    if pitch >= 50:
        voice = 'en_US/ljspeech_low'
    if pitch > 75:
        voice = 'en_US/vctk_low'
    length_scale = str(1.2 - (rate / 600.0))
    srange = min(srange, 100)
    noise_w = str(srange / 100.0)
    text = html.unescape(say_text).replace('"', "'")
    if not text:
        return
    audio_filename = '/tmp/epicyon_voice.wav'
    cmd = 'mimic3 -v ' + voice + \
        ' --length-scale ' + length_scale + \
        ' --noise-w ' + noise_w + \
        ' --stdout' + \
        ' "' + text + '" > ' + \
        audio_filename + ' 2> /dev/null'
    cmd = safe_system_string(cmd)
    try:
        os.system(cmd)
    except OSError as ex:
        print('EX: unable to play ' + audio_filename + ' ' + str(ex))
    _play_sound(audio_filename)


def _speaker_picospeaker(pitch: int, rate: int, system_language: str,
                         say_text: str) -> None:
    """TTS using picospeaker
    """
    speaker_lang = 'en-GB'
    supported_languages = {
        "fr": "fr-FR",
        "es": "es-ES",
        "de": "de-DE",
        "it": "it-IT"
    }
    for lang, speaker_str in supported_languages.items():
        if system_language.startswith(lang):
            speaker_lang = speaker_str
            break
    say_text = str(say_text).replace('"', "'")
    speaker_text = html.unescape(str(say_text))
    speaker_cmd = \
        'picospeaker ' + \
        '-l ' + safe_system_string(speaker_lang) + \
        ' -r ' + str(rate) + \
        ' -p ' + str(pitch) + ' "' + \
        safe_system_string(speaker_text) + '" 2> /dev/null'
    os.system(speaker_cmd)


def _desktop_notification(notification_type: str,
                          title: str, message: str) -> None:
    """Shows a desktop notification
    """
    if not notification_type:
        return

    if notification_type == 'notify-send':
        # Ubuntu
        cmd = \
            'notify-send "' + safe_system_string(title) + \
            '" "' + safe_system_string(message) + '"'
        os.system(cmd)
    elif notification_type == 'zenity':
        # Zenity
        cmd = \
            'zenity --notification --title "' + safe_system_string(title) + \
            '" --text="' + safe_system_string(message) + '"'
        os.system(cmd)
    elif notification_type == 'osascript':
        # Mac
        cmd = \
            "osascript -e 'display notification \"" + \
            safe_system_string(message) + "\" with title \"" + \
            safe_system_string(title) + "\"'"
        os.system(cmd)
    elif notification_type == 'New-BurntToastNotification':
        # Windows
        cmd = \
            "New-BurntToastNotification -Text \"" + \
            safe_system_string(title) + "\", '" + \
            safe_system_string(message) + "'"
        os.system(cmd)


def _text_to_speech(say_str: str, screenreader: str,
                    pitch: int, rate: int, srange: int,
                    system_language: str, espeak=None) -> None:
    """Say something via TTS
    """
    # speak the post content
    if screenreader == 'espeak':
        _speaker_espeak(espeak, pitch, rate, srange, say_str)
    elif screenreader == 'picospeaker':
        _speaker_picospeaker(pitch, rate, system_language, say_str)
    elif screenreader == 'mimic3':
        _speaker_mimic3(pitch, rate, srange, say_str)


def _say_command(content: str, say_str: str, screenreader: str,
                 system_language: str, espeak=None,
                 speaker_name: str = 'screen reader',
                 speaker_gender: str = 'They/Them') -> None:
    """Speaks a command
    """
    print(content)
    if not screenreader:
        return

    pitch = get_speaker_pitch(speaker_name,
                              screenreader, speaker_gender)
    rate = get_speaker_rate(speaker_name, screenreader)
    srange = get_speaker_range(speaker_name)

    _text_to_speech(say_str, screenreader,
                    pitch, rate, srange,
                    system_language, espeak)


def _desktop_reply_to_post(session, post_id: str,
                           base_dir: str, nickname: str, password: str,
                           domain: str, port: int, http_prefix: str,
                           cached_webfingers: {}, person_cache: {},
                           debug: bool, subject: str,
                           screenreader: str, system_language: str,
                           languages_understood: [],
                           espeak, conversation_id: str, convthread_id: str,
                           low_bandwidth: bool,
                           content_license_url: str,
                           media_license_url: str, media_creator: str,
                           signing_priv_key_pem: str,
                           translate: {},
                           mitm_servers: []) -> None:
    """Use the desktop client to send a reply to the most recent post
    """
    if '://' not in post_id:
        return
    to_nickname = get_nickname_from_actor(post_id)
    if not to_nickname:
        return
    to_domain, to_port = get_domain_from_actor(post_id)
    if not to_domain:
        return
    say_str = 'Replying to ' + to_nickname + '@' + to_domain
    _say_command(say_str, say_str,
                 screenreader, system_language, espeak)
    say_str = translate['Type your reply message, then press Enter'] + '.'
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    reply_message = input()
    if not reply_message:
        say_str = translate['No reply was entered'] + '.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    reply_message = reply_message.strip()
    if not reply_message:
        say_str = translate['No reply was entered'] + '.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    print('')
    say_str = translate['You entered this reply'] + ':'
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    _say_command(reply_message, reply_message, screenreader,
                 system_language, espeak)
    say_str = translate['Send this reply, yes or no?']
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    yesno = input()
    if 'y' not in yesno.lower():
        say_str = translate['Abandoning reply']
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    cc_url = None
    attach = None
    media_type = None
    attached_image_description = None
    is_article = False
    subject = None
    comments_enabled = True
    city = 'London, England'
    say_str = 'Sending reply'
    event_date = None
    event_time = None
    event_end_time = None
    location = None
    buy_url = ''
    chat_url = ''
    video_transcript = None
    auto_cw_cache = {}
    # TODO searchable status
    searchable_by: list[str] = []
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    if send_post_via_server(signing_priv_key_pem, __version__,
                            base_dir, session, nickname, password,
                            domain, port,
                            to_nickname, to_domain, to_port, cc_url,
                            http_prefix, reply_message,
                            comments_enabled, attach, media_type,
                            attached_image_description, video_transcript,
                            city, cached_webfingers,
                            person_cache, is_article,
                            system_language, languages_understood,
                            low_bandwidth, content_license_url,
                            media_license_url, media_creator,
                            event_date, event_time, event_end_time, location,
                            translate, buy_url, chat_url, auto_cw_cache,
                            debug, post_id, post_id,
                            conversation_id, convthread_id, subject,
                            searchable_by, mitm_servers) == 0:
        say_str = translate['Sent']
    else:
        say_str = translate['Post failed']
    _say_command(say_str, say_str, screenreader, system_language, espeak)


def _desktop_new_post(session,
                      base_dir: str, nickname: str, password: str,
                      domain: str, port: int, http_prefix: str,
                      cached_webfingers: {}, person_cache: {},
                      debug: bool,
                      screenreader: str, system_language: str,
                      languages_understood: [],
                      espeak, low_bandwidth: bool,
                      content_license_url: str,
                      media_license_url: str, media_creator: str,
                      signing_priv_key_pem: str,
                      translate: {},
                      mitm_servers: []) -> None:
    """Use the desktop client to create a new post
    """
    conversation_id = None
    convthread_id = None
    say_str = translate['Create a new post']
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    say_str = translate['Type your post, then press Enter'] + '.'
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    new_message = input()
    if not new_message:
        say_str = translate['No post was entered'] + '.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    new_message = new_message.strip()
    if not new_message:
        say_str = translate['No post was entered'] + '.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    print('')
    say_str = translate['You entered this public post'] + ':'
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    _say_command(new_message, new_message,
                 screenreader, system_language, espeak)
    say_str = translate['Send this post, yes or no?']
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    yesno = input()
    if 'y' not in yesno.lower():
        say_str = translate['Abandoning new post']
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    cc_url = None
    attach = None
    media_type = None
    attached_image_description = None
    city = 'London, England'
    is_article = False
    subject = None
    comments_enabled = True
    subject = None
    say_str = 'Sending'
    event_date = None
    event_time = None
    event_end_time = None
    location = None
    buy_url = ''
    chat_url = ''
    video_transcript = None
    auto_cw_cache = {}
    # TODO searchable status
    searchable_by: list[str] = []
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    if send_post_via_server(signing_priv_key_pem, __version__,
                            base_dir, session, nickname, password,
                            domain, port,
                            None, '#Public', port, cc_url,
                            http_prefix, new_message,
                            comments_enabled, attach, media_type,
                            attached_image_description, video_transcript, city,
                            cached_webfingers, person_cache, is_article,
                            system_language, languages_understood,
                            low_bandwidth, content_license_url,
                            media_license_url, media_creator,
                            event_date, event_time, event_end_time, location,
                            translate, buy_url, chat_url, auto_cw_cache,
                            debug, None, None,
                            conversation_id, convthread_id, subject,
                            searchable_by, mitm_servers) == 0:
        say_str = translate['Sent']
    else:
        say_str = translate['Post failed']
    _say_command(say_str, say_str, screenreader, system_language, espeak)


def _safe_message(content: str) -> str:
    """Removes anything potentially unsafe from a string
    """
    return content.replace('`', '').replace('$(', '$ (')


def _timeline_is_empty(box_json: {}) -> bool:
    """Returns true if the given timeline is empty
    """
    empty = False
    if not box_json:
        empty = True
    else:
        if not isinstance(box_json, dict):
            empty = True
        elif not box_json.get('orderedItems'):
            empty = True
    return empty


def _get_first_item_id(box_json: {}) -> str:
    """Returns the id of the first item in the timeline
    """
    if _timeline_is_empty(box_json):
        return ''
    if len(box_json['orderedItems']) == 0:
        return ''
    return box_json['orderedItems'][0]['id']


def _text_only_content(content: str) -> str:
    """Remove formatting from the given string
    """
    content = urllib.parse.unquote_plus(content)
    content = html.unescape(content)
    return remove_html(content)


def _get_image_description(post_json_object: {}) -> str:
    """Returns a image description/s on a post
    """
    image_description = ''
    post_attachments = get_post_attachments(post_json_object)
    if not post_attachments:
        return image_description

    # for each attachment
    for img in post_attachments:
        if not isinstance(img, dict):
            continue
        if not img.get('name'):
            continue
        if not img.get('mediaType'):
            continue
        if not isinstance(img['name'], str):
            continue
        if not isinstance(img['mediaType'], str):
            continue
        if not img['mediaType'].startswith('image/'):
            if not img['mediaType'].startswith('video/'):
                continue
        message_str = img['name']
        if message_str:
            message_str = message_str.strip()
            message_str = remove_html(message_str)
            if not message_str.endswith('.'):
                image_description += message_str + '. '
            else:
                image_description += message_str + ' '
    return image_description


def _show_likes_on_post(post_json_object: {}, max_likes: int) -> None:
    """Shows the likes on a post
    """
    if not has_object_dict(post_json_object):
        return
    if not post_json_object['object'].get('likes'):
        return
    object_likes = post_json_object['object']['likes']
    if not isinstance(object_likes, dict):
        return
    if not object_likes.get('items'):
        return
    if not isinstance(object_likes['items'], list):
        return
    print('')
    ctr = 0
    for item in object_likes['items']:
        print('  ❤ ' + str(item['actor']))
        ctr += 1
        if ctr >= max_likes:
            break


def _show_replies_on_post(post_json_object: {}, max_replies: int) -> None:
    """Shows the replies on a post
    """
    if not has_object_dict(post_json_object):
        return
    if not post_json_object['object'].get('replies'):
        return
    object_replies = post_json_object['object']['replies']
    if not isinstance(object_replies, dict):
        return
    if not object_replies.get('items'):
        return
    if not isinstance(object_replies['items'], list):
        return
    print('')
    ctr = 0
    for item in object_replies['items']:
        url_str = get_url_from_post(item['url'])
        item_url = remove_html(url_str)
        print('  ↰ ' + str(item_url))
        ctr += 1
        if ctr >= max_replies:
            break


def _read_local_box_post(session, nickname: str, domain: str,
                         http_prefix: str, base_dir: str, box_name: str,
                         page_number: int, index: int, box_json: {},
                         system_language: str,
                         screenreader: str, espeak,
                         translate: {}, your_actor: str,
                         domain_full: str, person_cache: {},
                         signing_priv_key_pem: str,
                         blocked_cache: {}, block_federated: [],
                         bold_reading: bool,
                         mitm_servers: []) -> {}:
    """Reads a post from the given timeline
    Returns the post json
    """
    if _timeline_is_empty(box_json):
        return {}

    post_json_object = _desktop_get_box_post_object(box_json, index)
    if not post_json_object:
        return {}
    gender = 'They/Them'

    box_name_str = box_name
    if box_name.startswith('tl'):
        box_name_str = box_name[2:]
    say_str = 'Reading ' + box_name_str + ' post ' + str(index) + \
        ' from page ' + str(page_number) + '.'
    say_str2 = say_str.replace(' dm ', ' DM ')
    _say_command(say_str, say_str2, screenreader, system_language, espeak)
    print('')

    if post_json_object['type'] == 'Announce':
        actor = get_actor_from_post(post_json_object)
        name_str = get_nickname_from_actor(actor)
        if not name_str:
            return {}
        recent_posts_cache = {}
        allow_local_network_access = False
        yt_replace_domain = None
        twitter_replacement_domain = None
        show_vote_posts = False
        languages_understood: list[str] = []
        person_url = local_actor_url(http_prefix, nickname, domain_full)
        actor_json = \
            get_person_from_cache(base_dir, person_url, person_cache)
        if actor_json:
            languages_understood = get_actor_languages_list(actor_json)
        post_json_object2 = \
            download_announce(session, base_dir,
                              http_prefix,
                              nickname, domain,
                              post_json_object,
                              __version__,
                              yt_replace_domain,
                              twitter_replacement_domain,
                              allow_local_network_access,
                              recent_posts_cache, False,
                              system_language,
                              domain_full, person_cache,
                              signing_priv_key_pem,
                              blocked_cache, block_federated, bold_reading,
                              show_vote_posts,
                              languages_understood,
                              mitm_servers)
        if post_json_object2:
            if has_object_dict(post_json_object2):
                if post_json_object2['object'].get('attributedTo') and \
                   post_json_object2['object'].get('content'):
                    attrib_field = post_json_object2['object']['attributedTo']
                    attributed_to = get_attributed_to(attrib_field)
                    content = \
                        get_base_content_from_post(post_json_object2,
                                                   system_language)
                    if attributed_to and content:
                        actor = attributed_to
                        name_str1 = get_nickname_from_actor(actor)
                        if not name_str1:
                            return {}
                        name_str += ' ' + translate['announces'] + ' ' + \
                            name_str1
                        say_str = name_str
                        _say_command(say_str, say_str, screenreader,
                                     system_language, espeak)
                        print('')
                        if screenreader:
                            time.sleep(2)
                        content = \
                            _text_only_content(content)
                        im_desc = _get_image_description(post_json_object2)
                        if im_desc:
                            if not content.endswith('.'):
                                content += '.'
                            content += ' ' + im_desc
                        message_str, _ = \
                            speakable_text(http_prefix,
                                           nickname, domain, domain_full,
                                           base_dir, content, translate)
                        say_str = content
                        _say_command(say_str, message_str, screenreader,
                                     system_language, espeak)
                        return post_json_object2
        return {}

    attributed_to = \
        get_attributed_to(post_json_object['object']['attributedTo'])
    if not attributed_to:
        return {}
    content = get_base_content_from_post(post_json_object, system_language)
    if not isinstance(attributed_to, str) or \
       not isinstance(content, str):
        return {}
    actor = attributed_to
    name_str = get_nickname_from_actor(actor)
    if not name_str:
        return {}
    content = _text_only_content(content)
    im_desc = _get_image_description(post_json_object)
    if im_desc:
        if not content.endswith('.'):
            content += '.'
        content += ' ' + im_desc

    if is_pgp_encrypted(content):
        say_str = translate['Encrypted message. Please enter your passphrase.']
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        content = pgp_decrypt(domain, content, actor, signing_priv_key_pem,
                              mitm_servers)
        if is_pgp_encrypted(content):
            say_str = translate['Message could not be decrypted']
            _say_command(say_str, say_str,
                         screenreader, system_language, espeak)
            return {}

    content = _safe_message(content)
    message_str, _ = speakable_text(http_prefix,
                                    nickname, domain, domain_full,
                                    base_dir, content, translate)

    if screenreader:
        time.sleep(2)

    # say the speaker's name
    _say_command(name_str, name_str, screenreader,
                 system_language, espeak, name_str, gender)
    print('')

    reply_id = get_reply_to(post_json_object['object'])
    if reply_id:
        print(translate['replying to'].title() + ' ' + reply_id + '\n')

    if screenreader:
        time.sleep(2)

    # speak the post content
    _say_command(content, message_str, screenreader,
                 system_language, espeak, name_str, gender)

    _show_likes_on_post(post_json_object, 10)
    _show_replies_on_post(post_json_object, 10)

    # if the post is addressed to you then mark it as read
    if _post_is_to_you(your_actor, post_json_object):
        if is_dm(post_json_object):
            _mark_post_as_read(your_actor, post_json_object['id'], 'dm')
        else:
            _mark_post_as_read(your_actor, post_json_object['id'], 'replies')

    return post_json_object


def _desktop_show_actor(http_prefix: str,
                        nickname: str, domain: str, domain_full: str,
                        base_dir: str, actor_json: {}, translate: {},
                        system_language: str, screenreader: str,
                        espeak) -> None:
    """Shows information for the given actor
    """
    actor = actor_json['id']
    actor_nickname = get_nickname_from_actor(actor)
    if not actor_nickname:
        return
    actor_domain, actor_port = get_domain_from_actor(actor)
    actor_domain_full = get_full_domain(actor_domain, actor_port)
    handle = '@' + actor_nickname + '@' + actor_domain_full

    say_str = translate['Profile for'] + ' ' + html.unescape(handle)
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    print(actor)
    if actor_json.get('movedTo'):
        say_str = 'Moved to ' + html.unescape(actor_json['movedTo'])
        _say_command(say_str, say_str, screenreader, system_language, espeak)
    if actor_json.get('alsoKnownAs'):
        also_known_as_str = ''
        ctr = 0
        for alt_actor in actor_json['alsoKnownAs']:
            if ctr > 0:
                also_known_as_str += ', '
            ctr += 1
            also_known_as_str += alt_actor

        say_str = translate['Other accounts'] + ' ' + \
            html.unescape(also_known_as_str)
        _say_command(say_str, say_str, screenreader, system_language, espeak)
    if actor_json.get('summary'):
        say_str = html.unescape(remove_html(actor_json['summary']))
        say_str = say_str.replace('"', "'")
        say_str2 = speakable_text(http_prefix,
                                  nickname, domain, domain_full,
                                  base_dir, say_str, translate)[0]
        _say_command(say_str, say_str2, screenreader, system_language, espeak)


def _desktop_show_profile(session, nickname: str,
                          domain: str, domain_full: str,
                          base_dir: str, index: int, box_json: {},
                          system_language: str,
                          screenreader: str, espeak,
                          translate: {}, post_json_object: {},
                          signing_priv_key_pem: str,
                          http_prefix: str,
                          mitm_servers: []) -> {}:
    """Shows the profile of the actor for the given post
    Returns the actor json
    """
    if _timeline_is_empty(box_json):
        return {}

    if not post_json_object:
        post_json_object = _desktop_get_box_post_object(box_json, index)
        if not post_json_object:
            return {}

    actor = None
    if post_json_object['type'] == 'Announce':
        nickname = get_nickname_from_actor(post_json_object['object'])
        if nickname:
            nick_str = '/' + nickname + '/'
            if nick_str in post_json_object['object']:
                actor = \
                    post_json_object['object'].split(nick_str)[0] + \
                    '/' + nickname
    else:
        actor = get_attributed_to(post_json_object['object']['attributedTo'])

    if not actor:
        return {}

    is_http = False
    if 'http://' in actor:
        is_http = True
    is_gnunet = False
    is_ipfs = False
    is_ipns = False
    actor_json, _ = \
        get_actor_json(domain, actor, is_http, is_gnunet, is_ipfs, is_ipns,
                       False, True, signing_priv_key_pem, session,
                       mitm_servers)

    _desktop_show_actor(http_prefix,
                        nickname, domain, domain_full,
                        base_dir, actor_json, translate,
                        system_language, screenreader, espeak)

    return actor_json


def _desktop_show_profile_from_handle(session, nickname: str, domain: str,
                                      domain_full: str, base_dir: str,
                                      handle: str, system_language: str,
                                      screenreader: str, espeak,
                                      translate: {},
                                      signing_priv_key_pem: str,
                                      http_prefix: str,
                                      mitm_servers: []) -> {}:
    """Shows the profile for a handle
    Returns the actor json
    """
    actor_json, _ = \
        get_actor_json(domain, handle, False, False, False, False,
                       False, True,
                       signing_priv_key_pem, session, mitm_servers)

    _desktop_show_actor(http_prefix, nickname, domain, domain_full,
                        base_dir, actor_json, translate,
                        system_language, screenreader, espeak)

    return actor_json


def _desktop_get_box_post_object(box_json: {}, index: int) -> {}:
    """Gets the post with the given index from the timeline
    """
    ctr = 0
    for post_json_object in box_json['orderedItems']:
        if not post_json_object.get('type'):
            continue
        if not post_json_object.get('object'):
            continue
        if post_json_object['type'] == 'Announce':
            if not isinstance(post_json_object['object'], str):
                continue
            ctr += 1
            if ctr == index:
                return post_json_object
            continue
        if not has_object_dict(post_json_object):
            continue
        if not post_json_object['object'].get('published'):
            continue
        if not post_json_object['object'].get('content'):
            continue
        ctr += 1
        if ctr == index:
            return post_json_object
    return None


def _format_published(published: str) -> str:
    """Formats the published time for display on timeline
    """
    date_str = published.split('T')[0]
    month_str = date_str.split('-')[1]
    day_str = date_str.split('-')[2]
    time_str = published.split('T')[1]
    hour_str = time_str.split(':')[0]
    min_str = time_str.split(':')[1]
    return month_str + '-' + day_str + ' ' + hour_str + ':' + min_str + 'Z'


def _pad_to_width(content: str, width: int) -> str:
    """Pads the given string to the given width
    """
    if len(content) > width:
        content = content[:width]
    else:
        while len(content) < width:
            content += ' '
    return content


def _highlight_text(text: str) -> str:
    """Returns a highlighted version of the given text
    """
    return '\33[7m' + text + '\33[0m'


def _desktop_show_box(indent: str,
                      follow_requests_json: {},
                      your_actor: str, box_name: str, box_json: {},
                      translate: {},
                      screenreader: str, system_language: str, espeak,
                      page_number: int) -> bool:
    """Shows online timeline
    """
    number_width = 2
    name_width = 16
    content_width = 50

    # title
    _desktop_clear_screen()
    _desktop_show_banner()

    notification_icons = ''
    if box_name.startswith('tl'):
        box_name_str = box_name[2:]
    else:
        box_name_str = box_name
    title_str = _highlight_text(box_name_str.upper())
    # if new_dms:
    #     notification_icons += ' 📩'
    # if new_replies:
    #     notification_icons += ' 📨'

    if notification_icons:
        while len(title_str) < 95 - len(notification_icons):
            title_str += ' '
        title_str += notification_icons
    print(indent + title_str + '\n')

    if _timeline_is_empty(box_json):
        box_str = box_name_str
        if box_name == 'dm':
            box_str = 'DM'
        say_str = indent + 'You have no ' + box_str + ' posts yet.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        print('')
        return False

    ctr = 1
    for post_json_object in box_json['orderedItems']:
        if not post_json_object.get('type'):
            continue
        if post_json_object['type'] == 'Announce':
            if post_json_object.get('actor') and \
               post_json_object.get('object'):
                if isinstance(post_json_object['object'], str):
                    author_actor = \
                        get_actor_from_post(post_json_object)
                    name1 = get_nickname_from_actor(author_actor)
                    if not name1:
                        continue
                    name = name1 + ' ⮌'
                    name = _pad_to_width(name, name_width)
                    ctr_str = str(ctr)
                    pos_str = _pad_to_width(ctr_str, number_width)
                    published = \
                        _format_published(post_json_object['published'])
                    announced_nickname = \
                        get_nickname_from_actor(post_json_object['object'])
                    if not announced_nickname:
                        continue
                    announced_domain, _ = \
                        get_domain_from_actor(post_json_object['object'])
                    if not announced_domain:
                        continue
                    announced_handle = \
                        announced_nickname + '@' + announced_domain
                    line_str = \
                        indent + str(pos_str) + ' | ' + name + ' | ' + \
                        published + ' | ' + \
                        _pad_to_width(announced_handle, content_width)
                    print(line_str)
                    ctr += 1
                    continue

        if not has_object_dict(post_json_object):
            continue
        if not post_json_object['object'].get('published'):
            continue
        if not post_json_object['object'].get('content'):
            continue
        ctr_str = str(ctr)
        pos_str = _pad_to_width(ctr_str, number_width)

        author_actor = \
            get_attributed_to(post_json_object['object']['attributedTo'])
        content_warning = None
        if post_json_object['object'].get('summary'):
            content_warning = '⚡' + \
                _pad_to_width(post_json_object['object']['summary'],
                              content_width)
        name = get_nickname_from_actor(author_actor)
        if not name:
            continue

        # append icons to the end of the name
        space_added = False
        reply_id = get_reply_to(post_json_object['object'])
        if reply_id:
            if not space_added:
                space_added = True
                name += ' '
            name += '↲'
            if post_json_object['object'].get('replies'):
                replies_list = post_json_object['object']['replies']
                if replies_list.get('items'):
                    items = replies_list['items']
                    for i in range(int(items)):
                        name += '↰'
                        if i > 10:
                            break
        likes_count = no_of_likes(post_json_object)
        likes_count = max(likes_count, 10)
        for _ in range(likes_count):
            if not space_added:
                space_added = True
                name += ' '
            name += '❤'
        name = _pad_to_width(name, name_width)

        published = _format_published(post_json_object['published'])

        content_str = get_base_content_from_post(post_json_object,
                                                 system_language)
        content = _text_only_content(content_str)
        if box_name != 'dm':
            if is_dm(post_json_object):
                content = '📧' + content
        if not content_warning:
            if is_pgp_encrypted(content):
                content = '🔒' + content
            elif '://' in content:
                content = '🔗' + content
            content = _pad_to_width(content, content_width)
        else:
            # display content warning
            if is_pgp_encrypted(content):
                content = '🔒' + content_warning
            else:
                if '://' in content:
                    content = '🔗' + content_warning
                else:
                    content = content_warning
        if post_json_object['object'].get('ignores'):
            content = '🔇'
        if post_json_object['object'].get('bookmarks'):
            content = '🔖' + content
        if '\n' in content:
            content = content.replace('\n', ' ')
        line_str = indent + str(pos_str) + ' | ' + name + ' | ' + \
            published + ' | ' + content
        if box_name == 'inbox' and \
           _post_is_to_you(your_actor, post_json_object):
            if not _has_read_post(your_actor, post_json_object['id'], 'dm'):
                if not _has_read_post(your_actor, post_json_object['id'],
                                      'replies'):
                    line_str = _highlight_text(line_str)
        print(line_str)
        ctr += 1

    if follow_requests_json:
        _desktop_show_follow_requests(follow_requests_json, translate)

    print('')

    # say the post number range
    say_str = indent + box_name_str + ' page ' + str(page_number) + \
        ' containing ' + str(ctr - 1) + ' posts. '
    replacements = {
        '\33[3m': '',
        '\33[0m': '',
        'show dm': 'show DM',
        'dm post': 'Direct message post'
    }
    say_str2 = replace_strings(say_str, replacements)
    _say_command(say_str, say_str2, screenreader, system_language, espeak)
    print('')
    return True


def _desktop_new_dm(session, to_handle: str,
                    base_dir: str, nickname: str, password: str,
                    domain: str, port: int, http_prefix: str,
                    cached_webfingers: {}, person_cache: {},
                    debug: bool,
                    screenreader: str, system_language: str,
                    languages_understood: [],
                    espeak, low_bandwidth: bool,
                    content_license_url: str,
                    media_license_url: str, media_creator: str,
                    signing_priv_key_pem: str,
                    translate: {},
                    mitm_servers: []) -> None:
    """Use the desktop client to create a new direct message
    which can include multiple destination handles
    """
    if ' ' in to_handle:
        handles_list = to_handle.split(' ')
    elif ',' in to_handle:
        handles_list = to_handle.split(',')
    elif ';' in to_handle:
        handles_list = to_handle.split(';')
    else:
        handles_list = [to_handle]

    for handle in handles_list:
        handle = handle.strip()
        _desktop_new_dm_base(session, handle,
                             base_dir, nickname, password,
                             domain, port, http_prefix,
                             cached_webfingers, person_cache,
                             debug,
                             screenreader, system_language,
                             languages_understood,
                             espeak, low_bandwidth,
                             content_license_url,
                             media_license_url, media_creator,
                             signing_priv_key_pem, translate,
                             mitm_servers)


def _desktop_new_dm_base(session, to_handle: str,
                         base_dir: str, nickname: str, password: str,
                         domain: str, port: int, http_prefix: str,
                         cached_webfingers: {}, person_cache: {},
                         debug: bool,
                         screenreader: str, system_language: str,
                         languages_understood: [],
                         espeak, low_bandwidth: bool,
                         content_license_url: str,
                         media_license_url: str, media_creator: str,
                         signing_priv_key_pem: str,
                         translate: {},
                         mitm_servers: []) -> None:
    """Use the desktop client to create a new direct message
    """
    conversation_id = None
    convthread_id = None
    to_port = port
    if '://' in to_handle:
        to_nickname = get_nickname_from_actor(to_handle)
        if not to_nickname:
            return
        to_domain, to_port = get_domain_from_actor(to_handle)
        if not to_domain:
            return
        to_handle = to_nickname + '@' + to_domain
    else:
        if to_handle.startswith('@'):
            to_handle = to_handle[1:]
        to_nickname = to_handle.split('@')[0]
        if not to_nickname:
            return
        to_domain = to_handle.split('@')[1]
        if not to_domain:
            return

    say_str = translate['Create new direct message to'] + ' ' + to_handle
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    say_str = 'Type your direct message, then press Enter.'
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    new_message = input()
    if not new_message:
        say_str = translate['No direct message was entered'] + '.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    new_message = new_message.strip()
    if not new_message:
        say_str = translate['No direct message was entered'] + '.'
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return
    say_str = translate['You entered this direct message to'] + \
        ' ' + to_handle + ':'
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    _say_command(new_message, new_message,
                 screenreader, system_language, espeak)
    cc_url = None
    attach = None
    media_type = None
    attached_image_description = None
    city = 'London, England'
    is_article = False
    subject = None
    comments_enabled = True
    subject = None

    # if there is a local PGP key then attempt to encrypt the DM
    # using the PGP public key of the recipient
    if has_local_pg_pkey():
        say_str = \
            'Local PGP key detected...' + \
            'Fetching PGP public key for ' + to_handle
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        padded_message = new_message
        if len(padded_message) < 32:
            # add some padding before and after
            # This is to guard against cribs based on small messages, like "Hi"
            for _ in range(randint(1, 16)):
                padded_message = ' ' + padded_message
            for _ in range(randint(1, 16)):
                padded_message += ' '
        cipher_text = \
            pgp_encrypt_to_actor(domain, padded_message, to_handle,
                                 signing_priv_key_pem, mitm_servers)
        if not cipher_text:
            say_str = \
                to_handle + ' has no PGP public key. ' + \
                'Your message will be sent in clear text'
            _say_command(say_str, say_str,
                         screenreader, system_language, espeak)
        else:
            new_message = cipher_text
            say_str = translate['Message encrypted']
            _say_command(say_str, say_str,
                         screenreader, system_language, espeak)

    say_str = translate['Send this direct message, yes or no?']
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    yesno = input()
    if 'y' not in yesno.lower():
        say_str = translate['Abandoning new direct message']
        _say_command(say_str, say_str, screenreader, system_language, espeak)
        return

    event_date = None
    event_time = None
    event_end_time = None
    location = None
    buy_url = ''
    chat_url = ''
    video_transcript = None

    say_str = 'Sending'
    auto_cw_cache = {}
    # TODO searchable status
    searchable_by: list[str] = []
    _say_command(say_str, say_str, screenreader, system_language, espeak)
    if send_post_via_server(signing_priv_key_pem, __version__,
                            base_dir, session, nickname, password,
                            domain, port,
                            to_nickname, to_domain, to_port, cc_url,
                            http_prefix, new_message,
                            comments_enabled, attach, media_type,
                            attached_image_description, video_transcript, city,
                            cached_webfingers, person_cache, is_article,
                            system_language, languages_understood,
                            low_bandwidth, content_license_url,
                            media_license_url, media_creator,
                            event_date, event_time, event_end_time, location,
                            translate, buy_url, chat_url, auto_cw_cache,
                            debug, None, None,
                            conversation_id, convthread_id, subject,
                            searchable_by, mitm_servers) == 0:
        say_str = translate['Sent']
    else:
        say_str = translate['Post failed']
    _say_command(say_str, say_str, screenreader, system_language, espeak)


def _desktop_show_follow_requests(follow_requests_json: {},
                                  translate: {}) -> None:
    """Shows any follow requests
    """
    if not isinstance(follow_requests_json, dict):
        return
    if not follow_requests_json.get('orderedItems'):
        return
    if not follow_requests_json['orderedItems']:
        return
    indent = '   '
    print('')
    print(indent + translate['Approve follow requests'] + ':')
    print('')
    for item in follow_requests_json['orderedItems']:
        handle_nickname = get_nickname_from_actor(item)
        if not handle_nickname:
            continue
        handle_domain, handle_port = get_domain_from_actor(item)
        if not handle_domain:
            continue
        handle_domain_full = \
            get_full_domain(handle_domain, handle_port)
        print(indent + '  👤 ' +
              handle_nickname + '@' + handle_domain_full)


def _desktop_show_following(following_json: {}, translate: {},
                            page_number: int, indent: str,
                            follow_type: str = 'following') -> None:
    """Shows a page of accounts followed
    """
    if not isinstance(following_json, dict):
        return
    if not following_json.get('orderedItems'):
        return
    if not following_json['orderedItems']:
        return
    print('')
    if follow_type == 'following':
        print(indent + 'Following page ' + str(page_number))
    elif follow_type == 'followers':
        print(indent + 'Followers page ' + str(page_number))
    print('')
    for item in following_json['orderedItems']:
        handle_nickname = get_nickname_from_actor(item)
        if not handle_nickname:
            continue
        handle_domain, handle_port = get_domain_from_actor(item)
        if not handle_domain:
            continue
        handle_domain_full = \
            get_full_domain(handle_domain, handle_port)
        print(indent + '  👤 ' +
              handle_nickname + '@' + handle_domain_full)


def run_desktop_client(base_dir: str, proxy_type: str, http_prefix: str,
                       nickname: str, domain: str, port: int,
                       password: str, screenreader: str,
                       system_language: str,
                       notification_sounds: bool,
                       notification_type: str,
                       no_key_press: bool,
                       store_inbox_posts: bool,
                       show_new_posts: bool,
                       language: str,
                       debug: bool, low_bandwidth: bool) -> None:
    """Runs the desktop and screen reader client,
    which announces new inbox items
    """
    bold_reading = False

    # TODO: this should probably be retrieved somehow from the server
    signing_priv_key_pem = None

    content_license_url = 'https://creativecommons.org/licenses/by-nc/4.0'
    media_license_url = 'https://creativecommons.org/licenses/by-nc/4.0'
    media_creator = ''

    blocked_cache = {}
    block_federated: list[str] = []
    languages_understood: list[str] = []
    mitm_servers: list[str] = []

    indent = '   '
    if show_new_posts:
        indent = ''

    _desktop_clear_screen()
    _desktop_show_banner()

    espeak = None
    if screenreader:
        if screenreader == 'espeak':
            print('Setting up espeak')
            from espeak import espeak
        elif screenreader not in ('picospeaker', 'mimic3'):
            print(screenreader + ' is not a supported TTS system')
            return

        say_str = indent + 'Running ' + screenreader + ' for ' + \
            nickname + '@' + domain
        _say_command(say_str, say_str, screenreader,
                     system_language, espeak)
    else:
        print(indent + 'Running desktop notifications for ' +
              nickname + '@' + domain)
    if notification_sounds:
        say_str = indent + 'Notification sounds on'
    else:
        say_str = indent + 'Notification sounds off'
    _say_command(say_str, say_str, screenreader,
                 system_language, espeak)

    curr_timeline = 'inbox'
    page_number = 1

    post_json_object = {}
    original_screen_reader = screenreader
    sounds_dir = 'theme/default/sounds/'
    # prev_say = ''
    # prev_calendar = False
    # prev_follow = False
    # prev_like = ''
    # prev_share = False
    dm_sound_filename = sounds_dir + 'dm.ogg'
    reply_sound_filename = sounds_dir + 'reply.ogg'
    # calendar_sound_filename = sounds_dir + 'calendar.ogg'
    # follow_sound_filename = sounds_dir + 'follow.ogg'
    # like_sound_filename = sounds_dir + 'like.ogg'
    # share_sound_filename = sounds_dir + 'share.ogg'
    player = 'ffplay'
    name_str = None
    gender = None
    message_str = None
    content = None
    cached_webfingers = {}
    person_cache = {}
    pgp_key_upload = False

    say_str = indent + 'Loading translations file'
    _say_command(say_str, say_str, screenreader,
                 system_language, espeak)
    translate, system_language = \
        load_translations_from_file(base_dir, language)

    say_str = indent + 'Connecting...'
    _say_command(say_str, say_str, screenreader,
                 system_language, espeak)
    session = create_session(proxy_type)

    say_str = indent + '/q or /quit to exit'
    _say_command(say_str, say_str, screenreader,
                 system_language, espeak)

    domain_full = get_full_domain(domain, port)
    your_actor = local_actor_url(http_prefix, nickname, domain_full)
    actor_json = None

    notify_json = {
        "dmPostId": "Initial",
        "dmNotify": False,
        "dmNotifyChanged": False,
        "repliesPostId": "Initial",
        "repliesNotify": False,
        "repliesNotifyChanged": False
    }
    prev_timeline_first_id = ''
    desktop_shown = False
    while (1):
        if not pgp_key_upload:
            if not has_local_pg_pkey():
                print('No PGP public key was found')
            else:
                say_str = indent + 'Uploading PGP public key'
                _say_command(say_str, say_str, screenreader,
                             system_language, espeak)
                pgp_public_key_upload(base_dir, session,
                                      nickname, password,
                                      domain, port, http_prefix,
                                      cached_webfingers, person_cache,
                                      debug, False,
                                      signing_priv_key_pem,
                                      system_language, mitm_servers)
                say_str = indent + translate['PGP Public Key'] + ' uploaded'
                _say_command(say_str, say_str, screenreader,
                             system_language, espeak)
            pgp_key_upload = True

        box_json = c2s_box_json(session, nickname, password,
                                domain, port, http_prefix,
                                curr_timeline, page_number,
                                debug, signing_priv_key_pem,
                                mitm_servers)

        follow_requests_json = \
            get_follow_requests_via_server(session,
                                           nickname, password,
                                           domain, port,
                                           http_prefix, 1,
                                           debug, __version__,
                                           signing_priv_key_pem,
                                           mitm_servers)

        if not (curr_timeline == 'inbox' and page_number == 1):
            # monitor the inbox to generate notifications
            inbox_json = c2s_box_json(session, nickname, password,
                                      domain, port, http_prefix,
                                      'inbox', 1, debug,
                                      signing_priv_key_pem,
                                      mitm_servers)
        else:
            inbox_json = box_json
        if inbox_json:
            _new_desktop_notifications(your_actor, inbox_json, notify_json)
            if notify_json.get('dmNotify'):
                if notify_json.get('dmNotifyChanged'):
                    _desktop_notification(notification_type,
                                          "Epicyon",
                                          "New DM " + your_actor + '/dm')
                    if notification_sounds:
                        _play_sound(dm_sound_filename, player)
            if notify_json.get('repliesNotify'):
                if notify_json.get('repliesNotifyChanged'):
                    _desktop_notification(notification_type,
                                          "Epicyon",
                                          "New reply " +
                                          your_actor + '/replies')
                    if notification_sounds:
                        _play_sound(reply_sound_filename, player)

        if box_json:
            timeline_first_id = _get_first_item_id(box_json)
            if timeline_first_id != prev_timeline_first_id:
                _desktop_clear_screen()
                _desktop_show_box(indent, follow_requests_json,
                                  your_actor, curr_timeline, box_json,
                                  translate,
                                  None, system_language, espeak,
                                  page_number)
                desktop_shown = True
            prev_timeline_first_id = timeline_first_id
        else:
            session = create_session(proxy_type)
            if not desktop_shown:
                if not session:
                    print('No session\n')

                _desktop_clear_screen()
                _desktop_show_banner()
                print('No posts\n')
                if proxy_type == 'tor':
                    print('You may need to run the desktop client ' +
                          'with the --http option')

        # wait for a while, or until a key is pressed
        if no_key_press:
            time.sleep(10)
        else:
            command_str = _desktop_wait_for_cmd(30, debug)
        if command_str:
            refresh_timeline = False

            if command_str.startswith('/'):
                command_str = command_str[1:]
            if command_str in ('q', 'quit', 'exit'):
                say_str = 'Quit'
                _say_command(say_str, say_str, screenreader,
                             system_language, espeak)
                if screenreader:
                    command_str = _desktop_wait_for_cmd(2, debug)
                break
            if command_str.startswith('show dm'):
                page_number = 1
                prev_timeline_first_id = ''
                curr_timeline = 'dm'
                box_json = c2s_box_json(session, nickname, password,
                                        domain, port, http_prefix,
                                        curr_timeline, page_number,
                                        debug, signing_priv_key_pem,
                                        mitm_servers)
                if box_json:
                    _desktop_show_box(indent, follow_requests_json,
                                      your_actor, curr_timeline, box_json,
                                      translate,
                                      screenreader, system_language, espeak,
                                      page_number)
            elif command_str.startswith('show rep'):
                page_number = 1
                prev_timeline_first_id = ''
                curr_timeline = 'tlreplies'
                box_json = c2s_box_json(session, nickname, password,
                                        domain, port, http_prefix,
                                        curr_timeline, page_number,
                                        debug, signing_priv_key_pem,
                                        mitm_servers)
                if box_json:
                    _desktop_show_box(indent, follow_requests_json,
                                      your_actor, curr_timeline, box_json,
                                      translate,
                                      screenreader, system_language, espeak,
                                      page_number)
            elif command_str.startswith('show b'):
                page_number = 1
                prev_timeline_first_id = ''
                curr_timeline = 'tlbookmarks'
                box_json = c2s_box_json(session, nickname, password,
                                        domain, port, http_prefix,
                                        curr_timeline, page_number,
                                        debug, signing_priv_key_pem,
                                        mitm_servers)
                if box_json:
                    _desktop_show_box(indent, follow_requests_json,
                                      your_actor, curr_timeline, box_json,
                                      translate,
                                      screenreader, system_language, espeak,
                                      page_number)
            elif (command_str.startswith('show sen') or
                  command_str.startswith('show out')):
                page_number = 1
                prev_timeline_first_id = ''
                curr_timeline = 'outbox'
                box_json = c2s_box_json(session, nickname, password,
                                        domain, port, http_prefix,
                                        curr_timeline, page_number,
                                        debug, signing_priv_key_pem,
                                        mitm_servers)
                if box_json:
                    _desktop_show_box(indent, follow_requests_json,
                                      your_actor, curr_timeline, box_json,
                                      translate,
                                      screenreader, system_language, espeak,
                                      page_number)
            elif (command_str == 'show' or command_str.startswith('show in') or
                  command_str == 'clear'):
                page_number = 1
                prev_timeline_first_id = ''
                curr_timeline = 'inbox'
                refresh_timeline = True
            elif command_str.startswith('next'):
                page_number += 1
                prev_timeline_first_id = ''
                refresh_timeline = True
            elif command_str.startswith('prev'):
                page_number -= 1
                page_number = max(page_number, 1)
                prev_timeline_first_id = ''
                box_json = c2s_box_json(session, nickname, password,
                                        domain, port, http_prefix,
                                        curr_timeline, page_number,
                                        debug, signing_priv_key_pem,
                                        mitm_servers)
                if box_json:
                    _desktop_show_box(indent, follow_requests_json,
                                      your_actor, curr_timeline, box_json,
                                      translate,
                                      screenreader, system_language, espeak,
                                      page_number)
            elif (command_str.startswith('read ') or
                  command_str.startswith('show ') or
                  command_str == 'read' or
                  command_str == 'show'):
                if command_str in ('read', 'show'):
                    post_index_str = '1'
                else:
                    if 'read ' in command_str:
                        post_index_str = command_str.split('read ')[1]
                    else:
                        post_index_str = command_str.split('show ')[1]
                    if len(post_index_str) > 5:
                        post_index_str = "1"
                if box_json and post_index_str.isdigit():
                    _desktop_clear_screen()
                    _desktop_show_banner()
                    if len(post_index_str) > 5:
                        post_index_str = "1"
                    post_index = int(post_index_str)
                    post_json_object = \
                        _read_local_box_post(session, nickname, domain,
                                             http_prefix, base_dir,
                                             curr_timeline,
                                             page_number, post_index, box_json,
                                             system_language, screenreader,
                                             espeak, translate, your_actor,
                                             domain_full, person_cache,
                                             signing_priv_key_pem,
                                             blocked_cache, block_federated,
                                             bold_reading, mitm_servers)
                    print('')
                    say_str = translate['Press Enter to continue'] + '...'
                    say_str2 = _highlight_text(say_str)
                    _say_command(say_str2, say_str,
                                 screenreader, system_language, espeak)
                    input()
                    prev_timeline_first_id = ''
                    refresh_timeline = True
                print('')
            elif (command_str.startswith('profile ') or
                  command_str == 'profile'):
                actor_json = None
                if command_str == 'profile':
                    if post_json_object:
                        actor_json = \
                            _desktop_show_profile(session, nickname,
                                                  domain, domain_full,
                                                  base_dir, post_index,
                                                  box_json,
                                                  system_language,
                                                  screenreader,
                                                  espeak, translate,
                                                  post_json_object,
                                                  signing_priv_key_pem,
                                                  http_prefix,
                                                  mitm_servers)
                    else:
                        post_index_str = '1'
                else:
                    post_index_str = command_str.split('profile ')[1]

                if not post_index_str.isdigit():
                    profile_handle = post_index_str
                    _desktop_clear_screen()
                    _desktop_show_banner()
                    _desktop_show_profile_from_handle(session, nickname,
                                                      domain, domain_full,
                                                      base_dir,
                                                      profile_handle,
                                                      system_language,
                                                      screenreader,
                                                      espeak, translate,
                                                      signing_priv_key_pem,
                                                      http_prefix,
                                                      mitm_servers)
                    say_str = translate['Press Enter to continue'] + '...'
                    say_str2 = _highlight_text(say_str)
                    _say_command(say_str2, say_str,
                                 screenreader, system_language, espeak)
                    input()
                    prev_timeline_first_id = ''
                    refresh_timeline = True
                elif not actor_json and box_json:
                    _desktop_clear_screen()
                    _desktop_show_banner()
                    if len(post_index_str) > 5:
                        post_index_str = "1"
                    post_index = int(post_index_str)
                    actor_json = \
                        _desktop_show_profile(session, nickname,
                                              domain, domain_full,
                                              base_dir, post_index,
                                              box_json,
                                              system_language, screenreader,
                                              espeak, translate,
                                              None, signing_priv_key_pem,
                                              http_prefix, mitm_servers)
                    say_str = translate['Press Enter to continue'] + '...'
                    say_str2 = _highlight_text(say_str)
                    _say_command(say_str2, say_str,
                                 screenreader, system_language, espeak)
                    input()
                    prev_timeline_first_id = ''
                    refresh_timeline = True
                print('')
            elif command_str in ('reply', 'r'):
                if post_json_object:
                    post_content = ''
                    if post_json_object['object'].get('content'):
                        post_content = post_json_object['object']['content']
                    post_summary = ''
                    if post_json_object['object'].get('summary'):
                        post_summary = post_json_object['object']['summary']
                    if not disallow_reply(post_summary + ' ' + post_content):
                        if post_json_object.get('id'):
                            post_id = post_json_object['id']
                            subject = None
                            if post_json_object['object'].get('summary'):
                                subject = post_json_object['object']['summary']
                            conversation_id = None
                            convthread_id = None
                            # Due to lack of AP specification maintenance,
                            # a conversation can also be referred to as a
                            # thread or (confusingly) "context"
                            if post_json_object['object'].get('conversation'):
                                conversation_id = \
                                    post_json_object['object']['conversation']
                            elif post_json_object['object'].get('context'):
                                conversation_id = \
                                    post_json_object['object']['context']
                            if post_json_object['object'].get('thread'):
                                convthread_id = \
                                    post_json_object['object']['thread']
                            session_reply = create_session(proxy_type)
                            _desktop_reply_to_post(session_reply, post_id,
                                                   base_dir, nickname,
                                                   password,
                                                   domain, port, http_prefix,
                                                   cached_webfingers,
                                                   person_cache,
                                                   debug, subject,
                                                   screenreader,
                                                   system_language,
                                                   languages_understood,
                                                   espeak, conversation_id,
                                                   convthread_id,
                                                   low_bandwidth,
                                                   content_license_url,
                                                   media_license_url,
                                                   media_creator,
                                                   signing_priv_key_pem,
                                                   translate,
                                                   mitm_servers)
                refresh_timeline = True
                print('')
            elif (command_str == 'post' or command_str == 'p' or
                  command_str == 'send' or
                  command_str.startswith('dm ') or
                  command_str.startswith('direct message ') or
                  command_str.startswith('post ') or
                  command_str.startswith('send ')):
                session_post = create_session(proxy_type)
                if command_str.startswith('dm ') or \
                   command_str.startswith('direct message ') or \
                   command_str.startswith('post ') or \
                   command_str.startswith('send '):
                    replacements = {
                        ' to ': ' ',
                        ' dm ': ' ',
                        ' DM ': ' '
                    }
                    command_str = replace_strings(command_str, replacements)
                    # direct message
                    to_handle = None
                    if command_str.startswith('post '):
                        to_handle = command_str.split('post ', 1)[1]
                    elif command_str.startswith('send '):
                        to_handle = command_str.split('send ', 1)[1]
                    elif command_str.startswith('dm '):
                        to_handle = command_str.split('dm ', 1)[1]
                    elif command_str.startswith('direct message '):
                        to_handle = command_str.split('direct message ', 1)[1]
                    if to_handle:
                        _desktop_new_dm(session_post, to_handle,
                                        base_dir, nickname, password,
                                        domain, port, http_prefix,
                                        cached_webfingers, person_cache,
                                        debug,
                                        screenreader, system_language,
                                        languages_understood,
                                        espeak, low_bandwidth,
                                        content_license_url,
                                        media_license_url, media_creator,
                                        signing_priv_key_pem, translate,
                                        mitm_servers)
                        refresh_timeline = True
                else:
                    # public post
                    _desktop_new_post(session_post,
                                      base_dir, nickname, password,
                                      domain, port, http_prefix,
                                      cached_webfingers, person_cache,
                                      debug,
                                      screenreader, system_language,
                                      languages_understood,
                                      espeak, low_bandwidth,
                                      content_license_url,
                                      media_license_url, media_creator,
                                      signing_priv_key_pem, translate,
                                      mitm_servers)
                    refresh_timeline = True
                print('')
            elif command_str == 'like' or command_str.startswith('like '):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        like_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(like_actor)
                        if say_str1:
                            say_str = 'Liking post by ' + say_str1
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            session_like = create_session(proxy_type)
                            send_like_via_server(base_dir, session_like,
                                                 nickname, password,
                                                 domain, port, http_prefix,
                                                 post_json_object['id'],
                                                 cached_webfingers,
                                                 person_cache,
                                                 False, __version__,
                                                 signing_priv_key_pem,
                                                 system_language,
                                                 mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str == 'undo mute' or
                  command_str == 'undo ignore' or
                  command_str == 'remove mute' or
                  command_str == 'rm mute' or
                  command_str == 'unmute' or
                  command_str == 'unignore' or
                  command_str == 'mute undo' or
                  command_str.startswith('undo mute ') or
                  command_str.startswith('undo ignore ') or
                  command_str.startswith('remove mute ') or
                  command_str.startswith('remove ignore ') or
                  command_str.startswith('unignore ') or
                  command_str.startswith('unmute ')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        mute_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(mute_actor)
                        if say_str1:
                            say_str = 'Unmuting post by ' + say_str1
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            session_mute = create_session(proxy_type)
                            send_undo_mute_via_server(base_dir, session_mute,
                                                      nickname, password,
                                                      domain, port,
                                                      http_prefix,
                                                      post_json_object['id'],
                                                      cached_webfingers,
                                                      person_cache,
                                                      False, __version__,
                                                      signing_priv_key_pem,
                                                      system_language,
                                                      mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str == 'mute' or
                  command_str == 'ignore' or
                  command_str.startswith('mute ') or
                  command_str.startswith('ignore ')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        mute_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(mute_actor)
                        say_str = 'Muting post by ' + say_str1
                        if say_str1:
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            session_mute = create_session(proxy_type)
                            send_mute_via_server(base_dir, session_mute,
                                                 nickname, password,
                                                 domain, port,
                                                 http_prefix,
                                                 post_json_object['id'],
                                                 cached_webfingers,
                                                 person_cache,
                                                 False, __version__,
                                                 signing_priv_key_pem,
                                                 system_language,
                                                 mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str == 'undo bookmark' or
                  command_str == 'remove bookmark' or
                  command_str == 'rm bookmark' or
                  command_str == 'undo bm' or
                  command_str == 'rm bm' or
                  command_str == 'remove bm' or
                  command_str == 'unbookmark' or
                  command_str == 'bookmark undo' or
                  command_str == 'bm undo ' or
                  command_str.startswith('undo bm ') or
                  command_str.startswith('remove bm ') or
                  command_str.startswith('undo bookmark ') or
                  command_str.startswith('remove bookmark ') or
                  command_str.startswith('unbookmark ') or
                  command_str.startswith('unbm ')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        bm_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(bm_actor)
                        if say_str1:
                            say_str = 'Unbookmarking post by ' + say_str1
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            sessionbm = create_session(proxy_type)
                            id_str = post_json_object['id']
                            send_undo_bookmark_via_server(base_dir, sessionbm,
                                                          nickname, password,
                                                          domain, port,
                                                          http_prefix,
                                                          id_str,
                                                          cached_webfingers,
                                                          person_cache,
                                                          False, __version__,
                                                          signing_priv_key_pem,
                                                          system_language,
                                                          mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str == 'bookmark' or
                  command_str == 'bm' or
                  command_str.startswith('bookmark ') or
                  command_str.startswith('bm ')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        bm_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(bm_actor)
                        if say_str1:
                            say_str = 'Bookmarking post by ' + say_str1
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            sessionbm = create_session(proxy_type)
                            send_bookmark_via_server(base_dir, sessionbm,
                                                     nickname, password,
                                                     domain, port, http_prefix,
                                                     post_json_object['id'],
                                                     cached_webfingers,
                                                     person_cache,
                                                     False, __version__,
                                                     signing_priv_key_pem,
                                                     system_language,
                                                     mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str.startswith('undo block ') or
                  command_str.startswith('remove block ') or
                  command_str.startswith('rm block ') or
                  command_str.startswith('unblock ')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id') and \
                       post_json_object.get('object'):
                        if has_object_dict(post_json_object):
                            if post_json_object['object'].get('attributedTo'):
                                attrib_field = \
                                    post_json_object['object']['attributedTo']
                                block_actor = get_attributed_to(attrib_field)
                                say_str1 = get_nickname_from_actor(block_actor)
                                if say_str1:
                                    say_str = 'Unblocking ' + say_str1
                                    _say_command(say_str, say_str,
                                                 screenreader,
                                                 system_language, espeak)
                                    session_block = create_session(proxy_type)
                                    sign_key_pem = signing_priv_key_pem
                                    cached_wf = cached_webfingers
                                    send_undo_block_via_server(base_dir,
                                                               session_block,
                                                               nickname,
                                                               password,
                                                               domain, port,
                                                               http_prefix,
                                                               block_actor,
                                                               cached_wf,
                                                               person_cache,
                                                               False,
                                                               __version__,
                                                               sign_key_pem,
                                                               system_language,
                                                               mitm_servers)
                refresh_timeline = True
                print('')
            elif command_str.startswith('block '):
                block_actor = None
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                    else:
                        if '@' in post_index:
                            block_handle = post_index
                            if block_handle.startswith('@'):
                                block_handle = block_handle[1:]
                            if '@' in block_handle:
                                block_domain = block_handle.split('@')[1]
                                block_nickname = block_handle.split('@')[0]
                                block_actor = \
                                    local_actor_url(http_prefix,
                                                    block_nickname,
                                                    block_domain)
                if curr_index > 0 and box_json and not block_actor:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object and not block_actor:
                    if post_json_object.get('id') and \
                       post_json_object.get('object'):
                        if has_object_dict(post_json_object):
                            if post_json_object['object'].get('attributedTo'):
                                attrib_field = \
                                    post_json_object['object']['attributedTo']
                                block_actor = get_attributed_to(attrib_field)
                if block_actor:
                    say_str1 = get_nickname_from_actor(block_actor)
                    if say_str1:
                        say_str = 'Blocking ' + say_str1
                        _say_command(say_str, say_str,
                                     screenreader,
                                     system_language, espeak)
                        session_block = create_session(proxy_type)
                        send_block_via_server(base_dir, session_block,
                                              nickname, password,
                                              domain, port,
                                              http_prefix,
                                              block_actor,
                                              cached_webfingers,
                                              person_cache,
                                              False, __version__,
                                              signing_priv_key_pem,
                                              system_language,
                                              mitm_servers)
                refresh_timeline = True
                print('')
            elif command_str in ('unlike', 'undo like'):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        unlike_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(unlike_actor)
                        if say_str1:
                            say_str = \
                                'Undoing like of post by ' + \
                                say_str1
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            session_unlike = create_session(proxy_type)
                            send_undo_like_via_server(base_dir, session_unlike,
                                                      nickname, password,
                                                      domain, port,
                                                      http_prefix,
                                                      post_json_object['id'],
                                                      cached_webfingers,
                                                      person_cache,
                                                      False, __version__,
                                                      signing_priv_key_pem,
                                                      system_language,
                                                      mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str.startswith('announce') or
                  command_str.startswith('boost') or
                  command_str.startswith('retweet')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    post_content = ''
                    if post_json_object['object'].get('content'):
                        post_content = post_json_object['object']['content']
                    post_summary = ''
                    if post_json_object['object'].get('summary'):
                        post_summary = post_json_object['object']['summary']
                    attachment = get_post_attachments(post_json_object)
                    capabilities = {}
                    if post_json_object['object'].get('capabilities'):
                        capabilities = \
                            post_json_object['object']['capabilities']
                    if not disallow_announce(post_summary + ' ' +
                                             post_content, attachment,
                                             capabilities):
                        if post_json_object.get('id'):
                            post_id = post_json_object['id']
                            attrib_field = \
                                post_json_object['object']['attributedTo']
                            announce_actor = get_attributed_to(attrib_field)
                            say_str1 = get_nickname_from_actor(announce_actor)
                            if say_str1:
                                say_str = 'Announcing post by ' + say_str1
                                _say_command(say_str, say_str,
                                             screenreader,
                                             system_language, espeak)
                                session_announce = create_session(proxy_type)
                                send_announce_via_server(base_dir,
                                                         session_announce,
                                                         nickname, password,
                                                         domain, port,
                                                         http_prefix, post_id,
                                                         cached_webfingers,
                                                         person_cache,
                                                         True, __version__,
                                                         signing_priv_key_pem,
                                                         system_language,
                                                         mitm_servers)
                    refresh_timeline = True
                print('')
            elif (command_str.startswith('unannounce') or
                  command_str.startswith('undo announce') or
                  command_str.startswith('unboost') or
                  command_str.startswith('undo boost') or
                  command_str.startswith('undo retweet')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        post_id = post_json_object['id']
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        announce_actor = get_attributed_to(attrib_field)
                        say_str1 = get_nickname_from_actor(announce_actor)
                        if say_str1:
                            say_str = 'Undoing announce post by ' + say_str1
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                            session_announce = create_session(proxy_type)
                            send_undo_announce_via_server(base_dir,
                                                          session_announce,
                                                          post_json_object,
                                                          nickname, password,
                                                          domain, port,
                                                          http_prefix,
                                                          cached_webfingers,
                                                          person_cache,
                                                          True, __version__,
                                                          signing_priv_key_pem,
                                                          system_language,
                                                          mitm_servers)
                            refresh_timeline = True
                print('')
            elif (command_str == 'follow requests' or
                  command_str.startswith('follow requests ')):
                curr_page = 1
                if ' ' in command_str:
                    page_num = command_str.split(' ')[-1].strip()
                    if len(page_num) > 5:
                        page_num = "1"
                    if page_num.isdigit():
                        curr_page = int(page_num)
                follow_requests_json = \
                    get_follow_requests_via_server(session,
                                                   nickname, password,
                                                   domain, port,
                                                   http_prefix, curr_page,
                                                   debug, __version__,
                                                   signing_priv_key_pem,
                                                   mitm_servers)
                if follow_requests_json:
                    if isinstance(follow_requests_json, dict):
                        _desktop_show_follow_requests(follow_requests_json,
                                                      translate)
                print('')
            elif (command_str == 'following' or
                  command_str.startswith('following ')):
                curr_page = 1
                if ' ' in command_str:
                    page_num = command_str.split(' ')[-1].strip()
                    if len(page_num) > 5:
                        page_num = "1"
                    if page_num.isdigit():
                        curr_page = int(page_num)
                following_json = \
                    get_following_via_server(session,
                                             nickname, password,
                                             domain, port,
                                             http_prefix, curr_page,
                                             debug, __version__,
                                             signing_priv_key_pem,
                                             mitm_servers)
                if following_json:
                    if isinstance(following_json, dict):
                        _desktop_show_following(following_json, translate,
                                                curr_page, indent,
                                                'following')
                print('')
            elif (command_str == 'followers' or
                  command_str.startswith('followers ')):
                curr_page = 1
                if ' ' in command_str:
                    page_num = command_str.split(' ')[-1].strip()
                    if len(page_num) > 5:
                        page_num = "1"
                    if page_num.isdigit():
                        curr_page = int(page_num)
                followers_json = \
                    get_followers_via_server(session,
                                             nickname, password,
                                             domain, port,
                                             http_prefix, curr_page,
                                             debug, __version__,
                                             signing_priv_key_pem,
                                             mitm_servers)
                if followers_json:
                    if isinstance(followers_json, dict):
                        _desktop_show_following(followers_json, translate,
                                                curr_page, indent,
                                                'followers')
                print('')
            elif (command_str == 'follow' or
                  command_str.startswith('follow ')):
                if command_str == 'follow':
                    if actor_json:
                        follow_handle = actor_json['id']
                    else:
                        follow_handle = ''
                else:
                    follow_handle = command_str.replace('follow ', '').strip()
                    if follow_handle.startswith('@'):
                        follow_handle = follow_handle[1:]

                if '@' in follow_handle or '://' in follow_handle:
                    follow_nickname = get_nickname_from_actor(follow_handle)
                    follow_domain, follow_port = \
                        get_domain_from_actor(follow_handle)
                    if follow_nickname and follow_domain:
                        say_str = 'Sending follow request to ' + \
                            follow_nickname + '@' + follow_domain
                        _say_command(say_str, say_str,
                                     screenreader, system_language, espeak)
                        session_follow = create_session(proxy_type)
                        send_follow_request_via_server(base_dir,
                                                       session_follow,
                                                       nickname, password,
                                                       domain, port,
                                                       follow_nickname,
                                                       follow_domain,
                                                       follow_port,
                                                       http_prefix,
                                                       cached_webfingers,
                                                       person_cache,
                                                       debug, __version__,
                                                       signing_priv_key_pem,
                                                       system_language,
                                                       mitm_servers)
                    else:
                        if follow_handle:
                            say_str = follow_handle + ' is not valid'
                        else:
                            say_str = 'Specify a handle to follow'
                        _say_command(say_str,
                                     screenreader, system_language, espeak)
                    print('')
            elif (command_str.startswith('unfollow ') or
                  command_str.startswith('stop following ')):
                follow_handle = command_str.replace('unfollow ', '').strip()
                follow_handle = follow_handle.replace('stop following ', '')
                if follow_handle.startswith('@'):
                    follow_handle = follow_handle[1:]
                if '@' in follow_handle or '://' in follow_handle:
                    follow_nickname = get_nickname_from_actor(follow_handle)
                    follow_domain, follow_port = \
                        get_domain_from_actor(follow_handle)
                    if follow_nickname and follow_domain:
                        say_str = 'Stop following ' + \
                            follow_nickname + '@' + follow_domain
                        _say_command(say_str, say_str,
                                     screenreader, system_language, espeak)
                        session_unfollow = create_session(proxy_type)
                        send_unfollow_request_via_server(base_dir,
                                                         session_unfollow,
                                                         nickname, password,
                                                         domain, port,
                                                         follow_nickname,
                                                         follow_domain,
                                                         follow_port,
                                                         http_prefix,
                                                         cached_webfingers,
                                                         person_cache,
                                                         debug, __version__,
                                                         signing_priv_key_pem,
                                                         system_language,
                                                         mitm_servers)
                    else:
                        say_str = follow_handle + ' is not valid'
                        _say_command(say_str, say_str,
                                     screenreader, system_language, espeak)
                    print('')
            elif command_str.startswith('approve '):
                approve_handle = command_str.replace('approve ', '').strip()
                if approve_handle.startswith('@'):
                    approve_handle = approve_handle[1:]

                if '@' in approve_handle or '://' in approve_handle:
                    approve_nickname = get_nickname_from_actor(approve_handle)
                    approve_domain, _ = \
                        get_domain_from_actor(approve_handle)
                    if approve_nickname and approve_domain:
                        say_str = 'Sending approve follow request for ' + \
                            approve_nickname + '@' + approve_domain
                        _say_command(say_str, say_str,
                                     screenreader, system_language, espeak)
                        session_approve = create_session(proxy_type)
                        approve_follow_request_via_server(session_approve,
                                                          nickname, password,
                                                          domain, port,
                                                          http_prefix,
                                                          approve_handle,
                                                          debug,
                                                          __version__,
                                                          signing_priv_key_pem,
                                                          mitm_servers)
                    else:
                        if approve_handle:
                            say_str = approve_handle + ' is not valid'
                        else:
                            say_str = 'Specify a handle to approve'
                        _say_command(say_str,
                                     screenreader, system_language, espeak)
                    print('')
            elif command_str.startswith('deny '):
                deny_handle = command_str.replace('deny ', '').strip()
                if deny_handle.startswith('@'):
                    deny_handle = deny_handle[1:]

                if '@' in deny_handle or '://' in deny_handle:
                    deny_nickname = get_nickname_from_actor(deny_handle)
                    deny_domain, _ = \
                        get_domain_from_actor(deny_handle)
                    if deny_nickname and deny_domain:
                        say_str = 'Sending deny follow request for ' + \
                            deny_nickname + '@' + deny_domain
                        _say_command(say_str, say_str,
                                     screenreader, system_language, espeak)
                        session_deny = create_session(proxy_type)
                        deny_follow_request_via_server(session_deny,
                                                       nickname, password,
                                                       domain, port,
                                                       http_prefix,
                                                       deny_handle,
                                                       debug,
                                                       __version__,
                                                       signing_priv_key_pem,
                                                       mitm_servers)
                    else:
                        if deny_handle:
                            say_str = deny_handle + ' is not valid'
                        else:
                            say_str = 'Specify a handle to deny'
                        _say_command(say_str,
                                     screenreader, system_language, espeak)
                    print('')
            elif command_str in ('repeat', 'replay', 'rp',
                                 'again', 'say again'):
                if screenreader and name_str and \
                   gender and message_str and content:
                    say_str = 'Repeating ' + name_str
                    _say_command(say_str, say_str, screenreader,
                                 system_language, espeak,
                                 name_str, gender)
                    time.sleep(2)
                    _say_command(content, message_str, screenreader,
                                 system_language, espeak,
                                 name_str, gender)
                    print('')
            elif command_str in ('sounds on',
                                 'sound on',
                                 'sound'):
                say_str = 'Notification sounds on'
                _say_command(say_str, say_str, screenreader,
                             system_language, espeak)
                notification_sounds = True
            elif command_str in ('sounds off',
                                 'sound off',
                                 'nosound'):
                say_str = 'Notification sounds off'
                _say_command(say_str, say_str, screenreader,
                             system_language, espeak)
                notification_sounds = False
            elif command_str in ('speak',
                                 'screen reader on',
                                 'speak on',
                                 'speaker on',
                                 'talker on',
                                 'talk on',
                                 'reader on'):
                if original_screen_reader:
                    screenreader = original_screen_reader
                    say_str = 'Screen reader on'
                    _say_command(say_str, say_str, screenreader,
                                 system_language, espeak)
                else:
                    print('No --screenreader option was specified')
            elif command_str in ('mute',
                                 'screen reader off',
                                 'speaker off',
                                 'talker off',
                                 'reader off'):
                if original_screen_reader:
                    screenreader = None
                    say_str = 'Screen reader off'
                    _say_command(say_str, say_str, original_screen_reader,
                                 system_language, espeak)
                else:
                    print('No --screenreader option was specified')
            elif command_str.startswith('open'):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object['type'] == 'Announce':
                        recent_posts_cache = {}
                        allow_local_network_access = False
                        yt_replace_domain = None
                        twitter_replacement_domain = None
                        show_vote_posts = False
                        post_json_object2 = \
                            download_announce(session, base_dir,
                                              http_prefix,
                                              nickname, domain,
                                              post_json_object,
                                              __version__,
                                              yt_replace_domain,
                                              twitter_replacement_domain,
                                              allow_local_network_access,
                                              recent_posts_cache, False,
                                              system_language,
                                              domain_full, person_cache,
                                              signing_priv_key_pem,
                                              blocked_cache,
                                              block_federated,
                                              bold_reading,
                                              show_vote_posts,
                                              languages_understood,
                                              mitm_servers)
                        if post_json_object2:
                            post_json_object = post_json_object2
                if post_json_object:
                    content = \
                        get_base_content_from_post(post_json_object,
                                                   system_language)
                    message_str, detected_links = \
                        speakable_text(http_prefix,
                                       nickname, domain, domain_full,
                                       base_dir, content, translate)
                    link_opened = False
                    for url in detected_links:
                        if '://' in url:
                            webbrowser.open(url)
                            link_opened = True
                    if link_opened:
                        say_str = 'Opened web links'
                        _say_command(say_str, say_str, original_screen_reader,
                                     system_language, espeak)
                    else:
                        say_str = 'There are no web links to open.'
                        _say_command(say_str, say_str, original_screen_reader,
                                     system_language, espeak)
                print('')
            elif (command_str.startswith('pgp') or
                  command_str.startswith('gpg')):
                if not has_local_pg_pkey():
                    print('No PGP public key was found')
                else:
                    print(pgp_local_public_key())
                print('')
            elif command_str.startswith('h'):
                _desktop_help()
                say_str = translate['Press Enter to continue'] + '...'
                say_str2 = _highlight_text(say_str)
                _say_command(say_str2, say_str,
                             screenreader, system_language, espeak)
                input()
                prev_timeline_first_id = ''
                refresh_timeline = True
            elif (command_str == 'delete' or
                  command_str == 'rm' or
                  command_str.startswith('delete ') or
                  command_str.startswith('rm ')):
                curr_index = 0
                if ' ' in command_str:
                    post_index = command_str.split(' ')[-1].strip()
                    if len(post_index) > 5:
                        post_index = "1"
                    if post_index.isdigit():
                        curr_index = int(post_index)
                if curr_index > 0 and box_json:
                    post_json_object = \
                        _desktop_get_box_post_object(box_json, curr_index)
                if post_json_object:
                    if post_json_object.get('id'):
                        attrib_field = \
                            post_json_object['object']['attributedTo']
                        rm_actor = get_attributed_to(attrib_field)
                        if rm_actor != your_actor:
                            say_str = 'You can only delete your own posts'
                            _say_command(say_str, say_str,
                                         screenreader,
                                         system_language, espeak)
                        else:
                            print('')
                            if post_json_object['object'].get('summary'):
                                print(post_json_object['object']['summary'])
                            content_str = \
                                get_base_content_from_post(post_json_object,
                                                           system_language)
                            print(content_str)
                            print('')
                            say_str = 'Confirm delete, yes or no?'
                            _say_command(say_str, say_str, screenreader,
                                         system_language, espeak)
                            yesno = input()
                            if 'y' not in yesno.lower():
                                say_str = 'Deleting post'
                                _say_command(say_str, say_str,
                                             screenreader,
                                             system_language, espeak)
                                sessionrm = create_session(proxy_type)
                                send_delete_via_server(base_dir, sessionrm,
                                                       nickname, password,
                                                       domain, port,
                                                       http_prefix,
                                                       post_json_object['id'],
                                                       cached_webfingers,
                                                       person_cache,
                                                       False, __version__,
                                                       signing_priv_key_pem,
                                                       system_language,
                                                       mitm_servers)
                                refresh_timeline = True
                print('')

            if refresh_timeline:
                if box_json:
                    _desktop_show_box(indent, follow_requests_json,
                                      your_actor, curr_timeline, box_json,
                                      translate,
                                      screenreader, system_language,
                                      espeak, page_number)
