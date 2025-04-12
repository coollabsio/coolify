__filename__ = "daemon_get_post.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon Timeline"

import os
import json
from webapp_conversation import html_conversation_view
from flags import is_public_post_from_url
from flags import is_public_post
from flags import is_premium_account
from utils import get_instance_url
from utils import local_actor_url
from utils import locate_post
from utils import get_config_param
from utils import can_reply_to
from utils import get_nickname_from_actor
from utils import get_new_post_endpoints
from utils import acct_dir
from utils import get_json_content_from_accept
from utils import convert_domains
from utils import has_object_dict
from utils import load_json
from utils import detect_mitm
from session import establish_session
from languages import get_understood_languages
from languages import get_reply_language
from httpcodes import write2
from httpcodes import http_401
from httpcodes import http_403
from httpcodes import http_404
from httpheaders import set_headers
from httpheaders import set_html_post_headers
from httpheaders import login_headers
from httpheaders import redirect_headers
from httprequests import request_http
from posts import populate_replies_json
from posts import remove_post_interactions
from webapp_post import html_post_replies
from webapp_post import html_individual_post
from webapp_create_post import html_new_post
from webapp_likers import html_likers_of_post
from fitnessFunctions import fitness_performance
from securemode import secure_mode
from context import get_individual_post_context
from conversation import convthread_id_to_conversation_tag


def _show_post_from_file(self, post_filename: str, liked_by: str,
                         react_by: str, react_emoji: str,
                         authorized: bool,
                         calling_domain: str, referer_domain: str,
                         base_dir: str, http_prefix: str, nickname: str,
                         domain: str, port: int,
                         getreq_start_time,
                         proxy_type: str, cookie: str,
                         debug: str, include_create_wrapper: bool,
                         curr_session, translate: {},
                         account_timezone: {},
                         bold_reading_nicknames: {},
                         recent_posts_cache: {},
                         max_recent_posts: int,
                         cached_webfingers: {},
                         person_cache: {},
                         project_version: str,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         show_published_date_only: bool,
                         peertube_instances: [],
                         allow_local_network_access: bool,
                         theme_name: str,
                         system_language: str,
                         max_like_count: int,
                         signing_priv_key_pem: str,
                         cw_lists: {},
                         lists_enabled: {},
                         dogwhistles: {},
                         min_images_for_accounts: [],
                         buy_sites: [],
                         auto_cw_cache: {},
                         fitness: {}, path: str,
                         onion_domain: str,
                         i2p_domain: str,
                         mitm_servers: [],
                         instance_software: {}) -> bool:
    """Shows an individual post from its filename
    """
    if not os.path.isfile(post_filename):
        http_404(self, 71)
        self.server.getreq_busy = False
        return True

    # if this is a premium account and the viewer is unauthorized
    if not authorized:
        if is_premium_account(base_dir, nickname, domain):
            http_401(self, translate['Premium account'])
            self.server.getreq_busy = False
            return True

    post_json_object = load_json(post_filename)
    if not post_json_object:
        self.send_response(429)
        self.end_headers()
        self.server.getreq_busy = False
        return True

    # Only authorized viewers get to see likes on posts
    # Otherwize marketers could gain more social graph info
    if not authorized:
        pjo = post_json_object
        if not is_public_post(pjo):
            # only public posts may be viewed by unauthorized viewers
            http_401(self, 'only public posts ' +
                     'may be viewed by unauthorized viewers')
            self.server.getreq_busy = False
            return True
        remove_post_interactions(pjo, True)
    if request_http(self.headers, debug):
        timezone = None
        if account_timezone.get(nickname):
            timezone = account_timezone.get(nickname)

        mitm = detect_mitm(self)
        if not mitm:
            mitm_filename = \
                post_filename.replace('.json', '') + '.mitm'
            if os.path.isfile(mitm_filename):
                mitm = True

        bold_reading = False
        if bold_reading_nicknames.get(nickname):
            bold_reading = True

        msg = \
            html_individual_post(recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 base_dir,
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 nickname, domain, port,
                                 authorized,
                                 post_json_object,
                                 http_prefix,
                                 project_version,
                                 liked_by, react_by, react_emoji,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 peertube_instances,
                                 allow_local_network_access,
                                 theme_name,
                                 system_language,
                                 max_like_count,
                                 signing_priv_key_pem,
                                 cw_lists,
                                 lists_enabled,
                                 timezone, mitm, bold_reading,
                                 dogwhistles,
                                 min_images_for_accounts,
                                 buy_sites,
                                 auto_cw_cache,
                                 mitm_servers,
                                 instance_software)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_html_post_headers(self, msglen,
                              cookie, calling_domain, False,
                              post_json_object)
        write2(self, msg)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_post_from_file',
                            debug)
    else:
        if secure_mode(curr_session, proxy_type, False,
                       self.server, self.headers, path):
            if not include_create_wrapper and \
               post_json_object['type'] == 'Create' and \
               has_object_dict(post_json_object):
                unwrapped_json = post_json_object['object']
                unwrapped_json['@context'] = \
                    get_individual_post_context()
                msg_str = json.dumps(unwrapped_json,
                                     ensure_ascii=False)
            else:
                msg_str = json.dumps(post_json_object,
                                     ensure_ascii=False)
            msg_str = convert_domains(calling_domain,
                                      referer_domain,
                                      msg_str, http_prefix,
                                      domain,
                                      onion_domain,
                                      i2p_domain)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            protocol_str = \
                get_json_content_from_accept(self.headers['Accept'])
            set_headers(self, protocol_str, msglen,
                        None, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_post_from_file json',
                                debug)
        else:
            http_404(self, 73)
    self.server.getreq_busy = False
    return True


