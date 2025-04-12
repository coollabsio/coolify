__filename__ = "speaker.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Accessibility"

import os
import html
import random
import urllib.parse
from flags import is_reply
from flags import is_pgp_encrypted
from utils import data_dir
from utils import get_post_attachments
from utils import get_cached_post_filename
from utils import remove_id_ending
from utils import is_dm
from utils import camel_case_split
from utils import get_domain_from_actor
from utils import get_nickname_from_actor
from utils import get_gender_from_bio
from utils import get_display_name
from utils import remove_html
from utils import load_json
from utils import save_json
from utils import has_object_dict
from utils import acct_dir
from utils import local_actor_url
from utils import get_actor_from_post
from content import html_replace_quote_marks

SPEAKER_REMOVE_CHARS = ('.\n', '. ', ',', ';', '?', '!')


def get_speaker_pitch(display_name: str, screenreader: str,
                      gender: str) -> int:
    """Returns the speech synthesis pitch for the given name
    """
    random.seed(display_name)
    range_min = 1
    range_max = 100
    if 'She' in gender:
        range_min = 50
    elif 'Him' in gender:
        range_max = 50
    if screenreader == 'picospeaker':
        range_min = -6
        range_max = 3
        if 'She' in gender:
            range_min = -1
        elif 'Him' in gender:
            range_max = -1
    return random.randint(range_min, range_max)


def get_speaker_rate(display_name: str, screenreader: str) -> int:
    """Returns the speech synthesis rate for the given name
    """
    random.seed(display_name)
    if screenreader == 'picospeaker':
        return random.randint(-40, -20)
    return random.randint(50, 120)


def get_speaker_range(display_name: str) -> int:
    """Returns the speech synthesis range for the given name
    """
    random.seed(display_name)
    return random.randint(300, 800)


def _speaker_pronounce(base_dir: str, say_text: str, translate: {}) -> str:
    """Screen readers may not always pronounce correctly, so you
    can have a file which specifies conversions. File should contain
    line items such as:
    Epicyon -> Epi-cyon
    """
    pronounce_filename = data_dir(base_dir) + '/speaker_pronounce.txt'
    convert_dict = {}
    if translate:
        convert_dict = {
            "Epicyon": "Epi-cyon",
            "espeak": "e-speak",
            "emoji": "emowji",
            "clearnet": "clear-net",
            "https": "H-T-T-P-S",
            "HTTPS": "H-T-T-P-S",
            "XMPP": "X-M-P-P",
            "xmpp": "X-M-P-P",
            "sql": "S-Q-L",
            ".js": " dot J-S",
            "PSQL": "Postgres S-Q-L",
            "SQL": "S-Q-L",
            "gdpr": "G-D-P-R",
            "kde": "K-D-E",
            "AGPL": "Affearo G-P-L",
            "agpl": "Affearo G-P-L",
            "GPL": "G-P-L",
            "gpl": "G-P-L",
            "coop": "co-op",
            "KMail": "K-Mail",
            "kmail": "K-Mail",
            "gmail": "G-mail",
            "Gmail": "G-mail",
            "OpenPGP": "Open P-G-P",
            "Tor": "Toor",
            "memes": "meemes",
            "Memes": "Meemes",
            "rofl": translate["laughing"],
            "ROFL": translate["laughing"],
            "lmao": translate["laughing"],
            "LMAO": translate["laughing"],
            "fwiw": "for what it's worth",
            "fyi": "for your information",
            "irl": "in real life",
            "IRL": "in real life",
            "imho": "in my opinion",
            "afaik": "as far as I know",
            "AFAIK": "as far as I know",
            "fediverse": "fediiverse",
            "Fediverse": "Fediiverse",
            " foss ": " free and open source software ",
            " floss ": " free libre and open source software ",
            " FOSS ": "free and open source software",
            " FLOSS ": "free libre and open source software",
            " oss ": " open source software ",
            " OSS ": " open source software ",
            "🤔": ". " + translate["thinking emoji"],
            "RT @": "Re-Tweet ",
            "#nowplaying": translate["hashtag"] + " now-playing",
            "#NowPlaying": translate["hashtag"] + " now-playing",
            "#": translate["hashtag"] + ' ',
            "¯\\_(ツ)_/¯": translate["shrug"],
            ":D": '. ' + translate["laughing"],
            ":-D": '. ' + translate["laughing"],
            ":)": '. ' + translate["smile"],
            ";)": '. ' + translate["wink"],
            ":(": '. ' + translate["sad face"],
            ":-)": '. ' + translate["smile"],
            ":-(": '. ' + translate["sad face"],
            ";-)": '. ' + translate["wink"],
            ":O": '. ' + translate['shocked'],
            "?": "? ",
            '"': "'",
            "*": "",
            "(": ",",
            ")": ","
        }
    if os.path.isfile(pronounce_filename):
        pronounce_list: list[str] = []
        try:
            with open(pronounce_filename, 'r', encoding='utf-8') as fp_pro:
                pronounce_list = fp_pro.readlines()
        except OSError:
            print('EX: _speaker_pronounce unable to read ' +
                  pronounce_filename)
        if pronounce_list:
            for conversion in pronounce_list:
                separator = None
                if '->' in conversion:
                    separator = '->'
                elif ';' in conversion:
                    separator = ';'
                elif ':' in conversion:
                    separator = ':'
                elif ',' in conversion:
                    separator = ','
                if not separator:
                    continue

                text = conversion.split(separator)[0].strip()
                converted = conversion.split(separator)[1].strip()
                convert_dict[text] = converted
    for text, converted in convert_dict.items():
        if text in say_text:
            say_text = say_text.replace(text, converted)
    return say_text


