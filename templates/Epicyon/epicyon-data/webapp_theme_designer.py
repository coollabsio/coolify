__filename__ = "webapp_theme_designer.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from utils import data_dir
from utils import load_json
from utils import get_config_param
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_banner_file


color_to_hex = {
    "aliceblue": "#f0f8ff",
    "antiquewhite": "#faebd7",
    "aqua": "#00ffff",
    "aquamarine": "#7fffd4",
    "azure": "#f0ffff",
    "beige": "#f5f5dc",
    "bisque": "#ffe4c4",
    "black": "#000000",
    "blanchedalmond": "#ffebcd",
    "blue": "#0000ff",
    "blueviolet": "#8a2be2",
    "brown": "#a52a2a",
    "burlywood": "#deb887",
    "cadetblue": "#5f9ea0",
    "chartreuse": "#7fff00",
    "chocolate": "#d2691e",
    "coral": "#ff7f50",
    "cornflowerblue": "#6495ed",
    "cornsilk": "#fff8dc",
    "crimson": "#dc143c",
    "cyan": "#00ffff",
    "darkblue": "#00008b",
    "darkcyan": "#008b8b",
    "darkgoldenrod": "#b8860b",
    "darkgray": "#a9a9a9",
    "darkgrey": "#a9a9a9",
    "darkgreen": "#006400",
    "darkkhaki": "#bdb76b",
    "darkmagenta": "#8b008b",
    "darkolivegreen": "#556b2f",
    "darkorange": "#ff8c00",
    "darkorchid": "#9932cc",
    "darkred": "#8b0000",
    "darksalmon": "#e9967a",
    "darkseagreen": "#8fbc8f",
    "darkslateblue": "#483d8b",
    "darkslategray": "#2f4f4f",
    "darkslategrey": "#2f4f4f",
    "darkturquoise": "#00ced1",
    "darkviolet": "#9400d3",
    "deeppink": "#ff1493",
    "deepskyblue": "#00bfff",
    "dimgray": "#696969",
    "dimgrey": "#696969",
    "dodgerblue": "#1e90ff",
    "firebrick": "#b22222",
    "floralwhite": "#fffaf0",
    "forestgreen": "#228b22",
    "fuchsia": "#ff00ff",
    "gainsboro": "#dcdcdc",
    "ghostwhite": "#f8f8ff",
    "gold": "#ffd700",
    "goldenrod": "#daa520",
    "gray": "#808080",
    "grey": "#808080",
    "green": "#008000",
    "greenyellow": "#adff2f",
    "honeydew": "#f0fff0",
    "hotpink": "#ff69b4",
    "indianred": "#cd5c5c",
    "indigo": "#4b0082",
    "ivory": "#fffff0",
    "khaki": "#f0e68c",
    "lavender": "#e6e6fa",
    "lavenderblush": "#fff0f5",
    "lawngreen": "#7cfc00",
    "lemonchiffon": "#fffacd",
    "lightblue": "#add8e6",
    "lightcoral": "#f08080",
    "lightcyan": "#e0ffff",
    "lightgoldenrodyellow": "#fafad2",
    "lightgray": "#d3d3d3",
    "lightgrey": "#d3d3d3",
    "lightgreen": "#90ee90",
    "lightpink": "#ffb6c1",
    "lightsalmon": "#ffa07a",
    "lightseagreen": "#20b2aa",
    "lightskyblue": "#87cefa",
    "lightslategray": "#778899",
    "lightslategrey": "#778899",
    "lightsteelblue": "#b0c4de",
    "lightyellow": "#ffffe0",
    "lime": "#00ff00",
    "limegreen": "#32cd32",
    "linen": "#faf0e6",
    "magenta": "#ff00ff",
    "maroon": "#800000",
    "mediumaquamarine": "#66cdaa",
    "mediumblue": "#0000cd",
    "mediumorchid": "#ba55d3",
    "mediumpurple": "#9370db",
    "mediumseagreen": "#3cb371",
    "mediumslateblue": "#7b68ee",
    "mediumspringgreen": "#00fa9a",
    "mediumturquoise": "#48d1cc",
    "mediumvioletred": "#c71585",
    "midnightblue": "#191970",
    "mintcream": "#f5fffa",
    "mistyrose": "#ffe4e1",
    "moccasin": "#ffe4b5",
    "navajowhite": "#ffdead",
    "navy": "#000080",
    "oldlace": "#fdf5e6",
    "olive": "#808000",
    "olivedrab": "#6b8e23",
    "orange": "#ffa500",
    "orangered": "#ff4500",
    "orchid": "#da70d6",
    "palegoldenrod": "#eee8aa",
    "palegreen": "#98fb98",
    "paleturquoise": "#afeeee",
    "palevioletred": "#db7093",
    "papayawhip": "#ffefd5",
    "peachpuff": "#ffdab9",
    "peru": "#cd853f",
    "pink": "#ffc0cb",
    "plum": "#dda0dd",
    "powderblue": "#b0e0e6",
    "purple": "#800080",
    "red": "#ff0000",
    "rosybrown": "#bc8f8f",
    "royalblue": "#4169e1",
    "saddlebrown": "#8b4513",
    "salmon": "#fa8072",
    "sandybrown": "#f4a460",
    "seagreen": "#2e8b57",
    "seashell": "#fff5ee",
    "sienna": "#a0522d",
    "silver": "#c0c0c0",
    "skyblue": "#87ceeb",
    "slateblue": "#6a5acd",
    "slategray": "#708090",
    "slategrey": "#708090",
    "snow": "#fffafa",
    "springgreen": "#00ff7f",
    "steelblue": "#4682b4",
    "tan": "#d2b48c",
    "teal": "#008080",
    "thistle": "#d8bfd8",
    "tomato": "#ff6347",
    "turquoise": "#40e0d0",
    "violet": "#ee82ee",
    "wheat": "#f5deb3",
    "white": "#ffffff",
    "whitesmoke": "#f5f5f5",
    "yellow": "#ffff00",
    "yellowgreen": "#9acd32",
}


