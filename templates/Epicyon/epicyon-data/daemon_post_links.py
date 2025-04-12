__filename__ = "daemon_post_links.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import errno
from socket import error as SocketError
from flags import is_editor
from utils import data_dir
from utils import dangerous_markup
from utils import get_instance_url
from utils import get_nickname_from_actor
from utils import get_config_param
from httpheaders import redirect_headers
from content import extract_text_fields_in_post


def _links_update_edited(fields: {}, links_filename: str) -> None:
    """Moderators can update the links column
    """
    if fields.get('editedLinks'):
        links_str = fields['editedLinks']
        if fields.get('newColLink'):
            if links_str:
                if not links_str.endswith('\n'):
                    links_str += '\n'
            links_str += fields['newColLink'] + '\n'
        try:
            with open(links_filename, 'w+',
                      encoding='utf-8') as fp_links:
                fp_links.write(links_str)
        except OSError:
            print('EX: _links_update unable to write ' +
                  links_filename)
    else:
        if fields.get('newColLink'):
            # the text area is empty but there is a new link added
            links_str = fields['newColLink'] + '\n'
            try:
                with open(links_filename, 'w+',
                          encoding='utf-8') as fp_links:
                    fp_links.write(links_str)
            except OSError:
                print('EX: _links_update unable to write ' +
                      links_filename)
        else:
            if os.path.isfile(links_filename):
                try:
                    os.remove(links_filename)
                except OSError:
                    print('EX: _links_update unable to delete ' +
                          links_filename)


def _links_update_about(fields: {}, allow_local_network_access: bool,
                        about_filename: str) -> None:
    """Administrator can update the instance About screen
    """
    if fields.get('editedAbout'):
        about_str = fields['editedAbout']
        if not dangerous_markup(about_str,
                                allow_local_network_access, []):
            try:
                with open(about_filename, 'w+',
                          encoding='utf-8') as fp_about:
                    fp_about.write(about_str)
            except OSError:
                print('EX: unable to write about ' +
                      about_filename)
    else:
        if os.path.isfile(about_filename):
            try:
                os.remove(about_filename)
            except OSError:
                print('EX: _links_update unable to delete ' +
                      about_filename)


def _links_update_tos(fields: {}, allow_local_network_access: bool,
                      tos_filename: str) -> None:
    """Administrator can update the terms of service
    """
    if fields.get('editedTOS'):
        tos_str = fields['editedTOS']
        if not dangerous_markup(tos_str,
                                allow_local_network_access, []):
            try:
                with open(tos_filename, 'w+', encoding='utf-8') as fp_tos:
                    fp_tos.write(tos_str)
            except OSError:
                print('EX: unable to write TOS ' + tos_filename)
    else:
        if os.path.isfile(tos_filename):
            try:
                os.remove(tos_filename)
            except OSError:
                print('EX: _links_update unable to delete ' +
                      tos_filename)


def _links_update_sepcification(fields: {},
                                specification_filename: str) -> None:
    """Administrator can update the ActivityPub specification
    """
    if fields.get('editedSpecification'):
        specification_str = fields['editedSpecification']
        try:
            with open(specification_filename, 'w+',
                      encoding='utf-8') as fp_specification:
                fp_specification.write(specification_str)
        except OSError:
            print('EX: unable to write specification ' +
                  specification_filename)
    else:
        if os.path.isfile(specification_filename):
            try:
                os.remove(specification_filename)
            except OSError:
                print('EX: _links_update_specification unable to delete ' +
                      specification_filename)


def links_update(self, calling_domain: str, cookie: str,
                 path: str, base_dir: str, debug: bool,
                 default_timeline: str,
                 allow_local_network_access: bool,
                 http_prefix: str, domain_full: str,
                 onion_domain: str, i2p_domain: str,
                 max_post_length: int) -> None:
    """Updates the left links column of the timeline
    """
    users_path = path.replace('/linksdata', '')
    users_path = users_path.replace('/editlinks', '')
    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix,
                         domain_full,
                         onion_domain,
                         i2p_domain) + \
        users_path

    boundary = None
    if ' boundary=' in self.headers['Content-type']:
        boundary = self.headers['Content-type'].split('boundary=')[1]
        if ';' in boundary:
            boundary = boundary.split(';')[0]

    # get the nickname
    nickname = get_nickname_from_actor(actor_str)
    editor = None
    if nickname:
        editor = is_editor(base_dir, nickname)
    if not nickname or not editor:
        if not nickname:
            print('WARN: nickname not found in ' + actor_str)
        else:
            print('WARN: nickname is not a moderator' + actor_str)
        redirect_headers(self, actor_str, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    if self.headers.get('Content-length'):
        length = int(self.headers['Content-length'])

        # check that the POST isn't too large
        if length > max_post_length:
            print('Maximum links data length exceeded ' + str(length))
            redirect_headers(self, actor_str, cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return

    try:
        # read the bytes of the http form POST
        post_bytes = self.rfile.read(length)
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: connection was reset while ' +
                  'reading bytes from http form POST')
        else:
            print('EX: error while reading bytes ' +
                  'from http form POST')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: failed to read bytes for POST, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    links_filename = data_dir(base_dir) + '/links.txt'
    about_filename = data_dir(base_dir) + '/about.md'
    tos_filename = data_dir(base_dir) + '/tos.md'
    specification_filename = data_dir(base_dir) + '/activitypub.md'

    if not boundary:
        if b'--LYNX' in post_bytes:
            boundary = '--LYNX'

    if boundary:
        # extract all of the text fields into a dict
        fields = \
            extract_text_fields_in_post(post_bytes, boundary, debug, None)

        _links_update_edited(fields, links_filename)

        # administrator can do a few extra things other than editing links
        admin_nickname = get_config_param(base_dir, 'admin')
        if nickname == admin_nickname:
            _links_update_about(fields, allow_local_network_access,
                                about_filename)
            _links_update_tos(fields, allow_local_network_access, tos_filename)
            _links_update_sepcification(fields, specification_filename)

    # redirect back to the default timeline
    redirect_headers(self, actor_str + '/' + default_timeline,
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False
