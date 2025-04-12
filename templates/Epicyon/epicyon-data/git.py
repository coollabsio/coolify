__filename__ = "git.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Profile Metadata"

import os
import html
from utils import remove_link_tracking
from utils import acct_dir
from utils import has_object_string_type
from utils import text_in_file
from utils import get_attachment_property_value
from utils import remove_html
from utils import get_attributed_to
from utils import string_contains


def _git_format_content(content: str) -> str:
    """ replace html formatting, so that it's more
    like the original patch file
    """
    patch_str = content.replace('<br>', '\n').replace('<br />', '\n')
    patch_str = patch_str.replace('<p>', '').replace('</p>', '\n')
    patch_str = html.unescape(patch_str)
    if 'From ' in patch_str:
        patch_str = 'From ' + patch_str.split('From ', 1)[1]
    return patch_str


def _get_git_project_name(base_dir: str, nickname: str, domain: str,
                          subject: str) -> str:
    """Returns the project name for a git patch
    The project name should be contained within the subject line
    and should match against a list of projects which the account
    holder wants to receive
    """
    git_projects_filename = \
        acct_dir(base_dir, nickname, domain) + '/gitprojects.txt'
    if not os.path.isfile(git_projects_filename):
        return None
    subject_line_words = subject.lower().split(' ')
    for word in subject_line_words:
        if text_in_file(word, git_projects_filename):
            return word
    return None


def is_git_patch(base_dir: str, nickname: str, domain: str,
                 message_type: str,
                 subject: str, content: str,
                 check_project_name: bool = True) -> bool:
    """Is the given post content a git patch?
    """
    if message_type not in ('Note', 'Page', 'Patch'):
        return False
    # must have a subject line
    if not subject:
        return False
    if '[PATCH]' not in content:
        return False
    if '---' not in content:
        return False
    if 'diff ' not in content:
        return False
    if 'From ' not in content:
        return False
    if 'From:' not in content:
        return False
    if 'Date:' not in content:
        return False
    if 'Subject:' not in content:
        return False
    if '<br>' not in content:
        if '<br />' not in content:
            return False
    if check_project_name:
        project_name = \
            _get_git_project_name(base_dir, nickname, domain, subject)
        if not project_name:
            return False
    return True


def _get_git_hash(patch_str: str) -> str:
    """Returns the commit hash from a given patch
    """
    patch_lines = patch_str.split('\n')
    for line in patch_lines:
        if line.startswith('From '):
            words = line.split(' ')
            if len(words) > 1:
                if len(words[1]) > 20:
                    return words[1]
            break
    return None


def _get_patch_description(patch_str: str) -> str:
    """Returns the description from a given patch
    """
    patch_lines = patch_str.split('\n')
    description = ''
    started = False
    for line in patch_lines:
        if started:
            if line.strip() == '---':
                break
            description += line + '\n'
        if line.startswith('Subject:'):
            started = True
    return description


def convert_post_to_patch(base_dir: str, nickname: str, domain: str,
                          post_json_object: {}) -> bool:
    """Detects whether the given post contains a patch
    and if so then converts it to a Patch ActivityPub type
    """
    if not has_object_string_type(post_json_object, False):
        return False
    if post_json_object['object']['type'] == 'Patch':
        return True
    if not post_json_object['object'].get('summary'):
        return False
    if not post_json_object['object'].get('content'):
        return False
    if not post_json_object['object'].get('attributedTo'):
        return False
    if get_attributed_to(post_json_object['object']['attributedTo']) is None:
        return False
    if not is_git_patch(base_dir, nickname, domain,
                        post_json_object['object']['type'],
                        post_json_object['object']['summary'],
                        post_json_object['object']['content'],
                        False):
        return False
    patch_str = _git_format_content(post_json_object['object']['content'])
    commit_hash = _get_git_hash(patch_str)
    if not commit_hash:
        return False
    post_json_object['object']['type'] = 'Patch'
    # add a commitedBy parameter
    if not post_json_object['object'].get('committedBy'):
        post_json_object['object']['committedBy'] = \
            get_attributed_to(post_json_object['object']['attributedTo'])
    post_json_object['object']['hash'] = commit_hash
    post_json_object['object']['description'] = {
        "mediaType": "text/plain",
        "content": _get_patch_description(patch_str)
    }
    # remove content map
    if post_json_object['object'].get('contentMap'):
        del post_json_object['object']['contentMap']
    print('Converted post to Patch ActivityPub type')
    return True


