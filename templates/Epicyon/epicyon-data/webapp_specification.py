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


def html_specification(base_dir: str, http_prefix: str,
                       domain_full: str, onion_domain: str, translate: {},
                       system_language: str) -> str:
    """Show the specification screen
    """
    specification_filename = base_dir + '/specification/activitypub.md'
    admin_nickname = get_config_param(base_dir, 'admin')
    dir_str = data_dir(base_dir)
    if os.path.isfile(dir_str + '/activitypub.md'):
        specification_filename = dir_str + '/activitypub.md'

    if os.path.isfile(dir_str + '/login-background-custom.jpg'):
        if not os.path.isfile(dir_str + '/login-background.jpg'):
            copyfile(dir_str + '/login-background-custom.jpg',
                     dir_str + '/login-background.jpg')

    specification_text = 'ActivityPub Protocol Specification.'
    if os.path.isfile(specification_filename):
        try:
            with open(specification_filename, 'r',
                      encoding='utf-8') as fp_specification:
                md_text = markdown_example_numbers(fp_specification.read())
                specification_text = markdown_to_html(md_text)
        except OSError:
            print('EX: html_specification unable to read ' +
                  specification_filename)

    specification_form = ''
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    specification_form = \
        html_header_with_website_markup(css_filename, instance_title,
                                        http_prefix, domain_full,
                                        system_language)
    specification_form += \
        '<div class="container">' + specification_text + '</div>'
    if onion_domain:
        specification_form += \
            '<div class="container"><center>\n' + \
            '<p class="administeredby">' + \
            'http://' + onion_domain + '</p>\n</center></div>\n'
    if admin_nickname:
        admin_actor = '/users/' + admin_nickname
        specification_form += \
            '<div class="container"><center>\n' + \
            '<p class="administeredby">' + \
            translate['Administered by'] + ' <a href="' + \
            admin_actor + '">' + admin_nickname + '</a>. ' + \
            translate['Version'] + ' ' + __version__ + \
            '</p>\n</center></div>\n'
    specification_form += html_footer()
    return specification_form
