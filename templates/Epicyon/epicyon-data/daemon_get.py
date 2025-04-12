__filename__ = "daemon_get.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon"

import os
import time
import json
import urllib.parse
from siteactive import referer_is_active
from maps import map_format_from_tagmaps_path
from blog import html_edit_blog
from blog import html_blog_post
from blog import path_contains_blog_link
from blog import html_blog_view
from speaker import get_ssml_box
from follow import pending_followers_timeline_json
from blocking import broch_mode_is_active
from blocking import remove_global_block
from blocking import update_blocked_cache
from blocking import add_global_block
from blocking import blocked_timeline_json
from cache import get_person_from_cache
from webapp_moderation import html_account_info
from webapp_calendar import html_calendar_delete_confirm
from webapp_calendar import html_calendar
from webapp_hashtagswarm import html_search_hashtag_category
from webapp_minimalbutton import set_minimal
from webapp_minimalbutton import is_minimal
from webapp_search import html_search_emoji_text_entry
from webapp_search import html_search
from webapp_search import html_hashtag_search_remote
from webapp_column_left import html_links_mobile
from webapp_column_right import html_newswire_mobile
from webapp_theme_designer import html_theme_designer
from webapp_accesskeys import html_access_keys
from webapp_manual import html_manual
from webapp_specification import html_specification
from webapp_about import html_about
from webapp_tos import html_terms_of_service
from webapp_confirm import html_confirm_remove_shared_item
from webapp_welcome_profile import html_welcome_profile
from webapp_welcome_final import html_welcome_final
from webapp_welcome import html_welcome_screen
from webapp_welcome import is_welcome_screen_complete
from webapp_podcast import html_podcast_episode
from webapp_utils import html_known_epicyon_instances
from webapp_utils import get_default_path
from webapp_utils import csv_following_list
from webapp_utils import get_shares_collection
from webapp_utils import html_following_list
from webapp_utils import html_show_share
from webapp_login import html_login
from followerSync import update_followers_sync_cache
from securemode import secure_mode
from fitnessFunctions import sorted_watch_points
from fitnessFunctions import fitness_performance
from fitnessFunctions import html_watch_points_graph
from session import establish_session
from session import get_session_for_domains
from crawlers import blocked_user_agent
from daemon_utils import etag_exists
from daemon_utils import has_accept
from daemon_utils import show_person_options
from daemon_utils import is_authorized
from daemon_utils import get_user_agent
from daemon_utils import log_epicyon_instances
from httpheaders import update_headers_catalog
from httpheaders import set_headers_etag
from httpheaders import login_headers
from httpheaders import redirect_headers
from httprequests import request_icalendar
from httprequests import request_ssml
from httprequests import request_csv
from httprequests import request_http
from httpheaders import set_headers
from httpheaders import logout_headers
from httpheaders import logout_redirect
from httpheaders import contains_suspicious_headers
from httpcodes import http_200
from httpcodes import http_402
from httpcodes import http_403
from httpcodes import http_404
from httpcodes import http_304
from httpcodes import http_400
from httpcodes import http_503
from httpcodes import write2
from flags import is_image_file
from flags import is_artist
from flags import is_blog_post
from utils import date_utcnow
from utils import replace_strings
from utils import contains_invalid_chars
from utils import save_json
from utils import data_dir
from utils import user_agent_domain
from utils import local_network_host
from utils import permitted_dir
from utils import has_users_path
from utils import media_file_mime_type
from utils import replace_users_with_at
from utils import remove_id_ending
from utils import local_actor_url
from utils import load_json
from utils import acct_dir
from utils import get_instance_url
from utils import convert_domains
from utils import get_nickname_from_actor
from utils import get_json_content_from_accept
from utils import check_bad_path
from utils import corp_servers
from utils import decoded_host
from utils import detect_mitm
from person import get_person_notes_endpoint
from person import get_account_pub_key
from shares import actor_attached_shares
from shares import get_share_category
from shares import vf_proposal_from_id
from shares import authorize_shared_items
from shares import shares_catalog_endpoint
from shares import shares_catalog_account_endpoint
from shares import shares_catalog_csv_endpoint
from posts import is_moderator
from posts import get_pinned_post_as_json
from posts import outbox_message_create_wrap
from daemon_get_masto_api import masto_api
from daemon_get_favicon import show_cached_favicon
from daemon_get_favicon import get_favicon
from daemon_get_exports import get_exported_blocks
from daemon_get_exports import get_exported_theme
from daemon_get_pwa import progressive_web_app_manifest
from daemon_get_css import get_fonts
from daemon_get_css import get_style_sheet
from daemon_get_nodeinfo import get_nodeinfo
from daemon_get_hashtag import hashtag_search_rss2
from daemon_get_hashtag import hashtag_search_json2
from daemon_get_hashtag import hashtag_search2
from daemon_get_hashtag import get_hashtag_categories_feed2
from daemon_get_timeline import show_media_timeline
from daemon_get_timeline import show_blogs_timeline
from daemon_get_timeline import show_news_timeline
from daemon_get_timeline import show_features_timeline
from daemon_get_timeline import show_shares_timeline
from daemon_get_timeline import show_wanted_timeline
from daemon_get_timeline import show_bookmarks_timeline
from daemon_get_timeline import show_outbox_timeline
from daemon_get_timeline import show_mod_timeline
from daemon_get_timeline import show_dms
from daemon_get_timeline import show_replies
from daemon_get_timeline import show_inbox
from daemon_get_feeds import show_shares_feed
from daemon_get_feeds import show_following_feed
from daemon_get_feeds import show_moved_feed
from daemon_get_feeds import show_inactive_feed
from daemon_get_feeds import show_followers_feed
from daemon_get_buttons_announce import announce_button
from daemon_get_buttons_announce import announce_button_undo
from daemon_get_buttons import follow_approve_button
from daemon_get_buttons import follow_deny_button
from daemon_get_buttons_like import like_button
from daemon_get_buttons_like import like_button_undo
from daemon_get_buttons_reaction import reaction_button
from daemon_get_buttons_reaction import reaction_button_undo
from daemon_get_buttons_bookmark import bookmark_button
from daemon_get_buttons_bookmark import bookmark_button_undo
from daemon_get_buttons import delete_button
from daemon_get_buttons_mute import mute_button
from daemon_get_buttons_mute import mute_button_undo
from daemon_get_newswire import get_newswire_feed
from daemon_get_newswire import newswire_vote
from daemon_get_newswire import newswire_unvote
from daemon_get_newswire import edit_newswire2
from daemon_get_newswire import edit_news_post2
from daemon_get_rss import get_rss2feed
from daemon_get_rss import get_rss2site
from daemon_get_rss import get_rss3feed
from daemon_get_profile import show_person_profile
from daemon_get_profile import show_skills
from daemon_get_profile import show_roles
from daemon_get_profile import edit_profile2
from daemon_get_images import show_avatar_or_banner
from daemon_get_images import show_cached_avatar
from daemon_get_images import show_help_screen_image
from daemon_get_images import show_manual_image
from daemon_get_images import show_specification_image
from daemon_get_images import show_icon
from daemon_get_images import show_share_image
from daemon_get_images import show_media
from daemon_get_images import show_background_image
from daemon_get_images import show_default_profile_background
from daemon_get_images import column_image
from daemon_get_images import search_screen_banner
from daemon_get_images import show_qrcode
from daemon_get_images import show_emoji
from daemon_get_post import show_individual_post
from daemon_get_post import show_notify_post
from daemon_get_post import show_replies_to_post
from daemon_get_post import show_announcers_of_post
from daemon_get_post import show_likers_of_post
from daemon_get_post import show_individual_at_post
from daemon_get_post import show_new_post
from daemon_get_post import show_conversation_thread
from daemon_get_collections import get_featured_collection
from daemon_get_collections import get_featured_tags_collection
from daemon_get_collections import get_following_json
from daemon_get_webfinger import get_webfinger
from daemon_get_reactions import reaction_picker2
from daemon_get_instance_actor import show_instance_actor
from daemon_get_vcard import show_vcard
from daemon_get_blog import show_blog_page
from daemon_get_links import edit_links2
from daemon_get_login import redirect_to_login_screen
from daemon_get_login import show_login_screen
from poison import html_poisoned

# Blogs can be longer, so don't show many per page
MAX_POSTS_IN_BLOGS_FEED = 4

# maximum number of posts to list in outbox feed
MAX_POSTS_IN_FEED = 12

# Maximum number of entries in returned rss.xml
MAX_POSTS_IN_RSS_FEED = 10

# reduced posts for media feed because it can take a while
MAX_POSTS_IN_MEDIA_FEED = 6

MAX_POSTS_IN_NEWS_FEED = 10

# number of item shares per page
SHARES_PER_PAGE = 12

# number of follows/followers per page
FOLLOWS_PER_PAGE = 6

# maximum number of posts in a hashtag feed
MAX_POSTS_IN_HASHTAG_FEED = 6