def speaker_replace_links(http_prefix: str, nickname: str,
                          orig_domain: str, orig_domain_full: str,
                          say_text: str, translate: {},
                          detected_links: []) -> str:
    """Replaces any links in the given text with "link to [domain]".
    Instead of reading out potentially very long and meaningless links
    """
    text = say_text
    text = text.replace('?v=', '__v=')
    for char in SPEAKER_REMOVE_CHARS:
        text = text.replace(char, ' ')
    text = text.replace('__v=', '?v=')
    replacements = {}
    replacements_hashtags = {}
    words_list = text.split(' ')
    if translate.get('Linked'):
        linked_str = translate['Linked']
    else:
        linked_str = 'Linked'
    prev_word = ''
    for word in words_list:
        if word.startswith('v='):
            replacements[word] = ''
        if word.startswith(':'):
            if word.endswith(':'):
                replacements[word] = ', emowji ' + word.replace(':', '') + ','
                continue
        if word.startswith('@') and not prev_word.endswith('RT'):
            # replace mentions, but not re-tweets
            if translate.get('mentioning'):
                replacements[word] = \
                    translate['mentioning'] + ' ' + word[1:] + ', '
        prev_word = word

        domain = None
        domain_full = None
        if 'https://' in word:
            domain = word.split('https://')[1]
            domain_full = 'https://' + domain
        elif 'http://' in word:
            domain = word.split('http://')[1]
            domain_full = 'http://' + domain
        if not domain:
            continue
        if '/' in domain:
            domain = domain.split('/')[0]
        if domain.startswith('www.'):
            domain = domain.replace('www.', '')
        replacements[domain_full] = '. ' + linked_str + ' ' + domain + '.'
        if '/tags/' in domain_full and domain != orig_domain:
            remote_hashtag_link = \
                http_prefix + '://' + orig_domain_full + '/users/' + \
                nickname + '?remotetag=' + domain_full.replace('/', '--')
            detected_links.append(remote_hashtag_link)
        else:
            detected_links.append(domain_full)
    for replace_str, new_str in replacements.items():
        say_text = say_text.replace(replace_str, new_str)
    for replace_str, new_str in replacements_hashtags.items():
        say_text = say_text.replace(replace_str, new_str)
    return say_text.replace('..', '.')


def _add_ssml_emphasis(say_text: str) -> str:
    """Adds emphasis to *emphasised* text
    """
    if '*' not in say_text:
        return say_text
    text = say_text
    for char in SPEAKER_REMOVE_CHARS:
        text = text.replace(char, ' ')
    words_list = text.split(' ')
    replacements = {}
    for word in words_list:
        if not word.startswith('*'):
            continue
        if not word.endswith('*'):
            continue
        replacements[word] = \
            '<emphasis level="strong">' + word.replace('*', '') + \
            '</emphasis>'
    for replace_str, new_str in replacements.items():
        say_text = say_text.replace(replace_str, new_str)
    return say_text


