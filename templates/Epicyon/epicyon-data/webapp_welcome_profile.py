__filename__ = "webapp_welcome_profile.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Onboarding"

import os
from shutil import copyfile
from utils import replace_strings
from utils import data_dir
from utils import remove_html
from utils import load_json
from utils import get_config_param
from utils import get_image_extensions
from utils import get_image_formats
from utils import acct_dir
from utils import local_actor_url
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import edit_text_field
from markdown import markdown_to_html


def html_welcome_profile(base_dir: str, nickname: str, domain: str,
                         http_prefix: str, domain_full: str,
                         language: str, translate: {},
                         theme_name: str) -> str:
    """Returns the welcome profile screen to set avatar and bio
    """
    # set a custom background for the welcome screen
    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/welcome-background-custom.jpg'):
        if not os.path.isfile(dir_str + '/welcome-background.jpg'):
            copyfile(dir_str + '/welcome-background-custom.jpg',
                     dir_str + '/welcome-background.jpg')

    profile_text = 'Welcome to Epicyon'
    profile_filename = dir_str + '/welcome_profile.md'
    if not os.path.isfile(profile_filename):
        default_filename = None
        if theme_name:
            default_filename = \
                base_dir + '/theme/' + theme_name + '/welcome/' + \
                'profile_' + language + '.md'
            if not os.path.isfile(default_filename):
                default_filename = None
        if not default_filename:
            default_filename = \
                base_dir + '/defaultwelcome/profile_' + language + '.md'
        if not os.path.isfile(default_filename):
            default_filename = base_dir + '/defaultwelcome/profile_en.md'
        copyfile(default_filename, profile_filename)

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    if not instance_title:
        instance_title = 'Epicyon'

    if os.path.isfile(profile_filename):
        try:
            with open(profile_filename, 'r', encoding='utf-8') as fp_pro:
                profile_text = fp_pro.read()
                profile_text = profile_text.replace('INSTANCE', instance_title)
                profile_text = markdown_to_html(remove_html(profile_text))
        except OSError:
            print('EX: html_welcome_profile unable to read ' +
                  profile_filename)

    profile_form = ''
    css_filename = base_dir + '/epicyon-welcome.css'
    if os.path.isfile(base_dir + '/welcome.css'):
        css_filename = base_dir + '/welcome.css'

    preload_images: list[str] = []
    profile_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # get the url of the avatar
    ext = 'png'
    for ext in get_image_extensions():
        avatar_filename = \
            acct_dir(base_dir, nickname, domain) + '/avatar.' + ext
        if os.path.isfile(avatar_filename):
            break
    avatar_url = \
        local_actor_url(http_prefix, nickname, domain_full) + \
        '/avatar.' + ext

    image_formats = get_image_formats()
    profile_form += '<div class="container">' + profile_text + '</div>\n'
    profile_form += \
        '<form enctype="multipart/form-data" method="POST" ' + \
        'accept-charset="UTF-8" ' + \
        'action="/users/' + nickname + '/profiledata">\n'
    profile_form += '<div class="container">\n'
    profile_form += '  <center>\n'
    profile_form += '    <img class="welcomeavatar" src="'
    profile_form += avatar_url + '"><br>\n'
    profile_form += '    <input type="file" id="avatar" name="avatar" '
    profile_form += 'accept="' + image_formats + '">\n'
    profile_form += '  </center>\n'
    profile_form += '</div>\n'

    profile_form += '<center>\n'
    profile_form += \
        '  <button type="submit" class="button" ' + \
        'name="previewAvatar">' + translate['Preview'] + '</button> '
    profile_form += '</center>\n'

    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    actor_json = load_json(actor_filename)
    display_nickname = actor_json['name']
    profile_form += '<div class="container">\n'
    profile_form += \
        edit_text_field(translate['Nickname'], 'displayNickname',
                        display_nickname)

    bio_str = ''
    if actor_json.get('summary'):
        replacements = {
            '<p>': '',
            '</p>': ''
        }
        bio_str = replace_strings(actor_json['summary'], replacements)
    if not bio_str:
        bio_str = translate['Your bio']
    profile_form += '  <label class="labels">' + \
        translate['Your bio'] + '</label><br>\n'
    profile_form += '  <textarea id="message" name="bio" ' + \
        'style="height:130px" spellcheck="true">' + \
        bio_str + '</textarea>\n'
    profile_form += '</div>\n'

    profile_form += '<div class="container next">\n'
    profile_form += \
        '    <button type="submit" class="button" ' + \
        'name="initialWelcomeScreen">' + translate['Go Back'] + '</button> '
    profile_form += \
        '    <button type="submit" class="button" ' + \
        'name="finalWelcomeScreen">' + translate['Next'] + '</button>\n'
    profile_form += '</div>\n'

    profile_form += '</form>\n'
    profile_form += html_footer()
    return profile_form