def daemon_http_get(self) -> None:
    """daemon handler for http GET
    """
    if self.server.starting_daemon:
        return
    if check_bad_path(self.path):
        http_400(self)
        return

    calling_domain = self.server.domain_full

    # record header fields encountered
    update_headers_catalog(self.server.base_dir,
                           self.server.headers_catalog,
                           self.headers)

    if self.headers.get('Server'):
        if self.headers['Server'] in corp_servers():
            print('GET HTTP Corporate leech bounced: ' +
                  self.headers['Server'])
            http_402(self)
            return

    # handle robots.txt
    if self.path == '/robots.txt':
        if self.server.robots_txt:
            msg = self.server.robots_txt
        else:
            msg = "User-agent: *\nAllow: /"
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/plain', msglen,
                    '', calling_domain, False)
        write2(self, msg)
        return

    # headers used by LLM scrapers
    # oai-host-hash requests come from Microsoft Corporation,
    # which has a long term partnership with OpenAI
    if 'oai-host-hash' in self.headers:
        if is_image_file(self.path):
            http_404(self, 720)
            return
        print('GET HTTP LLM scraper poisoned: ' + str(self.headers))
        msg = html_poisoned(self.server.dictionary,
                            self.server.twograms)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    '', calling_domain, False)
        write2(self, msg)
        return

    # replace invalid .well-known path, prior to checking for suspicious paths
    if self.path.startswith('/users/.well-known/'):
        self.path = self.path.replace('/users/.well-known/', '/.well-known/')

    # suspicious headers
    if contains_suspicious_headers(self.headers):
        print('GET HTTP suspicious headers 1 ' + str(self.headers))
        http_403(self)
        return

    # php
    if 'index.php' in self.path:
        print('GET HTTP Attempt to access PHP file ' + self.path)
        http_404(self, 145)
        return

    if contains_invalid_chars(str(self.headers)):
        print('GET HTTP headers contain invalid characters ' +
              str(self.headers))
        http_403(self)
        return

    if self.headers.get('Host'):
        calling_domain = decoded_host(self.headers['Host'])
        if self.server.onion_domain:
            if calling_domain not in (self.server.domain,
                                      self.server.domain_full,
                                      self.server.onion_domain):
                print('GET domain blocked: ' + calling_domain)
                http_400(self)
                return
        elif self.server.i2p_domain:
            if calling_domain not in (self.server.domain,
                                      self.server.domain_full,
                                      self.server.i2p_domain):
                print('GET domain blocked: ' + calling_domain)
                http_400(self)
                return
        else:
            if calling_domain not in (self.server.domain,
                                      self.server.domain_full):
                print('GET domain blocked: ' + calling_domain)
                http_400(self)
                return

    ua_str = get_user_agent(self)

    if ua_str:
        if 'Epicyon/' in ua_str:
            log_epicyon_instances(self.server.base_dir, ua_str,
                                  self.server.known_epicyon_instances)

    if not _permitted_crawler_path(self.path):
        block, self.server.blocked_cache_last_updated, llm = \
            blocked_user_agent(calling_domain, ua_str,
                               self.server.news_instance,
                               self.server.debug,
                               self.server.user_agents_blocked,
                               self.server.blocked_cache_last_updated,
                               self.server.base_dir,
                               self.server.blocked_cache,
                               self.server.block_federated,
                               self.server.blocked_cache_update_secs,
                               self.server.crawlers_allowed,
                               self.server.known_bots,
                               self.path, self.server.block_military,
                               self.server.block_government,
                               self.server.block_bluesky,
                               self.server.block_nostr)
        if block:
            if llm:
                # check if LLM is too frequent
                if self.server.last_llm_time:
                    curr_date = date_utcnow()
                    time_diff = curr_date - self.server.last_llm_time
                    diff_secs = time_diff.total_seconds()
                    if diff_secs < 60:
                        http_402(self)
                        return
                if is_image_file(self.path):
                    http_402(self)
                    return
                # if this is an LLM crawler then feed it some trash
                print('GET HTTP LLM scraper poisoned: ' + str(self.headers))
                msg = html_poisoned(self.server.dictionary,
                                    self.server.twograms)
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            '', calling_domain, False)
                write2(self, msg)
                self.server.last_llm_time = date_utcnow()
                return
            http_400(self)
            return

    referer_domain = _get_referer_domain(self, ua_str)

    mitm = detect_mitm(self)
    if mitm and referer_domain:
        print('DEBUG: MITM on HTTP GET, ' + str(referer_domain))

    curr_session, proxy_type = \
        get_session_for_domains(self.server,
                                calling_domain, referer_domain)

    getreq_start_time = time.time()

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'start', self.server.debug)

    if show_vcard(self, self.server.base_dir,
                  self.path, calling_domain, referer_domain,
                  self.server.domain, self.server.translate):
        return

    # getting the public key for an account
    acct_pub_key_json = \
        get_account_pub_key(self.path, self.server.person_cache,
                            self.server.base_dir,
                            self.server.domain, calling_domain,
                            self.server.http_prefix,
                            self.server.domain_full,
                            self.server.onion_domain,
                            self.server.i2p_domain)
    if acct_pub_key_json:
        msg_str = json.dumps(acct_pub_key_json, ensure_ascii=False)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        return

    # Since fediverse crawlers are quite active,
    # make returning info to them high priority
    # get nodeinfo endpoint
    if get_nodeinfo(self, ua_str, calling_domain, referer_domain,
                    self.server.http_prefix, 5, self.server.debug,
                    self.server.base_dir,
                    self.server.unit_test,
                    self.server.domain_full,
                    self.path,
                    self.server.allow_local_network_access,
                    self.server.sites_unavailable,
                    self.server.known_crawlers,
                    self.server.onion_domain,
                    self.server.i2p_domain,
                    self.server.project_version,
                    self.server.show_node_info_version,
                    self.server.show_node_info_accounts,
                    self.server.registration,
                    self.server.domain,
                    self.server.instance_description_short,
                    self.server.instance_description):
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', '_nodeinfo[calling_domain]',
                        self.server.debug)

    if _security_txt(self, ua_str, calling_domain, referer_domain,
                     self.server.http_prefix, 5, self.server.debug):
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', '_security_txt[calling_domain]',
                        self.server.debug)

    # followers synchronization request
    # See
    # https://codeberg.org/fediverse/fep/src/branch/main/feps/fep-8fcf.md
    if self.path.startswith('/users/') and \
       self.path.endswith('/followers_synchronization'):
        if self.server.followers_synchronization:
            # only do one request at a time
            http_503(self)
            return
        self.server.followers_synchronization = True
        if self.server.debug:
            print('DEBUG: followers synchronization request ' +
                  self.path + ' ' + calling_domain)
        # check authorized fetch
        if secure_mode(curr_session, proxy_type, False,
                       self.server, self.headers, self.path):
            nickname = get_nickname_from_actor(self.path)
            sync_cache = self.server.followers_sync_cache
            sync_json, _ = \
                update_followers_sync_cache(self.server.base_dir,
                                            nickname,
                                            self.server.domain,
                                            self.server.http_prefix,
                                            self.server.domain_full,
                                            calling_domain,
                                            sync_cache)
            msg_str = json.dumps(sync_json, ensure_ascii=False)
            msg_str = convert_domains(calling_domain, referer_domain,
                                      msg_str,
                                      self.server.http_prefix,
                                      self.server.domain,
                                      self.server.onion_domain,
                                      self.server.i2p_domain)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'application/json', msglen,
                        None, calling_domain, False)
            write2(self, msg)
            self.server.followers_synchronization = False
            return
        else:
            # request was not signed
            result_json = {
                "error": "Request not signed"
            }
            msg_str = json.dumps(result_json, ensure_ascii=False)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            accept_str = self.headers['Accept']
            if 'json' in accept_str:
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                self.server.followers_synchronization = False
                return
        http_404(self, 110)
        self.server.followers_synchronization = False
        return

    if self.path == '/logout':
        if not self.server.news_instance:
            msg = \
                html_login(self.server.translate,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain_full,
                           self.server.system_language,
                           False, ua_str,
                           self.server.theme_name).encode('utf-8')
            msglen = len(msg)
            logout_headers(self, 'text/html', msglen, calling_domain)
            write2(self, msg)
        else:
            news_url = \
                get_instance_url(calling_domain,
                                 self.server.http_prefix,
                                 self.server.domain_full,
                                 self.server.onion_domain,
                                 self.server.i2p_domain) + \
                '/users/news'
            logout_redirect(self, news_url, calling_domain)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'logout',
                            self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show logout',
                        self.server.debug)

    # replace https://domain/@nick with https://domain/users/nick
    if self.path.startswith('/@'):
        self.path = self.path.replace('/@', '/users/')
        # replace https://domain/@nick/statusnumber
        # with https://domain/users/nick/statuses/statusnumber
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            status_number_str = nickname.split('/')[1]
            if status_number_str.isdigit():
                nickname = nickname.split('/')[0]
                self.path = \
                    self.path.replace('/users/' + nickname + '/',
                                      '/users/' + nickname + '/statuses/')

    # instance actor
    if self.path in ('/actor', '/users/instance.actor', '/users/actor',
                     '/Actor', '/users/Actor'):
        self.path = '/users/inbox'
        if show_instance_actor(self, calling_domain, referer_domain,
                               self.path,
                               self.server.base_dir,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.domain_full,
                               self.server.onion_domain,
                               self.server.i2p_domain,
                               getreq_start_time,
                               None, self.server.debug,
                               self.server.enable_shared_inbox,
                               self.server.fitness):
            return
        http_404(self, 111)
        return

    # turn off dropdowns on new post screen
    no_drop_down = False
    if self.path.endswith('?nodropdown'):
        no_drop_down = True
        self.path = self.path.replace('?nodropdown', '')

    # redirect music to #nowplaying list
    if self.path == '/music' or self.path == '/NowPlaying':
        self.path = '/tags/NowPlaying'

    if self.server.debug:
        print('DEBUG: GET from ' + self.server.base_dir +
              ' path: ' + self.path + ' busy: ' +
              str(self.server.getreq_busy))

    if self.server.debug:
        print(str(self.headers))

    cookie = None
    if self.headers.get('Cookie'):
        cookie = self.headers['Cookie']

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'get cookie',
                        self.server.debug)

    if '/manifest.json' in self.path:
        if has_accept(self, calling_domain):
            if not request_http(self.headers, self.server.debug):
                progressive_web_app_manifest(self, self.server.base_dir,
                                             calling_domain,
                                             referer_domain,
                                             getreq_start_time,
                                             self.server.http_prefix,
                                             self.server.domain,
                                             self.server.onion_domain,
                                             self.server.i2p_domain,
                                             self.server.fitness,
                                             self.server.debug)
                return
            else:
                self.path = '/'

    if '/browserconfig.xml' in self.path:
        if has_accept(self, calling_domain):
            _browser_config(self, calling_domain, referer_domain,
                            getreq_start_time)
            return

    # default newswire favicon, for links to sites which
    # have no favicon
    if not self.path.startswith('/favicons/'):
        if 'newswire_favicon.ico' in self.path:
            get_favicon(self, calling_domain, self.server.base_dir,
                        self.server.debug,
                        'newswire_favicon.ico',
                        self.server.iconsCache,
                        self.server.domain_full)
            return

        # favicon image
        if 'favicon.ico' in self.path:
            get_favicon(self, calling_domain, self.server.base_dir,
                        self.server.debug, 'favicon.ico',
                        self.server.iconsCache,
                        self.server.domain_full)
            return

    # check authorization
    authorized = is_authorized(self)
    if self.server.debug:
        if authorized:
            print('GET Authorization granted ' + self.path)
        else:
            print('GET Not authorized ' + self.path + ' ' +
                  str(self.headers))

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'isAuthorized',
                        self.server.debug)

    # json endpoint for person options notes
    if (authorized and
        ('/private_account_notes/' in self.path or
         self.path.endswith('/private_account_notes'))):
        nickname = get_nickname_from_actor(self.path)
        handle = ''
        if '/private_account_notes/' in self.path:
            handle = self.path.split('/private_account_notes/', 1)[1]
        if nickname:
            notes_json = \
                get_person_notes_endpoint(self.server.base_dir,
                                          nickname,
                                          self.server.domain,
                                          handle,
                                          self.server.http_prefix,
                                          self.server.domain_full)
            msg_str = json.dumps(notes_json)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'application/json', msglen,
                        None, calling_domain, True)
            write2(self, msg)
            return
        http_404(self, 212)
        return

    if authorized and self.path.endswith('/bots.txt'):
        known_bots_str = ''
        for bot_name in self.server.known_bots:
            known_bots_str += bot_name + '\n'
        msg = known_bots_str.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/plain; charset=utf-8',
                    msglen, None, calling_domain, True)
        write2(self, msg)
        if self.server.debug:
            print('Sent known bots: ' +
                  self.server.path + ' ' + calling_domain)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'get_known_bots',
                            self.server.debug)
        return

    if show_conversation_thread(self, authorized,
                                calling_domain, self.path,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.port,
                                self.server.debug,
                                self.server.session,
                                cookie, ua_str,
                                self.server.domain_full,
                                self.server.onion_domain,
                                self.server.i2p_domain,
                                self.server.account_timezone,
                                self.server.bold_reading,
                                self.server.translate,
                                self.server.project_version,
                                self.server.recent_posts_cache,
                                self.server.max_recent_posts,
                                self.server.cached_webfingers,
                                self.server.person_cache,
                                self.server.yt_replace_domain,
                                self.server.twitter_replacement_domain,
                                self.server.show_published_date_only,
                                self.server.peertube_instances,
                                self.server.allow_local_network_access,
                                self.server.theme_name,
                                self.server.system_language,
                                self.server.max_like_count,
                                self.server.signing_priv_key_pem,
                                self.server.cw_lists,
                                self.server.lists_enabled,
                                self.server.dogwhistles,
                                self.server.access_keys,
                                self.server.min_images_for_accounts,
                                self.server.buy_sites,
                                self.server.blocked_cache,
                                self.server.block_federated,
                                self.server.auto_cw_cache,
                                self.server.default_timeline,
                                self.server.mitm_servers,
                                self.server.instance_software):
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', '_show_conversation_thread',
                            self.server.debug)
        return

    # show a shared item if it is listed within actor attachment
    if self.path.startswith('/users/') and '/shareditems/' in self.path:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        shared_item_display_name = self.path.split('/shareditems/')[1]
        if not nickname or not shared_item_display_name:
            http_404(self, 112)
            return
        if not has_accept(self, calling_domain):
            print('DEBUG: shareditems 1')
            http_404(self, 113)
            return
        # get the actor from the cache
        actor = \
            get_instance_url(calling_domain,
                             self.server.http_prefix,
                             self.server.domain_full,
                             self.server.onion_domain,
                             self.server.i2p_domain) + \
            '/users/' + nickname
        actor_json = get_person_from_cache(self.server.base_dir, actor,
                                           self.server.person_cache)
        if not actor_json:
            actor_filename = acct_dir(self.server.base_dir, nickname,
                                      self.server.domain) + '.json'
            if os.path.isfile(actor_filename):
                actor_json = load_json(actor_filename)
        if not actor_json:
            print('DEBUG: shareditems 2 ' + actor)
            http_404(self, 114)
            return
        attached_shares = actor_attached_shares(actor_json)
        if not attached_shares:
            print('DEBUG: shareditems 3 ' + str(actor_json['attachment']))
            http_404(self, 115)
            return
        # is the given shared item in the list?
        share_id = None
        for share_href in attached_shares:
            if not isinstance(share_href, str):
                continue
            if share_href.endswith(self.path):
                share_id = share_href.replace('://', '___')
                share_id = share_id.replace('/', '--')
                break
        if not share_id:
            print('DEBUG: shareditems 4')
            http_404(self, 116)
            return
        # show the shared item
        print('DEBUG: shareditems 5 ' + share_id)
        shares_file_type = 'shares'
        if request_http(self.headers, self.server.debug):
            # get the category for share_id
            share_category = \
                get_share_category(self.server.base_dir,
                                   nickname, self.server.domain,
                                   shares_file_type, share_id)
            msg = \
                html_show_share(self.server.base_dir,
                                self.server.domain, nickname,
                                self.server.http_prefix,
                                self.server.domain_full,
                                share_id, self.server.translate,
                                self.server.shared_items_federated_domains,
                                self.server.default_timeline,
                                self.server.theme_name, shares_file_type,
                                share_category, not authorized)
            if msg:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            None, calling_domain, True)
                write2(self, msg)
                return
            print('DEBUG: shareditems 6 ' + share_id)
        else:
            # get json for the shared item in ValueFlows format
            share_json = \
                vf_proposal_from_id(self.server.base_dir,
                                    nickname, self.server.domain,
                                    shares_file_type, share_id,
                                    actor)
            if share_json:
                msg_str = json.dumps(share_json)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str,
                                          self.server.http_prefix,
                                          self.server.domain,
                                          self.server.onion_domain,
                                          self.server.i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'application/json', msglen,
                            None, calling_domain, True)
                write2(self, msg)
                return
            print('DEBUG: shareditems 7 ' + share_id)
        http_404(self, 117)
        return

    # shared items offers collection for this instance
    # this is only accessible to instance members or to
    # other instances which present an authorization token
    if self.path.startswith('/users/') and '/offers' in self.path:
        offers_collection_authorized = authorized
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        page_number = 1
        if '?page=' in self.path:
            page_number_str = self.path.split('?page=')[1]
            if ';' in page_number_str:
                page_number_str = page_number_str.split(';')[0]
            if page_number_str.isdigit():
                page_number = int(page_number_str)
        if not offers_collection_authorized:
            if self.server.debug:
                print('Offers collection access is not authorized. ' +
                      'Checking Authorization header')
            # Check the authorization token
            if self.headers.get('Origin') and \
               self.headers.get('Authorization'):
                permitted_domains = \
                    self.server.shared_items_federated_domains
                shared_item_tokens = \
                    self.server.shared_item_federation_tokens
                if authorize_shared_items(permitted_domains,
                                          self.server.base_dir,
                                          self.headers['Origin'],
                                          calling_domain,
                                          self.headers['Authorization'],
                                          self.server.debug,
                                          shared_item_tokens):
                    offers_collection_authorized = True
                elif self.server.debug:
                    print('Authorization token refused for ' +
                          'offers collection federation')
        # show offers collection for federation
        offers_json: list[dict] = []
        if has_accept(self, calling_domain) and \
           offers_collection_authorized:
            if self.server.debug:
                print('Preparing offers collection')

            domain_full = self.server.domain_full
            http_prefix = self.server.http_prefix
            if self.server.debug:
                print('Offers collection for account: ' + nickname)
            base_dir = self.server.base_dir
            offers_items_per_page = 12
            max_shares_per_account = offers_items_per_page
            shared_items_federated_domains = \
                self.server.shared_items_federated_domains
            actor = \
                local_actor_url(http_prefix, nickname, domain_full) + \
                '/offers'
            offers_json = \
                get_shares_collection(actor, page_number,
                                      offers_items_per_page, base_dir,
                                      self.server.domain, nickname,
                                      max_shares_per_account,
                                      shared_items_federated_domains,
                                      'shares')
        msg_str = json.dumps(offers_json,
                             ensure_ascii=False)
        msg_str = convert_domains(calling_domain,
                                  referer_domain,
                                  msg_str,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.onion_domain,
                                  self.server.i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        return

    if self.path.startswith('/users/') and '/blocked' in self.path:
        blocked_collection_authorized = authorized
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        page_number = 1
        if '?page=' in self.path:
            page_number_str = self.path.split('?page=')[1]
            if ';' in page_number_str:
                page_number_str = page_number_str.split(';')[0]
            if page_number_str.isdigit():
                page_number = int(page_number_str)
        # show blocked collection for the nickname
        actor = \
            local_actor_url(self.server.http_prefix,
                            nickname, self.server.domain_full)
        actor += '/blocked'
        blocked_json = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
                "https://purl.archive.org/socialweb/blocked"
            ],
            "id": actor,
            "type": "OrderedCollection",
            "name": nickname + "'s Blocked Collection",
            "orderedItems": []
        }
        if has_accept(self, calling_domain) and \
           blocked_collection_authorized:
            if self.server.debug:
                print('Preparing blocked collection')

            if self.server.debug:
                print('Blocked collection for account: ' + nickname)
            base_dir = self.server.base_dir
            blocked_items_per_page = 12
            blocked_json = \
                blocked_timeline_json(actor, page_number,
                                      blocked_items_per_page, base_dir,
                                      nickname, self.server.domain)
        msg_str = json.dumps(blocked_json,
                             ensure_ascii=False)
        msg_str = convert_domains(calling_domain,
                                  referer_domain,
                                  msg_str,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.onion_domain,
                                  self.server.i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        return

    if self.path.startswith('/users/') and \
       '/pendingFollowers' in self.path:
        pending_collection_authorized = authorized
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        page_number = 1
        if '?page=' in self.path:
            page_number_str = self.path.split('?page=')[1]
            if ';' in page_number_str:
                page_number_str = page_number_str.split(';')[0]
            if page_number_str.isdigit():
                page_number = int(page_number_str)
        # show pending followers collection for the nickname
        actor = \
            local_actor_url(self.server.http_prefix,
                            nickname, self.server.domain_full)
        actor += '/pendingFollowers'
        pending_json = {
            "@context": [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            "id": actor,
            "type": "OrderedCollection",
            "name": nickname + "'s Pending Followers",
            "orderedItems": []
        }
        if has_accept(self, calling_domain) and \
           pending_collection_authorized:
            if self.server.debug:
                print('Preparing pending followers collection')

            if self.server.debug:
                print('Pending followers collection for account: ' +
                      nickname)
            base_dir = self.server.base_dir
            pending_json = \
                pending_followers_timeline_json(actor, base_dir, nickname,
                                                self.server.domain)
        msg_str = json.dumps(pending_json,
                             ensure_ascii=False)
        msg_str = convert_domains(calling_domain,
                                  referer_domain,
                                  msg_str,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.onion_domain,
                                  self.server.i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        return

    # wanted items collection for this instance
    # this is only accessible to instance members or to
    # other instances which present an authorization token
    if self.path.startswith('/users/') and '/wanted' in self.path:
        wanted_collection_authorized = authorized
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        page_number = 1
        if '?page=' in self.path:
            page_number_str = self.path.split('?page=')[1]
            if ';' in page_number_str:
                page_number_str = page_number_str.split(';')[0]
            if page_number_str.isdigit():
                page_number = int(page_number_str)
        if not wanted_collection_authorized:
            if self.server.debug:
                print('Wanted collection access is not authorized. ' +
                      'Checking Authorization header')
            # Check the authorization token
            if self.headers.get('Origin') and \
               self.headers.get('Authorization'):
                permitted_domains = \
                    self.server.shared_items_federated_domains
                shared_item_tokens = \
                    self.server.shared_item_federation_tokens
                if authorize_shared_items(permitted_domains,
                                          self.server.base_dir,
                                          self.headers['Origin'],
                                          calling_domain,
                                          self.headers['Authorization'],
                                          self.server.debug,
                                          shared_item_tokens):
                    wanted_collection_authorized = True
                elif self.server.debug:
                    print('Authorization token refused for ' +
                          'wanted collection federation')
        # show wanted collection for federation
        wanted_json: list[dict] = []
        if has_accept(self, calling_domain) and \
           wanted_collection_authorized:
            if self.server.debug:
                print('Preparing wanted collection')

            domain_full = self.server.domain_full
            http_prefix = self.server.http_prefix
            nickname = self.path.split('/users/')[1]
            if '/' in nickname:
                nickname = nickname.split('/')[0]
            if self.server.debug:
                print('Wanted collection for account: ' + nickname)
            base_dir = self.server.base_dir
            wanted_items_per_page = 12
            max_shares_per_account = wanted_items_per_page
            shared_items_federated_domains = \
                self.server.shared_items_federated_domains
            actor = \
                local_actor_url(http_prefix, nickname, domain_full) + \
                '/wanted'
            wanted_json = \
                get_shares_collection(actor, page_number,
                                      wanted_items_per_page, base_dir,
                                      self.server.domain, nickname,
                                      max_shares_per_account,
                                      shared_items_federated_domains,
                                      'wanted')
        msg_str = json.dumps(wanted_json,
                             ensure_ascii=False)
        msg_str = convert_domains(calling_domain,
                                  referer_domain,
                                  msg_str,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.onion_domain,
                                  self.server.i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        return

    # shared items catalog for this instance
    # this is only accessible to instance members or to
    # other instances which present an authorization token
    if self.path.startswith('/catalog') or \
       (self.path.startswith('/users/') and '/catalog' in self.path):
        catalog_authorized = authorized
        if not catalog_authorized:
            if self.server.debug:
                print('Catalog access is not authorized. ' +
                      'Checking Authorization header')
            # Check the authorization token
            if self.headers.get('Origin') and \
               self.headers.get('Authorization'):
                permitted_domains = \
                    self.server.shared_items_federated_domains
                shared_item_tokens = \
                    self.server.shared_item_federation_tokens
                if authorize_shared_items(permitted_domains,
                                          self.server.base_dir,
                                          self.headers['Origin'],
                                          calling_domain,
                                          self.headers['Authorization'],
                                          self.server.debug,
                                          shared_item_tokens):
                    catalog_authorized = True
                elif self.server.debug:
                    print('Authorization token refused for ' +
                          'shared items federation')
            elif self.server.debug:
                print('No Authorization header is available for ' +
                      'shared items federation')
        # show shared items catalog for federation
        if has_accept(self, calling_domain) and catalog_authorized:
            catalog_type = 'json'
            headers = self.headers
            debug = self.server.debug
            if self.path.endswith('.csv') or request_csv(headers):
                catalog_type = 'csv'
            elif (self.path.endswith('.json') or
                  not request_http(headers, debug)):
                catalog_type = 'json'
            if self.server.debug:
                print('Preparing DFC catalog in format ' + catalog_type)

            if catalog_type == 'json':
                # catalog as a json
                if not self.path.startswith('/users/'):
                    if self.server.debug:
                        print('Catalog for the instance')
                    catalog_json = \
                        shares_catalog_endpoint(self.server.base_dir,
                                                self.server.http_prefix,
                                                self.server.domain_full,
                                                self.path, 'shares')
                else:
                    domain_full = self.server.domain_full
                    http_prefix = self.server.http_prefix
                    nickname = self.path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if self.server.debug:
                        print('Catalog for account: ' + nickname)
                    base_dir = self.server.base_dir
                    catalog_json = \
                        shares_catalog_account_endpoint(base_dir,
                                                        http_prefix,
                                                        nickname,
                                                        self.server.domain,
                                                        domain_full,
                                                        self.path,
                                                        self.server.debug,
                                                        'shares')
                msg_str = json.dumps(catalog_json,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str,
                                          self.server.http_prefix,
                                          self.server.domain,
                                          self.server.onion_domain,
                                          self.server.i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                return
            if catalog_type == 'csv':
                # catalog as a CSV file for import into a spreadsheet
                msg = \
                    shares_catalog_csv_endpoint(self.server.base_dir,
                                                self.server.http_prefix,
                                                self.server.domain_full,
                                                self.path,
                                                'shares').encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/csv',
                            msglen, None, calling_domain, False)
                write2(self, msg)
                return
            http_404(self, 118)
            return
        http_400(self)
        return

    # wanted items catalog for this instance
    # this is only accessible to instance members or to
    # other instances which present an authorization token
    if self.path.startswith('/wantedItems') or \
       (self.path.startswith('/users/') and '/wantedItems' in self.path):
        catalog_authorized = authorized
        if not catalog_authorized:
            if self.server.debug:
                print('Wanted catalog access is not authorized. ' +
                      'Checking Authorization header')
            # Check the authorization token
            if self.headers.get('Origin') and \
               self.headers.get('Authorization'):
                permitted_domains = \
                    self.server.shared_items_federated_domains
                shared_item_tokens = \
                    self.server.shared_item_federation_tokens
                if authorize_shared_items(permitted_domains,
                                          self.server.base_dir,
                                          self.headers['Origin'],
                                          calling_domain,
                                          self.headers['Authorization'],
                                          self.server.debug,
                                          shared_item_tokens):
                    catalog_authorized = True
                elif self.server.debug:
                    print('Authorization token refused for ' +
                          'wanted items federation')
            elif self.server.debug:
                print('No Authorization header is available for ' +
                      'wanted items federation')
        # show wanted items catalog for federation
        if has_accept(self, calling_domain) and catalog_authorized:
            catalog_type = 'json'
            headers = self.headers
            debug = self.server.debug
            if self.path.endswith('.csv') or request_csv(headers):
                catalog_type = 'csv'
            elif (self.path.endswith('.json') or
                  not request_http(headers, debug)):
                catalog_type = 'json'
            if self.server.debug:
                print('Preparing DFC wanted catalog in format ' +
                      catalog_type)

            if catalog_type == 'json':
                # catalog as a json
                if not self.path.startswith('/users/'):
                    if self.server.debug:
                        print('Wanted catalog for the instance')
                    catalog_json = \
                        shares_catalog_endpoint(self.server.base_dir,
                                                self.server.http_prefix,
                                                self.server.domain_full,
                                                self.path, 'wanted')
                else:
                    domain_full = self.server.domain_full
                    http_prefix = self.server.http_prefix
                    nickname = self.path.split('/users/')[1]
                    if '/' in nickname:
                        nickname = nickname.split('/')[0]
                    if self.server.debug:
                        print('Wanted catalog for account: ' + nickname)
                    base_dir = self.server.base_dir
                    catalog_json = \
                        shares_catalog_account_endpoint(base_dir,
                                                        http_prefix,
                                                        nickname,
                                                        self.server.domain,
                                                        domain_full,
                                                        self.path,
                                                        self.server.debug,
                                                        'wanted')
                msg_str = json.dumps(catalog_json,
                                     ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str,
                                          self.server.http_prefix,
                                          self.server.domain,
                                          self.server.onion_domain,
                                          self.server.i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                accept_str = self.headers['Accept']
                protocol_str = \
                    get_json_content_from_accept(accept_str)
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                return
            if catalog_type == 'csv':
                # catalog as a CSV file for import into a spreadsheet
                msg = \
                    shares_catalog_csv_endpoint(self.server.base_dir,
                                                self.server.http_prefix,
                                                self.server.domain_full,
                                                self.path,
                                                'wanted').encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/csv',
                            msglen, None, calling_domain, False)
                write2(self, msg)
                return
            http_404(self, 119)
            return
        http_400(self)
        return

    # minimal mastodon api
    if masto_api(self, self.path, calling_domain, ua_str,
                 authorized,
                 self.server.http_prefix,
                 self.server.base_dir,
                 self.authorized_nickname,
                 self.server.domain,
                 self.server.domain_full,
                 self.server.onion_domain,
                 self.server.i2p_domain,
                 self.server.translate,
                 self.server.registration,
                 self.server.system_language,
                 self.server.project_version,
                 self.server.custom_emoji,
                 self.server.show_node_info_accounts,
                 referer_domain,
                 self.server.debug,
                 self.server.known_crawlers,
                 self.server.sites_unavailable,
                 self.server.unit_test,
                 self.server.allow_local_network_access):
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', '_masto_api[calling_domain]',
                        self.server.debug)

    curr_session = \
        establish_session("GET", curr_session,
                          proxy_type, self.server)
    if not curr_session:
        http_404(self, 120)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'session fail',
                            self.server.debug)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'create session',
                        self.server.debug)

    # is this a html/ssml/icalendar request?
    html_getreq = False
    csv_getreq = False
    ssml_getreq = False
    icalendar_getreq = False
    if has_accept(self, calling_domain):
        if request_http(self.headers, self.server.debug):
            html_getreq = True
        elif request_csv(self.headers):
            csv_getreq = True
        elif request_ssml(self.headers):
            ssml_getreq = True
        elif request_icalendar(self.headers):
            icalendar_getreq = True
    else:
        if self.headers.get('Connection'):
            # https://developer.mozilla.org/en-US/
            # docs/Web/HTTP/Protocol_upgrade_mechanism
            if self.headers.get('Upgrade'):
                print('HTTP Connection request: ' +
                      self.headers['Upgrade'])
            else:
                print('HTTP Connection request: ' +
                      self.headers['Connection'])
            http_200(self)
        else:
            print('WARN: No Accept header ' + str(self.headers))
            http_400(self)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'hasAccept',
                        self.server.debug)

    # cached favicon images
    # Note that this comes before the busy flag to avoid conflicts
    if self.path.startswith('/favicons/'):
        if self.server.domain_full in self.path:
            # favicon for this instance
            get_favicon(self, calling_domain, self.server.base_dir,
                        self.server.debug, 'favicon.ico',
                        self.server.iconsCache,
                        self.server.domain_full)
            return
        show_cached_favicon(self, referer_domain, self.path,
                            self.server.base_dir,
                            getreq_start_time,
                            self.server.favicons_cache,
                            self.server.fitness,
                            self.server.debug)
        return

    # get css
    # Note that this comes before the busy flag to avoid conflicts
    if self.path.endswith('.css'):
        if get_style_sheet(self, self.server.base_dir,
                           calling_domain, self.path,
                           getreq_start_time,
                           self.server.debug,
                           self.server.css_cache,
                           self.server.fitness):
            return

    if authorized and '/exports/' in self.path:
        if 'blocks.csv' in self.path:
            get_exported_blocks(self, self.path,
                                self.server.base_dir,
                                self.server.domain,
                                calling_domain)
        else:
            get_exported_theme(self, self.path,
                               self.server.base_dir,
                               self.server.domain_full)
        return

    # get fonts
    if '/fonts/' in self.path:
        get_fonts(self, calling_domain, self.path,
                  self.server.base_dir, self.server.debug,
                  getreq_start_time, self.server.fitness,
                  self.server.fontsCache,
                  self.server.domain_full)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'fonts',
                        self.server.debug)

    if self.path in ('/sharedInbox', '/users/inbox', '/actor/inbox',
                     '/users/' + self.server.domain):
        # if shared inbox is not enabled
        if not self.server.enable_shared_inbox:
            http_503(self)
            return

        self.path = '/inbox'

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'sharedInbox enabled',
                        self.server.debug)

    if self.path == '/categories.xml':
        get_hashtag_categories_feed2(self, calling_domain, self.path,
                                     self.server.base_dir,
                                     proxy_type,
                                     getreq_start_time,
                                     self.server.debug,
                                     curr_session,
                                     self.server.fitness)
        return

    if self.path == '/newswire.xml':
        get_newswire_feed(self, calling_domain, self.path,
                          proxy_type,
                          getreq_start_time,
                          self.server.debug,
                          curr_session,
                          self.server.newswire,
                          self.server.http_prefix,
                          self.server.domain_full,
                          self.server.translate,
                          self.server.fitness)
        return

    # RSS 2.0
    if self.path.startswith('/blog/') and \
       self.path.endswith('/rss.xml'):
        if not self.path == '/blog/rss.xml':
            get_rss2feed(self, calling_domain, self.path,
                         self.server.base_dir,
                         self.server.http_prefix,
                         self.server.domain,
                         self.server.port,
                         proxy_type,
                         getreq_start_time,
                         self.server.debug,
                         curr_session, MAX_POSTS_IN_RSS_FEED,
                         self.server.translate,
                         self.server.system_language,
                         self.server.fitness)
        else:
            get_rss2site(self, calling_domain, self.path,
                         self.server.base_dir,
                         self.server.http_prefix,
                         self.server.domain_full,
                         self.server.port,
                         proxy_type,
                         self.server.translate,
                         getreq_start_time,
                         self.server.debug,
                         curr_session, MAX_POSTS_IN_RSS_FEED,
                         self.server.system_language,
                         self.server.fitness)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'rss2 done',
                        self.server.debug)

    # RSS 3.0
    if self.path.startswith('/blog/') and \
       self.path.endswith('/rss.txt'):
        get_rss3feed(self, calling_domain, self.path,
                     self.server.base_dir,
                     self.server.http_prefix,
                     self.server.domain,
                     self.server.port,
                     proxy_type,
                     getreq_start_time,
                     self.server.debug,
                     self.server.system_language,
                     curr_session, MAX_POSTS_IN_RSS_FEED,
                     self.server.fitness)
        return

    users_in_path = False
    if '/users/' in self.path:
        users_in_path = True

    if authorized and not html_getreq and users_in_path:
        if '/following?page=' in self.path:
            get_following_json(self, self.server.base_dir,
                               self.path,
                               calling_domain, referer_domain,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.port,
                               self.server.followingItemsPerPage,
                               self.server.debug, 'following',
                               self.server.onion_domain,
                               self.server.i2p_domain)
            return
        if '/followers?page=' in self.path:
            get_following_json(self, self.server.base_dir,
                               self.path,
                               calling_domain, referer_domain,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.port,
                               self.server.followingItemsPerPage,
                               self.server.debug, 'followers',
                               self.server.onion_domain,
                               self.server.i2p_domain)
            return
        if '/followrequests?page=' in self.path:
            get_following_json(self, self.server.base_dir,
                               self.path,
                               calling_domain, referer_domain,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.port,
                               self.server.followingItemsPerPage,
                               self.server.debug,
                               'followrequests',
                               self.server.onion_domain,
                               self.server.i2p_domain)
            return

    # authorized endpoint used for TTS of posts
    # arriving in your inbox
    if authorized and users_in_path and \
       self.path.endswith('/speaker'):
        if 'application/ssml' not in self.headers['Accept']:
            # json endpoint
            _get_speaker(self, calling_domain, referer_domain,
                         self.path,
                         self.server.base_dir,
                         self.server.domain)
        else:
            xml_str = \
                get_ssml_box(self.server.base_dir,
                             self.path, self.server.domain,
                             self.server.system_language,
                             self.server.instanceTitle,
                             'inbox')
            if xml_str:
                msg = xml_str.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'application/xrd+xml', msglen,
                            None, calling_domain, False)
                write2(self, msg)
        return

    # show a podcast episode
    if authorized and users_in_path and html_getreq and \
       '?podepisode=' in self.path:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        episode_timestamp = self.path.split('?podepisode=')[1].strip()
        replacements = {
            '__': ' ',
            'aa': ':'
        }
        episode_timestamp = replace_strings(episode_timestamp, replacements)
        if self.server.newswire.get(episode_timestamp):
            pod_episode = self.server.newswire[episode_timestamp]
            html_str = \
                html_podcast_episode(self.server.translate,
                                     self.server.base_dir,
                                     nickname,
                                     self.server.domain,
                                     pod_episode,
                                     self.server.text_mode_banner,
                                     self.server.session,
                                     self.server.session_onion,
                                     self.server.session_i2p,
                                     self.server.http_prefix,
                                     self.server.debug,
                                     self.server.mitm_servers)
            if html_str:
                msg = html_str.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            None, calling_domain, False)
                write2(self, msg)
                return

    # redirect to the welcome screen
    if html_getreq and authorized and users_in_path and \
       '/welcome' not in self.path:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if '?' in nickname:
            nickname = nickname.split('?')[0]
        if nickname == self.authorized_nickname and \
           self.path != '/users/' + nickname:
            if not is_welcome_screen_complete(self.server.base_dir,
                                              nickname,
                                              self.server.domain):
                redirect_headers(self, '/users/' + nickname + '/welcome',
                                 cookie, calling_domain, 303)
                return

    if not html_getreq and \
       users_in_path and self.path.endswith('/pinned'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        pinned_post_json = \
            get_pinned_post_as_json(self.server.base_dir,
                                    self.server.http_prefix,
                                    nickname, self.server.domain,
                                    self.server.domain_full,
                                    self.server.system_language)
        message_json = {}
        if pinned_post_json:
            post_id = remove_id_ending(pinned_post_json['id'])
            message_json = \
                outbox_message_create_wrap(self.server.http_prefix,
                                           nickname,
                                           self.server.domain,
                                           self.server.port,
                                           pinned_post_json)
            message_json['id'] = post_id + '/activity'
            message_json['object']['id'] = post_id
            message_json['object']['url'] = replace_users_with_at(post_id)
            message_json['object']['atomUri'] = post_id
        msg_str = json.dumps(message_json,
                             ensure_ascii=False)
        msg_str = convert_domains(calling_domain,
                                  referer_domain,
                                  msg_str,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.onion_domain,
                                  self.server.i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        return

    if not html_getreq and \
       users_in_path and self.path.endswith('/collections/featured'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        # return the featured posts collection
        get_featured_collection(self, calling_domain, referer_domain,
                                self.server.base_dir,
                                self.server.http_prefix,
                                nickname, self.server.domain,
                                self.server.domain_full,
                                self.server.system_language,
                                self.server.onion_domain,
                                self.server.i2p_domain)
        return

    if not html_getreq and \
       users_in_path and self.path.endswith('/collections/featuredTags'):
        get_featured_tags_collection(self, calling_domain, referer_domain,
                                     self.path,
                                     self.server.http_prefix,
                                     self.server.domain_full,
                                     self.server.domain,
                                     self.server.onion_domain,
                                     self.server.i2p_domain)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'get_featured_tags_collection done',
                        self.server.debug)

    # show a performance graph
    if authorized and '/performance?graph=' in self.path:
        graph = self.path.split('?graph=')[1]
        if html_getreq and not graph.endswith('.json'):
            if graph == 'post':
                graph = '_POST'
            elif graph == 'inbox':
                graph = 'INBOX'
            elif graph == 'get':
                graph = '_GET'
            msg = \
                html_watch_points_graph(self.server.base_dir,
                                        self.server.fitness,
                                        graph, 16).encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'graph',
                                self.server.debug)
            return
        graph = graph.replace('.json', '')
        if graph == 'post':
            graph = '_POST'
        elif graph == 'inbox':
            graph = 'INBOX'
        elif graph == 'get':
            graph = '_GET'
        watch_points_json = \
            sorted_watch_points(self.server.fitness, graph)
        msg_str = json.dumps(watch_points_json,
                             ensure_ascii=False)
        msg_str = convert_domains(calling_domain,
                                  referer_domain,
                                  msg_str,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.onion_domain,
                                  self.server.i2p_domain)
        msg = msg_str.encode('utf-8')
        msglen = len(msg)
        accept_str = self.headers['Accept']
        protocol_str = \
            get_json_content_from_accept(accept_str)
        set_headers(self, protocol_str, msglen,
                    None, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'graph json',
                            self.server.debug)
        return

    # show the main blog page
    if html_getreq and \
       self.path in ('/blog', '/blog/', '/blogs', '/blogs/'):
        if '/rss.xml' not in self.path:
            curr_session = \
                establish_session("show the main blog page",
                                  curr_session,
                                  proxy_type, self.server)
            if not curr_session:
                http_404(self, 121)
                return
            msg = html_blog_view(authorized,
                                 curr_session,
                                 self.server.base_dir,
                                 self.server.http_prefix,
                                 self.server.translate,
                                 self.server.domain,
                                 self.server.port,
                                 MAX_POSTS_IN_BLOGS_FEED,
                                 self.server.peertube_instances,
                                 self.server.system_language,
                                 self.server.person_cache,
                                 self.server.debug)
            if msg is not None:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time, self.server.fitness,
                                    '_GET', 'blog view',
                                    self.server.debug)
                return
            http_404(self, 122)
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'blog view done',
                        self.server.debug)

    # show a particular page of blog entries
    # for a particular account
    if html_getreq and self.path.startswith('/blog/'):
        if '/rss.xml' not in self.path:
            if show_blog_page(self, authorized,
                              calling_domain, self.path,
                              self.server.base_dir,
                              self.server.http_prefix,
                              self.server.domain,
                              self.server.port,
                              getreq_start_time,
                              proxy_type,
                              cookie, self.server.translate,
                              self.server.debug,
                              curr_session, MAX_POSTS_IN_BLOGS_FEED,
                              self.server.peertube_instances,
                              self.server.system_language,
                              self.server.person_cache,
                              self.server.fitness):
                return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show_blog_page',
                        self.server.debug)

    if html_getreq and users_in_path:
        # show the person options screen with view/follow/block/report
        if '?options=' in self.path:
            show_person_options(self, calling_domain, self.path,
                                self.server.base_dir,
                                self.server.domain,
                                self.server.domain_full,
                                getreq_start_time,
                                cookie, self.server.debug,
                                authorized,
                                curr_session)
            return

        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'person options done',
                            self.server.debug)
        # show blog post
        blog_filename, nickname = \
            path_contains_blog_link(self.server.base_dir,
                                    self.server.http_prefix,
                                    self.server.domain,
                                    self.server.domain_full,
                                    self.path)
        if blog_filename and nickname:
            post_json_object = load_json(blog_filename)
            if is_blog_post(post_json_object):
                msg = html_blog_post(curr_session,
                                     authorized,
                                     self.server.base_dir,
                                     self.server.http_prefix,
                                     self.server.translate,
                                     nickname, self.server.domain,
                                     self.server.domain_full,
                                     post_json_object,
                                     self.server.peertube_instances,
                                     self.server.system_language,
                                     self.server.person_cache,
                                     self.server.debug,
                                     self.server.content_license_url)
                if msg is not None:
                    msg = msg.encode('utf-8')
                    msglen = len(msg)
                    set_headers(self, 'text/html', msglen,
                                cookie, calling_domain, False)
                    write2(self, msg)
                    fitness_performance(getreq_start_time,
                                        self.server.fitness,
                                        '_GET', 'blog post 2',
                                        self.server.debug)
                    return
                http_404(self, 123)
                return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'blog post 2 done',
                        self.server.debug)

    # after selecting a shared item from the left column then show it
    if html_getreq and \
       '?showshare=' in self.path and '/users/' in self.path:
        item_id = self.path.split('?showshare=')[1]
        if '?' in item_id:
            item_id = item_id.split('?')[0]
        category = ''
        if '?category=' in self.path:
            category = self.path.split('?category=')[1]
        if '?' in category:
            category = category.split('?')[0]
        users_path = self.path.split('?showshare=')[0]
        nickname = users_path.replace('/users/', '')
        item_id = urllib.parse.unquote_plus(item_id.strip())
        msg = \
            html_show_share(self.server.base_dir,
                            self.server.domain, nickname,
                            self.server.http_prefix,
                            self.server.domain_full,
                            item_id, self.server.translate,
                            self.server.shared_items_federated_domains,
                            self.server.default_timeline,
                            self.server.theme_name, 'shares', category,
                            False)
        if not msg:
            if calling_domain.endswith('.onion') and \
               self.server.onion_domain:
                actor = 'http://' + self.server.onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and
                  self.server.i2p_domain):
                actor = 'http://' + self.server.i2p_domain + users_path
            redirect_headers(self, actor + '/tlshares',
                             cookie, calling_domain, 303)
            return
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'html_show_share',
                            self.server.debug)
        return

    # after selecting a wanted item from the left column then show it
    if html_getreq and \
       '?showwanted=' in self.path and '/users/' in self.path:
        item_id = self.path.split('?showwanted=')[1]
        if ';' in item_id:
            item_id = item_id.split(';')[0]
        category = self.path.split('?category=')[1]
        if ';' in category:
            category = category.split(';')[0]
        users_path = self.path.split('?showwanted=')[0]
        nickname = users_path.replace('/users/', '')
        item_id = urllib.parse.unquote_plus(item_id.strip())
        msg = \
            html_show_share(self.server.base_dir,
                            self.server.domain, nickname,
                            self.server.http_prefix,
                            self.server.domain_full,
                            item_id, self.server.translate,
                            self.server.shared_items_federated_domains,
                            self.server.default_timeline,
                            self.server.theme_name, 'wanted', category,
                            False)
        if not msg:
            if calling_domain.endswith('.onion') and \
               self.server.onion_domain:
                actor = 'http://' + self.server.onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and
                  self.server.i2p_domain):
                actor = 'http://' + self.server.i2p_domain + users_path
            redirect_headers(self, actor + '/tlwanted',
                             cookie, calling_domain, 303)
            return
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'htmlShowWanted',
                            self.server.debug)
        return

    # remove a shared item
    if html_getreq and '?rmshare=' in self.path:
        item_id = self.path.split('?rmshare=')[1]
        item_id = urllib.parse.unquote_plus(item_id.strip())
        users_path = self.path.split('?rmshare=')[0]
        actor = \
            self.server.http_prefix + '://' + \
            self.server.domain_full + users_path
        msg = html_confirm_remove_shared_item(self.server.translate,
                                              self.server.base_dir,
                                              actor, item_id,
                                              calling_domain, 'shares')
        if not msg:
            if calling_domain.endswith('.onion') and \
               self.server.onion_domain:
                actor = 'http://' + self.server.onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and
                  self.server.i2p_domain):
                actor = 'http://' + self.server.i2p_domain + users_path
            redirect_headers(self, actor + '/tlshares',
                             cookie, calling_domain, 303)
            return
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'remove shared item',
                            self.server.debug)
        return

    # remove a wanted item
    if html_getreq and '?rmwanted=' in self.path:
        item_id = self.path.split('?rmwanted=')[1]
        item_id = urllib.parse.unquote_plus(item_id.strip())
        users_path = self.path.split('?rmwanted=')[0]
        actor = \
            self.server.http_prefix + '://' + \
            self.server.domain_full + users_path
        msg = html_confirm_remove_shared_item(self.server.translate,
                                              self.server.base_dir,
                                              actor, item_id,
                                              calling_domain, 'wanted')
        if not msg:
            if calling_domain.endswith('.onion') and \
               self.server.onion_domain:
                actor = 'http://' + self.server.onion_domain + users_path
            elif (calling_domain.endswith('.i2p') and
                  self.server.i2p_domain):
                actor = 'http://' + self.server.i2p_domain + users_path
            redirect_headers(self, actor + '/tlwanted',
                             cookie, calling_domain, 303)
            return
        msg = msg.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'remove shared item',
                            self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'remove shared item done',
                        self.server.debug)

    if self.path.startswith('/terms'):
        if calling_domain.endswith('.onion') and \
           self.server.onion_domain:
            msg = html_terms_of_service(self.server.base_dir, 'http',
                                        self.server.onion_domain)
        elif (calling_domain.endswith('.i2p') and
              self.server.i2p_domain):
            msg = html_terms_of_service(self.server.base_dir, 'http',
                                        self.server.i2p_domain)
        else:
            msg = html_terms_of_service(self.server.base_dir,
                                        self.server.http_prefix,
                                        self.server.domain_full)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'terms of service shown',
                            self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'terms of service done',
                        self.server.debug)

    # show a list of who you are following
    if (authorized and users_in_path and
        (self.path.endswith('/followingaccounts') or
         self.path.endswith('/followingaccounts.csv'))):
        nickname = get_nickname_from_actor(self.path)
        if not nickname:
            http_404(self, 124)
            return
        following_filename = \
            acct_dir(self.server.base_dir,
                     nickname, self.server.domain) + '/following.txt'
        if not os.path.isfile(following_filename):
            http_404(self, 125)
            return
        if self.path.endswith('/followingaccounts.csv'):
            html_getreq = False
            csv_getreq = True
        if html_getreq:
            msg = html_following_list(self.server.base_dir,
                                      following_filename)
            msglen = len(msg)
            login_headers(self, 'text/html', msglen, calling_domain)
            write2(self, msg.encode('utf-8'))
        elif csv_getreq:
            msg = csv_following_list(following_filename,
                                     self.server.base_dir,
                                     nickname,
                                     self.server.domain)
            msglen = len(msg)
            login_headers(self, 'text/csv', msglen, calling_domain)
            write2(self, msg.encode('utf-8'))
        else:
            http_404(self, 126)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'following accounts shown',
                            self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'following accounts done',
                        self.server.debug)

    # show a list of who are your followers
    if authorized and users_in_path and \
       self.path.endswith('/followersaccounts'):
        nickname = get_nickname_from_actor(self.path)
        if not nickname:
            http_404(self, 127)
            return
        followers_filename = \
            acct_dir(self.server.base_dir,
                     nickname, self.server.domain) + '/followers.txt'
        if not os.path.isfile(followers_filename):
            http_404(self, 128)
            return
        if html_getreq:
            msg = html_following_list(self.server.base_dir,
                                      followers_filename)
            msglen = len(msg)
            login_headers(self, 'text/html', msglen, calling_domain)
            write2(self, msg.encode('utf-8'))
        elif csv_getreq:
            msg = csv_following_list(followers_filename,
                                     self.server.base_dir,
                                     nickname,
                                     self.server.domain)
            msglen = len(msg)
            login_headers(self, 'text/csv', msglen, calling_domain)
            write2(self, msg.encode('utf-8'))
        else:
            http_404(self, 129)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'followers accounts shown',
                            self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'followers accounts done',
                        self.server.debug)

    if self.path.endswith('/about'):
        if calling_domain.endswith('.onion'):
            msg = \
                html_about(self.server.base_dir, 'http',
                           self.server.onion_domain,
                           None, self.server.translate,
                           self.server.system_language)
        elif calling_domain.endswith('.i2p'):
            msg = \
                html_about(self.server.base_dir, 'http',
                           self.server.i2p_domain,
                           None, self.server.translate,
                           self.server.system_language)
        else:
            msg = \
                html_about(self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain_full,
                           self.server.onion_domain,
                           self.server.translate,
                           self.server.system_language)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'show about screen',
                            self.server.debug)
        return

    if self.path in ('/specification', '/protocol', '/activitypub'):
        if calling_domain.endswith('.onion'):
            msg = \
                html_specification(self.server.base_dir, 'http',
                                   self.server.onion_domain,
                                   None, self.server.translate,
                                   self.server.system_language)
        elif calling_domain.endswith('.i2p'):
            msg = \
                html_specification(self.server.base_dir, 'http',
                                   self.server.i2p_domain,
                                   None, self.server.translate,
                                   self.server.system_language)
        else:
            msg = \
                html_specification(self.server.base_dir,
                                   self.server.http_prefix,
                                   self.server.domain_full,
                                   self.server.onion_domain,
                                   self.server.translate,
                                   self.server.system_language)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'show specification screen',
                            self.server.debug)
        return

    if self.path.endswith('/knowninstances'):
        if not authorized:
            http_403(self)
            return

        if calling_domain.endswith('.onion'):
            msg = \
                html_known_epicyon_instances(
                    self.server.base_dir, 'http',
                    self.server.onion_domain,
                    self.server.system_language,
                    self.server.known_epicyon_instances,
                    self.server.translate)
        elif calling_domain.endswith('.i2p'):
            msg = \
                html_known_epicyon_instances(
                    self.server.base_dir, 'http',
                    self.server.i2p_domain,
                    self.server.system_language,
                    self.server.known_epicyon_instances,
                    self.server.translate)
        else:
            msg = \
                html_known_epicyon_instances(
                    self.server.base_dir,
                    self.server.http_prefix,
                    self.server.domain_full,
                    self.server.system_language,
                    self.server.known_epicyon_instances,
                    self.server.translate)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'known epicyon instances',
                            self.server.debug)
        return

    if self.path in ('/manual', '/usermanual', '/userguide'):
        if calling_domain.endswith('.onion'):
            msg = \
                html_manual(self.server.base_dir, 'http',
                            self.server.onion_domain,
                            None, self.server.translate,
                            self.server.system_language)
        elif calling_domain.endswith('.i2p'):
            msg = \
                html_manual(self.server.base_dir, 'http',
                            self.server.i2p_domain,
                            None, self.server.translate,
                            self.server.system_language)
        else:
            msg = \
                html_manual(self.server.base_dir,
                            self.server.http_prefix,
                            self.server.domain_full,
                            self.server.onion_domain,
                            self.server.translate,
                            self.server.system_language)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'show user manual screen',
                            self.server.debug)
        return

    if html_getreq and users_in_path and authorized and \
       self.path.endswith('/accesskeys'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]

        access_keys = self.server.access_keys
        if self.server.key_shortcuts.get(nickname):
            access_keys = \
                self.server.key_shortcuts[nickname]

        msg = \
            html_access_keys(self.server.base_dir,
                             nickname, self.server.domain,
                             self.server.translate,
                             access_keys,
                             self.server.access_keys,
                             self.server.default_timeline,
                             self.server.theme_name)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'show accesskeys screen',
                            self.server.debug)
        return

    if html_getreq and users_in_path and authorized and \
       self.path.endswith('/themedesigner'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]

        if not is_artist(self.server.base_dir, nickname):
            http_403(self)
            return

        msg = \
            html_theme_designer(self.server.base_dir,
                                nickname, self.server.domain,
                                self.server.translate,
                                self.server.default_timeline,
                                self.server.theme_name,
                                self.server.access_keys)
        msg = msg.encode('utf-8')
        msglen = len(msg)
        login_headers(self, 'text/html', msglen, calling_domain)
        write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'show theme designer screen',
                            self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show about screen done',
                        self.server.debug)

    # the initial welcome screen after first logging in
    if html_getreq and authorized and \
       '/users/' in self.path and self.path.endswith('/welcome'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if not is_welcome_screen_complete(self.server.base_dir,
                                          nickname,
                                          self.server.domain):
            msg = \
                html_welcome_screen(self.server.base_dir, nickname,
                                    self.server.system_language,
                                    self.server.translate,
                                    self.server.theme_name)
            msg = msg.encode('utf-8')
            msglen = len(msg)
            login_headers(self, 'text/html', msglen, calling_domain)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'show welcome screen',
                                self.server.debug)
            return
        self.path = self.path.replace('/welcome', '')

    # the welcome screen which allows you to set an avatar image
    if html_getreq and authorized and \
       '/users/' in self.path and self.path.endswith('/welcome_profile'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if not is_welcome_screen_complete(self.server.base_dir,
                                          nickname,
                                          self.server.domain):
            msg = \
                html_welcome_profile(self.server.base_dir, nickname,
                                     self.server.domain,
                                     self.server.http_prefix,
                                     self.server.domain_full,
                                     self.server.system_language,
                                     self.server.translate,
                                     self.server.theme_name)
            msg = msg.encode('utf-8')
            msglen = len(msg)
            login_headers(self, 'text/html', msglen, calling_domain)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'show welcome profile screen',
                                self.server.debug)
            return
        self.path = self.path.replace('/welcome_profile', '')

    # the final welcome screen
    if html_getreq and authorized and \
       '/users/' in self.path and self.path.endswith('/welcome_final'):
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if not is_welcome_screen_complete(self.server.base_dir,
                                          nickname,
                                          self.server.domain):
            msg = \
                html_welcome_final(self.server.base_dir, nickname,
                                   self.server.system_language,
                                   self.server.translate,
                                   self.server.theme_name)
            msg = msg.encode('utf-8')
            msglen = len(msg)
            login_headers(self, 'text/html', msglen, calling_domain)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'show welcome final screen',
                                self.server.debug)
            return
        self.path = self.path.replace('/welcome_final', '')

    # if not authorized then show the login screen
    if html_getreq and self.path != '/login' and \
       not is_image_file(self.path) and \
       self.path != '/' and \
       not self.path.startswith('/.well-known/protocol-handler') and \
       self.path != '/users/news/linksmobile' and \
       self.path != '/users/news/newswiremobile':
        if redirect_to_login_screen(self, calling_domain, self.path,
                                    self.server.http_prefix,
                                    self.server.domain_full,
                                    self.server.onion_domain,
                                    self.server.i2p_domain,
                                    getreq_start_time,
                                    authorized, self.server.debug,
                                    self.server.news_instance,
                                    self.server.fitness):
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show login screen done',
                        self.server.debug)

    # manifest images used to create a home screen icon
    # when selecting "add to home screen" in browsers
    # which support progressive web apps
    if self.path in ('/logo72.png', '/logo96.png', '/logo128.png',
                     '/logo144.png', '/logo150.png', '/logo192.png',
                     '/logo256.png', '/logo512.png',
                     '/apple-touch-icon.png'):
        media_filename = \
            self.server.base_dir + '/img' + self.path
        if os.path.isfile(media_filename):
            if etag_exists(self, media_filename):
                # The file has not changed
                http_304(self)
                return

            tries = 0
            media_binary = None
            while tries < 5:
                try:
                    with open(media_filename, 'rb') as fp_av:
                        media_binary = fp_av.read()
                        break
                except OSError as ex:
                    print('EX: manifest logo ' +
                          str(tries) + ' ' + str(ex))
                    time.sleep(1)
                    tries += 1
            if media_binary:
                mime_type = media_file_mime_type(media_filename)
                set_headers_etag(self, media_filename, mime_type,
                                 media_binary, cookie,
                                 self.server.domain_full,
                                 False, None)
                write2(self, media_binary)
                fitness_performance(getreq_start_time, self.server.fitness,
                                    '_GET', 'manifest logo shown',
                                    self.server.debug)
                return
        http_404(self, 130)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'manifest logo done',
                        self.server.debug)

    # manifest images used to show example screenshots
    # for use by app stores
    if self.path in ('/screenshot1.jpg', '/screenshot2.jpg'):
        screen_filename = \
            self.server.base_dir + '/img' + self.path
        if os.path.isfile(screen_filename):
            if etag_exists(self, screen_filename):
                # The file has not changed
                http_304(self)
                return

            tries = 0
            media_binary = None
            while tries < 5:
                try:
                    with open(screen_filename, 'rb') as fp_av:
                        media_binary = fp_av.read()
                        break
                except OSError as ex:
                    print('EX: manifest screenshot ' +
                          str(tries) + ' ' + str(ex))
                    time.sleep(1)
                    tries += 1
            if media_binary:
                mime_type = media_file_mime_type(screen_filename)
                set_headers_etag(self, screen_filename, mime_type,
                                 media_binary, cookie,
                                 self.server.domain_full,
                                 False, None)
                write2(self, media_binary)
                fitness_performance(getreq_start_time, self.server.fitness,
                                    '_GET', 'show screenshot',
                                    self.server.debug)
                return
        http_404(self, 131)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show screenshot done',
                        self.server.debug)

    # image on login screen or qrcode
    if (is_image_file(self.path) and
        (self.path.startswith('/login.') or
         self.path.startswith('/qrcode.png'))):
        icon_filename = data_dir(self.server.base_dir) + self.path
        if os.path.isfile(icon_filename):
            if etag_exists(self, icon_filename):
                # The file has not changed
                http_304(self)
                return

            tries = 0
            media_binary = None
            while tries < 5:
                try:
                    with open(icon_filename, 'rb') as fp_av:
                        media_binary = fp_av.read()
                        break
                except OSError as ex:
                    print('EX: login screen image ' +
                          str(tries) + ' ' + str(ex))
                    time.sleep(1)
                    tries += 1
            if media_binary:
                mime_type_str = media_file_mime_type(icon_filename)
                set_headers_etag(self, icon_filename,
                                 mime_type_str,
                                 media_binary, cookie,
                                 self.server.domain_full,
                                 False, None)
                write2(self, media_binary)
                fitness_performance(getreq_start_time, self.server.fitness,
                                    '_GET', 'login screen logo',
                                    self.server.debug)
                return
        http_404(self, 132)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'login screen logo done',
                        self.server.debug)

    # QR code for account handle
    if users_in_path and \
       self.path.endswith('/qrcode.png'):
        if show_qrcode(self, calling_domain, self.path,
                       self.server.base_dir,
                       self.server.domain,
                       self.server.domain_full,
                       self.server.onion_domain,
                       self.server.i2p_domain,
                       self.server.port,
                       getreq_start_time,
                       self.server.fitness,
                       self.server.debug):
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'account qrcode done',
                        self.server.debug)

    if users_in_path:
        # search screen banner image
        if self.path.endswith('/search_banner.png'):
            if search_screen_banner(self, self.path,
                                    self.server.base_dir,
                                    self.server.domain,
                                    getreq_start_time,
                                    self.server.domain_full,
                                    self.server.fitness,
                                    self.server.debug):
                return

        # main timeline left column
        if self.path.endswith('/left_col_image.png'):
            if column_image(self, 'left', self.path,
                            self.server.base_dir,
                            self.server.domain,
                            getreq_start_time,
                            self.server.domain_full,
                            self.server.fitness,
                            self.server.debug):
                return

        # main timeline right column
        if self.path.endswith('/right_col_image.png'):
            if column_image(self, 'right', self.path,
                            self.server.base_dir,
                            self.server.domain,
                            getreq_start_time,
                            self.server.domain_full,
                            self.server.fitness,
                            self.server.debug):
                return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'search screen banner done',
                        self.server.debug)

    if self.path.startswith('/defaultprofilebackground'):
        show_default_profile_background(self, self.server.base_dir,
                                        self.server.theme_name,
                                        getreq_start_time,
                                        self.server.domain_full,
                                        self.server.fitness,
                                        self.server.debug)
        return

    # show a background image on the login or person options page
    if '-background.' in self.path:
        if show_background_image(self, self.path,
                                 self.server.base_dir,
                                 getreq_start_time,
                                 self.server.domain_full,
                                 self.server.fitness,
                                 self.server.debug):
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'background shown done',
                        self.server.debug)

    # emoji images
    if '/emoji/' in self.path:
        show_emoji(self, self.path, self.server.base_dir,
                   getreq_start_time, self.server.domain_full,
                   self.server.fitness, self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show emoji done',
                        self.server.debug)

    # show media
    # Note that this comes before the busy flag to avoid conflicts
    # replace mastoson-style media path
    if '/system/media_attachments/files/' in self.path:
        self.path = self.path.replace('/system/media_attachments/files/',
                                      '/media/')
    if '/media/' in self.path:
        show_media(self, self.path, self.server.base_dir,
                   getreq_start_time, self.server.fitness,
                   self.server.debug)
        return

    if '/ontologies/' in self.path or \
       '/data/' in self.path:
        if not has_users_path(self.path):
            _get_ontology(self, calling_domain,
                          self.path, self.server.base_dir,
                          getreq_start_time)
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show media done',
                        self.server.debug)

    # show shared item images
    # Note that this comes before the busy flag to avoid conflicts
    if '/sharefiles/' in self.path:
        if show_share_image(self, self.path, self.server.base_dir,
                            getreq_start_time,
                            self.server.domain_full,
                            self.server.fitness,
                            self.server.debug):
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'share image done',
                        self.server.debug)

    # icon images
    # Note that this comes before the busy flag to avoid conflicts
    if self.path.startswith('/icons/'):
        show_icon(self, self.path, self.server.base_dir,
                  getreq_start_time, self.server.theme_name,
                  self.server.iconsCache,
                  self.server.domain_full,
                  self.server.fitness, self.server.debug)
        return

    # show images within https://instancedomain/activitypub
    if self.path.startswith('/activitypub-tutorial-'):
        if self.path.endswith('.png'):
            show_specification_image(self, self.path,
                                     self.server.base_dir,
                                     getreq_start_time,
                                     self.server.iconsCache,
                                     self.server.domain_full,
                                     self.server.fitness,
                                     self.server.debug)
            return

    # show images within https://instancedomain/manual
    if self.path.startswith('/manual-'):
        if is_image_file(self.path):
            show_manual_image(self, self.path,
                              self.server.base_dir,
                              getreq_start_time,
                              self.server.iconsCache,
                              self.server.domain_full,
                              self.server.fitness,
                              self.server.debug)
            return

    # help screen images
    # Note that this comes before the busy flag to avoid conflicts
    if self.path.startswith('/helpimages/'):
        show_help_screen_image(self, self.path,
                               self.server.base_dir,
                               getreq_start_time,
                               self.server.theme_name,
                               self.server.domain_full,
                               self.server.fitness,
                               self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'help screen image done',
                        self.server.debug)

    # cached avatar images
    # Note that this comes before the busy flag to avoid conflicts
    if self.path.startswith('/avatars/'):
        show_cached_avatar(self, referer_domain, self.path,
                           self.server.base_dir,
                           getreq_start_time,
                           self.server.fitness,
                           self.server.debug)
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'cached avatar done',
                        self.server.debug)

    # show avatar or background image
    # Note that this comes before the busy flag to avoid conflicts
    if show_avatar_or_banner(self, referer_domain, self.path,
                             self.server.base_dir,
                             self.server.domain,
                             getreq_start_time,
                             self.server.fitness,
                             self.server.debug):
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'avatar or banner shown done',
                        self.server.debug)

    # This busy state helps to avoid flooding
    # Resources which are expected to be called from a web page
    # should be above this
    curr_time_getreq = int(time.time() * 1000)
    if self.server.getreq_busy:
        if curr_time_getreq - self.server.last_getreq < 500:
            if self.server.debug:
                print('DEBUG: GET Busy')
            self.send_response(429)
            self.end_headers()
            return
    self.server.getreq_busy = True
    self.server.last_getreq = curr_time_getreq

    # returns after this point should set getreq_busy to False

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'GET busy time',
                        self.server.debug)

    if not permitted_dir(self.path):
        if self.server.debug:
            print('DEBUG: GET Not permitted')
        http_404(self, 133)
        self.server.getreq_busy = False
        return

    # get webfinger endpoint for a person
    if get_webfinger(self, calling_domain, referer_domain, cookie,
                     self.path, self.server.debug,
                     self.server.onion_domain,
                     self.server.i2p_domain,
                     self.server.http_prefix,
                     self.server.domain,
                     self.server.domain_full,
                     self.server.base_dir,
                     self.server.port):
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'webfinger called',
                            self.server.debug)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'permitted directory',
                        self.server.debug)

    # show the login screen
    if show_login_screen(self.path, authorized,
                         self.server.news_instance,
                         self.server.translate,
                         self.server.base_dir,
                         self.server.http_prefix,
                         self.server.domain_full,
                         self.server.system_language,
                         ua_str, self.server.theme_name,
                         calling_domain,
                         getreq_start_time,
                         self.server.fitness, self.server.debug,
                         self):
        self.server.getreq_busy = False
        return

    # show the news front page
    if self.path == '/' and \
       not authorized and \
       self.server.news_instance:
        news_url = get_instance_url(calling_domain,
                                    self.server.http_prefix,
                                    self.server.domain_full,
                                    self.server.onion_domain,
                                    self.server.i2p_domain) + \
                                    '/users/news'
        logout_redirect(self, news_url, calling_domain)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'news front page shown',
                            self.server.debug)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'login shown done',
                        self.server.debug)

    # the newswire screen on mobile
    if html_getreq and self.path.startswith('/users/') and \
       self.path.endswith('/newswiremobile'):
        if (authorized or
            (not authorized and
             self.path.startswith('/users/news/') and
             self.server.news_instance)):
            nickname = get_nickname_from_actor(self.path)
            if not nickname:
                http_404(self, 134)
                self.server.getreq_busy = False
                return
            timeline_path = \
                '/users/' + nickname + '/' + self.server.default_timeline
            show_publish_as_icon = self.server.show_publish_as_icon
            rss_icon_at_top = self.server.rss_icon_at_top
            icons_as_buttons = self.server.icons_as_buttons
            default_timeline = self.server.default_timeline
            access_keys = self.server.access_keys
            if self.server.key_shortcuts.get(nickname):
                access_keys = self.server.key_shortcuts[nickname]
            msg = \
                html_newswire_mobile(self.server.base_dir,
                                     nickname,
                                     self.server.domain,
                                     self.server.domain_full,
                                     self.server.translate,
                                     self.server.newswire,
                                     self.server.positive_voting,
                                     timeline_path,
                                     show_publish_as_icon,
                                     authorized,
                                     rss_icon_at_top,
                                     icons_as_buttons,
                                     default_timeline,
                                     self.server.theme_name,
                                     access_keys,
                                     ua_str).encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            self.server.getreq_busy = False
            return

    if html_getreq and self.path.startswith('/users/') and \
       self.path.endswith('/linksmobile'):
        if (authorized or
            (not authorized and
             self.path.startswith('/users/news/') and
             self.server.news_instance)):
            nickname = get_nickname_from_actor(self.path)
            if not nickname:
                http_404(self, 135)
                self.server.getreq_busy = False
                return
            access_keys = self.server.access_keys
            if self.server.key_shortcuts.get(nickname):
                access_keys = self.server.key_shortcuts[nickname]
            timeline_path = \
                '/users/' + nickname + '/' + self.server.default_timeline
            icons_as_buttons = self.server.icons_as_buttons
            default_timeline = self.server.default_timeline
            shared_items_domains = \
                self.server.shared_items_federated_domains
            known_instances = self.server.known_epicyon_instances
            msg = \
                html_links_mobile(self.server.base_dir, nickname,
                                  self.server.domain_full,
                                  self.server.http_prefix,
                                  self.server.translate,
                                  timeline_path,
                                  authorized,
                                  self.server.rss_icon_at_top,
                                  icons_as_buttons,
                                  default_timeline,
                                  self.server.theme_name,
                                  access_keys,
                                  shared_items_domains,
                                  known_instances).encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen, cookie, calling_domain,
                        False)
            write2(self, msg)
            self.server.getreq_busy = False
            return

    if '?remotetag=' in self.path and \
       '/users/' in self.path and authorized:
        actor = self.path.split('?remotetag=')[0]
        nickname = get_nickname_from_actor(actor)
        hashtag_url = self.path.split('?remotetag=')[1]
        if ';' in hashtag_url:
            hashtag_url = hashtag_url.split(';')[0]
        hashtag_url = hashtag_url.replace('--', '/')

        page_number = 1
        if ';page=' in self.path:
            page_number_str = self.path.split(';page=')[1]
            if ';' in page_number_str:
                page_number_str = page_number_str.split(';')[0]
            if page_number_str.isdigit():
                page_number = int(page_number_str)

        allow_local_network_access = self.server.allow_local_network_access
        show_published_date_only = self.server.show_published_date_only
        twitter_replacement_domain = self.server.twitter_replacement_domain
        timezone = None
        if self.server.account_timezone.get(nickname):
            timezone = \
                self.server.account_timezone.get(nickname)
        msg = \
            html_hashtag_search_remote(nickname,
                                       self.server.domain,
                                       self.server.port,
                                       self.server.recent_posts_cache,
                                       self.server.max_recent_posts,
                                       self.server.translate,
                                       self.server.base_dir,
                                       hashtag_url,
                                       page_number, MAX_POSTS_IN_FEED,
                                       self.server.session,
                                       self.server.cached_webfingers,
                                       self.server.person_cache,
                                       self.server.http_prefix,
                                       self.server.project_version,
                                       self.server.yt_replace_domain,
                                       twitter_replacement_domain,
                                       show_published_date_only,
                                       self.server.peertube_instances,
                                       allow_local_network_access,
                                       self.server.theme_name,
                                       self.server.system_language,
                                       self.server.max_like_count,
                                       self.server.signing_priv_key_pem,
                                       self.server.cw_lists,
                                       self.server.lists_enabled,
                                       timezone,
                                       self.server.bold_reading,
                                       self.server.dogwhistles,
                                       self.server.min_images_for_accounts,
                                       self.server.debug,
                                       self.server.buy_sites,
                                       self.server.auto_cw_cache,
                                       self.server.mitm_servers,
                                       self.server.instance_software)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen, cookie, calling_domain,
                        False)
            write2(self, msg)
            self.server.getreq_busy = False
            return
        hashtag = urllib.parse.unquote(hashtag_url.split('/')[-1])
        tags_filename = \
            self.server.base_dir + '/tags/' + hashtag + '.txt'
        if os.path.isfile(tags_filename):
            # redirect to the local hashtag screen
            self.server.getreq_busy = False
            ht_url = \
                get_instance_url(calling_domain,
                                 self.server.http_prefix,
                                 self.server.domain_full,
                                 self.server.onion_domain,
                                 self.server.i2p_domain) + \
                '/users/' + nickname + '/tags/' + hashtag
            redirect_headers(self, ht_url, cookie, calling_domain, 303)
        else:
            # redirect to the upstream hashtag url
            self.server.getreq_busy = False
            redirect_headers(self, hashtag_url, None, calling_domain, 303)
        return

    # hashtag search
    if self.path.startswith('/tags/') or \
       (authorized and '/tags/' in self.path):
        if self.path.startswith('/tags/rss2/'):
            hashtag_search_rss2(self, calling_domain,
                                self.path, cookie,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.domain_full,
                                self.server.port,
                                self.server.onion_domain,
                                self.server.i2p_domain,
                                getreq_start_time,
                                self.server.system_language,
                                self.server.fitness,
                                self.server.debug)
            self.server.getreq_busy = False
            return
        if not html_getreq:
            hashtag_search_json2(self, calling_domain, referer_domain,
                                 self.path, cookie,
                                 self.server.base_dir,
                                 self.server.http_prefix,
                                 self.server.domain,
                                 self.server.domain_full,
                                 self.server.port,
                                 self.server.onion_domain,
                                 self.server.i2p_domain,
                                 getreq_start_time,
                                 MAX_POSTS_IN_FEED,
                                 self.server.fitness,
                                 self.server.debug)
            self.server.getreq_busy = False
            return
        hashtag_search2(self, calling_domain,
                        self.path, cookie,
                        self.server.base_dir,
                        self.server.http_prefix,
                        self.server.domain,
                        self.server.domain_full,
                        self.server.port,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        getreq_start_time,
                        curr_session,
                        MAX_POSTS_IN_HASHTAG_FEED,
                        self.server.translate,
                        self.server.account_timezone,
                        self.server.bold_reading,
                        self.server.fitness,
                        self.server.debug,
                        self.server.recent_posts_cache,
                        self.server.max_recent_posts,
                        self.server.cached_webfingers,
                        self.server.person_cache,
                        self.server.project_version,
                        self.server.yt_replace_domain,
                        self.server.twitter_replacement_domain,
                        self.server.show_published_date_only,
                        self.server.peertube_instances,
                        self.server.allow_local_network_access,
                        self.server.theme_name,
                        self.server.system_language,
                        self.server.max_like_count,
                        self.server.signing_priv_key_pem,
                        self.server.cw_lists,
                        self.server.lists_enabled,
                        self.server.dogwhistles,
                        self.server.map_format,
                        self.server.access_keys,
                        self.server.min_images_for_accounts,
                        self.server.buy_sites,
                        self.server.auto_cw_cache,
                        ua_str, self.server.mitm_servers,
                        self.server.instance_software)
        self.server.getreq_busy = False
        return

    # hashtag map kml
    if self.path.startswith('/tagmaps/') or \
       (authorized and '/tagmaps/' in self.path):
        map_str = \
            map_format_from_tagmaps_path(self.server.base_dir, self.path,
                                         self.server.map_format,
                                         self.server.domain,
                                         self.server.session)
        if map_str:
            msg = map_str.encode('utf-8')
            msglen = len(msg)
            if self.server.map_format == 'gpx':
                header_type = \
                    'application/gpx+xml; charset=utf-8'
            else:
                header_type = \
                    'application/vnd.google-earth.kml+xml; charset=utf-8'
            set_headers(self, header_type, msglen,
                        None, calling_domain, True)
            write2(self, msg)
            self.server.getreq_busy = False
            return
        http_404(self, 136)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'hashtag search done',
                        self.server.debug)

    # hide announces button in the web interface
    if html_getreq and users_in_path and \
       self.path.endswith('/hideannounces') and \
       authorized:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if self.server.hide_announces.get(nickname):
            del self.server.hide_announces[nickname]
        else:
            self.server.hide_announces[nickname] = True
        hide_announces_filename = \
            data_dir(self.server.base_dir) + '/hide_announces.json'
        save_json(self.server.hide_announces, hide_announces_filename)
        self.path = get_default_path(self.server.media_instance,
                                     self.server.blogs_instance,
                                     nickname)

    # show or hide buttons in the web interface
    if html_getreq and users_in_path and \
       self.path.endswith('/minimal') and \
       authorized:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        not_min = not is_minimal(self.server.base_dir,
                                 self.server.domain, nickname)
        set_minimal(self.server.base_dir,
                    self.server.domain, nickname, not_min)
        self.path = get_default_path(self.server.media_instance,
                                     self.server.blogs_instance,
                                     nickname)

    # search for a fediverse address, shared item or emoji
    # from the web interface by selecting search icon
    if html_getreq and users_in_path:
        if self.path.endswith('/search') or \
           '/search?' in self.path:
            if '?' in self.path:
                self.path = self.path.split('?')[0]

            nickname = self.path.split('/users/')[1]
            if '/' in nickname:
                nickname = nickname.split('/')[0]

            access_keys = self.server.access_keys
            if self.server.key_shortcuts.get(nickname):
                access_keys = self.server.key_shortcuts[nickname]

            # show the search screen
            msg = html_search(self.server.translate,
                              self.server.base_dir, self.path,
                              self.server.domain,
                              self.server.default_timeline,
                              self.server.theme_name,
                              self.server.text_mode_banner,
                              access_keys)
            if msg:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen, cookie,
                            calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time, self.server.fitness,
                                    '_GET', 'search screen shown',
                                    self.server.debug)
            self.server.getreq_busy = False
            return

    # show a hashtag category from the search screen
    if html_getreq and '/category/' in self.path:
        msg = html_search_hashtag_category(self.server.translate,
                                           self.server.base_dir, self.path,
                                           self.server.domain,
                                           self.server.theme_name)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen, cookie, calling_domain,
                        False)
            write2(self, msg)
        fitness_performance(getreq_start_time, self.server.fitness,
                            '_GET', 'hashtag category screen shown',
                            self.server.debug)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'search screen shown done',
                        self.server.debug)

    # Show the html calendar for a user
    if html_getreq and users_in_path:
        if '/calendar' in self.path:
            nickname = self.path.split('/users/')[1]
            if '/' in nickname:
                nickname = nickname.split('/')[0]

            access_keys = self.server.access_keys
            if self.server.key_shortcuts.get(nickname):
                access_keys = self.server.key_shortcuts[nickname]

            # show the calendar screen
            msg = html_calendar(self.server.person_cache,
                                self.server.translate,
                                self.server.base_dir, self.path,
                                self.server.http_prefix,
                                self.server.domain_full,
                                self.server.text_mode_banner,
                                access_keys,
                                False, self.server.system_language,
                                self.server.default_timeline,
                                self.server.theme_name,
                                self.server.session,
                                self.server.session_onion,
                                self.server.session_i2p,
                                ua_str)
            if msg:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                if 'ical=true' in self.path:
                    set_headers(self, 'text/calendar',
                                msglen, cookie, calling_domain,
                                      False)
                else:
                    set_headers(self, 'text/html',
                                msglen, cookie, calling_domain,
                                False)
                write2(self, msg)
                fitness_performance(getreq_start_time, self.server.fitness,
                                    '_GET', 'calendar shown',
                                    self.server.debug)
            else:
                http_404(self, 137)
            self.server.getreq_busy = False
            return

    # Show the icalendar for a user
    if icalendar_getreq and users_in_path:
        if '/calendar' in self.path:
            nickname = self.path.split('/users/')[1]
            if '/' in nickname:
                nickname = nickname.split('/')[0]

            access_keys = self.server.access_keys
            if self.server.key_shortcuts.get(nickname):
                access_keys = self.server.key_shortcuts[nickname]

            # show the calendar screen
            msg = html_calendar(self.server.person_cache,
                                self.server.translate,
                                self.server.base_dir, self.path,
                                self.server.http_prefix,
                                self.server.domain_full,
                                self.server.text_mode_banner,
                                access_keys,
                                True,
                                self.server.system_language,
                                self.server.default_timeline,
                                self.server.theme_name,
                                self.server.session,
                                self.server.session_onion,
                                self.server.session_i2p,
                                ua_str)
            if msg:
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/calendar',
                            msglen, cookie, calling_domain,
                                  False)
                write2(self, msg)
            else:
                http_404(self, 138)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'icalendar shown',
                                self.server.debug)
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'calendar shown done',
                        self.server.debug)

    # Show confirmation for deleting a calendar event
    if html_getreq and users_in_path:
        if '/eventdelete' in self.path and \
           '?time=' in self.path and \
           '?eventid=' in self.path:
            if _confirm_delete_event(self, calling_domain, self.path,
                                     self.server.base_dir,
                                     self.server.http_prefix,
                                     cookie,
                                     self.server.translate,
                                     self.server.domain_full,
                                     self.server.onion_domain,
                                     self.server.i2p_domain,
                                     getreq_start_time):
                self.server.getreq_busy = False
                return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'calendar delete shown done',
                        self.server.debug)

    # search for emoji by name
    if html_getreq and users_in_path:
        if self.path.endswith('/searchemoji'):
            # show the search screen
            msg = \
                html_search_emoji_text_entry(self.server.translate,
                                             self.server.base_dir,
                                             self.path).encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'emoji search shown',
                                self.server.debug)
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'emoji search shown done',
                        self.server.debug)

    repeat_private = False
    if html_getreq and '?repeatprivate=' in self.path:
        repeat_private = True
        self.path = self.path.replace('?repeatprivate=', '?repeat=')
    # announce/repeat button was pressed
    if authorized and html_getreq and '?repeat=' in self.path:
        announce_button(self, calling_domain, self.path,
                        self.server.base_dir,
                        cookie, proxy_type,
                        self.server.http_prefix,
                        self.server.domain,
                        self.server.domain_full,
                        self.server.port,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        getreq_start_time,
                        repeat_private,
                        self.server.debug,
                        curr_session,
                        self.server.sites_unavailable,
                        self.server.federation_list,
                        self.server.send_threads,
                        self.server.post_log,
                        self.server.person_cache,
                        self.server.cached_webfingers,
                        self.server.project_version,
                        self.server.signing_priv_key_pem,
                        self.server.system_language,
                        self.server.recent_posts_cache,
                        self.server.max_recent_posts,
                        self.server.translate,
                        self.server.allow_deletion,
                        self.server.yt_replace_domain,
                        self.server.twitter_replacement_domain,
                        self.server.show_published_date_only,
                        self.server.peertube_instances,
                        self.server.allow_local_network_access,
                        self.server.theme_name,
                        self.server.max_like_count,
                        self.server.cw_lists,
                        self.server.lists_enabled,
                        self.server.dogwhistles,
                        self.server.buy_sites,
                        self.server.auto_cw_cache,
                        self.server.fitness,
                        self.server.iconsCache,
                        self.server.account_timezone,
                        self.server.bold_reading,
                        self.server.min_images_for_accounts,
                        self.server.session_onion,
                        self.server.session_i2p,
                        self.server.mitm_servers,
                        self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show announce done',
                        self.server.debug)

    if authorized and html_getreq and '?unrepeatprivate=' in self.path:
        self.path = self.path.replace('?unrepeatprivate=', '?unrepeat=')

    # undo an announce/repeat from the web interface
    if authorized and html_getreq and '?unrepeat=' in self.path:
        announce_button_undo(self, calling_domain, self.path,
                             self.server.base_dir,
                             cookie, proxy_type,
                             self.server.http_prefix,
                             self.server.domain,
                             self.server.domain_full,
                             self.server.onion_domain,
                             self.server.i2p_domain,
                             getreq_start_time,
                             self.server.debug,
                             self.server.recent_posts_cache,
                             curr_session,
                             self.server.iconsCache,
                             self.server.project_version,
                             self.server.fitness,
                             self.server.session_onion,
                             self.server.session_i2p)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'unannounce done',
                        self.server.debug)

    # send a newswire moderation vote from the web interface
    if authorized and '/newswirevote=' in self.path and \
       self.path.startswith('/users/'):
        newswire_vote(self, calling_domain, self.path,
                      cookie,
                      self.server.base_dir,
                      self.server.http_prefix,
                      self.server.domain_full,
                      self.server.onion_domain,
                      self.server.i2p_domain,
                      getreq_start_time,
                      self.server.newswire,
                      self.server.default_timeline,
                      self.server.fitness,
                      self.server.debug)
        self.server.getreq_busy = False
        return

    # send a newswire moderation unvote from the web interface
    if authorized and '/newswireunvote=' in self.path and \
       self.path.startswith('/users/'):
        newswire_unvote(self, calling_domain, self.path,
                        cookie,
                        self.server.base_dir,
                        self.server.http_prefix,
                        self.server.domain_full,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        getreq_start_time,
                        self.server.debug,
                        self.server.newswire,
                        self.server.default_timeline,
                        self.server.fitness)
        self.server.getreq_busy = False
        return

    # send a follow request approval from the web interface
    if authorized and '/followapprove=' in self.path and \
       self.path.startswith('/users/'):
        follow_approve_button(self, calling_domain, self.path,
                              cookie,
                              self.server.base_dir,
                              self.server.http_prefix,
                              self.server.domain,
                              self.server.domain_full,
                              self.server.port,
                              self.server.onion_domain,
                              self.server.i2p_domain,
                              getreq_start_time,
                              proxy_type,
                              self.server.debug,
                              curr_session,
                              self.server.federation_list,
                              self.server.send_threads,
                              self.server.post_log,
                              self.server.cached_webfingers,
                              self.server.person_cache,
                              self.server.project_version,
                              self.server.sites_unavailable,
                              self.server.system_language,
                              self.server.fitness,
                              self.server.signing_priv_key_pem,
                              self.server.followers_sync_cache,
                              self.server.session_onion,
                              self.server.session_i2p,
                              self.server.session,
                              self.server.mitm_servers)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'follow approve done',
                        self.server.debug)

    # deny a follow request from the web interface
    if authorized and '/followdeny=' in self.path and \
       self.path.startswith('/users/'):
        follow_deny_button(self, calling_domain, self.path,
                           cookie,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain,
                           self.server.domain_full,
                           self.server.port,
                           self.server.onion_domain,
                           self.server.i2p_domain,
                           getreq_start_time,
                           self.server.debug,
                           self.server.federation_list,
                           self.server.send_threads,
                           self.server.post_log,
                           self.server.cached_webfingers,
                           self.server.person_cache,
                           self.server.project_version,
                           self.server.signing_priv_key_pem,
                           self.server.followers_sync_cache,
                           self.server.sites_unavailable,
                           self.server.system_language,
                           self.server.fitness,
                           self.server.session,
                           self.server.session_onion,
                           self.server.session_i2p,
                           self.server.mitm_servers)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'follow deny done',
                        self.server.debug)

    # like from the web interface icon
    if authorized and html_getreq and '?like=' in self.path:
        like_button(self, calling_domain, self.path,
                    self.server.base_dir,
                    self.server.http_prefix,
                    self.server.domain,
                    self.server.domain_full,
                    self.server.onion_domain,
                    self.server.i2p_domain,
                    getreq_start_time,
                    proxy_type,
                    cookie,
                    self.server.debug,
                    curr_session,
                    self.server.signing_priv_key_pem,
                    self.server.recent_posts_cache,
                    self.server.max_recent_posts,
                    self.server.translate,
                    self.server.cached_webfingers,
                    self.server.person_cache,
                    self.server.port,
                    self.server.allow_deletion,
                    self.server.project_version,
                    self.server.yt_replace_domain,
                    self.server.twitter_replacement_domain,
                    self.server.show_published_date_only,
                    self.server.peertube_instances,
                    self.server.allow_local_network_access,
                    self.server.theme_name,
                    self.server.system_language,
                    self.server.max_like_count,
                    self.server.cw_lists,
                    self.server.lists_enabled,
                    self.server.dogwhistles,
                    self.server.buy_sites,
                    self.server.auto_cw_cache,
                    self.server.fitness,
                    self.server.account_timezone,
                    self.server.iconsCache,
                    self.server.bold_reading,
                    self.server.min_images_for_accounts,
                    self.server.session_onion,
                    self.server.session_i2p,
                    self.server.mitm_servers,
                    self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'like button done',
                        self.server.debug)

    # undo a like from the web interface icon
    if authorized and html_getreq and '?unlike=' in self.path:
        like_button_undo(self, calling_domain, self.path,
                         self.server.base_dir,
                         self.server.http_prefix,
                         self.server.domain,
                         self.server.domain_full,
                         self.server.onion_domain,
                         self.server.i2p_domain,
                         getreq_start_time,
                         proxy_type,
                         cookie, self.server.debug,
                         curr_session,
                         self.server.signing_priv_key_pem,
                         self.server.recent_posts_cache,
                         self.server.max_recent_posts,
                         self.server.translate,
                         self.server.cached_webfingers,
                         self.server.person_cache,
                         self.server.port,
                         self.server.allow_deletion,
                         self.server.project_version,
                         self.server.yt_replace_domain,
                         self.server.twitter_replacement_domain,
                         self.server.show_published_date_only,
                         self.server.peertube_instances,
                         self.server.allow_local_network_access,
                         self.server.theme_name,
                         self.server.system_language,
                         self.server.max_like_count,
                         self.server.cw_lists,
                         self.server.lists_enabled,
                         self.server.dogwhistles,
                         self.server.buy_sites,
                         self.server.auto_cw_cache,
                         self.server.fitness,
                         self.server.account_timezone,
                         self.server.bold_reading,
                         self.server.min_images_for_accounts,
                         self.server.iconsCache,
                         self.server.session_onion,
                         self.server.session_i2p,
                         self.server.mitm_servers,
                         self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'unlike button done',
                        self.server.debug)

    # emoji reaction from the web interface icon
    if authorized and html_getreq and \
       '?react=' in self.path and \
       '?actor=' in self.path:
        reaction_button(self, calling_domain, self.path,
                        self.server.base_dir,
                        self.server.http_prefix,
                        self.server.domain,
                        self.server.domain_full,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        getreq_start_time,
                        proxy_type,
                        cookie,
                        self.server.debug,
                        curr_session,
                        self.server.signing_priv_key_pem,
                        self.server.recent_posts_cache,
                        self.server.max_recent_posts,
                        self.server.translate,
                        self.server.cached_webfingers,
                        self.server.person_cache,
                        self.server.port,
                        self.server.allow_deletion,
                        self.server.project_version,
                        self.server.yt_replace_domain,
                        self.server.twitter_replacement_domain,
                        self.server.show_published_date_only,
                        self.server.peertube_instances,
                        self.server.allow_local_network_access,
                        self.server.theme_name,
                        self.server.system_language,
                        self.server.max_like_count,
                        self.server.cw_lists,
                        self.server.lists_enabled,
                        self.server.dogwhistles,
                        self.server.buy_sites,
                        self.server.auto_cw_cache,
                        self.server.fitness,
                        self.server.account_timezone,
                        self.server.bold_reading,
                        self.server.min_images_for_accounts,
                        self.server.session_onion,
                        self.server.session_i2p,
                        self.server.mitm_servers,
                        self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'emoji reaction button done',
                        self.server.debug)

    # undo an emoji reaction from the web interface icon
    if authorized and html_getreq and \
       '?unreact=' in self.path and \
       '?actor=' in self.path:
        reaction_button_undo(self, calling_domain, self.path,
                             self.server.base_dir,
                             self.server.http_prefix,
                             self.server.domain,
                             self.server.domain_full,
                             self.server.onion_domain,
                             self.server.i2p_domain,
                             getreq_start_time,
                             proxy_type,
                             cookie, self.server.debug,
                             curr_session,
                             self.server.signing_priv_key_pem,
                             self.server.recent_posts_cache,
                             self.server.max_recent_posts,
                             self.server.translate,
                             self.server.cached_webfingers,
                             self.server.person_cache,
                             self.server.port,
                             self.server.allow_deletion,
                             self.server.project_version,
                             self.server.yt_replace_domain,
                             self.server.twitter_replacement_domain,
                             self.server.show_published_date_only,
                             self.server.peertube_instances,
                             self.server.allow_local_network_access,
                             self.server.theme_name,
                             self.server.system_language,
                             self.server.max_like_count,
                             self.server.cw_lists,
                             self.server.lists_enabled,
                             self.server.dogwhistles,
                             self.server.buy_sites,
                             self.server.auto_cw_cache,
                             self.server.fitness,
                             self.server.account_timezone,
                             self.server.bold_reading,
                             self.server.min_images_for_accounts,
                             self.server.session_onion,
                             self.server.session_i2p,
                             self.server.mitm_servers,
                             self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'unreaction button done',
                        self.server.debug)

    # bookmark from the web interface icon
    if authorized and html_getreq and '?bookmark=' in self.path:
        bookmark_button(self, calling_domain, self.path,
                        self.server.base_dir,
                        self.server.http_prefix,
                        self.server.domain,
                        self.server.domain_full,
                        self.server.port,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        getreq_start_time,
                        proxy_type,
                        cookie, self.server.debug,
                        curr_session,
                        self.server.signing_priv_key_pem,
                        self.server.recent_posts_cache,
                        self.server.max_recent_posts,
                        self.server.translate,
                        self.server.cached_webfingers,
                        self.server.person_cache,
                        self.server.allow_deletion,
                        self.server.project_version,
                        self.server.yt_replace_domain,
                        self.server.twitter_replacement_domain,
                        self.server.show_published_date_only,
                        self.server.peertube_instances,
                        self.server.allow_local_network_access,
                        self.server.theme_name,
                        self.server.system_language,
                        self.server.max_like_count,
                        self.server.cw_lists,
                        self.server.lists_enabled,
                        self.server.dogwhistles,
                        self.server.buy_sites,
                        self.server.auto_cw_cache,
                        self.server.fitness,
                        self.server.federation_list,
                        self.server.iconsCache,
                        self.server.account_timezone,
                        self.server.bold_reading,
                        self.server.min_images_for_accounts,
                        self.server.session_onion,
                        self.server.session_i2p,
                        self.server.mitm_servers,
                        self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'bookmark shown done',
                        self.server.debug)

    # emoji recation from the web interface bottom icon
    if authorized and html_getreq and '?selreact=' in self.path:
        reaction_picker2(self, calling_domain, self.path,
                         self.server.base_dir,
                         self.server.http_prefix,
                         self.server.domain,
                         self.server.domain_full,
                         self.server.port,
                         getreq_start_time,
                         cookie, self.server.debug,
                         curr_session,
                         self.server.onion_domain,
                         self.server.i2p_domain,
                         self.server.recent_posts_cache,
                         self.server.max_recent_posts,
                         self.server.translate,
                         self.server.cached_webfingers,
                         self.server.person_cache,
                         self.server.project_version,
                         self.server.yt_replace_domain,
                         self.server.twitter_replacement_domain,
                         self.server.show_published_date_only,
                         self.server.peertube_instances,
                         self.server.allow_local_network_access,
                         self.server.theme_name,
                         self.server.system_language,
                         self.server.max_like_count,
                         self.server.signing_priv_key_pem,
                         self.server.cw_lists,
                         self.server.lists_enabled,
                         self.server.dogwhistles,
                         self.server.min_images_for_accounts,
                         self.server.buy_sites,
                         self.server.auto_cw_cache,
                         self.server.account_timezone,
                         self.server.bold_reading,
                         self.server.fitness,
                         self.server.mitm_servers,
                         self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'bookmark shown done',
                        self.server.debug)

    # undo a bookmark from the web interface icon
    if authorized and html_getreq and '?unbookmark=' in self.path:
        bookmark_button_undo(self, calling_domain, self.path,
                             self.server.base_dir,
                             self.server.http_prefix,
                             self.server.domain,
                             self.server.domain_full,
                             self.server.port,
                             self.server.onion_domain,
                             self.server.i2p_domain,
                             getreq_start_time,
                             proxy_type, cookie,
                             self.server.debug,
                             curr_session,
                             self.server.signing_priv_key_pem,
                             self.server.recent_posts_cache,
                             self.server.max_recent_posts,
                             self.server.translate,
                             self.server.cached_webfingers,
                             self.server.person_cache,
                             self.server.allow_deletion,
                             self.server.project_version,
                             self.server.yt_replace_domain,
                             self.server.twitter_replacement_domain,
                             self.server.show_published_date_only,
                             self.server.peertube_instances,
                             self.server.allow_local_network_access,
                             self.server.theme_name,
                             self.server.system_language,
                             self.server.max_like_count,
                             self.server.cw_lists,
                             self.server.lists_enabled,
                             self.server.dogwhistles,
                             self.server.buy_sites,
                             self.server.auto_cw_cache,
                             self.server.fitness,
                             self.server.federation_list,
                             self.server.iconsCache,
                             self.server.account_timezone,
                             self.server.bold_reading,
                             self.server.min_images_for_accounts,
                             self.server.session_onion,
                             self.server.session_i2p,
                             self.server.mitm_servers,
                             self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'unbookmark shown done',
                        self.server.debug)

    # delete button is pressed on a post
    if authorized and html_getreq and '?delete=' in self.path:
        delete_button(self, calling_domain, self.path,
                      self.server.base_dir,
                      self.server.http_prefix,
                      self.server.domain_full,
                      self.server.onion_domain,
                      self.server.i2p_domain,
                      getreq_start_time,
                      proxy_type, cookie,
                      self.server.debug,
                      curr_session,
                      self.server.recent_posts_cache,
                      self.server.max_recent_posts,
                      self.server.translate,
                      self.server.project_version,
                      self.server.cached_webfingers,
                      self.server.person_cache,
                      self.server.yt_replace_domain,
                      self.server.twitter_replacement_domain,
                      self.server.show_published_date_only,
                      self.server.peertube_instances,
                      self.server.allow_local_network_access,
                      self.server.theme_name,
                      self.server.system_language,
                      self.server.max_like_count,
                      self.server.signing_priv_key_pem,
                      self.server.cw_lists,
                      self.server.lists_enabled,
                      self.server.dogwhistles,
                      self.server.min_images_for_accounts,
                      self.server.buy_sites,
                      self.server.auto_cw_cache,
                      self.server.fitness,
                      self.server.allow_deletion,
                      self.server.session_onion,
                      self.server.session_i2p,
                      self.server.default_timeline,
                      self.server.mitm_servers,
                      self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'delete shown done',
                        self.server.debug)

    # The mute button is pressed
    if authorized and html_getreq and '?mute=' in self.path:
        mute_button(self, calling_domain, self.path,
                    self.server.base_dir,
                    self.server.http_prefix,
                    self.server.domain,
                    self.server.domain_full,
                    self.server.port,
                    self.server.onion_domain,
                    self.server.i2p_domain,
                    getreq_start_time,
                    cookie, self.server.debug,
                    curr_session,
                    self.server.signing_priv_key_pem,
                    self.server.recent_posts_cache,
                    self.server.max_recent_posts,
                    self.server.translate,
                    self.server.cached_webfingers,
                    self.server.person_cache,
                    self.server.allow_deletion,
                    self.server.project_version,
                    self.server.yt_replace_domain,
                    self.server.twitter_replacement_domain,
                    self.server.show_published_date_only,
                    self.server.peertube_instances,
                    self.server.allow_local_network_access,
                    self.server.theme_name,
                    self.server.system_language,
                    self.server.max_like_count,
                    self.server.cw_lists,
                    self.server.lists_enabled,
                    self.server.dogwhistles,
                    self.server.buy_sites,
                    self.server.auto_cw_cache,
                    self.server.fitness,
                    self.server.account_timezone,
                    self.server.bold_reading,
                    self.server.min_images_for_accounts,
                    self.server.default_timeline,
                    self.server.mitm_servers,
                    self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'post muted done',
                        self.server.debug)

    # unmute a post from the web interface icon
    if authorized and html_getreq and '?unmute=' in self.path:
        mute_button_undo(self, calling_domain, self.path,
                         self.server.base_dir,
                         self.server.http_prefix,
                         self.server.domain,
                         self.server.domain_full,
                         self.server.port,
                         self.server.onion_domain,
                         self.server.i2p_domain,
                         getreq_start_time,
                         cookie, self.server.debug,
                         curr_session,
                         self.server.signing_priv_key_pem,
                         self.server.recent_posts_cache,
                         self.server.max_recent_posts,
                         self.server.translate,
                         self.server.cached_webfingers,
                         self.server.person_cache,
                         self.server.allow_deletion,
                         self.server.project_version,
                         self.server.yt_replace_domain,
                         self.server.twitter_replacement_domain,
                         self.server.show_published_date_only,
                         self.server.peertube_instances,
                         self.server.allow_local_network_access,
                         self.server.theme_name,
                         self.server.system_language,
                         self.server.max_like_count,
                         self.server.cw_lists,
                         self.server.lists_enabled,
                         self.server.dogwhistles,
                         self.server.buy_sites,
                         self.server.auto_cw_cache,
                         self.server.fitness,
                         self.server.account_timezone,
                         self.server.bold_reading,
                         self.server.min_images_for_accounts,
                         self.server.default_timeline,
                         self.server.mitm_servers,
                         self.server.instance_software)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'unmute activated done',
                        self.server.debug)

    # reply from the web interface icon
    in_reply_to_url = None
    reply_to_list: list[str] = []
    reply_page_number = 1
    reply_category = ''
    share_description = None
    conversation_id = None
    convthread_id = None
    if html_getreq:
        if '?conversationId=' in self.path:
            conversation_id = self.path.split('?conversationId=')[1]
            if '?' in conversation_id:
                conversation_id = conversation_id.split('?')[0]
        if '?convthreadId=' in self.path:
            convthread_id = self.path.split('?convthreadId=')[1]
            if '?' in convthread_id:
                convthread_id = convthread_id.split('?')[0]
        # public reply
        if '?replyto=' in self.path:
            in_reply_to_url = self.path.split('?replyto=')[1]
            if '?' in in_reply_to_url:
                mentions_list = in_reply_to_url.split('?')
                for ment in mentions_list:
                    if ment.startswith('mention='):
                        reply_handle = ment.replace('mention=', '')
                        if reply_handle not in reply_to_list:
                            reply_to_list.append(reply_handle)
                    if ment.startswith('page='):
                        reply_page_str = ment.replace('page=', '')
                        if len(reply_page_str) > 5:
                            reply_page_str = "1"
                        if reply_page_str.isdigit():
                            reply_page_number = int(reply_page_str)
                in_reply_to_url = mentions_list[0]
            if not self.server.public_replies_unlisted:
                self.path = self.path.split('?replyto=')[0] + '/newpost'
            else:
                self.path = \
                    self.path.split('?replyto=')[0] + '/newunlisted'
            if self.server.debug:
                print('DEBUG: replyto path ' + self.path)

        # unlisted reply
        if '?replyunlisted=' in self.path:
            in_reply_to_url = self.path.split('?replyunlisted=')[1]
            if '?' in in_reply_to_url:
                mentions_list = in_reply_to_url.split('?')
                for ment in mentions_list:
                    if ment.startswith('mention='):
                        reply_handle = ment.replace('mention=', '')
                        if reply_handle not in reply_to_list:
                            reply_to_list.append(reply_handle)
                    if ment.startswith('page='):
                        reply_page_str = ment.replace('page=', '')
                        if len(reply_page_str) > 5:
                            reply_page_str = "1"
                        if reply_page_str.isdigit():
                            reply_page_number = int(reply_page_str)
                in_reply_to_url = mentions_list[0]
            self.path = \
                self.path.split('?replyunlisted=')[0] + '/newunlisted'
            if self.server.debug:
                print('DEBUG: replyunlisted path ' + self.path)

        # reply to followers
        if '?replyfollowers=' in self.path:
            in_reply_to_url = self.path.split('?replyfollowers=')[1]
            if '?' in in_reply_to_url:
                mentions_list = in_reply_to_url.split('?')
                for ment in mentions_list:
                    if ment.startswith('mention='):
                        reply_handle = ment.replace('mention=', '')
                        ment2 = ment.replace('mention=', '')
                        if ment2 not in reply_to_list:
                            reply_to_list.append(reply_handle)
                    if ment.startswith('page='):
                        reply_page_str = ment.replace('page=', '')
                        if len(reply_page_str) > 5:
                            reply_page_str = "1"
                        if reply_page_str.isdigit():
                            reply_page_number = int(reply_page_str)
                in_reply_to_url = mentions_list[0]
            self.path = self.path.split('?replyfollowers=')[0] + \
                '/newfollowers'
            if self.server.debug:
                print('DEBUG: replyfollowers path ' + self.path)

        # replying as a direct message,
        # for moderation posts or the dm timeline
        reply_is_chat = False
        if '?replydm=' in self.path or '?replychat=' in self.path:
            reply_type = 'replydm'
            if '?replychat=' in self.path:
                reply_type = 'replychat'
                reply_is_chat = True
            in_reply_to_url = self.path.split('?' + reply_type + '=')[1]
            in_reply_to_url = urllib.parse.unquote_plus(in_reply_to_url)
            if '?' in in_reply_to_url:
                # multiple parameters
                mentions_list = in_reply_to_url.split('?')
                for ment in mentions_list:
                    if ment.startswith('mention='):
                        reply_handle = ment.replace('mention=', '')
                        in_reply_to_url = reply_handle
                        if reply_handle not in reply_to_list:
                            reply_to_list.append(reply_handle)
                    elif ment.startswith('page='):
                        reply_page_str = ment.replace('page=', '')
                        if len(reply_page_str) > 5:
                            reply_page_str = "1"
                        if reply_page_str.isdigit():
                            reply_page_number = int(reply_page_str)
                    elif ment.startswith('category='):
                        reply_category = ment.replace('category=', '')
                    elif ment.startswith('sharedesc:'):
                        # get the title for the shared item
                        share_description = \
                            ment.replace('sharedesc:', '').strip()
                        share_description = \
                            share_description.replace('_', ' ')
                in_reply_to_url = mentions_list[0]
            else:
                # single parameter
                if in_reply_to_url.startswith('mention='):
                    reply_handle = in_reply_to_url.replace('mention=', '')
                    in_reply_to_url = reply_handle
                    if reply_handle not in reply_to_list:
                        reply_to_list.append(reply_handle)
                elif in_reply_to_url.startswith('sharedesc:'):
                    # get the title for the shared item
                    share_description = \
                        in_reply_to_url.replace('sharedesc:', '').strip()
                    share_description = \
                        share_description.replace('_', ' ')

            self.path = \
                self.path.split('?' + reply_type + '=')[0] + '/newdm'
            if self.server.debug:
                print('DEBUG: ' + reply_type + ' path ' + self.path)

        # Edit a blog post
        if authorized and \
           '/users/' in self.path and \
           '?editblogpost=' in self.path and \
           ';actor=' in self.path:
            message_id = self.path.split('?editblogpost=')[1]
            if ';' in message_id:
                message_id = message_id.split(';')[0]
            actor = self.path.split(';actor=')[1]
            if ';' in actor:
                actor = actor.split(';')[0]
            nickname = get_nickname_from_actor(self.path.split('?')[0])
            if not nickname:
                http_404(self, 139)
                self.server.getreq_busy = False
                return
            if nickname == actor:
                post_url = \
                    local_actor_url(self.server.http_prefix, nickname,
                                    self.server.domain_full) + \
                    '/statuses/' + message_id
                msg = html_edit_blog(self.server.media_instance,
                                     self.server.translate,
                                     self.server.base_dir,
                                     self.path, reply_page_number,
                                     nickname, self.server.domain,
                                     post_url,
                                     self.server.system_language)
                if msg:
                    msg = msg.encode('utf-8')
                    msglen = len(msg)
                    set_headers(self, 'text/html', msglen,
                                cookie, calling_domain, False)
                    write2(self, msg)
                    self.server.getreq_busy = False
                    return

        # Edit a post
        edit_post_params = {}
        if authorized and \
           '/users/' in self.path and \
           '?postedit=' in self.path and \
           ';scope=' in self.path and \
           ';actor=' in self.path:
            post_scope = self.path.split(';scope=')[1]
            if ';' in post_scope:
                post_scope = post_scope.split(';')[0]
            edit_post_params['scope'] = post_scope
            message_id = self.path.split('?postedit=')[1]
            if ';' in message_id:
                message_id = message_id.split(';')[0]
            if ';replyTo=' in self.path:
                reply_to = self.path.split(';replyTo=')[1]
                if ';' in reply_to:
                    reply_to = message_id.split(';')[0]
                edit_post_params['replyTo'] = reply_to
            actor = self.path.split(';actor=')[1]
            if ';' in actor:
                actor = actor.split(';')[0]
            edit_post_params['actor'] = actor
            nickname = get_nickname_from_actor(self.path.split('?')[0])
            edit_post_params['nickname'] = nickname
            if not nickname:
                http_404(self, 140)
                self.server.getreq_busy = False
                return
            if nickname != actor:
                http_404(self, 141)
                self.server.getreq_busy = False
                return
            post_url = \
                local_actor_url(self.server.http_prefix, nickname,
                                self.server.domain_full) + \
                '/statuses/' + message_id
            edit_post_params['post_url'] = post_url
            # use the new post functions, but using edit_post_params
            new_post_scope = post_scope
            if post_scope == 'public':
                new_post_scope = 'post'
            self.path = '/users/' + nickname + '/new' + new_post_scope

        # list of known crawlers accessing nodeinfo or masto API
        if _show_known_crawlers(self, calling_domain, self.path,
                                self.server.base_dir,
                                self.server.known_crawlers):
            self.server.getreq_busy = False
            return

        # edit profile in web interface
        if edit_profile2(self, calling_domain, self.path,
                         self.server.translate,
                         self.server.base_dir,
                         self.server.domain,
                         self.server.port,
                         cookie,
                         self.server.peertube_instances,
                         self.server.access_keys,
                         self.server.key_shortcuts,
                         self.server.default_reply_interval_hrs,
                         self.server.default_timeline,
                         self.server.theme_name,
                         self.server.text_mode_banner,
                         self.server.user_agents_blocked,
                         self.server.crawlers_allowed,
                         self.server.cw_lists,
                         self.server.lists_enabled,
                         self.server.system_language,
                         self.server.min_images_for_accounts,
                         self.server.max_recent_posts,
                         self.server.reverse_sequence,
                         self.server.buy_sites,
                         self.server.block_military,
                         self.server.block_government,
                         self.server.block_bluesky,
                         self.server.block_nostr,
                         self.server.block_federated_endpoints):
            self.server.getreq_busy = False
            return

        # edit links from the left column of the timeline in web interface
        if edit_links2(self, calling_domain, self.path,
                       self.server.translate,
                       self.server.base_dir,
                       self.server.domain,
                       cookie,
                       self.server.theme_name,
                       self.server.access_keys,
                       self.server.key_shortcuts,
                       self.server.default_timeline):
            self.server.getreq_busy = False
            return

        # edit newswire from the right column of the timeline
        if edit_newswire2(self, calling_domain, self.path,
                          self.server.translate,
                          self.server.base_dir,
                          self.server.domain, cookie,
                          self.server.access_keys,
                          self.server.key_shortcuts,
                          self.server.default_timeline,
                          self.server.theme_name,
                          self.server.dogwhistles):
            self.server.getreq_busy = False
            return

        # edit news post
        if edit_news_post2(self, calling_domain, self.path,
                           self.server.translate,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain,
                           self.server.domain_full,
                           cookie, self.server.system_language):
            self.server.getreq_busy = False
            return

        if show_new_post(self, edit_post_params,
                         calling_domain, self.path,
                         self.server.media_instance,
                         self.server.translate,
                         self.server.base_dir,
                         self.server.http_prefix,
                         in_reply_to_url, reply_to_list,
                         reply_is_chat,
                         share_description, reply_page_number,
                         reply_category,
                         self.server.domain,
                         self.server.domain_full,
                         getreq_start_time,
                         cookie, no_drop_down, conversation_id,
                         convthread_id,
                         curr_session,
                         self.server.default_reply_interval_hrs,
                         self.server.debug,
                         self.server.access_keys,
                         self.server.key_shortcuts,
                         self.server.system_language,
                         self.server.default_post_language,
                         self.server.bold_reading,
                         self.server.person_cache,
                         self.server.fitness,
                         self.server.default_timeline,
                         self.server.newswire,
                         self.server.theme_name,
                         self.server.recent_posts_cache,
                         self.server.max_recent_posts,
                         self.server.cached_webfingers,
                         self.server.port,
                         self.server.project_version,
                         self.server.yt_replace_domain,
                         self.server.twitter_replacement_domain,
                         self.server.show_published_date_only,
                         self.server.peertube_instances,
                         self.server.allow_local_network_access,
                         self.server.max_like_count,
                         self.server.signing_priv_key_pem,
                         self.server.cw_lists,
                         self.server.lists_enabled,
                         self.server.dogwhistles,
                         self.server.min_images_for_accounts,
                         self.server.buy_sites,
                         self.server.auto_cw_cache,
                         self.server.searchable_by_default,
                         self.server.mitm_servers,
                         self.server.instance_software):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'new post done',
                        self.server.debug)

    # get an individual post from the path /@nickname/statusnumber
    if show_individual_at_post(self, ssml_getreq, authorized,
                               calling_domain, referer_domain,
                               self.path,
                               self.server.base_dir,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.domain_full,
                               self.server.port,
                               getreq_start_time,
                               proxy_type,
                               cookie, self.server.debug,
                               curr_session,
                               self.server.translate,
                               self.server.account_timezone,
                               self.server.fitness,
                               self.server.recent_posts_cache,
                               self.server.max_recent_posts,
                               self.server.cached_webfingers,
                               self.server.person_cache,
                               self.server.project_version,
                               self.server.yt_replace_domain,
                               self.server.twitter_replacement_domain,
                               self.server.show_published_date_only,
                               self.server.peertube_instances,
                               self.server.allow_local_network_access,
                               self.server.theme_name,
                               self.server.system_language,
                               self.server.max_like_count,
                               self.server.signing_priv_key_pem,
                               self.server.cw_lists,
                               self.server.lists_enabled,
                               self.server.dogwhistles,
                               self.server.min_images_for_accounts,
                               self.server.buy_sites,
                               self.server.auto_cw_cache,
                               self.server.onion_domain,
                               self.server.i2p_domain,
                               self.server.bold_reading,
                               self.server.mitm_servers,
                               self.server.instance_software):
        self.server.getreq_busy = False
        return

    # show the likers of a post
    if show_likers_of_post(self, authorized,
                           calling_domain, self.path,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain,
                           self.server.port,
                           getreq_start_time,
                           cookie, self.server.debug,
                           curr_session,
                           self.server.bold_reading,
                           self.server.translate,
                           self.server.theme_name,
                           self.server.access_keys,
                           self.server.recent_posts_cache,
                           self.server.max_recent_posts,
                           self.server.cached_webfingers,
                           self.server.person_cache,
                           self.server.project_version,
                           self.server.yt_replace_domain,
                           self.server.twitter_replacement_domain,
                           self.server.show_published_date_only,
                           self.server.peertube_instances,
                           self.server.allow_local_network_access,
                           self.server.system_language,
                           self.server.max_like_count,
                           self.server.signing_priv_key_pem,
                           self.server.cw_lists,
                           self.server.lists_enabled,
                           self.server.default_timeline,
                           self.server.dogwhistles,
                           self.server.min_images_for_accounts,
                           self.server.buy_sites,
                           self.server.auto_cw_cache,
                           self.server.fitness,
                           self.server.mitm_servers,
                           self.server.instance_software):
        self.server.getreq_busy = False
        return

    # show the announcers/repeaters of a post
    if show_announcers_of_post(self, authorized,
                               calling_domain, self.path,
                               self.server.base_dir,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.port,
                               getreq_start_time,
                               cookie, self.server.debug,
                               curr_session,
                               self.server.bold_reading,
                               self.server.translate,
                               self.server.theme_name,
                               self.server.access_keys,
                               self.server.recent_posts_cache,
                               self.server.max_recent_posts,
                               self.server.cached_webfingers,
                               self.server.person_cache,
                               self.server.project_version,
                               self.server.yt_replace_domain,
                               self.server.twitter_replacement_domain,
                               self.server.show_published_date_only,
                               self.server.peertube_instances,
                               self.server.allow_local_network_access,
                               self.server.system_language,
                               self.server.max_like_count,
                               self.server.signing_priv_key_pem,
                               self.server.cw_lists,
                               self.server.lists_enabled,
                               self.server.default_timeline,
                               self.server.dogwhistles,
                               self.server.min_images_for_accounts,
                               self.server.buy_sites,
                               self.server.auto_cw_cache,
                               self.server.fitness,
                               self.server.mitm_servers,
                               self.server.instance_software):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'individual post done',
                        self.server.debug)

    # get replies to a post /users/nickname/statuses/number/replies
    if self.path.endswith('/replies') or \
       ('/replies?' in self.path and 'page=' in self.path):
        if show_replies_to_post(self, authorized,
                                calling_domain, referer_domain,
                                self.path,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.domain_full,
                                self.server.port,
                                getreq_start_time,
                                proxy_type, cookie,
                                self.server.debug,
                                curr_session,
                                self.server.recent_posts_cache,
                                self.server.max_recent_posts,
                                self.server.translate,
                                self.server.cached_webfingers,
                                self.server.person_cache,
                                self.server.project_version,
                                self.server.yt_replace_domain,
                                self.server.twitter_replacement_domain,
                                self.server.peertube_instances,
                                self.server.account_timezone,
                                self.server.bold_reading,
                                self.server.show_published_date_only,
                                self.server.allow_local_network_access,
                                self.server.theme_name,
                                self.server.system_language,
                                self.server.max_like_count,
                                self.server.signing_priv_key_pem,
                                self.server.cw_lists,
                                self.server.lists_enabled,
                                self.server.dogwhistles,
                                self.server.min_images_for_accounts,
                                self.server.buy_sites,
                                self.server.auto_cw_cache,
                                self.server.fitness,
                                self.server.onion_domain,
                                self.server.i2p_domain,
                                self.server.mitm_servers,
                                self.server.instance_software):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'post replies done',
                        self.server.debug)

    # roles on profile screen
    if self.path.endswith('/roles') and users_in_path:
        if show_roles(self, calling_domain, referer_domain,
                      self.path,
                      self.server.base_dir,
                      self.server.http_prefix,
                      self.server.domain,
                      getreq_start_time,
                      proxy_type,
                      cookie, self.server.debug,
                      curr_session,
                      self.server.default_timeline,
                      self.server.recent_posts_cache,
                      self.server.cached_webfingers,
                      self.server.yt_replace_domain,
                      self.server.twitter_replacement_domain,
                      self.server.icons_as_buttons,
                      self.server.access_keys,
                      self.server.key_shortcuts,
                      self.server.city,
                      self.server.signing_priv_key_pem,
                      self.server.rss_icon_at_top,
                      self.server.shared_items_federated_domains,
                      self.server.account_timezone,
                      self.server.bold_reading,
                      self.server.max_recent_posts,
                      self.server.translate,
                      self.server.project_version,
                      self.server.person_cache,
                      self.server.show_published_date_only,
                      self.server.newswire,
                      self.server.theme_name,
                      self.server.dormant_months,
                      self.server.peertube_instances,
                      self.server.allow_local_network_access,
                      self.server.text_mode_banner,
                      self.server.system_language,
                      self.server.max_like_count,
                      self.server.cw_lists,
                      self.server.lists_enabled,
                      self.server.content_license_url,
                      self.server.buy_sites,
                      self.server.max_shares_on_profile,
                      self.server.sites_unavailable,
                      self.server.no_of_books,
                      self.server.auto_cw_cache,
                      self.server.fitness,
                      self.server.onion_domain,
                      self.server.i2p_domain,
                      self.server.mitm_servers,
                      self.server.hide_recent_posts):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show roles done',
                        self.server.debug)

    # show skills on the profile page
    if self.path.endswith('/skills') and users_in_path:
        if show_skills(self, calling_domain, referer_domain,
                       self.path,
                       self.server.base_dir,
                       self.server.http_prefix,
                       self.server.domain,
                       getreq_start_time,
                       proxy_type,
                       cookie, self.server.debug,
                       curr_session,
                       self.server.default_timeline,
                       self.server.recent_posts_cache,
                       self.server.cached_webfingers,
                       self.server.yt_replace_domain,
                       self.server.twitter_replacement_domain,
                       self.server.show_published_date_only,
                       self.server.icons_as_buttons,
                       self.server.allow_local_network_access,
                       self.server.access_keys,
                       self.server.key_shortcuts,
                       self.server.shared_items_federated_domains,
                       self.server.signing_priv_key_pem,
                       self.server.content_license_url,
                       self.server.peertube_instances,
                       self.server.city,
                       self.server.account_timezone,
                       self.server.bold_reading,
                       self.server.max_shares_on_profile,
                       self.server.rss_icon_at_top,
                       self.server.max_recent_posts,
                       self.server.translate,
                       self.server.project_version,
                       self.server.person_cache,
                       self.server.newswire,
                       self.server.theme_name,
                       self.server.dormant_months,
                       self.server.text_mode_banner,
                       self.server.system_language,
                       self.server.max_like_count,
                       self.server.cw_lists,
                       self.server.lists_enabled,
                       self.server.buy_sites,
                       self.server.sites_unavailable,
                       self.server.no_of_books,
                       self.server.auto_cw_cache,
                       self.server.fitness,
                       self.server.domain_full,
                       self.server.onion_domain,
                       self.server.i2p_domain,
                       self.server.mitm_servers,
                       self.server.hide_recent_posts):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show skills done',
                        self.server.debug)

    if '?notifypost=' in self.path and users_in_path and authorized:
        if show_notify_post(self, authorized,
                            calling_domain, referer_domain,
                            self.path,
                            self.server.base_dir,
                            self.server.http_prefix,
                            self.server.domain,
                            self.server.port,
                            getreq_start_time,
                            proxy_type,
                            cookie, self.server.debug,
                            curr_session,
                            self.server.translate,
                            self.server.account_timezone,
                            self.server.fitness,
                            self.server.recent_posts_cache,
                            self.server.max_recent_posts,
                            self.server.cached_webfingers,
                            self.server.person_cache,
                            self.server.project_version,
                            self.server.yt_replace_domain,
                            self.server.twitter_replacement_domain,
                            self.server.show_published_date_only,
                            self.server.peertube_instances,
                            self.server.allow_local_network_access,
                            self.server.theme_name,
                            self.server.system_language,
                            self.server.max_like_count,
                            self.server.signing_priv_key_pem,
                            self.server.cw_lists,
                            self.server.lists_enabled,
                            self.server.dogwhistles,
                            self.server.min_images_for_accounts,
                            self.server.buy_sites,
                            self.server.auto_cw_cache,
                            self.server.onion_domain,
                            self.server.i2p_domain,
                            self.server.bold_reading,
                            self.server.mitm_servers,
                            self.server.instance_software):
            self.server.getreq_busy = False
            return

    # get an individual post from the path
    # /users/nickname/statuses/number
    if '/statuses/' in self.path and users_in_path:
        if show_individual_post(self, ssml_getreq, authorized,
                                calling_domain, referer_domain,
                                self.path,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.domain_full,
                                self.server.port,
                                getreq_start_time,
                                proxy_type,
                                cookie, self.server.debug,
                                curr_session,
                                self.server.translate,
                                self.server.account_timezone,
                                self.server.fitness,
                                self.server.bold_reading,
                                self.server.recent_posts_cache,
                                self.server.max_recent_posts,
                                self.server.cached_webfingers,
                                self.server.person_cache,
                                self.server.project_version,
                                self.server.yt_replace_domain,
                                self.server.twitter_replacement_domain,
                                self.server.show_published_date_only,
                                self.server.peertube_instances,
                                self.server.allow_local_network_access,
                                self.server.theme_name,
                                self.server.system_language,
                                self.server.max_like_count,
                                self.server.signing_priv_key_pem,
                                self.server.cw_lists,
                                self.server.lists_enabled,
                                self.server.dogwhistles,
                                self.server.min_images_for_accounts,
                                self.server.buy_sites,
                                self.server.auto_cw_cache,
                                self.server.onion_domain,
                                self.server.i2p_domain,
                                self.server.mitm_servers,
                                self.server.instance_software):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show status done',
                        self.server.debug)

    # get the inbox timeline for a given person
    if self.path.endswith('/inbox') or '/inbox?page=' in self.path:
        if show_inbox(self, authorized,
                      calling_domain, referer_domain,
                      self.path,
                      self.server.base_dir,
                      self.server.http_prefix,
                      self.server.domain,
                      self.server.port,
                      getreq_start_time,
                      cookie, self.server.debug,
                      self.server.recent_posts_cache,
                      curr_session,
                      self.server.default_timeline,
                      self.server.max_recent_posts,
                      self.server.translate,
                      self.server.cached_webfingers,
                      self.server.person_cache,
                      self.server.allow_deletion,
                      self.server.project_version,
                      self.server.yt_replace_domain,
                      self.server.twitter_replacement_domain,
                      ua_str, MAX_POSTS_IN_FEED,
                      self.server.positive_voting,
                      self.server.voting_time_mins,
                      self.server.fitness,
                      self.server.full_width_tl_button_header,
                      self.server.access_keys,
                      self.server.key_shortcuts,
                      self.server.shared_items_federated_domains,
                      self.server.allow_local_network_access,
                      self.server.account_timezone,
                      self.server.bold_reading,
                      self.server.reverse_sequence,
                      self.server.show_published_date_only,
                      self.server.newswire,
                      self.server.show_publish_as_icon,
                      self.server.icons_as_buttons,
                      self.server.rss_icon_at_top,
                      self.server.publish_button_at_top,
                      self.server.theme_name,
                      self.server.peertube_instances,
                      self.server.text_mode_banner,
                      self.server.system_language,
                      self.server.max_like_count,
                      self.server.signing_priv_key_pem,
                      self.server.cw_lists,
                      self.server.lists_enabled,
                      self.server.dogwhistles,
                      self.server.min_images_for_accounts,
                      self.server.buy_sites,
                      self.server.auto_cw_cache,
                      self.server.onion_domain,
                      self.server.i2p_domain,
                      self.server.hide_announces,
                      self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show inbox done',
                        self.server.debug)

    # get the direct messages timeline for a given person
    if self.path.endswith('/dm') or '/dm?page=' in self.path:
        if show_dms(self, authorized,
                    calling_domain, referer_domain,
                    self.path,
                    self.server.base_dir,
                    self.server.http_prefix,
                    self.server.domain,
                    self.server.port,
                    getreq_start_time,
                    cookie, self.server.debug,
                    curr_session, ua_str, MAX_POSTS_IN_FEED,
                    self.server.recent_posts_cache,
                    self.server.positive_voting,
                    self.server.voting_time_mins,
                    self.server.full_width_tl_button_header,
                    self.server.access_keys,
                    self.server.key_shortcuts,
                    self.server.shared_items_federated_domains,
                    self.server.allow_local_network_access,
                    self.server.twitter_replacement_domain,
                    self.server.show_published_date_only,
                    self.server.account_timezone,
                    self.server.bold_reading,
                    self.server.reverse_sequence,
                    self.server.default_timeline,
                    self.server.max_recent_posts,
                    self.server.translate,
                    self.server.cached_webfingers,
                    self.server.person_cache,
                    self.server.allow_deletion,
                    self.server.project_version,
                    self.server.yt_replace_domain,
                    self.server.newswire,
                    self.server.show_publish_as_icon,
                    self.server.icons_as_buttons,
                    self.server.rss_icon_at_top,
                    self.server.publish_button_at_top,
                    self.server.theme_name,
                    self.server.peertube_instances,
                    self.server.text_mode_banner,
                    self.server.system_language,
                    self.server.max_like_count,
                    self.server.signing_priv_key_pem,
                    self.server.cw_lists,
                    self.server.lists_enabled,
                    self.server.dogwhistles,
                    self.server.min_images_for_accounts,
                    self.server.buy_sites,
                    self.server.auto_cw_cache,
                    self.server.fitness,
                    self.server.onion_domain,
                    self.server.i2p_domain,
                    self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show dms done',
                        self.server.debug)

    # get the replies timeline for a given person
    if self.path.endswith('/tlreplies') or '/tlreplies?page=' in self.path:
        if show_replies(self, authorized,
                        calling_domain, referer_domain,
                        self.path,
                        self.server.base_dir,
                        self.server.http_prefix,
                        self.server.domain,
                        self.server.port,
                        getreq_start_time,
                        cookie, self.server.debug,
                        curr_session, ua_str, MAX_POSTS_IN_FEED,
                        self.server.recent_posts_cache,
                        self.server.positive_voting,
                        self.server.voting_time_mins,
                        self.server.full_width_tl_button_header,
                        self.server.access_keys,
                        self.server.key_shortcuts,
                        self.server.shared_items_federated_domains,
                        self.server.allow_local_network_access,
                        self.server.twitter_replacement_domain,
                        self.server.show_published_date_only,
                        self.server.account_timezone,
                        self.server.bold_reading,
                        self.server.reverse_sequence,
                        self.server.default_timeline,
                        self.server.max_recent_posts,
                        self.server.translate,
                        self.server.cached_webfingers,
                        self.server.person_cache,
                        self.server.allow_deletion,
                        self.server.project_version,
                        self.server.yt_replace_domain,
                        self.server.newswire,
                        self.server.show_publish_as_icon,
                        self.server.icons_as_buttons,
                        self.server.rss_icon_at_top,
                        self.server.publish_button_at_top,
                        self.server.theme_name,
                        self.server.peertube_instances,
                        self.server.text_mode_banner,
                        self.server.system_language,
                        self.server.max_like_count,
                        self.server.signing_priv_key_pem,
                        self.server.cw_lists,
                        self.server.lists_enabled,
                        self.server.dogwhistles,
                        self.server.min_images_for_accounts,
                        self.server.buy_sites,
                        self.server.auto_cw_cache,
                        self.server.fitness,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show replies 2 done',
                        self.server.debug)

    # get the media timeline for a given person
    if self.path.endswith('/tlmedia') or '/tlmedia?page=' in self.path:
        if show_media_timeline(self, authorized,
                               calling_domain, referer_domain,
                               self.path,
                               self.server.base_dir,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.port,
                               getreq_start_time,
                               cookie, self.server.debug,
                               curr_session, ua_str,
                               MAX_POSTS_IN_MEDIA_FEED,
                               self.server.recent_posts_cache,
                               self.server.positive_voting,
                               self.server.voting_time_mins,
                               self.server.access_keys,
                               self.server.key_shortcuts,
                               self.server.shared_items_federated_domains,
                               self.server.allow_local_network_access,
                               self.server.twitter_replacement_domain,
                               self.server.account_timezone,
                               self.server.bold_reading,
                               self.server.reverse_sequence,
                               self.server.default_timeline,
                               self.server.max_recent_posts,
                               self.server.translate,
                               self.server.cached_webfingers,
                               self.server.person_cache,
                               self.server.allow_deletion,
                               self.server.project_version,
                               self.server.yt_replace_domain,
                               self.server.show_published_date_only,
                               self.server.newswire,
                               self.server.show_publish_as_icon,
                               self.server.icons_as_buttons,
                               self.server.rss_icon_at_top,
                               self.server.publish_button_at_top,
                               self.server.theme_name,
                               self.server.peertube_instances,
                               self.server.text_mode_banner,
                               self.server.system_language,
                               self.server.max_like_count,
                               self.server.signing_priv_key_pem,
                               self.server.cw_lists,
                               self.server.lists_enabled,
                               self.server.dogwhistles,
                               self.server.min_images_for_accounts,
                               self.server.buy_sites,
                               self.server.auto_cw_cache,
                               self.server.fitness,
                               self.server.full_width_tl_button_header,
                               self.server.onion_domain,
                               self.server.i2p_domain,
                               self.server.hide_announces,
                               self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show media 2 done',
                        self.server.debug)

    # get the blogs for a given person
    if self.path.endswith('/tlblogs') or '/tlblogs?page=' in self.path:
        if show_blogs_timeline(self, authorized,
                               calling_domain, referer_domain,
                               self.path,
                               self.server.base_dir,
                               self.server.http_prefix,
                               self.server.domain,
                               self.server.port,
                               getreq_start_time,
                               cookie, self.server.debug,
                               curr_session, ua_str,
                               MAX_POSTS_IN_BLOGS_FEED,
                               self.server.recent_posts_cache,
                               self.server.positive_voting,
                               self.server.voting_time_mins,
                               self.server.full_width_tl_button_header,
                               self.server.access_keys,
                               self.server.key_shortcuts,
                               self.server.shared_items_federated_domains,
                               self.server.allow_local_network_access,
                               self.server.twitter_replacement_domain,
                               self.server.account_timezone,
                               self.server.bold_reading,
                               self.server.reverse_sequence,
                               self.server.default_timeline,
                               self.server.max_recent_posts,
                               self.server.translate,
                               self.server.cached_webfingers,
                               self.server.person_cache,
                               self.server.allow_deletion,
                               self.server.project_version,
                               self.server.yt_replace_domain,
                               self.server.show_published_date_only,
                               self.server.newswire,
                               self.server.show_publish_as_icon,
                               self.server.icons_as_buttons,
                               self.server.rss_icon_at_top,
                               self.server.publish_button_at_top,
                               self.server.theme_name,
                               self.server.peertube_instances,
                               self.server.text_mode_banner,
                               self.server.system_language,
                               self.server.max_like_count,
                               self.server.signing_priv_key_pem,
                               self.server.cw_lists,
                               self.server.lists_enabled,
                               self.server.dogwhistles,
                               self.server.min_images_for_accounts,
                               self.server.buy_sites,
                               self.server.auto_cw_cache,
                               self.server.fitness,
                               self.server.onion_domain,
                               self.server.i2p_domain,
                               self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show blogs 2 done',
                        self.server.debug)

    # get the news for a given person
    if self.path.endswith('/tlnews') or '/tlnews?page=' in self.path:
        if show_news_timeline(self, authorized,
                              calling_domain, referer_domain,
                              self.path,
                              self.server.base_dir,
                              self.server.http_prefix,
                              self.server.domain,
                              self.server.port,
                              getreq_start_time,
                              cookie, self.server.debug,
                              curr_session, ua_str,
                              MAX_POSTS_IN_NEWS_FEED,
                              self.server.recent_posts_cache,
                              self.server.newswire_votes_threshold,
                              self.server.positive_voting,
                              self.server.voting_time_mins,
                              self.server.full_width_tl_button_header,
                              self.server.access_keys,
                              self.server.key_shortcuts,
                              self.server.shared_items_federated_domains,
                              self.server.account_timezone,
                              self.server.bold_reading,
                              self.server.reverse_sequence,
                              self.server.default_timeline,
                              self.server.max_recent_posts,
                              self.server.translate,
                              self.server.cached_webfingers,
                              self.server.person_cache,
                              self.server.allow_deletion,
                              self.server.project_version,
                              self.server.yt_replace_domain,
                              self.server.twitter_replacement_domain,
                              self.server.show_published_date_only,
                              self.server.newswire,
                              self.server.show_publish_as_icon,
                              self.server.icons_as_buttons,
                              self.server.rss_icon_at_top,
                              self.server.publish_button_at_top,
                              self.server.theme_name,
                              self.server.peertube_instances,
                              self.server.allow_local_network_access,
                              self.server.text_mode_banner,
                              self.server.system_language,
                              self.server.max_like_count,
                              self.server.signing_priv_key_pem,
                              self.server.cw_lists,
                              self.server.lists_enabled,
                              self.server.dogwhistles,
                              self.server.min_images_for_accounts,
                              self.server.buy_sites,
                              self.server.auto_cw_cache,
                              self.server.fitness,
                              self.server.onion_domain,
                              self.server.i2p_domain,
                              self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    # get features (local blogs) for a given person
    if self.path.endswith('/tlfeatures') or \
       '/tlfeatures?page=' in self.path:
        if show_features_timeline(self, authorized,
                                  calling_domain, referer_domain,
                                  self.path,
                                  self.server.base_dir,
                                  self.server.http_prefix,
                                  self.server.domain,
                                  self.server.port,
                                  getreq_start_time,
                                  cookie, self.server.debug,
                                  curr_session, ua_str,
                                  MAX_POSTS_IN_NEWS_FEED,
                                  self.server.recent_posts_cache,
                                  self.server.newswire_votes_threshold,
                                  self.server.positive_voting,
                                  self.server.voting_time_mins,
                                  self.server.full_width_tl_button_header,
                                  self.server.access_keys,
                                  self.server.key_shortcuts,
                                  self.server.shared_items_federated_domains,
                                  self.server.allow_local_network_access,
                                  self.server.twitter_replacement_domain,
                                  self.server.show_published_date_only,
                                  self.server.account_timezone,
                                  self.server.bold_reading,
                                  self.server.min_images_for_accounts,
                                  self.server.reverse_sequence,
                                  self.server.default_timeline,
                                  self.server.max_recent_posts,
                                  self.server.translate,
                                  self.server.cached_webfingers,
                                  self.server.person_cache,
                                  self.server.allow_deletion,
                                  self.server.project_version,
                                  self.server.yt_replace_domain,
                                  self.server.newswire,
                                  self.server.show_publish_as_icon,
                                  self.server.icons_as_buttons,
                                  self.server.rss_icon_at_top,
                                  self.server.publish_button_at_top,
                                  self.server.theme_name,
                                  self.server.peertube_instances,
                                  self.server.text_mode_banner,
                                  self.server.system_language,
                                  self.server.max_like_count,
                                  self.server.signing_priv_key_pem,
                                  self.server.cw_lists,
                                  self.server.lists_enabled,
                                  self.server.dogwhistles,
                                  self.server.buy_sites,
                                  self.server.auto_cw_cache,
                                  self.server.fitness,
                                  self.server.onion_domain,
                                  self.server.i2p_domain,
                                  self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show news 2 done',
                        self.server.debug)

    # get the shared items timeline for a given person
    if self.path.endswith('/tlshares') or '/tlshares?page=' in self.path:
        if show_shares_timeline(self, authorized,
                                calling_domain, self.path,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.port,
                                getreq_start_time,
                                cookie, self.server.debug,
                                curr_session, ua_str,
                                MAX_POSTS_IN_FEED,
                                self.server.access_keys,
                                self.server.key_shortcuts,
                                self.server.full_width_tl_button_header,
                                self.server.account_timezone,
                                self.server.bold_reading,
                                self.server.reverse_sequence,
                                self.server.default_timeline,
                                self.server.recent_posts_cache,
                                self.server.max_recent_posts,
                                self.server.translate,
                                self.server.cached_webfingers,
                                self.server.person_cache,
                                self.server.allow_deletion,
                                self.server.project_version,
                                self.server.yt_replace_domain,
                                self.server.twitter_replacement_domain,
                                self.server.show_published_date_only,
                                self.server.newswire,
                                self.server.positive_voting,
                                self.server.show_publish_as_icon,
                                self.server.icons_as_buttons,
                                self.server.rss_icon_at_top,
                                self.server.publish_button_at_top,
                                self.server.theme_name,
                                self.server.peertube_instances,
                                self.server.allow_local_network_access,
                                self.server.text_mode_banner,
                                self.server.system_language,
                                self.server.max_like_count,
                                self.server.shared_items_federated_domains,
                                self.server.signing_priv_key_pem,
                                self.server.cw_lists,
                                self.server.lists_enabled,
                                self.server.dogwhistles,
                                self.server.min_images_for_accounts,
                                self.server.buy_sites,
                                self.server.auto_cw_cache,
                                self.server.fitness,
                                self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    # get the wanted items timeline for a given person
    if self.path.endswith('/tlwanted') or '/tlwanted?page=' in self.path:
        if show_wanted_timeline(self, authorized,
                                calling_domain, self.path,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.port,
                                getreq_start_time,
                                cookie, self.server.debug,
                                curr_session, ua_str,
                                MAX_POSTS_IN_FEED,
                                self.server.access_keys,
                                self.server.key_shortcuts,
                                self.server.full_width_tl_button_header,
                                self.server.account_timezone,
                                self.server.bold_reading,
                                self.server.reverse_sequence,
                                self.server.default_timeline,
                                self.server.recent_posts_cache,
                                self.server.max_recent_posts,
                                self.server.translate,
                                self.server.cached_webfingers,
                                self.server.person_cache,
                                self.server.allow_deletion,
                                self.server.project_version,
                                self.server.yt_replace_domain,
                                self.server.twitter_replacement_domain,
                                self.server.show_published_date_only,
                                self.server.newswire,
                                self.server.positive_voting,
                                self.server.show_publish_as_icon,
                                self.server.icons_as_buttons,
                                self.server.rss_icon_at_top,
                                self.server.publish_button_at_top,
                                self.server.theme_name,
                                self.server.peertube_instances,
                                self.server.allow_local_network_access,
                                self.server.text_mode_banner,
                                self.server.system_language,
                                self.server.max_like_count,
                                self.server.shared_items_federated_domains,
                                self.server.signing_priv_key_pem,
                                self.server.cw_lists,
                                self.server.lists_enabled,
                                self.server.dogwhistles,
                                self.server.min_images_for_accounts,
                                self.server.buy_sites,
                                self.server.auto_cw_cache,
                                self.server.fitness,
                                self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show shares 2 done',
                        self.server.debug)

    # block a domain from html_account_info
    if authorized and users_in_path and \
       '/accountinfo?blockdomain=' in self.path and \
       '?handle=' in self.path:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if not is_moderator(self.server.base_dir, nickname):
            http_400(self)
            self.server.getreq_busy = False
            return
        block_domain = self.path.split('/accountinfo?blockdomain=')[1]
        search_handle = block_domain.split('?handle=')[1]
        search_handle = urllib.parse.unquote_plus(search_handle)
        block_domain = block_domain.split('?handle=')[0]
        block_domain = urllib.parse.unquote_plus(block_domain.strip())
        if '?' in block_domain:
            block_domain = block_domain.split('?')[0]
        add_global_block(self.server.base_dir, '*', block_domain, None)
        self.server.blocked_cache_last_updated = \
            update_blocked_cache(self.server.base_dir,
                                 self.server.blocked_cache,
                                 self.server.blocked_cache_last_updated, 0)
        msg = \
            html_account_info(self.server.translate,
                              self.server.base_dir,
                              self.server.http_prefix,
                              nickname,
                              self.server.domain,
                              search_handle,
                              self.server.debug,
                              self.server.system_language,
                              self.server.signing_priv_key_pem,
                              None,
                              self.server.block_federated,
                              self.server.mitm_servers,
                              self.server.instance_software)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            login_headers(self, 'text/html',
                          msglen, calling_domain)
            write2(self, msg)
        self.server.getreq_busy = False
        return

    # unblock a domain from html_account_info
    if authorized and users_in_path and \
       '/accountinfo?unblockdomain=' in self.path and \
       '?handle=' in self.path:
        nickname = self.path.split('/users/')[1]
        if '/' in nickname:
            nickname = nickname.split('/')[0]
        if not is_moderator(self.server.base_dir, nickname):
            http_400(self)
            self.server.getreq_busy = False
            return
        block_domain = self.path.split('/accountinfo?unblockdomain=')[1]
        search_handle = block_domain.split('?handle=')[1]
        search_handle = urllib.parse.unquote_plus(search_handle)
        block_domain = block_domain.split('?handle=')[0]
        block_domain = urllib.parse.unquote_plus(block_domain.strip())
        remove_global_block(self.server.base_dir, '*', block_domain)
        self.server.blocked_cache_last_updated = \
            update_blocked_cache(self.server.base_dir,
                                 self.server.blocked_cache,
                                 self.server.blocked_cache_last_updated, 0)
        msg = \
            html_account_info(self.server.translate,
                              self.server.base_dir,
                              self.server.http_prefix,
                              nickname,
                              self.server.domain,
                              search_handle,
                              self.server.debug,
                              self.server.system_language,
                              self.server.signing_priv_key_pem,
                              None,
                              self.server.block_federated,
                              self.server.mitm_servers,
                              self.server.instance_software)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            login_headers(self, 'text/html',
                          msglen, calling_domain)
            write2(self, msg)
        self.server.getreq_busy = False
        return

    # get the bookmarks timeline for a given person
    if self.path.endswith('/tlbookmarks') or \
       '/tlbookmarks?page=' in self.path or \
       self.path.endswith('/bookmarks') or \
       '/bookmarks?page=' in self.path:
        if show_bookmarks_timeline(self, authorized,
                                   calling_domain, referer_domain,
                                   self.path,
                                   self.server.base_dir,
                                   self.server.http_prefix,
                                   self.server.domain,
                                   self.server.port,
                                   getreq_start_time,
                                   cookie, self.server.debug,
                                   curr_session, ua_str,
                                   MAX_POSTS_IN_FEED,
                                   self.server.recent_posts_cache,
                                   self.server.positive_voting,
                                   self.server.voting_time_mins,
                                   self.server.full_width_tl_button_header,
                                   self.server.access_keys,
                                   self.server.key_shortcuts,
                                   self.server.shared_items_federated_domains,
                                   self.server.allow_local_network_access,
                                   self.server.twitter_replacement_domain,
                                   self.server.show_published_date_only,
                                   self.server.account_timezone,
                                   self.server.bold_reading,
                                   self.server.reverse_sequence,
                                   self.server.default_timeline,
                                   self.server.max_recent_posts,
                                   self.server.translate,
                                   self.server.cached_webfingers,
                                   self.server.person_cache,
                                   self.server.allow_deletion,
                                   self.server.project_version,
                                   self.server.yt_replace_domain,
                                   self.server.newswire,
                                   self.server.show_publish_as_icon,
                                   self.server.icons_as_buttons,
                                   self.server.rss_icon_at_top,
                                   self.server.publish_button_at_top,
                                   self.server.theme_name,
                                   self.server.peertube_instances,
                                   self.server.text_mode_banner,
                                   self.server.system_language,
                                   self.server.max_like_count,
                                   self.server.signing_priv_key_pem,
                                   self.server.cw_lists,
                                   self.server.lists_enabled,
                                   self.server.dogwhistles,
                                   self.server.min_images_for_accounts,
                                   self.server.buy_sites,
                                   self.server.auto_cw_cache,
                                   self.server.fitness,
                                   self.server.onion_domain,
                                   self.server.i2p_domain,
                                   self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show bookmarks 2 done',
                        self.server.debug)

    # outbox timeline
    if self.path.endswith('/outbox') or \
       '/outbox?page=' in self.path:
        if show_outbox_timeline(self, authorized,
                                calling_domain, referer_domain,
                                self.path,
                                self.server.base_dir,
                                self.server.http_prefix,
                                self.server.domain,
                                self.server.port,
                                getreq_start_time,
                                cookie, self.server.debug,
                                curr_session, ua_str,
                                proxy_type, MAX_POSTS_IN_FEED,
                                self.server.recent_posts_cache,
                                self.server.newswire_votes_threshold,
                                self.server.positive_voting,
                                self.server.voting_time_mins,
                                self.server.full_width_tl_button_header,
                                self.server.access_keys,
                                self.server.key_shortcuts,
                                self.server.account_timezone,
                                self.server.bold_reading,
                                self.server.reverse_sequence,
                                self.server.default_timeline,
                                self.server.max_recent_posts,
                                self.server.translate,
                                self.server.cached_webfingers,
                                self.server.person_cache,
                                self.server.allow_deletion,
                                self.server.project_version,
                                self.server.yt_replace_domain,
                                self.server.twitter_replacement_domain,
                                self.server.show_published_date_only,
                                self.server.newswire,
                                self.server.show_publish_as_icon,
                                self.server.icons_as_buttons,
                                self.server.rss_icon_at_top,
                                self.server.publish_button_at_top,
                                self.server.theme_name,
                                self.server.peertube_instances,
                                self.server.allow_local_network_access,
                                self.server.text_mode_banner,
                                self.server.system_language,
                                self.server.max_like_count,
                                self.server.shared_items_federated_domains,
                                self.server.signing_priv_key_pem,
                                self.server.cw_lists,
                                self.server.lists_enabled,
                                self.server.dogwhistles,
                                self.server.min_images_for_accounts,
                                self.server.buy_sites,
                                self.server.auto_cw_cache,
                                self.server.fitness,
                                self.server.onion_domain,
                                self.server.i2p_domain,
                                self.server.hide_announces,
                                self.server.mitm_servers,
                                self.server.hide_recent_posts):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show outbox done',
                        self.server.debug)

    # get the moderation feed for a moderator
    if self.path.endswith('/moderation') or \
       '/moderation?' in self.path:
        if show_mod_timeline(self, authorized,
                             calling_domain, referer_domain,
                             self.path,
                             self.server.base_dir,
                             self.server.http_prefix,
                             self.server.domain,
                             self.server.port,
                             getreq_start_time,
                             cookie, self.server.debug,
                             curr_session, ua_str,
                             MAX_POSTS_IN_FEED,
                             self.server.recent_posts_cache,
                             self.server.positive_voting,
                             self.server.voting_time_mins,
                             self.server.full_width_tl_button_header,
                             self.server.access_keys,
                             self.server.key_shortcuts,
                             self.server.shared_items_federated_domains,
                             self.server.twitter_replacement_domain,
                             self.server.allow_local_network_access,
                             self.server.show_published_date_only,
                             self.server.account_timezone,
                             self.server.bold_reading,
                             self.server.min_images_for_accounts,
                             self.server.reverse_sequence,
                             self.server.default_timeline,
                             self.server.max_recent_posts,
                             self.server.translate,
                             self.server.cached_webfingers,
                             self.server.person_cache,
                             self.server.project_version,
                             self.server.yt_replace_domain,
                             self.server.newswire,
                             self.server.show_publish_as_icon,
                             self.server.icons_as_buttons,
                             self.server.rss_icon_at_top,
                             self.server.publish_button_at_top,
                             self.server.theme_name,
                             self.server.peertube_instances,
                             self.server.text_mode_banner,
                             self.server.system_language,
                             self.server.max_like_count,
                             self.server.signing_priv_key_pem,
                             self.server.cw_lists,
                             self.server.lists_enabled,
                             self.server.dogwhistles,
                             self.server.buy_sites,
                             self.server.auto_cw_cache,
                             self.server.fitness,
                             self.server.onion_domain,
                             self.server.i2p_domain,
                             self.server.mitm_servers):
            self.server.getreq_busy = False
            return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show moderation done',
                        self.server.debug)

    if show_shares_feed(self, authorized,
                        calling_domain, referer_domain,
                        self.path,
                        self.server.base_dir,
                        self.server.http_prefix,
                        self.server.domain,
                        self.server.port,
                        getreq_start_time,
                        proxy_type,
                        cookie, self.server.debug, 'shares',
                        curr_session, SHARES_PER_PAGE,
                        self.server.access_keys,
                        self.server.key_shortcuts,
                        self.server.city,
                        self.server.shared_items_federated_domains,
                        self.server.account_timezone,
                        self.server.bold_reading,
                        self.server.signing_priv_key_pem,
                        self.server.rss_icon_at_top,
                        self.server.icons_as_buttons,
                        self.server.default_timeline,
                        self.server.recent_posts_cache,
                        self.server.max_recent_posts,
                        self.server.translate,
                        self.server.project_version,
                        self.server.cached_webfingers,
                        self.server.person_cache,
                        self.server.yt_replace_domain,
                        self.server.twitter_replacement_domain,
                        self.server.show_published_date_only,
                        self.server.newswire,
                        self.server.theme_name,
                        self.server.dormant_months,
                        self.server.peertube_instances,
                        self.server.allow_local_network_access,
                        self.server.text_mode_banner,
                        self.server.system_language,
                        self.server.max_like_count,
                        self.server.cw_lists,
                        self.server.lists_enabled,
                        self.server.content_license_url,
                        self.server.buy_sites,
                        self.server.max_shares_on_profile,
                        self.server.sites_unavailable,
                        self.server.no_of_books,
                        self.server.auto_cw_cache,
                        self.server.fitness,
                        self.server.onion_domain,
                        self.server.i2p_domain,
                        self.server.mitm_servers,
                        self.server.hide_recent_posts):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show profile 2 done',
                        self.server.debug)

    if show_following_feed(self, authorized,
                           calling_domain, referer_domain,
                           self.path,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain,
                           self.server.port,
                           getreq_start_time,
                           proxy_type,
                           cookie, self.server.debug,
                           curr_session, FOLLOWS_PER_PAGE,
                           self.server.access_keys,
                           self.server.key_shortcuts,
                           self.server.city,
                           self.server.account_timezone,
                           self.server.content_license_url,
                           self.server.shared_items_federated_domains,
                           self.server.bold_reading,
                           self.server.hide_follows,
                           self.server.max_shares_on_profile,
                           self.server.sites_unavailable,
                           self.server.signing_priv_key_pem,
                           self.server.rss_icon_at_top,
                           self.server.icons_as_buttons,
                           self.server.default_timeline,
                           self.server.recent_posts_cache,
                           self.server.max_recent_posts,
                           self.server.translate,
                           self.server.project_version,
                           self.server.cached_webfingers,
                           self.server.person_cache,
                           self.server.yt_replace_domain,
                           self.server.twitter_replacement_domain,
                           self.server.show_published_date_only,
                           self.server.newswire,
                           self.server.theme_name,
                           self.server.dormant_months,
                           self.server.peertube_instances,
                           self.server.allow_local_network_access,
                           self.server.text_mode_banner,
                           self.server.system_language,
                           self.server.max_like_count,
                           self.server.cw_lists,
                           self.server.lists_enabled,
                           self.server.buy_sites,
                           self.server.no_of_books,
                           self.server.auto_cw_cache,
                           self.server.fitness,
                           self.server.onion_domain,
                           self.server.i2p_domain,
                           self.server.mitm_servers,
                           self.server.hide_recent_posts):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show profile 3 done',
                        self.server.debug)

    if show_moved_feed(self, authorized,
                       calling_domain, referer_domain,
                       self.path,
                       self.server.base_dir,
                       self.server.http_prefix,
                       self.server.domain,
                       self.server.port,
                       getreq_start_time,
                       proxy_type,
                       cookie, self.server.debug,
                       curr_session, FOLLOWS_PER_PAGE,
                       self.server.access_keys,
                       self.server.key_shortcuts,
                       self.server.city,
                       self.server.account_timezone,
                       self.server.content_license_url,
                       self.server.shared_items_federated_domains,
                       self.server.bold_reading,
                       self.server.max_shares_on_profile,
                       self.server.sites_unavailable,
                       self.server.signing_priv_key_pem,
                       self.server.rss_icon_at_top,
                       self.server.icons_as_buttons,
                       self.server.default_timeline,
                       self.server.recent_posts_cache,
                       self.server.max_recent_posts,
                       self.server.translate,
                       self.server.project_version,
                       self.server.cached_webfingers,
                       self.server.person_cache,
                       self.server.yt_replace_domain,
                       self.server.twitter_replacement_domain,
                       self.server.show_published_date_only,
                       self.server.newswire,
                       self.server.theme_name,
                       self.server.dormant_months,
                       self.server.peertube_instances,
                       self.server.allow_local_network_access,
                       self.server.text_mode_banner,
                       self.server.system_language,
                       self.server.max_like_count,
                       self.server.cw_lists,
                       self.server.lists_enabled,
                       self.server.buy_sites,
                       self.server.no_of_books,
                       self.server.auto_cw_cache,
                       self.server.fitness,
                       self.server.onion_domain,
                       self.server.i2p_domain,
                       self.server.mitm_servers,
                       self.server.hide_recent_posts):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show moved 4 done',
                        self.server.debug)

    if show_inactive_feed(self, authorized,
                          calling_domain, referer_domain,
                          self.path,
                          self.server.base_dir,
                          self.server.http_prefix,
                          self.server.domain,
                          self.server.port,
                          getreq_start_time,
                          proxy_type,
                          cookie, self.server.debug,
                          curr_session,
                          self.server.dormant_months,
                          self.server.sites_unavailable,
                          FOLLOWS_PER_PAGE,
                          self.server.access_keys,
                          self.server.key_shortcuts,
                          self.server.city,
                          self.server.account_timezone,
                          self.server.content_license_url,
                          self.server.shared_items_federated_domains,
                          self.server.bold_reading,
                          self.server.max_shares_on_profile,
                          self.server.signing_priv_key_pem,
                          self.server.rss_icon_at_top,
                          self.server.icons_as_buttons,
                          self.server.default_timeline,
                          self.server.recent_posts_cache,
                          self.server.max_recent_posts,
                          self.server.translate,
                          self.server.project_version,
                          self.server.cached_webfingers,
                          self.server.person_cache,
                          self.server.yt_replace_domain,
                          self.server.twitter_replacement_domain,
                          self.server.show_published_date_only,
                          self.server.newswire,
                          self.server.theme_name,
                          self.server.peertube_instances,
                          self.server.allow_local_network_access,
                          self.server.text_mode_banner,
                          self.server.system_language,
                          self.server.max_like_count,
                          self.server.cw_lists,
                          self.server.lists_enabled,
                          self.server.buy_sites,
                          self.server.no_of_books,
                          self.server.auto_cw_cache,
                          self.server.fitness,
                          self.server.onion_domain,
                          self.server.i2p_domain,
                          self.server.mitm_servers,
                          self.server.hide_recent_posts):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show inactive 5 done',
                        self.server.debug)

    if show_followers_feed(self, authorized,
                           calling_domain, referer_domain,
                           self.path,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain,
                           self.server.port,
                           getreq_start_time,
                           proxy_type,
                           cookie, self.server.debug,
                           curr_session, FOLLOWS_PER_PAGE,
                           self.server.access_keys,
                           self.server.key_shortcuts,
                           self.server.city,
                           self.server.account_timezone,
                           self.server.content_license_url,
                           self.server.shared_items_federated_domains,
                           self.server.bold_reading,
                           self.server.hide_follows,
                           self.server.max_shares_on_profile,
                           self.server.sites_unavailable,
                           self.server.signing_priv_key_pem,
                           self.server.rss_icon_at_top,
                           self.server.icons_as_buttons,
                           self.server.default_timeline,
                           self.server.recent_posts_cache,
                           self.server.max_recent_posts,
                           self.server.translate,
                           self.server.project_version,
                           self.server.cached_webfingers,
                           self.server.person_cache,
                           self.server.yt_replace_domain,
                           self.server.twitter_replacement_domain,
                           self.server.show_published_date_only,
                           self.server.newswire,
                           self.server.theme_name,
                           self.server.dormant_months,
                           self.server.peertube_instances,
                           self.server.allow_local_network_access,
                           self.server.text_mode_banner,
                           self.server.system_language,
                           self.server.max_like_count,
                           self.server.cw_lists,
                           self.server.lists_enabled,
                           self.server.buy_sites,
                           self.server.no_of_books,
                           self.server.auto_cw_cache,
                           self.server.fitness,
                           self.server.onion_domain,
                           self.server.i2p_domain,
                           self.server.mitm_servers,
                           self.server.hide_recent_posts):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show profile 5 done',
                        self.server.debug)

    # look up a person
    if show_person_profile(self, authorized,
                           calling_domain, referer_domain,
                           self.path,
                           self.server.base_dir,
                           self.server.http_prefix,
                           self.server.domain,
                           self.server.onion_domain,
                           self.server.i2p_domain,
                           getreq_start_time,
                           proxy_type,
                           cookie, self.server.debug,
                           curr_session,
                           self.server.access_keys,
                           self.server.key_shortcuts,
                           self.server.city,
                           self.server.account_timezone,
                           self.server.bold_reading,
                           self.server.max_shares_on_profile,
                           self.server.sites_unavailable,
                           self.server.fitness,
                           self.server.signing_priv_key_pem,
                           self.server.rss_icon_at_top,
                           self.server.icons_as_buttons,
                           self.server.default_timeline,
                           self.server.recent_posts_cache,
                           self.server.max_recent_posts,
                           self.server.translate,
                           self.server.project_version,
                           self.server.cached_webfingers,
                           self.server.person_cache,
                           self.server.yt_replace_domain,
                           self.server.twitter_replacement_domain,
                           self.server.show_published_date_only,
                           self.server.newswire,
                           self.server.theme_name,
                           self.server.dormant_months,
                           self.server.peertube_instances,
                           self.server.allow_local_network_access,
                           self.server.text_mode_banner,
                           self.server.system_language,
                           self.server.max_like_count,
                           self.server.shared_items_federated_domains,
                           self.server.cw_lists,
                           self.server.lists_enabled,
                           self.server.content_license_url,
                           self.server.buy_sites,
                           self.server.no_of_books,
                           self.server.auto_cw_cache,
                           self.server.mitm_servers,
                           self.server.hide_recent_posts):
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'show profile posts done',
                        self.server.debug)

    # check that a json file was requested
    if not self.path.endswith('.json'):
        if self.server.debug:
            print('DEBUG: GET Not json: ' + self.path +
                  ' ' + self.server.base_dir)
        http_404(self, 142)
        self.server.getreq_busy = False
        return

    if not secure_mode(curr_session,
                       proxy_type, False,
                       self.server, self.headers,
                       self.path):
        if self.server.debug:
            print('WARN: Unauthorized GET')
        http_404(self, 143)
        self.server.getreq_busy = False
        return

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'authorized fetch',
                        self.server.debug)

    # check that the file exists
    filename = self.server.base_dir + self.path

    # check that the file is not suspended
    if filename.endswith('.suspended'):
        http_404(self, 145)
        self.server.getreq_busy = False
        return

    if os.path.isfile(filename):
        content = None
        try:
            with open(filename, 'r', encoding='utf-8') as fp_rfile:
                content = fp_rfile.read()
        except OSError:
            print('EX: unable to read file ' + filename)
        if content:
            try:
                content_json = json.loads(content)
            except json.decoder.JSONDecodeError as ex:
                http_400(self)
                print('EX: json decode error ' + str(ex) +
                      ' from GET content_json ' +
                      str(content))
                self.server.getreq_busy = False
                return

            msg_str = json.dumps(content_json, ensure_ascii=False)
            msg_str = convert_domains(calling_domain,
                                      referer_domain,
                                      msg_str,
                                      self.server.http_prefix,
                                      self.server.domain,
                                      self.server.onion_domain,
                                      self.server.i2p_domain)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            accept_str = self.headers['Accept']
            protocol_str = \
                get_json_content_from_accept(accept_str)
            set_headers(self, protocol_str, msglen,
                        None, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', 'arbitrary json',
                                self.server.debug)
    else:
        if self.server.debug:
            print('DEBUG: GET Unknown file')
        http_404(self, 144)
    self.server.getreq_busy = False

    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', 'end benchmarks',
                        self.server.debug)


