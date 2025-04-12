__filename__ = "socnet.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Moderation"

from session import create_session
from webfinger import webfinger_handle
from posts import get_person_box
from posts import get_post_domains
from utils import get_full_domain


def instances_graph(base_dir: str, handles: str,
                    proxy_type: str,
                    port: int, http_prefix: str,
                    debug: bool, project_version: str,
                    system_language: str, signing_priv_key_pem: str,
                    mitm_servers: []) -> str:
    """ Returns a dot graph of federating instances
    based upon a few sample handles.
    The handles argument should contain a comma separated list
    of handles on different instances
    """
    dot_graph_str = 'digraph instances {\n'
    if ',' not in handles:
        return dot_graph_str + '}\n'
    session = create_session(proxy_type)
    if not session:
        return dot_graph_str + '}\n'

    person_cache = {}
    cached_webfingers = {}

    person_handles = handles.split(',')
    for handle in person_handles:
        handle = handle.strip()
        if handle.startswith('@'):
            handle = handle[1:]
        if '@' not in handle:
            continue

        nickname = handle.split('@')[0]
        domain = handle.split('@')[1]

        domain_full = get_full_domain(domain, port)
        handle = http_prefix + "://" + domain_full + "/@" + nickname
        wf_request = \
            webfinger_handle(session, handle, http_prefix,
                             cached_webfingers,
                             domain, project_version, debug, False,
                             signing_priv_key_pem, mitm_servers)
        if not wf_request:
            return dot_graph_str + '}\n'
        if not isinstance(wf_request, dict):
            print('Webfinger for ' + handle + ' did not return a dict. ' +
                  str(wf_request))
            return dot_graph_str + '}\n'

        origin_domain = None
        (person_url, _, _, _, _, _,
         _, _) = get_person_box(signing_priv_key_pem,
                                origin_domain,
                                base_dir, session, wf_request,
                                person_cache,
                                project_version, http_prefix,
                                nickname, domain, 'outbox',
                                27261, system_language,
                                mitm_servers)
        word_frequency = {}
        post_domains = \
            get_post_domains(session, person_url, 64, debug,
                             project_version, http_prefix, domain,
                             word_frequency, [], system_language,
                             signing_priv_key_pem, mitm_servers)
        post_domains.sort()
        for fed_domain in post_domains:
            dot_line_str = '    "' + domain + '" -> "' + fed_domain + '";\n'
            if dot_line_str not in dot_graph_str:
                dot_graph_str += dot_line_str
    return dot_graph_str + '}\n'