def show_individual_post(self, ssml_getreq: bool, authorized: bool,
                         calling_domain: str, referer_domain: str,
                         path: str,
                         base_dir: str, http_prefix: str,
                         domain: str, domain_full: str, port: int,
                         getreq_start_time,
                         proxy_type: str, cookie: str,
                         debug: str,
                         curr_session, translate: {},
                         account_timezone: {},
                         fitness: {},
                         bold_reading_nicknames: {},
                         recent_posts_cache: {},
                         max_recent_posts: int,
                         cached_webfingers: {},
                         person_cache: {},
                         project_version: str,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         show_published_date_only: bool,
                         peertube_instances: [],
                         allow_local_network_access: bool,
                         theme_name: str,
                         system_language: str,
                         max_like_count: int,
                         signing_priv_key_pem: str,
                         cw_lists: {},
                         lists_enabled: {},
                         dogwhistles: {},
                         min_images_for_accounts: [],
                         buy_sites: [],
                         auto_cw_cache: {},
                         onion_domain: str,
                         i2p_domain: str,
                         mitm_servers: [],
                         instance_software: {}) -> bool:
    """Shows an individual post
    """
    liked_by = None
    if '?likedBy=' in path:
        liked_by = path.split('?likedBy=')[1].strip()
        if '?' in liked_by:
            liked_by = liked_by.split('?')[0]
        path = path.split('?likedBy=')[0]

    react_by = None
    react_emoji = None
    if '?reactBy=' in path:
        react_by = path.split('?reactBy=')[1].strip()
        if ';' in react_by:
            react_by = react_by.split(';')[0]
        if ';emoj=' in path:
            react_emoji = path.split(';emoj=')[1].strip()
            if ';' in react_emoji:
                react_emoji = react_emoji.split(';')[0]
        path = path.split('?reactBy=')[0]

    named_status = path.split('/users/')[1]
    if '/' not in named_status:
        return False
    post_sections = named_status.split('/')
    if len(post_sections) < 3:
        return False
    nickname = post_sections[0]
    status_number = post_sections[2]
    if len(status_number) <= 10 or (not status_number.isdigit()):
        return False

    if ssml_getreq:
        ssml_filename = \
            acct_dir(base_dir, nickname, domain) + '/outbox/' + \
            http_prefix + ':##' + domain_full + '#users#' + nickname + \
            '#statuses#' + status_number + '.ssml'
        if not os.path.isfile(ssml_filename):
            ssml_filename = \
                acct_dir(base_dir, nickname, domain) + '/postcache/' + \
                http_prefix + ':##' + domain_full + '#users#' + \
                nickname + '#statuses#' + status_number + '.ssml'
        if not os.path.isfile(ssml_filename):
            http_404(self, 74)
            return True
        ssml_str = None
        try:
            with open(ssml_filename, 'r', encoding='utf-8') as fp_ssml:
                ssml_str = fp_ssml.read()
        except OSError:
            print('EX: unable to read ssml file ' + ssml_filename)
        if ssml_str:
            msg = ssml_str.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'application/ssml+xml', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            return True
        http_404(self, 75)
        return True

    post_filename = \
        acct_dir(base_dir, nickname, domain) + '/outbox/' + \
        http_prefix + ':##' + domain_full + '#users#' + nickname + \
        '#statuses#' + status_number + '.json'

    include_create_wrapper = False
    if post_sections[-1] == 'activity':
        include_create_wrapper = True

    result = _show_post_from_file(self, post_filename, liked_by,
                                  react_by, react_emoji,
                                  authorized, calling_domain,
                                  referer_domain,
                                  base_dir, http_prefix, nickname,
                                  domain, port,
                                  getreq_start_time,
                                  proxy_type, cookie, debug,
                                  include_create_wrapper,
                                  curr_session, translate,
                                  account_timezone,
                                  bold_reading_nicknames,
                                  recent_posts_cache,
                                  max_recent_posts,
                                  cached_webfingers,
                                  person_cache,
                                  project_version,
                                  yt_replace_domain,
                                  twitter_replacement_domain,
                                  show_published_date_only,
                                  peertube_instances,
                                  allow_local_network_access,
                                  theme_name,
                                  system_language,
                                  max_like_count,
                                  signing_priv_key_pem,
                                  cw_lists,
                                  lists_enabled,
                                  dogwhistles,
                                  min_images_for_accounts,
                                  buy_sites,
                                  auto_cw_cache,
                                  fitness, path,
                                  onion_domain,
                                  i2p_domain,
                                  mitm_servers,
                                  instance_software)

    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_individual_post',
                        debug)
    return result