def _permitted_crawler_path(path: str) -> bool:
    """Is the given path permitted to be crawled by a search engine?
    this should only allow through basic information, such as nodeinfo
    """
    if path == '/' or path == '/about' or path == '/login' or \
       path.startswith('/blog/'):
        return True
    return False


def _get_referer_domain(self, ua_str: str) -> str:
    """Returns the referer domain
    Which domain is the GET request coming from?
    """
    referer_domain = None
    if self.headers.get('referer'):
        referer_domain = \
            user_agent_domain(self.headers['referer'], self.server.debug)
    elif self.headers.get('Referer'):
        referer_domain = \
            user_agent_domain(self.headers['Referer'], self.server.debug)
    elif self.headers.get('Signature'):
        if 'keyId="' in self.headers['Signature']:
            referer_domain = self.headers['Signature'].split('keyId="')[1]
            if '://' in referer_domain:
                referer_domain = referer_domain.split('://')[1]
            if '/' in referer_domain:
                referer_domain = referer_domain.split('/')[0]
            elif '#' in referer_domain:
                referer_domain = referer_domain.split('#')[0]
            elif '"' in referer_domain:
                referer_domain = referer_domain.split('"')[0]
    elif ua_str:
        referer_domain = user_agent_domain(ua_str, self.server.debug)
    return referer_domain


