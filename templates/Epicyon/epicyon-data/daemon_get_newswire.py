__filename__ = "daemon_get_newswire.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import urllib.parse
from session import establish_session
from httpcodes import write2
from httpcodes import http_404
from httpheaders import redirect_headers
from httpheaders import set_headers
from newswire import get_rss_from_dict
from fitnessFunctions import fitness_performance
from posts import is_moderator
from utils import data_dir
from utils import local_actor_url
from utils import save_json
from webapp_column_right import html_edit_news_post
from webapp_column_right import html_edit_newswire


def get_newswire_feed(self, calling_domain: str, path: str,
                      proxy_type: str, getreq_start_time,
                      debug: bool, curr_session,
                      newswire: {}, http_prefix: str,
                      domain_full: str, translate: {},
                      fitness: {}) -> None:
    """Returns the newswire feed
    """
    curr_session = \
        establish_session("get_newswire_feed",
                          curr_session,
                          proxy_type,
                          self.server)
    if not curr_session:
        http_404(self, 25)
        return

    msg = get_rss_from_dict(newswire, http_prefix, domain_full, translate)
    if msg:
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/xml', msglen,
                    None, calling_domain, True)
        write2(self, msg)
        if debug:
            print('Sent rss2 newswire feed: ' +
                  path + ' ' + calling_domain)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_get_newswire_feed',
                            debug)
        return
    if debug:
        print('Failed to get rss2 newswire feed: ' +
              path + ' ' + calling_domain)
    http_404(self, 26)


def newswire_vote(self, calling_domain: str, path: str,
                  cookie: str,
                  base_dir: str, http_prefix: str,
                  domain_full: str,
                  onion_domain: str, i2p_domain: str,
                  getreq_start_time,
                  newswire: {}, default_timeline: str,
                  fitness: {}, debug: bool) -> None:
    """Vote for a newswire item
    """
    origin_path_str = path.split('/newswirevote=')[0]
    date_str = \
        path.split('/newswirevote=')[1].replace('T', ' ')
    date_str = date_str.replace(' 00:00', '').replace('+00:00', '')
    date_str = urllib.parse.unquote_plus(date_str) + '+00:00'
    nickname = \
        urllib.parse.unquote_plus(origin_path_str.split('/users/')[1])
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    print('Newswire item date: ' + date_str)
    if newswire.get(date_str):
        if is_moderator(base_dir, nickname):
            newswire_item = newswire[date_str]
            print('Voting on newswire item: ' + str(newswire_item))
            votes_index = 2
            filename_index = 3
            if 'vote:' + nickname not in newswire_item[votes_index]:
                newswire_item[votes_index].append('vote:' + nickname)
                filename = newswire_item[filename_index]
                newswire_state_filename = \
                    data_dir(base_dir) + '/.newswirestate.json'
                try:
                    save_json(newswire, newswire_state_filename)
                except BaseException as ex:
                    print('EX: saving newswire state, ' + str(ex))
                if filename:
                    save_json(newswire_item[votes_index],
                              filename + '.votes')
    else:
        print('No newswire item with date: ' + date_str + ' ' +
              str(newswire))

    origin_path_str_absolute = \
        http_prefix + '://' + domain_full + origin_path_str + '/' + \
        default_timeline
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str_absolute = \
            'http://' + onion_domain + origin_path_str
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str_absolute = \
            'http://' + i2p_domain + origin_path_str
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_newswire_vote',
                        debug)
    redirect_headers(self, origin_path_str_absolute,
                     cookie, calling_domain, 303)


def newswire_unvote(self, calling_domain: str, path: str,
                    cookie: str, base_dir: str, http_prefix: str,
                    domain_full: str,
                    onion_domain: str, i2p_domain: str,
                    getreq_start_time, debug: bool,
                    newswire: {}, default_timeline: str,
                    fitness: {}) -> None:
    """Remove vote for a newswire item
    """
    origin_path_str = path.split('/newswireunvote=')[0]
    date_str = \
        path.split('/newswireunvote=')[1].replace('T', ' ')
    date_str = date_str.replace(' 00:00', '').replace('+00:00', '')
    date_str = urllib.parse.unquote_plus(date_str) + '+00:00'
    nickname = \
        urllib.parse.unquote_plus(origin_path_str.split('/users/')[1])
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    if newswire.get(date_str):
        if is_moderator(base_dir, nickname):
            votes_index = 2
            filename_index = 3
            newswire_item = newswire[date_str]
            if 'vote:' + nickname in newswire_item[votes_index]:
                newswire_item[votes_index].remove('vote:' + nickname)
                filename = newswire_item[filename_index]
                newswire_state_filename = \
                    data_dir(base_dir) + '/.newswirestate.json'
                try:
                    save_json(newswire, newswire_state_filename)
                except BaseException as ex:
                    print('EX: saving newswire state, ' + str(ex))
                if filename:
                    save_json(newswire_item[votes_index],
                              filename + '.votes')
    else:
        print('No newswire item with date: ' + date_str + ' ' +
              str(newswire))

    origin_path_str_absolute = \
        http_prefix + '://' + domain_full + origin_path_str + '/' + \
        default_timeline
    if calling_domain.endswith('.onion') and onion_domain:
        origin_path_str_absolute = \
            'http://' + onion_domain + origin_path_str
    elif (calling_domain.endswith('.i2p') and i2p_domain):
        origin_path_str_absolute = \
            'http://' + i2p_domain + origin_path_str
    redirect_headers(self, origin_path_str_absolute,
                     cookie, calling_domain, 303)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', '_newswire_unvote', debug)


def edit_newswire2(self, calling_domain: str, path: str,
                   translate: {}, base_dir: str,
                   domain: str, cookie: str,
                   access_keys: {},
                   key_shortcuts: {},
                   default_timeline: str,
                   theme_name: str,
                   dogwhistles: {}) -> bool:
    """Show the newswire from the right column
    """
    if '/users/' in path and path.endswith('/editnewswire'):
        nickname = path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]

        if key_shortcuts.get(nickname):
            access_keys = key_shortcuts[nickname]

        msg = html_edit_newswire(translate,
                                 base_dir,
                                 path, domain,
                                 default_timeline,
                                 theme_name,
                                 access_keys,
                                 dogwhistles)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
        else:
            http_404(self, 107)
        return True
    return False


def edit_news_post2(self, calling_domain: str, path: str,
                    translate: {}, base_dir: str,
                    http_prefix: str, domain: str,
                    domain_full: str, cookie: str,
                    system_language: str) -> bool:
    """Show the edit screen for a news post
    """
    if '/users/' in path and '/editnewspost=' in path:
        post_actor = 'news'
        if '?actor=' in path:
            post_actor = path.split('?actor=')[1]
            if '?' in post_actor:
                post_actor = post_actor.split('?')[0]
        post_id = path.split('/editnewspost=')[1]
        if '?' in post_id:
            post_id = post_id.split('?')[0]
        post_url = \
            local_actor_url(http_prefix, post_actor, domain_full) + \
            '/statuses/' + post_id
        path = path.split('/editnewspost=')[0]
        msg = html_edit_news_post(translate, base_dir,
                                  path, domain,
                                  post_url,
                                  system_language)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
        else:
            http_404(self, 108)
        return True
    return False