def show_new_post(self, edit_post_params: {},
                  calling_domain: str, path: str,
                  media_instance: bool, translate: {},
                  base_dir: str, http_prefix: str,
                  in_reply_to_url: str, reply_to_list: [],
                  reply_is_chat: bool,
                  share_description: str, reply_page_number: int,
                  reply_category: str,
                  domain: str, domain_full: str,
                  getreq_start_time, cookie,
                  no_drop_down: bool, conversation_id: str,
                  convthread_id: str,
                  curr_session, default_reply_interval_hrs: int,
                  debug: bool, access_keys: {},
                  key_shortcuts: {}, system_language: str,
                  default_post_language: {},
                  bold_reading_nicknames: {},
                  person_cache: {},
                  fitness: {},
                  default_timeline: str,
                  newswire: {},
                  theme_name: str,
                  recent_posts_cache: {},
                  max_recent_posts: int,
                  cached_webfingers: {},
                  port: int,
                  project_version: str,
                  yt_replace_domain: str,
                  twitter_replacement_domain: str,
                  show_published_date_only: bool,
                  peertube_instances: [],
                  allow_local_network_access: bool,
                  max_like_count: int,
                  signing_priv_key_pem: str,
                  cw_lists: {},
                  lists_enabled: {},
                  dogwhistles: {},
                  min_images_for_accounts: [],
                  buy_sites: [],
                  auto_cw_cache: {},
                  searchable_by_default_dict: [],
                  mitm_servers: [],
                  instance_software: {}) -> bool:
    """Shows the new post screen
    """
    searchable_by_default = 'yourself'
    is_new_post_endpoint = False
    new_post_month = None
    new_post_year = None
    if '/users/' in path and '/new' in path:
        if '?month=' in path:
            month_str = path.split('?month=')[1]
            if ';' in month_str:
                month_str = month_str.split(';')[0]
            if month_str.isdigit():
                new_post_month = int(month_str)
        if new_post_month and ';year=' in path:
            year_str = path.split(';year=')[1]
            if ';' in year_str:
                year_str = year_str.split(';')[0]
            if year_str.isdigit():
                new_post_year = int(year_str)
            if new_post_year:
                path = path.split('?month=')[0]
        # Various types of new post in the web interface
        new_post_endpoints = get_new_post_endpoints()
        for curr_post_type in new_post_endpoints:
            if path.endswith('/' + curr_post_type):
                is_new_post_endpoint = True
                break
    if is_new_post_endpoint:
        nickname = get_nickname_from_actor(path)
        if not nickname:
            http_404(self, 103)
            return True
        if searchable_by_default_dict.get(nickname):
            searchable_by_default = searchable_by_default_dict[nickname]
        if in_reply_to_url:
            reply_interval_hours = default_reply_interval_hrs
            if not can_reply_to(base_dir, nickname, domain,
                                in_reply_to_url, reply_interval_hours):
                print('Reply outside of time window ' + in_reply_to_url +
                      ' ' + str(reply_interval_hours) + ' hours')
                http_403(self)
                return True
            if debug:
                print('Reply is within time interval: ' +
                      str(reply_interval_hours) + ' hours')

        if key_shortcuts.get(nickname):
            access_keys = key_shortcuts[nickname]

        custom_submit_text = get_config_param(base_dir, 'customSubmitText')

        default_post_language2 = system_language
        if default_post_language.get(nickname):
            default_post_language2 = default_post_language[nickname]

        post_json_object = None
        if in_reply_to_url:
            reply_post_filename = \
                locate_post(base_dir, nickname, domain, in_reply_to_url)
            if reply_post_filename:
                post_json_object = load_json(reply_post_filename)
                if post_json_object:
                    reply_language = \
                        get_reply_language(base_dir, post_json_object)
                    if reply_language:
                        default_post_language2 = reply_language

        bold_reading = False
        if bold_reading_nicknames.get(nickname):
            bold_reading = True

        languages_understood = \
            get_understood_languages(base_dir,
                                     http_prefix,
                                     nickname,
                                     domain_full,
                                     person_cache)
        default_buy_site = ''
        msg = \
            html_new_post(edit_post_params, media_instance,
                          translate,
                          base_dir,
                          http_prefix,
                          path, in_reply_to_url,
                          reply_to_list,
                          share_description, None,
                          reply_page_number,
                          reply_category,
                          nickname, domain,
                          domain_full,
                          default_timeline,
                          newswire,
                          theme_name,
                          no_drop_down, access_keys,
                          custom_submit_text,
                          conversation_id, convthread_id,
                          recent_posts_cache,
                          max_recent_posts,
                          curr_session,
                          cached_webfingers,
                          person_cache,
                          port,
                          post_json_object,
                          project_version,
                          yt_replace_domain,
                          twitter_replacement_domain,
                          show_published_date_only,
                          peertube_instances,
                          allow_local_network_access,
                          system_language,
                          languages_understood,
                          max_like_count,
                          signing_priv_key_pem,
                          cw_lists,
                          lists_enabled,
                          default_timeline,
                          reply_is_chat,
                          bold_reading,
                          dogwhistles,
                          min_images_for_accounts,
                          new_post_month, new_post_year,
                          default_post_language2,
                          buy_sites,
                          default_buy_site,
                          auto_cw_cache,
                          searchable_by_default,
                          mitm_servers,
                          instance_software)
        if not msg:
            print('Error replying to ' + in_reply_to_url)
            http_404(self, 104)
            return True
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_new_post',
                            debug)
        return True
    return False


