__filename__ = "donate.py"
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


def _get_donation_types() -> []:
    return ('patreon', 'paypal', 'gofundme', 'liberapay',
            'kickstarter', 'indiegogo', 'crowdsupply',
            'subscribestar', 'kofi', 'ko-fi',
            'fundly', 'crowdrise',
            'justgiving', 'globalgiving', 'givedirectly',
            'fundrazr', 'kiva', 'thebiggive', 'donorbox',
            'opencollective', 'buymeacoffee', 'flattr',
            'bountysource', 'coindrop', 'gitpay', 'tipeee')


def get_donation_url(actor_json: {}) -> str:
    """Returns a link used for donations
    """
    if not actor_json.get('attachment'):
        return ''
    if not isinstance(actor_json['attachment'], list):
        return ''
    donation_type = _get_donation_types()
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        name_value_lower = name_value.lower()
        if name_value_lower not in donation_type:
            if 'support' not in name_value_lower and \
               'buy me ' not in name_value_lower and \
               'fund' not in name_value_lower:
                continue
        if not property_value.get('type'):
            continue
        prop_value_name, prop_value = \
            get_attachment_property_value(property_value)
        if not prop_value:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        if '<a href="' in property_value[prop_value_name]:
            donate_url = property_value[prop_value_name].split('<a href="')[1]
            if '"' in donate_url:
                donate_url = donate_url.split('"')[0]
                donate_url = remove_html(donate_url)
                return remove_link_tracking(donate_url)
        else:
            donate_url = remove_html(property_value[prop_value_name])
            if ' ' in donate_url:
                donate_url = donate_url.split(' ')[0]
            if '://' in donate_url:
                return remove_link_tracking(donate_url)
    return ''


def set_donation_url(actor_json: {}, donate_url: str) -> None:
    """Sets a link used for donations
    """
    not_url = False
    if '.' not in donate_url:
        not_url = True
    if '://' not in donate_url:
        not_url = True
    if ' ' in donate_url:
        not_url = True
    if '<' in donate_url:
        not_url = True

    if not actor_json.get('attachment'):
        actor_json['attachment']: list[dict] = []

    donation_type = _get_donation_types()
    donate_name = None
    for payment_service in donation_type:
        if payment_service in donate_url:
            donate_name = payment_service
    if not donate_name:
        return

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
        if not name_value.lower() != donate_name:
            continue
        property_found = property_value
        break
    if property_found:
        actor_json['attachment'].remove(property_found)
    if not_url:
        return

    donate_value = \
        '<a href="' + donate_url + \
        '" rel="me nofollow noopener noreferrer" target="_blank">' + \
        donate_url + '</a>'

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
        if name_value.lower() != donate_name:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = donate_value
        return

    new_donate = {
        "name": donate_name,
        "type": "PropertyValue",
        "value": donate_value,
        "rel": "payment"
    }
    actor_json['attachment'].append(new_donate)
