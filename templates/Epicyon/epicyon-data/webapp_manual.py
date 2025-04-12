__filename__ = "webapp_about.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from shutil import copyfile
from utils import data_dir
from utils import get_config_param
from webapp_utils import html_header_with_website_markup
from webapp_utils import html_footer
from markdown import markdown_example_numbers
from markdown import markdown_to_html


def html_manual(base_dir: str, http_prefix: str,
                domain_full: str, onion_domain: str, translate: {},
                system_language: str) -> str:
    """Show the user manual screen
    """
    manual_filename = base_dir + '/manual/manual.md'
    admin_nickname = get_config_param(base_dir, 'admin')
    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/manual.md'):
        manual_filename = dir_str + '/manual.md'

    if os.path.isfile(dir_str + '/login-background-custom.jpg'):
        if not os.path.isfile(dir_str + '/login-background.jpg'):
            copyfile(dir_str + '/login-background-custom.jpg',
                     dir_str + '/login-background.jpg')

    manual_text = 'User Manual.'
    if os.path.isfile(manual_filename):
        try:
            with open(manual_filename, 'r',
                      encoding='utf-8') as fp_manual:
                md_text = markdown_example_numbers(fp_manual.read())
                manual_text = markdown_to_html(md_text)
        except OSError:
            print('EX: html_manual unable to read ' + manual_filename)

    manual_form = ''
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    manual_form = \
        html_header_with_website_markup(css_filename, instance_title,
                                        http_prefix, domain_full,
                                        system_language)
    manual_form += \
        '<div class="container">' + manual_text + '</div>'
    if onion_domain:
        manual_form += \
            '<div class="container"><center>\n' + \
            '<p class="administeredby">' + \
            'http://' + onion_domain + '</p>\n</center></div>\n'
    if admin_nickname:
        admin_actor = '/users/' + admin_nickname
        manual_form += \
            '<div class="container"><center>\n' + \
            '<p class="administeredby">' + \
            translate['Administered by'] + ' <a href="' + \
            admin_actor + '">' + admin_nickname + '</a>. ' + \
            translate['Version'] + ' ' + __version__ + \
            '</p>\n</center></div>\n'
    manual_form += html_footer()
    return manual_form