def show_individual_at_post(self, ssml_getreq: bool, authorized: bool,
                            calling_domain: str, referer_domain: str,
                            path: str,
                            base_dir: str, http_prefix: str,
                            domain: str, domain_full: str, port: int,
                            getreq_start_time,
                            proxy_type: str, cookie: str,
                            debug: str,
                            curr_session, translate: {},
                            account_timezone: {},
                            fitness: {},
                            recent_posts_cache: {},
                            max_recent_posts: int,
                            cached_webfingers: {},
                            person_cache: {},
                            project_version: str,
                            yt_replace_domain: str,
                            twitter_replacement_domain: str,
                            show_published_date_only: bool,
                            peertube_instances: [],
                            allow_local_network_access: bool,
                            theme_name: str,
                            system_language: str,
                            max_like_count: int,
                            signing_priv_key_pem: str,
                            cw_lists: {},
                            lists_enabled: {},
                            dogwhistles: {},
                            min_images_for_accounts: [],
                            buy_sites: [],
                            auto_cw_cache: {},
                            onion_domain: str,
                            i2p_domain: str,
                            bold_reading_nicknames: {},
                            mitm_servers: [],
                            instance_software: {}) -> bool:
    """get an individual post from the path /@nickname/statusnumber
    """
    if '/@' not in path:
        return False

    liked_by = None
    if '?likedBy=' in path:
        liked_by = path.split('?likedBy=')[1].strip()
        if '?' in liked_by:
            liked_by = liked_by.split('?')[0]
        path = path.split('?likedBy=')[0]

    react_by = None
    react_emoji = None
    if '?reactBy=' in path:
        react_by = path.split('?reactBy=')[1].strip()
        if ';' in react_by:
            react_by = react_by.split(';')[0]
        if ';emoj=' in path:
            react_emoji = path.split(';emoj=')[1].strip()
            if ';' in react_emoji:
                react_emoji = react_emoji.split(';')[0]
        path = path.split('?reactBy=')[0]

    named_status = path.split('/@')[1]
    if '/' not in named_status:
        # show actor
        nickname = named_status
        return False

    post_sections = named_status.split('/')
    if len(post_sections) != 2:
        return False
    nickname = post_sections[0]
    status_number = post_sections[1]
    if len(status_number) <= 10 or not status_number.isdigit():
        return False

    if ssml_getreq:
        ssml_filename = \
            acct_dir(base_dir, nickname, domain) + '/outbox/' + \
            http_prefix + ':##' + domain_full + '#users#' + nickname + \
            '#statuses#' + status_number + '.ssml'
        if not os.path.isfile(ssml_filename):
            ssml_filename = \
                acct_dir(base_dir, nickname, domain) + '/postcache/' + \
                http_prefix + ':##' + domain_full + '#users#' + \
                nickname + '#statuses#' + status_number + '.ssml'
        if not os.path.isfile(ssml_filename):
            http_404(self, 67)
            return True
        ssml_str = None
        try:
            with open(ssml_filename, 'r', encoding='utf-8') as fp_ssml:
                ssml_str = fp_ssml.read()
        except OSError:
            print('EX: unable to read ssml file 2 ' + ssml_filename)
        if ssml_str:
            msg = ssml_str.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'application/ssml+xml', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            return True
        http_404(self, 68)
        return True

    post_filename = \
        acct_dir(base_dir, nickname, domain) + '/outbox/' + \
        http_prefix + ':##' + domain_full + '#users#' + nickname + \
        '#statuses#' + status_number + '.json'

    include_create_wrapper = False
    if post_sections[-1] == 'activity':
        include_create_wrapper = True

    result = _show_post_from_file(self, post_filename, liked_by,
                                  react_by, react_emoji,
                                  authorized, calling_domain,
                                  referer_domain,
                                  base_dir, http_prefix, nickname,
                                  domain, port,
                                  getreq_start_time,
                                  proxy_type, cookie, debug,
                                  include_create_wrapper,
                                  curr_session, translate,
                                  account_timezone,
                                  bold_reading_nicknames,
                                  recent_posts_cache,
                                  max_recent_posts,
                                  cached_webfingers,
                                  person_cache,
                                  project_version,
                                  yt_replace_domain,
                                  twitter_replacement_domain,
                                  show_published_date_only,
                                  peertube_instances,
                                  allow_local_network_access,
                                  theme_name,
                                  system_language,
                                  max_like_count,
                                  signing_priv_key_pem,
                                  cw_lists,
                                  lists_enabled,
                                  dogwhistles,
                                  min_images_for_accounts,
                                  buy_sites,
                                  auto_cw_cache,
                                  fitness, path,
                                  onion_domain,
                                  i2p_domain,
                                  mitm_servers,
                                  instance_software)

    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_individual_at_post',
                        debug)
    return result