def _remove_emoji_from_text(say_text: str) -> str:
    """Removes :emoji: from the given text
    """
    if ':' not in say_text:
        return say_text
    text = say_text
    for char in SPEAKER_REMOVE_CHARS:
        text = text.replace(char, ' ')
    words_list = text.split(' ')
    replacements = {}
    for word in words_list:
        if not word.startswith(':'):
            continue
        if word.endswith(':'):
            replacements[word] = ''
    for replace_str, new_str in replacements.items():
        say_text = say_text.replace(replace_str, new_str)
    return say_text.replace('  ', ' ').strip()


def _speaker_endpoint_json(display_name: str, summary: str,
                           content: str, say_content: str,
                           image_description: str,
                           links: [], gender: str, post_id: str,
                           post_dm: bool, post_reply: bool,
                           follow_requests_exist: bool,
                           follow_requests_list: [],
                           liked_by: str, published: str, post_cal: bool,
                           post_share: bool, theme_name: str,
                           is_direct: bool, reply_to_you: bool) -> {}:
    """Returns a json endpoint for the TTS speaker
    """
    speaker_json = {
        "name": display_name,
        "summary": summary,
        "content": content,
        "say": say_content,
        "published": published,
        "imageDescription": image_description,
        "detectedLinks": links,
        "id": post_id,
        "direct": is_direct,
        "replyToYou": reply_to_you,
        "notify": {
            "theme": theme_name,
            "dm": post_dm,
            "reply": post_reply,
            "followRequests": follow_requests_exist,
            "followRequestsList": follow_requests_list,
            "likedBy": liked_by,
            "calendar": post_cal,
            "share": post_share
        }
    }
    if gender:
        speaker_json['gender'] = gender
    return speaker_json


def _ssml_header(system_language: str, box_name: str, summary: str) -> str:
    """Returns a header for an SSML document
    """
    if summary:
        summary = ': ' + summary
    return '<?xml version="1.0"?>\n' + \
        '<speak xmlns="http://www.w3.org/2001/10/synthesis"\n' + \
        '       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"\n' + \
        '       xsi:schemaLocation="http://www.w3.org/2001/10/synthesis\n' + \
        '         http://www.w3.org/TR/speech-synthesis11/synthesis.xsd"\n' + \
        '       version="1.1">\n' + \
        '  <metadata>\n' + \
        '    <dc:title xml:lang="' + system_language + '">' + \
        box_name + summary + '</dc:title>\n' + \
        '  </metadata>\n'


def _speaker_endpoint_ssml(display_name: str, summary: str,
                           content: str, language: str,
                           gender: str, box_name: str) -> str:
    """Returns an SSML endpoint for the TTS speaker
    https://en.wikipedia.org/wiki/Speech_Synthesis_Markup_Language
    https://www.w3.org/TR/speech-synthesis/
    """
    lang_short = 'en'
    if language:
        lang_short = language[:2]
    if not gender:
        gender = 'neutral'
    else:
        if lang_short == 'en':
            gender = gender.lower()
            if 'he/him' in gender:
                gender = 'male'
            elif 'she/her' in gender:
                gender = 'female'
            else:
                gender = 'neutral'

    content = _add_ssml_emphasis(content)
    voice_params = 'name="' + display_name + '" gender="' + gender + '"'
    if summary is None:
        summary = ''
    return _ssml_header(lang_short, box_name, summary) + \
        '  <p>\n' + \
        '    <s xml:lang="' + language + '">\n' + \
        '      <voice ' + voice_params + '>\n' + \
        '        ' + content + '\n' + \
        '      </voice>\n' + \
        '    </s>\n' + \
        '  </p>\n' + \
        '</speak>\n'


def get_ssml_box(base_dir: str, path: str,
                 domain: str,
                 system_language: str,
                 box_name: str) -> str:
    """Returns SSML for the given timeline
    """
    nickname = path.split('/users/')[1]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    speaker_filename = \
        acct_dir(base_dir, nickname, domain) + '/speaker.json'
    if not os.path.isfile(speaker_filename):
        return None
    speaker_json = load_json(speaker_filename)
    if not speaker_json:
        return None
    gender = None
    if speaker_json.get('gender'):
        gender = speaker_json['gender']
    return _speaker_endpoint_ssml(speaker_json['name'],
                                  speaker_json['summary'],
                                  speaker_json['say'],
                                  system_language,
                                  gender, box_name)