def _security_txt(self, ua_str: str, calling_domain: str,
                  referer_domain: str,
                  http_prefix: str, calling_site_timeout: int,
                  debug: bool) -> bool:
    """See https://www.rfc-editor.org/rfc/rfc9116
    """
    if not self.path.startswith('/security.txt'):
        return False
    if referer_domain == self.server.domain_full:
        print('security.txt request from self')
        http_400(self)
        return True
    if self.server.security_txt_is_active:
        if not referer_domain:
            print('security.txt is busy ' +
                  'during request without referer domain')
        else:
            print('security.txt is busy during request from ' +
                  referer_domain)
        http_503(self)
        return True
    self.server.security_txt_is_active = True
    # is this a real website making the call ?
    if not debug and not self.server.unit_test and referer_domain:
        # Does calling_domain look like a domain?
        if ' ' in referer_domain or \
           ';' in referer_domain or \
           '.' not in referer_domain:
            print('security.txt ' +
                  'referer domain does not look like a domain ' +
                  referer_domain)
            http_400(self)
            self.server.security_txt_is_active = False
            return True
        if not self.server.allow_local_network_access:
            if local_network_host(referer_domain):
                print('security.txt referer domain is from the ' +
                      'local network ' + referer_domain)
                http_400(self)
                self.server.security_txt_is_active = False
                return True

        if not referer_is_active(http_prefix,
                                 referer_domain, ua_str,
                                 calling_site_timeout,
                                 self.server.sites_unavailable):
            print('security.txt referer url is not active ' +
                  referer_domain)
            http_400(self)
            self.server.security_txt_is_active = False
            return True
    if debug:
        print('DEBUG: security.txt ' + self.path)

    # If we are in broch mode then don't reply
    if not broch_mode_is_active(self.server.base_dir):
        security_txt = \
            'Contact: https://gitlab.com/bashrc2/epicyon/-/issues'

        msg = security_txt.encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/plain; charset=utf-8',
                    msglen, None, calling_domain, True)
        write2(self, msg)
        if referer_domain:
            print('security.txt sent to ' + referer_domain)
        else:
            print('security.txt sent to unknown referer')
    self.server.security_txt_is_active = False
    return True