def show_likers_of_post(self, authorized: bool,
                        calling_domain: str, path: str,
                        base_dir: str, http_prefix: str,
                        domain: str, port: int,
                        getreq_start_time, cookie: str,
                        debug: str, curr_session,
                        bold_reading_nicknames: {},
                        translate: {},
                        theme_name: str,
                        access_keys: {},
                        recent_posts_cache: {},
                        max_recent_posts: int,
                        cached_webfingers: {},
                        person_cache: {},
                        project_version: str,
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        system_language: str,
                        max_like_count: int,
                        signing_priv_key_pem: str,
                        cw_lists: {},
                        lists_enabled: {},
                        default_timeline: str,
                        dogwhistles: {},
                        min_images_for_accounts: [],
                        buy_sites: [],
                        auto_cw_cache: {},
                        fitness: {},
                        mitm_servers: [],
                        instance_software: {}) -> bool:
    """Show the likers of a post
    """
    if not authorized:
        return False
    if '?likers=' not in path:
        return False
    if '/users/' not in path:
        return False
    nickname = path.split('/users/')[1]
    if '?' in nickname:
        nickname = nickname.split('?')[0]
    post_url = path.split('?likers=')[1]
    if '?' in post_url:
        post_url = post_url.split('?')[0]
    post_url = post_url.replace('--', '/')

    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True

    msg = \
        html_likers_of_post(base_dir, nickname, domain, port,
                            post_url, translate,
                            http_prefix,
                            theme_name,
                            access_keys,
                            recent_posts_cache,
                            max_recent_posts,
                            curr_session,
                            cached_webfingers,
                            person_cache,
                            project_version,
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            system_language,
                            max_like_count,
                            signing_priv_key_pem,
                            cw_lists,
                            lists_enabled,
                            'inbox', default_timeline,
                            bold_reading,
                            dogwhistles,
                            min_images_for_accounts,
                            buy_sites,
                            auto_cw_cache, 'likes',
                            mitm_servers,
                            instance_software)
    if not msg:
        http_404(self, 69)
        return True
    msg = msg.encode('utf-8')
    msglen = len(msg)
    set_headers(self, 'text/html', msglen,
                cookie, calling_domain, False)
    write2(self, msg)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_likers_of_post',
                        debug)
    return True


def show_announcers_of_post(self, authorized: bool,
                            calling_domain: str, path: str,
                            base_dir: str, http_prefix: str,
                            domain: str, port: int,
                            getreq_start_time, cookie: str,
                            debug: str, curr_session,
                            bold_reading_nicknames: {},
                            translate: {},
                            theme_name: str,
                            access_keys: {},
                            recent_posts_cache: {},
                            max_recent_posts: int,
                            cached_webfingers: {},
                            person_cache: {},
                            project_version: str,
                            yt_replace_domain: str,
                            twitter_replacement_domain: str,
                            show_published_date_only: bool,
                            peertube_instances: [],
                            allow_local_network_access: bool,
                            system_language: str,
                            max_like_count: int,
                            signing_priv_key_pem: str,
                            cw_lists: {},
                            lists_enabled: {},
                            default_timeline: str,
                            dogwhistles: {},
                            min_images_for_accounts: [],
                            buy_sites: [],
                            auto_cw_cache: {},
                            fitness: {},
                            mitm_servers: [],
                            instance_software: {}) -> bool:
    """Show the announcers of a post
    """
    if not authorized:
        return False
    if '?announcers=' not in path:
        return False
    if '/users/' not in path:
        return False
    nickname = path.split('/users/')[1]
    if '?' in nickname:
        nickname = nickname.split('?')[0]
    post_url = path.split('?announcers=')[1]
    if '?' in post_url:
        post_url = post_url.split('?')[0]
    post_url = post_url.replace('--', '/')

    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True

    # note that the likers function is reused, but with 'shares'
    msg = \
        html_likers_of_post(base_dir, nickname, domain, port,
                            post_url, translate,
                            http_prefix,
                            theme_name,
                            access_keys,
                            recent_posts_cache,
                            max_recent_posts,
                            curr_session,
                            cached_webfingers,
                            person_cache,
                            project_version,
                            yt_replace_domain,
                            twitter_replacement_domain,
                            show_published_date_only,
                            peertube_instances,
                            allow_local_network_access,
                            system_language,
                            max_like_count,
                            signing_priv_key_pem,
                            cw_lists,
                            lists_enabled,
                            'inbox', default_timeline,
                            bold_reading, dogwhistles,
                            min_images_for_accounts,
                            buy_sites,
                            auto_cw_cache,
                            'shares', mitm_servers,
                            instance_software)
    if not msg:
        http_404(self, 70)
        return True
    msg = msg.encode('utf-8')
    msglen = len(msg)
    set_headers(self, 'text/html', msglen,
                cookie, calling_domain, False)
    write2(self, msg)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_announcers_of_post',
                        debug)
    return True


