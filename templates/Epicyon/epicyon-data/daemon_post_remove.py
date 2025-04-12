__filename__ = "daemon_post_remove.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import errno
import urllib.parse
from socket import error as SocketError
from utils import save_json
from utils import load_json
from utils import acct_dir
from utils import get_instance_url
from utils import get_domain_from_actor
from utils import local_actor_url
from utils import get_config_param
from utils import get_nickname_from_actor
from reading import remove_reading_event
from httpheaders import redirect_headers
from posts import is_moderator
from shares import remove_shared_item2
from shares import add_shares_to_actor
from cache import store_person_in_cache
from cache import remove_person_from_cache
from cache import get_person_from_cache
from person import get_actor_update_json
from daemon_utils import post_to_outbox_thread
from daemon_utils import post_to_outbox
from happening import remove_calendar_event


def remove_reading_status(self, calling_domain: str, cookie: str,
                          path: str, base_dir: str, http_prefix: str,
                          domain_full: str,
                          onion_domain: str, i2p_domain: str,
                          debug: bool,
                          books_cache: {}) -> None:
    """Remove a reading status from the profile screen
    """
    users_path = path.split('/removereadingstatus')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path
    reader_nickname = get_nickname_from_actor(origin_path_str)
    if not reader_nickname:
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    length = int(self.headers['Content-length'])

    try:
        remove_reading_status_params = \
            self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST remove_reading_status_params ' +
                  'connection was reset')
        else:
            print('EX: POST remove_reading_status_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST remove_reading_status_params rfile.read failed, ' +
              str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitRemoveReadingStatus=' in remove_reading_status_params:
        reading_actor = \
            urllib.parse.unquote_plus(remove_reading_status_params)
        reading_actor = reading_actor.split('actor=')[1]
        if '&' in reading_actor:
            reading_actor = reading_actor.split('&')[0]

        if reading_actor == origin_path_str:
            post_secs_since_epoch = \
                urllib.parse.unquote_plus(remove_reading_status_params)
            post_secs_since_epoch = \
                post_secs_since_epoch.split('publishedtimesec=')[1]
            if '&' in post_secs_since_epoch:
                post_secs_since_epoch = post_secs_since_epoch.split('&')[0]

            book_event_type = \
                urllib.parse.unquote_plus(remove_reading_status_params)
            book_event_type = \
                book_event_type.split('bookeventtype=')[1]
            if '&' in book_event_type:
                book_event_type = book_event_type.split('&')[0]

            remove_reading_event(base_dir,
                                 reading_actor, post_secs_since_epoch,
                                 book_event_type, books_cache, debug)

    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str, cookie, calling_domain, 303)
    self.server.postreq_busy = False


def remove_share(self, calling_domain: str, cookie: str,
                 authorized: bool, path: str,
                 base_dir: str, http_prefix: str, domain_full: str,
                 onion_domain: str, i2p_domain: str,
                 curr_session, proxy_type: str,
                 person_cache: {},
                 max_shares_on_profile: int,
                 project_version: str) -> None:
    """Removes a shared item
    """
    users_path = path.split('/rmshare')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path

    length = int(self.headers['Content-length'])

    try:
        remove_share_confirm_params = \
            self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST remove_share_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST remove_share_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST remove_share_confirm_params ' +
              'rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitYes=' in remove_share_confirm_params and authorized:
        remove_share_confirm_params = \
            remove_share_confirm_params.replace('+', ' ').strip()
        remove_share_confirm_params = \
            urllib.parse.unquote_plus(remove_share_confirm_params)
        share_actor = remove_share_confirm_params.split('actor=')[1]
        if '&' in share_actor:
            share_actor = share_actor.split('&')[0]
        admin_nickname = get_config_param(base_dir, 'admin')
        admin_actor = \
            local_actor_url(http_prefix, admin_nickname, domain_full)
        actor = origin_path_str
        actor_nickname = get_nickname_from_actor(actor)
        if not actor_nickname:
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return
        if actor == share_actor or actor == admin_actor or \
           is_moderator(base_dir, actor_nickname):
            item_id = remove_share_confirm_params.split('itemID=')[1]
            if '&' in item_id:
                item_id = item_id.split('&')[0]
            share_nickname = get_nickname_from_actor(share_actor)
            share_domain, _ = \
                get_domain_from_actor(share_actor)
            if share_nickname and share_domain:
                remove_shared_item2(base_dir,
                                    share_nickname, share_domain, item_id,
                                    'shares')
                # remove shared items from the actor attachments
                # https://codeberg.org/fediverse/fep/
                # src/branch/main/fep/0837/fep-0837.md
                actor = \
                    get_instance_url(calling_domain,
                                     http_prefix,
                                     domain_full,
                                     onion_domain,
                                     i2p_domain) + \
                    '/users/' + share_nickname
                actor_json = get_person_from_cache(base_dir,
                                                   actor, person_cache)
                if not actor_json:
                    actor_filename = \
                        acct_dir(base_dir, share_nickname,
                                 share_domain) + '.json'
                    if os.path.isfile(actor_filename):
                        actor_json = load_json(actor_filename)
                if actor_json:
                    if add_shares_to_actor(base_dir,
                                           share_nickname, share_domain,
                                           actor_json,
                                           max_shares_on_profile):
                        remove_person_from_cache(base_dir, actor,
                                                 person_cache)
                        store_person_in_cache(base_dir, actor,
                                              actor_json,
                                              person_cache, True)
                        actor_filename = acct_dir(base_dir, share_nickname,
                                                  share_domain) + '.json'
                        save_json(actor_json, actor_filename)
                        # send profile update to followers

                        update_actor_json = \
                            get_actor_update_json(actor_json)
                        print('Sending actor update ' +
                              'after change to attached shares 2: ' +
                              str(update_actor_json))
                        post_to_outbox(self, update_actor_json,
                                       project_version,
                                       share_nickname,
                                       curr_session,
                                       proxy_type)

    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str + '/tlshares',
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False


