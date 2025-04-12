__filename__ = "metadata.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Metadata"

import os
from utils import no_of_active_accounts_monthly
from utils import no_of_accounts
from utils import get_status_count


def meta_data_node_info(base_dir: str,
                        about_url: str,
                        terms_of_service_url: str,
                        registration: bool, version: str,
                        show_accounts: bool,
                        domain: str,
                        instance_description_short: str,
                        instance_description: str) -> {}:
    """ /nodeinfo/2.2 endpoint
    Also see https://socialhub.activitypub.rocks/t/
    https://github.com/jhass/nodeinfo/blob/main/schemas/2.2/example.json
    fep-f1d5-nodeinfo-in-fediverse-software/1190/4

    Note that there are security considerations with this. If an adversary
    sees a lot of accounts and "local" posts then the instance may be
    considered a higher priority target.
    Also exposure of the version number and number of accounts could be
    sensitive
    """
    if show_accounts:
        active_accounts = no_of_accounts(base_dir)
        active_accounts_monthly = no_of_active_accounts_monthly(base_dir, 1)
        active_accounts_half_year = no_of_active_accounts_monthly(base_dir, 6)
        local_posts = get_status_count(base_dir)
    else:
        active_accounts = 1
        active_accounts_monthly = 1
        active_accounts_half_year = 1
        local_posts = 1

    nodeinfo = {
        "version": "2.2",
        'documents': {
            'about': about_url,
            'terms': terms_of_service_url
        },
        "instance": {
            "name": instance_description_short,
            "description": instance_description
        },
        "software": {
            "name": "epicyon",
            "repository": "https://gitlab.com/bashrc2/epicyon",
            "homepage": "https://gitlab.com/bashrc2/epicyon",
            "version": version
        },
        "protocols": ["activitypub"],
        "services": {
            "outbound": ["rss2.0"]
        },
        "openRegistrations": registration,
        "usage": {
            "localComments": 0,
            "localPosts": local_posts,
            "users": {
                "activeHalfyear": active_accounts_half_year,
                "activeMonth": active_accounts_monthly,
                "total": active_accounts
            }
        },
        "metadata": {
            "accountActivationRequired": False,
            "features": [
                "editing",
                "exposable_reactions"
            ],
            "chat_enabled": False,
            "federatedTimelineAvailable": False,
            "federation": {
                "enabled": True
            },
            "suggestions": {
                "enabled": False
            },
            "invitesEnabled": False,
            "private": True,
            "privilegedStaff": True,
            "nodeName": domain,
            "mailerEnabled": False,
            "publicTimelineVisibility": {},
            "postFormats": ["text/plain", "text/html",
                            "text/markdown", "text/x.misskeymarkdown"],
            "FEPs": ["c648", "521a", "8fcf", "4ccd", "c118", "fffd",
                     "1970", "0837", "7628", "2677", "5e53", "c16b",
                     "5e53", "268d", "b2b8", "9967", "dd4b", "5711",
                     "044f"]
        }
    }
    return nodeinfo


def metadata_custom_emoji(base_dir: str,
                          http_prefix: str, domain_full: str) -> {}:
    """Returns the custom emoji
    Endpoint /api/v1/custom_emojis
    See https://docs.joinmastodon.org/methods/instance/custom_emojis
    """
    result: list[dict] = []
    emojis_url = http_prefix + '://' + domain_full + '/emoji'
    for _, _, files in os.walk(base_dir + '/emoji'):
        for fname in files:
            if len(fname) < 3:
                continue
            if fname[0].isdigit() or fname[1].isdigit():
                continue
            if not fname.endswith('.png'):
                continue
            url = os.path.join(emojis_url, fname)
            result.append({
                "shortcode": fname.replace('.png', ''),
                "url": url,
                "static_url": url,
                "visible_in_picker": True
            })
        break
    return result
