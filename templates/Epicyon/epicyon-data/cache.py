__filename__ = "cache.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
from session import download_image
from session import url_exists
from session import get_json
from session import get_json_valid
from flags import url_permitted
from utils import remove_html
from utils import get_url_from_post
from utils import data_dir
from utils import get_attributed_to
from utils import remove_id_ending
from utils import get_post_attachments
from utils import has_object_dict
from utils import contains_statuses
from utils import load_json
from utils import save_json
from utils import get_file_case_insensitive
from utils import get_user_paths
from utils import date_utcnow
from utils import date_from_string_format
from content import remove_script


def remove_person_from_cache(base_dir: str, person_url: str,
                             person_cache: {}) -> bool:
    """Removes an actor from the cache
    """
    cache_filename = base_dir + '/cache/actors/' + \
        person_url.replace('/', '#') + '.json'
    if os.path.isfile(cache_filename):
        try:
            os.remove(cache_filename)
        except OSError:
            print('EX: unable to delete cached actor ' + str(cache_filename))
    if person_cache.get(person_url):
        del person_cache[person_url]


def clear_actor_cache(base_dir: str, person_cache: {},
                      clear_domain: str) -> None:
    """Clears the actor cache for the given domain
    This is useful if you know that a given instance has rotated their
    signing keys after a security incident
    """
    if not clear_domain:
        return
    if '.' not in clear_domain:
        return

    actor_cache_dir = base_dir + '/cache/actors'
    for subdir, _, files in os.walk(actor_cache_dir):
        for fname in files:
            filename = os.path.join(subdir, fname)
            if not filename.endswith('.json'):
                continue
            if clear_domain not in fname:
                continue
            person_url = fname.replace('#', '/').replace('.json', '')
            remove_person_from_cache(base_dir, person_url,
                                     person_cache)
        break


def check_for_changed_actor(session, base_dir: str,
                            http_prefix: str, domain_full: str,
                            person_url: str, avatar_url: str, person_cache: {},
                            timeout_sec: int):
    """Checks if the avatar url exists and if not then
    the actor has probably changed without receiving an actor/Person Update.
    So clear the actor from the cache and it will be refreshed when the next
    post from them is sent
    """
    if not session or not avatar_url:
        return
    if domain_full in avatar_url:
        return
    if url_exists(session, avatar_url, timeout_sec, http_prefix, domain_full):
        return
    remove_person_from_cache(base_dir, person_url, person_cache)


def store_person_in_cache(base_dir: str, person_url: str,
                          person_json: {}, person_cache: {},
                          allow_write_to_file: bool) -> None:
    """Store an actor in the cache
    """
    if contains_statuses(person_url) or person_url.endswith('/actor'):
        # This is not an actor or person account
        return

    curr_time = date_utcnow()
    person_cache[person_url] = {
        "actor": person_json,
        "timestamp": curr_time.strftime("%Y-%m-%dT%H:%M:%SZ")
    }
    if not base_dir:
        return

    # store to file
    if not allow_write_to_file:
        return
    if os.path.isdir(base_dir + '/cache/actors'):
        cache_filename = base_dir + '/cache/actors/' + \
            person_url.replace('/', '#') + '.json'
        if not os.path.isfile(cache_filename):
            save_json(person_json, cache_filename)


def get_person_from_cache(base_dir: str, person_url: str,
                          person_cache: {}) -> {}:
    """Get an actor from the cache
    """
    # if the actor is not in memory then try to load it from file
    loaded_from_file = False
    if not person_cache.get(person_url):
        # does the person exist as a cached file?
        cache_filename = base_dir + '/cache/actors/' + \
            person_url.replace('/', '#') + '.json'
        actor_filename = get_file_case_insensitive(cache_filename)
        if actor_filename:
            person_json = load_json(actor_filename)
            if person_json:
                store_person_in_cache(base_dir, person_url, person_json,
                                      person_cache, False)
                loaded_from_file = True

    if person_cache.get(person_url):
        if not loaded_from_file:
            # update the timestamp for the last time the actor was retrieved
            curr_time = date_utcnow()
            curr_time_str = curr_time.strftime("%Y-%m-%dT%H:%M:%SZ")
            person_cache[person_url]['timestamp'] = curr_time_str
        return person_cache[person_url]['actor']
    return None


def expire_person_cache(person_cache: {}):
    """Expires old entries from the cache in memory
    """
    curr_time = date_utcnow()
    removals: list[str] = []
    for person_url, cache_json in person_cache.items():
        cache_time = date_from_string_format(cache_json['timestamp'],
                                             ["%Y-%m-%dT%H:%M:%S%z"])
        days_since_cached = (curr_time - cache_time).days
        if days_since_cached > 2:
            removals.append(person_url)
    if len(removals) > 0:
        for person_url in removals:
            del person_cache[person_url]
        print(str(len(removals)) + ' actors were expired from the cache')


def store_webfinger_in_cache(handle: str, webfing,
                             cached_webfingers: {}) -> None:
    """Store a webfinger endpoint in the cache
    """
    cached_webfingers[handle] = webfing


def get_webfinger_from_cache(handle: str, cached_webfingers: {}) -> {}:
    """Get webfinger endpoint from the cache
    """
    if cached_webfingers.get(handle):
        return cached_webfingers[handle]
    return None


