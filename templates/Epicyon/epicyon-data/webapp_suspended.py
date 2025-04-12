__filename__ = "webapp_suspended.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from utils import get_config_param
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer


def html_suspended(base_dir: str) -> str:
    """Show the screen for suspended accounts
    """
    suspended_form = ''
    css_filename = base_dir + '/epicyon-suspended.css'
    if os.path.isfile(base_dir + '/suspended.css'):
        css_filename = base_dir + '/suspended.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    suspended_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    suspended_form += \
        '<div><center>\n' + \
        '  <p class="screentitle">Account Suspended</p>\n' + \
        '  <p>See <a href="/terms">Terms of Service</a></p>\n' + \
        '</center></div>\n'
    suspended_form += html_footer()
    return suspended_form
