__filename__ = "theme.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from shutil import copyfile
from shutil import make_archive
from shutil import unpack_archive
from shutil import rmtree
from utils import data_dir
from utils import string_ends_with
from utils import is_account_dir
from utils import load_json
from utils import save_json
from utils import get_image_extensions
from utils import copytree
from utils import acct_dir
from utils import dangerous_svg
from utils import local_actor_url
from utils import remove_html
from utils import text_in_file
from utils import remove_eol
from utils import language_right_to_left
from content import dangerous_css


def import_theme(base_dir: str, filename: str) -> bool:
    """Imports a theme
    """
    if not os.path.isfile(filename):
        return False
    temp_theme_dir = base_dir + '/imports/files'
    if os.path.isdir(temp_theme_dir):
        rmtree(temp_theme_dir, ignore_errors=False, onexc=None)
    os.mkdir(temp_theme_dir)
    unpack_archive(filename, temp_theme_dir, 'zip')
    essential_theme_files = ('name.txt', 'theme.json')
    for theme_file in essential_theme_files:
        if not os.path.isfile(temp_theme_dir + '/' + theme_file):
            print('WARN: ' + theme_file +
                  ' missing from imported theme')
            return False
    new_theme_name = None
    new_theme_name1 = None
    try:
        with open(temp_theme_dir + '/name.txt', 'r',
                  encoding='utf-8') as fp_theme:
            new_theme_name1 = fp_theme.read()
    except OSError:
        print('EX: import_theme unable to read ' +
              temp_theme_dir + '/name.txt')

    if new_theme_name1:
        new_theme_name = remove_eol(new_theme_name1)
        if len(new_theme_name) > 20:
            print('WARN: Imported theme name is too long')
            return False
        if len(new_theme_name) < 2:
            print('WARN: Imported theme name is too short')
            return False
        new_theme_name = new_theme_name.lower()
        forbidden_chars = (
            ' ', ';', '/', '\\', '?', '!', '#', '@',
            ':', '%', '&', '"', '+', '<', '>', '$'
        )
        for char in forbidden_chars:
            if char in new_theme_name:
                print('WARN: theme name contains forbidden character')
                return False

    if not new_theme_name:
        return False

    # if the theme name in the default themes list?
    default_themes_filename = base_dir + '/defaultthemes.txt'
    if os.path.isfile(default_themes_filename):
        test_str = new_theme_name.title() + '\n'
        if text_in_file(test_str, default_themes_filename):
            new_theme_name = new_theme_name + '2'

    theme_dir = base_dir + '/theme/' + new_theme_name
    if not os.path.isdir(theme_dir):
        os.mkdir(theme_dir)
    copytree(temp_theme_dir, theme_dir, False, None)
    if os.path.isdir(temp_theme_dir):
        rmtree(temp_theme_dir, ignore_errors=False, onexc=None)
    if scan_themes_for_scripts(theme_dir):
        rmtree(theme_dir, ignore_errors=False, onexc=None)
        return False
    return os.path.isfile(theme_dir + '/theme.json')


def export_theme(base_dir: str, theme: str) -> bool:
    """Exports a theme as a zip file
    """
    theme_dir = base_dir + '/theme/' + theme
    if not os.path.isfile(theme_dir + '/theme.json'):
        return False
    if not os.path.isdir(base_dir + '/exports'):
        os.mkdir(base_dir + '/exports')
    export_filename = base_dir + '/exports/' + theme + '.zip'
    if os.path.isfile(export_filename):
        try:
            os.remove(export_filename)
        except OSError:
            print('EX: export_theme unable to delete ' + str(export_filename))
    try:
        make_archive(base_dir + '/exports/' + theme, 'zip', theme_dir)
    except BaseException:
        print('EX: export_theme unable to archive ' +
              base_dir + '/exports/' + str(theme))
    return os.path.isfile(export_filename)


def _get_theme_files() -> []:
    """Gets the list of theme style sheets
    """
    return ('epicyon.css', 'login.css', 'follow.css',
            'suspended.css', 'calendar.css', 'blog.css',
            'options.css', 'search.css', 'links.css',
            'welcome.css', 'graph.css', 'podcast.css')