def speakable_text(http_prefix: str,
                   nickname: str, domain: str, domain_full: str,
                   base_dir: str, content: str, translate: {}) -> (str, []):
    """Convert the given text to a speakable version
    which includes changes for pronunciation
    """
    content = str(content)
    if is_pgp_encrypted(content):
        return content, []

    # replace some emoji before removing html
    if ' <3' in content:
        content = content.replace(' <3', ' ' + translate['heart'])
    content = remove_html(html_replace_quote_marks(content))
    detected_links: list[str] = []
    content = speaker_replace_links(http_prefix,
                                    nickname, domain, domain_full,
                                    content, translate, detected_links)
    # replace all double spaces
    while '  ' in content:
        content = content.replace('  ', ' ')
    content = content.replace(' . ', '. ').strip()
    say_content = _speaker_pronounce(base_dir, content, translate)
    # replace all double spaces
    while '  ' in say_content:
        say_content = say_content.replace('  ', ' ')
    say_str = say_content.replace(' . ', '. ').strip()
    return say_str, detected_links


def _post_to_speaker_json(base_dir: str, http_prefix: str,
                          nickname: str, domain: str, domain_full: str,
                          post_json_object: {}, person_cache: {},
                          translate: {}, announcing_actor: str,
                          theme_name: str) -> {}:
    """Converts an ActivityPub post into some Json containing
    speech synthesis parameters.
    NOTE: There currently appears to be no standardized json
    format for speech synthesis
    """
    if not has_object_dict(post_json_object):
        return {}
    if not post_json_object['object'].get('content'):
        return {}
    if not isinstance(post_json_object['object']['content'], str):
        return {}
    detected_links: list[str] = []
    content = urllib.parse.unquote_plus(post_json_object['object']['content'])
    content = html.unescape(content)
    content = content.replace('<p>', '').replace('</p>', ' ')
    if not is_pgp_encrypted(content):
        # replace some emoji before removing html
        if ' <3' in content:
            content = content.replace(' <3', ' ' + translate['heart'])
        content = remove_html(html_replace_quote_marks(content))
        content = speaker_replace_links(http_prefix,
                                        nickname, domain, domain_full,
                                        content, translate, detected_links)
        # replace all double spaces
        while '  ' in content:
            content = content.replace('  ', ' ')
        content = content.replace(' . ', '. ').strip()
        say_content = content
        say_content = _speaker_pronounce(base_dir, content, translate)
        # replace all double spaces
        while '  ' in say_content:
            say_content = say_content.replace('  ', ' ')
        say_content = say_content.replace(' . ', '. ').strip()
    else:
        say_content = content

    image_description = ''
    post_attachments = get_post_attachments(post_json_object)
    if post_attachments:
        if isinstance(post_attachments, list):
            for img in post_attachments:
                if not isinstance(img, dict):
                    continue
                if not img.get('name'):
                    continue
                if isinstance(img['name'], str):
                    image_description += remove_html(img['name']) + '. '

    is_direct = is_dm(post_json_object)
    actor = local_actor_url(http_prefix, nickname, domain_full)
    reply_to_you = is_reply(post_json_object, actor)

    published = ''
    if post_json_object['object'].get('published'):
        published = post_json_object['object']['published']

    summary = ''
    if post_json_object['object'].get('summary'):
        if isinstance(post_json_object['object']['summary'], str):
            post_json_object_summary = post_json_object['object']['summary']
            summary = \
                urllib.parse.unquote_plus(post_json_object_summary)
            summary = html.unescape(summary)

    actor_url = get_actor_from_post(post_json_object)
    speaker_name = \
        get_display_name(base_dir, actor_url, person_cache)
    if not speaker_name:
        return {}
    speaker_name = _remove_emoji_from_text(speaker_name)
    speaker_name = speaker_name.replace('_', ' ')
    speaker_name = camel_case_split(speaker_name)
    actor_url = get_actor_from_post(post_json_object)
    gender = get_gender_from_bio(base_dir, actor_url,
                                 person_cache, translate)
    if announcing_actor:
        announced_nickname = get_nickname_from_actor(announcing_actor)
        announced_domain, _ = \
            get_domain_from_actor(announcing_actor)
        if announced_nickname and announced_domain:
            announced_handle = announced_nickname + '@' + announced_domain
            say_content = \
                translate['announces'] + ' ' + \
                announced_handle + '. ' + say_content
            content = \
                translate['announces'] + ' ' + \
                announced_handle + '. ' + content
    post_id = None
    if post_json_object['object'].get('id'):
        post_id = remove_id_ending(post_json_object['object']['id'])

    follow_requests_exist = False
    follow_requests_list: list[str] = []
    accounts_dir = acct_dir(base_dir, nickname, domain_full)
    approve_follows_filename = accounts_dir + '/followrequests.txt'
    if os.path.isfile(approve_follows_filename):
        follows: list[str] = []
        try:
            with open(approve_follows_filename, 'r',
                      encoding='utf-8') as fp_foll:
                follows = fp_foll.readlines()
        except OSError:
            print('EX: _post_to_speaker_json unable to read ' +
                  approve_follows_filename)
        if follows:
            if len(follows) > 0:
                follow_requests_exist = True
                for i, _ in enumerate(follows):
                    follows[i] = follows[i].strip()
                follow_requests_list = follows
    post_dm = False
    dm_filename = accounts_dir + '/.newDM'
    if os.path.isfile(dm_filename):
        post_dm = True
    post_reply = False
    reply_filename = accounts_dir + '/.newReply'
    if os.path.isfile(reply_filename):
        post_reply = True
    liked_by = ''
    like_filename = accounts_dir + '/.newLike'
    if os.path.isfile(like_filename):
        try:
            with open(like_filename, 'r', encoding='utf-8') as fp_like:
                liked_by = fp_like.read()
        except OSError:
            print('EX: _post_to_speaker_json unable to read 2 ' +
                  like_filename)
    calendar_filename = accounts_dir + '/.newCalendar'
    post_cal = os.path.isfile(calendar_filename)
    share_filename = accounts_dir + '/.newShare'
    post_share = os.path.isfile(share_filename)

    return _speaker_endpoint_json(speaker_name, summary,
                                  content, say_content, image_description,
                                  detected_links, gender, post_id,
                                  post_dm, post_reply,
                                  follow_requests_exist,
                                  follow_requests_list,
                                  liked_by, published,
                                  post_cal, post_share, theme_name,
                                  is_direct, reply_to_you)


