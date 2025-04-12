__filename__ = "art.py"
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

art_fieldnames = ('art')

art_sites = (
    'etsy.com', 'shopify.com', 'folksy.com', 'aftcra.com',
    'justartisan.com', 'goimagine.com', 'artfire.com',
    'indiecart.com', 'madeit.com.au', 'icraftgifts.com',
    'felt.co.nz', 'shootproof.com', 'pixieset.com',
    'instaproofs.com', 'smugmug.com', 'singulart.com',
    'pic-time.com', 'zenfolio.com', 'photoshelter.com',
    'squarespace.com', 'alamy.com', 'shutterstock.com',
    'dreamstime.com', 'canstockphoto.com',
    'stock.adobe.com', 'gettyimages.', 'istockphoto.com',
    'stocksy.com', '500px.com', 'fineartamerica.com',
    '//photos.', 'ipernity.com', 'imgur.com', 'flickr.com',
    'shotshare.dev', 'artsy.net', 'saatchiart.com',
    'society6.com', 'artpal.com', 'artfinder.com',
    'singulart.com', 'fineartamerica.com', 'redbubble.com',
    'amazon.com/Art/', 'mademe.co.uk', 'artsthread.com',
    'theaoi.com', 'marketplace.asos.com', 'behance.net',
    'bristolmarket.co.uk', 'craftersmarket.uk',
    'craftscouncil.org.uk', 'craftyfoxmarket.co.uk',
    'designnation.co.uk', 'eclecticartisans.com',
    'findamaker.co.uk', 'handmadeinbritain.co.uk',
    'lisavalentinehome.co.uk', 'notonthehighstreet.com',
    'numonday.com', 'odissa.co.uk', 'onlineceramics.com',
    'pedddle.com', 'rebelsmarket.com', 'rockettstgeorge.co.uk',
    'spoonflower.com', 'artisanfounder.com',
    'thebritishcrafthouse.co.uk', 'thefuturekept.com',
    'threadless.com', 'trouva.com', 'wolfandbadger.com',
    'yoyoandflo.com'
)


def get_art_site_url(actor_json: {}) -> str:
    """Returns art site url for the given actor
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
        art_text = remove_html(property_value[prop_value_name])
        if not string_contains(art_text, art_sites):
            continue
        if not resembles_url(art_text):
            continue
        return art_text
    return ''


def set_art_site_url(actor_json: {}, art_site_url: str) -> None:
    """Sets art site url for the given actor
    """
    art_site_url = remove_html(art_site_url)
    if not resembles_url(art_site_url):
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
        if not string_contains(name_value, art_fieldnames):
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
        if not string_contains(name_value, art_fieldnames):
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        prop_value_name, _ = \
            get_attachment_property_value(property_value)
        if not prop_value_name:
            continue
        property_value[prop_value_name] = remove_html(art_site_url)
        return

    new_art = {
        "type": "PropertyValue",
        "name": "Art",
        "value": remove_html(art_site_url)
    }
    actor_json['attachment'].append(new_art)