def is_news_theme_name(base_dir: str, theme_name: str) -> bool:
    """Returns true if the given theme is a news instance
    """
    theme_dir = base_dir + '/theme/' + theme_name
    if os.path.isfile(theme_dir + '/is_news_instance'):
        return True
    return False


def get_themes_list(base_dir: str) -> []:
    """Returns the list of available themes
    Note that these should be capitalized, since they're
    also used to create the web interface dropdown list
    and to lookup function names
    """
    themes: list[str] = []
    for _, dirs, _ in os.walk(base_dir + '/theme'):
        for theme_name in dirs:
            if '~' not in theme_name and \
               theme_name != 'icons' and theme_name != 'fonts':
                themes.append(theme_name.title())
        break
    themes.sort()
    print('Themes available: ' + str(themes))
    return themes


def _copy_theme_help_files(base_dir: str, theme_name: str,
                           system_language: str) -> None:
    """Copies any theme specific help files from the welcome subdirectory
    """
    if not system_language:
        system_language = 'en'
    theme_dir = base_dir + '/theme/' + theme_name + '/welcome'
    if not os.path.isdir(theme_dir):
        theme_dir = base_dir + '/defaultwelcome'
    dir_str = data_dir(base_dir)
    for _, _, files in os.walk(theme_dir):
        for help_markdown_file in files:
            if not help_markdown_file.endswith('_' + system_language + '.md'):
                continue
            dest_help_markdown_file = \
                help_markdown_file.replace('_' + system_language + '.md',
                                           '.md')
            if dest_help_markdown_file in ('profile.md', 'final.md'):
                dest_help_markdown_file = 'welcome_' + dest_help_markdown_file
            if os.path.isdir(dir_str):
                copyfile(theme_dir + '/' + help_markdown_file,
                         dir_str + '/' + dest_help_markdown_file)
        break


def _set_theme_in_config(base_dir: str, name: str) -> bool:
    """Sets the theme with the given name within config.json
    """
    config_filename = base_dir + '/config.json'
    if not os.path.isfile(config_filename):
        return False
    config_json = load_json(config_filename)
    if not config_json:
        return False
    config_json['theme'] = name
    return save_json(config_json, config_filename)


def _set_newswire_publish_as_icon(base_dir: str, use_icon: bool) -> bool:
    """Shows the newswire publish action as an icon or a button
    """
    config_filename = base_dir + '/config.json'
    if not os.path.isfile(config_filename):
        return False
    config_json = load_json(config_filename)
    if not config_json:
        return False
    config_json['showPublishAsIcon'] = use_icon
    return save_json(config_json, config_filename)


def _set_icons_as_buttons(base_dir: str, use_buttons: bool) -> bool:
    """Whether to show icons in the header (inbox, outbox, etc)
    as buttons
    """
    config_filename = base_dir + '/config.json'
    if not os.path.isfile(config_filename):
        return False
    config_json = load_json(config_filename)
    if not config_json:
        return False
    config_json['iconsAsButtons'] = use_buttons
    return save_json(config_json, config_filename)


def _set_rss_icon_at_top(base_dir: str, at_top: bool) -> bool:
    """Whether to show RSS icon at the top of the timeline
    """
    config_filename = base_dir + '/config.json'
    if not os.path.isfile(config_filename):
        return False
    config_json = load_json(config_filename)
    if not config_json:
        return False
    config_json['rssIconAtTop'] = at_top
    return save_json(config_json, config_filename)


def _set_publish_button_at_top(base_dir: str, at_top: bool) -> bool:
    """Whether to show the publish button above the title image
    in the newswire column
    """
    config_filename = base_dir + '/config.json'
    if not os.path.isfile(config_filename):
        return False
    config_json = load_json(config_filename)
    if not config_json:
        return False
    config_json['publishButtonAtTop'] = at_top
    return save_json(config_json, config_filename)


def _set_full_width_timeline_button_header(base_dir: str,
                                           full_width: bool) -> bool:
    """Shows the timeline button header containing inbox, outbox,
    calendar, etc as full width
    """
    config_filename = base_dir + '/config.json'
    if not os.path.isfile(config_filename):
        return False
    config_json = load_json(config_filename)
    if not config_json:
        return False
    config_json['fullWidthTlButtonHeader'] = full_width
    return save_json(config_json, config_filename)