def html_theme_designer(base_dir: str,
                        nickname: str, domain: str,
                        translate: {}, default_timeline: str,
                        theme_name: str, access_keys: {}) -> str:
    """Edit theme settings
    """
    theme_filename = base_dir + '/theme/' + theme_name + '/theme.json'
    theme_json = {}
    if os.path.isfile(theme_filename):
        theme_json = load_json(theme_filename)

    # set custom theme parameters
    custom_variables_file = data_dir(base_dir) + '/theme.json'
    if os.path.isfile(custom_variables_file):
        custom_theme_params = load_json(custom_variables_file)
        if custom_theme_params:
            for variable_name, value in custom_theme_params.items():
                theme_json[variable_name] = value

    theme_form = ''
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    banner_file, _ = \
        get_banner_file(base_dir, nickname, domain, theme_name)
    banner_path = '/users/' + nickname + '/' + banner_file

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images = [banner_path]
    theme_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    theme_form += \
        '<a href="/users/' + nickname + '/' + default_timeline + '" ' + \
        'accesskey="' + access_keys['menuTimeline'] + '">' + \
        '<img loading="lazy" decoding="async" class="timeline-banner" ' + \
        'title="' + translate['Switch to timeline view'] + '" ' + \
        'alt="' + translate['Switch to timeline view'] + '" ' + \
        'src="' + banner_path + '" /></a>\n'
    theme_form += '<div class="container">\n'

    theme_form += \
        '    <h1>' + translate['Theme Designer'] + '</h1>\n'

    theme_form += '  <form method="POST" action="' + \
        '/users/' + nickname + '/changeThemeSettings">\n'

    reset_key = access_keys['menuLogout']
    submit_key = access_keys['submitButton']
    theme_form += \
        '    <center>\n' + \
        '    <button type="submit" class="button" ' + \
        'name="submitThemeDesignerReset" ' + \
        'accesskey="' + reset_key + '">' + \
        translate['Reset'] + '</button>\n' + \
        '    <button type="submit" class="button" ' + \
        'name="submitThemeDesigner" accesskey="' + submit_key + '">' + \
        translate['Save'] + '</button>\n    </center>\n'

    contrast_warning = ''
    if theme_json.get('main-bg-color'):
        background = theme_json['main-bg-color']
        if theme_json.get('main-fg-color'):
            foreground = theme_json['main-fg-color']
            contrast = color_contrast(background, foreground)
            if contrast:
                if contrast < 4.5:
                    contrast_warning = '⚠️ '
                    theme_form += \
                        '    <center><label class="labels">' + \
                        contrast_warning + '<b>' + \
                        translate['Color contrast is too low'] + \
                        '</b></label></center>\n'

    table_str = '    <table class="accesskeys">\n'
    table_str += '      <colgroup>\n'
    table_str += '        <col span="1" class="accesskeys-left">\n'
    table_str += '        <col span="1" class="accesskeys-center">\n'
    table_str += '      </colgroup>\n'
    table_str += '      <tbody>\n'

    font_str = '    <div class="container">\n' + table_str
    color_str = '    <div class="container">\n' + table_str
    dimension_str = '    <div class="container">\n' + table_str
    switch_str = '    <div class="container">\n' + table_str
    for variable_name, value in theme_json.items():
        if 'font-size' in variable_name:
            variable_name_str = variable_name.replace('-', ' ')
            variable_name_str = variable_name_str.title()
            variable_name_label = variable_name_str
            if contrast_warning:
                if variable_name in ('main-bg-color', 'main-fg-color'):
                    variable_name_label = contrast_warning + variable_name_str
            font_str += \
                '      <tr><td><label class="labels">' + \
                variable_name_label + '</label></td>'
            font_str += \
                '<td><input type="text" name="themeSetting_' + \
                variable_name + '" value="' + str(value) + \
                '" title="' + variable_name_str + '"></td></tr>\n'
        elif ('-color' in variable_name or
              '-background' in variable_name or
              variable_name.endswith('-text') or
              value.startswith('#') or
              color_to_hex.get(value)):
            # only use colors defined as hex
            if not value.startswith('#'):
                if color_to_hex.get(value):
                    value = color_to_hex[value]
                else:
                    continue
            variable_name_str = variable_name.replace('-', ' ')
            if ' color' in variable_name_str:
                variable_name_str = variable_name_str.replace(' color', '')
            if ' bg' in variable_name_str:
                variable_name_str = \
                    variable_name_str.replace(' bg', ' background')
            elif ' fg' in variable_name_str:
                variable_name_str = \
                    variable_name_str.replace(' fg', ' foreground')
            if variable_name_str == 'cw':
                variable_name_str = 'content warning'
            variable_name_str = variable_name_str.title()
            color_str += \
                '      <tr><td><label class="labels">' + \
                variable_name_str + '</label></td>'
            color_str += \
                '<td><input type="color" name="themeSetting_' + \
                variable_name + '" value="' + str(value) + \
                '" title="' + variable_name_str + '"></td></tr>\n'
        elif (('-width' in variable_name or
               '-height' in variable_name or
               '-spacing' in variable_name or
               '-margin' in variable_name or
               '-vertical' in variable_name) and
              (value.lower() != 'true' and value.lower() != 'false')):
            variable_name_str = variable_name.replace('-', ' ')
            variable_name_str = variable_name_str.title()
            dimension_str += \
                '      <tr><td><label class="labels">' + \
                variable_name_str + '</label></td>'
            dimension_str += \
                '<td><input type="text" name="themeSetting_' + \
                variable_name + '" value="' + str(value) + \
                '" title="' + variable_name_str + '"></td></tr>\n'
        elif value.title() == 'True' or value.title() == 'False':
            variable_name_str = variable_name.replace('-', ' ')
            variable_name_str = variable_name_str.title()
            switch_str += \
                '      <tr><td><label class="labels">' + \
                variable_name_str + '</label></td>'
            checked_str = ''
            if value.title() == 'True':
                checked_str = ' checked'
            switch_str += \
                '<td><input type="checkbox" class="profilecheckbox" ' + \
                'name="themeSetting_' + variable_name + '"' + \
                checked_str + '></td></tr>\n'

    color_str += '    </table>\n    </div>\n'
    font_str += '    </table>\n    </div>\n'
    dimension_str += '    </table>\n    </div>\n'
    switch_str += '    </table>\n    </div>\n'

    theme_formats = '.zip, .gz'
    export_import_str = '    <div class="container">\n'
    export_import_str += \
        '      <label class="labels">' + \
        translate['Import Theme'] + '</label>\n'
    export_import_str += '      <input type="file" id="importTheme" '
    export_import_str += 'name="importTheme" '
    export_import_str += 'accept="' + theme_formats + '">\n'
    export_import_str += \
        '      <label class="labels">' + \
        translate['Export Theme'] + '</label><br>\n'
    export_import_str += \
        '      <button type="submit" class="button" ' + \
        'name="submitExportTheme">➤</button><br>\n'
    export_import_str += '    </div>\n'

    theme_form += color_str + font_str + dimension_str
    theme_form += switch_str + export_import_str
    theme_form += '  </form>\n'
    theme_form += '</div>\n'
    theme_form += html_footer()
    return theme_form


