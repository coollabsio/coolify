__filename__ = "skills.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"

import os
from webfinger import webfinger_handle
from auth import create_basic_auth_header
from posts import get_person_box
from session import post_json
from utils import has_object_string
from utils import get_full_domain
from utils import get_nickname_from_actor
from utils import get_domain_from_actor
from utils import load_json
from utils import get_occupation_skills
from utils import set_occupation_skills_list
from utils import acct_dir
from utils import local_actor_url
from utils import has_actor
from utils import get_actor_from_post


def set_skills_from_dict(actor_json: {}, skills_dict: {}) -> []:
    """Converts a dict containing skills to a list
    Returns the string version of the dictionary
    """
    skills_list: list[str] = []
    for name, value in skills_dict.items():
        skills_list.append(name + ':' + str(value))
    set_occupation_skills_list(actor_json, skills_list)
    return skills_list


def get_skills_from_list(skills_list: []) -> {}:
    """Returns a dict of skills from a list
    """
    if isinstance(skills_list, list):
        skills_list2 = skills_list
    else:
        skills_list2 = skills_list.split(',')
    skills_dict = {}
    for skill in skills_list2:
        if ':' not in skill:
            continue
        name = skill.split(':')[0].strip().lower()
        value_str = skill.split(':')[1]
        if not value_str.isdigit():
            continue
        skills_dict[name] = int(value_str)
    return skills_dict


def actor_skill_value(actor_json: {}, skill_name: str) -> int:
    """Returns The skill level from an actor
    """
    oc_skills_list = get_occupation_skills(actor_json)
    skills_dict = get_skills_from_list(oc_skills_list)
    if not skills_dict:
        return 0
    skill_name = skill_name.lower()
    if skills_dict.get(skill_name):
        return skills_dict[skill_name]
    return 0


def no_of_actor_skills(actor_json: {}) -> int:
    """Returns the number of skills that an actor has
    """
    if actor_json.get('hasOccupation'):
        skills_list = get_occupation_skills(actor_json)
        return len(skills_list)
    return 0


def set_actor_skill_level(actor_json: {},
                          skill: str, skill_level_percent: int) -> bool:
    """Set a skill level for a person
    Setting skill level to zero removes it
    """
    if skill_level_percent < 0 or skill_level_percent > 100:
        return False

    if not actor_json:
        return True
    if not actor_json.get('hasOccupation'):
        actor_json['hasOccupation'] = [{
            '@type': 'Occupation',
            'name': '',
            "occupationLocation": {
                "@type": "City",
                "name": "Fediverse"
            },
            'skills': []
        }]
    oc_skills_list = get_occupation_skills(actor_json)
    skills_dict = get_skills_from_list(oc_skills_list)
    if not skills_dict.get(skill):
        if len(skills_dict.items()) >= 32:
            print('WARN: Maximum number of skills reached for ' +
                  actor_json['id'])
            return False
    if skill_level_percent > 0:
        skills_dict[skill] = skill_level_percent
    else:
        if skills_dict.get(skill):
            del skills_dict[skill]
    set_skills_from_dict(actor_json, skills_dict)
    return True


def set_skill_level(base_dir: str, nickname: str, domain: str,
                    skill: str, skill_level_percent: int) -> bool:
    """Set a skill level for a person
    Setting skill level to zero removes it
    """
    if skill_level_percent < 0 or skill_level_percent > 100:
        return False
    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if not os.path.isfile(actor_filename):
        return False

    actor_json = load_json(actor_filename)
    return set_actor_skill_level(actor_json,
                                 skill, skill_level_percent)


def get_skills(base_dir: str, nickname: str, domain: str) -> []:
    """Returns the skills for a given person
    """
    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if not os.path.isfile(actor_filename):
        return False

    actor_json = load_json(actor_filename)
    if actor_json:
        if not actor_json.get('hasOccupation'):
            return None
        oc_skills_list = get_occupation_skills(actor_json)
        return get_skills_from_list(oc_skills_list)
    return None


