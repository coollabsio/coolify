__filename__ = "matrix.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"


from utils import get_attachment_property_value
from utils import remove_html


def get_matrix_address(actor_json: {}) -> str:
    """Returns matrix address for the given actor
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
        name_value_lower = name_value.lower()
        if not name_value_lower.startswith('matrix'):
            if not name_value_lower.startswith('chat'):
                continue
        if 'xmpp' in name_value_lower or \
           'jabber' in name_value_lower:
            continue
        if not property_value.get('type'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        address_text = property_value[prop_value_name]
        if 'xmpp' in address_text.lower():
            continue
        if 'jabber' in address_text.lower():
            continue
        if 'matrix' in address_text:
            address_text = address_text.split('matrix')[1]
        elif 'Matrix' in address_text:
            address_text = address_text.split('Matrix')[1]
        if '@' in address_text:
            address_text = '@' + address_text.split('@', 1)[1]
        if ' ' in address_text:
            address_text = address_text.split(' ')[0]
        if '|' in address_text:
            address_text = address_text.split('|')[0]
        if ',' in address_text:
            address_text = address_text.split(',')[0]
        if ':' not in address_text:
            continue
        if '"' in address_text:
            continue
        return remove_html(address_text)
    return ''


def set_matrix_address(actor_json: {}, matrix_address: str) -> None:
    """Sets an matrix address for the given actor
    """
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
        if not name_value.lower().startswith('matrix'):
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)

    if '@' not in matrix_address:
        return
    if not matrix_address.startswith('@'):
        return
    if '.' not in matrix_address:
        return
    if '"' in matrix_address:
        return
    if '<' in matrix_address:
        return
    if ':' not in matrix_address:
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
        if not name_value.lower().startswith('matrix'):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = matrix_address
        return

    new_matrix_address = {
        "name": "Matrix",
        "type": "PropertyValue",
        "value": matrix_address
    }
    actor_json['attachment'].append(new_matrix_address)
