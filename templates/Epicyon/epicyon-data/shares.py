__filename__ = "shares.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
import re
import secrets
import time
import datetime
from random import randint
from pprint import pprint
from session import get_json
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from auth import constant_time_string_check
from posts import get_person_box
from session import post_json
from session import post_image
from session import create_session
from session import get_json_valid
from flags import is_float
from utils import replace_strings
from utils import data_dir
from utils import resembles_url
from utils import date_utcnow
from utils import dangerous_markup
from utils import remove_html
from utils import get_media_extensions
from utils import acct_handle_dir
from utils import remove_eol
from utils import has_object_string_type
from utils import date_string_to_seconds
from utils import date_seconds_to_string
from utils import get_config_param
from utils import get_full_domain
from utils import valid_nickname
from utils import load_json
from utils import save_json
from utils import get_image_extensions
from utils import remove_domain_port
from utils import is_account_dir
from utils import acct_dir
from utils import get_category_types
from utils import get_shares_files_list
from utils import local_actor_url
from utils import get_actor_from_post
from media import process_meta_data
from media import convert_image_to_low_bandwidth
from filters import is_filtered_globally
from siteactive import site_is_active
from content import get_price_from_string
from blocking import is_blocked
from threads import begin_thread
from cache import remove_person_from_cache
from cache import store_person_in_cache


def _load_dfc_ids(base_dir: str, system_language: str,
                  product_type: str,
                  http_prefix: str, domain_full: str) -> {}:
    """Loads the product types ontology
    This is used to add an id to shared items
    """
    product_types_filename = \
        base_dir + '/ontology/custom' + product_type.title() + 'Types.json'
    if not os.path.isfile(product_types_filename):
        product_types_filename = \
            base_dir + '/ontology/' + product_type + 'Types.json'
    product_types = load_json(product_types_filename)
    if not product_types:
        print('Unable to load ontology: ' + product_types_filename)
        return None
    if not product_types.get('@graph'):
        print('No @graph list within ontology')
        return None
    if len(product_types['@graph']) == 0:
        print('@graph list has no contents')
        return None
    if not product_types['@graph'][0].get('rdfs:label'):
        print('@graph list entry has no rdfs:label')
        return None
    language_exists = False
    for label in product_types['@graph'][0]['rdfs:label']:
        if not label.get('@language'):
            continue
        if label['@language'] == system_language:
            language_exists = True
            break
    if not language_exists:
        print('product_types ontology does not contain the language ' +
              system_language)
        return None
    dfc_ids = {}
    for item in product_types['@graph']:
        if not item.get('@id'):
            continue
        if not item.get('rdfs:label'):
            continue
        for label in item['rdfs:label']:
            if not label.get('@language'):
                continue
            if not label.get('@value'):
                continue
            if label['@language'] != system_language:
                continue
            item_id = \
                item['@id'].replace('http://static.datafoodconsortium.org',
                                    http_prefix + '://' + domain_full)
            dfc_ids[label['@value'].lower()] = item_id
            break
    return dfc_ids


def _get_valid_shared_item_id(actor: str, display_name: str) -> str:
    """Removes any invalid characters from the display name to
    produce an item ID
    """
    remove_chars = (' ', '\n', '\r', '#')
    for char in remove_chars:
        display_name = display_name.replace(char, '')
    remove_chars2 = ('+', '/', '\\', '?', '&')
    for char in remove_chars2:
        display_name = display_name.replace(char, '-')
    replacements = {
        '.': '_',
        "’": "'"
    }
    display_name = replace_strings(display_name, replacements)
    replacements2 = {
        '://': '___',
        '/': '--'
    }
    actor = replace_strings(actor, replacements2)
    return actor + '--shareditems--' + display_name


def remove_shared_item2(base_dir: str, nickname: str, domain: str,
                        item_id: str, shares_file_type: str) -> None:
    """Removes a share for a person
    """
    shares_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + shares_file_type + '.json'
    if not os.path.isfile(shares_filename):
        print('ERROR: remove shared item, missing ' +
              shares_file_type + '.json ' + shares_filename)
        return

    shares_json = load_json(shares_filename)
    if not shares_json:
        print('ERROR: remove shared item, ' +
              shares_file_type + '.json could not be loaded from ' +
              shares_filename)
        return

    if shares_json.get(item_id):
        # remove any image for the item
        item_idfile = base_dir + '/sharefiles/' + nickname + '/' + item_id
        if shares_json[item_id]['imageUrl']:
            formats = get_image_extensions()
            for ext in formats:
                if not shares_json[item_id]['imageUrl'].endswith('.' + ext):
                    continue
                if not os.path.isfile(item_idfile + '.' + ext):
                    continue
                try:
                    os.remove(item_idfile + '.' + ext)
                except OSError:
                    print('EX: remove_shared_item unable to delete ' +
                          item_idfile + '.' + ext)
        # remove the item itself
        del shares_json[item_id]
        save_json(shares_json, shares_filename)
    else:
        print('ERROR: share index "' + item_id +
              '" does not exist in ' + shares_filename)


def _add_share_duration_sec(duration: str, published: int) -> int:
    """Returns the duration for the shared item in seconds
    """
    if ' ' not in duration:
        return 0
    duration_list = duration.split(' ')
    if not duration_list[0].isdigit():
        return 0
    if 'hour' in duration_list[1]:
        return published + (int(duration_list[0]) * 60 * 60)
    if 'day' in duration_list[1]:
        return published + (int(duration_list[0]) * 60 * 60 * 24)
    if 'week' in duration_list[1]:
        return published + (int(duration_list[0]) * 60 * 60 * 24 * 7)
    if 'month' in duration_list[1]:
        return published + (int(duration_list[0]) * 60 * 60 * 24 * 30)
    if 'year' in duration_list[1]:
        return published + (int(duration_list[0]) * 60 * 60 * 24 * 365)
    return 0


def _dfc_product_type_from_category(base_dir: str,
                                    item_category: str, translate: {}) -> str:
    """Does the shared item category match a DFC product type?
    If so then return the product type.
    This will be used to select an appropriate ontology file
    such as ontology/foodTypes.json
    """
    product_types_list = get_category_types(base_dir)
    category_lower = item_category.lower()
    for product_type in product_types_list:
        if translate.get(product_type):
            if translate[product_type] in category_lower:
                return product_type
        else:
            if product_type in category_lower:
                return product_type
    return None


def _getshare_dfc_id(base_dir: str, system_language: str,
                     item_type: str, item_category: str,
                     translate: {},
                     http_prefix: str, domain_full: str,
                     dfc_ids: {} = None) -> str:
    """Attempts to obtain a DFC Id for the shared item,
    based upon product_types ontology.
    See https://github.com/datafoodconsortium/ontology
    """
    # does the category field match any prodyct type ontology
    # files in the ontology subdirectory?
    matched_product_type = \
        _dfc_product_type_from_category(base_dir, item_category, translate)
    if not matched_product_type:
        replacements = {
            ' ': '_',
            '.': ''
        }
        item_type = replace_strings(item_type, replacements)
        return 'epicyon#' + item_type
    if not dfc_ids:
        dfc_ids = _load_dfc_ids(base_dir, system_language,
                                matched_product_type,
                                http_prefix, domain_full)
        if not dfc_ids:
            return ''
    item_type_lower = item_type.lower()
    match_name = ''
    match_id = ''
    for name, uri in dfc_ids.items():
        if name not in item_type_lower:
            continue
        if len(name) > len(match_name):
            match_name = name
            match_id = uri
    if not match_id:
        # bag of words match
        max_matched_words = 0
        for name, uri in dfc_ids.items():
            name = name.replace('-', ' ')
            words = name.split(' ')
            score = 0
            for wrd in words:
                if wrd in item_type_lower:
                    score += 1
            if score > max_matched_words:
                max_matched_words = score
                match_id = uri
    return match_id


def _getshare_type_from_dfc_id(dfc_uri: str, dfc_ids: {}) -> str:
    """Attempts to obtain a share item type from its DFC Id,
    based upon product_types ontology.
    See https://github.com/datafoodconsortium/ontology
    """
    if dfc_uri.startswith('epicyon#'):
        item_type = dfc_uri.split('#')[1]
        item_type = item_type.replace('_', ' ')
        return item_type

    for name, uri in dfc_ids.items():
        if uri.endswith('#' + dfc_uri):
            return name
        if uri == dfc_uri:
            return name
    return None