def remove_wanted(self, calling_domain: str, cookie: str,
                  authorized: bool, path: str,
                  base_dir: str, http_prefix: str,
                  domain_full: str,
                  onion_domain: str, i2p_domain: str) -> None:
    """Removes a wanted item
    """
    users_path = path.split('/rmwanted')[0]
    origin_path_str = http_prefix + '://' + domain_full + users_path

    length = int(self.headers['Content-length'])

    try:
        remove_share_confirm_params = \
            self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST remove_share_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST remove_share_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST remove_share_confirm_params ' +
              'rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    if '&submitYes=' in remove_share_confirm_params and authorized:
        remove_share_confirm_params = \
            remove_share_confirm_params.replace('+', ' ').strip()
        remove_share_confirm_params = \
            urllib.parse.unquote_plus(remove_share_confirm_params)
        share_actor = remove_share_confirm_params.split('actor=')[1]
        if '&' in share_actor:
            share_actor = share_actor.split('&')[0]
        admin_nickname = get_config_param(base_dir, 'admin')
        admin_actor = \
            local_actor_url(http_prefix, admin_nickname, domain_full)
        actor = origin_path_str
        actor_nickname = get_nickname_from_actor(actor)
        if not actor_nickname:
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return
        if actor == share_actor or actor == admin_actor or \
           is_moderator(base_dir, actor_nickname):
            item_id = remove_share_confirm_params.split('itemID=')[1]
            if '&' in item_id:
                item_id = item_id.split('&')[0]
            share_nickname = get_nickname_from_actor(share_actor)
            share_domain, _ = \
                get_domain_from_actor(share_actor)
            if share_nickname and share_domain:
                remove_shared_item2(base_dir,
                                    share_nickname, share_domain, item_id,
                                    'wanted')

    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    redirect_headers(self, origin_path_str + '/tlwanted',
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False


def receive_remove_post(self, calling_domain: str, cookie: str,
                        path: str, base_dir: str, http_prefix: str,
                        domain: str, domain_full: str,
                        onion_domain: str, i2p_domain: str,
                        curr_session, proxy_type: str) -> None:
    """Endpoint for removing posts after confirmation
    """
    page_number = 1
    users_path = path.split('/rmpost')[0]
    origin_path_str = \
        http_prefix + '://' + \
        domain_full + users_path

    length = int(self.headers['Content-length'])

    try:
        remove_post_confirm_params = \
            self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST remove_post_confirm_params ' +
                  'connection was reset')
        else:
            print('EX: POST remove_post_confirm_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST remove_post_confirm_params ' +
              'rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    if '&submitYes=' in remove_post_confirm_params:
        remove_post_confirm_params = \
            urllib.parse.unquote_plus(remove_post_confirm_params)
        if 'messageId=' in remove_post_confirm_params:
            remove_message_id = \
                remove_post_confirm_params.split('messageId=')[1]
        elif 'eventid=' in remove_post_confirm_params:
            remove_message_id = \
                remove_post_confirm_params.split('eventid=')[1]
        else:
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return
        if '&' in remove_message_id:
            remove_message_id = remove_message_id.split('&')[0]
        print('remove_message_id: ' + remove_message_id)
        if 'pageNumber=' in remove_post_confirm_params:
            page_number_str = \
                remove_post_confirm_params.split('pageNumber=')[1]
            if '&' in page_number_str:
                page_number_str = page_number_str.split('&')[0]
            if len(page_number_str) > 5:
                page_number_str = "1"
            if page_number_str.isdigit():
                page_number = int(page_number_str)
        year_str = None
        if 'year=' in remove_post_confirm_params:
            year_str = remove_post_confirm_params.split('year=')[1]
            if '&' in year_str:
                year_str = year_str.split('&')[0]
        month_str = None
        if 'month=' in remove_post_confirm_params:
            month_str = remove_post_confirm_params.split('month=')[1]
            if '&' in month_str:
                month_str = month_str.split('&')[0]
        if '/statuses/' in remove_message_id:
            remove_post_actor = remove_message_id.split('/statuses/')[0]
        print('origin_path_str: ' + origin_path_str)
        print('remove_post_actor: ' + remove_post_actor)
        if origin_path_str in remove_post_actor:
            to_list = [
                'https://www.w3.org/ns/activitystreams#Public',
                remove_post_actor
            ]
            delete_json = {
                "@context": [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
                ],
                'actor': remove_post_actor,
                'object': remove_message_id,
                'to': to_list,
                'cc': [remove_post_actor + '/followers'],
                'type': 'Delete'
            }
            self.post_to_nickname = \
                get_nickname_from_actor(remove_post_actor)
            if self.post_to_nickname:
                if month_str and year_str:
                    if len(month_str) <= 3 and \
                       len(year_str) <= 5 and \
                       month_str.isdigit() and \
                       year_str.isdigit():
                        year_int = int(year_str)
                        month_int = int(month_str)
                        remove_calendar_event(base_dir,
                                              self.post_to_nickname,
                                              domain, year_int,
                                              month_int,
                                              remove_message_id)
                post_to_outbox_thread(self, delete_json,
                                      curr_session, proxy_type)
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str = 'http://' + i2p_domain + users_path
    if page_number == 1:
        redirect_headers(self, origin_path_str + '/outbox', cookie,
                         calling_domain, 303)
    else:
        page_number_str = str(page_number)
        actor_path_str = \
            origin_path_str + '/outbox?page=' + page_number_str
        redirect_headers(self, actor_path_str,
                         cookie, calling_domain, 303)
    self.server.postreq_busy = False
