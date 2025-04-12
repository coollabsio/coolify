__filename__ = "webapp_accesskeys.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Accessibility"

import os
from utils import data_dir
from utils import is_account_dir
from utils import load_json
from utils import get_config_param
from utils import acct_dir
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_banner_file


def load_access_keys_for_accounts(base_dir: str, key_shortcuts: {},
                                  access_keys_template: {}) -> None:
    """Loads key shortcuts for each account
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for acct in dirs:
            if not is_account_dir(acct):
                continue
            account_dir = os.path.join(dir_str, acct)
            access_keys_filename = account_dir + '/access_keys.json'
            if not os.path.isfile(access_keys_filename):
                continue
            nickname = acct.split('@')[0]
            access_keys = load_json(access_keys_filename)
            if access_keys:
                key_shortcuts[nickname] = access_keys_template.copy()
                for variable_name, _ in access_keys_template.items():
                    if access_keys.get(variable_name):
                        key_shortcuts[nickname][variable_name] = \
                            access_keys[variable_name]
        break


def html_access_keys(base_dir: str,
                     nickname: str, domain: str,
                     translate: {}, access_keys: {},
                     default_access_keys: {},
                     default_timeline: str, theme: str) -> str:
    """Show and edit key shortcuts
    """
    access_keys_filename = \
        acct_dir(base_dir, nickname, domain) + '/access_keys.json'
    if os.path.isfile(access_keys_filename):
        access_keys_from_file = load_json(access_keys_filename)
        if access_keys_from_file:
            access_keys = access_keys_from_file

    timeline_key = access_keys['menuTimeline']
    submit_key = access_keys['submitButton']

    access_keys_form = ''
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images = []
    access_keys_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    access_keys_form += \
        '<header>\n' + \
        '<a href="/users/' + nickname + '/' + \
        default_timeline + '" title="' + \
        translate['Switch to timeline view'] + '" alt="' + \
        translate['Switch to timeline view'] + '">\n'
    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme)
    access_keys_form += '<img loading="lazy" decoding="async" ' + \
        'class="timeline-banner" alt="" ' + \
        'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
        '</header>\n'

    access_keys_form += '<div class="container">\n'

    access_keys_form += \
        '    <h1>' + translate['Key Shortcuts'] + '</h1>\n'
    access_keys_form += \
        '<p>' + translate['These access keys may be used'] + \
        '<label class="labels"></label></p>'

    access_keys_form += '  <form method="POST" action="' + \
        '/users/' + nickname + '/changeAccessKeys">\n'

    access_keys_form += \
        '    <center>\n' + \
        '    <button type="submit" class="button" ' + \
        'name="submitAccessKeysCancel" accesskey="' + timeline_key + '">' + \
        translate['Go Back'] + '</button>\n' + \
        '    <button type="submit" class="button" ' + \
        'name="submitAccessKeys" accesskey="' + submit_key + '">' + \
        translate['Publish'] + '</button>\n    </center>\n'

    access_keys_form += '    <table class="accesskeys">\n'
    access_keys_form += '      <colgroup>\n'
    access_keys_form += '        <col span="1" class="accesskeys-left">\n'
    access_keys_form += '        <col span="1" class="accesskeys-center">\n'
    access_keys_form += '      </colgroup>\n'
    access_keys_form += '      <tbody>\n'

    for variable_name, key in default_access_keys.items():
        if not translate.get(variable_name):
            continue
        key_str = '<tr>'
        key_str += \
            '<td><label class="labels">' + \
            translate[variable_name] + '</label></td>'
        if access_keys.get(variable_name):
            key = access_keys[variable_name]
        if len(key) > 1:
            key = key[0]
        key_str += \
            '<td><input type="text" ' + \
            'name="' + variable_name.replace(' ', '_') + '" ' + \
            'value="' + key + '">'
        key_str += '</td></tr>\n'
        access_keys_form += key_str

    access_keys_form += '      </tbody>\n'
    access_keys_form += '    </table>\n'
    access_keys_form += '  </form>\n'
    access_keys_form += '</div>\n'
    access_keys_form += html_footer()
    return access_keys_form