def show_replies_to_post(self, authorized: bool,
                         calling_domain: str, referer_domain: str,
                         path: str, base_dir: str, http_prefix: str,
                         domain: str, domain_full: str, port: int,
                         getreq_start_time,
                         proxy_type: str, cookie: str,
                         debug: str, curr_session,
                         recent_posts_cache: {},
                         max_recent_posts: int,
                         translate: {},
                         cached_webfingers: {},
                         person_cache: {},
                         project_version: str,
                         yt_replace_domain: str,
                         twitter_replacement_domain: str,
                         peertube_instances: [],
                         account_timezone: {},
                         bold_reading_nicknames: {},
                         show_published_date_only: bool,
                         allow_local_network_access: bool,
                         theme_name: str,
                         system_language: str,
                         max_like_count: int,
                         signing_priv_key_pem: str,
                         cw_lists: {},
                         lists_enabled: {},
                         dogwhistles: {},
                         min_images_for_accounts: [],
                         buy_sites: [],
                         auto_cw_cache: {},
                         fitness: {},
                         onion_domain: str,
                         i2p_domain: str,
                         mitm_servers: [],
                         instance_software: {}) -> bool:
    """Shows the replies to a post
    """
    if not ('/statuses/' in path and '/users/' in path):
        return False

    named_status = path.split('/users/')[1]
    if '/' not in named_status:
        return False

    post_sections = named_status.split('/')
    if len(post_sections) < 4:
        return False

    if not post_sections[3].startswith('replies'):
        return False
    nickname = post_sections[0]
    status_number = post_sections[2]
    if not (len(status_number) > 10 and status_number.isdigit()):
        return False

    boxname = 'outbox'
    # get the replies file
    post_dir = \
        acct_dir(base_dir, nickname, domain) + '/' + boxname
    orig_post_url = http_prefix + ':##' + domain_full + '#users#' + \
        nickname + '#statuses#' + status_number
    post_replies_filename = \
        post_dir + '/' + orig_post_url + '.replies'
    if not os.path.isfile(post_replies_filename):
        # There are no replies,
        # so show empty collection
        first_str = \
            local_actor_url(http_prefix, nickname, domain_full) + \
            '/statuses/' + status_number + '/replies?page=true'

        id_str = \
            local_actor_url(http_prefix, nickname, domain_full) + \
            '/statuses/' + status_number + '/replies'

        last_str = \
            local_actor_url(http_prefix, nickname, domain_full) + \
            '/statuses/' + status_number + '/replies?page=true'

        replies_json = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'first': first_str,
            'id': id_str,
            'last': last_str,
            'totalItems': 0,
            'type': 'OrderedCollection'
        }

        if request_http(self.headers, debug):
            curr_session = \
                establish_session("show_replies_to_post",
                                  curr_session, proxy_type,
                                  self.server)
            if not curr_session:
                http_404(self, 61)
                return True
            yt_domain = yt_replace_domain
            timezone = None
            if account_timezone.get(nickname):
                timezone = account_timezone.get(nickname)
            bold_reading = False
            if bold_reading_nicknames.get(nickname):
                bold_reading = True
            msg = \
                html_post_replies(recent_posts_cache,
                                  max_recent_posts,
                                  translate,
                                  base_dir,
                                  curr_session,
                                  cached_webfingers,
                                  person_cache,
                                  nickname,
                                  domain,
                                  port,
                                  replies_json,
                                  http_prefix,
                                  project_version,
                                  yt_domain,
                                  twitter_replacement_domain,
                                  show_published_date_only,
                                  peertube_instances,
                                  allow_local_network_access,
                                  theme_name,
                                  system_language,
                                  max_like_count,
                                  signing_priv_key_pem,
                                  cw_lists,
                                  lists_enabled,
                                  timezone, bold_reading,
                                  dogwhistles,
                                  min_images_for_accounts,
                                  buy_sites,
                                  auto_cw_cache,
                                  mitm_servers,
                                  instance_software)
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_replies_to_post',
                                debug)
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, path):
                msg_str = json.dumps(replies_json, ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                protocol_str = \
                    get_json_content_from_accept(self.headers['Accept'])
                msglen = len(msg)
                set_headers(self, protocol_str, msglen, None,
                            calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time, fitness,
                                    '_GET', 'show_replies_to_post json',
                                    debug)
            else:
                http_404(self, 62)
        return True

    # replies exist. Itterate through the
    # text file containing message ids
    id_str = \
        local_actor_url(http_prefix, nickname, domain_full) + \
        '/statuses/' + status_number + '?page=true'

    part_of_str = \
        local_actor_url(http_prefix, nickname, domain_full) + \
        '/statuses/' + status_number

    replies_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': id_str,
        'orderedItems': [
        ],
        'partOf': part_of_str,
        'type': 'OrderedCollectionPage'
    }

    # if the original post is public then return the replies
    replies_are_public = \
        is_public_post_from_url(base_dir, nickname, domain,
                                orig_post_url)
    if replies_are_public:
        authorized = True

    # populate the items list with replies
    populate_replies_json(base_dir, nickname, domain,
                          post_replies_filename,
                          authorized, replies_json)

    # send the replies json
    if request_http(self.headers, debug):
        curr_session = \
            establish_session("show_replies_to_post2",
                              curr_session, proxy_type,
                              self.server)
        if not curr_session:
            http_404(self, 63)
            return True
        yt_domain = yt_replace_domain
        timezone = None
        if account_timezone.get(nickname):
            timezone = account_timezone.get(nickname)
        bold_reading = False
        if bold_reading_nicknames.get(nickname):
            bold_reading = True
        msg = \
            html_post_replies(recent_posts_cache,
                              max_recent_posts,
                              translate,
                              base_dir,
                              curr_session,
                              cached_webfingers,
                              person_cache,
                              nickname,
                              domain,
                              port,
                              replies_json,
                              http_prefix,
                              project_version,
                              yt_domain,
                              twitter_replacement_domain,
                              show_published_date_only,
                              peertube_instances,
                              allow_local_network_access,
                              theme_name,
                              system_language,
                              max_like_count,
                              signing_priv_key_pem,
                              cw_lists,
                              lists_enabled,
                              timezone, bold_reading,
                              dogwhistles,
                              min_images_for_accounts,
                              buy_sites,
                              auto_cw_cache,
                              mitm_servers,
                              instance_software)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', 'show_replies_to_post',
                            debug)
    else:
        if secure_mode(curr_session, proxy_type, False,
                       self.server, self.headers, path):
            msg_str = json.dumps(replies_json, ensure_ascii=False)
            msg_str = convert_domains(calling_domain,
                                      referer_domain,
                                      msg_str, http_prefix,
                                      domain,
                                      onion_domain,
                                      i2p_domain)
            msg = msg_str.encode('utf-8')
            protocol_str = \
                get_json_content_from_accept(self.headers['Accept'])
            msglen = len(msg)
            set_headers(self, protocol_str, msglen,
                        None, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', 'show_replies_to_post json',
                                debug)
        else:
            http_404(self, 64)
    return True


