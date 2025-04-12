__filename__ = "peertube.py"
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

peertube_fieldnames = ['peertube']


def get_peertube(actor_json: {}) -> str:
    """Returns peertube for the given actor
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
        if not string_contains(name_value, peertube_fieldnames):
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        peertube_text = property_value[prop_value_name]
        return remove_html(peertube_text)

    for property_value in actor_json['attachment']:
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        peertube_text = property_value[prop_value_name]
        if '//peertube.' not in peertube_text:
            continue
        return remove_html(peertube_text)
    return ''


def set_peertube(actor_json: {}, peertube: str) -> None:
    """Sets peertube for the given actor
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
        if not string_contains(name_value, peertube_fieldnames):
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
        if not string_contains(name_value, peertube_fieldnames):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = remove_html(peertube)
        return

    new_peertube = {
        "type": "PropertyValue",
        "name": "Peertube",
        "value": remove_html(peertube)
    }
    actor_json['attachment'].append(new_peertube)