def update_speaker(base_dir: str, http_prefix: str,
                   nickname: str, domain: str, domain_full: str,
                   post_json_object: {}, person_cache: {},
                   translate: {}, announcing_actor: str,
                   theme_name: str,
                   system_language: str, box_name: str) -> None:
    """ Generates a json file which can be used for TTS announcement
    of incoming inbox posts
    """
    speaker_json = \
        _post_to_speaker_json(base_dir, http_prefix,
                              nickname, domain, domain_full,
                              post_json_object, person_cache,
                              translate, announcing_actor,
                              theme_name)
    if not speaker_json:
        return
    account_dir = acct_dir(base_dir, nickname, domain)
    speaker_filename = account_dir + '/speaker.json'
    save_json(speaker_json, speaker_filename)

    # save the ssml
    cached_ssml_filename = \
        get_cached_post_filename(base_dir, nickname,
                                 domain, post_json_object)
    if not cached_ssml_filename:
        return
    cached_ssml_filename = cached_ssml_filename.replace('.html', '.ssml')
    if box_name == 'outbox':
        cached_ssml_filename = \
            cached_ssml_filename.replace('/postcache/', '/outbox/')
    gender = None
    if speaker_json.get('gender'):
        gender = speaker_json['gender']
    ssml_str = \
        _speaker_endpoint_ssml(speaker_json['name'],
                               speaker_json['summary'],
                               speaker_json['say'],
                               system_language,
                               gender, box_name)
    try:
        with open(cached_ssml_filename, 'w+', encoding='utf-8') as fp_ssml:
            fp_ssml.write(ssml_str)
    except OSError:
        print('EX: unable to write ssml ' + cached_ssml_filename)