def get_theme(base_dir: str) -> str:
    """Gets the current theme name from config.json
    """
    config_filename = base_dir + '/config.json'
    if os.path.isfile(config_filename):
        config_json = load_json(config_filename)
        if config_json:
            if config_json.get('theme'):
                return config_json['theme']
    return 'default'


def _remove_theme(base_dir: str):
    """Removes the current theme style sheets
    """
    theme_files = _get_theme_files()
    for filename in theme_files:
        if not os.path.isfile(base_dir + '/' + filename):
            continue
        try:
            os.remove(base_dir + '/' + filename)
        except OSError:
            print('EX: _remove_theme unable to delete ' +
                  base_dir + '/' + filename)


def set_css_param(css: str, param: str, value: str) -> str:
    """Sets a CSS parameter to a given value
    """
    value = remove_html(value)
    # is this just a simple string replacement?
    if ';' in param:
        return css.replace(param, value)
    # color replacement
    if param.startswith('rgba('):
        return css.replace(param, value)
    # if the parameter begins with * then don't prepend --
    once_only = False
    if param.startswith('*'):
        if param.startswith('**'):
            once_only = True
            search_str = param.replace('**', '') + ':'
        else:
            search_str = param.replace('*', '') + ':'
    else:
        search_str = '--' + param + ':'
    if search_str not in css:
        return css
    if once_only:
        sstr = css.split(search_str, 1)
    else:
        sstr = css.split(search_str)
    newcss = ''
    for section_str in sstr:
        # handle font-family which is a variable
        next_section = section_str
        if ';' in next_section:
            next_section = next_section.split(';')[0] + ';'
        if search_str == 'font-family:' and "var(--" in next_section:
            newcss += search_str + ' ' + section_str
            continue

        if not newcss:
            if section_str:
                newcss = section_str
            else:
                newcss = ' '
        else:
            if ';' in section_str:
                newcss += \
                    search_str + ' ' + value + ';' + \
                    section_str.split(';', 1)[1]
            else:
                newcss += search_str + ' ' + section_str
    return newcss.strip()


def _set_theme_from_dict(base_dir: str, name: str,
                         theme_params: {}, bg_params: {},
                         allow_local_network_access: bool,
                         system_language: str) -> None:
    """Uses a dictionary to set a theme
    """
    if name:
        _set_theme_in_config(base_dir, name)
    rtl = language_right_to_left(system_language)
    theme_files = _get_theme_files()
    for filename in theme_files:
        # check for custom css within the theme directory
        template_filename = \
            base_dir + '/theme/' + name + '/epicyon-' + filename
        if filename == 'epicyon.css':
            template_filename = \
                base_dir + '/theme/' + name + '/epicyon-profile.css'

        # Ensure that any custom CSS is mostly harmless.
        # If not then just use the defaults
        if dangerous_css(template_filename, allow_local_network_access) or \
           not os.path.isfile(template_filename):
            # use default css
            template_filename = base_dir + '/epicyon-' + filename
            if filename == 'epicyon.css':
                template_filename = base_dir + '/epicyon-profile.css'

        if not os.path.isfile(template_filename):
            continue

        with open(template_filename, 'r', encoding='utf-8') as fp_css:
            css = fp_css.read()
            for param_name, param_value in theme_params.items():
                if param_name == 'newswire-publish-icon':
                    if param_value.lower() == 'true':
                        _set_newswire_publish_as_icon(base_dir, True)
                    else:
                        _set_newswire_publish_as_icon(base_dir, False)
                    continue
                if param_name == 'full-width-timeline-buttons':
                    if param_value.lower() == 'true':
                        _set_full_width_timeline_button_header(base_dir, True)
                    else:
                        _set_full_width_timeline_button_header(base_dir, False)
                    continue
                if param_name == 'icons-as-buttons':
                    if param_value.lower() == 'true':
                        _set_icons_as_buttons(base_dir, True)
                    else:
                        _set_icons_as_buttons(base_dir, False)
                    continue
                if param_name == 'rss-icon-at-top':
                    if param_value.lower() == 'true':
                        _set_rss_icon_at_top(base_dir, True)
                    else:
                        _set_rss_icon_at_top(base_dir, False)
                    continue
                if param_name == 'publish-button-at-top':
                    if param_value.lower() == 'true':
                        _set_publish_button_at_top(base_dir, True)
                    else:
                        _set_publish_button_at_top(base_dir, False)
                    continue
                css = set_css_param(css, param_name, param_value)

            # set the text direction
            if rtl:
                css = set_css_param(css, 'text-justify', 'right')
                css = set_css_param(css, 'language-direction', 'rtl')

            filename = base_dir + '/' + filename
            try:
                with open(filename, 'w+', encoding='utf-8') as fp_css:
                    fp_css.write(css)
            except OSError:
                print('EX: _set_theme_from_dict unable to write ' + filename)

    screen_name = (
        'login', 'follow', 'options', 'search', 'welcome'
    )
    for scr in screen_name:
        if bg_params.get(scr):
            _set_background_format(base_dir, scr, bg_params[scr])