def show_notify_post(self, authorized: bool,
                     calling_domain: str, referer_domain: str,
                     path: str,
                     base_dir: str, http_prefix: str,
                     domain: str, port: int,
                     getreq_start_time,
                     proxy_type: str, cookie: str,
                     debug: str,
                     curr_session, translate: {},
                     account_timezone: {},
                     fitness: {},
                     recent_posts_cache: {},
                     max_recent_posts: int,
                     cached_webfingers: {},
                     person_cache: {},
                     project_version: str,
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     show_published_date_only: bool,
                     peertube_instances: [],
                     allow_local_network_access: bool,
                     theme_name: str,
                     system_language: str,
                     max_like_count: int,
                     signing_priv_key_pem: str,
                     cw_lists: {},
                     lists_enabled: {},
                     dogwhistles: {},
                     min_images_for_accounts: [],
                     buy_sites: [],
                     auto_cw_cache: {},
                     onion_domain: str,
                     i2p_domain: str,
                     bold_reading_nicknames: {},
                     mitm_servers: [],
                     instance_software: {}) -> bool:
    """Shows an individual post from an account which you are following
    and where you have the notify checkbox set on person options
    """
    liked_by = None
    react_by = None
    react_emoji = None
    post_id = path.split('?notifypost=')[1].strip()
    post_id = post_id.replace('-', '/')
    path = path.split('?notifypost=')[0]
    nickname = path.split('/users/')[1]
    if '/' in nickname:
        return False
    replies = False

    post_filename = locate_post(base_dir, nickname, domain,
                                post_id, replies)
    if not post_filename:
        return False

    include_create_wrapper = False
    if path.endswith('/activity'):
        include_create_wrapper = True

    result = _show_post_from_file(self, post_filename, liked_by,
                                  react_by, react_emoji,
                                  authorized, calling_domain,
                                  referer_domain,
                                  base_dir, http_prefix, nickname,
                                  domain, port,
                                  getreq_start_time,
                                  proxy_type, cookie, debug,
                                  include_create_wrapper,
                                  curr_session, translate,
                                  account_timezone,
                                  bold_reading_nicknames,
                                  recent_posts_cache,
                                  max_recent_posts,
                                  cached_webfingers,
                                  person_cache,
                                  project_version,
                                  yt_replace_domain,
                                  twitter_replacement_domain,
                                  show_published_date_only,
                                  peertube_instances,
                                  allow_local_network_access,
                                  theme_name,
                                  system_language,
                                  max_like_count,
                                  signing_priv_key_pem,
                                  cw_lists,
                                  lists_enabled,
                                  dogwhistles,
                                  min_images_for_accounts,
                                  buy_sites,
                                  auto_cw_cache,
                                  fitness, path,
                                  onion_domain,
                                  i2p_domain,
                                  mitm_servers,
                                  instance_software)
    fitness_performance(getreq_start_time, fitness,
                        '_GET', 'show_notify_post',
                        debug)
    return result