def _browser_config(self, calling_domain: str, referer_domain: str,
                    getreq_start_time) -> None:
    """Used by MS Windows to put an icon on the desktop if you
    link to a website
    """
    xml_str = \
        '<?xml version="1.0" encoding="utf-8"?>\n' + \
        '<browserconfig>\n' + \
        '  <msapplication>\n' + \
        '    <tile>\n' + \
        '      <square150x150logo src="/logo150.png"/>\n' + \
        '      <TileColor>#eeeeee</TileColor>\n' + \
        '    </tile>\n' + \
        '  </msapplication>\n' + \
        '</browserconfig>'

    msg_str = json.dumps(xml_str, ensure_ascii=False)
    msg_str = convert_domains(calling_domain,
                              referer_domain,
                              msg_str,
                              self.server.http_prefix,
                              self.server.domain,
                              self.server.onion_domain,
                              self.server.i2p_domain)
    msg = msg_str.encode('utf-8')
    msglen = len(msg)
    set_headers(self, 'application/xrd+xml', msglen,
                None, calling_domain, False)
    write2(self, msg)
    if self.server.debug:
        print('Sent browserconfig: ' + calling_domain)
    fitness_performance(getreq_start_time, self.server.fitness,
                        '_GET', '_browser_config',
                        self.server.debug)