def _set_background_format(base_dir: str,
                           background_type: str, extension: str) -> None:
    """Sets the background file extension
    """
    if extension == 'jpg':
        return
    css_filename = base_dir + '/' + background_type + '.css'
    if not os.path.isfile(css_filename):
        return

    css = None
    try:
        with open(css_filename, 'r', encoding='utf-8') as fp_css:
            css = fp_css.read()
    except OSError as exc:
        print('EX: _set_background_format 1 ' + css_filename + ' ' + str(exc))

    if css:
        css = css.replace('background.jpg', 'background.' + extension)
        try:
            with open(css_filename, 'w+', encoding='utf-8') as fp_css2:
                fp_css2.write(css)
        except OSError as exc:
            print('EX: _set_background_format 2 ' +
                  css_filename + ' ' + str(exc))


def enable_grayscale(base_dir: str) -> None:
    """Enables grayscale for the current theme
    """
    theme_files = _get_theme_files()
    for filename in theme_files:
        template_filename = base_dir + '/' + filename
        if not os.path.isfile(template_filename):
            continue
        try:
            with open(template_filename, 'r', encoding='utf-8') as fp_css:
                css = fp_css.read()
                if 'grayscale' not in css:
                    css = \
                        css.replace('body, html {',
                                    'body, html {\n' +
                                    '    filter: grayscale(100%);')
                    filename = base_dir + '/' + filename
                    with open(filename, 'w+', encoding='utf-8') as fp_css:
                        fp_css.write(css)
        except OSError as ex:
            print('EX: enable_grayscale unable to read ' +
                  template_filename + ' ' + str(ex))
    grayscale_filename = data_dir(base_dir) + '/.grayscale'
    if not os.path.isfile(grayscale_filename):
        try:
            with open(grayscale_filename, 'w+', encoding='utf-8') as fp_gray:
                fp_gray.write(' ')
        except OSError as ex:
            print('EX: enable_grayscale unable to write ' +
                  grayscale_filename + ' ' + str(ex))


def disable_grayscale(base_dir: str) -> None:
    """Disables grayscale for the current theme
    """
    theme_files = _get_theme_files()
    for filename in theme_files:
        template_filename = base_dir + '/' + filename
        if not os.path.isfile(template_filename):
            continue
        try:
            with open(template_filename, 'r', encoding='utf-8') as fp_css:
                css = fp_css.read()
                if 'grayscale' in css:
                    css = \
                        css.replace('\n    filter: grayscale(100%);', '')
                    filename = base_dir + '/' + filename
                    with open(filename, 'w+', encoding='utf-8') as fp_css:
                        fp_css.write(css)
        except OSError as ex:
            print('EX: disable_grayscale unable to read ' +
                  template_filename + ' ' + str(ex))
    grayscale_filename = data_dir(base_dir) + '/.grayscale'
    if os.path.isfile(grayscale_filename):
        try:
            os.remove(grayscale_filename)
        except OSError:
            print('EX: disable_grayscale unable to delete ' +
                  grayscale_filename)