def show_conversation_thread(self, authorized: bool,
                             calling_domain: str, path: str,
                             base_dir: str, http_prefix: str,
                             domain: str, port: int,
                             debug: str, curr_session,
                             cookie: str, ua_str: str,
                             domain_full: str,
                             onion_domain: str,
                             i2p_domain: str,
                             account_timezone: {},
                             bold_reading_nicknames: {},
                             translate: {},
                             project_version: str,
                             recent_posts_cache: {},
                             max_recent_posts: int,
                             cached_webfingers: {},
                             person_cache: {},
                             yt_replace_domain: str,
                             twitter_replacement_domain: str,
                             show_published_date_only: bool,
                             peertube_instances: [],
                             allow_local_network_access: bool,
                             theme_name: str,
                             system_language: str,
                             max_like_count: int,
                             signing_priv_key_pem: str,
                             cw_lists: {},
                             lists_enabled: {},
                             dogwhistles: {},
                             access_keys: {},
                             min_images_for_accounts: [],
                             buy_sites: [],
                             blocked_cache: {},
                             block_federated: {},
                             auto_cw_cache: {},
                             default_timeline: str,
                             mitm_servers: [],
                             instance_software: {}) -> bool:
    """get conversation thread from the date link on a post
    """
    if not path.startswith('/users/'):
        return False
    conv_separator = '?convthread='
    # https://codeberg.org/fediverse/fep/src/branch/main/fep/76ea/fep-76ea.md
    if conv_separator not in path and \
       '/thread/' in path and \
       '?repeat' not in path and \
       '?un' not in path and \
       '?like' not in path and \
       '?delete' not in path and \
       '?postedit' not in path and \
       '?bookmark' not in path and \
       '?selreact' not in path and \
       '?mute' not in path and \
       '?reply' not in path:
        convthread_id = path.split('/thread/', 1)[1].strip()
        if convthread_id.isdigit():
            new_tag = convthread_id_to_conversation_tag(domain_full,
                                                        convthread_id)
            if new_tag:
                path = \
                    path.split(conv_separator)[0] + conv_separator + new_tag
    if conv_separator not in path:
        return False
    post_id = path.split(conv_separator)[1].strip()
    post_id = post_id.replace('--', '/')
    if post_id.startswith('/users/'):
        instance_url = get_instance_url(calling_domain,
                                        http_prefix,
                                        domain_full,
                                        onion_domain,
                                        i2p_domain)
        post_id = instance_url + post_id
    nickname = path.split('/users/')[1]
    if conv_separator in nickname:
        nickname = nickname.split(conv_separator)[0]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    timezone = None
    if account_timezone.get(nickname):
        timezone = account_timezone.get(nickname)
    bold_reading = False
    if bold_reading_nicknames.get(nickname):
        bold_reading = True
    conv_str = \
        html_conversation_view(authorized,
                               post_id, translate,
                               base_dir,
                               http_prefix,
                               nickname,
                               domain,
                               project_version,
                               recent_posts_cache,
                               max_recent_posts,
                               curr_session,
                               cached_webfingers,
                               person_cache,
                               port,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               allow_local_network_access,
                               theme_name,
                               system_language,
                               max_like_count,
                               signing_priv_key_pem,
                               cw_lists,
                               lists_enabled,
                               timezone, bold_reading,
                               dogwhistles,
                               access_keys,
                               min_images_for_accounts,
                               debug,
                               buy_sites,
                               blocked_cache,
                               block_federated,
                               auto_cw_cache,
                               ua_str,
                               default_timeline,
                               mitm_servers,
                               instance_software)
    if conv_str:
        msg = conv_str.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        self.server.getreq_busy = False
        return True
    # redirect to the original site if there are no results
    if '://' + domain_full + '/' in post_id:
        redirect_headers(self, post_id, cookie, calling_domain, 303)
    else:
        redirect_headers(self, post_id, None, calling_domain, 303)
    self.server.getreq_busy = False
    return True
