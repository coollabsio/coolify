__filename__ = "cwtch.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"

import re
from utils import get_attachment_property_value
from utils import remove_html


def get_cwtch_address(actor_json: {}) -> str:
    """Returns cwtch address for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    if not isinstance(actor_json['attachment'], list):
        return ''
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if not name_value.lower().startswith('cwtch'):
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, prop_value = \
            get_attachment_property_value(property_value)
        if not prop_value:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        property_value[prop_value_name] = \
            property_value[prop_value_name].strip()
        if len(property_value[prop_value_name]) < 2:
            continue
        if '"' in property_value[prop_value_name]:
            continue
        if ' ' in property_value[prop_value_name]:
            continue
        if ',' in property_value[prop_value_name]:
            continue
        if '.' in property_value[prop_value_name]:
            continue
        return remove_html(property_value[prop_value_name])
    return ''


def set_cwtch_address(actor_json: {}, cwtch_address: str) -> None:
    """Sets an cwtch address for the given actor
    """
    not_cwtch_address = False

    if len(cwtch_address) < 56:
        not_cwtch_address = True
    if cwtch_address != cwtch_address.lower():
        not_cwtch_address = True
    if not re.match("^[a-z0-9]*$", cwtch_address):
        not_cwtch_address = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    # remove any existing value
    property_found = None
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
        if not name_value.lower().startswith('cwtch'):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_cwtch_address:
        return

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
        if not name_value.lower().startswith('cwtch'):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = cwtch_address
        return

    new_cwtch_address = {
        "name": "Cwtch",
        "type": "PropertyValue",
        "value": cwtch_address
    }
    actor_json['attachment'].append(new_cwtch_address)