def _set_dyslexic_font(base_dir: str) -> bool:
    """sets the dyslexic font if needed
    """
    theme_files = _get_theme_files()
    for filename in theme_files:
        template_filename = base_dir + '/' + filename
        if not os.path.isfile(template_filename):
            continue

        css = None
        try:
            with open(template_filename, 'r', encoding='utf-8') as fp_css:
                css = fp_css.read()
        except OSError:
            print('EX: _set_dyslexic_font unable to read ' + template_filename)

        if css:
            css = \
                set_css_param(css, "*src",
                              "url('./fonts/OpenDyslexic-Regular.woff2" +
                              "') format('woff2')")
            css = set_css_param(css, "*font-family", "'OpenDyslexic'")
            filename = base_dir + '/' + filename
            try:
                with open(filename, 'w+', encoding='utf-8') as fp_css:
                    fp_css.write(css)
            except OSError:
                print('EX: _set_dyslexic_font unable to write ' + filename)
    return False


def _set_custom_font(base_dir: str):
    """Uses a dictionary to set a theme
    """
    custom_font_ext = None
    custom_font_type = None
    font_extension = {
        'woff': 'woff',
        'woff2': 'woff2',
        'otf': 'opentype',
        'ttf': 'truetype'
    }
    for ext, ext_type in font_extension.items():
        filename = base_dir + '/fonts/custom.' + ext
        if os.path.isfile(filename):
            custom_font_ext = ext
            custom_font_type = ext_type
    if not custom_font_ext:
        return

    theme_files = _get_theme_files()
    for filename in theme_files:
        template_filename = base_dir + '/' + filename
        if not os.path.isfile(template_filename):
            continue

        css = None
        try:
            with open(template_filename, 'r', encoding='utf-8') as fp_css:
                css = fp_css.read()
        except OSError:
            print('EX: _set_custom_font unable to read ' + template_filename)

        if css:
            css = \
                set_css_param(css, "*src",
                              "url('./fonts/custom." +
                              custom_font_ext + "') format('" +
                              custom_font_type + "')")
            css = set_css_param(css, "*font-family", "'CustomFont'")
            css = set_css_param(css, "header-font", "'CustomFont'")
            filename = base_dir + '/' + filename
            try:
                with open(filename, 'w+', encoding='utf-8') as fp_css:
                    fp_css.write(css)
            except OSError:
                print('EX: _set_custom_font unable to write ' + filename)


def set_theme_from_designer(base_dir: str, theme_name: str, domain: str,
                            theme_params: {},
                            allow_local_network_access: bool,
                            system_language: str,
                            dyslexic_font: bool):
    custom_theme_filename = data_dir(base_dir) + '/theme.json'
    save_json(theme_params, custom_theme_filename)
    set_theme(base_dir, theme_name, domain,
              allow_local_network_access, system_language,
              dyslexic_font, False)


def reset_theme_designer_settings(base_dir: str) -> None:
    """Resets the theme designer settings
    """
    custom_variables_file = data_dir(base_dir) + '/theme.json'
    if os.path.isfile(custom_variables_file):
        try:
            os.remove(custom_variables_file)
            print('Theme designer settings were reset')
        except OSError:
            print('EX: unable to remove theme designer settings on reset')


def _read_variables_file(base_dir: str, theme_name: str,
                         variables_file: str,
                         allow_local_network_access: bool,
                         system_language: str) -> None:
    """Reads variables from a file in the theme directory
    """
    theme_params = load_json(variables_file)
    if not theme_params:
        return

    # set custom theme parameters
    custom_variables_file = data_dir(base_dir) + '/theme.json'
    if os.path.isfile(custom_variables_file):
        custom_theme_params = load_json(custom_variables_file)
        if custom_theme_params:
            for variable_name, value in custom_theme_params.items():
                theme_params[variable_name] = value

    bg_params = {
        "login": "jpg",
        "follow": "jpg",
        "options": "jpg",
        "search": "jpg"
    }
    _set_theme_from_dict(base_dir, theme_name, theme_params, bg_params,
                         allow_local_network_access, system_language)


def _set_theme_default(base_dir: str, allow_local_network_access: bool,
                       system_language: str):
    name = 'default'
    _remove_theme(base_dir)
    _set_theme_in_config(base_dir, name)

    variables_file = base_dir + '/theme/' + name + '/theme.json'
    if os.path.isfile(variables_file):
        _read_variables_file(base_dir, name, variables_file,
                             allow_local_network_access,
                             system_language)
    else:
        bg_params = {
            "login": "jpg",
            "follow": "jpg",
            "options": "jpg",
            "search": "jpg"
        }
        theme_params = {
            "newswire-publish-icon": True,
            "full-width-timeline-buttons": False,
            "icons-as-buttons": False,
            "rss-icon-at-top": True,
            "publish-button-at-top": False,
            "banner-height": "20vh",
            "banner-height-mobile": "10vh",
            "search-banner-height-mobile": "15vh"
        }
        _set_theme_from_dict(base_dir, name, theme_params, bg_params,
                             allow_local_network_access,
                             system_language)