def get_actor_public_key_from_id(person_json: {}, key_id: str) -> (str, str):
    """Returns the public key referenced by the given id
    https://codeberg.org/fediverse/fep/src/branch/main/fep/521a/fep-521a.md
    """
    pub_key = None
    pub_key_id = None
    if person_json.get('publicKey'):
        if person_json['publicKey'].get('publicKeyPem'):
            pub_key = person_json['publicKey']['publicKeyPem']
            if person_json['publicKey'].get('id'):
                pub_key_id = person_json['publicKey']['id']
    elif person_json.get('assertionMethod'):
        if isinstance(person_json['assertionMethod'], list):
            for key_dict in person_json['assertionMethod']:
                if not key_dict.get('id') or \
                   not key_dict.get('publicKeyMultibase'):
                    continue
                if key_id is None or key_dict['id'] == key_id:
                    pub_key = key_dict['publicKeyMultibase']
                    pub_key_id = key_dict['id']
                    break
    if not pub_key and person_json.get('publicKeyPem'):
        pub_key = person_json['publicKeyPem']
        if person_json.get('id'):
            pub_key_id = person_json['id']
    return pub_key, pub_key_id


def get_person_pub_key(base_dir: str, session, person_url: str,
                       person_cache: {}, debug: bool,
                       project_version: str, http_prefix: str,
                       domain: str, onion_domain: str,
                       i2p_domain: str,
                       signing_priv_key_pem: str,
                       mitm_servers: []) -> str:
    """Get the public key for an actor
    """
    original_person_url = person_url
    if not person_url:
        return None
    if '#/publicKey' in person_url:
        person_url = person_url.replace('#/publicKey', '')
    elif '/main-key' in person_url:
        person_url = person_url.replace('/main-key', '')
    else:
        person_url = person_url.replace('#main-key', '')
    users_paths = get_user_paths()
    for possible_users_path in users_paths:
        if person_url.endswith(possible_users_path + 'inbox'):
            if debug:
                print('DEBUG: Obtaining public key for shared inbox')
            person_url = \
                person_url.replace(possible_users_path + 'inbox', '/inbox')
            break
    person_json = \
        get_person_from_cache(base_dir, person_url, person_cache)
    if not person_json:
        if debug:
            print('DEBUG: Obtaining public key for ' + person_url)
        person_domain = domain
        if onion_domain:
            if '.onion/' in person_url:
                person_domain = onion_domain
        elif i2p_domain:
            if '.i2p/' in person_url:
                person_domain = i2p_domain
        profile_str = 'https://www.w3.org/ns/activitystreams'
        accept_str = \
            'application/activity+json; profile="' + profile_str + '"'
        as_header = {
            'Accept': accept_str
        }
        person_json = \
            get_json(signing_priv_key_pem,
                     session, person_url, as_header, None, debug,
                     mitm_servers, project_version, http_prefix, person_domain)
        if not get_json_valid(person_json):
            if person_json is not None:
                if isinstance(person_json, dict):
                    # return the error code
                    return person_json
            return None
    pub_key, _ = get_actor_public_key_from_id(person_json, original_person_url)
    if not pub_key:
        if debug:
            print('DEBUG: Public key not found for ' + person_url)

    store_person_in_cache(base_dir, person_url, person_json,
                          person_cache, True)
    return pub_key


def cache_svg_images(session, base_dir: str, http_prefix: str,
                     domain: str, domain_full: str,
                     onion_domain: str, i2p_domain: str,
                     post_json_object: {},
                     federation_list: [], debug: bool,
                     test_image_filename: str) -> bool:
    """Creates a local copy of a remote svg file
    """
    if has_object_dict(post_json_object):
        obj = post_json_object['object']
    else:
        obj = post_json_object
    if not obj.get('id'):
        return False
    post_attachments = get_post_attachments(obj)
    if not post_attachments:
        return False
    cached = False
    post_id = remove_id_ending(obj['id']).replace('/', '--')
    actor = 'unknown'
    if post_attachments and obj.get('attributedTo'):
        actor = get_attributed_to(obj['attributedTo'])
    log_filename = data_dir(base_dir) + '/svg_scripts_log.txt'
    for index in range(len(post_attachments)):
        attach = post_attachments[index]
        if not attach.get('mediaType'):
            continue
        if not attach.get('url'):
            continue
        url_str = get_url_from_post(attach['url'])
        if url_str.endswith('.svg') or \
           'svg' in attach['mediaType']:
            url = remove_html(url_str)
            if not url_permitted(url, federation_list):
                continue
            # if this is a local image then it has already been
            # validated on upload
            if '://' + domain in url:
                continue
            if onion_domain:
                if '://' + onion_domain in url:
                    continue
            if i2p_domain:
                if '://' + i2p_domain in url:
                    continue
            if '/' in url:
                filename = url.split('/')[-1]
            else:
                filename = url
            if not test_image_filename:
                image_filename = \
                    base_dir + '/media/' + post_id + '_' + filename
                if not download_image(session, url,
                                      image_filename, debug):
                    continue
            else:
                image_filename = test_image_filename
            image_data = None
            try:
                with open(image_filename, 'rb') as fp_svg:
                    image_data = fp_svg.read()
            except OSError:
                print('EX: unable to read svg file data')
            if not image_data:
                continue
            image_data = image_data.decode()
            cleaned_up = \
                remove_script(image_data, log_filename, actor, url)
            if cleaned_up != image_data:
                # write the cleaned up svg image
                svg_written = False
                cleaned_up = cleaned_up.encode('utf-8')
                try:
                    with open(image_filename, 'wb') as fp_im:
                        fp_im.write(cleaned_up)
                        svg_written = True
                except OSError:
                    print('EX: unable to write cleaned up svg ' + url)
                if svg_written:
                    # convert to list if needed
                    if isinstance(obj['attachment'], dict):
                        obj['attachment'] = [obj['attachment']]
                    # change the url to be the local version
                    obj['attachment'][index]['url'] = \
                        http_prefix + '://' + domain_full + '/media/' + \
                        post_id + '_' + filename
                    cached = True
            else:
                cached = True
    return cached