def _get_speaker(self, calling_domain: str, referer_domain: str,
                 path: str, base_dir: str, domain: str) -> None:
    """Returns the speaker file used for TTS and
    accessed via c2s
    """
    nickname = path.split('/users/')[1]
    if '/' in nickname:
        nickname = nickname.split('/')[0]
    speaker_filename = \
        acct_dir(base_dir, nickname, domain) + '/speaker.json'
    if not os.path.isfile(speaker_filename):
        http_404(self, 18)
        return

    speaker_json = load_json(speaker_filename)
    msg_str = json.dumps(speaker_json, ensure_ascii=False)
    msg_str = convert_domains(calling_domain,
                              referer_domain,
                              msg_str,
                              self.server.http_prefix,
                              domain,
                              self.server.onion_domain,
                              self.server.i2p_domain)
    msg = msg_str.encode('utf-8')
    msglen = len(msg)
    protocol_str = \
        get_json_content_from_accept(self.headers['Accept'])
    set_headers(self, protocol_str, msglen,
                None, calling_domain, False)
    write2(self, msg)


def _get_ontology(self, calling_domain: str,
                  path: str, base_dir: str,
                  getreq_start_time) -> None:
    """Returns an ontology file
    """
    if '.owl' in path or '.rdf' in path or '.json' in path:
        if '/ontologies/' in path:
            ontology_str = path.split('/ontologies/')[1].replace('#', '')
        else:
            ontology_str = path.split('/data/')[1].replace('#', '')
        ontology_filename = None
        ontology_file_type = 'application/rdf+xml'
        if ontology_str.startswith('DFC_'):
            ontology_filename = base_dir + '/ontology/DFC/' + ontology_str
        else:
            ontology_str = ontology_str.replace('/data/', '')
            ontology_filename = base_dir + '/ontology/' + ontology_str
        if ontology_str.endswith('.json'):
            ontology_file_type = 'application/ld+json'
        if os.path.isfile(ontology_filename):
            ontology_file = None
            try:
                with open(ontology_filename, 'r',
                          encoding='utf-8') as fp_ont:
                    ontology_file = fp_ont.read()
            except OSError:
                print('EX: unable to read ontology ' + ontology_filename)
            if ontology_file:
                ontology_file = \
                    ontology_file.replace('static.datafoodconsortium.org',
                                          calling_domain)
                if not calling_domain.endswith('.i2p') and \
                   not calling_domain.endswith('.onion'):
                    ontology_file = \
                        ontology_file.replace('http://' +
                                              calling_domain,
                                              'https://' +
                                              calling_domain)
                msg = ontology_file.encode('utf-8')
                msglen = len(msg)
                set_headers(self, ontology_file_type, msglen,
                            None, calling_domain, False)
                write2(self, msg)
            fitness_performance(getreq_start_time, self.server.fitness,
                                '_GET', '_get_ontology', self.server.debug)
            return
    http_404(self, 34)