def _set_theme_fonts(base_dir: str, theme_name: str) -> None:
    """Adds custom theme fonts
    """
    theme_name_lower = theme_name.lower()
    fonts_dir = base_dir + '/fonts'
    theme_fonts_dir = \
        base_dir + '/theme/' + theme_name_lower + '/fonts'
    if not os.path.isdir(theme_fonts_dir):
        return
    for _, _, files in os.walk(theme_fonts_dir):
        for filename in files:
            if string_ends_with(filename, ('.woff2', '.woff', '.ttf', '.otf')):
                dest_filename = fonts_dir + '/' + filename
                if os.path.isfile(dest_filename):
                    # font already exists in the destination location
                    continue
                copyfile(theme_fonts_dir + '/' + filename,
                         dest_filename)
        break


def get_text_mode_banner(base_dir: str) -> str:
    """Returns the banner used for shell browsers, like Lynx
    """
    text_mode_banner_filename = data_dir(base_dir) + '/banner.txt'
    if os.path.isfile(text_mode_banner_filename):
        with open(text_mode_banner_filename, 'r',
                  encoding='utf-8') as fp_text:
            banner_str = fp_text.read()
            if banner_str:
                return banner_str.replace('\n', '<br>')
    return None


def get_text_mode_logo(base_dir: str) -> str:
    """Returns the login screen logo used for shell browsers, like Lynx
    """
    text_mode_logo_filename = data_dir(base_dir) + '/logo.txt'
    if not os.path.isfile(text_mode_logo_filename):
        text_mode_logo_filename = base_dir + '/img/logo.txt'

    with open(text_mode_logo_filename, 'r', encoding='utf-8') as fp_text:
        logo_str = fp_text.read()
        if logo_str:
            return logo_str.replace('\n', '<br>')
    return None


def _set_text_mode_theme(base_dir: str, name: str) -> None:
    # set the text mode logo which appears on the login screen
    # in browsers such as Lynx
    text_mode_logo_filename = \
        base_dir + '/theme/' + name + '/logo.txt'
    dir_str = data_dir(base_dir)
    if os.path.isfile(text_mode_logo_filename):
        try:
            copyfile(text_mode_logo_filename, dir_str + '/logo.txt')
        except OSError:
            print('EX: _set_text_mode_theme unable to copy ' +
                  text_mode_logo_filename + ' ' +
                  dir_str + '/logo.txt')
    else:
        dir_str = data_dir(base_dir)
        try:
            copyfile(base_dir + '/img/logo.txt', dir_str + '/logo.txt')
        except OSError:
            print('EX: _set_text_mode_theme unable to copy ' +
                  base_dir + '/img/logo.txt ' + dir_str + '/logo.txt')

    # set the text mode banner which appears in browsers such as Lynx
    text_mode_banner_filename = \
        base_dir + '/theme/' + name + '/banner.txt'
    if os.path.isfile(dir_str + '/banner.txt'):
        try:
            os.remove(dir_str + '/banner.txt')
        except OSError:
            print('EX: _set_text_mode_theme unable to delete ' +
                  dir_str + '/banner.txt')
    if os.path.isfile(text_mode_banner_filename):
        try:
            copyfile(text_mode_banner_filename, dir_str + '/banner.txt')
        except OSError:
            print('EX: _set_text_mode_theme unable to copy ' +
                  text_mode_banner_filename + ' ' +
                  dir_str + '/banner.txt')


