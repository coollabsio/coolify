__filename__ = "securemode.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Security"

from httpsig import verify_post_headers
from session import establish_session
from flags import url_permitted
from httpsig import signed_get_key_id
from cache import get_person_pub_key


def secure_mode(curr_session, proxy_type: str,
                force: bool, server, headers: {}, path: str) -> bool:
    """http authentication (aka 'authorized fetch') of GET requests for json
    See AUTHORIZED_FETCH in https://docs.joinmastodon.org/admin/config
    """
    if not server.secure_mode and not force:
        return True

    key_id = signed_get_key_id(headers, server.debug)
    if not key_id:
        if server.debug:
            print('AUTH: secure mode, ' +
                  'failed to obtain key_id from signature')
        return False

    # is the key_id (actor) valid?
    if not url_permitted(key_id, server.federation_list):
        if server.debug:
            print('AUTH: Secure mode GET request not permitted: ' + key_id)
        return False

    if server.onion_domain:
        if '.onion/' in key_id:
            curr_session = server.session_onion
            proxy_type = 'tor'
    if server.i2p_domain:
        if '.i2p/' in key_id:
            curr_session = server.session_i2p
            proxy_type = 'i2p'

    curr_session = \
        establish_session("secure mode",
                          curr_session, proxy_type,
                          server)
    if not curr_session:
        return False

    # obtain the public key. key_id is the actor
    pub_key = \
        get_person_pub_key(server.base_dir,
                           curr_session, key_id,
                           server.person_cache, server.debug,
                           server.project_version,
                           server.http_prefix,
                           server.domain,
                           server.onion_domain,
                           server.i2p_domain,
                           server.signing_priv_key_pem,
                           server.mitm_servers)
    if not pub_key:
        if server.debug:
            print('AUTH: secure mode failed to ' +
                  'obtain public key for ' + key_id)
        return False

    # was an error http code returned?
    if isinstance(pub_key, dict):
        if server.debug:
            print('AUTH: failed to ' +
                  'obtain public key for ' + key_id +
                  ' ' + str(pub_key))
        return False

    # verify the GET request without any digest
    if verify_post_headers(server.http_prefix,
                           server.domain_full,
                           pub_key, headers,
                           path, True, None, '', server.debug):
        return True

    if server.debug:
        print('AUTH: secure mode authorization failed for ' + key_id)
    return False