def _confirm_delete_event(self, calling_domain: str, path: str,
                          base_dir: str, http_prefix: str, cookie: str,
                          translate: {}, domain_full: str,
                          onion_domain: str, i2p_domain: str,
                          getreq_start_time) -> bool:
    """Confirm whether to delete a calendar event
    """
    post_id = path.split('?eventid=')[1]
    if '?' in post_id:
        post_id = post_id.split('?')[0]
    post_time = path.split('?time=')[1]
    if '?' in post_time:
        post_time = post_time.split('?')[0]
    post_year = path.split('?year=')[1]
    if '?' in post_year:
        post_year = post_year.split('?')[0]
    post_month = path.split('?month=')[1]
    if '?' in post_month:
        post_month = post_month.split('?')[0]
    post_day = path.split('?day=')[1]
    if '?' in post_day:
        post_day = post_day.split('?')[0]
    # show the confirmation screen screen
    msg = html_calendar_delete_confirm(translate,
                                       base_dir, path,
                                       http_prefix,
                                       domain_full,
                                       post_id, post_time,
                                       post_year, post_month, post_day,
                                       calling_domain)
    if not msg:
        actor = \
            http_prefix + '://' + \
            domain_full + \
            path.split('/eventdelete')[0]
        if calling_domain.endswith('.onion') and onion_domain:
            actor = \
                'http://' + onion_domain + \
                path.split('/eventdelete')[0]
        elif calling_domain.endswith('.i2p') and i2p_domain:
            actor = \
                'http://' + i2p_domain + \
                path.split('/eventdelete')[0]
        redirect_headers(self, actor + '/calendar',
                         cookie, calling_domain, 303)
        fitness_performance(getreq_start_time,
                            self.server.fitness,
                            '_GET', '_confirm_delete_event',
                            self.server.debug)
        return True
    msg = msg.encode('utf-8')
    msglen = len(msg)
    set_headers(self, 'text/html', msglen,
                cookie, calling_domain, False)
    write2(self, msg)
    return True


def _show_known_crawlers(self, calling_domain: str, path: str,
                         base_dir: str, known_crawlers: {}) -> bool:
    """Show a list of known web crawlers
    """
    if '/users/' not in path:
        return False
    if not path.endswith('/crawlers'):
        return False
    nickname = get_nickname_from_actor(path)
    if not nickname:
        return False
    if not is_moderator(base_dir, nickname):
        return False
    crawlers_list: list[str] = []
    curr_time = int(time.time())
    recent_crawlers = 60 * 60 * 24 * 30
    for ua_str, item in known_crawlers.items():
        if item['lastseen'] - curr_time < recent_crawlers:
            hits_str = str(item['hits']).zfill(8)
            crawlers_list.append(hits_str + ' ' + ua_str)
    crawlers_list.sort(reverse=True)
    msg = ''
    for line_str in crawlers_list:
        msg += line_str + '\n'
    msg = msg.encode('utf-8')
    msglen = len(msg)
    set_headers(self, 'text/plain; charset=utf-8', msglen,
                None, calling_domain, True)
    write2(self, msg)
    return True