def outbox_skills(base_dir: str, nickname: str, message_json: {},
                  debug: bool) -> bool:
    """Handles receiving a skills update
    """
    if not message_json.get('type'):
        return False
    if not message_json['type'] == 'Skill':
        return False
    if not has_actor(message_json, debug):
        return False
    if not has_object_string(message_json, debug):
        return False

    actor_url = get_actor_from_post(message_json)
    actor_nickname = get_nickname_from_actor(actor_url)
    if not actor_nickname:
        return False
    if actor_nickname != nickname:
        return False
    domain, _ = get_domain_from_actor(actor_url)
    skill = message_json['object'].replace('"', '').split(';')[0].strip()
    skill_level_percent_str = \
        message_json['object'].replace('"', '').split(';')[1].strip()
    skill_level_percent = 50
    if skill_level_percent_str.isdigit():
        skill_level_percent = int(skill_level_percent_str)

    return set_skill_level(base_dir, nickname, domain,
                           skill, skill_level_percent)


def send_skill_via_server(base_dir: str, session, nickname: str, password: str,
                          domain: str, port: int,
                          http_prefix: str,
                          skill: str, skill_level_percent: int,
                          cached_webfingers: {}, person_cache: {},
                          debug: bool, project_version: str,
                          signing_priv_key_pem: str,
                          system_language: str,
                          mitm_servers: []) -> {}:
    """Sets a skill for a person via c2s
    """
    if not session:
        print('WARN: No session for send_skill_via_server')
        return 6

    domain_full = get_full_domain(domain, port)

    actor = local_actor_url(http_prefix, nickname, domain_full)
    to_url = actor
    cc_url = actor + '/followers'

    if skill_level_percent:
        skill_str = skill + ';' + str(skill_level_percent)
    else:
        skill_str = skill + ';0'

    new_skill_json = {
        'type': 'Skill',
        'actor': actor,
        'object': '"' + skill_str + '"',
        'to': [to_url],
        'cc': [cc_url]
    }

    handle = http_prefix + '://' + domain_full + '/@' + nickname

    # lookup the inbox for the To handle
    wf_request = \
        webfinger_handle(session, handle, http_prefix,
                         cached_webfingers,
                         domain, project_version, debug, False,
                         signing_priv_key_pem, mitm_servers)
    if not wf_request:
        if debug:
            print('DEBUG: skill webfinger failed for ' + handle)
        return 1
    if not isinstance(wf_request, dict):
        print('WARN: skill webfinger for ' + handle +
              ' did not return a dict. ' + str(wf_request))
        return 1

    post_to_box = 'outbox'

    # get the actor inbox for the To handle
    origin_domain = domain
    (inbox_url, _, _, from_person_id, _, _,
     _, _) = get_person_box(signing_priv_key_pem,
                            origin_domain,
                            base_dir, session, wf_request,
                            person_cache, project_version,
                            http_prefix, nickname, domain,
                            post_to_box, 76121,
                            system_language, mitm_servers)

    if not inbox_url:
        if debug:
            print('DEBUG: skill no ' + post_to_box +
                  ' was found for ' + handle)
        return 3
    if not from_person_id:
        if debug:
            print('DEBUG: skill no actor was found for ' + handle)
        return 4

    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/json',
        'Authorization': auth_header
    }
    post_result = \
        post_json(http_prefix, domain_full,
                  session, new_skill_json, [], inbox_url,
                  headers, 30, True)
    if not post_result:
        if debug:
            print('DEBUG: POST skill failed for c2s to ' + inbox_url)
#        return 5

    if debug:
        print('DEBUG: c2s POST skill success')

    return new_skill_json


def actor_has_skill(actor_json: {}, skill_name: str) -> bool:
    """Returns true if the given actor has the given skill
    """
    oc_skills_list = get_occupation_skills(actor_json)
    for skill_str in oc_skills_list:
        if skill_name + ':' in skill_str:
            return True
    return False
