__filename__ = "pixelfed.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"


from utils import get_attachment_property_value
from utils import remove_html
from utils import string_contains
from utils import resembles_url
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import get_full_domain

pixelfed_fieldnames = ['pixelfed']


def get_pixelfed(actor_json: {}) -> str:
    """Returns pixelfed for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    if not isinstance(actor_json['attachment'], list):
        return ''
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name'].lower()
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name'].lower()
        if not name_value:
            continue
        if not string_contains(name_value, pixelfed_fieldnames):
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        pixelfed_text = remove_html(property_value[prop_value_name])
        if not resembles_url(pixelfed_text):
            if '@' not in pixelfed_text:
                continue
            # a pixelfed handle has been given, rather than a url
            nickname = get_nickname_from_actor(pixelfed_text)
            domain, port = get_domain_from_actor(pixelfed_text)
            if not nickname or not domain:
                continue
            http_prefix = 'https://'
            if domain.endswith('.onion') or \
               domain.endswith('.i2p'):
                http_prefix = 'http://'
            pixelfed_text = \
                http_prefix + \
                get_full_domain(domain, port) + '/@' + nickname
        if not resembles_url(pixelfed_text):
            continue
        return pixelfed_text

    for property_value in actor_json['attachment']:
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        pixelfed_text = property_value[prop_value_name]
        if '//pixelfed.' not in pixelfed_text:
            continue
        pixelfed_text = remove_html(pixelfed_text)
        return pixelfed_text

    return ''


def set_pixelfed(actor_json: {}, pixelfed: str) -> None:
    """Sets pixelfed for the given actor
    """
    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    # remove any existing value
    property_found = None
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name'].lower()
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name'].lower()
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        if not string_contains(name_value, pixelfed_fieldnames):
            continue
        property_found = property_value
        break

    if property_found:
        actor_json['attachment'].remove(property_found)

    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not property_value.get('type'):
            continue
        name_value = name_value.lower()
        if not string_contains(name_value, pixelfed_fieldnames):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = remove_html(pixelfed)
        return

    new_pixelfed = {
        "type": "PropertyValue",
        "name": "Pixelfed",
        "value": remove_html(pixelfed)
    }
    actor_json['attachment'].append(new_pixelfed)