def _relative_luminance(color: str) -> float:
    """ Returns the relative luminance for the given color
    """
    color = color.lstrip('#')
    rgb = list(int(color[i:i+2], 16) for i in (0, 2, 4))
    srgb = (
        rgb[0] / 255.0,
        rgb[1] / 255.0,
        rgb[2] / 255.0
    )
    if srgb[0] <= 0.03928:
        rgb[0] = srgb[0] / 12.92
    else:
        rgb[0] = pow(((srgb[0] + 0.055) / 1.055), 2.4)
    if srgb[1] <= 0.03928:
        rgb[1] = srgb[1] / 12.92
    else:
        rgb[1] = pow(((srgb[1] + 0.055) / 1.055), 2.4)
    if srgb[2] <= 0.03928:
        rgb[2] = srgb[2] / 12.92
    else:
        rgb[2] = pow(((srgb[2] + 0.055) / 1.055), 2.4)

    return \
        0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]


def color_contrast(background: str, foreground: str) -> float:
    """returns the color contrast
    """
    if not background.startswith('#'):
        if color_to_hex.get(background):
            background = color_to_hex[background]
        else:
            return None
    if not foreground.startswith('#'):
        if color_to_hex.get(foreground):
            foreground = color_to_hex[foreground]
        else:
            return None
    background_luminance = _relative_luminance(background)
    foreground_luminance = _relative_luminance(foreground)
    if background_luminance > foreground_luminance:
        return (0.05 + background_luminance) / (0.05 + foreground_luminance)
    return (0.05 + foreground_luminance) / (0.05 + background_luminance)
