__filename__ = "ssb.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"


from utils import get_attachment_property_value
from utils import remove_html


def get_ssb_address(actor_json: {}) -> str:
    """Returns ssb address for the given actor
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
        if not name_value.lower().startswith('ssb'):
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        property_value[prop_value_name] = \
            property_value[prop_value_name].strip()
        if not property_value[prop_value_name].startswith('@'):
            continue
        if '=.' not in property_value[prop_value_name]:
            continue
        if '"' in property_value[prop_value_name]:
            continue
        if ' ' in property_value[prop_value_name]:
            continue
        if ',' in property_value[prop_value_name]:
            continue
        return remove_html(property_value[prop_value_name])
    return ''


def set_ssb_address(actor_json: {}, ssb_address: str) -> None:
    """Sets an ssb address for the given actor
    """
    not_ssb_address = False
    if not ssb_address.startswith('@'):
        not_ssb_address = True
    if '=.' not in ssb_address:
        not_ssb_address = True
    if '"' in ssb_address:
        not_ssb_address = True
    if ' ' in ssb_address:
        not_ssb_address = True
    if ',' in ssb_address:
        not_ssb_address = True
    if '<' in ssb_address:
        not_ssb_address = True

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
        if not name_value.lower().startswith('ssb'):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_ssb_address:
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
        if not name_value.lower().startswith('ssb'):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = ssb_address
        return

    new_ssb_address = {
        "name": "SSB",
        "type": "PropertyValue",
        "value": ssb_address
    }
    actor_json['attachment'].append(new_ssb_address)