def _set_theme_images(base_dir: str, name: str) -> None:
    """Changes the profile background image
    and banner to the defaults
    """
    theme_name_lower = name.lower()

    profile_image_filename = \
        base_dir + '/theme/' + theme_name_lower + '/image.png'
    banner_filename = \
        base_dir + '/theme/' + theme_name_lower + '/banner.png'
    search_banner_filename = \
        base_dir + '/theme/' + theme_name_lower + '/search_banner.png'
    left_col_image_filename = \
        base_dir + '/theme/' + theme_name_lower + '/left_col_image.png'
    right_col_image_filename = \
        base_dir + '/theme/' + theme_name_lower + '/right_col_image.png'

    _set_text_mode_theme(base_dir, theme_name_lower)

    background_names = ('login', 'shares', 'delete', 'follow',
                        'options', 'block', 'search', 'calendar',
                        'welcome')
    extensions = get_image_extensions()

    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for acct in dirs:
            if not is_account_dir(acct):
                continue
            account_dir = os.path.join(dir_str, acct)

            for background_type in background_names:
                for ext in extensions:
                    if theme_name_lower == 'default':
                        background_image_filename = \
                            base_dir + '/theme/default/' + \
                            background_type + '_background.' + ext
                    else:
                        background_image_filename = \
                            base_dir + '/theme/' + theme_name_lower + '/' + \
                            background_type + '_background' + '.' + ext

                    if os.path.isfile(background_image_filename):
                        try:
                            copyfile(background_image_filename,
                                     dir_str + '/' +
                                     background_type + '-background.' + ext)
                            continue
                        except OSError:
                            print('EX: _set_theme_images unable to copy ' +
                                  background_image_filename)
                    # background image was not found
                    # so remove any existing file
                    if os.path.isfile(dir_str + '/' +
                                      background_type + '-background.' + ext):
                        try:
                            os.remove(dir_str + '/' +
                                      background_type + '-background.' + ext)
                        except OSError:
                            print('EX: _set_theme_images unable to delete ' +
                                  dir_str + '/' +
                                  background_type + '-background.' + ext)

            if os.path.isfile(profile_image_filename) and \
               os.path.isfile(banner_filename):
                try:
                    copyfile(profile_image_filename,
                             account_dir + '/image.png')
                except OSError:
                    print('EX: _set_theme_images unable to copy ' +
                          profile_image_filename)

                try:
                    copyfile(banner_filename,
                             account_dir + '/banner.png')
                except OSError:
                    print('EX: _set_theme_images unable to copy ' +
                          banner_filename)

                try:
                    if os.path.isfile(search_banner_filename):
                        copyfile(search_banner_filename,
                                 account_dir + '/search_banner.png')
                except OSError:
                    print('EX: _set_theme_images unable to copy ' +
                          search_banner_filename)

                try:
                    if os.path.isfile(left_col_image_filename):
                        copyfile(left_col_image_filename,
                                 account_dir + '/left_col_image.png')
                    elif os.path.isfile(account_dir +
                                        '/left_col_image.png'):
                        try:
                            os.remove(account_dir + '/left_col_image.png')
                        except OSError:
                            print('EX: _set_theme_images unable to delete ' +
                                  account_dir + '/left_col_image.png')
                except OSError:
                    print('EX: _set_theme_images unable to copy ' +
                          left_col_image_filename)

                try:
                    if os.path.isfile(right_col_image_filename):
                        copyfile(right_col_image_filename,
                                 account_dir + '/right_col_image.png')
                    else:
                        if os.path.isfile(account_dir +
                                          '/right_col_image.png'):
                            try:
                                os.remove(account_dir + '/right_col_image.png')
                            except OSError:
                                print('EX: _set_theme_images ' +
                                      'unable to delete ' +
                                      account_dir + '/right_col_image.png')
                except OSError:
                    print('EX: _set_theme_images unable to copy ' +
                          right_col_image_filename)
        break


def set_news_avatar(base_dir: str, name: str,
                    http_prefix: str,
                    domain: str, domain_full: str) -> None:
    """Sets the avatar for the news account
    """
    nickname = 'news'
    new_filename = base_dir + '/theme/' + name + '/icons/avatar_news.png'
    if not os.path.isfile(new_filename):
        new_filename = base_dir + '/theme/default/icons/avatar_news.png'
    if not os.path.isfile(new_filename):
        return
    avatar_filename = \
        local_actor_url(http_prefix, domain_full, nickname) + '.png'
    avatar_filename = avatar_filename.replace('/', '-')
    filename = base_dir + '/cache/avatars/' + avatar_filename

    if os.path.isfile(filename):
        try:
            os.remove(filename)
        except OSError:
            print('EX: set_news_avatar unable to delete ' + filename)
    if os.path.isdir(base_dir + '/cache/avatars'):
        copyfile(new_filename, filename)
    account_dir = acct_dir(base_dir, nickname, domain)
    copyfile(new_filename, account_dir + '/avatar.png')


