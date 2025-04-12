__filename__ = "daemon_post_newswire.py"
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
from utils import clear_from_post_caches
from utils import remove_id_ending
from utils import save_json
from utils import first_paragraph_from_string
from utils import date_from_string_format
from utils import load_json
from utils import locate_post
from utils import acct_dir
from utils import get_instance_url
from utils import get_nickname_from_actor
from httpheaders import redirect_headers
from posts import is_moderator
from content import extract_text_fields_in_post
from content import load_dogwhistles


def newswire_update(self, calling_domain: str, cookie: str,
                    path: str, base_dir: str,
                    domain: str, debug: bool,
                    default_timeline: str,
                    http_prefix: str, domain_full: str,
                    onion_domain: str, i2p_domain: str,
                    max_post_length: int) -> None:
    """Updates the right newswire column of the timeline
    """
    users_path = path.replace('/newswiredata', '')
    users_path = users_path.replace('/editnewswire', '')
    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path

    boundary = None
    if ' boundary=' in self.headers['Content-type']:
        boundary = self.headers['Content-type'].split('boundary=')[1]
        if ';' in boundary:
            boundary = boundary.split(';')[0]

    # get the nickname
    nickname = get_nickname_from_actor(actor_str)
    moderator = None
    if nickname:
        moderator = is_moderator(base_dir, nickname)
    if not nickname or not moderator:
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
            print('Maximum newswire data length exceeded ' + str(length))
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

    newswire_filename = data_dir(base_dir) + '/newswire.txt'

    if not boundary:
        if b'--LYNX' in post_bytes:
            boundary = '--LYNX'

    if boundary:
        # extract all of the text fields into a dict
        fields = \
            extract_text_fields_in_post(post_bytes, boundary, debug, None)
        if fields.get('editedNewswire'):
            newswire_str = fields['editedNewswire']
            # append a new newswire entry
            if fields.get('newNewswireFeed'):
                if newswire_str:
                    if not newswire_str.endswith('\n'):
                        newswire_str += '\n'
                newswire_str += fields['newNewswireFeed'] + '\n'
            try:
                with open(newswire_filename, 'w+',
                          encoding='utf-8') as fp_news:
                    fp_news.write(newswire_str)
            except OSError:
                print('EX: unable to write ' + newswire_filename)
        else:
            if fields.get('newNewswireFeed'):
                # the text area is empty but there is a new feed added
                newswire_str = fields['newNewswireFeed'] + '\n'
                try:
                    with open(newswire_filename, 'w+',
                              encoding='utf-8') as fp_news:
                        fp_news.write(newswire_str)
                except OSError:
                    print('EX: unable to write ' + newswire_filename)
            else:
                # text area has been cleared and there is no new feed
                if os.path.isfile(newswire_filename):
                    try:
                        os.remove(newswire_filename)
                    except OSError:
                        print('EX: _newswire_update unable to delete ' +
                              newswire_filename)

        # save filtered words list for the newswire
        filter_newswire_filename = \
            data_dir(base_dir) + '/' + 'news@' + domain + '/filters.txt'
        if fields.get('filteredWordsNewswire'):
            try:
                with open(filter_newswire_filename, 'w+',
                          encoding='utf-8') as fp_filter:
                    fp_filter.write(fields['filteredWordsNewswire'])
            except OSError:
                print('EX: newswire_update unable to write ' +
                      filter_newswire_filename)
        else:
            if os.path.isfile(filter_newswire_filename):
                try:
                    os.remove(filter_newswire_filename)
                except OSError:
                    print('EX: _newswire_update unable to delete ' +
                          filter_newswire_filename)

        # save dogwhistle words list
        dogwhistles_filename = data_dir(base_dir) + '/dogwhistles.txt'
        if fields.get('dogwhistleWords'):
            try:
                with open(dogwhistles_filename, 'w+',
                          encoding='utf-8') as fp_dogwhistles:
                    fp_dogwhistles.write(fields['dogwhistleWords'])
            except OSError:
                print('EX: newswire_update unable to write 2 ' +
                      dogwhistles_filename)
            self.server.dogwhistles = \
                load_dogwhistles(dogwhistles_filename)
        else:
            # save an empty file
            try:
                with open(dogwhistles_filename, 'w+',
                          encoding='utf-8') as fp_dogwhistles:
                    fp_dogwhistles.write('')
            except OSError:
                print('EX: newswire_update unable unable to write 3 ' +
                      dogwhistles_filename)
            self.server.dogwhistles = {}

        # save news tagging rules
        hashtag_rules_filename = data_dir(base_dir) + '/hashtagrules.txt'
        if fields.get('hashtagRulesList'):
            try:
                with open(hashtag_rules_filename, 'w+',
                          encoding='utf-8') as fp_rules:
                    fp_rules.write(fields['hashtagRulesList'])
            except OSError:
                print('EX: newswire_update unable to write 4 ' +
                      hashtag_rules_filename)
        else:
            if os.path.isfile(hashtag_rules_filename):
                try:
                    os.remove(hashtag_rules_filename)
                except OSError:
                    print('EX: _newswire_update unable to delete ' +
                          hashtag_rules_filename)

        newswire_tusted_filename = data_dir(base_dir) + '/newswiretrusted.txt'
        if fields.get('trustedNewswire'):
            newswire_trusted = fields['trustedNewswire']
            if not newswire_trusted.endswith('\n'):
                newswire_trusted += '\n'
            try:
                with open(newswire_tusted_filename, 'w+',
                          encoding='utf-8') as fp_trust:
                    fp_trust.write(newswire_trusted)
            except OSError:
                print('EX: newswire_update unable to write 5 ' +
                      newswire_tusted_filename)
        else:
            if os.path.isfile(newswire_tusted_filename):
                try:
                    os.remove(newswire_tusted_filename)
                except OSError:
                    print('EX: _newswire_update unable to delete ' +
                          newswire_tusted_filename)

    # redirect back to the default timeline
    redirect_headers(self, actor_str + '/' + default_timeline,
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False


def citations_update(self, calling_domain: str, cookie: str,
                     path: str, base_dir: str,
                     domain: str, debug: bool,
                     newswire: {},
                     http_prefix: str, domain_full: str,
                     onion_domain: str, i2p_domain: str,
                     max_post_length: int) -> None:
    """Updates the citations for a blog post after hitting
    update button on the citations screen
    """
    users_path = path.replace('/citationsdata', '')
    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path
    nickname = get_nickname_from_actor(actor_str)
    if not nickname:
        self.server.postreq_busy = False
        return

    citations_filename = \
        acct_dir(base_dir, nickname, domain) + '/.citations.txt'
    # remove any existing citations file
    if os.path.isfile(citations_filename):
        try:
            os.remove(citations_filename)
        except OSError:
            print('EX: _citations_update unable to delete ' +
                  citations_filename)

    if newswire and \
       ' boundary=' in self.headers['Content-type']:
        boundary = self.headers['Content-type'].split('boundary=')[1]
        if ';' in boundary:
            boundary = boundary.split(';')[0]

        length = int(self.headers['Content-length'])

        # check that the POST isn't too large
        if length > max_post_length:
            print('Maximum citations data length exceeded ' + str(length))
            redirect_headers(self, actor_str, cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return

        try:
            # read the bytes of the http form POST
            post_bytes = self.rfile.read(length)
        except SocketError as ex:
            if ex.errno == errno.ECONNRESET:
                print('EX: connection was reset while ' +
                      'reading bytes from http form ' +
                      'citation screen POST')
            else:
                print('EX: error while reading bytes ' +
                      'from http form citations screen POST')
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return
        except ValueError as ex:
            print('EX: failed to read bytes for ' +
                  'citations screen POST, ' + str(ex))
            self.send_response(400)
            self.end_headers()
            self.server.postreq_busy = False
            return

        # extract all of the text fields into a dict
        fields = \
            extract_text_fields_in_post(post_bytes, boundary, debug, None)
        print('citationstest: ' + str(fields))
        citations: list[str] = []
        for ctr in range(0, 128):
            field_name = 'newswire' + str(ctr)
            if not fields.get(field_name):
                continue
            citations.append(fields[field_name])

        if citations:
            citations_str = ''
            for citation_date in citations:
                citations_str += citation_date + '\n'
            # save citations dates, so that they can be added when
            # reloading the newblog screen
            try:
                with open(citations_filename, 'w+',
                          encoding='utf-8') as fp_cit:
                    fp_cit.write(citations_str)
            except OSError:
                print('EX: citations_update unable to write ' +
                      citations_filename)

    # redirect back to the default timeline
    redirect_headers(self, actor_str + '/newblog',
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False


def news_post_edit(self, calling_domain: str, cookie: str,
                   path: str, base_dir: str,
                   domain: str, debug: bool,
                   http_prefix: str, domain_full: str,
                   onion_domain: str, i2p_domain: str,
                   news_instance: bool,
                   max_post_length: int,
                   system_language: str,
                   recent_posts_cache: {},
                   newswire: {}) -> None:
    """edits a news post after receiving POST
    """
    users_path = path.replace('/newseditdata', '')
    users_path = users_path.replace('/editnewspost', '')
    actor_str = \
        get_instance_url(calling_domain,
                         http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path

    boundary = None
    if ' boundary=' in self.headers['Content-type']:
        boundary = self.headers['Content-type'].split('boundary=')[1]
        if ';' in boundary:
            boundary = boundary.split(';')[0]

    # get the nickname
    nickname = get_nickname_from_actor(actor_str)
    editor_role = None
    if nickname:
        editor_role = is_editor(base_dir, nickname)
    if not nickname or not editor_role:
        if not nickname:
            print('WARN: nickname not found in ' + actor_str)
        else:
            print('WARN: nickname is not an editor' + actor_str)
        if news_instance:
            redirect_headers(self, actor_str + '/tlfeatures',
                             cookie, calling_domain, 303)
        else:
            redirect_headers(self, actor_str + '/tlnews',
                             cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    if self.headers.get('Content-length'):
        length = int(self.headers['Content-length'])

        # check that the POST isn't too large
        if length > max_post_length:
            print('Maximum news data length exceeded ' + str(length))
            if news_instance:
                redirect_headers(self, actor_str + '/tlfeatures',
                                 cookie, calling_domain, 303)
            else:
                redirect_headers(self, actor_str + '/tlnews',
                                 cookie, calling_domain, 303)
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

    if not boundary:
        if b'--LYNX' in post_bytes:
            boundary = '--LYNX'

    if boundary:
        # extract all of the text fields into a dict
        fields = \
            extract_text_fields_in_post(post_bytes, boundary, debug, None)
        news_post_url = None
        news_post_title = None
        news_post_content = None
        if fields.get('newsPostUrl'):
            news_post_url = fields['newsPostUrl']
        if fields.get('newsPostTitle'):
            news_post_title = fields['newsPostTitle']
        if fields.get('editedNewsPost'):
            news_post_content = fields['editedNewsPost']

        if news_post_url and news_post_content and news_post_title:
            # load the post
            post_filename = \
                locate_post(base_dir, nickname, domain,
                            news_post_url)
            if post_filename:
                post_json_object = load_json(post_filename)

                # update the content and title
                post_json_object['object']['summary'] = \
                    news_post_title
                post_json_object['object']['content'] = \
                    news_post_content
                content_map = post_json_object['object']['contentMap']
                content_map[system_language] = \
                    news_post_content

                # update newswire
                pub_date = post_json_object['object']['published']
                published_date = \
                    date_from_string_format(pub_date,
                                            ["%Y-%m-%dT%H:%M:%S%z"])
                if newswire.get(str(published_date)):
                    newswire[published_date][0] = news_post_title
                    newswire[published_date][4] = \
                        first_paragraph_from_string(news_post_content)
                    # save newswire
                    newswire_state_filename = \
                        data_dir(base_dir) + '/.newswirestate.json'
                    try:
                        save_json(newswire, newswire_state_filename)
                    except BaseException as ex:
                        print('EX: saving newswire state, ' + str(ex))

                # remove any previous cached news posts
                news_id = \
                    remove_id_ending(post_json_object['object']['id'])
                news_id = news_id.replace('/', '#')
                clear_from_post_caches(base_dir, recent_posts_cache,
                                       news_id)

                # save the news post
                save_json(post_json_object, post_filename)

    # redirect back to the default timeline
    if news_instance:
        redirect_headers(self, actor_str + '/tlfeatures',
                         cookie, calling_domain, 303)
    else:
        redirect_headers(self, actor_str + '/tlnews',
                         cookie, calling_domain, 303)
    self.server.postreq_busy = False
