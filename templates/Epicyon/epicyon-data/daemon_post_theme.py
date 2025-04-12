__filename__ = "daemon_post_theme.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import errno
import urllib.parse
from socket import error as SocketError
from theme import reset_theme_designer_settings
from theme import set_theme
from theme import set_theme_from_designer
from httpheaders import redirect_headers
from utils import load_json


def theme_designer_edit(self, calling_domain: str, cookie: str,
                        base_dir: str, http_prefix: str, nickname: str,
                        domain: str, domain_full: str,
                        onion_domain: str, i2p_domain: str,
                        default_timeline: str, theme_name: str,
                        allow_local_network_access: bool,
                        system_language: str,
                        dyslexic_font: bool) -> None:
    """Receive POST from webapp_theme_designer
    """
    users_path = '/users/' + nickname
    origin_path_str = \
        http_prefix + '://' + domain_full + users_path + '/' + \
        default_timeline
    length = int(self.headers['Content-length'])

    try:
        theme_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST theme_params ' +
                  'connection reset by peer')
        else:
            print('EX: POST theme_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST theme_params rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    theme_params = \
        urllib.parse.unquote_plus(theme_params)

    # theme designer screen, reset button
    # See html_theme_designer
    if 'submitThemeDesignerReset=' in theme_params or \
       'submitThemeDesigner=' not in theme_params:
        if 'submitThemeDesignerReset=' in theme_params:
            reset_theme_designer_settings(base_dir)
            self.server.css_cache = {}
            set_theme(base_dir, theme_name, domain,
                      allow_local_network_access, system_language,
                      dyslexic_font, True)

        if calling_domain.endswith('.onion') and onion_domain:
            origin_path_str = \
                'http://' + onion_domain + users_path + '/' + \
                default_timeline
        elif calling_domain.endswith('.i2p') and i2p_domain:
            origin_path_str = \
                'http://' + i2p_domain + users_path + \
                '/' + default_timeline
        redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    fields = {}
    fields_list = theme_params.split('&')
    for field_str in fields_list:
        if '=' not in field_str:
            continue
        field_value = field_str.split('=')[1].strip()
        if not field_value:
            continue
        if field_value == 'on':
            field_value = 'True'
        fields_index = field_str.split('=')[0]
        fields[fields_index] = field_value

    # Check for boolean values which are False.
    # These don't come through via theme_params,
    # so need to be checked separately
    theme_filename = base_dir + '/theme/' + theme_name + '/theme.json'
    theme_json = load_json(theme_filename)
    if theme_json:
        for variable_name, value in theme_json.items():
            variable_name = 'themeSetting_' + variable_name
            if value.lower() == 'false' or value.lower() == 'true':
                if variable_name not in fields:
                    fields[variable_name] = 'False'

    # get the parameters from the theme designer screen
    theme_designer_params = {}
    for variable_name, key in fields.items():
        if variable_name.startswith('themeSetting_'):
            variable_name = variable_name.replace('themeSetting_', '')
            theme_designer_params[variable_name] = key

    self.server.css_cache = {}
    set_theme_from_designer(base_dir, theme_name, domain,
                            theme_designer_params,
                            allow_local_network_access,
                            system_language, dyslexic_font)

    # set boolean values
    if 'rss-icon-at-top' in theme_designer_params:
        if theme_designer_params['rss-icon-at-top'].lower() == 'true':
            self.server.rss_icon_at_top = True
        else:
            self.server.rss_icon_at_top = False
    if 'publish-button-at-top' in theme_designer_params:
        publish_button_at_top_str = \
            theme_designer_params['publish-button-at-top'].lower()
        if publish_button_at_top_str == 'true':
            self.server.publish_button_at_top = True
        else:
            self.server.publish_button_at_top = False
    if 'newswire-publish-icon' in theme_designer_params:
        newswire_publish_icon_str = \
            theme_designer_params['newswire-publish-icon'].lower()
        if newswire_publish_icon_str == 'true':
            self.server.show_publish_as_icon = True
        else:
            self.server.show_publish_as_icon = False
    if 'icons-as-buttons' in theme_designer_params:
        if theme_designer_params['icons-as-buttons'].lower() == 'true':
            self.server.icons_as_buttons = True
        else:
            self.server.icons_as_buttons = False
    if 'full-width-timeline-buttons' in theme_designer_params:
        theme_value = theme_designer_params['full-width-timeline-buttons']
        if theme_value.lower() == 'true':
            self.server.full_width_tl_button_header = True
        else:
            self.server.full_width_tl_button_header = False

    # redirect back from theme designer screen
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = \
            'http://' + onion_domain + users_path + '/' + default_timeline
    elif calling_domain.endswith('.i2p') and i2p_domain:
        origin_path_str = \
            'http://' + i2p_domain + users_path + '/' + default_timeline
    redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
    self.server.postreq_busy = False
    return