def _indicate_new_share_available(base_dir: str, http_prefix: str,
                                  nickname: str, domain: str,
                                  domain_full: str,
                                  shares_file_type: str,
                                  block_federated: []) -> None:
    """Indicate to each account that a new share is available
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for handle in dirs:
            if not is_account_dir(handle):
                continue
            account_dir = acct_handle_dir(base_dir, handle)
            if shares_file_type == 'shares':
                new_share_file = account_dir + '/.newShare'
            else:
                new_share_file = account_dir + '/.newWanted'
            if os.path.isfile(new_share_file):
                continue
            account_nickname = handle.split('@')[0]
            # does this account block you?
            if account_nickname != nickname:
                if is_blocked(base_dir, account_nickname, domain,
                              nickname, domain, None, block_federated):
                    continue
            local_actor = \
                local_actor_url(http_prefix, account_nickname, domain_full)
            try:
                with open(new_share_file, 'w+', encoding='utf-8') as fp_new:
                    if shares_file_type == 'shares':
                        fp_new.write(local_actor + '/tlshares')
                    else:
                        fp_new.write(local_actor + '/tlwanted')
            except OSError:
                print('EX: _indicate_new_share_available unable to write ' +
                      str(new_share_file))
        break


def add_share(base_dir: str,
              http_prefix: str, nickname: str, domain: str, port: int,
              display_name: str, summary: str, image_filename: str,
              item_qty: float, item_type: str, item_category: str,
              location: str, duration: str, debug: bool, city: str,
              price: str, currency: str,
              system_language: str, translate: {},
              shares_file_type: str, low_bandwidth: bool,
              content_license_url: str, share_on_profile: bool,
              block_federated: []) -> None:
    """Adds a new share
    """
    if is_filtered_globally(base_dir,
                            display_name + ' ' + summary + ' ' +
                            item_type + ' ' + item_category,
                            system_language):
        print('Shared item was filtered due to content')
        return
    shares_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + shares_file_type + '.json'
    shares_json = {}
    if os.path.isfile(shares_filename):
        shares_json = load_json(shares_filename)

    duration = duration.lower()
    published = int(time.time())
    duration_sec = _add_share_duration_sec(duration, published)

    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)
    item_id = _get_valid_shared_item_id(actor, display_name)
    dfc_id = _getshare_dfc_id(base_dir, system_language,
                              item_type, item_category, translate,
                              http_prefix, domain_full)

    # has an image for this share been uploaded?
    image_url = None
    move_image = False
    if not image_filename:
        shares_image_filename = \
            acct_dir(base_dir, nickname, domain) + '/upload'
        formats = get_image_extensions()
        for ext in formats:
            if not os.path.isfile(shares_image_filename + '.' + ext):
                continue
            image_filename = shares_image_filename + '.' + ext
            move_image = True

    domain_full = get_full_domain(domain, port)

    # copy or move the image for the shared item to its destination
    if image_filename:
        if os.path.isfile(image_filename):
            if not os.path.isdir(base_dir + '/sharefiles'):
                os.mkdir(base_dir + '/sharefiles')
            if not os.path.isdir(base_dir + '/sharefiles/' + nickname):
                os.mkdir(base_dir + '/sharefiles/' + nickname)
            item_idfile = base_dir + '/sharefiles/' + nickname + '/' + item_id
            formats = get_image_extensions()
            for ext in formats:
                if not image_filename.endswith('.' + ext):
                    continue
                if low_bandwidth:
                    convert_image_to_low_bandwidth(image_filename)
                process_meta_data(base_dir, nickname, domain,
                                  image_filename, item_idfile + '.' + ext,
                                  city, content_license_url)
                if move_image:
                    try:
                        os.remove(image_filename)
                    except OSError:
                        print('EX: add_share unable to delete ' +
                              str(image_filename))
                image_url = \
                    http_prefix + '://' + domain_full + \
                    '/sharefiles/' + nickname + '/' + item_id + '.' + ext

    shares_json[item_id] = {
        "displayName": display_name,
        "summary": summary,
        "imageUrl": image_url,
        "itemQty": float(item_qty),
        "dfcId": dfc_id,
        "itemType": item_type,
        "category": item_category,
        "location": location,
        "published": published,
        "expire": duration_sec,
        "itemPrice": price,
        "itemCurrency": currency,
        "shareOnProfile": share_on_profile
    }

    save_json(shares_json, shares_filename)

    _indicate_new_share_available(base_dir, http_prefix,
                                  nickname, domain, domain_full,
                                  shares_file_type,
                                  block_federated)


def expire_shares(base_dir: str, max_shares_on_profile: int,
                  person_cache: {}) -> None:
    """Removes expired items from shares
    """
    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for account in dirs:
            if not is_account_dir(account):
                continue
            nickname = account.split('@')[0]
            domain = account.split('@')[1]
            shares_list = get_shares_files_list()
            expired_ctr = 0
            for shares_file_type in shares_list:
                ctr = \
                    _expire_shares_for_account(base_dir, nickname, domain,
                                               shares_file_type)
                if shares_file_type == 'shares':
                    expired_ctr = ctr
            # have shared items been expired?
            if expired_ctr > 0:
                continue
            # regenerate shared items within actor attachment
            actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
            if not os.path.isfile(actor_filename):
                continue
            actor_json = load_json(actor_filename)
            if not actor_json:
                continue
            if add_shares_to_actor(base_dir,
                                   nickname, domain,
                                   actor_json,
                                   max_shares_on_profile):
                actor = actor_json['id']
                remove_person_from_cache(base_dir, actor,
                                         person_cache)
                store_person_in_cache(base_dir, actor,
                                      actor_json,
                                      person_cache, True)
                save_json(actor_json, actor_filename)
        break


def _expire_shares_for_account(base_dir: str, nickname: str, domain: str,
                               shares_file_type: str) -> int:
    """Removes expired items from shares for a particular account
    Returns the number of items removed
    """
    handle_domain = remove_domain_port(domain)
    handle = nickname + '@' + handle_domain
    shares_filename = \
        acct_handle_dir(base_dir, handle) + '/' + shares_file_type + '.json'
    if not os.path.isfile(shares_filename):
        return 0
    shares_json = load_json(shares_filename)
    if not shares_json:
        return 0
    curr_time = int(time.time())
    delete_item_id: list[str] = []
    for item_id, item in shares_json.items():
        if curr_time > item['expire']:
            delete_item_id.append(item_id)
    if not delete_item_id:
        return 0
    removed_ctr = len(delete_item_id)
    for item_id in delete_item_id:
        del shares_json[item_id]
        # remove any associated images
        item_idfile = base_dir + '/sharefiles/' + nickname + '/' + item_id
        formats = get_image_extensions()
        for ext in formats:
            if not os.path.isfile(item_idfile + '.' + ext):
                continue
            try:
                os.remove(item_idfile + '.' + ext)
            except OSError:
                print('EX: _expire_shares_for_account unable to delete ' +
                      item_idfile + '.' + ext)
    save_json(shares_json, shares_filename)
    return removed_ctr


def get_shares_feed_for_person(base_dir: str,
                               domain: str, port: int,
                               path: str, http_prefix: str,
                               shares_file_type: str,
                               shares_per_page: int) -> {}:
    """Returns the shares for an account from GET requests
    """
    if '/' + shares_file_type not in path:
        return None
    # handle page numbers
    header_only = True
    page_number = None
    if '?page=' in path:
        page_number = path.split('?page=')[1]
        if len(page_number) > 5:
            page_number = 1
        if page_number == 'true':
            page_number = 1
        else:
            try:
                page_number = int(page_number)
            except BaseException:
                print('EX: get_shares_feed_for_person ' +
                      'unable to convert to int ' + str(page_number))
        path = path.split('?page=')[0]
        header_only = False

    if not path.endswith('/' + shares_file_type):
        return None
    nickname = None
    if path.startswith('/users/'):
        nickname = \
            path.replace('/users/', '', 1).replace('/' + shares_file_type, '')
    if path.startswith('/@'):
        if '/@/' not in path:
            nickname = \
                path.replace('/@', '', 1).replace('/' + shares_file_type, '')
    if not nickname:
        return None
    if not valid_nickname(domain, nickname):
        return None

    domain = get_full_domain(domain, port)

    handle_domain = remove_domain_port(domain)
    shares_filename = \
        acct_dir(base_dir, nickname, handle_domain) + '/' + \
        shares_file_type + '.json'

    if header_only:
        no_of_shares = 0
        if os.path.isfile(shares_filename):
            shares_json = load_json(shares_filename)
            if shares_json:
                no_of_shares = len(shares_json.items())
        id_str = local_actor_url(http_prefix, nickname, domain)
        shares = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'first': id_str + '/' + shares_file_type + '?page=1',
            'id': id_str + '/' + shares_file_type,
            'totalItems': str(no_of_shares),
            'type': 'OrderedCollection'
        }
        return shares

    if not page_number:
        page_number = 1

    next_page_number = int(page_number + 1)
    id_str = local_actor_url(http_prefix, nickname, domain)
    shared_items_collection_id = \
        id_str + '/' + shares_file_type + '?page=' + str(page_number)
    shares = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': shared_items_collection_id,
        'orderedItems': [],
        'partOf': id_str + '/' + shares_file_type,
        'sharedItemsOf': id_str,
        'totalItems': 0,
        'type': 'OrderedCollectionPage'
    }

    if not os.path.isfile(shares_filename):
        return shares
    curr_page = 1
    page_ctr = 0
    total_ctr = 0

    shares_json = load_json(shares_filename)
    if shares_json:
        for item_id, item in shares_json.items():
            page_ctr += 1
            total_ctr += 1
            if curr_page == page_number:
                item['shareId'] = item_id
                shares['orderedItems'].append(item)
            if page_ctr >= shares_per_page:
                page_ctr = 0
                curr_page += 1
    shares['totalItems'] = total_ctr
    last_page = int(total_ctr / shares_per_page)
    last_page = max(last_page, 1)
    if next_page_number > last_page:
        shares['next'] = \
            local_actor_url(http_prefix, nickname, domain) + \
            '/' + shares_file_type + '?page=' + str(last_page)
    return shares


def send_share_via_server(base_dir, session,
                          from_nickname: str, password: str,
                          from_domain: str, from_port: int,
                          http_prefix: str, display_name: str,
                          summary: str, image_filename: str,
                          item_qty: float, item_type: str, item_category: str,
                          location: str, duration: str,
                          cached_webfingers: {}, person_cache: {},
                          debug: bool, project_version: str,
                          item_price: str, item_currency: str,
                          signing_priv_key_pem: str,
                          system_language: str,
                          mitm_servers: []) -> {}:
    """Creates an item share via c2s
    """
    if not session:
        print('WARN: No session for send_share_via_server')
        return 6

    # convert $4.23 to 4.23 USD
    new_item_price, new_item_currency = get_price_from_string(item_price)
    if new_item_price != item_price:
        item_price = new_item_price
        if not item_currency:
            if new_item_currency != item_currency:
                item_currency = new_item_currency

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = actor + '/followers'

    new_share_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Add',
        'actor': actor,
        'target': actor + '/shares',
        'object': {
            "type": "Offer",
            "displayName": display_name,
            "summary": summary,
            "itemQty": float(item_qty),
            "itemType": item_type,
            "category": item_category,
            "location": location,
            "duration": duration,
            "itemPrice": item_price,
            "itemCurrency": item_currency,
            'to': [to_url],
            'cc': [cc_url]
        },
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix,
                         cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: share webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: share webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     display_name, _) = get_person_box(signing_priv_key_pem,
                                       origin_domain,
                                       base_dir, session, wf_request,
                                       person_cache, project_version,
                                       http_prefix, from_nickname,
                                       from_domain, post_to_box,
                                       83653, system_language,
                                       mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: share no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: share no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    if image_filename:
        headers = {
            'host': from_domain,
            'Authorization': auth_header
        }
        inbox_url_str = inbox_url.replace('/' + post_to_box, '/shares')
        post_result = \
            post_image(session, image_filename, [], inbox_url_str,
                       headers, http_prefix, from_domain_full)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, new_share_json, [], inbox_url, headers, 30, True)
    if not post_result:
        if debug:
            print('DEBUG: POST share failed for c2s to ' + inbox_url)
#        return 5

    if debug:
        print('DEBUG: c2s POST share item success')

    return new_share_json


def send_undo_share_via_server(base_dir: str, session,
                               from_nickname: str, password: str,
                               from_domain: str, from_port: int,
                               http_prefix: str, display_name: str,
                               cached_webfingers: {}, person_cache: {},
                               debug: bool, project_version: str,
                               signing_priv_key_pem: str,
                               system_language: str,
                               mitm_servers: []) -> {}:
    """Undoes a share via c2s
    """
    if not session:
        print('WARN: No session for send_undo_share_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = actor + '/followers'

    undo_share_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Remove',
        'actor': actor,
        'target': actor + '/shares',
        'object': {
            "type": "Offer",
            "displayName": display_name,
            'to': [to_url],
            'cc': [cc_url]
        },
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: unshare webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: unshare webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     display_name, _) = get_person_box(signing_priv_key_pem,
                                       origin_domain,
                                       base_dir, session, wf_request,
                                       person_cache, project_version,
                                       http_prefix, from_nickname,
                                       from_domain, post_to_box,
                                       12663, system_language,
                                       mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: unshare no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: unshare no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, undo_share_json, [], inbox_url,
                  headers, 30, True)
    if not post_result:
        if debug:
            print('DEBUG: POST unshare failed for c2s to ' + inbox_url)
#        return 5

    if debug:
        print('DEBUG: c2s POST unshare success')

    return undo_share_json


def send_wanted_via_server(base_dir, session,
                           from_nickname: str, password: str,
                           from_domain: str, from_port: int,
                           http_prefix: str, display_name: str,
                           summary: str, image_filename: str,
                           item_qty: float, item_type: str, item_category: str,
                           location: str, duration: str,
                           cached_webfingers: {}, person_cache: {},
                           debug: bool, project_version: str,
                           item_max_price: str, item_currency: str,
                           signing_priv_key_pem: str,
                           system_language: str,
                           mitm_servers: []) -> {}:
    """Creates a wanted item via c2s
    """
    if not session:
        print('WARN: No session for send_wanted_via_server')
        return 6

    # convert $4.23 to 4.23 USD
    new_item_max_price, new_item_currency = \
        get_price_from_string(item_max_price)
    if new_item_max_price != item_max_price:
        item_max_price = new_item_max_price
        if not item_currency:
            if new_item_currency != item_currency:
                item_currency = new_item_currency

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = actor + '/followers'

    new_share_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Add',
        'actor': actor,
        'target': actor + '/wanted',
        'object': {
            "type": "Offer",
            "displayName": display_name,
            "summary": summary,
            "itemQty": float(item_qty),
            "itemType": item_type,
            "category": item_category,
            "location": location,
            "duration": duration,
            "itemPrice": item_max_price,
            "itemCurrency": item_currency,
            'to': [to_url],
            'cc': [cc_url]
        },
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix,
                         cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: share webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: wanted webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     display_name, _) = get_person_box(signing_priv_key_pem,
                                       origin_domain,
                                       base_dir, session, wf_request,
                                       person_cache, project_version,
                                       http_prefix, from_nickname,
                                       from_domain, post_to_box,
                                       23653, system_language,
                                       mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: wanted no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: wanted no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    if image_filename:
        headers = {
            'host': from_domain,
            'Authorization': auth_header
        }
        inbox_url_str = inbox_url.replace('/' + post_to_box, '/wanted')
        post_result = \
            post_image(session, image_filename, [], inbox_url_str,
                       headers, http_prefix, from_domain_full)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, new_share_json, [], inbox_url, headers, 30, True)
    if not post_result:
        if debug:
            print('DEBUG: POST wanted failed for c2s to ' + inbox_url)
#        return 5

    if debug:
        print('DEBUG: c2s POST wanted item success')

    return new_share_json


def send_undo_wanted_via_server(base_dir: str, session,
                                from_nickname: str, password: str,
                                from_domain: str, from_port: int,
                                http_prefix: str, display_name: str,
                                cached_webfingers: {}, person_cache: {},
                                debug: bool, project_version: str,
                                signing_priv_key_pem: str,
                                system_language: str,
                                mitm_servers: []) -> {}:
    """Undoes a wanted item via c2s
    """
    if not session:
        print('WARN: No session for send_undo_wanted_via_server')
        return 6

    from_domain_full = get_full_domain(from_domain, from_port)

    actor = local_actor_url(http_prefix, from_nickname, from_domain_full)
    to_url = 'https://www.w3.org/ns/activitystreams#Public'
    cc_url = actor + '/followers'

    undo_share_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'type': 'Remove',
        'actor': actor,
        'target': actor + '/wanted',
        'object': {
            "type": "Offer",
            "displayName": display_name,
            'to': [to_url],
            'cc': [cc_url]
        },
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + from_domain_full + '/@' + from_nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix, cached_webfingers,
                         from_domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: unwant webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: unwant webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = from_domain
    (inbox_url, _, _, from_person_id, _, _,
     display_name, _) = get_person_box(signing_priv_key_pem,
                                       origin_domain,
                                       base_dir, session, wf_request,
                                       person_cache, project_version,
                                       http_prefix, from_nickname,
                                       from_domain, post_to_box,
                                       12693, system_language,
                                       mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: unwant no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: unwant no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(from_nickname, password)

    headers = {
        'host': from_domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, from_domain_full,
                  session, undo_share_json, [], inbox_url,
                  headers, 30, True)
    if not post_result:
        if debug:
            print('DEBUG: POST unwant failed for c2s to ' + inbox_url)
#        return 5

    if debug:
        print('DEBUG: c2s POST unwant success')

    return undo_share_json


def get_shared_items_catalog_via_server(session, nickname: str, password: str,
                                        domain: str, port: int,
                                        http_prefix: str, debug: bool,
                                        signing_priv_key_pem: str,
                                        mitm_servers: []) -> {}:
    """Returns the shared items catalog via c2s
    """
    if not session:
        print('WARN: No session for get_shared_items_catalog_via_server')
        return 6

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header,
        'Accept': 'application/json'
    }
    domain_full = get_full_domain(domain, port)
    url = local_actor_url(http_prefix, nickname, domain_full) + '/catalog'
    if debug:
        print('Shared items catalog request to: ' + url)
    catalog_json = get_json(signing_priv_key_pem, session, url, headers, None,
                            debug, mitm_servers, __version__, http_prefix,
                            None)
    if not get_json_valid(catalog_json):
        if debug:
            print('DEBUG: GET shared items catalog failed for c2s to ' + url)
#        return 5

    if debug:
        print('DEBUG: c2s GET shared items catalog success')

    return catalog_json


def get_offers_via_server(session, nickname: str, password: str,
                          domain: str, port: int,
                          http_prefix: str, debug: bool,
                          signing_priv_key_pem: str,
                          mitm_servers: []) -> {}:
    """Returns the offers collection for shared items via c2s
    """
    if not session:
        print('WARN: No session for get_offers_via_server')
        return 6

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header,
        'Accept': 'application/json'
    }
    domain_full = get_full_domain(domain, port)
    url = local_actor_url(http_prefix, nickname, domain_full) + '/offers'
    if debug:
        print('Offers collection request to: ' + url)
    offers_json = get_json(signing_priv_key_pem, session, url, headers, None,
                           debug, mitm_servers, __version__, http_prefix, None)
    if not get_json_valid(offers_json):
        if debug:
            print('DEBUG: GET offers collection failed for c2s to ' + url)
#        return 5

    if debug:
        print('DEBUG: c2s GET offers collection success')

    return offers_json


def get_wanted_via_server(session, nickname: str, password: str,
                          domain: str, port: int,
                          http_prefix: str, debug: bool,
                          signing_priv_key_pem: str,
                          mitm_servers: []) -> {}:
    """Returns the wanted collection for shared items via c2s
    """
    if not session:
        print('WARN: No session for get_wanted_via_server')
        return 6

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header,
        'Accept': 'application/json'
    }
    domain_full = get_full_domain(domain, port)
    url = local_actor_url(http_prefix, nickname, domain_full) + '/wanted'
    if debug:
        print('Wanted collection request to: ' + url)
    wanted_json = get_json(signing_priv_key_pem, session, url, headers, None,
                           debug, mitm_servers, __version__, http_prefix, None)
    if not get_json_valid(wanted_json):
        if debug:
            print('DEBUG: GET wanted collection failed for c2s to ' + url)
#        return 5

    if debug:
        print('DEBUG: c2s GET wanted collection success')

    return wanted_json


def outbox_share_upload(base_dir: str, http_prefix: str,
                        nickname: str, domain: str, port: int,
                        message_json: {}, debug: bool, city: str,
                        system_language: str, translate: {},
                        low_bandwidth: bool,
                        content_license_url: str,
                        block_federated: []) -> None:
    """ When a shared item is received by the outbox from c2s
    """
    if not message_json.get('type'):
        return
    if not message_json['type'] == 'Add':
        return
    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'Offer':
        if debug:
            print('DEBUG: not an Offer activity')
        return
    if not message_json['object'].get('displayName'):
        if debug:
            print('DEBUG: displayName missing from Offer')
        return
    if not message_json['object'].get('summary'):
        if debug:
            print('DEBUG: summary missing from Offer')
        return
    if not message_json['object'].get('itemQty'):
        if debug:
            print('DEBUG: itemQty missing from Offer')
        return
    if not message_json['object'].get('itemType'):
        if debug:
            print('DEBUG: itemType missing from Offer')
        return
    if not message_json['object'].get('category'):
        if debug:
            print('DEBUG: category missing from Offer')
        return
    if not message_json['object'].get('duration'):
        if debug:
            print('DEBUG: duration missing from Offer')
        return
    item_qty = float(message_json['object']['itemQty'])
    location = ''
    if message_json['object'].get('location'):
        location = message_json['object']['location']
    image_filename = None
    if message_json['object'].get('image_filename'):
        image_filename = message_json['object']['image_filename']
    if debug:
        print('Adding shared item')
        pprint(message_json)

    add_share(base_dir,
              http_prefix, nickname, domain, port,
              message_json['object']['displayName'],
              message_json['object']['summary'],
              image_filename,
              item_qty,
              message_json['object']['itemType'],
              message_json['object']['category'],
              location,
              message_json['object']['duration'],
              debug, city,
              message_json['object']['itemPrice'],
              message_json['object']['itemCurrency'],
              system_language, translate, 'shares',
              low_bandwidth, content_license_url,
              False, block_federated)
    if debug:
        print('DEBUG: shared item received via c2s')


def outbox_undo_share_upload(base_dir: str, nickname: str, domain: str,
                             message_json: {}, debug: bool) -> None:
    """ When a shared item is removed via c2s
    """
    if not message_json.get('type'):
        return
    if not message_json['type'] == 'Remove':
        return
    if not has_object_string_type(message_json, debug):
        return
    if not message_json['object']['type'] == 'Offer':
        if debug:
            print('DEBUG: not an Offer activity')
        return
    if not message_json['object'].get('displayName'):
        if debug:
            print('DEBUG: displayName missing from Offer')
        return
    remove_shared_item2(base_dir, nickname, domain,
                        message_json['object']['displayName'],
                        'shares')
    if debug:
        print('DEBUG: shared item removed via c2s')


def _shares_catalog_params(path: str) -> (bool, float, float, str):
    """Returns parameters when accessing the shares catalog
    """
    today = False
    min_price = 0
    max_price = 9999999
    match_pattern = None
    if '?' not in path:
        return today, min_price, max_price, match_pattern
    args = path.split('?', 1)[1]
    arg_list = args.split(';')
    for arg in arg_list:
        if '=' not in arg:
            continue
        key = arg.split('=')[0].lower()
        value = arg.split('=')[1]
        if key == 'today':
            value = value.lower()
            if 't' in value or 'y' in value or '1' in value:
                today = True
        elif key.startswith('min'):
            if is_float(value):
                min_price = float(value)
        elif key.startswith('max'):
            if is_float(value):
                max_price = float(value)
        elif key.startswith('match'):
            match_pattern = value
    return today, min_price, max_price, match_pattern


def shares_catalog_account_endpoint(base_dir: str, http_prefix: str,
                                    nickname: str, domain: str,
                                    domain_full: str,
                                    path: str, debug: bool,
                                    shares_file_type: str) -> {}:
    """Returns the endpoint for the shares catalog of a particular account
    See https://github.com/datafoodconsortium/ontology
    Also the subdirectory ontology/DFC
    """
    today, min_price, max_price, match_pattern = _shares_catalog_params(path)
    dfc_url = \
        http_prefix + '://' + domain_full + '/ontologies/DFC_FullModel.owl#'
    dfc_pt_url = \
        http_prefix + '://' + domain_full + \
        '/ontologies/DFC_ProductGlossary.rdf#'
    owner = local_actor_url(http_prefix, nickname, domain_full)
    if shares_file_type == 'shares':
        dfc_instance_id = owner + '/catalog'
    else:
        dfc_instance_id = owner + '/wantedItems'
    endpoint = {
        "@context": {
            "DFC": dfc_url,
            "dfc-pt": dfc_pt_url,
            "@base": "http://maPlateformeNationale"
        },
        "@id": dfc_instance_id,
        "@type": "DFC:Entreprise",
        "DFC:supplies": []
    }

    curr_date = date_utcnow()
    curr_date_str = curr_date.strftime("%Y-%m-%d")

    shares_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + shares_file_type + '.json'
    if not os.path.isfile(shares_filename):
        if debug:
            print(shares_file_type + '.json file not found: ' +
                  shares_filename)
        return endpoint
    shares_json = load_json(shares_filename)
    if not shares_json:
        if debug:
            print('Unable to load json for ' + shares_filename)
        return endpoint

    for item_id, item in shares_json.items():
        if not item.get('dfcId'):
            if debug:
                print('Item does not have dfcId: ' + item_id)
            continue
        if '#' not in item['dfcId']:
            continue
        if today:
            if not item['published'].startswith(curr_date_str):
                continue
        if min_price is not None:
            if float(item['itemPrice']) < min_price:
                continue
        if max_price is not None:
            if float(item['itemPrice']) > max_price:
                continue
        description = item['displayName'] + ': ' + item['summary']
        if match_pattern:
            if not re.match(match_pattern, description):
                continue

        expire_date = datetime.datetime.fromtimestamp(item['expire'],
                                                      datetime.timezone.utc)
        expire_date_str = expire_date.strftime("%Y-%m-%dT%H:%M:%SZ")

        share_id = _get_valid_shared_item_id(owner, item['displayName'])
        if item['dfcId'].startswith('epicyon#'):
            dfc_id = "epicyon:" + item['dfcId'].split('#')[1]
        else:
            dfc_id = "dfc-pt:" + item['dfcId'].split('#')[1]
        price_str = item['itemPrice'] + ' ' + item['itemCurrency']
        catalog_item = {
            "@id": share_id,
            "@type": "DFC:SuppliedProduct",
            "DFC:hasType": dfc_id,
            "DFC:startDate": item['published'],
            "DFC:expiryDate": expire_date_str,
            "DFC:quantity": float(item['itemQty']),
            "DFC:price": price_str,
            "DFC:Image": item['imageUrl'],
            "DFC:description": description
        }
        endpoint['DFC:supplies'].append(catalog_item)

    return endpoint


def shares_catalog_endpoint(base_dir: str, http_prefix: str,
                            domain_full: str,
                            path: str, shares_file_type: str) -> {}:
    """Returns the endpoint for the shares catalog for the instance
    See https://github.com/datafoodconsortium/ontology
    Also the subdirectory ontology/DFC
    """
    today, min_price, max_price, match_pattern = _shares_catalog_params(path)
    dfc_url = \
        http_prefix + '://' + domain_full + '/ontologies/DFC_FullModel.owl#'
    dfc_pt_url = \
        http_prefix + '://' + domain_full + \
        '/ontologies/DFC_ProductGlossary.rdf#'
    dfc_instance_id = http_prefix + '://' + domain_full + '/catalog'
    endpoint = {
        "@context": {
            "DFC": dfc_url,
            "dfc-pt": dfc_pt_url,
            "@base": "http://maPlateformeNationale"
        },
        "@id": dfc_instance_id,
        "@type": "DFC:Entreprise",
        "DFC:supplies": []
    }

    curr_date = date_utcnow()
    curr_date_str = curr_date.strftime("%Y-%m-%d")

    dir_str = data_dir(base_dir)
    for _, dirs, _ in os.walk(dir_str):
        for acct in dirs:
            if not is_account_dir(acct):
                continue
            nickname = acct.split('@')[0]
            domain = acct.split('@')[1]
            owner = local_actor_url(http_prefix, nickname, domain_full)

            shares_filename = \
                acct_dir(base_dir, nickname, domain) + '/' + \
                shares_file_type + '.json'
            if not os.path.isfile(shares_filename):
                continue
            print('Test 78363 ' + shares_filename)
            shares_json = load_json(shares_filename)
            if not shares_json:
                continue

            for _, item in shares_json.items():
                if not item.get('dfcId'):
                    continue
                if '#' not in item['dfcId']:
                    continue
                if today:
                    if not item['published'].startswith(curr_date_str):
                        continue
                if min_price is not None:
                    if float(item['itemPrice']) < min_price:
                        continue
                if max_price is not None:
                    if float(item['itemPrice']) > max_price:
                        continue
                description = item['displayName'] + ': ' + item['summary']
                if match_pattern:
                    if not re.match(match_pattern, description):
                        continue

                start_date_str = date_seconds_to_string(item['published'])
                expire_date_str = date_seconds_to_string(item['expire'])
                share_id = \
                    _get_valid_shared_item_id(owner, item['displayName'])
                if item['dfcId'].startswith('epicyon#'):
                    dfc_id = "epicyon:" + item['dfcId'].split('#')[1]
                else:
                    dfc_id = "dfc-pt:" + item['dfcId'].split('#')[1]
                price_str = item['itemPrice'] + ' ' + item['itemCurrency']
                catalog_item = {
                    "@id": share_id,
                    "@type": "DFC:SuppliedProduct",
                    "DFC:hasType": dfc_id,
                    "DFC:startDate": start_date_str,
                    "DFC:expiryDate": expire_date_str,
                    "DFC:quantity": float(item['itemQty']),
                    "DFC:price": price_str,
                    "DFC:Image": item['imageUrl'],
                    "DFC:description": description
                }
                endpoint['DFC:supplies'].append(catalog_item)
        break

    return endpoint


def shares_catalog_csv_endpoint(base_dir: str, http_prefix: str,
                                domain_full: str,
                                path: str, shares_file_type: str) -> str:
    """Returns a CSV version of the shares catalog
    """
    catalog_json = \
        shares_catalog_endpoint(base_dir, http_prefix, domain_full, path,
                                shares_file_type)
    if not catalog_json:
        return ''
    if not catalog_json.get('DFC:supplies'):
        return ''
    csv_str = \
        'id,type,hasType,startDate,expiryDate,' + \
        'quantity,price,currency,Image,description,\n'
    for item in catalog_json['DFC:supplies']:
        csv_str += '"' + item['@id'] + '",'
        csv_str += '"' + item['@type'] + '",'
        csv_str += '"' + item['DFC:hasType'] + '",'
        csv_str += '"' + item['DFC:startDate'] + '",'
        csv_str += '"' + item['DFC:expiryDate'] + '",'
        csv_str += str(item['DFC:quantity']) + ','
        csv_str += item['DFC:price'].split(' ')[0] + ','
        csv_str += '"' + item['DFC:price'].split(' ')[1] + '",'
        if item.get('DFC:Image'):
            csv_str += '"' + item['DFC:Image'] + '",'
        description = item['DFC:description'].replace('"', "'")
        csv_str += '"' + description + '",\n'
    return csv_str


def generate_shared_item_federation_tokens(shared_items_federated_domains: [],
                                           base_dir: str) -> {}:
    """Generates tokens for shared item federated domains
    """
    if not shared_items_federated_domains:
        return {}

    tokens_json = {}
    if base_dir:
        tokens_filename = \
            data_dir(base_dir) + '/sharedItemsFederationTokens.json'
        if os.path.isfile(tokens_filename):
            tokens_json = load_json(tokens_filename)
            if tokens_json is None:
                tokens_json = {}

    tokens_added = False
    for domain_full in shared_items_federated_domains:
        if not tokens_json.get(domain_full):
            tokens_json[domain_full] = ''
            tokens_added = True

    if not tokens_added:
        return tokens_json
    if base_dir:
        save_json(tokens_json, tokens_filename)
    return tokens_json


def update_shared_item_federation_token(base_dir: str,
                                        token_domain_full: str, new_token: str,
                                        debug: bool,
                                        tokens_json: {} = None) -> {}:
    """Updates an individual token for shared item federation
    """
    if debug:
        print('Updating shared items token for ' + token_domain_full)
    if not tokens_json:
        tokens_json = {}
    if base_dir:
        tokens_filename = \
            data_dir(base_dir) + '/sharedItemsFederationTokens.json'
        if os.path.isfile(tokens_filename):
            if debug:
                print('Update loading tokens for ' + token_domain_full)
            tokens_json = load_json(tokens_filename)
            if tokens_json is None:
                tokens_json = {}
    update_required = False
    if tokens_json.get(token_domain_full):
        if tokens_json[token_domain_full] != new_token:
            update_required = True
    else:
        update_required = True
    if update_required:
        tokens_json[token_domain_full] = new_token
        if base_dir:
            save_json(tokens_json, tokens_filename)
    return tokens_json


def merge_shared_item_tokens(base_dir: str, domain_full: str,
                             new_shared_items_federated_domains: [],
                             tokens_json: {}) -> {}:
    """When the shared item federation domains list has changed, update
    the tokens dict accordingly
    """
    removals: list[str] = []
    changed = False
    for token_domain_full, _ in tokens_json.items():
        if domain_full:
            if token_domain_full.startswith(domain_full):
                continue
        if token_domain_full not in new_shared_items_federated_domains:
            removals.append(token_domain_full)
    # remove domains no longer in the federation list
    for token_domain_full in removals:
        del tokens_json[token_domain_full]
        changed = True
    # add new domains from the federation list
    for token_domain_full in new_shared_items_federated_domains:
        if token_domain_full not in tokens_json:
            tokens_json[token_domain_full] = ''
            changed = True
    if base_dir and changed:
        tokens_filename = \
            data_dir(base_dir) + '/sharedItemsFederationTokens.json'
        save_json(tokens_json, tokens_filename)
    return tokens_json


def create_shared_item_federation_token(base_dir: str,
                                        token_domain_full: str,
                                        force: bool,
                                        tokens_json: {} = None) -> {}:
    """Updates an individual token for shared item federation
    """
    if not tokens_json:
        tokens_json = {}
    if base_dir:
        tokens_filename = \
            data_dir(base_dir) + '/sharedItemsFederationTokens.json'
        if os.path.isfile(tokens_filename):
            tokens_json = load_json(tokens_filename)
            if tokens_json is None:
                tokens_json = {}
    if force or not tokens_json.get(token_domain_full):
        tokens_json[token_domain_full] = secrets.token_urlsafe(64)
        if base_dir:
            save_json(tokens_json, tokens_filename)
    return tokens_json


def authorize_shared_items(shared_items_federated_domains: [],
                           base_dir: str,
                           origin_domain_full: str,
                           calling_domain_full: str,
                           auth_header: str,
                           debug: bool,
                           tokens_json: {} = None) -> bool:
    """HTTP simple token check for shared item federation
    """
    if not shared_items_federated_domains:
        # no shared item federation
        return False
    if origin_domain_full not in shared_items_federated_domains:
        if debug:
            print(origin_domain_full +
                  ' is not in the shared items federation list ' +
                  str(shared_items_federated_domains))
        return False
    if 'Basic ' in auth_header:
        if debug:
            print('DEBUG: shared item federation should not use basic auth')
        return False
    provided_token = remove_eol(auth_header).strip()
    if not provided_token:
        if debug:
            print('DEBUG: shared item federation token is empty')
        return False
    if len(provided_token) < 60:
        if debug:
            print('DEBUG: shared item federation token is too small ' +
                  provided_token)
        return False
    if not tokens_json:
        tokens_filename = \
            data_dir(base_dir) + '/sharedItemsFederationTokens.json'
        if not os.path.isfile(tokens_filename):
            if debug:
                print('DEBUG: shared item federation tokens file missing ' +
                      tokens_filename)
            return False
        tokens_json = load_json(tokens_filename)
    if not tokens_json:
        return False
    if not tokens_json.get(calling_domain_full):
        if debug:
            print('DEBUG: shared item federation token ' +
                  'check failed for ' + calling_domain_full)
        return False
    if not constant_time_string_check(tokens_json[calling_domain_full],
                                      provided_token):
        if debug:
            print('DEBUG: shared item federation token ' +
                  'mismatch for ' + calling_domain_full)
        return False
    return True


def _update_federated_shares_cache(session, shared_items_federated_domains: [],
                                   base_dir: str, domain_full: str,
                                   http_prefix: str,
                                   tokens_json: {}, debug: bool,
                                   system_language: str,
                                   shares_file_type: str,
                                   sites_unavailable: [],
                                   mitm_servers: []) -> None:
    """Updates the cache of federated shares for the instance.
    This enables shared items to be available even when other instances
    might not be online
    """
    # create directories where catalogs will be stored
    cache_dir = base_dir + '/cache'
    if not os.path.isdir(cache_dir):
        os.mkdir(cache_dir)
    if shares_file_type == 'shares':
        catalogs_dir = cache_dir + '/catalogs'
    else:
        catalogs_dir = cache_dir + '/wantedItems'
    if not os.path.isdir(catalogs_dir):
        os.mkdir(catalogs_dir)

    as_header = {
        "Accept": "application/ld+json",
        "Origin": domain_full
    }
    for federated_domain_full in shared_items_federated_domains:
        # NOTE: federatedDomain does not have a port extension,
        # so may not work in some situations
        if federated_domain_full.startswith(domain_full):
            # only download from instances other than this one
            continue
        if not tokens_json.get(federated_domain_full):
            # token has been obtained for the other domain
            continue
        if not site_is_active(http_prefix + '://' + federated_domain_full, 10,
                              sites_unavailable):
            continue
        if shares_file_type == 'shares':
            url = http_prefix + '://' + federated_domain_full + '/catalog'
        else:
            url = http_prefix + '://' + federated_domain_full + '/wantedItems'
        as_header['Authorization'] = tokens_json[federated_domain_full]
        catalog_json = get_json(session, url, as_header, None,
                                debug, mitm_servers, __version__, http_prefix,
                                None)
        if not get_json_valid(catalog_json):
            print('WARN: failed to download shared items catalog for ' +
                  federated_domain_full)
            continue
        catalog_filename = catalogs_dir + '/' + federated_domain_full + '.json'
        if save_json(catalog_json, catalog_filename):
            print('Downloaded shared items catalog for ' +
                  federated_domain_full)
            shares_json = _dfc_to_shares_format(catalog_json,
                                                base_dir, system_language,
                                                http_prefix, domain_full)
            if shares_json:
                shares_filename = \
                    catalogs_dir + '/' + federated_domain_full + '.' + \
                    shares_file_type + '.json'
                save_json(shares_json, shares_filename)
                print('Converted shares catalog for ' + federated_domain_full)
        else:
            time.sleep(2)


def run_federated_shares_watchdog(project_version: str, httpd) -> None:
    """This tries to keep the federated shares update thread
    running even if it dies
    """
    print('THREAD: Starting federated shares watchdog')
    federated_shares_original = \
        httpd.thrPostSchedule.clone(run_federated_shares_daemon)
    begin_thread(httpd.thrFederatedSharesDaemon,
                 'run_federated_shares_watchdog')
    while True:
        time.sleep(55)
        if httpd.thrFederatedSharesDaemon.is_alive():
            continue
        httpd.thrFederatedSharesDaemon.kill()
        print('THREAD: restarting federated shares watchdog')
        httpd.thrFederatedSharesDaemon = \
            federated_shares_original.clone(run_federated_shares_daemon)
        begin_thread(httpd.thrFederatedSharesDaemon,
                     'run_federated_shares_watchdog 2')
        print('Restarting federated shares daemon...')


def _generate_next_shares_token_update(base_dir: str,
                                       min_days: int, max_days: int) -> None:
    """Creates a file containing the next date when the shared items token
    for this instance will be updated
    """
    token_update_dir = data_dir(base_dir)
    if not os.path.isdir(base_dir):
        os.mkdir(base_dir)
    if not os.path.isdir(token_update_dir):
        os.mkdir(token_update_dir)
    token_update_filename = token_update_dir + '/.tokenUpdate'
    next_update_sec = None
    if os.path.isfile(token_update_filename):
        try:
            with open(token_update_filename, 'r', encoding='utf-8') as fp_tok:
                next_update_str = fp_tok.read()
                if next_update_str:
                    if next_update_str.isdigit():
                        next_update_sec = int(next_update_str)
        except OSError:
            print('EX: _generate_next_shares_token_update unable to read ' +
                  token_update_filename)
    curr_time = int(time.time())
    updated = False
    if next_update_sec:
        if curr_time > next_update_sec:
            next_update_days = randint(min_days, max_days)
            next_update_interval = int(60 * 60 * 24 * next_update_days)
            next_update_sec += next_update_interval
            updated = True
    else:
        next_update_days = randint(min_days, max_days)
        next_update_interval = int(60 * 60 * 24 * next_update_days)
        next_update_sec = curr_time + next_update_interval
        updated = True
    if updated:
        try:
            with open(token_update_filename, 'w+', encoding='utf-8') as fp_tok:
                fp_tok.write(str(next_update_sec))
        except OSError:
            print('EX: _generate_next_shares_token_update unable to write' +
                  token_update_filename)


def _regenerate_shares_token(base_dir: str, domain_full: str,
                             min_days: int, max_days: int, httpd) -> None:
    """Occasionally the shared items token for your instance is updated.
    Scenario:
      - You share items with $FriendlyInstance
      - Some time later under new management
        $FriendlyInstance becomes $HostileInstance
      - You block $HostileInstance and remove them from your
        federated shares domains list
      - $HostileInstance still knows your shared items token,
        and can still have access to your shared items if it presents a
        spoofed Origin header together with the token
    By rotating the token occasionally $HostileInstance will eventually
    lose access to your federated shares. If other instances within your
    federated shares list of domains continue to follow and communicate
    then they will receive the new token automatically
    """
    token_update_filename = data_dir(base_dir) + '/.tokenUpdate'
    if not os.path.isfile(token_update_filename):
        return
    next_update_sec = None
    try:
        with open(token_update_filename, 'r', encoding='utf-8') as fp_tok:
            next_update_str = fp_tok.read()
            if next_update_str:
                if next_update_str.isdigit():
                    next_update_sec = int(next_update_str)
    except OSError:
        print('EX: _regenerate_shares_token unable to read ' +
              token_update_filename)
    if not next_update_sec:
        return
    curr_time = int(time.time())
    if curr_time <= next_update_sec:
        return
    create_shared_item_federation_token(base_dir, domain_full, True, None)
    _generate_next_shares_token_update(base_dir, min_days, max_days)
    # update the tokens used within the daemon
    shared_fed_domains = httpd.shared_items_federated_domains
    httpd.shared_item_federation_tokens = \
        generate_shared_item_federation_tokens(shared_fed_domains,
                                               base_dir)


def run_federated_shares_daemon(base_dir: str, httpd, http_prefix: str,
                                domain_full: str, proxy_type: str, debug: bool,
                                system_language: str,
                                mitm_servers: []) -> None:
    """Runs the daemon used to update federated shared items
    """
    seconds_per_hour = 60 * 60
    file_check_interval_sec = 120
    time.sleep(60)
    # the token for this instance will be changed every 7-14 days
    min_days = 7
    max_days = 14
    _generate_next_shares_token_update(base_dir, min_days, max_days)
    sites_unavailable: list[str] = []
    while True:
        shared_items_federated_domains_str = \
            get_config_param(base_dir, 'sharedItemsFederatedDomains')
        if not shared_items_federated_domains_str:
            time.sleep(file_check_interval_sec)
            continue

        # occasionally change the federated shared items token
        # for this instance
        _regenerate_shares_token(base_dir, domain_full,
                                 min_days, max_days, httpd)

        # get a list of the domains within the shared items federation
        shared_items_federated_domains: list[str] = []
        fed_domains_list = \
            shared_items_federated_domains_str.split(',')
        for shared_fed_domain in fed_domains_list:
            shared_items_federated_domains.append(shared_fed_domain.strip())
        if not shared_items_federated_domains:
            time.sleep(file_check_interval_sec)
            continue

        # load the tokens
        tokens_filename = \
            data_dir(base_dir) + '/sharedItemsFederationTokens.json'
        if not os.path.isfile(tokens_filename):
            time.sleep(file_check_interval_sec)
            continue
        tokens_json = load_json(tokens_filename)
        if not tokens_json:
            time.sleep(file_check_interval_sec)
            continue

        session = create_session(proxy_type)
        for shares_file_type in get_shares_files_list():
            _update_federated_shares_cache(session,
                                           shared_items_federated_domains,
                                           base_dir, domain_full, http_prefix,
                                           tokens_json, debug, system_language,
                                           shares_file_type, sites_unavailable,
                                           mitm_servers)
        time.sleep(seconds_per_hour * 6)


def _dfc_to_shares_format(catalog_json: {},
                          base_dir: str, system_language: str,
                          http_prefix: str, domain_full: str) -> {}:
    """Converts DFC format into the internal formal used to store shared items.
    This simplifies subsequent search and display
    """
    if not catalog_json.get('DFC:supplies'):
        return {}
    shares_json = {}

    dfc_ids = {}
    product_types_list = get_category_types(base_dir)
    for product_type in product_types_list:
        dfc_ids[product_type] = \
            _load_dfc_ids(base_dir, system_language, product_type,
                          http_prefix, domain_full)

    curr_time = int(time.time())
    for item in catalog_json['DFC:supplies']:
        if not item.get('@id') or \
           not item.get('@type') or \
           not item.get('DFC:hasType') or \
           not item.get('DFC:startDate') or \
           not item.get('DFC:expiryDate') or \
           not item.get('DFC:quantity') or \
           not item.get('DFC:price') or \
           not item.get('DFC:description'):
            continue

        if ' ' not in item['DFC:price']:
            continue
        if ':' not in item['DFC:description']:
            continue
        if ':' not in item['DFC:hasType']:
            continue

        start_time_sec = date_string_to_seconds(item['DFC:startDate'])
        if not start_time_sec:
            continue
        expiry_time_sec = date_string_to_seconds(item['DFC:expiryDate'])
        if not expiry_time_sec:
            continue
        if expiry_time_sec < curr_time:
            # has expired
            continue

        if item['DFC:hasType'].startswith('epicyon:'):
            item_type = item['DFC:hasType'].split(':')[1]
            item_type = item_type.replace('_', ' ')
            item_category = 'non-food'
            product_type = None
        else:
            has_type = item['DFC:hasType'].split(':')[1]
            item_type = None
            product_type = None
            for prod_type in product_types_list:
                item_type = \
                    _getshare_type_from_dfc_id(has_type, dfc_ids[prod_type])
                if item_type:
                    product_type = prod_type
                    break
            item_category = 'food'
        if not item_type:
            continue

        all_text = \
            item['DFC:description'] + ' ' + item_type + ' ' + item_category
        if is_filtered_globally(base_dir, all_text, system_language):
            continue

        dfc_id = None
        if product_type:
            dfc_id = dfc_ids[product_type][item_type]
        item_id = item['@id']
        description = item['DFC:description'].split(':', 1)[1].strip()

        image_url = ''
        if item.get('DFC:Image'):
            image_url = item['DFC:Image']
        shares_json[item_id] = {
            "displayName": item['DFC:description'].split(':')[0],
            "summary": description,
            "imageUrl": image_url,
            "itemQty": float(item['DFC:quantity']),
            "dfcId": dfc_id,
            "itemType": item_type,
            "category": item_category,
            "location": "",
            "published": start_time_sec,
            "expire": expiry_time_sec,
            "itemPrice": item['DFC:price'].split(' ')[0],
            "itemCurrency": item['DFC:price'].split(' ')[1],
            "shareOnProfile": False
        }
    return shares_json


def share_category_icon(category: str) -> str:
    """Returns unicode icon for the given category
    """
    category_icons = {
        'accommodation': '🏠',
        'clothes':  '👚',
        'tools': '🔧',
        'food': '🍏'
    }
    if category_icons.get(category):
        return category_icons[category]
    return ''


def _currency_to_wikidata(currency_type: str) -> str:
    """Converts a currency type, such as USD, into a wikidata reference
    """
    currencies = {
        "GBP": "https://www.wikidata.org/wiki/Q25224",
        "EUR": "https://www.wikidata.org/wiki/Q4916",
        "CAD": "https://www.wikidata.org/wiki/Q1104069",
        "USD": "https://www.wikidata.org/wiki/Q4917",
        "AUD": "https://www.wikidata.org/wiki/Q259502",
        "PKR": "https://www.wikidata.org/wiki/Q188289",
        "PEN": "https://www.wikidata.org/wiki/Q204656",
        "PAB": "https://www.wikidata.org/wiki/Q210472",
        "PHP": "https://www.wikidata.org/wiki/Q17193",
        "RWF": "https://www.wikidata.org/wiki/Q4741",
        "NZD": "https://www.wikidata.org/wiki/Q1472704",
        "MXN": "https://www.wikidata.org/wiki/Q4730",
        "JMD": "https://www.wikidata.org/wiki/Q209792",
        "ISK": "https://www.wikidata.org/wiki/Q131473",
        "EGP": "https://www.wikidata.org/wiki/Q199462",
        "CNY": "https://www.wikidata.org/wiki/Q39099",
        "AFN": "https://www.wikidata.org/wiki/Q199471",
        "AWG": "https://www.wikidata.org/wiki/Q232270",
        "AZN": "https://www.wikidata.org/wiki/Q483725",
        "BYN": "https://www.wikidata.org/wiki/Q21531507",
        "BZD": "https://www.wikidata.org/wiki/Q275112",
        "BOB": "https://www.wikidata.org/wiki/Q200737",
        "BAM": "https://www.wikidata.org/wiki/Q179620",
        "BWP": "https://www.wikidata.org/wiki/Q186794",
        "BGN": "https://www.wikidata.org/wiki/Q172540",
        "BRL": "https://www.wikidata.org/wiki/Q173117",
        "KHR": "https://www.wikidata.org/wiki/Q204737",
        "UYU": "https://www.wikidata.org/wiki/Q209272",
        "DOP": "https://www.wikidata.org/wiki/Q242922",
        "CRC": "https://www.wikidata.org/wiki/Q242915",
        "HRK": "https://www.wikidata.org/wiki/Q595634",
        "CUP": "https://www.wikidata.org/wiki/Q201505",
        "CZK": "https://www.wikidata.org/wiki/Q131016",
        "NOK": "https://www.wikidata.org/wiki/Q132643",
        "GHS": "https://www.wikidata.org/wiki/Q183530",
        "GTQ": "https://www.wikidata.org/wiki/Q207396",
        "HNL": "https://www.wikidata.org/wiki/Q4719",
        "HUF": "https://www.wikidata.org/wiki/Q47190",
        "IDR": "https://www.wikidata.org/wiki/Q41588",
        "INR": "https://www.wikidata.org/wiki/Q80524",
        "IRR": "https://www.wikidata.org/wiki/Q188608",
        "ILS": "https://www.wikidata.org/wiki/Q131309",
        "JPY": "https://www.wikidata.org/wiki/Q8146",
        "KRW": "https://www.wikidata.org/wiki/Q202040",
        "LAK": "https://www.wikidata.org/wiki/Q200055",
        "MKD": "https://www.wikidata.org/wiki/Q177875",
        "MYR": "https://www.wikidata.org/wiki/Q163712",
        "MUR": "https://www.wikidata.org/wiki/Q212967",
        "MNT": "https://www.wikidata.org/wiki/Q183435",
        "MZN": "https://www.wikidata.org/wiki/Q200753",
        "NIO": "https://www.wikidata.org/wiki/Q207312",
        "NGN": "https://www.wikidata.org/wiki/Q203567",
        "PYG": "https://www.wikidata.org/wiki/Q207514",
        "PLN": "https://www.wikidata.org/wiki/Q123213",
        "RON": "https://www.wikidata.org/wiki/Q131645",
        "RUB": "https://www.wikidata.org/wiki/Q41044",
        "RSD": "https://www.wikidata.org/wiki/Q172524",
        "SOS": "https://www.wikidata.org/wiki/Q4603",
        "ZAR": "https://www.wikidata.org/wiki/Q181907",
        "CHF": "https://www.wikidata.org/wiki/Q25344",
        "TWD": "https://www.wikidata.org/wiki/Q208526",
        "THB": "https://www.wikidata.org/wiki/Q177882",
        "TTD": "https://www.wikidata.org/wiki/Q242890",
        "UAH": "https://www.wikidata.org/wiki/Q81893",
        "VES": "https://www.wikidata.org/wiki/Q56349362",
        "VEB": "https://www.wikidata.org/wiki/Q56349362",
        "VND": "https://www.wikidata.org/wiki/Q192090"
    }
    currency_type = currency_type.upper()
    for curr, curr_url in currencies.items():
        if curr in currency_type:
            return curr_url
    return "https://www.wikidata.org/wiki/Q25224"


def _vf_share_id(share_id: str) -> str:
    """returns the share id
    """
    share_id = share_id.replace('___', '://')
    return share_id.replace('--', '/')


def vf_proposal_from_share(shared_item: {}, share_type: str) -> {}:
    """Returns a ValueFlows proposal from a shared item
    """
    if not shared_item.get('shareId'):
        return {}
    om2_link = \
        "http://www.ontology-of-units-of-measure.org/resource/om-2/"
    share_id = _vf_share_id(shared_item['shareId'])
    published = date_seconds_to_string(shared_item['published'])
    actor_url = get_actor_from_post(shared_item)
    offer_item = {
        "@context": [
            "https://www.w3.org/ns/activitystreams",
            {
                "om2": om2_link,
                "vf": "https://w3id.org/valueflows/ont/vf#",
                "Proposal": "vf:Proposal",
                "Intent": "vf:Intent",
                "action": "vf:action",
                "purpose": "vf:purpose",
                "unitBased": "vf:unitBased",
                "publishes": "vf:publishes",
                "reciprocal": "vf:reciprocal",
                "resourceConformsTo": "vf:resourceConformsTo",
                "resourceQuantity": "vf:resourceQuantity",
                "hasUnit": "om2:hasUnit",
                "hasNumericalValue": "om2:hasNumericalValue"
            }
        ],
        "type": "Proposal",
        "purpose": share_type,
        "id": share_id,
        "attributedTo": actor_url,
        "name": shared_item['displayName'],
        "content": shared_item['summary'],
        "published": published,
        "publishes": {
            "type": "Intent",
            "id": share_id + '#primary',
            "action": "transfer",
            "resourceQuantity": {
                "hasUnit": "one",
                "hasNumericalValue": str(shared_item['itemQty'])
            },
        },
        "attachment": [],
        "unitBased": False,
        "to": "https://www.w3.org/ns/activitystreams#Public"
    }
    if shared_item.get('dfcId'):
        offer_item['publishes']['resourceConformsTo'] = \
            shared_item['dfcId']
    if shared_item['category']:
        offer_item['attachment'].append({
            "type": "PropertyValue",
            "name": "category",
            "value": shared_item['category']
        })
    if shared_item['location']:
        # pixelfed style representation of location
        offer_item['location'] = {
            "type": "Place",
            "name": shared_item['location'].title()
        }
    if shared_item['imageUrl']:
        if resembles_url(shared_item['imageUrl']):
            file_extension = None
            accepted_types = get_media_extensions()
            for mtype in accepted_types:
                if shared_item['imageUrl'].endswith('.' + mtype):
                    if mtype == 'jpg':
                        mtype = 'jpeg'
                    if mtype == 'mp3':
                        mtype = 'mpeg'
                    file_extension = mtype
            if file_extension:
                media_type = 'image/' + file_extension
                shared_item_url = remove_html(shared_item['imageUrl'])
                offer_item['attachment'].append({
                    'mediaType': media_type,
                    'name': shared_item['displayName'],
                    'type': 'Document',
                    'url': shared_item_url
                })
    if shared_item['itemPrice'] and shared_item['itemCurrency']:
        currency_url = _currency_to_wikidata(shared_item['itemCurrency'])
        offer_item['reciprocal'] = {
            "type": "Intent",
            "id": share_id + '#reciprocal',
            "action": "transfer",
            "resourceConformsTo": currency_url,
            "resourceQuantity": {
                "hasUnit": "one",
                "hasNumericalValue": str(shared_item['itemPrice'])
            }
        }
    return offer_item


def get_share_category(base_dir: str, nickname: str, domain: str,
                       shares_file_type: str, share_id: str) -> str:
    """Returns the category for a shared item
    """
    shares_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + shares_file_type + '.json'
    if not os.path.isfile(shares_filename):
        return ''

    shares_json = load_json(shares_filename)
    if not shares_json:
        return ''
    if not shares_json.get(share_id):
        return ''
    if not shares_json[share_id].get('category'):
        return ''
    return shares_json[share_id]['category']


def vf_proposal_from_id(base_dir: str, nickname: str, domain: str,
                        shares_file_type: str, share_id: str,
                        actor: str) -> {}:
    """Returns a ValueFlows proposal from a shared item id
    """
    shares_filename = \
        acct_dir(base_dir, nickname, domain) + '/' + shares_file_type + '.json'
    if not os.path.isfile(shares_filename):
        print('DEBUG: vf_proposal_from_id file not found ' + shares_filename)
        return {}

    shares_json = load_json(shares_filename)
    if not shares_json:
        print('DEBUG: vf_proposal_from_id file not loaded ' + shares_filename)
        return {}
    if not shares_json.get(share_id):
        print('DEBUG: vf_proposal_from_id does not contain id ' + share_id)
        return {}
    if shares_file_type == 'shares':
        share_type = 'offer'
    else:
        share_type = 'request'
    shares_json[share_id]['shareId'] = share_id
    shares_json[share_id]['actor'] = actor
    return vf_proposal_from_share(shares_json[share_id],
                                  share_type)


def _is_valueflows_attachment(attach_item: {}) -> bool:
    """Returns true if the given item is a ValueFlows entry
    within the actor attachment list
    """
    if 'rel' not in attach_item or \
       'href' not in attach_item or \
       'name' not in attach_item:
        return False
    if not isinstance(attach_item['rel'], list):
        return False
    if not isinstance(attach_item['name'], str):
        return False
    if not isinstance(attach_item['href'], str):
        return False
    if len(attach_item['rel']) != 2:
        return False
    if len(attach_item['name']) <= 1:
        return False
    if attach_item['rel'][0] == 'payment' and \
       attach_item['rel'][1].endswith('/valueflows/Proposal'):
        if not dangerous_markup(attach_item['href'], False, []):
            return True
    return False


def actor_attached_shares(actor_json: {}) -> []:
    """Returns any shared items attached to an actor
    https://codeberg.org/fediverse/fep/src/branch/main/fep/0837/fep-0837.md
    """
    if not actor_json.get('attachment'):
        return []
    if not isinstance(actor_json['attachment'], list):
        return []

    attached_shares: list[str] = []
    for attach_item in actor_json['attachment']:
        if _is_valueflows_attachment(attach_item):
            attached_shares.append(attach_item['href'])
    return attached_shares


def actor_attached_shares_as_html(actor_json: {},
                                  max_shares_on_profile: int) -> str:
    """Returns html for any shared items attached to an actor
    https://codeberg.org/fediverse/fep/src/branch/main/fep/0837/fep-0837.md
    """
    if not actor_json.get('attachment') or \
       max_shares_on_profile == 0:
        return ''

    html_str = ''
    ctr = 0
    for attach_item in actor_json['attachment']:
        if not _is_valueflows_attachment(attach_item):
            continue
        if not html_str:
            html_str = '<ul>\n'
        html_str += \
            '  <li><a href="' + attach_item['href'] + '" tabindex="1">' + \
            remove_html(attach_item['name']) + '</a></li>\n'
        ctr += 1
        if ctr >= max_shares_on_profile:
            break
    if html_str:
        html_str = html_str.strip() + '</ul>\n'
    return html_str


def add_shares_to_actor(base_dir: str,
                        nickname: str, domain: str,
                        actor_json: {},
                        max_shares_on_profile: int) -> bool:
    """Adds shared items to the given actor attachments
    https://codeberg.org/fediverse/fep/src/branch/main/fep/0837/fep-0837.md
    """
    if 'attachment' not in actor_json:
        actor_json['attachment']: list[dict] = []
    changed = False

    # remove any existing ValueFlows items from attachment list
    new_attachment: list[dict] = []
    for attach_item in actor_json['attachment']:
        is_proposal = False
        if _is_valueflows_attachment(attach_item):
            changed = True
            is_proposal = True
        if not is_proposal:
            new_attachment.append(attach_item)
    actor_json['attachment'] = new_attachment

    # do shared items exist for this account?
    shares_filename = \
        acct_dir(base_dir, nickname, domain) + '/shares.json'
    if not os.path.isfile(shares_filename):
        return changed
    shares_json = load_json(shares_filename)
    if not shares_json:
        return changed

    # add ValueFlows items to the attachment list
    media_type = \
        "application/ld+json; profile=" + \
        "\"https://www.w3.org/ns/activitystreams\""
    ctr = 0
    for share_id, shared_item in shares_json.items():
        if ctr >= max_shares_on_profile:
            break
        if not shared_item.get('shareOnProfile'):
            continue
        share_id = _vf_share_id(share_id)
        actor_json['attachment'].append({
            "type": "Link",
            "name": shared_item['displayName'],
            "mediaType": media_type,
            "href": share_id,
            "rel": ["payment", "https://w3id.org/valueflows/ont/vf#Proposal"]
        })
        changed = True
        ctr += 1
    return changed
