__filename__ = "daemon_post_question.py"
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
from utils import replace_strings
from utils import remove_post_from_cache
from utils import get_cached_post_filename
from utils import load_json
from utils import locate_post
from utils import get_full_domain
from utils import get_domain_from_actor
from utils import text_in_file
from utils import get_nickname_from_actor
from utils import acct_dir
from httpheaders import redirect_headers
from city import get_spoofed_city
from languages import get_understood_languages
from posts import create_direct_message_post
from daemon_utils import post_to_outbox
from inbox import populate_replies


def receive_vote(self, calling_domain: str, cookie: str,
                 path: str, http_prefix: str,
                 domain: str, domain_full: str, port: int,
                 onion_domain: str, i2p_domain: str,
                 curr_session, proxy_type: str,
                 base_dir: str, city: str,
                 person_cache: {}, debug: bool,
                 system_language: str,
                 low_bandwidth: bool,
                 dm_license_url: str,
                 content_license_url: str,
                 translate: {}, max_replies: int,
                 project_version: str,
                 recent_posts_cache: {},
                 default_timeline: str,
                 auto_cw_cache: {}) -> None:
    """Receive a vote on a question via POST
    """
    first_post_id = ''
    if '?firstpost=' in path:
        first_post_id = path.split('?firstpost=')[1]
        path = path.split('?firstpost=')[0]
    if ';firstpost=' in path:
        first_post_id = path.split(';firstpost=')[1]
        path = path.split(';firstpost=')[0]
    if first_post_id:
        if '?' in first_post_id:
            first_post_id = first_post_id.split('?')[0]
        if ';' in first_post_id:
            first_post_id = first_post_id.split(';')[0]
        first_post_id = first_post_id.replace('/', '--')
        first_post_id = ';firstpost=' + first_post_id.replace('#', '--')

    last_post_id = ''
    if '?lastpost=' in path:
        last_post_id = path.split('?lastpost=')[1]
        path = path.split('?lastpost=')[0]
    if ';lastpost=' in path:
        last_post_id = path.split(';lastpost=')[1]
        path = path.split(';lastpost=')[0]
    if last_post_id:
        if '?' in last_post_id:
            last_post_id = last_post_id.split('?')[0]
        if ';' in last_post_id:
            last_post_id = last_post_id.split(';')[0]
        last_post_id = last_post_id.replace('/', '--')
        last_post_id = ';lastpost=' + last_post_id.replace('#', '--')

    page_number = 1
    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)

    # the actor who votes
    users_path = path.replace('/question', '')
    actor = http_prefix + '://' + domain_full + users_path
    nickname = get_nickname_from_actor(actor)
    if not nickname:
        if calling_domain.endswith('.onion') and onion_domain:
            actor = 'http://' + onion_domain + users_path
        elif (calling_domain.endswith('.i2p') and i2p_domain):
            actor = 'http://' + i2p_domain + users_path
        actor_path_str = \
            actor + '/' + default_timeline + \
            '?page=' + str(page_number)
        redirect_headers(self, actor_path_str,
                         cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    # get the parameters
    length = int(self.headers['Content-length'])

    try:
        question_params = self.rfile.read(length).decode('utf-8')
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: POST question_params connection was reset')
        else:
            print('EX: POST question_params socket error')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: POST question_params rfile.read failed, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    replacements = {
        '+': ' ',
        '%3F': ''
    }
    question_params = replace_strings(question_params, replacements)
    question_params = \
        urllib.parse.unquote_plus(question_params.strip())

    # post being voted on
    message_id = None
    if 'messageId=' in question_params:
        message_id = question_params.split('messageId=')[1]
        if '&' in message_id:
            message_id = message_id.split('&')[0]

    answer = None
    if 'answer=' in question_params:
        answer = question_params.split('answer=')[1]
        if '&' in answer:
            answer = answer.split('&')[0]

    _send_reply_to_question(self, base_dir, http_prefix,
                            nickname, domain, domain_full, port,
                            message_id, answer,
                            curr_session, proxy_type, city,
                            person_cache, debug,
                            system_language,
                            low_bandwidth,
                            dm_license_url,
                            content_license_url,
                            translate, max_replies,
                            project_version,
                            recent_posts_cache,
                            auto_cw_cache)
    if calling_domain.endswith('.onion') and onion_domain:
        actor = 'http://' + onion_domain + users_path
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        actor = 'http://' + i2p_domain + users_path
    actor_path_str = \
        actor + '/' + default_timeline + \
        '?page=' + str(page_number) + first_post_id + last_post_id
    redirect_headers(self, actor_path_str, cookie,
                     calling_domain, 303)
    self.server.postreq_busy = False
    return


def _send_reply_to_question(self, base_dir: str,
                            http_prefix: str,
                            nickname: str, domain: str,
                            domain_full: str,
                            port: int,
                            message_id: str,
                            answer: str,
                            curr_session, proxy_type: str,
                            city_name: str,
                            person_cache: {},
                            debug: bool,
                            system_language: str,
                            low_bandwidth: bool,
                            dm_license_url: str,
                            content_license_url: str,
                            translate: {},
                            max_replies: int,
                            project_version: str,
                            recent_posts_cache: {},
                            auto_cw_cache: {}) -> None:
    """Sends a reply to a question
    """
    votes_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/questions.txt'

    if os.path.isfile(votes_filename):
        # have we already voted on this?
        if text_in_file(message_id, votes_filename):
            print('Already voted on message ' + message_id)
            return

    print('Voting on message ' + message_id)
    print('Vote for: ' + answer)
    comments_enabled = True
    attach_image_filename = None
    media_type = None
    image_description = None
    video_transcript = None
    in_reply_to = message_id
    in_reply_to_atom_uri = message_id
    subject = None
    schedule_post = False
    event_date = None
    event_time = None
    event_end_time = None
    location = None
    conversation_id = None
    convthread_id = None
    buy_url = ''
    chat_url = ''
    city = get_spoofed_city(city_name, base_dir, nickname, domain)
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    reply_to_nickname = get_nickname_from_actor(in_reply_to)
    reply_to_domain, reply_to_port = get_domain_from_actor(in_reply_to)
    message_json = None
    if reply_to_nickname and reply_to_domain:
        reply_to_domain_full = \
            get_full_domain(reply_to_domain, reply_to_port)
        mentions_str = '@' + reply_to_nickname + '@' + reply_to_domain_full

        message_json = \
            create_direct_message_post(base_dir, nickname, domain,
                                       port, http_prefix,
                                       mentions_str + ' ' + answer,
                                       False, False,
                                       comments_enabled,
                                       attach_image_filename,
                                       media_type, image_description,
                                       video_transcript, city,
                                       in_reply_to, in_reply_to_atom_uri,
                                       subject, debug,
                                       schedule_post,
                                       event_date, event_time,
                                       event_end_time,
                                       location,
                                       system_language,
                                       conversation_id, convthread_id,
                                       low_bandwidth,
                                       dm_license_url,
                                       content_license_url, '',
                                       languages_understood, False,
                                       translate, buy_url,
                                       chat_url,
                                       auto_cw_cache, curr_session)
    if message_json:
        # NOTE: content and contentMap are not required, but we will keep
        # them in there so that the post does not get filtered out by
        # inbox processing.
        # name field contains the answer
        message_json['object']['name'] = answer
        if post_to_outbox(self, message_json,
                          project_version, nickname,
                          curr_session, proxy_type):
            post_filename = \
                locate_post(base_dir, nickname, domain, message_id)
            if post_filename:
                post_json_object = load_json(post_filename)
                if post_json_object:
                    populate_replies(base_dir,
                                     http_prefix,
                                     domain_full,
                                     post_json_object,
                                     max_replies,
                                     debug)
                    # record the vote
                    try:
                        with open(votes_filename, 'a+',
                                  encoding='utf-8') as fp_votes:
                            fp_votes.write(message_id + '\n')
                    except OSError:
                        print('EX: unable to write vote ' +
                              votes_filename)

                    # ensure that the cached post is removed if it exists,
                    # so that it then will be recreated
                    cached_post_filename = \
                        get_cached_post_filename(base_dir,
                                                 nickname, domain,
                                                 post_json_object)
                    if cached_post_filename:
                        if os.path.isfile(cached_post_filename):
                            try:
                                os.remove(cached_post_filename)
                            except OSError:
                                print('EX: _send_reply_to_question ' +
                                      'unable to delete ' +
                                      cached_post_filename)
                    # remove from memory cache
                    remove_post_from_cache(post_json_object,
                                           recent_posts_cache)
        else:
            print('ERROR: unable to post vote to outbox')
    else:
        print('ERROR: unable to create vote')