def _set_clear_cache_flag(base_dir: str) -> None:
    """Sets a flag which can be used by an external system
    (eg. a script in a cron job) to clear the browser cache
    """
    dir_str = data_dir(base_dir)
    if not os.path.isdir(dir_str):
        return
    flag_filename = dir_str + '/.clear_cache'
    try:
        with open(flag_filename, 'w+', encoding='utf-8') as fp_flag:
            fp_flag.write('\n')
    except OSError:
        print('EX: _set_clear_cache_flag unable to write ' + flag_filename)


def set_theme(base_dir: str, name: str, domain: str,
              allow_local_network_access: bool, system_language: str,
              dyslexic_font: bool, designer_reset: bool) -> bool:
    """Sets the theme with the given name as the current theme
    """
    result = False

    prev_theme_name = get_theme(base_dir)

    # if the theme has changed then remove any custom settings
    if prev_theme_name != name or designer_reset:
        reset_theme_designer_settings(base_dir)

    _remove_theme(base_dir)

    # has the theme changed?
    themes = get_themes_list(base_dir)
    for theme_name in themes:
        theme_name_lower = theme_name.lower()
        if name == theme_name_lower:
            if prev_theme_name:
                if prev_theme_name.lower() != theme_name_lower or \
                   designer_reset:
                    # change the banner and profile image
                    # to the default for the theme
                    _set_theme_images(base_dir, name)
                    _set_theme_fonts(base_dir, name)
            result = True
            break

    if not result:
        # default
        _set_theme_default(base_dir, allow_local_network_access,
                           system_language)
        result = True

    # read theme settings from a json file in the theme directory
    variables_file = base_dir + '/theme/' + name + '/theme.json'
    if os.path.isfile(variables_file):
        _read_variables_file(base_dir, name, variables_file,
                             allow_local_network_access,
                             system_language)

    if dyslexic_font:
        _set_dyslexic_font(base_dir)
    else:
        _set_custom_font(base_dir)

    # set the news avatar
    news_avatar_theme_filename = \
        base_dir + '/theme/' + name + '/icons/avatar_news.png'
    dir_str = data_dir(base_dir)
    if os.path.isdir(dir_str + '/news@' + domain):
        if os.path.isfile(news_avatar_theme_filename):
            news_avatar_filename = dir_str + '/news@' + domain + '/avatar.png'
            copyfile(news_avatar_theme_filename, news_avatar_filename)

    grayscale_filename = dir_str + '/.grayscale'
    if os.path.isfile(grayscale_filename):
        enable_grayscale(base_dir)
    else:
        disable_grayscale(base_dir)

    _copy_theme_help_files(base_dir, name, system_language)
    _set_theme_in_config(base_dir, name)
    _set_clear_cache_flag(base_dir)

    return result


def update_default_themes_list(base_dir: str) -> None:
    """Recreates the list of default themes
    """
    theme_names = get_themes_list(base_dir)
    default_themes_filename = base_dir + '/defaultthemes.txt'
    try:
        with open(default_themes_filename, 'w+', encoding='utf-8') as fp_def:
            for name in theme_names:
                fp_def.write(name + '\n')
    except OSError:
        print('EX: update_default_themes_list unable to write ' +
              default_themes_filename)


def scan_themes_for_scripts(base_dir: str) -> bool:
    """Scans the theme directory for any svg files containing scripts
    """
    # allow recursive walk
    for subdir, _, files in os.walk(base_dir + '/theme'):
        for fname in files:
            if not fname.endswith('.svg'):
                continue
            svg_filename = os.path.join(subdir, fname)
            content = ''
            try:
                with open(svg_filename, 'r', encoding='utf-8') as fp_svg:
                    content = fp_svg.read()
            except OSError:
                print('EX: scan_themes_for_scripts unable to read ' +
                      svg_filename)
            svg_dangerous = dangerous_svg(content, False)
            if svg_dangerous:
                print('svg file contains script: ' + svg_filename)
                return True
        # deliberately no break - should resursively scan
    return False
