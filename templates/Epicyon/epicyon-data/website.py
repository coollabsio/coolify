__filename__ = "website.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"


from utils import get_attachment_property_value
from utils import remove_html
from utils import remove_link_tracking


def _get_website_strings() -> []:
    return ['www', 'website', 'web', 'homepage', 'home', 'contact']


def _get_gemini_strings() -> []:
    return ['gemini', 'capsule', 'gemlog']


def get_website(actor_json: {}, translate: {}) -> str:
    """Returns a web address link
    """
    if not actor_json.get('attachment'):
        return ''
    match_strings = _get_website_strings()
    match_strings.append(translate['Website'].lower())
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        found = False
        for possible_str in match_strings:
            if possible_str in name_value.lower():
                found = True
                break
        if not found:
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        value_str = remove_html(property_value[prop_value_name])
        if 'https://' not in value_str and \
           'http://' not in value_str:
            continue
        return remove_link_tracking(value_str)
    return ''


def get_gemini_link(actor_json: {}) -> str:
    """Returns a gemini link
    """
    if not actor_json.get('attachment'):
        return ''
    match_strings = _get_gemini_strings()
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if name_value.lower() not in match_strings:
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        url = remove_html(property_value[prop_value_name])
        return remove_link_tracking(url)

    for property_value in actor_json['attachment']:
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        url = remove_html(property_value[prop_value_name])
        if 'gemini://' not in url:
            continue
        return remove_link_tracking(url)
    return ''


def set_website(actor_json: {}, website_url: str, translate: {}) -> None:
    """Sets a web address
    """
    website_url = website_url.strip()
    not_url = False
    if '.' not in website_url:
        not_url = True
    if '://' not in website_url:
        not_url = True
    if ' ' in website_url:
        not_url = True
    if '<' in website_url:
        not_url = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    match_strings = _get_website_strings()
    match_strings.append(translate['Website'].lower())

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        if not property_value.get('name'):
            continue
        if not property_value.get('type'):
            continue
        if property_value['name'].lower() not in match_strings:
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_url:
        return

    new_entry = {
        "name": 'Website',
        "type": "PropertyValue",
        "value": website_url
    }
    actor_json['attachment'].append(new_entry)


def set_gemini_link(actor_json: {}, gemini_link: str) -> None:
    """Sets a gemini link
    """
    gemini_link = gemini_link.strip()
    not_link = False
    if '.' not in gemini_link:
        not_link = True
    if '://' not in gemini_link:
        not_link = True
    if ' ' in gemini_link:
        not_link = True
    if '<' in gemini_link:
        not_link = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    match_strings = _get_gemini_strings()

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        if not property_value.get('name'):
            continue
        if not property_value.get('type'):
            continue
        if property_value['name'].lower() not in match_strings:
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_link:
        return

    new_entry = {
        "name": 'Gemini',
        "type": "PropertyValue",
        "value": gemini_link
    }
    actor_json['attachment'].append(new_entry)
