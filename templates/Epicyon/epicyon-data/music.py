__filename__ = "music.py"
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

music_fieldnames = ('music')

music_sites = (
    'bandcamp.com', 'soundcloud.com', 'beatport.com', 'last.fm',
    'hypeddit.com', 'mixcloud.com', 'reverbnation.com', 'soundclick.com',
    'pandora.com'
)


def get_music_site_url(actor_json: {}) -> str:
    """Returns music site url for the given actor
    """
    if not actor_json.get('attachment'):
        return ''
    if not isinstance(actor_json['attachment'], list):
        return ''
    for property_value in actor_json['attachment']:
        if not property_value.get('type'):
            continue
        if not isinstance(property_value['type'], str):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        music_text = remove_html(property_value[prop_value_name])
        if not string_contains(music_text, music_sites):
            continue
        if not resembles_url(music_text):
            continue
        return music_text
    return ''


def set_music_site_url(actor_json: {}, music_site_url: str) -> None:
    """Sets music site url for the given actor
    """
    music_site_url = remove_html(music_site_url)
    if not resembles_url(music_site_url):
        return

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
        if not string_contains(name_value, music_fieldnames):
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
        if not string_contains(name_value, music_fieldnames):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = remove_html(music_site_url)
        return

    new_music = {
        "type": "PropertyValue",
        "name": "Music",
        "value": remove_html(music_site_url)
    }
    actor_json['attachment'].append(new_music)