def _git_add_from_handle(patch_str: str, handle: str) -> str:
    """Adds the activitypub handle of the sender to the patch
    """
    from_str = 'AP-signed-off-by: '
    if from_str in patch_str:
        return patch_str

    patch_lines = patch_str.split('\n')
    patch_str = ''
    for line in patch_lines:
        patch_str += line + '\n'
        if line.startswith('From:'):
            if from_str not in patch_str:
                patch_str += from_str + handle + '\n'
    return patch_str


def receive_git_patch(base_dir: str, nickname: str, domain: str,
                      message_type: str, subject: str, content: str,
                      from_nickname: str, from_domain: str) -> bool:
    """Receive a git patch
    """
    if not is_git_patch(base_dir, nickname, domain,
                        message_type, subject, content):
        return False

    patch_str = _git_format_content(content)

    patch_lines = patch_str.split('\n')
    patch_filename = None
    project_dir = None
    patches_dir = acct_dir(base_dir, nickname, domain) + '/patches'
    # get the subject line and turn it into a filename
    for line in patch_lines:
        if line.startswith('Subject:'):
            patch_subject = \
                line.replace('Subject:', '').replace('/', '|')
            patch_subject = patch_subject.replace('[PATCH]', '').strip()
            patch_subject = patch_subject.replace(' ', '_')
            project_name = \
                _get_git_project_name(base_dir, nickname, domain, subject)
            if not os.path.isdir(patches_dir):
                os.mkdir(patches_dir)
            project_dir = patches_dir + '/' + project_name
            if not os.path.isdir(project_dir):
                os.mkdir(project_dir)
            patch_filename = \
                project_dir + '/' + patch_subject + '.patch'
            break
    if not patch_filename:
        return False
    patch_str = \
        _git_add_from_handle(patch_str,
                             '@' + from_nickname + '@' + from_domain)
    try:
        with open(patch_filename, 'w+', encoding='utf-8') as fp_patch:
            fp_patch.write(patch_str)
            patch_notify_filename = \
                acct_dir(base_dir, nickname, domain) + '/.newPatchContent'
            with open(patch_notify_filename, 'w+',
                      encoding='utf-8') as fp_patch_notify:
                fp_patch_notify.write(patch_str)
                return True
    except OSError as ex:
        print('EX: receive_git_patch ' + patch_filename + ' ' + str(ex))
    return False


def get_repo_url(actor_json: {}) -> str:
    """Returns a link used for code repo
    """
    if not actor_json.get('attachment'):
        return ''
    if not isinstance(actor_json['attachment'], list):
        return ''
    repo_type = ('github', 'ghub', 'gitlab', 'glab', 'codeberg', 'launchpad',
                 'sourceforge', 'bitbucket', 'gitea')
    for property_value in actor_json['attachment']:
        name_value = None
        if property_value.get('name'):
            name_value = property_value['name']
        elif property_value.get('schema:name'):
            name_value = property_value['schema:name']
        if not name_value:
            continue
        if name_value.lower() not in repo_type:
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
            repo_url = property_value[prop_value_name].split('<a href="')[1]
            if '"' in repo_url:
                repo_url = repo_url.split('"')[0]
        else:
            repo_url = property_value[prop_value_name]
        if '.' not in repo_url:
            continue
        repo_url = remove_html(repo_url)
        return remove_link_tracking(repo_url)

    repo_sites = ('github.com', 'gitlab.com', 'codeberg.org')

    for property_value in actor_json['attachment']:
        if not property_value.get('type'):
            continue
        prop_value_name, prop_value = \
            get_attachment_property_value(property_value)
        if not prop_value:
            continue
        if not property_value['type'].endswith('PropertyValue'):
            continue
        repo_url = property_value[prop_value_name]
        if not string_contains(repo_url, repo_sites):
            continue
        repo_url = remove_html(repo_url)
        return remove_link_tracking(repo_url)

    return ''
